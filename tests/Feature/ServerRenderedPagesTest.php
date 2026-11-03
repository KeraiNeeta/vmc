<?php

namespace Tests\Feature;

use App\Models\EmergencyJob;
use App\Models\FireStation;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Ward;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerRenderedPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_dashboard_preview_does_not_render_database_records(): void
    {
        FireStation::query()->create([
            'code' => 'PRIVATE-01',
            'name' => 'Protected Station',
            'address' => 'Private address',
        ]);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('operations-dashboard-page')
            ->assertSee('/css/operations-theme.css')
            ->assertSee('/css/dashboard-reference.css')
            ->assertSee('Vadodara')
            ->assertSee('Preview mode')
            ->assertSee('Sign in to view operations')
            ->assertSee('Total calls')
            ->assertDontSee('Protected Station')
            ->assertDontSee('PRIVATE-01');
    }

    public function test_server_rendered_dashboard_observes_station_scope(): void
    {
        $stationAdmin = User::factory()->create();
        $stationAdmin->roles()->attach(Role::query()->where('code', 'FIRE_STATION_ADMIN')->value('id'));

        $assignedStation = FireStation::query()->create([
            'code' => 'FS-ASSIGNED',
            'name' => 'Assigned Station',
            'address' => '1 Main Street',
            'admin_user_id' => $stationAdmin->id,
        ]);
        $otherStation = FireStation::query()->create([
            'code' => 'FS-PRIVATE',
            'name' => 'Private Station',
            'address' => '2 North Road',
        ]);
        $ward = Ward::query()->create(['code' => 'WARD-01', 'name' => 'Assigned Ward']);
        $assignedStation->wards()->attach($ward->id);
        $otherStation->users()->attach(User::factory()->create()->id);

        $this->actingAs($stationAdmin)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Assigned Station')
            ->assertDontSee('Private Station')
            ->assertSee('Ward-wise pending jobs');
    }

    public function test_directory_pages_are_server_rendered_searchable_and_station_scoped(): void
    {
        $stationAdmin = User::factory()->create();
        $stationAdmin->roles()->attach(Role::query()->where('code', 'FIRE_STATION_ADMIN')->value('id'));
        $station = FireStation::query()->create([
            'code' => 'FS-CENTRAL',
            'name' => 'Central Station',
            'address' => '1 Main Street',
            'admin_user_id' => $stationAdmin->id,
        ]);
        $other = FireStation::query()->create([
            'code' => 'FS-NORTH',
            'name' => 'North Station',
            'address' => '2 North Road',
        ]);
        $ward = Ward::query()->create(['code' => 'WARD-01', 'name' => 'Central Ward']);
        $station->wards()->attach($ward->id);
        $other->wards()->attach(Ward::query()->create(['code' => 'WARD-02', 'name' => 'North Ward'])->id);

        $this->actingAs($stationAdmin)
            ->get('/stations?search=CENTRAL')
            ->assertOk()
            ->assertSee('operations-dashboard-page')
            ->assertSee('/css/operations-theme.css')
            ->assertSee('Central Station')
            ->assertDontSee('North Station');

        $this->get('/wards')
            ->assertOk()
            ->assertSee('Central Ward')
            ->assertDontSee('North Ward');

        $this->get('/roles')
            ->assertOk()
            ->assertSee('FIRE_STATION_ADMIN')
            ->assertSee('Permission');
    }

    public function test_master_data_and_incident_views_are_station_scoped(): void
    {
        $stationAdmin = User::factory()->create();
        $stationAdmin->roles()->attach(Role::query()->where('code', 'FIRE_STATION_ADMIN')->value('id'));
        $assignedStation = FireStation::query()->create([
            'code' => 'FS-OPS-CENTRAL',
            'name' => 'Operations Central',
            'address' => '1 Main Street',
            'admin_user_id' => $stationAdmin->id,
        ]);
        $otherStation = FireStation::query()->create([
            'code' => 'FS-OPS-NORTH',
            'name' => 'Operations North',
            'address' => '2 North Road',
        ]);
        $assignedWard = Ward::query()->create(['code' => 'WARD-OPS-01', 'name' => 'Operations Central Ward']);
        $otherWard = Ward::query()->create(['code' => 'WARD-OPS-02', 'name' => 'Operations North Ward']);
        $assignedStation->wards()->attach($assignedWard);
        $otherStation->wards()->attach($otherWard);
        Vehicle::query()->create([
            'fire_station_id' => $assignedStation->id,
            'vehicle_number' => 'OPS-CENTRAL-01',
            'vehicle_type' => 'FIRE_ENGINE',
            'status' => Vehicle::STATUS_AVAILABLE,
        ]);
        Vehicle::query()->create([
            'fire_station_id' => $otherStation->id,
            'vehicle_number' => 'OPS-NORTH-01',
            'vehicle_type' => 'RESCUE',
            'status' => Vehicle::STATUS_AVAILABLE,
        ]);
        Team::query()->create([
            'fire_station_id' => $assignedStation->id,
            'name' => 'Operations Central Team',
        ]);
        Team::query()->create([
            'fire_station_id' => $otherStation->id,
            'name' => 'Operations North Team',
        ]);
        EmergencyJob::query()->create([
            'job_id' => 'OPS-JOB-CENTRAL',
            'caller_name' => 'Central Caller',
            'caller_phone' => '+91-90000-01001',
            'incident_type' => 'STRUCTURE_FIRE',
            'priority' => 'HIGH',
            'occurred_at' => now(),
            'ward_id' => $assignedWard->id,
            'incident_address' => 'Central incident location',
            'status' => EmergencyJob::STATUS_RECEIVED,
        ]);
        EmergencyJob::query()->create([
            'job_id' => 'OPS-JOB-NORTH',
            'caller_name' => 'North Caller',
            'caller_phone' => '+91-90000-01002',
            'incident_type' => 'ROAD_ACCIDENT',
            'priority' => 'NORMAL',
            'occurred_at' => now(),
            'ward_id' => $otherWard->id,
            'incident_address' => 'North incident location',
            'status' => EmergencyJob::STATUS_RECEIVED,
        ]);

        $this->actingAs($stationAdmin)
            ->get('/vehicles')
            ->assertOk()
            ->assertSee('OPS-CENTRAL-01')
            ->assertDontSee('OPS-NORTH-01');

        $this->get('/teams')
            ->assertOk()
            ->assertSee('Operations Central Team')
            ->assertDontSee('Operations North Team');

        $this->get('/jobs')
            ->assertOk()
            ->assertSee('OPS-JOB-CENTRAL')
            ->assertDontSee('OPS-JOB-NORTH');

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Incident & station map')
            ->assertSee('OPS-JOB-CENTRAL')
            ->assertDontSee('OPS-JOB-NORTH');
    }

    public function test_phase_four_status_queues_show_the_matching_station_scoped_incidents(): void
    {
        $stationAdmin = User::factory()->create();
        $stationAdmin->roles()->attach(Role::query()->where('code', 'FIRE_STATION_ADMIN')->value('id'));
        $station = FireStation::query()->create([
            'code' => 'FS-QUEUE',
            'name' => 'Queue Test Station',
            'address' => '1 Main Street',
            'admin_user_id' => $stationAdmin->id,
        ]);
        $ward = Ward::query()->create(['code' => 'WARD-QUEUE', 'name' => 'Queue Test Ward']);
        $station->wards()->attach($ward->id);

        foreach ([
            EmergencyJob::STATUS_RECEIVED => 'QUEUE-RECEIVED',
            EmergencyJob::STATUS_ASSIGNED => 'QUEUE-ASSIGNED',
            EmergencyJob::STATUS_EN_ROUTE => 'QUEUE-EN-ROUTE',
            EmergencyJob::STATUS_ARRIVED => 'QUEUE-ARRIVED',
            EmergencyJob::STATUS_IN_PROGRESS => 'QUEUE-IN-PROGRESS',
            EmergencyJob::STATUS_COMPLETED => 'QUEUE-COMPLETED',
        ] as $status => $jobId) {
            EmergencyJob::query()->create([
                'job_id' => $jobId,
                'caller_name' => 'Queue Test Caller',
                'caller_phone' => '+91-90000-01010',
                'incident_type' => 'STRUCTURE_FIRE',
                'priority' => 'HIGH',
                'occurred_at' => now(),
                'ward_id' => $ward->id,
                'incident_address' => 'Queue Test Location',
                'status' => $status,
            ]);
        }

        $this->actingAs($stationAdmin)
            ->get('/jobs/active')
            ->assertOk()
            ->assertSee('QUEUE-ASSIGNED')
            ->assertSee('QUEUE-EN-ROUTE')
            ->assertSee('QUEUE-ARRIVED')
            ->assertSee('QUEUE-IN-PROGRESS')
            ->assertDontSee('QUEUE-RECEIVED')
            ->assertDontSee('QUEUE-COMPLETED');

        $this->get('/jobs/en-route')
            ->assertOk()
            ->assertSee('QUEUE-EN-ROUTE')
            ->assertDontSee('QUEUE-ASSIGNED');

        $this->get('/jobs/completed')
            ->assertOk()
            ->assertSee('QUEUE-COMPLETED')
            ->assertDontSee('QUEUE-IN-PROGRESS');
    }

    public function test_pages_require_their_configured_permissions_for_authenticated_users(): void
    {
        $teamMember = User::factory()->create();
        $teamMember->roles()->attach(Role::query()->where('code', 'TEAM_MEMBER')->value('id'));

        $this->actingAs($teamMember)
            ->get('/dashboard')
            ->assertForbidden();

        $this->get('/stations')->assertForbidden();
    }

    public function test_server_login_and_logout_use_web_session(): void
    {
        $user = User::factory()->create(['password' => 'correct horse battery']);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->value('id'));

        $this->get('/login')
            ->assertOk()
            ->assertSee('/css/operations-theme.css')
            ->assertSee('Sign in to your account');

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'correct horse battery',
        ])->assertRedirect('/dashboard')
            ->assertSessionHas('_token');

        $this->get('/dashboard')->assertOk()->assertSee($user->name);

        $this->post('/logout')->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk()->assertSee('Preview mode');
    }
}
