<?php

namespace Tests\Feature;

use App\Models\FireStation;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterDataManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_control_room_admin_can_manage_stations_and_wards_through_the_api(): void
    {
        $admin = $this->userWithRole('CONTROL_ROOM_ADMIN');

        $stationResponse = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/fire-stations', [
                'code' => 'FS-PHASE2',
                'name' => 'Phase Two Station',
                'address' => '1 Operations Road',
                'contact_number' => '+91-90000-01000',
                'latitude' => 22.3072,
                'longitude' => 73.1812,
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'FS-PHASE2');

        $stationId = $stationResponse->json('data.id');
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'stations.created',
            'subject_id' => (string) $stationId,
        ]);

        $wardResponse = $this->postJson('/api/v1/wards', [
            'code' => 'WARD-PHASE2',
            'name' => 'Phase Two Ward',
            'description' => 'Test coverage area',
            'station_ids' => [$stationId],
            'is_active' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.fire_stations.0.id', $stationId);
        $wardId = $wardResponse->json('data.id');

        $this->putJson('/api/v1/wards/'.$wardId, [
            'code' => 'WARD-PHASE2',
            'name' => 'Updated Phase Two Ward',
            'description' => 'Updated coverage area',
            'station_ids' => [$stationId],
            'is_active' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Phase Two Ward');

        $this->deleteJson('/api/v1/wards/'.$wardId)->assertNoContent();
        $this->deleteJson('/api/v1/fire-stations/'.$stationId)->assertNoContent();
        $this->assertSoftDeleted('wards', ['id' => $wardId]);
        $this->assertSoftDeleted('fire_stations', ['id' => $stationId]);
    }

    public function test_station_admin_can_manage_only_resources_and_members_in_their_station(): void
    {
        $stationAdmin = $this->userWithRole('FIRE_STATION_ADMIN');
        $station = FireStation::query()->create([
            'code' => 'FS-LOCAL',
            'name' => 'Local Station',
            'address' => 'Local Operations Road',
            'admin_user_id' => $stationAdmin->id,
        ]);
        $stationAdmin->fireStations()->attach($station->id);
        $member = User::factory()->create(['name' => 'Local Response Member']);
        $station->users()->attach($member->id);
        $outsideStation = FireStation::query()->create([
            'code' => 'FS-OUTSIDE',
            'name' => 'Outside Station',
            'address' => 'Outside scope',
        ]);
        $outsideVehicle = Vehicle::query()->create([
            'fire_station_id' => $outsideStation->id,
            'vehicle_number' => 'OUTSIDE-01',
            'vehicle_type' => 'RESCUE',
            'status' => 'AVAILABLE',
        ]);

        $this->actingAs($stationAdmin, 'sanctum')
            ->postJson('/api/v1/vehicles', [
                'fire_station_id' => $outsideStation->id,
                'vehicle_number' => 'OUTSIDE-01',
                'vehicle_type' => 'RESCUE',
                'status' => 'AVAILABLE',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fire_station_id');

        $vehicleResponse = $this->postJson('/api/v1/vehicles', [
            'fire_station_id' => $station->id,
            'vehicle_number' => 'LOCAL-01',
            'vehicle_type' => 'FIRE_ENGINE',
            'registration_number' => 'REG-LOCAL-01',
            'status' => 'AVAILABLE',
        ])
            ->assertCreated()
            ->assertJsonPath('data.fire_station_id', $station->id);
        $vehicleId = $vehicleResponse->json('data.id');
        $this->getJson('/api/v1/vehicles')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.vehicle_number', 'LOCAL-01');
        $this->putJson('/api/v1/vehicles/'.$outsideVehicle->id, [
            'fire_station_id' => $station->id,
            'vehicle_number' => 'OUTSIDE-01',
            'vehicle_type' => 'RESCUE',
            'status' => 'AVAILABLE',
        ])->assertNotFound();

        $this->patchJson('/api/v1/vehicles/'.$vehicleId, [
            'fire_station_id' => $station->id,
            'vehicle_number' => 'LOCAL-01',
            'vehicle_type' => 'FIRE_ENGINE',
            'registration_number' => 'REG-LOCAL-01',
            'status' => 'MAINTENANCE',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'MAINTENANCE');

        $teamResponse = $this->postJson('/api/v1/teams', [
            'fire_station_id' => $station->id,
            'leader_user_id' => $member->id,
            'member_ids' => [$member->id],
            'name' => 'Local Response Team',
            'contact_number' => '+91-90000-01001',
            'status' => 'AVAILABLE',
        ])
            ->assertCreated()
            ->assertJsonPath('data.members.0.id', $member->id)
            ->assertJsonPath('data.active_members_count', 1);
        $teamId = $teamResponse->json('data.id');
        $this->getJson('/api/v1/teams')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Local Response Team');

        $this->deleteJson('/api/v1/vehicles/'.$vehicleId)->assertNoContent();
        $this->deleteJson('/api/v1/teams/'.$teamId)->assertNoContent();
        $this->assertSoftDeleted('vehicles', ['id' => $vehicleId]);
        $this->assertSoftDeleted('teams', ['id' => $teamId]);
    }

    public function test_master_data_web_forms_are_available_only_to_managers_and_save_records(): void
    {
        $stationAdmin = $this->userWithRole('FIRE_STATION_ADMIN');
        $station = FireStation::query()->create([
            'code' => 'FS-WEB',
            'name' => 'Web Station',
            'address' => 'Web Operations Road',
            'admin_user_id' => $stationAdmin->id,
        ]);
        $stationAdmin->fireStations()->attach($station->id);

        $this->actingAs($stationAdmin)
            ->get('/wards/create')
            ->assertOk()
            ->assertSee('Add Ward')
            ->assertSee('Web Station');

        $this->get('/stations/create')->assertForbidden();

        $this->get('/vehicles/create')
            ->assertOk()
            ->assertSee('Add Vehicle')
            ->assertSee('Web Station');

        $this->post('/vehicles', [
            'fire_station_id' => $station->id,
            'vehicle_number' => 'WEB-VEHICLE-01',
            'vehicle_type' => 'AMBULANCE',
            'status' => 'AVAILABLE',
        ])->assertRedirect('/vehicles');

        $vehicle = Vehicle::query()->where('vehicle_number', 'WEB-VEHICLE-01')->firstOrFail();
        $this->get('/vehicles')
            ->assertOk()
            ->assertSee('WEB-VEHICLE-01')
            ->assertSee(route('app.vehicles.edit', $vehicle->id));
        $this->get(route('app.vehicles.edit', $vehicle->id))
            ->assertOk()
            ->assertSee('Save changes');
    }

    public function test_write_endpoints_require_authentication_and_delete_protects_linked_records(): void
    {
        $this->postJson('/api/v1/vehicles', [])->assertUnauthorized();

        $admin = $this->userWithRole('CONTROL_ROOM_ADMIN');
        $station = FireStation::query()->create([
            'code' => 'FS-LINKED',
            'name' => 'Linked Station',
            'address' => 'Linked Operations Road',
        ]);
        $vehicle = Vehicle::query()->create([
            'fire_station_id' => $station->id,
            'vehicle_number' => 'LINKED-01',
            'vehicle_type' => 'FIRE_ENGINE',
            'status' => Vehicle::STATUS_AVAILABLE,
        ]);
        $team = Team::query()->create([
            'fire_station_id' => $station->id,
            'name' => 'Linked Response Team',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/v1/fire-stations/'.$station->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('record');
        $this->deleteJson('/api/v1/vehicles/'.$vehicle->id)->assertNoContent();
        $this->deleteJson('/api/v1/teams/'.$team->id)->assertNoContent();
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('code', $roleCode)->value('id'));

        return $user;
    }
}
