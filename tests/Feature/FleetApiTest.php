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
use App\Notifications\DispatchAssigned;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_driver_can_fetch_assigned_fleet_work_and_acknowledge_dispatch_notification(): void
    {
        [$dispatcher, $driver, $station, $ward, $vehicle, $team] = $this->fleetFixture();
        $job = $this->job($dispatcher, $ward, 'FLEET-ASSIGNMENT-01');

        $this->actingAs($dispatcher, 'sanctum')
            ->postJson('/api/v1/jobs/'.$job->id.'/assignments', [
                'assignments' => [[
                    'fire_station_id' => $station->id,
                    'vehicle_id' => $vehicle->id,
                    'team_id' => $team->id,
                ]],
            ])
            ->assertOk();

        $assignment = JobAssignment::query()->firstOrFail();
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $driver->id,
            'type' => DispatchAssigned::class,
        ]);

        $this->actingAs($driver, 'sanctum');
        $this->getJson('/api/v1/fleet/assignments')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $assignment->id)
            ->assertJsonPath('data.0.job.job_id', 'FLEET-ASSIGNMENT-01')
            ->assertJsonPath('data.0.job.society_name', 'Central Society')
            ->assertJsonPath('data.0.vehicle.vehicle_number', 'FLEET-FIRE-01');

        $this->getJson('/api/v1/fleet/vehicles')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.vehicle_number', 'FLEET-FIRE-01')
            ->assertJsonPath('data.0.assignments.0.job.job_id', 'FLEET-ASSIGNMENT-01');

        $this->getJson('/api/v1/fleet/notifications?unread_only=1')
            ->assertOk()
            ->assertJsonPath('meta.unread', 1)
            ->assertJsonPath('data.0.type', 'dispatch.assigned')
            ->assertJsonPath('data.0.data.assignment_id', $assignment->id)
            ->assertJsonPath('data.0.data.vehicle_number', 'FLEET-FIRE-01');

        $notificationId = $driver->fresh()->unreadNotifications()->firstOrFail()->id;
        $this->patchJson('/api/v1/fleet/notifications/'.$notificationId.'/read')
            ->assertOk()
            ->assertJsonPath('data.id', $notificationId);

        $this->getJson('/api/v1/fleet/notifications?unread_only=1')
            ->assertOk()
            ->assertJsonPath('meta.unread', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_fleet_endpoints_are_driver_only_and_notifications_are_private(): void
    {
        [, $driver, $station, $ward, $vehicle, $team] = $this->fleetFixture();
        $job = $this->job(User::factory()->create(), $ward, 'FLEET-PRIVATE-01');
        $assignment = JobAssignment::query()->create([
            'emergency_job_id' => $job->id,
            'fire_station_id' => $station->id,
            'vehicle_id' => $vehicle->id,
            'team_id' => $team->id,
            'team_leader_user_id' => $driver->id,
            'assigned_at' => now(),
            'status' => JobAssignment::STATUS_ASSIGNED,
        ]);
        $driver->notify(new DispatchAssigned(['assignment_id' => $assignment->id, 'job_id' => $job->job_id]));
        $notificationId = $driver->notifications()->firstOrFail()->id;

        $otherDriver = User::factory()->create();
        $otherDriver->roles()->attach(Role::query()->where('code', 'VEHICLE_DRIVER')->value('id'));
        $this->actingAs($otherDriver, 'sanctum')
            ->getJson('/api/v1/fleet/assignments')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->patchJson('/api/v1/fleet/notifications/'.$notificationId.'/read')->assertNotFound();

        $operator = User::factory()->create();
        $operator->roles()->attach(Role::query()->where('code', 'DISPATCH_OPERATOR')->value('id'));
        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/v1/fleet/vehicles')
            ->assertForbidden();
    }

    /**
     * @return array{User, User, FireStation, Ward, Vehicle, Team}
     */
    private function fleetFixture(): array
    {
        $dispatcher = User::factory()->create();
        $dispatcher->roles()->attach(Role::query()->where('code', 'DISPATCH_OPERATOR')->value('id'));

        $driver = User::factory()->create();
        $driver->roles()->attach(Role::query()->where('code', 'VEHICLE_DRIVER')->value('id'));

        $station = FireStation::query()->create([
            'code' => 'FS-FLEET-API',
            'name' => 'Fleet API Station',
            'address' => '1 Fleet Road',
        ]);
        $station->users()->attach([$dispatcher->id, $driver->id]);
        $ward = Ward::query()->create(['code' => 'WARD-FLEET-API', 'name' => 'Fleet API Ward']);
        $station->wards()->attach($ward->id);

        $vehicle = Vehicle::query()->create([
            'fire_station_id' => $station->id,
            'vehicle_number' => 'FLEET-FIRE-01',
            'vehicle_type' => 'FIRE_ENGINE',
            'status' => Vehicle::STATUS_AVAILABLE,
        ]);
        $team = Team::query()->create([
            'fire_station_id' => $station->id,
            'leader_user_id' => $driver->id,
            'name' => 'Fleet API Response Team',
            'status' => Team::STATUS_AVAILABLE,
        ]);

        return [$dispatcher, $driver, $station, $ward, $vehicle, $team];
    }

    private function job(User $creator, Ward $ward, string $jobId): EmergencyJob
    {
        return EmergencyJob::query()->create([
            'job_id' => $jobId,
            'caller_name' => 'Fleet Caller',
            'caller_phone' => '+91-90000-01000',
            'call_source' => 'PHONE',
            'incident_type' => 'STRUCTURE_FIRE',
            'priority' => 'HIGH',
            'occurred_at' => now(),
            'ward_id' => $ward->id,
            'society_name' => 'Central Society',
            'incident_address' => '12 Fleet Road',
            'status' => EmergencyJob::STATUS_RECEIVED,
            'created_by' => $creator->id,
        ]);
    }
}
