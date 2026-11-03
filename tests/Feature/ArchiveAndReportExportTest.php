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

class ArchiveAndReportExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_closed_incident_archive_preserves_history_and_can_be_restored(): void
    {
        [$admin, , $ward] = $this->stationAdmin();
        $job = $this->job($ward, EmergencyJob::STATUS_COMPLETED);
        JobStatusHistory::query()->create([
            'emergency_job_id' => $job->id,
            'previous_status' => 'IN_PROGRESS',
            'new_status' => EmergencyJob::STATUS_COMPLETED,
            'changed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('app.jobs.archive', $job->id))
            ->assertRedirect(route('app.archive'));

        $this->assertSoftDeleted('emergency_jobs', ['id' => $job->id]);
        $this->assertDatabaseHas('job_status_history', [
            'emergency_job_id' => $job->id,
            'new_status' => EmergencyJob::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'jobs.archived',
            'subject_id' => (string) $job->id,
        ]);
        $this->actingAs($admin)
            ->get(route('app.archive'))
            ->assertOk()
            ->assertSee($job->job_id);

        $this->post(route('app.archive.restore', $job->id))
            ->assertRedirect(route('app.archive'));

        $this->assertNotSoftDeleted('emergency_jobs', ['id' => $job->id]);
        $this->assertSame(EmergencyJob::STATUS_COMPLETED, $job->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'jobs.restored',
            'subject_id' => (string) $job->id,
        ]);
    }

    public function test_only_closed_jobs_without_active_assignments_can_be_archived(): void
    {
        [$admin, $station, $ward] = $this->stationAdmin();
        $activeJob = $this->job($ward, EmergencyJob::STATUS_EN_ROUTE);
        $completedJob = $this->job($ward, EmergencyJob::STATUS_COMPLETED);
        $vehicle = Vehicle::query()->create([
            'fire_station_id' => $station->id,
            'vehicle_number' => 'ARCHIVE-UNIT-01',
            'vehicle_type' => 'FIRE_ENGINE',
            'status' => Vehicle::STATUS_AVAILABLE,
        ]);
        JobAssignment::query()->create([
            'emergency_job_id' => $completedJob->id,
            'fire_station_id' => $station->id,
            'vehicle_id' => $vehicle->id,
            'assigned_at' => now()->subMinutes(10),
            'status' => JobAssignment::STATUS_COMPLETED,
        ]);

        $this->actingAs($admin)->post(route('app.jobs.archive', $activeJob->id))
            ->assertSessionHasErrors('job');
        $this->post(route('app.jobs.archive', $completedJob->id))
            ->assertSessionHasErrors('job');

        $this->assertNotSoftDeleted('emergency_jobs', ['id' => $activeJob->id]);
        $this->assertNotSoftDeleted('emergency_jobs', ['id' => $completedJob->id]);
    }

    public function test_archived_incidents_cannot_be_restored_outside_the_actors_station_scope(): void
    {
        [$admin] = $this->stationAdmin();
        $otherStation = $this->station('FS-ARCHIVE-OTHER');
        $otherWard = Ward::query()->create(['code' => 'WARD-ARCHIVE-OTHER', 'name' => 'Outside Archive Ward']);
        $otherStation->wards()->attach($otherWard->id);
        $job = $this->job($otherWard, EmergencyJob::STATUS_COMPLETED);
        $job->delete();

        $this->actingAs($admin)
            ->post(route('app.archive.restore', $job->id))
            ->assertNotFound();

        $this->assertSoftDeleted('emergency_jobs', ['id' => $job->id]);
    }

    public function test_station_scoped_user_can_restore_archive_after_ward_is_soft_deleted(): void
    {
        [$admin, , $ward] = $this->stationAdmin();
        $job = $this->job($ward, EmergencyJob::STATUS_COMPLETED);
        $job->delete();
        $ward->delete();

        $this->actingAs($admin)
            ->post(route('app.archive.restore', $job->id))
            ->assertRedirect(route('app.archive'));

        $this->assertNotSoftDeleted('emergency_jobs', ['id' => $job->id]);
    }

    public function test_archive_api_supports_scoped_archive_and_restore_actions(): void
    {
        [$admin, , $ward] = $this->stationAdmin();
        $job = $this->job($ward, EmergencyJob::STATUS_TRANSFERRED_TO_112);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/jobs/'.$job->id.'/archive')
            ->assertOk()
            ->assertJsonPath('data.status', EmergencyJob::STATUS_TRANSFERRED_TO_112);
        $this->assertSoftDeleted('emergency_jobs', ['id' => $job->id]);

        $this->postJson('/api/v1/jobs/'.$job->id.'/restore')
            ->assertOk()
            ->assertJsonPath('data.status', EmergencyJob::STATUS_TRANSFERRED_TO_112);
        $this->assertNotSoftDeleted('emergency_jobs', ['id' => $job->id]);
    }

    public function test_reports_download_csv_xlsx_and_pdf_for_the_selected_period(): void
    {
        [$admin, , $ward] = $this->stationAdmin();
        $this->job($ward, EmergencyJob::STATUS_COMPLETED);
        $query = ['from' => now()->toDateString(), 'to' => now()->toDateString()];

        $csv = $this->actingAs($admin)->get(route('app.reports.export', ['format' => 'csv'] + $query));
        $csv->assertOk()
            ->assertDownload('vmc-operations-'.$query['from'].'-to-'.$query['to'].'.csv')
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Total incidents', $csv->streamedContent());

        $xlsx = $this->get(route('app.reports.export', ['format' => 'xlsx'] + $query));
        $xlsx->assertOk()
            ->assertDownload('vmc-operations-'.$query['from'].'-to-'.$query['to'].'.xlsx')
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringStartsWith("PK\x03\x04", $xlsx->streamedContent());

        $pdf = $this->get(route('app.reports.export', ['format' => 'pdf'] + $query));
        $pdf->assertOk()
            ->assertDownload('vmc-operations-'.$query['from'].'-to-'.$query['to'].'.pdf')
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());

        $otherStation = $this->station('FS-EXPORT-OUTSIDE');
        $this->get(route('app.reports.export', [
            'format' => 'csv',
            ...$query,
            'station_id' => $otherStation->id,
        ]))->assertRedirect()->assertSessionHasErrors('station_id');

        $member = User::factory()->create();
        $member->roles()->attach(Role::query()->where('code', 'TEAM_MEMBER')->value('id'));
        $this->actingAs($member)
            ->get(route('app.reports.export', ['format' => 'csv'] + $query))
            ->assertForbidden();
    }

    private function stationAdmin(): array
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->where('code', 'FIRE_STATION_ADMIN')->value('id'));
        $station = $this->station('FS-ARCHIVE');
        $station->users()->attach($admin->id);
        $ward = Ward::query()->create(['code' => 'WARD-ARCHIVE', 'name' => 'Archive Ward']);
        $station->wards()->attach($ward->id);

        return [$admin, $station, $ward];
    }

    private function station(string $code): FireStation
    {
        return FireStation::query()->create([
            'code' => $code,
            'name' => 'Station '.$code,
            'address' => '1 Archive Road',
        ]);
    }

    private function job(Ward $ward, string $status): EmergencyJob
    {
        return EmergencyJob::query()->create([
            'job_id' => 'VMC-ARCHIVE-'.uniqid(),
            'caller_name' => 'Archive Caller',
            'caller_phone' => '+91 98765 43210',
            'call_source' => 'PHONE',
            'incident_type' => 'STRUCTURE_FIRE',
            'priority' => 'HIGH',
            'occurred_at' => now(),
            'ward_id' => $ward->id,
            'incident_address' => '10 Archive Road',
            'status' => $status,
        ]);
    }
}
