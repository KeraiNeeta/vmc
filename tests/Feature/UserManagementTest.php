<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FireStation;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DemoDispatchSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_user_directory_renders_edit_actions_for_manageable_users(): void
    {
        [$stationAdmin, $station] = $this->stationAdminWithStation();
        $staff = User::factory()->create(['name' => 'Station Dispatcher']);
        $staff->roles()->attach(Role::query()->where('code', 'DISPATCH_OPERATOR')->value('id'));
        $station->users()->attach($staff->id);

        $this->actingAs($stationAdmin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee($staff->name)
            ->assertSee(route('users.edit', $staff));
    }

    public function test_station_admin_can_create_and_deactivate_only_lower_level_users_in_assigned_stations(): void
    {
        [$stationAdmin, $station] = $this->stationAdminWithStation();
        $outsideStation = FireStation::query()->create([
            'code' => 'FS-OUTSIDE',
            'name' => 'Outside Station',
            'address' => 'Outside scope',
        ]);

        $this->actingAs($stationAdmin)
            ->get('/users/create')
            ->assertOk()
            ->assertSee('Dispatch Operator')
            ->assertDontSee('Super Admin');

        $this->post('/users', [
            'name' => 'Central Dispatcher',
            'email' => 'central.dispatch@example.test',
            'role_code' => 'DISPATCH_OPERATOR',
            'password' => 'safe-test-password-123',
            'station_ids' => [$station->id],
            'is_active' => '1',
        ])->assertRedirect();

        $managedUser = User::query()->where('email', 'central.dispatch@example.test')->firstOrFail();
        $this->assertSame('DISPATCH_OPERATOR', $managedUser->roles()->value('code'));
        $this->assertSame([$station->id], $managedUser->fireStations()->pluck('fire_stations.id')->all());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'users.created',
            'subject_id' => (string) $managedUser->id,
        ]);

        $managedUser->createToken('test-token');
        $this->put(route('users.update', $managedUser), [
            'name' => $managedUser->name,
            'email' => $managedUser->email,
            'role_code' => 'DISPATCH_OPERATOR',
            'station_ids' => [$outsideStation->id],
            'is_active' => '0',
        ])->assertSessionHasErrors('station_ids.0');

        $this->put(route('users.update', $managedUser), [
            'name' => $managedUser->name,
            'email' => $managedUser->email,
            'role_code' => 'DISPATCH_OPERATOR',
            'station_ids' => [$station->id],
            'is_active' => '0',
        ])->assertRedirect();

        $this->assertFalse($managedUser->fresh()->is_active);
        $this->assertSame(0, $managedUser->tokens()->count());
    }

    public function test_station_admin_cannot_assign_admin_role_or_manage_another_station_user(): void
    {
        [$stationAdmin, $station] = $this->stationAdminWithStation();
        $otherStation = FireStation::query()->create([
            'code' => 'FS-OTHER',
            'name' => 'Other Station',
            'address' => 'Other scope',
        ]);
        $otherUser = User::factory()->create();
        $otherUser->roles()->attach(Role::query()->where('code', 'DISPATCH_OPERATOR')->value('id'));
        $otherStation->users()->attach($otherUser->id);

        $this->actingAs($stationAdmin)
            ->post('/users', [
                'name' => 'Escalated User',
                'email' => 'escalated@example.test',
                'role_code' => 'FIRE_STATION_ADMIN',
                'password' => 'safe-test-password-123',
                'station_ids' => [$station->id],
                'is_active' => '1',
            ])
            ->assertSessionHasErrors('role_code');

        $this->get(route('users.show', $otherUser))->assertNotFound();
        $this->get(route('users.edit', $otherUser))->assertNotFound();
    }

    public function test_control_room_admin_can_manage_station_admins_but_not_super_admins(): void
    {
        $controlAdmin = User::factory()->create();
        $controlAdmin->roles()->attach(Role::query()->where('code', 'CONTROL_ROOM_ADMIN')->value('id'));
        $station = FireStation::query()->create([
            'code' => 'FS-CENTRAL',
            'name' => 'Central Station',
            'address' => 'Central',
        ]);

        $this->actingAs($controlAdmin)
            ->post('/users', [
                'name' => 'New Station Admin',
                'email' => 'station.admin@example.test',
                'role_code' => 'FIRE_STATION_ADMIN',
                'password' => 'safe-test-password-123',
                'station_ids' => [$station->id],
                'is_active' => '1',
            ])
            ->assertRedirect();

        $stationAdmin = User::query()->where('email', 'station.admin@example.test')->firstOrFail();
        $this->assertSame('FIRE_STATION_ADMIN', $stationAdmin->roles()->value('code'));

        $superAdmin = User::factory()->create();
        $superAdmin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->value('id'));
        $this->get(route('users.edit', $superAdmin))->assertNotFound();
    }

    public function test_user_detail_shows_individual_roles_station_access_and_activity(): void
    {
        [$stationAdmin, $station] = $this->stationAdminWithStation();
        $staff = User::factory()->create(['last_login_at' => now()]);
        $staff->roles()->attach(Role::query()->where('code', 'DISPATCH_OPERATOR')->value('id'));
        $station->users()->attach($staff->id);
        AuditLog::query()->create([
            'user_id' => $staff->id,
            'event' => 'auth.login',
            'created_at' => now(),
        ]);

        $this->actingAs($stationAdmin)
            ->get(route('users.show', $staff))
            ->assertOk()
            ->assertSee($staff->email)
            ->assertSee('Dispatch Operator')
            ->assertSee($station->name)
            ->assertSee('Auth Login');
    }

    public function test_deactivated_user_session_is_ended_on_the_next_web_request(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->value('id'));

        $this->actingAs($user)->get('/dashboard')->assertOk();
        $user->update(['is_active' => false]);

        $this->get('/dashboard')
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');
    }

    public function test_demo_seeder_creates_repeatable_hierarchical_test_data(): void
    {
        $this->seed(DemoDispatchSeeder::class);
        $this->seed(DemoDispatchSeeder::class);

        $this->assertDatabaseCount('fire_stations', 2);
        $this->assertDatabaseCount('wards', 4);
        $this->assertSame(8, User::query()->where('email', 'like', '%@example.test')->count());
        $this->assertSame('CONTROL_ROOM_ADMIN', User::query()
            ->where('email', 'control.room@example.test')
            ->firstOrFail()
            ->roles()
            ->value('code'));
        $this->assertSame('FIRE_STATION_ADMIN', User::query()
            ->where('email', 'central.admin@example.test')
            ->firstOrFail()
            ->roles()
            ->value('code'));
    }

    private function stationAdminWithStation(): array
    {
        $stationAdmin = User::factory()->create();
        $stationAdmin->roles()->attach(Role::query()->where('code', 'FIRE_STATION_ADMIN')->value('id'));
        $station = FireStation::query()->create([
            'code' => 'FS-ASSIGNED',
            'name' => 'Assigned Station',
            'address' => 'Assigned scope',
            'admin_user_id' => $stationAdmin->id,
        ]);

        return [$stationAdmin, $station];
    }
}
