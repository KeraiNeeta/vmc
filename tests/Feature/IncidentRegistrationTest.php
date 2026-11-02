<?php

namespace Tests\Feature;

use App\Models\EmergencyJob;
use App\Models\FireStation;
use App\Models\Role;
use App\Models\User;
use App\Models\Ward;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_dispatch_operator_can_register_an_incident_with_call_and_status_history(): void
    {
        [$operator, $ward] = $this->operatorWithWard();

        $this->actingAs($operator, 'sanctum')
            ->postJson('/api/v1/jobs', $this->incidentData($ward))
            ->assertCreated()
            ->assertJsonPath('data.status', EmergencyJob::STATUS_RECEIVED)
            ->assertJsonPath('data.ward.id', $ward->id)
            ->assertJsonPath('data.call_logs.0.call_source', 'PHONE')
            ->assertJsonPath('data.status_history.0.new_status', EmergencyJob::STATUS_RECEIVED);

        $job = EmergencyJob::query()->sole();
        $this->assertMatchesRegularExpression('/^VMC-\d{8}-[A-Z0-9]{8}$/', $job->job_id);
        $this->assertDatabaseHas('job_call_logs', [
            'emergency_job_id' => $job->id,
            'caller_phone' => '+91 98765 43210',
            'created_by' => $operator->id,
        ]);
        $this->assertDatabaseHas('job_status_history', [
            'emergency_job_id' => $job->id,
            'previous_status' => null,
            'new_status' => EmergencyJob::STATUS_RECEIVED,
            'changed_by' => $operator->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'jobs.created',
            'subject_id' => (string) $job->id,
        ]);
    }

    public function test_caller_search_finds_recent_calls_and_duplicate_close_records_the_original(): void
    {
        [$operator, $ward] = $this->operatorWithWard();
        $this->actingAs($operator, 'sanctum');
        $original = $this->postJson('/api/v1/jobs', $this->incidentData($ward))->assertCreated()->json('data');
        $duplicate = $this->postJson('/api/v1/jobs', $this->incidentData($ward, [
            'caller_phone' => '+91-98765-43210',
            'incident_address' => 'Different caller-reported location',
        ]))->assertCreated()->json('data');

        $this->getJson('/api/v1/callers/search?search=9876543210')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->postJson('/api/v1/jobs/'.$duplicate['id'].'/duplicate', [
            'duplicate_of' => $original['id'],
            'remarks' => 'Caller confirmed this is the same incident.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', EmergencyJob::STATUS_DUPLICATE)
            ->assertJsonPath('data.external_references.0.reference_number', $original['job_id']);

        $this->assertDatabaseHas('job_external_references', [
            'emergency_job_id' => $duplicate['id'],
            'reference_type' => 'DUPLICATE_OF',
            'reference_number' => $original['job_id'],
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'jobs.duplicate_closed',
            'subject_id' => (string) $duplicate['id'],
        ]);
    }

    public function test_incident_can_be_transferred_to_112_with_an_external_reference(): void
    {
        [$operator, $ward] = $this->operatorWithWard();
        $this->actingAs($operator, 'sanctum');
        $job = $this->postJson('/api/v1/jobs', $this->incidentData($ward))->assertCreated()->json('data');

        $this->postJson('/api/v1/jobs/'.$job['id'].'/transfer-112', [
            'reference_number' => '112-REF-2026-001',
            'details' => 'Handoff acknowledged by the 112 control room.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', EmergencyJob::STATUS_TRANSFERRED_TO_112)
            ->assertJsonPath('data.external_references.0.reference_type', '112');

        $this->assertDatabaseHas('job_status_history', [
            'emergency_job_id' => $job['id'],
            'new_status' => EmergencyJob::STATUS_TRANSFERRED_TO_112,
            'changed_by' => $operator->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'jobs.transferred_to_112',
            'subject_id' => (string) $job['id'],
        ]);
    }

    public function test_incident_registration_and_caller_search_are_station_scoped(): void
    {
        [$operator, $ward] = $this->operatorWithWard();
        $otherStation = FireStation::query()->create([
            'code' => 'FS-OUTSIDE',
            'name' => 'Outside Station',
            'address' => 'Outside scope',
        ]);
        $otherWard = Ward::query()->create(['code' => 'WARD-OUTSIDE', 'name' => 'Outside Ward']);
        $otherStation->wards()->attach($otherWard->id);
        EmergencyJob::query()->create([
            ...$this->incidentData($otherWard),
            'job_id' => 'OUTSIDE-INCIDENT-1',
            'status' => EmergencyJob::STATUS_RECEIVED,
            'created_by' => null,
        ]);

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/v1/callers/search?search=9876543210')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/jobs')->assertOk()->assertJsonPath('meta.total', 0);

        $this->postJson('/api/v1/jobs', $this->incidentData($otherWard))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ward_id');

        $this->assertNotSame($ward->id, $otherWard->id);
    }

    public function test_dispatch_operator_can_use_server_rendered_registration_and_incident_detail(): void
    {
        [$operator, $ward] = $this->operatorWithWard();
        $this->actingAs($operator)
            ->get('/jobs/new')
            ->assertOk()
            ->assertSee('Register an emergency')
            ->assertSee('Caller lookup');

        $response = $this->post('/jobs', $this->incidentData($ward));
        $job = EmergencyJob::query()->sole();
        $response->assertRedirect(route('app.jobs.show', $job));

        $this->get(route('app.jobs.show', $job))
            ->assertOk()
            ->assertSee($job->job_id)
            ->assertSee('Incident history');
    }

    /**
     * @return array{User, Ward}
     */
    private function operatorWithWard(): array
    {
        $operator = User::factory()->create();
        $operator->roles()->attach(Role::query()->where('code', 'DISPATCH_OPERATOR')->value('id'));
        $station = FireStation::query()->create([
            'code' => 'FS-INCIDENT',
            'name' => 'Incident Station',
            'address' => '1 Response Road',
        ]);
        $station->users()->attach($operator->id);
        $ward = Ward::query()->create(['code' => 'WARD-INCIDENT', 'name' => 'Incident Ward']);
        $station->wards()->attach($ward->id);

        return [$operator, $ward];
    }

    private function incidentData(Ward $ward, array $overrides = []): array
    {
        return array_replace([
            'caller_name' => 'Test Caller',
            'caller_phone' => '+91 98765 43210',
            'call_source' => 'PHONE',
            'incident_type' => 'STRUCTURE_FIRE',
            'priority' => 'HIGH',
            'occurred_at' => now()->subMinutes(5)->toIso8601String(),
            'ward_id' => $ward->id,
            'incident_address' => '10 Test Road, Vadodara',
            'latitude' => 22.3072,
            'longitude' => 73.1812,
            'remarks' => 'Smoke reported at the premises.',
        ], $overrides);
    }
}
