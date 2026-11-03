<?php

namespace Tests\Feature;

use App\Models\EmergencyJob;
use App\Models\FireStation;
use App\Models\JobAssignment;
use App\Models\JobStatusHistory;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Ward;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_operations_api_reports_scoped_counts_trends_and_response_metrics(): void
    {
        [$admin, $station, $ward] = $this->stationAdmin();
        $job = $this->job($ward, [
            'occurred_at' => now()->subMinutes(30),
            'status' => EmergencyJob::STATUS_EN_ROUTE,
        ]);
        $this->history($job, EmergencyJob::STATUS_EN_ROUTE, now()->subMinutes(10));
        $completedJob = $this->job($ward, [
            'job_id' => 'VMC-REPORT-COMPLETE',
            'occurred_at' => now()->subMinutes(60),
            'status' => EmergencyJob::STATUS_COMPLETED,
        ]);
        $this->history($completedJob, EmergencyJob::STATUS_COMPLETED, now()->subMinutes(10));
        $vehicle = Vehicle::query()->create([
            'fire_station_id' => $station->id,
            'vehicle_number' => 'REPORT-UNIT-01',
            'vehicle_type' => 'FIRE_ENGINE',
            'status' => Vehicle::STATUS_AVAILABLE,
        ]);
        JobAssignment::query()->create([
            'emergency_job_id' => $completedJob->id,
            'fire_station_id' => $station->id,
            'vehicle_id' => $vehicle->id,
            'assigned_at' => now()->subMinutes(55),
            'released_at' => now()->subMinutes(10),
            'status' => JobAssignment::STATUS_COMPLETED,
        ]);

        $otherStation = $this->station('FS-REPORT-OUT', 'Outside Report Station');
        $otherWard = Ward::query()->create(['code' => 'WARD-REPORT-OUT', 'name' => 'Outside Report Ward']);
        $otherStation->wards()->attach($otherWard->id);
        $this->job($otherWard, [
            'job_id' => 'VMC-REPORT-OUTSIDE',
            'priority' => 'CRITICAL',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/operations?'.http_build_query([
                'from' => now()->subDay()->toDateString(),
                'to' => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertJsonPath('data.summary.total_jobs', 2)
            ->assertJsonPath('data.summary.active_jobs', 1)
            ->assertJsonPath('data.summary.average_response_minutes', 20)
            ->assertJsonPath('data.summary.average_resolution_minutes', 50)
            ->assertJsonPath('data.summary.jobs_with_response_time', 1)
            ->assertJsonPath('data.priorities.0.label', 'HIGH')
            ->assertJsonPath('data.stations.0.deployments', 1)
            ->assertJsonPath('data.stations.0.completed_deployments', 1)
            ->assertJsonCount(1, 'data.stations_available')
            ->assertJsonPath('data.daily.1.count', 2);
    }

    public function test_reports_filter_by_station_and_reject_out_of_scope_stations_and_long_ranges(): void
    {
        [$admin, $station, $ward] = $this->stationAdmin();
        $this->job($ward);
        $otherStation = $this->station('FS-REPORT-OTHER', 'Other Report Station');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/operations?'.http_build_query([
                'from' => now()->subDay()->toDateString(),
                'to' => now()->toDateString(),
                'station_id' => $otherStation->id,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('station_id');

        $this->getJson('/api/v1/reports/operations?'.http_build_query([
            'from' => now()->subDays(91)->toDateString(),
            'to' => now()->toDateString(),
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');

        $this->getJson('/api/v1/reports/operations?'.http_build_query([
            'from' => now()->subDay()->toDateString(),
            'to' => now()->toDateString(),
            'station_id' => $station->id,
        ]))
            ->assertOk()
            ->assertJsonPath('data.summary.total_jobs', 1);
    }

    public function test_reports_return_zeroed_metrics_for_an_empty_period(): void
    {
        [$admin] = $this->stationAdmin();
        $from = now()->subDays(10)->toDateString();
        $to = now()->subDays(9)->toDateString();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/reports/operations?'.http_build_query(compact('from', 'to')))
            ->assertOk()
            ->assertJsonPath('data.summary.total_jobs', 0)
            ->assertJsonPath('data.summary.completion_rate', 0)
            ->assertJsonPath('data.summary.average_response_minutes', null)
            ->assertJsonPath('data.daily.0.count', 0)
            ->assertJsonPath('data.daily.1.count', 0);
    }

    public function test_reports_page_renders_filters_metrics_and_is_permission_protected(): void
    {
        [$admin, , $ward] = $this->stationAdmin();
        $this->job($ward, ['status' => EmergencyJob::STATUS_COMPLETED]);

        $this->actingAs($admin)
            ->get(route('app.reports'))
            ->assertOk()
            ->assertSee('Daily incident volume')
            ->assertSee('Station deployment workload')
            ->assertSee('Completed')
            ->assertSee('All accessible stations')
            ->assertSee('CSV')
            ->assertSee('Excel')
            ->assertSee('PDF');

        $member = User::factory()->create();
        $member->roles()->attach(Role::query()->where('code', 'TEAM_MEMBER')->value('id'));
        $this->actingAs($member)->get(route('app.reports'))->assertForbidden();
    }

    private function stationAdmin(): array
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->where('code', 'FIRE_STATION_ADMIN')->value('id'));
        $station = $this->station('FS-REPORT', 'Report Station');
        $station->users()->attach($admin->id);
        $ward = Ward::query()->create(['code' => 'WARD-REPORT', 'name' => 'Report Ward']);
        $station->wards()->attach($ward->id);

        return [$admin, $station, $ward];
    }

    private function station(string $code, string $name): FireStation
    {
        return FireStation::query()->create([
            'code' => $code,
            'name' => $name,
            'address' => '1 Report Road',
        ]);
    }

    private function job(Ward $ward, array $overrides = []): EmergencyJob
    {
        return EmergencyJob::query()->create(array_replace([
            'job_id' => 'VMC-REPORT-'.uniqid(),
            'caller_name' => 'Report Caller',
            'caller_phone' => '+91 98765 43210',
            'call_source' => 'PHONE',
            'incident_type' => 'STRUCTURE_FIRE',
            'priority' => 'HIGH',
            'occurred_at' => now()->subMinutes(30),
            'ward_id' => $ward->id,
            'society_name' => 'Report Society',
            'incident_address' => '10 Report Road',
            'status' => EmergencyJob::STATUS_RECEIVED,
        ], $overrides));
    }

    private function history(EmergencyJob $job, string $status, $changedAt): void
    {
        JobStatusHistory::query()->create([
            'emergency_job_id' => $job->id,
            'previous_status' => null,
            'new_status' => $status,
            'changed_at' => $changedAt,
        ]);
    }
}
