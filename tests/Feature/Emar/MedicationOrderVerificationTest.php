<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\Permission;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\MedicationScanVerificationService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
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

        Carbon::setTestNow(Carbon::parse('2026-08-23 10:00:00', config('app.worker_timezone', 'Pacific/Auckland')));
        $this->seed(RbacSeeder::class);

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
        $creator = $this->makeSiteUser(
            ['medications.orders.manage', 'medications.orders.verify'],
            $this->site,
            $this->client,
        );

        $this->actingAs($creator)
            ->post('/emar/medications', [
                'client_id' => $this->client->id,
                'medication_name' => 'Pending high-risk order',
                'dose' => '5 mg',
                'frequency' => 'Once daily',
                'high_risk' => true,
            ])
            ->assertRedirect();

        $medication = ClientMedication::query()
            ->where('name', 'Pending high-risk order')
            ->firstOrFail();
        $this->assertSame('pending_verification', $medication->approval_status);
        $this->assertSame($creator->id, (int) $medication->created_by);
        $this->assertNull($medication->verified_by);
        $this->assertNull($medication->verified_at);

        $medication->forceFill([
            'approval_status' => 'verified',
            'verified_by' => $creator->id,
            'verified_at' => now(),
        ])->saveQuietly();

        $this->actingAs($creator)
            ->put("/emar/medications/{$medication->id}", [
                'medication_name' => 'Updated high-risk order',
            ])
            ->assertRedirect();

        $medication->refresh();
        $this->assertSame('pending_verification', $medication->approval_status);
        $this->assertNull($medication->verified_by);
        $this->assertNull($medication->verified_at);
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
            'action' => 'medications.order.verified',
            'auditable_id' => $medication->id,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'medications.order.rejected',
            'auditable_id' => $medication->id,
        ]);
    }

    public function test_explicit_global_site_scope_requires_and_accepts_the_exact_verification_action(): void
    {
        $otherSite = Site::factory()->create([
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $globalVerifier = $this->makeSiteUser(
            ['medications.orders.verify', 'sites.viewAll'],
            $otherSite,
        );
        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'user_id' => $globalVerifier->id,
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->addHours(2),
            'actual_starts_at' => now()->subHour(),
            'actual_ends_at' => null,
            'started_by' => $globalVerifier->id,
            'status' => 'in_progress',
        ]);
        $medication = $this->pendingMedication([
            'created_by' => User::factory()->create()->id,
        ]);

        $this->actingAs($globalVerifier)
            ->post("/emar/medications/{$medication->id}/verify")
            ->assertRedirect();

        $this->assertSame('verified', $medication->refresh()->approval_status);
        $this->assertSame($globalVerifier->id, (int) $medication->verified_by);
    }

    public function test_high_risk_order_classes_deny_creator_self_verification_without_a_waiver(): void
    {
        $creator = $this->makeSiteUser(
            [
                'medications.orders.verify',
                'medications.controlled.view',
                'medications.controlled.record',
            ],
            $this->site,
            $this->client,
        );

        foreach ([
            ['high_risk' => true],
            ['controlled_drug' => true],
            ['witness_required' => true],
            ['high_risk' => true, 'created_by' => null],
        ] as $riskClass) {
            $medication = $this->pendingMedication([
                'created_by' => $creator->id,
                ...$riskClass,
            ]);

            $this->actingAs($creator)
                ->postJson("/emar/medications/{$medication->id}/verify")
                ->assertUnprocessable()
                ->assertJsonValidationErrors('verified_by');

            $this->assertSame('pending_verification', $medication->refresh()->approval_status);
        }

        $this->assertSame(
            0,
            AuditLog::query()->where('action', 'medications.order.verified')->count(),
        );
    }

    public function test_distinct_verifier_succeeds_and_replay_does_not_duplicate_the_effect(): void
    {
        $creator = User::factory()->create();
        $verifier = $this->makeSiteUser(
            ['medications.orders.verify'],
            $this->site,
            $this->client,
        );
        $medication = $this->pendingMedication([
            'created_by' => $creator->id,
            'high_risk' => true,
        ]);
        $orderEvidenceHash = $medication->verificationEvidenceHash();
        $scanCode = app(MedicationScanVerificationService::class)
            ->internalCode($this->client, $medication);

        $this->actingAs($verifier)
            ->post("/emar/medications/{$medication->id}/verify", [
                'scan_code' => $scanCode,
                'scan_source' => 'manual',
                'scan_verified' => true,
                'scan_match_source' => 'internal_emar',
            ])
            ->assertRedirect();

        $medication->refresh();
        $verifiedAt = $medication->verified_at?->toISOString();
        $this->assertSame('verified', $medication->approval_status);
        $this->assertSame($verifier->id, (int) $medication->verified_by);
        $this->assertNotNull($verifiedAt);
        $audit = AuditLog::query()
            ->where('action', 'medications.order.verified')
            ->where('auditable_id', $medication->id)
            ->sole();
        $this->assertSame($creator->id, (int) $audit->meta['creator_user_id']);
        $this->assertSame($verifier->id, (int) $audit->meta['verifier_user_id']);
        $this->assertTrue((bool) $audit->meta['independent_verifier_required']);
        $this->assertSame('independent_verifier', $audit->meta['verification_mode']);
        $this->assertSame('pending_verification', $audit->meta['approval_status_from']);
        $this->assertSame('verified', $audit->meta['approval_status_to']);
        $this->assertSame($orderEvidenceHash, $audit->meta['order_evidence_sha256']);
        $this->assertTrue((bool) $audit->meta['scan_verification_used']);
        $this->assertSame('internal_emar', $audit->meta['scan_match_source']);
        $this->assertSame(substr(str_replace('-', '', $scanCode), -6), $audit->meta['entered_code_suffix']);

        Carbon::setTestNow(now(config('app.worker_timezone', 'Pacific/Auckland'))->addMinute());
        $this->actingAs($verifier)
            ->post("/emar/medications/{$medication->id}/verify")
            ->assertRedirect();

        $this->assertSame($verifiedAt, $medication->refresh()->verified_at?->toISOString());
        $this->assertSame(
            1,
            AuditLog::query()
                ->where('action', 'medications.order.verified')
                ->where('auditable_id', $medication->id)
                ->count(),
        );

        $this->actingAs($verifier)
            ->postJson("/emar/medications/{$medication->id}/reject", [
                'rejection_reason' => 'A verified order cannot be rejected in place.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('approval_status');

        $medication->forceFill(['state' => 'ceased', 'active' => false])->saveQuietly();
        Carbon::setTestNow(now(config('app.worker_timezone', 'Pacific/Auckland'))->addMinute());
        $this->actingAs($verifier)
            ->post("/emar/medications/{$medication->id}/verify")
            ->assertRedirect();

        $this->assertSame($verifiedAt, $medication->refresh()->verified_at?->toISOString());
        $this->assertSame(
            1,
            AuditLog::query()
                ->where('action', 'medications.order.verified')
                ->where('auditable_id', $medication->id)
                ->count(),
        );
    }

    public function test_submitted_scan_evidence_is_reverified_against_the_locked_order(): void
    {
        $verifier = $this->makeSiteUser(
            ['medications.orders.verify'],
            $this->site,
            $this->client,
        );
        $medication = $this->pendingMedication([
            'created_by' => User::factory()->create()->id,
        ]);

        $this->actingAs($verifier)
            ->postJson("/emar/medications/{$medication->id}/verify", [
                'scan_code' => 'FORGED-MEDICATION-CODE',
                'scan_source' => 'scanner',
                'scan_verified' => true,
                'scan_match_source' => 'internal_emar',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scan_code');

        $validCode = app(MedicationScanVerificationService::class)
            ->internalCode($this->client, $medication);
        $this->actingAs($verifier)
            ->postJson("/emar/medications/{$medication->id}/verify", [
                'scan_code' => $validCode,
                'scan_source' => 'scanner',
                'scan_verified' => true,
                'scan_match_source' => 'vendor_barcode',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scan_code');

        $this->assertSame('pending_verification', $medication->refresh()->approval_status);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'medications.order.verified',
            'auditable_id' => $medication->id,
        ]);
    }

    public function test_non_pending_or_inactive_orders_cannot_enter_the_verification_transition(): void
    {
        $verifier = $this->makeSiteUser(
            ['medications.orders.verify'],
            $this->site,
            $this->client,
        );
        $rejected = $this->pendingMedication([
            'created_by' => User::factory()->create()->id,
        ]);
        $rejected->forceFill(['approval_status' => 'rejected'])->saveQuietly();
        $ceased = $this->pendingMedication([
            'created_by' => User::factory()->create()->id,
            'state' => 'ceased',
            'active' => false,
        ]);

        $this->actingAs($verifier)
            ->postJson("/emar/medications/{$rejected->id}/verify")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('approval_status');
        $this->actingAs($verifier)
            ->postJson("/emar/medications/{$ceased->id}/verify")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('medication');

        $this->assertSame('rejected', $rejected->refresh()->approval_status);
        $this->assertSame('pending_verification', $ceased->refresh()->approval_status);
        $this->assertSame(
            0,
            AuditLog::query()->where('action', 'medications.order.verified')->count(),
        );
    }

    public function test_emergency_waiver_requires_and_records_a_credentialed_same_site_approver(): void
    {
        $creator = $this->makeSiteUser(
            [
                'medications.orders.verify',
                'medications.controlled.view',
                'medications.controlled.record',
            ],
            $this->site,
            $this->client,
        );
        $unqualifiedApprover = $this->makeSiteUser([], $this->site, password: 'wrong-role-secret');
        $approver = $this->makeSiteUser(
            ['medications.orders.verify'],
            $this->site,
            password: 'approver-secret',
        );
        $medication = $this->pendingMedication([
            'created_by' => $creator->id,
            'controlled_drug' => true,
        ]);

        $this->actingAs($creator)
            ->from('/emar/medications')
            ->post("/emar/medications/{$medication->id}/verify", [
                'waiver_reason' => 'Urgent first dose while the on-call verifier travels to site.',
                'waiver_approved_by' => $unqualifiedApprover->id,
                'waiver_approver_credential' => 'wrong-role-secret',
            ])
            ->assertSessionHasErrors('waiver_approver_credential');
        $oldInput = session()->getOldInput();
        $this->assertArrayNotHasKey('waiver_approver_credential', $oldInput);
        $this->assertStringNotContainsString(
            'wrong-role-secret',
            json_encode($oldInput, JSON_THROW_ON_ERROR),
        );
        $this->assertSame('pending_verification', $medication->refresh()->approval_status);

        $this->actingAs($creator)
            ->post("/emar/medications/{$medication->id}/verify", [
                'waiver_reason' => 'Urgent first dose while the on-call verifier travels to site.',
                'waiver_approved_by' => $approver->id,
                'waiver_approver_credential' => 'approver-secret',
            ])
            ->assertRedirect();

        $this->assertSame($creator->id, (int) $medication->refresh()->verified_by);
        $audit = AuditLog::query()
            ->where('action', 'medications.order.verified')
            ->where('auditable_id', $medication->id)
            ->sole();
        $this->assertSame(
            'Urgent first dose while the on-call verifier travels to site.',
            $audit->meta['waiver_reason'],
        );
        $this->assertSame($approver->id, (int) $audit->meta['waiver_approved_by_user_id']);
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
            'action' => 'medications.order.verified',
            'auditable_id' => $medication->id,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'medications.order.rejected',
            'auditable_id' => $medication->id,
        ]);
    }

    public function test_rejection_is_a_locked_audited_terminal_transition_with_safe_replay(): void
    {
        $creator = User::factory()->create();
        $reviewer = $this->makeSiteUser(
            ['medications.orders.verify'],
            $this->site,
            $this->client,
        );
        $medication = $this->pendingMedication(['created_by' => $creator->id]);
        $orderEvidenceHash = $medication->verificationEvidenceHash();
        $reason = 'The supplied order does not match the signed prescription.';

        $this->actingAs($reviewer)
            ->post("/emar/medications/{$medication->id}/reject", [
                'rejection_reason' => $reason,
            ])
            ->assertRedirect();

        $medication->refresh();
        $updatedAt = $medication->updated_at?->toISOString();
        $this->assertSame('rejected', $medication->approval_status);
        $this->assertSame($reason, $medication->rejection_reason);
        $audit = AuditLog::query()
            ->where('action', 'medications.order.rejected')
            ->where('auditable_id', $medication->id)
            ->sole();
        $this->assertSame($creator->id, (int) $audit->meta['creator_user_id']);
        $this->assertSame($reviewer->id, (int) $audit->meta['reviewer_user_id']);
        $this->assertSame($orderEvidenceHash, $audit->meta['order_evidence_sha256']);
        $this->assertSame(hash('sha256', $reason), $audit->meta['rejection_reason_sha256']);

        Carbon::setTestNow(now(config('app.worker_timezone', 'Pacific/Auckland'))->addMinute());
        $this->actingAs($reviewer)
            ->post("/emar/medications/{$medication->id}/reject", [
                'rejection_reason' => 'A replay must not replace the original evidence.',
            ])
            ->assertRedirect();

        $medication->refresh();
        $this->assertSame($reason, $medication->rejection_reason);
        $this->assertSame($updatedAt, $medication->updated_at?->toISOString());
        $this->assertSame(
            1,
            AuditLog::query()
                ->where('action', 'medications.order.rejected')
                ->where('auditable_id', $medication->id)
                ->count(),
        );

        $this->actingAs($reviewer)
            ->postJson("/emar/medications/{$medication->id}/verify")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('approval_status');
    }

    public function test_audit_failure_rolls_back_the_verification_transition(): void
    {
        $creator = User::factory()->create();
        $verifier = $this->makeSiteUser(
            ['medications.orders.verify'],
            $this->site,
            $this->client,
        );
        $medication = $this->pendingMedication([
            'created_by' => $creator->id,
            'high_risk' => true,
        ]);
        $auditCreatingEvent = 'eloquent.creating: '.AuditLog::class;
        Event::listen($auditCreatingEvent, static function (): never {
            throw new RuntimeException('Injected medication verification audit failure.');
        });

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($verifier)
                ->post("/emar/medications/{$medication->id}/verify");
            $this->fail('The injected audit failure did not escape the verification transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected medication verification audit failure.', $exception->getMessage());
        } finally {
            Event::forget($auditCreatingEvent);
        }

        $medication->refresh();
        $this->assertSame('pending_verification', $medication->approval_status);
        $this->assertNull($medication->verified_by);
        $this->assertNull($medication->verified_at);
    }

    public function test_audit_failure_rolls_back_the_rejection_transition(): void
    {
        $reviewer = $this->makeSiteUser(
            ['medications.orders.verify'],
            $this->site,
            $this->client,
        );
        $medication = $this->pendingMedication([
            'created_by' => User::factory()->create()->id,
        ]);
        $auditCreatingEvent = 'eloquent.creating: '.AuditLog::class;
        Event::listen($auditCreatingEvent, static function (): never {
            throw new RuntimeException('Injected medication rejection audit failure.');
        });

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($reviewer)
                ->post("/emar/medications/{$medication->id}/reject", [
                    'rejection_reason' => 'This write must roll back with its audit.',
                ]);
            $this->fail('The injected audit failure did not escape the rejection transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected medication rejection audit failure.', $exception->getMessage());
        } finally {
            Event::forget($auditCreatingEvent);
        }

        $medication->refresh();
        $this->assertSame('pending_verification', $medication->approval_status);
        $this->assertNull($medication->rejection_reason);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'medications.order.rejected',
            'auditable_id' => $medication->id,
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
            ->assertRedirect();

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
            ->assertRedirect();
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
        $this->grantPermissions($user, $permissions);
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
