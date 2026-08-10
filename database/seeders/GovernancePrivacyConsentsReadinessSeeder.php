<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentRequest;
use App\Models\ConsentType;
use App\Models\DataBreachLog;
use App\Models\DataSubjectRequest;
use App\Models\NextOfKin;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class GovernancePrivacyConsentsReadinessSeeder extends Seeder
{
    public function run(): void
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

        ConsentType::query()->updateOrCreate(
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

        ConsentRequest::query()->where('client_id', $client->id)->delete();
        ClientConsent::withTrashed()->where('client_id', $client->id)->forceDelete();

        DataSubjectRequest::query()
            ->where('subject_email', 'like', 'privacy-readiness+%@example.test')
            ->delete();

        DataBreachLog::query()
            ->where('nature_of_breach', 'like', 'Playwright privacy lifecycle%')
            ->delete();
    }
}
