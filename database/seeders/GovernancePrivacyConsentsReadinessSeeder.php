<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentAuthorityScope;
use App\Models\ConsentRequest;
use App\Models\ConsentType;
use App\Models\ConsentTypeVersion;
use App\Models\DataBreachLog;
use App\Models\DataSubjectRequest;
use App\Models\NextOfKin;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class GovernancePrivacyConsentsReadinessSeeder extends Seeder
{
    public const REQUEST_PURPOSE = 'Playwright readiness flow for a personal tracker consent request.';

    public function run(): void
    {
        DB::transaction(fn () => $this->seedFixture());
    }

    private function seedFixture(): void
    {
        $portalRole = Role::query()->where('name', 'next_of_kin')->first();
        $siteScopedClient = Client::query()
            ->whereNotNull('site_id')
            ->where('site_id', '>', 0)
            ->whereHas('site', fn ($query) => $query
                ->where('is_active', true)
                ->where('archived', false))
            ->orderBy('id')
            ->first();
        $authorityVerifier = User::query()->where('role', 'admin')->first();

        if (! $siteScopedClient || ! $authorityVerifier) {
            return;
        }

        $client = Client::withTrashed()
            ->where('first_name', 'Playwright')
            ->where('last_name', 'Consent')
            ->first() ?? new Client;

        if ($client->exists && $client->email !== 'playwright.consent.client@example.test') {
            throw new RuntimeException('Readiness consent client must retain its synthetic identity.');
        }

        $client->fill([
            'first_name' => 'Playwright',
            'last_name' => 'Consent',
            'email' => 'playwright.consent.client@example.test',
            'nhi_number' => 'PWC1001',
            'date_of_birth' => '1980-01-01',
            'phone' => '0210000001',
            'address_line_1' => '1 Readiness Lane',
            'city' => 'Wellington',
            'postcode' => '6011',
            'status' => 'active',
            // ClientPolicy deliberately denies site-less business records.
            // Keep this readiness client inside the same canonical Site model
            // as every production client instead of relying on an admin
            // authorization bypass.
            'site_id' => $siteScopedClient->site_id,
            'service_context_id' => $siteScopedClient->service_context_id,
        ]);

        if ($client->trashed()) {
            $client->restore();
        }

        $client->save();

        $portalUser = User::query()->updateOrCreate(
            ['email' => 'portal.consent.readiness@demo.test'],
            [
                'name' => 'Playwright Consent Guardian',
                'password' => Hash::make('password'),
                'role' => 'next_of_kin',
                'approved_at' => now(),
                'email_verified_at' => now(),
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ],
        );

        if ($portalRole) {
            $portalUser->roles()->syncWithoutDetaching([$portalRole->id]);
        }

        $client->portalUsers()->syncWithoutDetaching([
            $portalUser->id => ['relation' => ConsentRequest::RELATION_WELFARE_GUARDIAN],
        ]);

        $authority = NextOfKin::withTrashed()
            ->where('client_id', $client->id)
            ->where('user_id', $portalUser->id)
            ->first() ?? new NextOfKin;
        $authority->forceFill([
            'client_id' => $client->id,
            'user_id' => $portalUser->id,
            'relationship' => 'Welfare Guardian',
            'legal_authority_type' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
            'legal_authority_verified_at' => now()->subDay(),
            'legal_authority_verified_by_user_id' => $authorityVerifier->id,
            'legal_authority_expires_at' => now()->addYear(),
            'is_primary_contact' => true,
            'is_emergency_contact' => true,
            'can_view_medical' => true,
            'can_view_medications' => true,
            'can_view_incidents' => true,
            'can_receive_updates' => true,
            'deleted_at' => null,
        ])->save();

        $consentType = ConsentType::query()->updateOrCreate(
            ['name' => 'Playwright Location Tracking Consent'],
            [
                'category' => 'tracking',
                'description' => 'Deterministic consent type for Playwright readiness coverage.',
                'purpose' => 'Allow safe location tracking for readiness smoke coverage.',
                'legal_basis' => 'Informed consent under HDC Code of Rights Right 7.',
                'is_mandatory' => false,
                'requires_capacity_assessment' => true,
                'validity_period_days' => 365,
                'active' => true,
            ],
        );

        // Reset only this fictional person's evidence, in restrictive-FK order.
        ClientConsent::withTrashed()->where('client_id', $client->id)
            ->whereNotNull('source_consent_request_id')->forceDelete();
        ConsentRequest::withTrashed()->where('client_id', $client->id)->forceDelete();
        ConsentAuthorityScope::query()->where('client_id', $client->id)->delete();
        ClientConsent::withTrashed()->where('client_id', $client->id)->forceDelete();

        $consentType->refresh();
        $typeVersion = ConsentTypeVersion::query()->firstOrCreate([
            'consent_type_id' => $consentType->id,
            'version' => $consentType->version,
        ], [
            'description' => $consentType->description,
            'purpose' => $consentType->purpose,
            'legal_basis' => $consentType->legal_basis,
            'effective_from' => now()->subDay(),
            'created_by' => $authorityVerifier->id,
        ]);
        // Fictional governance evidence for this exact readiness decision. It
        // cannot satisfy a consent gate by itself or authorize another purpose.
        $capacity = ClientConsent::query()->create([
            'client_id' => $client->id,
            'site_id' => $client->site_id,
            'consent_type_id' => $consentType->id,
            'consent_type_version_id' => $typeVersion->id,
            'status' => 'given',
            'given_at' => now()->subDays(2),
            'given_by_user_id' => $authorityVerifier->id,
            'given_method' => 'written',
            'capacity_assessed' => true,
            'capacity_outcome' => 'lacks_capacity',
            'capacity_assessor_id' => $authorityVerifier->id,
            'capacity_assessed_at' => now()->subDays(2),
            'capacity_notes' => 'Synthetic assessment of the fictional location tracking decision: PW-CONSENT-CAPACITY-001.',
            'expires_at' => now()->addDays(30),
            'decision_state' => ClientConsent::DECISION_GOVERNANCE_REVIEW,
            'gate_satisfying' => false,
            'governance_review_reason' => 'synthetic_capacity_assessment_only',
            'created_by' => $authorityVerifier->id,
        ]);
        $authority->refresh();
        $capacity->refresh();
        ConsentAuthorityScope::query()->create([
            'next_of_kin_id' => $authority->id,
            'client_id' => $client->id,
            'site_id' => $client->site_id,
            'representative_user_id' => $portalUser->id,
            'consent_type_id' => $consentType->id,
            'authority_type' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
            'purpose' => self::REQUEST_PURPOSE,
            'version' => 1,
            'valid_from' => now()->subDay(),
            'expires_at' => $capacity->expires_at,
            'verified_at' => $authority->legal_authority_verified_at,
            'verified_by_user_id' => $authorityVerifier->id,
            'capacity_evidence_consent_id' => $capacity->id,
            'evidence_reference' => 'PW-CONSENT-READINESS-AUTHORITY-001',
            'evidence_snapshot' => [
                'authority' => [
                    'next_of_kin_id' => $authority->id,
                    'legal_authority_type' => $authority->legal_authority_type,
                    'verified_at' => $authority->legal_authority_verified_at?->toISOString(),
                    'verified_by_user_id' => $authority->legal_authority_verified_by_user_id,
                    'expires_at' => $authority->legal_authority_expires_at?->toISOString(),
                ],
                'capacity' => [
                    'client_consent_id' => $capacity->id,
                    'outcome' => $capacity->capacity_outcome,
                    'assessor_user_id' => $capacity->capacity_assessor_id,
                    'assessed_at' => $capacity->capacity_assessed_at?->toISOString(),
                ],
                'governance_decision' => 'explicit_synthetic_readiness_fixture_only',
                'legal_or_clinical_determination' => 'not_made_by_consent_workflow',
            ],
        ]);

        DataSubjectRequest::query()
            ->where('subject_email', 'like', 'privacy-readiness+%@example.test')
            ->delete();

        DataBreachLog::query()
            ->where('nature_of_breach', 'like', 'Playwright privacy lifecycle%')
            ->delete();
    }
}
