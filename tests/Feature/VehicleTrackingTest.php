<?php

namespace Tests\Feature;

use App\Models\EmergencyJob;
use App\Models\FireStation;
use App\Models\JobAssignment;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleGpsLog;
use App\Models\Ward;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_assigned_driver_can_submit_gps_and_fetch_only_their_assignment_route(): void
    {
        [$dispatcher, $driver, $station, $vehicle, $assignment] = $this->activeVehicleAssignment();
        $this->actingAs($driver, 'sanctum');
        $firstRecordedAt = now()->subMinutes(2)->startOfSecond();

        $this->postJson('/api/v1/vehicles/'.$vehicle->id.'/gps', [
            'assignment_id' => $assignment->id,
            'latitude' => 22.3072,
            'longitude' => 73.1812,
            'speed_kph' => 34.5,
            'recorded_at' => $firstRecordedAt->toIso8601String(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.latitude', 22.3072)
            ->assertJsonPath('data.assignment_id', $assignment->id);

        $this->postJson('/api/v1/vehicles/'.$vehicle->id.'/gps', [
            'assignment_id' => $assignment->id,
            'latitude' => 22.3080,
            'longitude' => 73.1820,
            'recorded_at' => $firstRecordedAt->addMinute()->toIso8601String(),
        ])->assertCreated();

        $this->assertSame('22.3080000', $vehicle->fresh()->latitude);
        $this->assertSame('73.1820000', $vehicle->fresh()->longitude);
        $this->assertDatabaseHas('vehicle_gps_logs', [
            'vehicle_id' => $vehicle->id,
            'job_assignment_id' => $assignment->id,
            'received_by' => $driver->id,
            'latitude' => 22.3072,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'vehicles.gps_recorded',
            'user_id' => $driver->id,
        ]);

        $this->getJson('/api/v1/vehicles/'.$vehicle->id.'/gps')
            ->assertOk()
            ->assertJsonPath('meta.count', 2)
            ->assertJsonPath('data.0.assignment_id', $assignment->id);

        $this->getJson('/api/v1/tracking')
            ->assertOk()
            ->assertJsonPath('data.0.vehicle_number', $vehicle->vehicle_number);
    }

    public function test_driver_cannot_submit_for_another_team_or_replay_an_older_point(): void
    {
        [, $driver, , $vehicle, $assignment] = $this->activeVehicleAssignment();
        $otherDriver = User::factory()->create();
        $otherDriver->roles()->attach(Role::query()->where('code', 'VEHICLE_DRIVER')->value('id'));
        $this->actingAs($otherDriver, 'sanctum')
            ->postJson('/api/v1/vehicles/'.$vehicle->id.'/gps', [
                'assignment_id' => $assignment->id,
                'latitude' => 22.3072,
                'longitude' => 73.1812,
            ])
            ->assertNotFound();

        $this->actingAs($driver, 'sanctum');
        $this->postJson('/api/v1/vehicles/'.$vehicle->id.'/gps', [
            'assignment_id' => $assignment->id,
            'latitude' => 22.3072,
            'longitude' => 73.1812,
            'recorded_at' => now()->subMinute()->toIso8601String(),
        ])->assertCreated();

        $this->postJson('/api/v1/vehicles/'.$vehicle->id.'/gps', [
            'assignment_id' => $assignment->id,
            'latitude' => 22.3000,
            'longitude' => 73.1700,
            'recorded_at' => now()->subMinutes(2)->toIso8601String(),
        ])->assertUnprocessable()->assertJsonValidationErrors('recorded_at');
    }

    public function test_tracking_api_and_web_route_are_station_scoped_and_show_location_history(): void
    {
        [, , $station, $vehicle, $assignment] = $this->activeVehicleAssignment();
        VehicleGpsLog::query()->create([
            'vehicle_id' => $vehicle->id,
            'job_assignment_id' => $assignment->id,
            'latitude' => 22.3072,
            'longitude' => 73.1812,
            'recorded_at' => now()->subMinutes(2),
        ]);
        VehicleGpsLog::query()->create([
            'vehicle_id' => $vehicle->id,
            'job_assignment_id' => $assignment->id,
            'latitude' => 22.3080,
            'longitude' => 73.1820,
            'recorded_at' => now()->subMinute(),
        ]);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->where('code', 'FIRE_STATION_ADMIN')->value('id'));
        $station->users()->attach($admin->id);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/tracking')
            ->assertOk()
            ->assertJsonPath('data.0.vehicle_number', $vehicle->vehicle_number)
            ->assertJsonPath('data.0.last_gps_at', fn ($value) => is_string($value));

        $this->getJson('/api/v1/vehicles/'.$vehicle->id.'/gps?'.http_build_query([
            'from' => now()->subDays(8)->toIso8601String(),
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');

        $this->actingAs($admin)
            ->get(route('app.tracking', ['vehicle_id' => $vehicle->id]))
            ->assertOk()
            ->assertSee('Fleet location map')
            ->assertSee('Route history')
            ->assertSee($vehicle->vehicle_number)
            ->assertSee('22.3080000');
    }

    /**
     * @return array{User, User, FireStation, Vehicle, JobAssignment}
     */
    private function activeVehicleAssignment(): array
    {
        $dispatcher = User::factory()->create();
        $dispatcher->roles()->attach(Role::query()->where('code', 'DISPATCH_OPERATOR')->value('id'));
        $driver = User::factory()->create();
        $driver->roles()->attach(Role::query()->where('code', 'VEHICLE_DRIVER')->value('id'));
        $station = FireStation::query()->create([
            'code' => 'FS-GPS',
            'name' => 'GPS Station',
            'address' => '1 Tracking Road',
        ]);
        $station->users()->attach($dispatcher->id);
        $ward = Ward::query()->create(['code' => 'WARD-GPS', 'name' => 'GPS Ward']);
        $station->wards()->attach($ward->id);
        $vehicle = Vehicle::query()->create([
            'fire_station_id' => $station->id,
            'vehicle_number' => 'GPS-UNIT-01',
            'vehicle_type' => 'FIRE_ENGINE',
            'status' => Vehicle::STATUS_AVAILABLE,
        ]);
        $team = Team::query()->create([
            'fire_station_id' => $station->id,
            'leader_user_id' => $driver->id,
            'name' => 'GPS Response Team',
            'status' => Team::STATUS_AVAILABLE,
        ]);
        $job = EmergencyJob::query()->create([
            'job_id' => 'VMC-GPS-'.uniqid(),
            'caller_name' => 'GPS Caller',
            'caller_phone' => '+91 98765 43210',
            'call_source' => 'PHONE',
            'incident_type' => 'STRUCTURE_FIRE',
            'priority' => 'HIGH',
            'occurred_at' => now()->subMinutes(5),
            'ward_id' => $ward->id,
            'society_name' => 'GPS Society',
            'incident_address' => '10 Tracking Road',
            'status' => EmergencyJob::STATUS_RECEIVED,
            'created_by' => $dispatcher->id,
        ]);

        $this->actingAs($dispatcher, 'sanctum')
            ->postJson('/api/v1/jobs/'.$job->id.'/assignments', [
                'assignments' => [[
                    'fire_station_id' => $station->id,
                    'vehicle_id' => $vehicle->id,
                    'team_id' => $team->id,
                ]],
            ])
            ->assertOk();

        return [$dispatcher, $driver, $station, $vehicle, $job->assignments()->sole()];
    }
}
