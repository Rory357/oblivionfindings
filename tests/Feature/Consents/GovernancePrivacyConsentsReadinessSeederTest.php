<?php

namespace Tests\Feature\Consents;

use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentAuthorityScope;
use App\Models\ConsentRequest;
use App\Models\ConsentType;
use App\Models\NextOfKin;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\ConsentValidationService;
use Carbon\Carbon;
use Database\Seeders\GovernancePrivacyConsentsReadinessSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class GovernancePrivacyConsentsReadinessSeederTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $guardian;

    private Client $client;

    private ConsentType $type;

    private ConsentAuthorityScope $scope;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-05 00:00:00', 'UTC'));
        Notification::fake();
        $this->seed(RbacSeeder::class);
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        Client::factory()->create(['site_id' => $site->id]);
        $this->staff = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->staff->roles()->sync([Role::query()->where('name', 'admin')->firstOrFail()->id]);
        ensureCanonicalHrStaffProfile($this->staff, $site);
        $this->seed(GovernancePrivacyConsentsReadinessSeeder::class);
        $this->client = Client::query()->where('first_name', 'Playwright')->where('last_name', 'Consent')->sole();
        $this->assertSame('playwright.consent.client@example.test', $this->client->email);
        $this->guardian = User::query()->where('email', 'portal.consent.readiness@demo.test')->sole();
        $this->type = ConsentType::query()->where('name', 'Playwright Location Tracking Consent')->sole();
        $this->scope = ConsentAuthorityScope::query()->where('client_id', $this->client->id)->sole();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_synthetic_fixture_binds_the_exact_verified_scope_and_completes_the_real_staff_and_portal_journey(): void
    {
        $this->assertTrue($this->scope->isCurrent());
        $this->assertTrue($this->scope->authorityEvidenceIsCurrent());
        $this->assertTrue($this->scope->capacityEvidenceIsCurrent());
        $this->assertSame($this->client->id, $this->scope->client_id);
        $this->assertSame($this->client->site_id, $this->scope->site_id);
        $this->assertSame($this->guardian->id, $this->scope->representative_user_id);
        $this->assertSame($this->type->id, $this->scope->consent_type_id);
        $this->assertSame(ConsentRequest::RELATION_WELFARE_GUARDIAN, $this->scope->authority_type);
        $this->assertSame(GovernancePrivacyConsentsReadinessSeeder::REQUEST_PURPOSE, $this->scope->purpose);
        $this->assertFalse($this->scope->capacityEvidenceConsent->gate_satisfying);
        $this->assertFalse(ConsentValidationService::isConsumable(
            $this->scope->capacityEvidenceConsent, $this->client, $this->type->id, $this->type->purpose,
        ));

        $consent = $this->completeJourney();
        $this->assertSame(ClientConsent::DECISION_AUTHORITATIVE, $consent->decision_state);
        $this->assertTrue($consent->gate_satisfying);
        $this->assertSame($this->scope->id, $consent->authority_scope_id);
        $this->assertSame($this->scope->capacity_evidence_consent_id, $consent->capacity_evidence_consent_id);
        $this->assertTrue(ConsentValidationService::isConsumable($consent, $this->client, $this->type->id, $this->type->purpose));
    }

    #[DataProvider('requestRetentionStates')]
    public function test_reseeding_after_portal_approval_resets_only_the_synthetic_person_in_restrictive_dependency_order(bool $softDeleted): void
    {
        $consent = $this->completeJourney();
        if ($softDeleted) {
            ConsentRequest::query()->where('resulting_consent_id', $consent->id)->sole()->delete();
        }
        $oldCapacityId = $this->scope->capacity_evidence_consent_id;
        $foreignClient = Client::factory()->create(['site_id' => Site::factory()->create()->id]);
        $foreignGuardian = User::factory()->create([
            'name' => 'Foreign synthetic consent guardian',
            'email' => 'foreign.consent.guardian@example.test',
            'role' => 'next_of_kin', 'approved_at' => now(),
        ]);
        $foreignGuardian->roles()->sync([Role::query()->where('name', 'next_of_kin')->firstOrFail()->id]);
        $foreignAuthority = NextOfKin::query()->create([
            'client_id' => $foreignClient->id, 'user_id' => $foreignGuardian->id,
            'relationship' => 'Welfare Guardian',
            'legal_authority_type' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
            'legal_authority_verified_at' => now()->subDay(),
            'legal_authority_verified_by_user_id' => $this->staff->id,
        ]);
        $foreignCapacity = ClientConsent::query()->create([
            'client_id' => $foreignClient->id, 'site_id' => $foreignClient->site_id,
            'consent_type_id' => $this->type->id,
            'consent_type_version_id' => $this->scope->capacityEvidenceConsent->consent_type_version_id,
            'status' => 'given', 'given_at' => now(),
            'given_by_user_id' => $this->staff->id, 'given_method' => 'written',
            'decision_state' => ClientConsent::DECISION_GOVERNANCE_REVIEW,
            'gate_satisfying' => false, 'created_by' => $this->staff->id,
        ]);
        $foreignScope = ConsentAuthorityScope::query()->create([
            'next_of_kin_id' => $foreignAuthority->id, 'client_id' => $foreignClient->id,
            'site_id' => $foreignClient->site_id, 'representative_user_id' => $foreignGuardian->id,
            'consent_type_id' => $this->type->id, 'authority_type' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
            'purpose' => 'Foreign synthetic purpose must remain unchanged.', 'version' => 1,
            'valid_from' => now()->subDay(), 'verified_at' => now()->subDay(),
            'verified_by_user_id' => $this->staff->id, 'capacity_evidence_consent_id' => $foreignCapacity->id,
        ]);
        $foreignRecords = [$foreignClient, $foreignGuardian, $foreignAuthority, $foreignCapacity, $foreignScope];
        $before = array_map(fn ($record) => $record->refresh()->getRawOriginal(), $foreignRecords);

        $this->seed(GovernancePrivacyConsentsReadinessSeeder::class);

        $this->assertSame($before, array_map(fn ($record) => $record->refresh()->getRawOriginal(), $foreignRecords));
        $this->assertSame(0, ConsentRequest::query()->where('client_id', $this->client->id)->count());
        $this->assertSame(0, ConsentRequest::withTrashed()->where('client_id', $this->client->id)->count());
        $this->assertSame(1, ClientConsent::withTrashed()->where('client_id', $this->client->id)->count());
        $scope = ConsentAuthorityScope::query()->where('client_id', $this->client->id)->sole();
        $this->assertTrue($scope->authorityEvidenceIsCurrent());
        $this->assertTrue($scope->capacityEvidenceIsCurrent());
        $this->assertSame($this->guardian->id, $scope->representative_user_id);
        $this->assertNull(ClientConsent::withTrashed()->find($consent->id));
        $this->assertNull(ClientConsent::withTrashed()->find($oldCapacityId));
        $this->assertSame(1, $this->type->versions()->count());
    }

    public static function requestRetentionStates(): array
    {
        return ['active' => [false], 'already_soft_deleted' => [true]];
    }

    public function test_an_identity_collision_stops_before_any_fixture_changes(): void
    {
        $this->client->update(['email' => 'not-the-synthetic-identity@example.test']);
        $records = [$this->client, $this->guardian, $this->scope, $this->scope->nextOfKin, $this->scope->capacityEvidenceConsent];
        $before = array_map(fn ($record) => $record->refresh()->getRawOriginal(), $records);
        $auditCount = DB::table('audit_logs')->count();
        try {
            $this->seed(GovernancePrivacyConsentsReadinessSeeder::class);
            $this->fail('A mismatched readiness identity must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Readiness consent client must retain its synthetic identity.', $exception->getMessage());
        }
        $this->assertSame($before, array_map(fn ($record) => $record->refresh()->getRawOriginal(), $records));
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    #[DataProvider('scopeDenials')]
    public function test_changed_authority_scope_is_denied_without_requests_consents_audits_or_notifications(string $scenario): void
    {
        $payload = $this->payload();
        match ($scenario) {
            'purpose' => $payload['purpose'] = 'A different purpose was never verified for this fixture.',
            'expired' => $this->scope->update(['expires_at' => now()->subMinute()]),
            'foreign_site' => $this->scope->update(['site_id' => Site::factory()->create()->id]),
            'foreign_person' => $this->scope->update(['client_id' => Client::factory()->create(['site_id' => $this->client->site_id])->id]),
            'revoked' => $this->scope->update(['revoked_at' => now(), 'revoked_by_user_id' => $this->staff->id, 'revocation_reason' => 'Synthetic scope revocation']),
            'withdrawn_capacity' => $this->scope->capacityEvidenceConsent->update(['withdrawn_at' => now()]),
        };
        $records = [$this->client, $this->scope, $this->scope->nextOfKin, $this->scope->capacityEvidenceConsent];
        $before = array_map(fn ($record) => $record->refresh()->getRawOriginal(), $records);
        $requestCount = ConsentRequest::query()->count();
        $consentCount = ClientConsent::withTrashed()->count();
        $auditCount = DB::table('audit_logs')->count();

        $this->actingAs($this->staff)->post(route('operations.clients.consent-requests.store', $this->client), $payload)
            ->assertRedirect()
            ->assertSessionHasErrors(['recipient_relationship' => 'Current, verified authority scoped to this person, Site, consent type, purpose and period is required for substituted consent.']);

        $this->assertSame($before, array_map(fn ($record) => $record->refresh()->getRawOriginal(), $records));
        $this->assertSame($requestCount, ConsentRequest::query()->count());
        $this->assertSame($consentCount, ClientConsent::withTrashed()->count());
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        Notification::assertNothingSent();
    }

    public static function scopeDenials(): array
    {
        return array_combine(
            ['purpose', 'expired', 'foreign_site', 'foreign_person', 'revoked', 'withdrawn_capacity'],
            array_map(fn ($scenario) => [$scenario], ['purpose', 'expired', 'foreign_site', 'foreign_person', 'revoked', 'withdrawn_capacity']),
        );
    }

    private function payload(): array
    {
        return [
            'consent_type_id' => $this->type->id, 'recipient_user_id' => $this->guardian->id,
            'recipient_relationship' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
            'purpose' => GovernancePrivacyConsentsReadinessSeeder::REQUEST_PURPOSE,
            'data_scope' => 'Care team and on-call coordinator', 'retention_period_days' => 180,
            'capacity_outcome' => 'lacks_capacity',
            'capacity_assessed_at' => $this->scope->capacityEvidenceConsent->capacity_assessed_at->toISOString(),
            'capacity_assessment_expires_at' => $this->scope->capacityEvidenceConsent->expires_at->toISOString(),
            'capacity_assessment_reason' => 'Synthetic assessment: the fictional person could not understand or weigh the location tracking decision after supported explanation.',
            'capacity_evidence_type' => 'Synthetic decision-specific assessment', 'capacity_evidence_reference' => 'PW-CONSENT-CAPACITY-001',
            'best_interests_process_reason' => 'Synthetic review considered the fictional person’s wishes, privacy, safety, consultation and less restrictive alternatives for this tracking decision.',
            'best_interests_evidence_type' => 'Synthetic best-interests review', 'best_interests_evidence_reference' => 'PW-CONSENT-REVIEW-001',
            'best_interests_consultees' => ['Playwright Consent Guardian', 'Playwright key worker'],
        ];
    }

    private function completeJourney(): ClientConsent
    {
        $this->actingAs($this->staff)->post(route('operations.clients.consent-requests.store', $this->client), $this->payload())
            ->assertRedirect(route('operations.clients.consent-requests.index', $this->client))
            ->assertSessionDoesntHaveErrors()
            ->assertSessionHas('success', 'Consent request sent to the family portal.');
        $request = ConsentRequest::query()->where('client_id', $this->client->id)->sole();
        $this->assertSame(ConsentRequest::STATUS_PENDING, $request->status);
        $this->assertSame($this->client->site_id, $request->site_id);
        $this->assertSame($this->guardian->id, $request->recipient_user_id);
        $this->assertSame(GovernancePrivacyConsentsReadinessSeeder::REQUEST_PURPOSE, $request->purpose);
        $this->assertSame($this->scope->id, $request->authority_scope_id);
        $this->assertSame($this->scope->capacity_evidence_consent_id, $request->capacity_evidence_consent_id);
        $responseNotes = 'Approved through Playwright readiness coverage.';
        $this->actingAs($this->guardian)->post(route('portal.clients.consent-requests.approve', [$this->client, $request]), [
            'acknowledge_authority' => true,
            'response_notes' => $responseNotes,
        ])
            ->assertRedirect(route('portal.clients.dashboard', $this->client))
            ->assertSessionDoesntHaveErrors()
            ->assertSessionHas('success', 'Consent recorded. Thank you.');
        $request->refresh();
        $this->assertSame(ConsentRequest::STATUS_APPROVED, $request->status);
        $this->assertSame($responseNotes, $request->response_notes);
        $this->assertSame(ConsentRequest::DECISION_AUTHORITATIVE, $request->decision_kind);
        $consent = ClientConsent::query()->findOrFail($request->resulting_consent_id);
        $this->assertSame($request->id, $consent->source_consent_request_id);
        $this->assertSame($request->id, $consent->consent_request_id);
        $this->assertSame($this->client->id, $consent->client_id);
        $this->assertSame($this->client->site_id, $consent->site_id);
        $this->assertSame($this->guardian->id, $consent->decision_actor_user_id);

        return $consent;
    }
}
