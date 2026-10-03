<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationFollowup;
use App\Models\MedicationOrderRevision;
use App\Models\Permission;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\MedicationOrderWorkflow;
use App\Services\MedicationScanVerificationService;
use Carbon\Carbon;
use Database\Factories\UserFactory;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class MedicationOrderVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-23 10:00:00', config('app.worker_timezone', 'Pacific/Auckland'))->utc());
        $this->seed(RbacSeeder::class);
        Storage::fake('local');

        $this->site = Site::factory()->create([
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $serviceContext = ServiceContext::factory()->create([
            'name' => 'Medication verification test',
            'type' => 'residential',
            'is_active' => true,
        ]);
        $this->client = Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $serviceContext->id,
            'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_creation_and_update_always_require_the_explicit_verification_transition(): void
    {
        $creator = $this->makeSiteUser(['medications.orders.manage', 'medications.orders.verify'], $this->site, $this->client);
        $checker = $this->makeSiteUser(['medications.orders.verify'], $this->site, $this->client);
        $this->actingAs($creator)->post('/emar/orders', $this->entryInput(['name' => 'Pending high-risk order', 'high_risk' => true]))->assertRedirect()->assertSessionHasNoErrors();
        $revision = MedicationOrderRevision::sole();
        $medication = $revision->medication;
        $this->assertSame('pending_verification', $medication->approval_status);
        $this->assertSame($creator->id, (int) $medication->created_by);
        $this->assertNull($medication->verified_by);
        $this->assertNull($medication->verified_at);
        $this->actingAs($checker)->post('/emar/order-revisions/'.$revision->id.'/check', $this->checkInput())->assertRedirect()->assertSessionHasNoErrors();
        $medication->refresh();
        $verifiedAt = $medication->verified_at->toISOString();
        $this->actingAs($creator)->post('/emar/orders', $this->entryInput(['name' => $medication->name, 'dosage' => '10 mg', 'high_risk' => true], $medication))->assertRedirect()->assertSessionHasNoErrors();
        $change = MedicationOrderRevision::latest('id')->firstOrFail();
        $this->assertSame('pending', $change->status);
        $this->assertSame('10 mg', $change->version->dosage);
        $this->assertSame('5 mg', $medication->refresh()->dosage);
        $this->assertSame('verified', $medication->approval_status);
        $this->assertSame($checker->id, (int) $medication->verified_by);
        $this->assertSame($verifiedAt, $medication->verified_at->toISOString());
        $this->assertSame(1, $medication->version);
    }

    public function test_model_defaults_and_clinical_edits_cannot_bypass_fresh_verification(): void
    {
        $creator = User::factory()->create();
        $medication = ClientMedication::query()->create([
            'client_id' => $this->client->id,
            'created_by' => $creator->id,
            'name' => 'Model-owned pending medicine',
            'dosage' => '5 mg',
            'frequency' => 'Once daily',
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'verified_by' => $creator->id,
            'verified_at' => now(),
        ]);
        $this->assertSame('pending_verification', $medication->approval_status);
        $this->assertNull($medication->verified_by);
        $this->assertNull($medication->verified_at);

        $medication->forceFill([
            'approval_status' => 'verified',
            'verified_by' => $creator->id,
            'verified_at' => now(),
        ])->saveQuietly();
        $medication->forceFill([
            'dosage' => '10 mg',
            'approval_status' => 'verified',
            'verified_by' => $creator->id,
            'verified_at' => now(),
        ])->save();

        $medication->refresh();
        $this->assertSame('pending_verification', $medication->approval_status);
        $this->assertNull($medication->verified_by);
        $this->assertNull($medication->verified_at);
        $this->assertNull($medication->rejection_reason);
    }

    public function test_new_order_version_preserves_prior_provenance_and_requires_fresh_verification(): void
    {
        $creator = User::factory()->create();
        $verifier = User::factory()->create();
        $editor = User::factory()->create();
        $medication = $this->pendingMedication([
            'created_by' => $creator->id,
            'version' => 1,
        ]);
        $medication->forceFill([
            'approval_status' => 'verified',
            'verified_by' => $verifier->id,
            'verified_at' => now(),
        ])->saveQuietly();
        $verifiedAt = $medication->verified_at?->toISOString();

        $newVersion = $medication->createVersion($editor->id, 'Dose changed by the prescriber.');

        $medication->refresh();
        $this->assertSame($newVersion->id, (int) $medication->superseded_by);
        $this->assertSame('verified', $medication->approval_status);
        $this->assertSame($verifier->id, (int) $medication->verified_by);
        $this->assertSame($verifiedAt, $medication->verified_at?->toISOString());

        $newVersion->refresh();
        $this->assertSame(2, $newVersion->version);
        $this->assertSame($editor->id, (int) $newVersion->created_by);
        $this->assertSame('pending_verification', $newVersion->approval_status);
        $this->assertNull($newVersion->verified_by);
        $this->assertNull($newVersion->verified_at);
        $this->assertNull($newVersion->rejection_reason);
        $this->assertDatabaseHas('medication_order_versions', [
            'client_medication_id' => $medication->id,
            'version_number' => 1,
            'is_prn' => false,
        ]);
    }

    public function test_management_and_client_edit_permissions_do_not_authorize_verification(): void
    {
        $manager = $this->makeSiteUser(
            ['medications.orders.manage', 'clients.update', 'sites.viewAll'],
            $this->site,
            $this->client,
        );
        $medication = $this->pendingMedication(['created_by' => User::factory()->create()->id]);

        $this->actingAs($manager)
            ->postJson("/emar/medications/{$medication->id}/verify")
            ->assertForbidden();
        $this->actingAs($manager)
            ->postJson("/emar/medications/{$medication->id}/reject", [
                'rejection_reason' => 'Must not be accepted.',
            ])
            ->assertForbidden();

        $this->assertSame('pending_verification', $medication->refresh()->approval_status);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'medication_order.checked',
            'auditable_id' => $medication->id,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'medication_order.sent_back',
            'auditable_id' => $medication->id,
        ]);
    }

    public function test_explicit_global_site_scope_requires_and_accepts_the_exact_verification_action(): void
    {
        $otherSite = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $globalVerifier = $this->makeSiteUser(['medications.orders.verify', 'sites.viewAll'], $otherSite);
        Shift::factory()->create(['client_id' => $this->client->id, 'site_id' => $this->site->id, 'user_id' => $globalVerifier->id, 'starts_at' => now()->subHours(2), 'ends_at' => now()->addHours(2), 'actual_starts_at' => now()->subHour(), 'actual_ends_at' => null, 'started_by' => $globalVerifier->id, 'status' => 'in_progress']);
        $medication = $this->pendingMedication(['created_by' => User::factory()->create()->id]);
        $revision = $this->pendingRevision($medication);
        $this->actingAs($globalVerifier)->post('/emar/order-revisions/'.$revision->id.'/check', $this->checkInput())->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('verified', $medication->refresh()->approval_status);
        $this->assertSame($globalVerifier->id, (int) $medication->verified_by);
    }

    public function test_high_risk_order_classes_deny_creator_self_verification_without_a_lone_check(): void
    {
        $creator = $this->makeSiteUser(['medications.orders.verify', 'medications.controlled.view', 'medications.controlled.record'], $this->site, $this->client);
        foreach ([['high_risk' => true], ['controlled_drug' => true], ['witness_required' => true], ['high_risk' => true, 'created_by' => null]] as $riskClass) {
            $medication = $this->pendingMedication(['created_by' => $creator->id, ...$riskClass]);
            $revision = $this->pendingRevision($medication, $creator->id);
            $this->actingAs($creator)->postJson('/emar/order-revisions/'.$revision->id.'/check', $this->checkInput())->assertUnprocessable()->assertJsonValidationErrors('checker');
            $this->assertSame('pending_verification', $medication->refresh()->approval_status);
            $this->assertSame('pending', $revision->refresh()->status);
        }
        $this->assertSame(0, AuditLog::where('action', 'medication_order.checked')->count());
    }

    public function test_distinct_verifier_succeeds_and_replay_does_not_duplicate_the_effect(): void
    {
        $creator = User::factory()->create();
        $verifier = $this->makeSiteUser(['medications.orders.verify'], $this->site, $this->client);
        $medication = $this->pendingMedication(['created_by' => $creator->id, 'high_risk' => true]);
        $revision = $this->pendingRevision($medication);
        $versionBefore = $revision->version->fresh()->getRawOriginal();
        $this->actingAs($verifier)->post('/emar/order-revisions/'.$revision->id.'/check', $this->checkInput())->assertRedirect()->assertSessionHasNoErrors();
        $medication->refresh();
        $verifiedAt = $medication->verified_at?->toISOString();
        $this->assertSame('verified', $medication->approval_status);
        $this->assertSame($verifier->id, (int) $medication->verified_by);
        $this->assertNotNull($verifiedAt);
        $this->assertSame($creator->id, (int) $revision->refresh()->entered_by);
        $this->assertSame($verifier->id, (int) $revision->checked_by);
        $this->assertSame($versionBefore, $revision->version->fresh()->getRawOriginal());
        $audit = AuditLog::where('action', 'medication_order.checked')->where('auditable_id', $medication->id)->sole();
        $this->assertSame($verifier->id, (int) $audit->user_id);
        $this->assertSame($revision->id, (int) $audit->meta['revision_id']);
        $this->assertSame($this->client->id, (int) $audit->meta['client_id']);
        foreach (array_keys($this->checkInput()) as $field) {
            $this->assertTrue($audit->meta[$field]);
        }
        Carbon::setTestNow(now()->addMinute());
        $this->actingAs($verifier)->postJson('/emar/order-revisions/'.$revision->id.'/check', $this->checkInput())->assertUnprocessable()->assertJsonValidationErrors('order');
        $this->actingAs($verifier)->postJson('/emar/order-revisions/'.$revision->id.'/send-back', ['reason' => 'A checked order cannot be sent back in place.'])->assertUnprocessable()->assertJsonValidationErrors('order');
        $this->assertSame($verifiedAt, $medication->refresh()->verified_at?->toISOString());
        $medication->forceFill(['state' => 'ceased', 'active' => false])->saveQuietly();
        $this->actingAs($verifier)->postJson('/emar/order-revisions/'.$revision->id.'/check', $this->checkInput())->assertUnprocessable()->assertJsonValidationErrors('order');
        $this->assertSame($verifiedAt, $medication->refresh()->verified_at?->toISOString());
        $this->assertSame(1, AuditLog::where('action', 'medication_order.checked')->where('auditable_id', $medication->id)->count());
        $this->assertSame($versionBefore, $revision->version->fresh()->getRawOriginal());
    }

    public function test_legacy_scan_evidence_cannot_publish_or_replace_the_source_comparison(): void
    {
        $verifier = $this->makeSiteUser(['medications.orders.verify'], $this->site, $this->client);
        $medication = $this->pendingMedication(['created_by' => User::factory()->create()->id]);
        $revision = $this->pendingRevision($medication);
        $validCode = app(MedicationScanVerificationService::class)->internalCode($this->client, $medication);
        foreach ([['FORGED-MEDICATION-CODE', 'internal_emar'], [$validCode, 'vendor_barcode'], [$validCode, 'internal_emar']] as [$code, $match]) {
            $response = $this->actingAs($verifier)->postJson('/emar/medications/'.$medication->id.'/verify', ['scan_code' => $code, 'scan_source' => 'scanner', 'scan_verified' => true, 'scan_match_source' => $match])->assertStatus(409)->assertJsonPath('orders_url', '/emar/prescriptions?client_id='.$this->client->id.'&order_id='.$medication->id.'&action=check');
            $this->assertStringNotContainsString($code, $response->getContent());
        }
        $this->actingAs($verifier)->postJson('/emar/order-revisions/'.$revision->id.'/check', ['scan_code' => $validCode, 'scan_verified' => true])->assertUnprocessable()->assertJsonValidationErrors(array_keys($this->checkInput()));
        $this->assertSame('pending_verification', $medication->refresh()->approval_status);
        $this->assertSame('pending', $revision->refresh()->status);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'medication_order.checked', 'auditable_id' => $medication->id]);
    }

    public function test_non_pending_or_inactive_orders_cannot_enter_the_verification_transition(): void
    {
        $verifier = $this->makeSiteUser(['medications.orders.verify'], $this->site, $this->client);
        $rejected = $this->pendingMedication(['created_by' => User::factory()->create()->id]);
        $revision = $this->pendingRevision($rejected);
        $rejected->forceFill(['approval_status' => 'rejected'])->saveQuietly();
        $revision->forceFill(['status' => 'sent_back'])->save();
        $ceased = $this->pendingMedication(['created_by' => User::factory()->create()->id, 'state' => 'ceased', 'active' => false]);
        $ceasedRevision = $this->pendingRevision($ceased);
        foreach ([$revision, $ceasedRevision] as $target) {
            $this->actingAs($verifier)->postJson('/emar/order-revisions/'.$target->id.'/check', $this->checkInput())->assertUnprocessable()->assertJsonValidationErrors('order');
        }
        $this->assertSame('rejected', $rejected->refresh()->approval_status);
        $this->assertSame('pending_verification', $ceased->refresh()->approval_status);
        $this->assertSame(0, AuditLog::where('action', 'medication_order.checked')->count());
    }

    public function test_legacy_waiver_cannot_publish_and_lone_check_requires_reason_and_independent_followup(): void
    {
        $creator = $this->makeSiteUser(['medications.orders.manage', 'medications.orders.verify', 'medications.controlled.view', 'medications.controlled.record'], $this->site, $this->client);
        $unqualified = $this->makeSiteUser([], $this->site, $this->client);
        $approver = $this->makeSiteUser(['medications.orders.verify', 'medications.controlled.view', 'medications.controlled.record'], $this->site, $this->client);
        $medication = $this->pendingMedication(['created_by' => $creator->id, 'controlled_drug' => true]);
        $revision = $this->pendingRevision($medication);
        $reason = 'Only the authorised lead is available for the first dose.';
        foreach ([$unqualified, $approver] as $candidate) {
            $response = $this->actingAs($creator)->postJson('/emar/medications/'.$medication->id.'/verify', ['waiver_reason' => $reason, 'waiver_approved_by' => $candidate->id, 'waiver_approver_credential' => UserFactory::TEST_WITNESS_PIN])->assertStatus(409);
            $this->assertStringNotContainsString(UserFactory::TEST_WITNESS_PIN, $response->getContent());
            $this->assertSame('pending_verification', $medication->refresh()->approval_status);
        }
        $this->actingAs($creator)->postJson('/emar/order-revisions/'.$revision->id.'/check', $this->checkInput() + ['mode' => 'alone'])->assertUnprocessable()->assertJsonValidationErrors('lone_reason');
        $this->actingAs($creator)->post('/emar/order-revisions/'.$revision->id.'/check', $this->checkInput() + ['mode' => 'alone', 'lone_reason' => $reason])->assertRedirect()->assertSessionHasNoErrors();
        $revision->refresh();
        $this->assertSame('checked_alone', $revision->status);
        $this->assertSame($reason, $revision->lone_reason);
        $this->assertSame($creator->id, (int) $medication->refresh()->verified_by);
        $followup = MedicationFollowup::where('source_key', 'second-check:'.$revision->id)->sole();
        $this->assertNull($followup->completed_at);
        $this->assertSame('2026-08-24T11:59:59+00:00', $revision->second_due_at->utc()->toIso8601String());
        $this->actingAs($unqualified)->postJson('/emar/order-revisions/'.$revision->id.'/check', $this->checkInput() + ['mode' => 'second'])->assertForbidden();
        $this->actingAs($creator)->postJson('/emar/order-revisions/'.$revision->id.'/check', $this->checkInput() + ['mode' => 'second'])->assertUnprocessable()->assertJsonValidationErrors('checker');
        $this->actingAs($approver)->post('/emar/order-revisions/'.$revision->id.'/check', $this->checkInput() + ['mode' => 'second'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($approver->id, (int) $revision->refresh()->second_checked_by);
        $this->assertNotNull($followup->refresh()->completed_at);
        $audit = AuditLog::where('action', 'medication_order.checked_alone')->where('auditable_id', $medication->id)->sole();
        $this->assertSame($creator->id, (int) $audit->user_id);
        $this->assertSame($reason, $audit->meta['lone_reason']);
        $this->assertStringNotContainsString(UserFactory::TEST_WITNESS_PIN, $audit->toJson());
    }

    public function test_foreign_site_medication_is_concealed_before_waiver_validation(): void
    {
        $verifier = $this->makeSiteUser(
            ['medications.orders.verify'],
            $this->site,
            $this->client,
        );
        $foreignSite = Site::factory()->create([
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $foreignClient = Client::factory()->create([
            'site_id' => $foreignSite->id,
            'service_context_id' => $this->client->service_context_id,
            'status' => 'active',
        ]);
        $medication = $this->pendingMedication([
            'client_id' => $foreignClient->id,
            'created_by' => User::factory()->create()->id,
            'high_risk' => true,
        ]);

        $this->actingAs($verifier)
            ->postJson("/emar/medications/{$medication->id}/verify", [
                'waiver_reason' => 'Incomplete payload must not outrun Site authorization.',
            ])
            ->assertNotFound();
        $this->actingAs($verifier)
            ->postJson("/emar/medications/{$medication->id}/reject")
            ->assertNotFound();

        $this->assertSame('pending_verification', $medication->refresh()->approval_status);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'medication_order.checked',
            'auditable_id' => $medication->id,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'medication_order.sent_back',
            'auditable_id' => $medication->id,
        ]);
    }

    public function test_send_back_is_a_locked_audited_terminal_transition_with_safe_replay(): void
    {
        $creator = User::factory()->create();
        $reviewer = $this->makeSiteUser(['medications.orders.verify'], $this->site, $this->client);
        $medication = $this->pendingMedication(['created_by' => $creator->id]);
        $revision = $this->pendingRevision($medication);
        $versionBefore = $revision->version->fresh()->getRawOriginal();
        $reason = 'The supplied order does not match the signed prescription.';
        $this->actingAs($reviewer)->post('/emar/order-revisions/'.$revision->id.'/send-back', ['reason' => $reason])->assertRedirect()->assertSessionHasNoErrors();
        $medication->refresh();
        $updatedAt = $medication->updated_at?->toISOString();
        $this->assertSame('rejected', $medication->approval_status);
        $this->assertSame($reason, $medication->rejection_reason);
        $this->assertSame('sent_back', $revision->refresh()->status);
        $this->assertSame($reason, $revision->rejection_reason);
        $audit = AuditLog::where('action', 'medication_order.sent_back')->where('auditable_id', $medication->id)->sole();
        $this->assertSame($reviewer->id, (int) $audit->user_id);
        $this->assertSame($revision->id, (int) $audit->meta['revision_id']);
        $this->assertSame($reason, $audit->meta['reason']);
        $this->assertSame($creator->id, (int) $revision->entered_by);
        $this->assertSame($versionBefore, $revision->version->fresh()->getRawOriginal());
        Carbon::setTestNow(now()->addMinute());
        $this->actingAs($reviewer)->postJson('/emar/order-revisions/'.$revision->id.'/send-back', ['reason' => 'Replay must not replace the evidence.'])->assertUnprocessable()->assertJsonValidationErrors('order');
        $this->actingAs($reviewer)->postJson('/emar/order-revisions/'.$revision->id.'/check', $this->checkInput())->assertUnprocessable()->assertJsonValidationErrors('order');
        $this->assertSame($reason, $medication->refresh()->rejection_reason);
        $this->assertSame($updatedAt, $medication->updated_at?->toISOString());
        $this->assertSame(1, AuditLog::where('action', 'medication_order.sent_back')->where('auditable_id', $medication->id)->count());
        $this->assertSame($versionBefore, $revision->version->fresh()->getRawOriginal());
    }

    public function test_audit_failure_rolls_back_the_verification_transition(): void
    {
        $verifier = $this->makeSiteUser(['medications.orders.verify'], $this->site, $this->client);
        $medication = $this->pendingMedication(['created_by' => User::factory()->create()->id, 'high_risk' => true]);
        $revision = $this->pendingRevision($medication);
        $event = 'eloquent.creating: '.AuditLog::class;
        Event::listen($event, static function (): never {
            throw new RuntimeException('Injected medication verification audit failure.');
        });
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($verifier)->post('/emar/order-revisions/'.$revision->id.'/check', $this->checkInput());
            $this->fail('The audit failure did not escape the verification transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected medication verification audit failure.', $exception->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertSame('pending_verification', $medication->refresh()->approval_status);
        $this->assertNull($medication->verified_by);
        $this->assertNull($medication->verified_at);
        $this->assertSame('pending', $revision->refresh()->status);
        $this->assertNull($revision->checked_at);
    }

    public function test_audit_failure_rolls_back_the_rejection_transition(): void
    {
        $reviewer = $this->makeSiteUser(['medications.orders.verify'], $this->site, $this->client);
        $medication = $this->pendingMedication(['created_by' => User::factory()->create()->id]);
        $revision = $this->pendingRevision($medication);
        $event = 'eloquent.creating: '.AuditLog::class;
        Event::listen($event, static function (): never {
            throw new RuntimeException('Injected medication rejection audit failure.');
        });
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($reviewer)->post('/emar/order-revisions/'.$revision->id.'/send-back', ['reason' => 'This write must roll back with its audit.']);
            $this->fail('The audit failure did not escape the send-back transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected medication rejection audit failure.', $exception->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertSame('pending_verification', $medication->refresh()->approval_status);
        $this->assertNull($medication->rejection_reason);
        $this->assertSame('pending', $revision->refresh()->status);
        $this->assertNull($revision->rejection_reason);
    }

    public function test_csv_import_reports_validation_errors_when_no_rows_can_be_imported(): void
    {
        $manager = $this->makeSiteUser(['medications.orders.manage'], $this->site);
        $this->client->update(['first_name' => 'Local', 'last_name' => 'Resident']);
        Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->client->service_context_id,
            'first_name' => 'Local', 'last_name' => 'Resident', 'status' => 'active',
        ]);
        $header = "client_name,medication_name,dose,frequency,route\n";

        foreach ([
            $header,
            "\n\n",
            $header."Local Resident,Missing columns\n",
            $header."Local Resident,Missing dose,,Once daily,oral\n",
            $header."OneName,Invalid name,5 mg,Once daily,oral\n",
            $header."Unknown Person,Unmatched medicine,5 mg,Once daily,oral\n",
            $header."Local Resident,Ambiguous medicine,5 mg,Once daily,oral\n",
        ] as $csv) {
            $this->actingAs($manager)->post('/emar/medications/import', [
                'csv_file' => UploadedFile::fake()->createWithContent('medications.csv', $csv),
            ])->assertRedirect()->assertSessionHasErrors('csv_file')->assertSessionMissing('success');
            $this->assertDatabaseCount('client_medications', 0);
        }
    }

    public function test_csv_import_reports_validation_errors_when_the_actor_has_no_accessible_house(): void
    {
        $manager = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->grantPermissions($manager, ['medications.view', 'medications.orders.manage']);
        $this->client->update(['first_name' => 'Local', 'last_name' => 'Resident']);

        $this->actingAs($manager)->post('/emar/medications/import', [
            'csv_file' => UploadedFile::fake()->createWithContent('medications.csv',
                "client_name,medication_name,dose,frequency,route\n"
                ."Local Resident,Inaccessible medicine,5 mg,Once daily,oral\n"),
        ])->assertRedirect()->assertSessionHasErrors('csv_file')->assertSessionMissing('success');
        $this->assertDatabaseCount('client_medications', 0);
    }

    public function test_csv_import_success_reports_actual_imported_and_skipped_rows(): void
    {
        $manager = $this->makeSiteUser(['medications.orders.manage'], $this->site);
        $this->client->update(['first_name' => 'Local', 'last_name' => 'Resident']);

        $this->actingAs($manager)->post('/emar/medications/import', [
            'csv_file' => UploadedFile::fake()->createWithContent('medications.csv',
                "client_name,medication_name,dose,frequency,route\n"
                ."\nLocal Resident,Imported medicine,5 mg,Once daily,oral\n"
                ."Local Resident,Invalid row\n"
                ."Unknown Person,Unmatched medicine,5 mg,Once daily,oral\n"),
        ])->assertRedirect()->assertSessionHasNoErrors()
            ->assertSessionHas('success', '1 medication order imported for checking; 2 rows skipped.');
        $this->assertDatabaseCount('client_medications', 1);
        $this->assertDatabaseHas('client_medications', [
            'client_id' => $this->client->id, 'name' => 'Imported medicine',
            'approval_status' => 'pending_verification', 'verified_by' => null, 'verified_at' => null,
        ]);
    }

    public function test_csv_import_resolves_only_one_canonical_accessible_client_and_creates_pending_orders(): void
    {
        $manager = $this->makeSiteUser(['medications.orders.manage'], $this->site);
        $this->client->update([
            'first_name' => 'Local',
            'last_name' => 'Resident',
        ]);
        $foreignSite = Site::factory()->create([
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        Client::factory()->create([
            'site_id' => $foreignSite->id,
            'service_context_id' => $this->client->service_context_id,
            'first_name' => 'Local',
            'last_name' => 'Resident',
            'status' => 'active',
        ]);
        Client::factory()->create([
            'site_id' => $foreignSite->id,
            'service_context_id' => $this->client->service_context_id,
            'first_name' => 'Foreign',
            'last_name' => 'Only',
            'status' => 'active',
        ]);

        $this->actingAs($manager)
            ->post('/emar/medications/import', [
                'csv_file' => UploadedFile::fake()->createWithContent(
                    'medications.csv',
                    "client_name,medication_name,dose,frequency,route\n"
                    ."Local Resident,Accessible medicine,5 mg,Once daily,oral\n"
                    ."Foreign Only,Concealed medicine,10 mg,Once daily,oral\n",
                ),
            ])
            ->assertRedirect()->assertSessionHasNoErrors()
            ->assertSessionHas('success', '1 medication order imported for checking; 1 row skipped.');

        $accessibleMedication = ClientMedication::query()
            ->where('name', 'Accessible medicine')
            ->firstOrFail();
        $this->assertSame($this->client->id, (int) $accessibleMedication->client_id);
        $this->assertSame($manager->id, (int) $accessibleMedication->created_by);
        $this->assertSame('pending_verification', $accessibleMedication->approval_status);
        $this->assertNull($accessibleMedication->verified_by);
        $this->assertDatabaseMissing('client_medications', [
            'name' => 'Concealed medicine',
        ]);

        Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->client->service_context_id,
            'first_name' => 'Local',
            'last_name' => 'Resident',
            'status' => 'active',
        ]);
        $this->actingAs($manager)
            ->post('/emar/medications/import', [
                'csv_file' => UploadedFile::fake()->createWithContent(
                    'medications.csv',
                    "client_name,medication_name,dose,frequency,route\n"
                    ."\"Resident, Local\",Ambiguous medicine,5 mg,Once daily,oral\n",
                ),
            ])
            ->assertRedirect()->assertSessionHasErrors('csv_file');
        $this->assertDatabaseMissing('client_medications', [
            'name' => 'Ambiguous medicine',
        ]);
    }

    public function test_csv_import_rolls_back_every_order_when_a_later_creation_fails(): void
    {
        $manager = $this->makeSiteUser(['medications.orders.manage'], $this->site);
        $this->client->update([
            'first_name' => 'First',
            'last_name' => 'Resident',
        ]);
        Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->client->service_context_id,
            'first_name' => 'Second',
            'last_name' => 'Resident',
            'status' => 'active',
        ]);
        $createCount = 0;
        $creatingEvent = 'eloquent.creating: '.ClientMedication::class;
        Event::listen($creatingEvent, static function () use (&$createCount): void {
            $createCount++;
            if ($createCount === 2) {
                throw new RuntimeException('Simulated second-order persistence failure.');
            }
        });

        try {
            $this->withoutExceptionHandling()
                ->actingAs($manager)
                ->post('/emar/medications/import', [
                    'csv_file' => UploadedFile::fake()->createWithContent(
                        'medications.csv',
                        "client_name,medication_name,dose,frequency,route\n"
                        ."First Resident,First imported medicine,5 mg,Once daily,oral\n"
                        ."Second Resident,Second imported medicine,10 mg,Once daily,oral\n",
                    ),
                ]);
            $this->fail('The simulated persistence failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated second-order persistence failure.', $exception->getMessage());
        } finally {
            Event::forget($creatingEvent);
        }

        $this->assertDatabaseMissing('client_medications', [
            'name' => 'First imported medicine',
        ]);
        $this->assertDatabaseMissing('client_medications', [
            'name' => 'Second imported medicine',
        ]);
    }

    /** @param array<int, string> $permissions */
    private function makeSiteUser(
        array $permissions,
        Site $site,
        ?Client $shiftClient = null,
        string $password = 'medication-secret',
    ): User {
        $user = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
            'password' => Hash::make($password),
        ]);
        $this->grantPermissions($user, ['medications.view', ...$permissions]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);

        if ($shiftClient !== null) {
            Shift::factory()->create([
                'client_id' => $shiftClient->id,
                'site_id' => $site->id,
                'user_id' => $user->id,
                'starts_at' => now()->subHours(2),
                'ends_at' => now()->addHours(2),
                'actual_starts_at' => now()->subHour(),
                'actual_ends_at' => null,
                'started_by' => $user->id,
                'status' => 'in_progress',
            ]);
        }

        return $user;
    }

    private function checkInput(): array
    {
        return ['source_matches' => true, 'dose_route_times_checked' => true, 'allergies_interactions_checked' => true];
    }

    private function entryInput(array $changes = [], ?ClientMedication $medication = null): array
    {
        return [
            'client_id' => $this->client->id, 'medication_id' => $medication?->id, 'expected_version' => $medication?->version,
            'request_key' => 'verification-entry-'.bin2hex(random_bytes(8)), 'change_reason' => 'Instruction from the signed source.',
            'source' => ['type' => 'written', 'prescriber' => 'Dr Verification', 'received_at' => now()->subMinute()->toIso8601String(), 'description' => 'Signed prescription.'],
            'source_file' => UploadedFile::fake()->create('prescription.pdf', 1, 'application/pdf'),
            'prescription' => array_merge(['name' => 'Verification medicine', 'dosage' => '5 mg', 'frequency' => 'Once daily', 'dose_times' => ['10:00'], 'is_prn' => false, 'route' => 'oral', 'indication' => 'Indication from source.', 'start_date' => now('Pacific/Auckland')->toDateString(), 'controlled_drug' => false, 'high_risk' => false, 'witness_required' => false], $changes),
        ];
    }

    /** Verification fixtures retain a real written source and immutable version. */
    private function pendingRevision(ClientMedication $medication, ?int $enteredBy = null): MedicationOrderRevision
    {
        $medication->refresh();
        $enteredBy ??= $medication->created_by ?? User::factory()->create()->id;
        $workflow = app(MedicationOrderWorkflow::class);
        $file = UploadedFile::fake()->create('prescription.pdf', 1, 'application/pdf');
        $path = $file->store('test-prescription-sources', 'local');
        $source = ['type' => 'written', 'prescriber' => 'Dr Verification', 'received_at' => now()->subMinute()->toIso8601String(), 'description' => 'Signed prescription.', 'file_sha256' => hash_file('sha256', $file->getRealPath())];
        $payload = array_merge($workflow->payload($medication), ['route' => 'oral', 'indication' => 'Indication from the signed source.', 'start_date' => now('Pacific/Auckland')->toDateString()]);
        $version = $workflow->snapshot($medication, $payload, (int) $medication->version, $enteredBy, $source, 'Verification fixture source');
        $revision = MedicationOrderRevision::create(['client_id' => $medication->client_id, 'client_medication_id' => $medication->id, 'medication_order_version_id' => $version->id, 'base_version' => $medication->version, 'entered_by' => $enteredBy]);
        $revision->files()->create(['purpose' => 'source', 'file_name' => 'prescription.pdf', 'file_path' => $path, 'file_size' => $file->getSize(), 'mime_type' => 'application/pdf', 'sha256' => $source['file_sha256'], 'uploaded_by' => $enteredBy, 'created_at' => now()]);

        return $revision;
    }

    private function pendingMedication(array $overrides = []): ClientMedication
    {
        return ClientMedication::query()->create(array_merge([
            'client_id' => $this->client->id,
            'name' => 'Pending verification medicine',
            'dosage' => '5 mg',
            'frequency' => 'Once daily',
            'dose_times' => ['10:00'],
            'controlled_drug' => false,
            'high_risk' => false,
            'witness_required' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'pending_verification',
            'verified_by' => null,
            'verified_at' => null,
        ], $overrides));
    }

    /** @param array<int, string> $permissionKeys */
    private function grantPermissions(User $user, array $permissionKeys): void
    {
        $permissions = Permission::query()
            ->whereIn('key', $permissionKeys)
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
            ->all();

        $user->permissionOverrides()->syncWithoutDetaching($permissions);
        $user->unsetRelation('permissionOverrides');
        $user->unsetRelation('roles');
    }
}
