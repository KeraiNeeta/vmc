<?php

namespace Tests\Feature;

use App\Models\EmergencyJob;
use App\Models\FireStation;
use App\Models\JobAssignment;
use App\Models\Team;
use App\Models\Vehicle;
use Database\Seeders\DemoDispatchSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DispatchSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_schema_and_repeatable_local_demo_records_are_available(): void
    {
        $this->seed(RbacSeeder::class);
        $this->seed(DemoDispatchSeeder::class);
        $this->seed(DemoDispatchSeeder::class);

        foreach ([
            'vehicles',
            'teams',
            'team_members',
            'emergency_jobs',
            'job_call_logs',
            'job_assignments',
            'job_status_history',
            'job_remarks',
            'vehicle_gps_logs',
            'job_external_references',
            'notifications',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Expected {$table} table to exist.");
        }

        $this->assertDatabaseCount('vehicles', 2);
        $this->assertDatabaseCount('teams', 2);
        $this->assertDatabaseCount('team_members', 2);
        $this->assertDatabaseCount('emergency_jobs', 3);
        $this->assertDatabaseCount('job_call_logs', 3);
        $this->assertDatabaseCount('job_assignments', 1);
        $this->assertDatabaseCount('job_status_history', 6);
        $this->assertDatabaseCount('job_remarks', 1);
        $this->assertDatabaseCount('vehicle_gps_logs', 1);
        $this->assertDatabaseCount('job_external_references', 1);
        $this->assertDatabaseCount('notifications', 1);

        $activeJob = EmergencyJob::query()
            ->where('job_id', 'VMC-DEMO-0001')
            ->with(['ward', 'callLogs', 'assignments.vehicle', 'assignments.team', 'statusHistory'])
            ->firstOrFail();

        $this->assertSame(EmergencyJob::STATUS_EN_ROUTE, $activeJob->status);
        $this->assertSame('Central Ward', $activeJob->ward->name);
        $this->assertCount(1, $activeJob->callLogs);
        $this->assertCount(1, $activeJob->assignments);
        $this->assertSame('DEMO-FIRE-01', $activeJob->assignments->first()->vehicle->vehicle_number);
        $this->assertSame('Central Response Team', $activeJob->assignments->first()->team->name);
        $this->assertCount(3, $activeJob->statusHistory);

        $transferredJob = EmergencyJob::query()
            ->where('job_id', 'VMC-DEMO-0003')
            ->firstOrFail();

        $this->assertSame(EmergencyJob::STATUS_TRANSFERRED_TO_112, $transferredJob->status);
        $this->assertDatabaseHas('job_external_references', [
            'emergency_job_id' => $transferredJob->id,
            'reference_number' => 'DEMO-112-0003',
        ]);
        $this->assertSame(
            1,
            FireStation::query()->where('code', 'DEMO-CENTRAL')->firstOrFail()->vehicles()->count(),
        );
        $this->assertSame(
            '22.3072000',
            FireStation::query()->where('code', 'DEMO-CENTRAL')->value('latitude'),
        );
        $this->assertSame(
            '10 Station Road, Vadodara',
            FireStation::query()->where('code', 'DEMO-CENTRAL')->value('address'),
        );
        $this->assertSame(1, Vehicle::query()->where('status', Vehicle::STATUS_DEPLOYED)->count());
        $this->assertSame(2, Team::query()->count());
        $this->assertSame(1, JobAssignment::query()->where('status', EmergencyJob::STATUS_EN_ROUTE)->count());
    }
}
