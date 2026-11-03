<?php

namespace Tests\Feature;

use App\Models\EmergencyJob;
use App\Models\FireStation;
use App\Models\JobCallLog;
use App\Models\JobStatusHistory;
use App\Models\Role;
use App\Models\User;
use App\Models\Ward;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardActivityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_dashboard_renders_recent_calls_and_jobs_in_a_rotating_feed(): void
    {
        [$user, $ward] = $this->userAndWard();
        $job = $this->job($ward, 'ROTATE-JOB-01');
        JobCallLog::query()->create([
            'emergency_job_id' => $job->id,
            'caller_name' => 'Recent Caller',
            'caller_phone' => '+91-90000-01001',
            'call_source' => 'PHONE',
            'received_at' => now(),
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Incoming calls &amp; new jobs', false)
            ->assertSee('Operations overview')
            ->assertSee('Schematic map of saved station, incident and vehicle coordinates')
            ->assertSee('data-activity-status', false)
            ->assertSee('nav-dropdown-trigger', false)
            ->assertSee('data-sidebar-toggle', false)
            ->assertSee('Incident archive')
            ->assertSee('NEW CALL')
            ->assertSee('NEW JOB')
            ->assertSee('Society One')
            ->assertSee('Recent Caller')
            ->assertSee('ROTATE-JOB-01')
            ->assertSee('data-activity-carousel', false)
            ->assertSee('updates every 15 seconds');
    }

    public function test_dashboard_activity_endpoint_returns_new_jobs_and_calls_with_station_scope(): void
    {
        [$user, $ward] = $this->userAndWard();
        $job = $this->job($ward, 'VISIBLE-JOB-01');
        JobCallLog::query()->create([
            'emergency_job_id' => $job->id,
            'caller_name' => 'Visible Caller',
            'caller_phone' => '90000-01002',
            'call_source' => 'PHONE',
            'received_at' => now(),
        ]);
        JobStatusHistory::query()->create([
            'emergency_job_id' => $job->id,
            'previous_status' => EmergencyJob::STATUS_RECEIVED,
            'new_status' => EmergencyJob::STATUS_EN_ROUTE,
            'changed_at' => now()->addSecond(),
        ]);

        $outsideStation = FireStation::query()->create([
            'code' => 'FS-ACTIVITY-OUTSIDE',
            'name' => 'Outside Activity Station',
            'address' => 'Outside scope',
        ]);
        $outsideWard = Ward::query()->create([
            'code' => 'WARD-ACTIVITY-OUTSIDE',
            'name' => 'Outside Activity Ward',
        ]);
        $outsideStation->wards()->attach($outsideWard->id);
        $outsideJob = $this->job($outsideWard, 'HIDDEN-JOB-02');
        JobCallLog::query()->create([
            'emergency_job_id' => $outsideJob->id,
            'caller_name' => 'Hidden Caller',
            'caller_phone' => '90000-01003',
            'call_source' => 'PHONE',
            'received_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson('/dashboard/activity')
            ->assertOk()
            ->assertJsonFragment(['type' => 'NEW CALL'])
            ->assertJsonFragment(['type' => 'NEW JOB'])
            ->assertJsonFragment(['type' => 'STATUS UPDATE'])
            ->assertJsonFragment(['job_id' => 'VISIBLE-JOB-01'])
            ->assertJsonMissing(['job_id' => 'HIDDEN-JOB-02'])
            ->assertJsonMissing(['caller' => 'Hidden Caller']);

        $this->assertCount(3, $response->json('data'));
    }

    public function test_dashboard_activity_endpoint_requires_job_view_permission(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('code', 'REPORT_VIEWER')->value('id'));

        $this->actingAs($user)->getJson('/dashboard/activity')->assertForbidden();
    }

    /**
     * @return array{User, Ward}
     */
    private function userAndWard(): array
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('code', 'FIRE_STATION_ADMIN')->value('id'));
        $station = FireStation::query()->create([
            'code' => 'FS-ACTIVITY',
            'name' => 'Activity Station',
            'address' => '1 Response Road',
            'admin_user_id' => $user->id,
        ]);
        $ward = Ward::query()->create(['code' => 'WARD-ACTIVITY', 'name' => 'Activity Ward']);
        $station->wards()->attach($ward->id);

        return [$user, $ward];
    }

    private function job(Ward $ward, string $jobId): EmergencyJob
    {
        return EmergencyJob::query()->create([
            'job_id' => $jobId,
            'caller_name' => 'Caller for '.$jobId,
            'caller_phone' => '90000-01000',
            'call_source' => 'PHONE',
            'incident_type' => 'STRUCTURE_FIRE',
            'priority' => 'HIGH',
            'occurred_at' => now(),
            'ward_id' => $ward->id,
            'incident_address' => '1 Test Road',
            'society_name' => 'Society One',
            'status' => EmergencyJob::STATUS_RECEIVED,
        ]);
    }
}
