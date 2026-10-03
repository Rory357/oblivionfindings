<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientInrRecord;
use App\Models\ClientMedicalProfile;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationAllergy;
use App\Models\MedicationEvent;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Audit\MedicationEventRecorder;
use App\Services\Medication\ClientAllergyRecordService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonMedicationCommandsTest extends TestCase
{
    use RefreshDatabase;

    private Client $person;
    private User $lead;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-03 09:00', 'Pacific/Auckland'));
        $this->seed(RbacSeeder::class);
        $site = Site::factory()->create(['is_active' => true]);
        $this->person = Client::factory()->create(['site_id' => $site->id]);
        $this->lead = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->lead->permissionOverrides()->syncWithoutDetaching(Permission::query()->whereIn('key', ['clients.viewAny', 'clients.update', 'medications.view', 'medications.orders.manage', 'medications.administer.correct'])->pluck('id')->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all());
        HrEmployeeProfile::factory()->create(['user_id' => $this->lead->id, 'primary_site_id' => $site->id, 'start_date' => '2026-01-01', 'is_active' => true]);
        $this->actingAs($this->lead);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_legacy_copy_preserves_sources_and_distinct_reactions_and_starts_unreviewed(): void
    {
        $source = MedicationAllergy::create(['client_id' => $this->person->id, 'allergen' => 'Penicillin', 'severity' => 'severe', 'reaction' => 'Swelling', 'notes' => 'Original evidence', 'recorded_by' => $this->lead->id]);
        $duplicate = MedicationAllergy::create(['client_id' => $this->person->id, 'allergen' => ' penicillin ', 'severity' => 'severe', 'reaction' => 'swelling', 'notes' => 'Original evidence', 'recorded_by' => $this->lead->id]);
        $different = MedicationAllergy::create(['client_id' => $this->person->id, 'allergen' => 'Penicillin', 'severity' => 'mild', 'reaction' => 'Rash', 'recorded_by' => $this->lead->id]);
        $before = $source->getAttributes();
        $service = app(ClientAllergyRecordService::class);
        $profile = DB::transaction(fn () => $service->copyLegacy(Client::whereKey($this->person->id)->lockForUpdate()->firstOrFail()));
        $this->assertCount(2, $profile->allergy_records);
        $this->assertNull($profile->allergies_reviewed_at);
        $this->assertEquals($before, $source->fresh()->getAttributes());
        $this->assertDatabaseHas('medication_allergies', ['id' => $different->id, 'deleted_at' => null]);
        $merged = collect($profile->allergy_records)->firstWhere('reaction', 'Swelling');
        $this->assertEqualsCanonicalizing([$source->id, $duplicate->id], $merged['source_register_ids']);
        $this->assertCount(2, $merged['source_evidence']);
        DB::transaction(fn () => $service->copyLegacy(Client::whereKey($this->person->id)->lockForUpdate()->firstOrFail()));
        $this->assertCount(2, $profile->fresh()->allergy_records);
    }

    public function test_no_known_requires_a_leads_check_and_same_retry_creates_one_event(): void
    {
        $summary = $this->getJson($this->url('/allergies'))->assertOk()->json();
        $payload = ['action' => 'review', 'digest' => $summary['digest'], 'request_uuid' => (string) Str::uuid(), 'method' => 'Checked with the person and GP record', 'no_known' => true];
        $this->postJson($this->url('/allergies'), $payload)->assertOk()->assertJsonPath('status', 'no_known');
        $this->postJson($this->url('/allergies'), $payload)->assertOk()->assertJsonPath('status', 'no_known');
        $this->assertSame(1, MedicationEvent::where('kind', 'allergy.review')->count());
        $this->postJson($this->url('/allergies'), [...$payload, 'method' => 'Different evidence'])->assertConflict();
    }

    public function test_edits_clear_review_and_keep_removed_entry_history(): void
    {
        $service = app(ClientAllergyRecordService::class);
        $digest = $service->summary($this->person)['digest'];
        $key = (string) Str::uuid();
        $saved = $this->postJson($this->url('/allergies'), ['action' => 'edit', 'digest' => $digest, 'request_uuid' => (string) Str::uuid(), 'records' => [['key' => $key, 'allergen' => 'Peanut', 'severity' => 'severe', 'reaction' => 'Swelling']]])->assertOk()->json();
        $this->postJson($this->url('/allergies'), ['action' => 'review', 'digest' => $saved['digest'], 'request_uuid' => (string) Str::uuid(), 'method' => 'Checked the GP record'])->assertOk();
        $this->postJson($this->url('/allergies'), ['action' => 'edit', 'digest' => $saved['digest'], 'request_uuid' => (string) Str::uuid(), 'records' => []])->assertOk()->assertJsonPath('status', 'none')->assertJsonPath('reviewed', null);
        $record = ClientMedicalProfile::where('client_id', $this->person->id)->firstOrFail()->allergy_records[0];
        $this->assertSame($key, $record['key']);
        $this->assertNotEmpty($record['removed_at']);
        $this->postJson($this->url('/allergies'), ['action' => 'edit', 'digest' => $digest, 'request_uuid' => (string) Str::uuid(), 'records' => [['key' => $key, 'allergen' => 'Peanut']]])->assertUnprocessable();
    }

    public function test_event_failure_rolls_back_allergy_edit_and_receipt(): void
    {
        $this->mock(MedicationEventRecorder::class)->shouldReceive('append')->once()->andThrow(new \RuntimeException('synthetic event failure'));
        $this->withoutExceptionHandling();
        try {
            $this->postJson($this->url('/allergies'), ['action' => 'review', 'digest' => app(ClientAllergyRecordService::class)->summary($this->person)['digest'], 'request_uuid' => (string) Str::uuid(), 'method' => 'Checked with the person', 'no_known' => true]);
            $this->fail('The injected recorder failure must escape.');
        } catch (\RuntimeException $error) {
            $this->assertSame('synthetic event failure', $error->getMessage());
        }
        $this->assertDatabaseMissing('client_medical_profiles', ['client_id' => $this->person->id]);
        $this->assertDatabaseCount('medication_idempotency_results', 0);
    }

    public function test_unlinked_inr_requires_reason_and_preserves_instruction_without_calculation(): void
    {
        $payload = ['request_uuid' => (string) Str::uuid(), 'inr_value' => 2.4, 'tested_on' => '2026-10-03', 'instruction' => 'Follow recorded prescriber instruction', 'instruction_source' => 'GP instruction dated 3 October'];
        $this->postJson($this->url('/clinical/inr'), $payload)->assertUnprocessable()->assertJsonValidationErrors('unlinked_reason');
        $payload['unlinked_reason'] = 'Medicine order is awaiting entry';
        $this->postJson($this->url('/clinical/inr'), $payload)->assertOk();
        $this->postJson($this->url('/clinical/inr'), $payload)->assertOk();
        $this->assertDatabaseCount('client_inr_records', 1);
        $this->assertDatabaseHas('client_inr_records', ['client_id' => $this->person->id, 'dose_mg' => null, 'instruction' => $payload['instruction'], 'unlinked_reason' => $payload['unlinked_reason']]);
        $this->assertSame(1, MedicationEvent::where('kind', 'clinical.inr')->count());
    }

    public function test_inr_link_rejects_foreign_medicine_and_marking_error_retains_result(): void
    {
        $record = ClientInrRecord::create(['client_id' => $this->person->id, 'inr_value' => 2.4, 'tested_on' => '2026-10-03', 'recorded_by' => $this->lead->id]);
        $other = Client::factory()->create(['site_id' => $this->person->site_id]);
        $foreign = ClientMedication::create(['client_id' => $other->id, 'name' => 'Warfarin', 'dosage' => 'Recorded instruction', 'state' => 'active', 'active' => true]);
        $this->postJson($this->url('/clinical/link-inr'), ['request_uuid' => (string) Str::uuid(), 'record_id' => $record->id, 'client_medication_id' => $foreign->id])->assertNotFound();
        $this->postJson($this->url('/clinical/disable-inr'), ['request_uuid' => (string) Str::uuid(), 'record_id' => $record->id])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson($this->url('/clinical/disable-inr'), ['request_uuid' => (string) Str::uuid(), 'record_id' => $record->id, 'reason' => 'Entered against the wrong test date'])->assertOk();
        $this->assertSame('2.4', $record->fresh()->inr_value);
        $this->assertNotNull($record->fresh()->disabled_at);
    }

    public function test_correction_requester_cannot_approve_and_no_success_receipt_is_written(): void
    {
        $med = ClientMedication::create(['client_id' => $this->person->id, 'name' => 'Paracetamol', 'dosage' => '1 tablet', 'state' => 'active', 'active' => true]);
        $original = ClientMedicationAdministration::create(['client_id' => $this->person->id, 'client_medication_id' => $med->id, 'administered_by' => $this->lead->id, 'administered_at' => now(), 'status' => 'given']);
        $correction = $original->replicate();
        $correction->forceFill(['is_correction' => true, 'corrected_of_id' => $original->id, 'correction_requested_by' => $this->lead->id, 'correction_status' => 'pending', 'correction_reason' => 'Correcting the note'])->save();
        $this->postJson($this->url('/doses/'.$correction->id.'/corrections/approve'), ['request_uuid' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertSame('pending', $correction->fresh()->correction_status);
        $this->assertDatabaseCount('medication_idempotency_results', 0);
        $this->getJson($this->url('/doses/'.$original->id))->assertOk()->assertJsonCount(2, 'chain');
    }

    private function url(string $suffix): string
    {
        return '/emar/clients/'.$this->person->id.'/record'.$suffix;
    }
}
