<?php

namespace Database\Seeders;

use App\Models\EmergencyJob;
use App\Models\FireStation;
use App\Models\JobAssignment;
use App\Models\JobCallLog;
use App\Models\JobExternalReference;
use App\Models\JobRemark;
use App\Models\JobStatusHistory;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleGpsLog;
use App\Models\Ward;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class DemoDispatchSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo records can only be seeded in local or testing environments.');
        }

        DB::transaction(function (): void {
            $central = FireStation::withTrashed()->updateOrCreate(
                ['code' => 'DEMO-CENTRAL'],
                [
                    'name' => 'Central Fire Station',
                    'address' => '10 Station Road, Vadodara',
                    'contact_number' => '+91-20-5550-0100',
                    'latitude' => 22.3072000,
                    'longitude' => 73.1812000,
                    'is_active' => true,
                ],
            );
            $north = FireStation::withTrashed()->updateOrCreate(
                ['code' => 'DEMO-NORTH'],
                [
                    'name' => 'North Fire Station',
                    'address' => '24 Riverside Avenue, Vadodara',
                    'contact_number' => '+91-20-5550-0200',
                    'latitude' => 22.3310000,
                    'longitude' => 73.1970000,
                    'is_active' => true,
                ],
            );
            $central->restore();
            $north->restore();

            foreach ([
                ['code' => 'DEMO-WARD-01', 'name' => 'Central Ward', 'station' => $central],
                ['code' => 'DEMO-WARD-02', 'name' => 'Market Ward', 'station' => $central],
                ['code' => 'DEMO-WARD-03', 'name' => 'River Ward', 'station' => $north],
                ['code' => 'DEMO-WARD-04', 'name' => 'Garden Ward', 'station' => $north],
            ] as $details) {
                $ward = Ward::withTrashed()->updateOrCreate(
                    ['code' => $details['code']],
                    ['name' => $details['name'], 'is_active' => true],
                );
                $ward->restore();
                $ward->fireStations()->sync([$details['station']->id]);
            }

            $accounts = [
                [
                    'name' => 'Demo Control Room Admin',
                    'email' => 'control.room@example.test',
                    'role' => 'CONTROL_ROOM_ADMIN',
                    'stations' => [],
                ],
                [
                    'name' => 'Demo Central Station Admin',
                    'email' => 'central.admin@example.test',
                    'role' => 'FIRE_STATION_ADMIN',
                    'stations' => [$central],
                    'manages' => $central,
                ],
                [
                    'name' => 'Demo North Station Admin',
                    'email' => 'north.admin@example.test',
                    'role' => 'FIRE_STATION_ADMIN',
                    'stations' => [$north],
                    'manages' => $north,
                ],
                [
                    'name' => 'Demo Central Dispatcher',
                    'email' => 'central.dispatch@example.test',
                    'role' => 'DISPATCH_OPERATOR',
                    'stations' => [$central],
                ],
                [
                    'name' => 'Demo North Dispatcher',
                    'email' => 'north.dispatch@example.test',
                    'role' => 'DISPATCH_OPERATOR',
                    'stations' => [$north],
                ],
                [
                    'name' => 'Demo Vehicle Driver',
                    'email' => 'vehicle.driver@example.test',
                    'role' => 'VEHICLE_DRIVER',
                    'stations' => [$central],
                ],
                [
                    'name' => 'Demo Response Team Member',
                    'email' => 'team.member@example.test',
                    'role' => 'TEAM_MEMBER',
                    'stations' => [$north],
                ],
                [
                    'name' => 'Demo Report Viewer',
                    'email' => 'report.viewer@example.test',
                    'role' => 'REPORT_VIEWER',
                    'stations' => [$central],
                ],
            ];

            foreach ($accounts as $details) {
                $user = User::withTrashed()->firstOrCreate(
                    ['email' => $details['email']],
                    [
                        'name' => $details['name'],
                        'password' => Str::random(48),
                        'is_active' => true,
                    ],
                );
                $user->restore();
                $roleId = Role::query()->where('code', $details['role'])->value('id');

                if ($roleId === null) {
                    throw new RuntimeException('Run the RBAC seeder before seeding demo dispatch users.');
                }

                $user->roles()->sync([$roleId]);
                $stationIds = collect($details['stations'])->map(fn (FireStation $station) => $station->id);
                $user->fireStations()->sync($stationIds->all());

                if (isset($details['manages'])) {
                    $details['manages']->update(['admin_user_id' => $user->id]);
                }
            }

            $this->seedOperations($central, $north);
        });

        $this->command?->info('Demo stations, wards, users, vehicles, teams, and emergency jobs are ready.');
        $this->command?->line('Demo accounts have generated private passwords; reset them from User management before signing in.');
        $this->command?->line('Demo account emails use the example.test domain.');
    }

    private function seedOperations(FireStation $central, FireStation $north): void
    {
        $centralDriver = User::query()->where('email', 'vehicle.driver@example.test')->firstOrFail();
        $northMember = User::query()->where('email', 'team.member@example.test')->firstOrFail();
        $centralDispatcher = User::query()->where('email', 'central.dispatch@example.test')->firstOrFail();
        $northDispatcher = User::query()->where('email', 'north.dispatch@example.test')->firstOrFail();
        $centralWard = Ward::query()->where('code', 'DEMO-WARD-01')->firstOrFail();
        $marketWard = Ward::query()->where('code', 'DEMO-WARD-02')->firstOrFail();
        $riverWard = Ward::query()->where('code', 'DEMO-WARD-03')->firstOrFail();

        $centralVehicle = Vehicle::withTrashed()->updateOrCreate(
            ['vehicle_number' => 'DEMO-FIRE-01'],
            [
                'fire_station_id' => $central->id,
                'vehicle_type' => 'FIRE_ENGINE',
                'registration_number' => 'MH-DEMO-1001',
                'gps_device_id' => 'DEMO-GPS-1001',
                'latitude' => 22.3072000,
                'longitude' => 73.1812000,
                'status' => Vehicle::STATUS_DEPLOYED,
                'last_service_at' => now()->subDays(20),
            ],
        );
        $northVehicle = Vehicle::withTrashed()->updateOrCreate(
            ['vehicle_number' => 'DEMO-RESCUE-01'],
            [
                'fire_station_id' => $north->id,
                'vehicle_type' => 'RESCUE',
                'registration_number' => 'MH-DEMO-2001',
                'gps_device_id' => 'DEMO-GPS-2001',
                'latitude' => 22.3310000,
                'longitude' => 73.1970000,
                'status' => Vehicle::STATUS_AVAILABLE,
                'last_service_at' => now()->subDays(12),
            ],
        );
        $centralVehicle->restore();
        $northVehicle->restore();

        $centralTeam = Team::withTrashed()->updateOrCreate(
            ['fire_station_id' => $central->id, 'name' => 'Central Response Team'],
            [
                'leader_user_id' => $centralDriver->id,
                'contact_number' => '+91-20-5550-0110',
                'status' => 'DEPLOYED',
            ],
        );
        $northTeam = Team::withTrashed()->updateOrCreate(
            ['fire_station_id' => $north->id, 'name' => 'North Rescue Team'],
            [
                'leader_user_id' => $northMember->id,
                'contact_number' => '+91-20-5550-0210',
                'status' => 'AVAILABLE',
            ],
        );
        $centralTeam->restore();
        $northTeam->restore();
        $centralTeam->members()->sync([
            $centralDriver->id => [
                'designation' => 'Driver',
                'qualification' => 'Emergency vehicle operator',
                'joined_at' => now()->subYear()->toDateString(),
            ],
        ]);
        $northTeam->members()->sync([
            $northMember->id => [
                'designation' => 'Response team member',
                'qualification' => 'First aid responder',
                'joined_at' => now()->subMonths(8)->toDateString(),
            ],
        ]);

        $now = now();
        $activeOccurredAt = $now->copy()->subMinutes(14);
        $pendingOccurredAt = $now->copy()->subMinutes(4);
        $transferredOccurredAt = $now->copy()->subHours(2);
        $assignmentAt = $activeOccurredAt->copy()->addMinutes(5);
        $enRouteAt = $activeOccurredAt->copy()->addMinutes(7);
        $gpsAt = $now->copy()->subMinutes(2);
        $activeJob = EmergencyJob::withTrashed()->updateOrCreate(
            ['job_id' => 'VMC-DEMO-0001'],
            [
                'caller_name' => 'Demo Caller One',
                'caller_phone' => '+91-90000-00001',
                'call_source' => 'PHONE',
                'incident_type' => 'STRUCTURE_FIRE',
                'priority' => 'HIGH',
                'occurred_at' => $activeOccurredAt,
                'ward_id' => $centralWard->id,
                'society_name' => 'Central Demo Residency',
                'incident_address' => '12 Sample Street, Central Ward, Vadodara',
                'latitude' => 22.3090000,
                'longitude' => 73.1830000,
                'remarks' => 'DEMO DRILL: Smoke reported from a residential building.',
                'status' => EmergencyJob::STATUS_EN_ROUTE,
                'created_by' => $centralDispatcher->id,
            ],
        );
        $activeJob->restore();
        $pendingJob = EmergencyJob::withTrashed()->updateOrCreate(
            ['job_id' => 'VMC-DEMO-0002'],
            [
                'caller_name' => 'Demo Caller Two',
                'caller_phone' => '+91-90000-00002',
                'call_source' => 'PHONE',
                'incident_type' => 'ROAD_ACCIDENT',
                'priority' => 'NORMAL',
                'occurred_at' => $pendingOccurredAt,
                'ward_id' => $riverWard->id,
                'society_name' => 'Riverside Demo Housing',
                'incident_address' => '24 Riverside Avenue, River Ward, Vadodara',
                'latitude' => 22.3335000,
                'longitude' => 73.1990000,
                'remarks' => 'DEMO DRILL: One vehicle reported off the road.',
                'status' => EmergencyJob::STATUS_RECEIVED,
                'created_by' => $northDispatcher->id,
            ],
        );
        $pendingJob->restore();
        $transferredJob = EmergencyJob::withTrashed()->updateOrCreate(
            ['job_id' => 'VMC-DEMO-0003'],
            [
                'caller_name' => 'Demo Caller Three',
                'caller_phone' => '+91-90000-00003',
                'call_source' => 'PHONE',
                'incident_type' => 'MEDICAL_EMERGENCY',
                'priority' => 'HIGH',
                'occurred_at' => $transferredOccurredAt,
                'ward_id' => $marketWard->id,
                'society_name' => 'Market Demo Complex',
                'incident_address' => '8 Market Road, Market Ward, Vadodara',
                'latitude' => 22.3020000,
                'longitude' => 73.1860000,
                'remarks' => 'DEMO DRILL: Transferred to the central emergency service.',
                'status' => EmergencyJob::STATUS_TRANSFERRED_TO_112,
                'created_by' => $centralDispatcher->id,
            ],
        );
        $transferredJob->restore();

        foreach ([
            [$activeJob, 'DEMO-CALL-0001', 'Caller reports a residential fire.'],
            [$pendingJob, 'DEMO-CALL-0002', 'Caller reports a road accident.'],
            [$transferredJob, 'DEMO-CALL-0003', 'Caller requests medical assistance.'],
        ] as [$job, $callReference, $notes]) {
            JobCallLog::query()->updateOrCreate(
                ['call_reference' => $callReference],
                [
                    'emergency_job_id' => $job->id,
                    'caller_name' => $job->caller_name,
                    'caller_phone' => $job->caller_phone,
                    'call_source' => $job->call_source,
                    'received_at' => $job->occurred_at,
                    'notes' => $notes,
                    'created_by' => $job->created_by,
                ],
            );
        }

        $assignment = JobAssignment::query()->updateOrCreate(
            ['emergency_job_id' => $activeJob->id, 'vehicle_id' => $centralVehicle->id],
            [
                'fire_station_id' => $central->id,
                'team_id' => $centralTeam->id,
                'team_leader_user_id' => $centralDriver->id,
                'assigned_by' => $centralDispatcher->id,
                'assigned_at' => $assignmentAt,
                'released_at' => null,
                'status' => EmergencyJob::STATUS_EN_ROUTE,
                'remarks' => 'Central response team dispatched.',
                'cancellation_reason' => null,
            ],
        );

        foreach ([
            [$activeJob, null, null, EmergencyJob::STATUS_RECEIVED, $activeOccurredAt, 'DEMO DRILL: Call received.'],
            [$activeJob, $assignment, EmergencyJob::STATUS_RECEIVED, EmergencyJob::STATUS_ASSIGNED, $assignmentAt, 'DEMO DRILL: Vehicle and team assigned.'],
            [$activeJob, $assignment, EmergencyJob::STATUS_ASSIGNED, EmergencyJob::STATUS_EN_ROUTE, $enRouteAt, 'DEMO DRILL: Team is en route.'],
            [$pendingJob, null, null, EmergencyJob::STATUS_RECEIVED, $pendingOccurredAt, 'DEMO DRILL: Call received; awaiting dispatch.'],
            [$transferredJob, null, null, EmergencyJob::STATUS_RECEIVED, $transferredOccurredAt, 'DEMO DRILL: Call received.'],
            [$transferredJob, null, EmergencyJob::STATUS_RECEIVED, EmergencyJob::STATUS_TRANSFERRED_TO_112, $transferredOccurredAt->copy()->addMinutes(7), 'DEMO DRILL: Transferred to external emergency service.'],
        ] as [$job, $eventAssignment, $previous, $next, $changedAt, $remarks]) {
            JobStatusHistory::query()->updateOrCreate(
                [
                    'emergency_job_id' => $job->id,
                    'job_assignment_id' => $eventAssignment?->id,
                    'new_status' => $next,
                ],
                [
                    'previous_status' => $previous,
                    'changed_by' => $job->created_by,
                    'changed_at' => $changedAt,
                    'remarks' => $remarks,
                    'latitude' => $job->latitude,
                    'longitude' => $job->longitude,
                ],
            );
        }

        JobRemark::query()->updateOrCreate(
            ['emergency_job_id' => $activeJob->id, 'body' => 'Demo unit is responding; live location is sample data.'],
            ['user_id' => $centralDispatcher->id],
        );
        VehicleGpsLog::query()->updateOrCreate(
            [
                'vehicle_id' => $centralVehicle->id,
                'job_assignment_id' => $assignment->id,
                'speed_kph' => 34.50,
            ],
            [
                'latitude' => 22.3100000,
                'longitude' => 73.1840000,
                'recorded_at' => $gpsAt,
            ],
        );
        JobExternalReference::query()->updateOrCreate(
            ['reference_type' => 'TRANSFER_112', 'reference_number' => 'DEMO-112-0003'],
            [
                'emergency_job_id' => $transferredJob->id,
                'details' => 'Sample handoff reference; not connected to a live emergency service.',
                'transferred_at' => $transferredOccurredAt->copy()->addMinutes(7),
                'confirmed_at' => $transferredOccurredAt->copy()->addMinutes(9),
                'created_by' => $centralDispatcher->id,
            ],
        );

        DB::table('notifications')->updateOrInsert(
            ['id' => 'd3cfe234-c0ba-5b58-a5bc-cd2946598731'],
            [
                'type' => 'App\\Notifications\\DispatchAssigned',
                'notifiable_type' => User::class,
                'notifiable_id' => $centralDriver->id,
                'data' => json_encode([
                    'event' => 'dispatch.assigned',
                    'assignment_id' => $assignment->id,
                    'job_id' => $activeJob->job_id,
                    'incident_type' => $activeJob->incident_type,
                    'priority' => $activeJob->priority,
                    'caller_name' => $activeJob->caller_name,
                    'caller_phone' => $activeJob->caller_phone,
                    'society_name' => $activeJob->society_name,
                    'incident_address' => $activeJob->incident_address,
                    'fire_station' => $central->name,
                    'vehicle_number' => $centralVehicle->vehicle_number,
                    'message' => 'DEMO DRILL: A sample response assignment is ready.',
                    'assigned_at' => $assignment->assigned_at?->toIso8601String(),
                ], JSON_THROW_ON_ERROR),
                'read_at' => null,
                'created_at' => $assignmentAt,
                'updated_at' => $assignmentAt,
            ],
        );
    }
}
