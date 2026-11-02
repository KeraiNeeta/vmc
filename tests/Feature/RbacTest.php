<?php

namespace Tests\Feature;

use App\Models\FireStation;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\InitialSuperAdminSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_roles_grant_only_their_seeded_permissions(): void
    {
        $dispatcher = User::factory()->create();
        $dispatcher->roles()->attach(Role::query()->where('code', 'DISPATCH_OPERATOR')->value('id'));

        $this->assertTrue($dispatcher->hasRole('DISPATCH_OPERATOR'));
        $this->assertTrue($dispatcher->hasPermission('jobs.assign'));
        $this->assertFalse($dispatcher->hasPermission('settings.manage'));

        $superAdmin = User::factory()->create();
        $superAdmin->roles()->attach(Role::query()->where('code', 'SUPER_ADMIN')->value('id'));

        $this->assertTrue($superAdmin->hasPermission('settings.manage'));
    }

    public function test_station_access_is_limited_to_assigned_stations(): void
    {
        $user = User::factory()->create();
        $firstStation = FireStation::query()->create([
            'code' => 'FS-001',
            'name' => 'Central Station',
            'address' => '1 Main Street',
        ]);
        $secondStation = FireStation::query()->create([
            'code' => 'FS-002',
            'name' => 'North Station',
            'address' => '2 North Road',
        ]);

        $user->fireStations()->attach($firstStation->id);

        $this->assertTrue($user->canAccessFireStation($firstStation));
        $this->assertFalse($user->canAccessFireStation($secondStation));

        $managedStation = FireStation::query()->create([
            'code' => 'FS-003',
            'name' => 'Managed Station',
            'address' => '3 South Road',
            'admin_user_id' => $user->id,
        ]);

        $this->assertTrue($user->canAccessFireStation($managedStation));
    }

    public function test_initial_super_admin_is_created_from_explicit_credentials(): void
    {
        config([
            'emergency.initial_super_admin.email' => 'admin@example.com',
            'emergency.initial_super_admin.password' => 'a-strong-initial-password',
        ]);

        $this->seed(InitialSuperAdminSeeder::class);
        $this->seed(InitialSuperAdminSeeder::class);

        $user = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $this->assertTrue($user->hasRole('SUPER_ADMIN'));
        $this->assertTrue(Hash::check('a-strong-initial-password', $user->password));
        $this->assertSame(1, User::query()->count());
    }

    public function test_permission_middleware_enforces_role_permissions(): void
    {
        Route::middleware(['auth:sanctum', 'permission:jobs.assign'])
            ->get('/api/v1/permission-check', static fn () => response()->json(['allowed' => true]));

        $teamMember = User::factory()->create();
        $teamMember->roles()->attach(Role::query()->where('code', 'TEAM_MEMBER')->value('id'));

        $this->actingAs($teamMember, 'sanctum')
            ->getJson('/api/v1/permission-check')
            ->assertForbidden();

        $dispatcher = User::factory()->create();
        $dispatcher->roles()->attach(Role::query()->where('code', 'DISPATCH_OPERATOR')->value('id'));

        $this->actingAs($dispatcher, 'sanctum')
            ->getJson('/api/v1/permission-check')
            ->assertOk()
            ->assertJsonPath('allowed', true);
    }
}
