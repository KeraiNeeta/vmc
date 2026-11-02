<?php

namespace Tests\Feature;

use App\Models\FireStation;
use App\Models\Role;
use App\Models\User;
use App\Models\Ward;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_dashboard_counts_real_records_and_requires_permission(): void
    {
        $dispatcher = User::factory()->create();
        $dispatcher->roles()->attach(Role::query()->where('code', 'CONTROL_ROOM_ADMIN')->value('id'));

        $station = FireStation::query()->create([
            'code' => 'FS-001',
            'name' => 'Central Station',
            'address' => '1 Main Street',
            'latitude' => '18.5204300',
            'longitude' => '73.8567440',
        ]);
        $ward = Ward::query()->create([
            'code' => 'WARD-01',
            'name' => 'Central Ward',
        ]);
        $station->wards()->attach($ward->id);
        $staff = User::factory()->create();
        $station->users()->attach($staff->id);

        $this->actingAs($dispatcher, 'sanctum')
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.summary.total_stations', 1)
            ->assertJsonPath('data.summary.active_stations', 1)
            ->assertJsonPath('data.summary.active_wards', 1)
            ->assertJsonPath('data.summary.active_users', 2)
            ->assertJsonPath('data.summary.system_roles', 7)
            ->assertJsonPath('data.stations.0.wards_count', 1)
            ->assertJsonPath('data.stations.0.active_staff_count', 1)
            ->assertJsonPath('data.stations.0.latitude', 18.52043)
            ->assertJsonPath('data.operations_available.emergency_jobs', false);
    }

    public function test_station_scoped_dashboard_never_exposes_unassigned_stations_or_staff(): void
    {
        $stationAdmin = User::factory()->create();
        $stationAdmin->roles()->attach(Role::query()->where('code', 'FIRE_STATION_ADMIN')->value('id'));

        $assignedStation = FireStation::query()->create([
            'code' => 'FS-001',
            'name' => 'Assigned Station',
            'address' => '1 Main Street',
            'admin_user_id' => $stationAdmin->id,
        ]);
        $otherStation = FireStation::query()->create([
            'code' => 'FS-002',
            'name' => 'Restricted Station',
            'address' => '2 North Road',
        ]);
        $visibleWard = Ward::query()->create([
            'code' => 'WARD-01',
            'name' => 'Assigned Ward',
        ]);
        $restrictedWard = Ward::query()->create([
            'code' => 'WARD-02',
            'name' => 'Restricted Ward',
        ]);
        $assignedStation->wards()->attach($visibleWard->id);
        $otherStation->wards()->attach($restrictedWard->id);

        $otherStaff = User::factory()->create();
        $otherStation->users()->attach($otherStaff->id);

        $this->actingAs($stationAdmin, 'sanctum')
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.summary.total_stations', 1)
            ->assertJsonPath('data.summary.active_wards', 1)
            ->assertJsonPath('data.summary.active_users', 1)
            ->assertJsonCount(1, 'data.stations')
            ->assertJsonMissing(['name' => 'Restricted Station']);
    }

    public function test_dashboard_requires_login_and_dashboard_permission(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();

        $teamMember = User::factory()->create();
        $teamMember->roles()->attach(Role::query()->where('code', 'TEAM_MEMBER')->value('id'));

        $this->actingAs($teamMember, 'sanctum')
            ->getJson('/api/v1/dashboard')
            ->assertForbidden();
    }

    public function test_read_only_catalog_pages_return_real_searchable_station_scoped_data(): void
    {
        $stationAdmin = User::factory()->create();
        $stationAdmin->roles()->attach(Role::query()->where('code', 'FIRE_STATION_ADMIN')->value('id'));

        $assignedStation = FireStation::query()->create([
            'code' => 'FS-CENTRAL',
            'name' => 'Central Station',
            'address' => '1 Main Street',
            'admin_user_id' => $stationAdmin->id,
        ]);
        $otherStation = FireStation::query()->create([
            'code' => 'FS-NORTH',
            'name' => 'North Station',
            'address' => '2 North Road',
        ]);
        $visibleWard = Ward::query()->create([
            'code' => 'WARD-01',
            'name' => 'Central Ward',
        ]);
        $restrictedWard = Ward::query()->create([
            'code' => 'WARD-02',
            'name' => 'North Ward',
        ]);
        $assignedStation->wards()->attach($visibleWard->id);
        $otherStation->wards()->attach($restrictedWard->id);

        $this->actingAs($stationAdmin, 'sanctum')
            ->getJson('/api/v1/fire-stations?search=CENTRAL')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.code', 'FS-CENTRAL')
            ->assertJsonPath('data.0.wards_count', 1);

        $this->actingAs($stationAdmin, 'sanctum')
            ->getJson('/api/v1/wards')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.code', 'WARD-01');

        $this->actingAs($stationAdmin, 'sanctum')
            ->getJson('/api/v1/roles')
            ->assertOk()
            ->assertJsonPath('meta.total', 7)
            ->assertJsonFragment(['code' => 'vehicles.manage']);
    }

    public function test_catalog_search_rejects_invalid_pagination(): void
    {
        $stationAdmin = User::factory()->create();
        $stationAdmin->roles()->attach(Role::query()->where('code', 'FIRE_STATION_ADMIN')->value('id'));

        $this->actingAs($stationAdmin, 'sanctum')
            ->getJson('/api/v1/fire-stations?per_page=1000')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }
}
