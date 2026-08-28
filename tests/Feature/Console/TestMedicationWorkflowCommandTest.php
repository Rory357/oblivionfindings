<?php

namespace Tests\Feature\Console;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TestMedicationWorkflowCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_only_effective_clinical_administration_evidence_while_retaining_the_full_audit_count(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $medication = ClientMedication::factory()->create([
            'client_id' => $client->id,
            'active' => true,
            'state' => 'active',
            'controlled_drug' => false,
        ]);
        $performer = User::factory()->create(['approved_at' => now()]);
        $corrector = User::factory()->create(['approved_at' => now()]);
        $approver = User::factory()->create(['approved_at' => now()]);
        $administrationAt = now()->subMinute();
        $original = ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'service_context_id' => $client->service_context_id,
            'administered_by' => $performer->id,
            'scheduled_for' => $administrationAt,
            'administered_at' => $administrationAt,
            'status' => 'given',
        ]);

        ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'service_context_id' => $client->service_context_id,
            'administered_by' => $performer->id,
            'scheduled_for' => $administrationAt,
            'administered_at' => $administrationAt,
            'status' => 'withheld',
            'is_correction' => true,
            'corrected_of_id' => $original->id,
            'correction_requested_by' => $corrector->id,
            'correction_status' => 'approved',
            'correction_approved_by' => $approver->id,
            'correction_approved_at' => now()->subSecond(),
        ]);
        ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'service_context_id' => $client->service_context_id,
            'administered_by' => $performer->id,
            'scheduled_for' => $administrationAt,
            'administered_at' => $administrationAt,
            'status' => 'refused',
            'is_correction' => true,
            'corrected_of_id' => $original->id,
            'correction_requested_by' => $corrector->id,
            'correction_status' => 'pending',
        ]);

        $this->artisan('medication:workflow-test', ['--client' => $client->id])
            ->expectsOutputToContain('1 administration record(s) found')
            ->expectsOutputToContain('withheld: 1')
            ->expectsOutputToContain('3 audit record(s) in MAR')
            ->doesntExpectOutputToContain('given: 1')
            ->doesntExpectOutputToContain('refused: 1')
            ->assertSuccessful();
    }
}
