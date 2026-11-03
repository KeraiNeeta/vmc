<?php

namespace Tests\Feature;

use App\Models\EmergencyJob;
use App\Models\FireStation;
use App\Models\JobAssignment;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Ward;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_dispatcher_can_assign_multiple_vehicles_and_complete_each_unit_sequentially(): void
    {
        [$operator, $station, $ward] = $this->operatorWithStation();
        $vehicleOne = $this->vehicle($station, 'VMC-FIRE-01');
        $vehicleTwo = $this->vehicle($station, 'VMC-FIRE-02');
        $team = Team::query()->create([
            'fire_station_id' => $station->id,
            'name' => 'Central Response Team',
            'status' => Team::STATUS_AVAILABLE,
        ]);
        $this->actingAs($operator, 'sanctum');
        $job = $this->postJson('/api/v1/jobs', $this->incidentData($ward))
            ->assertCreated()
            ->json('data');

        $this->postJson('/api/v1/jobs/'.$job['id'].'/assignments', [
            'assignments' => [
                ['fire_station_id' => $station->id, 'vehicle_id' => $vehicleOne->id, 'team_id' => $team->id],
                ['fire_station_id' => $station->id, 'vehicle_id' => $vehicleTwo->id],
            ],
            'remarks' => 'Initial dispatch',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', EmergencyJob::STATUS_ASSIGNED)
            ->assertJsonCount(2, 'data.assignments')
            ->assertJsonPath('data.assignments.0.status', JobAssignment::STATUS_ASSIGNED)
            ->assertJsonPath('data.status_history.1.assignment.vehicle.vehicle_number', 'VMC-FIRE-01');

        $assignmentIds = JobAssignment::query()->orderBy('id')->pluck('id')->all();
        $this->patchJson('/api/v1/jobs/'.$job['id'].'/assignments/'.$assignmentIds[0].'/status', [
            'status' => JobAssignment::STATUS_ARRIVED,
        ])->assertUnprocessable()->assertJsonValidationErrors('status');

        foreach ($assignmentIds as $assignmentId) {
            foreach ([
                JobAssignment::STATUS_EN_ROUTE,
                JobAssignment::STATUS_ARRIVED,
                JobAssignment::STATUS_IN_PROGRESS,
            ] as $status) {
                $this->patchJson('/api/v1/jobs/'.$job['id'].'/assignments/'.$assignmentId.'/status', [
                    'status' => $status,
                    'remarks' => 'Status confirmed',
                    'latitude' => 22.3072,
                    'longitude' => 73.1812,
                ])->assertOk();
            }
        }

        $this->patchJson('/api/v1/jobs/'.$job['id'].'/assignments/'.$assignmentIds[0].'/status', [
            'status' => JobAssignment::STATUS_COMPLETED,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', EmergencyJob::STATUS_IN_PROGRESS);

        $this->patchJson('/api/v1/jobs/'.$job['id'].'/assignments/'.$assignmentIds[1].'/status', [
            'status' => JobAssignment::STATUS_COMPLETED,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', EmergencyJob::STATUS_COMPLETED);

        $this->assertSame(Vehicle::STATUS_AVAILABLE, $vehicleOne->fresh()->status);
        $this->assertSame(Vehicle::STATUS_AVAILABLE, $vehicleTwo->fresh()->status);
        $this->assertSame(Team::STATUS_AVAILABLE, $team->fresh()->status);
        $this->assertDatabaseHas('job_status_history', [
            'emergency_job_id' => $job['id'],
            'job_assignment_id' => $assignmentIds[0],
            'new_status' => JobAssignment::STATUS_EN_ROUTE,
            'latitude' => 22.3072,
            'longitude' => 73.1812,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'jobs.assignment_status_updated',
            'subject_id' => (string) $assignmentIds[0],
        ]);
    }

    public function test_dispatcher_cannot_assign_resources_from_an_unmanaged_station(): void
    {
        [$operator, , $ward] = $this->operatorWithStation();
        $outsideStation = FireStation::query()->create([
            'code' => 'FS-OUTSIDE',
            'name' => 'Outside Station',
            'address' => 'Outside scope',
        ]);
        $outsideStation->wards()->attach($ward->id);
        $outsideVehicle = $this->vehicle($outsideStation, 'OUTSIDE-01');
        $this->actingAs($operator, 'sanctum');
        $job = $this->postJson('/api/v1/jobs', $this->incidentData($ward))
            ->assertCreated()
            ->json('data');

        $this->postJson('/api/v1/jobs/'.$job['id'].'/assignments', [
            'assignments' => [[
                'fire_station_id' => $outsideStation->id,
                'vehicle_id' => $outsideVehicle->id,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('assignments');

        $this->assertDatabaseCount('job_assignments', 0);
        $this->assertSame(Vehicle::STATUS_AVAILABLE, $outsideVehicle->fresh()->status);
    }

    public function test_vehicle_driver_can_only_update_assignments_for_their_active_team(): void
    {
        [$operator, $station, $ward] = $this->operatorWithStation();
        $driver = User::factory()->create();
        $driver->roles()->attach(Role::query()->where('code', 'VEHICLE_DRIVER')->value('id'));
        $station->users()->attach($driver->id);

        $activeTeam = Team::query()->create([
            'fire_station_id' => $station->id,
            'name' => 'Driver Active Team',
            'status' => Team::STATUS_DEPLOYED,
        ]);
        $activeTeam->members()->attach($driver->id, ['joined_at' => now()->toDateString()]);

        $inactiveTeam = Team::query()->create([
            'fire_station_id' => $station->id,
            'name' => 'Driver Inactive Team',
            'status' => Team::STATUS_DEPLOYED,
        ]);
        $inactiveTeam->members()->attach($driver->id, [
            'joined_at' => now()->subMonth()->toDateString(),
            'left_at' => now()->subDay()->toDateString(),
        ]);

        $activeJob = $this->assignedJob($operator, $ward, 'VMC-DRIVER-ACTIVE');
        $inactiveJob = $this->assignedJob($operator, $ward, 'VMC-DRIVER-INACTIVE');
        $outsideJob = $this->assignedJob($operator, $ward, 'VMC-DRIVER-OUTSIDE');

        $activeAssignment = $this->assignment($station, $activeJob, 'DRIVER-ACTIVE-01', $activeTeam);
        $inactiveAssignment = $this->assignment($station, $inactiveJob, 'DRIVER-INACTIVE-01', $inactiveTeam);
        $outsideAssignment = $this->assignment($station, $outsideJob, 'DRIVER-OUTSIDE-01');

        $this->actingAs($driver, 'sanctum')
            ->patchJson("/api/v1/jobs/{$activeJob->id}/assignments/{$activeAssignment->id}/status", [
                'status' => JobAssignment::STATUS_EN_ROUTE,
            ])
            ->assertOk()
            ->assertJsonPath('data.assignments.0.status', JobAssignment::STATUS_EN_ROUTE);

        foreach ([
            [$inactiveJob, $inactiveAssignment],
            [$outsideJob, $outsideAssignment],
        ] as [$job, $assignment]) {
            $this->patchJson("/api/v1/jobs/{$job->id}/assignments/{$assignment->id}/status", [
                'status' => JobAssignment::STATUS_EN_ROUTE,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('assignment');
        }

        $this->assertSame(JobAssignment::STATUS_ASSIGNED, $inactiveAssignment->fresh()->status);
        $this->assertSame(JobAssignment::STATUS_ASSIGNED, $outsideAssignment->fresh()->status);
    }

    public function test_incident_detail_displays_assignment_controls_and_unit_history(): void
    {
        [$operator, $station, $ward] = $this->operatorWithStation();
        $this->vehicle($station, 'VMC-FIRE-03');
        $this->actingAs($operator);
        $job = EmergencyJob::query()->create([
            ...$this->incidentData($ward),
            'job_id' => 'VMC-ASSIGNMENT-DETAIL',
            'status' => EmergencyJob::STATUS_RECEIVED,
            'created_by' => $operator->id,
        ]);

        $this->get(route('app.jobs.show', $job))
            ->assertOk()
            ->assertSee('Assign one or more units')
            ->assertSee('Response units')
            ->assertSee('Add another vehicle');
    }

    /**
     * @return array{User, FireStation, Ward}
     */
    private function operatorWithStation(): array
    {
        $operator = User::factory()->create();
        $operator->roles()->attach(Role::query()->where('code', 'DISPATCH_OPERATOR')->value('id'));
        $station = FireStation::query()->create([
            'code' => 'FS-ASSIGN',
            'name' => 'Assignment Station',
            'address' => '1 Response Road',
        ]);
        $station->users()->attach($operator->id);
        $ward = Ward::query()->create(['code' => 'WARD-ASSIGN', 'name' => 'Assignment Ward']);
        $station->wards()->attach($ward->id);

        return [$operator, $station, $ward];
    }

    private function vehicle(FireStation $station, string $number): Vehicle
    {
        return Vehicle::query()->create([
            'fire_station_id' => $station->id,
            'vehicle_number' => $number,
            'vehicle_type' => 'FIRE_ENGINE',
            'status' => Vehicle::STATUS_AVAILABLE,
        ]);
    }

    private function assignedJob(User $operator, Ward $ward, string $jobId): EmergencyJob
    {
        return EmergencyJob::query()->create([
            ...$this->incidentData($ward),
            'job_id' => $jobId,
            'status' => EmergencyJob::STATUS_ASSIGNED,
            'created_by' => $operator->id,
        ]);
    }

    private function assignment(
        FireStation $station,
        EmergencyJob $job,
        string $vehicleNumber,
        ?Team $team = null,
    ): JobAssignment {
        $vehicle = $this->vehicle($station, $vehicleNumber);
        $vehicle->update(['status' => Vehicle::STATUS_DEPLOYED]);

        return JobAssignment::query()->create([
            'emergency_job_id' => $job->id,
            'fire_station_id' => $station->id,
            'vehicle_id' => $vehicle->id,
            'team_id' => $team?->id,
            'team_leader_user_id' => $team?->leader_user_id,
            'assigned_at' => now(),
            'status' => JobAssignment::STATUS_ASSIGNED,
        ]);
    }

    private function incidentData(Ward $ward): array
    {
        return [
            'caller_name' => 'Assignment Caller',
            'caller_phone' => '+91 98765 43210',
            'call_source' => 'PHONE',
            'incident_type' => 'STRUCTURE_FIRE',
            'priority' => 'HIGH',
            'occurred_at' => now()->subMinutes(5)->toIso8601String(),
            'ward_id' => $ward->id,
            'society_name' => 'Assignment Society',
            'incident_address' => '10 Test Road, Vadodara',
            'latitude' => 22.3072,
            'longitude' => 73.1812,
            'remarks' => 'Smoke reported at the premises.',
        ];
    }
}
