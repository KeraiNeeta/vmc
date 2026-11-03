<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RbacSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'dashboard.view' => ['View dashboard', 'View operational dashboard data.'],
            'jobs.view' => ['View jobs', 'View emergency jobs permitted by station scope.'],
            'jobs.create' => ['Create jobs', 'Register emergency calls.'],
            'jobs.update_status' => ['Update job status', 'Update permitted job or assignment statuses.'],
            'jobs.assign' => ['Assign jobs', 'Assign vehicles and teams to jobs.'],
            'jobs.close_duplicate' => ['Close duplicate jobs', 'Close a job confirmed as already handled.'],
            'jobs.transfer_112' => ['Transfer jobs to 112', 'Record a transfer to the 112 service.'],
            'jobs.archive' => ['Archive closed jobs', 'Archive and restore closed incidents without deleting operational history.'],
            'stations.view' => ['View fire stations', 'View permitted fire station records.'],
            'stations.manage' => ['Manage fire stations', 'Create and update permitted fire stations.'],
            'wards.view' => ['View wards', 'View permitted ward records.'],
            'roles.view' => ['View roles and permissions', 'View the configured role and permission catalogue.'],
            'wards.manage' => ['Manage wards', 'Create and update ward records.'],
            'vehicles.view' => ['View vehicles', 'View permitted emergency vehicles.'],
            'vehicles.manage' => ['Manage vehicles', 'Create and update permitted emergency vehicles.'],
            'teams.view' => ['View teams', 'View permitted response teams.'],
            'teams.manage' => ['Manage teams', 'Create and update permitted response teams.'],
            'gps.view' => ['View live GPS', 'View permitted vehicle and incident locations.'],
            'gps.update' => ['Submit GPS updates', 'Submit authenticated vehicle GPS updates.'],
            'reports.view' => ['View reports', 'View permitted operational reports.'],
            'reports.export' => ['Export reports', 'Export permitted operational reports.'],
            'users.view' => ['View users', 'View permitted user accounts.'],
            'users.manage' => ['Manage users', 'Create, update, and disable user accounts.'],
            'roles.manage' => ['Manage roles', 'Assign roles and permissions.'],
            'settings.manage' => ['Manage settings', 'Manage system-wide configuration.'],
        ];

        $roles = [
            'SUPER_ADMIN' => [
                'name' => 'Super Admin',
                'description' => 'Unrestricted system access.',
                'permissions' => array_keys($permissions),
            ],
            'CONTROL_ROOM_ADMIN' => [
                'name' => 'Control Room Admin',
                'description' => 'Monitor and dispatch incidents across all stations.',
                'permissions' => [
                    'dashboard.view', 'jobs.view', 'jobs.create', 'jobs.update_status', 'jobs.assign',
                    'jobs.close_duplicate', 'jobs.transfer_112', 'stations.view', 'wards.view',
                    'jobs.archive',
                    'stations.manage', 'wards.manage', 'vehicles.view', 'vehicles.manage',
                    'teams.view', 'teams.manage', 'gps.view', 'reports.view', 'reports.export',
                    'users.view', 'users.manage',
                ],
            ],
            'FIRE_STATION_ADMIN' => [
                'name' => 'Fire Station Admin',
                'description' => 'Manage operations within assigned fire stations.',
                'permissions' => [
                    'dashboard.view', 'jobs.view', 'jobs.update_status', 'jobs.assign',
                    'jobs.archive',
                    'stations.view', 'wards.view', 'wards.manage', 'vehicles.view', 'vehicles.manage',
                    'teams.view', 'teams.manage', 'gps.view', 'reports.view', 'reports.export',
                    'users.view', 'users.manage', 'roles.view',
                ],
            ],
            'DISPATCH_OPERATOR' => [
                'name' => 'Dispatch Operator',
                'description' => 'Register calls and dispatch response resources.',
                'permissions' => [
                    'dashboard.view', 'jobs.view', 'jobs.create', 'jobs.update_status', 'jobs.assign',
                    'jobs.close_duplicate', 'jobs.transfer_112', 'stations.view', 'wards.view',
                    'jobs.archive',
                    'vehicles.view', 'teams.view', 'gps.view',
                ],
            ],
            'VEHICLE_DRIVER' => [
                'name' => 'Vehicle Driver',
                'description' => 'View assigned jobs and vehicle tracking, update assignment status, and submit GPS.',
                'permissions' => ['jobs.view', 'jobs.update_status', 'gps.view', 'gps.update'],
            ],
            'TEAM_MEMBER' => [
                'name' => 'Team Member',
                'description' => 'View emergency information for assigned work.',
                'permissions' => ['jobs.view'],
            ],
            'REPORT_VIEWER' => [
                'name' => 'Report Viewer',
                'description' => 'View and export permitted operational reports.',
                'permissions' => ['dashboard.view', 'reports.view', 'reports.export'],
            ],
        ];

        DB::transaction(function () use ($permissions, $roles): void {
            foreach ($permissions as $code => [$name, $description]) {
                Permission::query()->updateOrCreate(
                    ['code' => $code],
                    ['name' => $name, 'description' => $description],
                );
            }

            $permissionIds = Permission::query()->pluck('id', 'code');

            foreach ($roles as $code => $details) {
                $role = Role::withTrashed()->updateOrCreate(
                    ['code' => $code],
                    [
                        'name' => $details['name'],
                        'description' => $details['description'],
                        'is_system' => true,
                    ],
                );

                if ($role->trashed()) {
                    $role->restore();
                }

                $role->permissions()->sync(array_map(
                    static fn (string $permission): int => $permissionIds[$permission],
                    $details['permissions'],
                ));
            }
        });
    }
}
