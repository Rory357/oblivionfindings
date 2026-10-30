<?php

use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\Services\ReferralSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Referral settings for the "Oblivion Living" client demo website (mockup v2, approved by Stephan
 * 23 Oct 2026), so the public site's Make a referral and Contact us forms work end to end.
 *
 * Runs only when config('demo.site') is true (DEMO_SITE in .env), and only while no referral
 * settings version has ever been published. It never edits or replaces a published version, and
 * leaves any unpublished drafts as they are. Running it again changes nothing.
 *
 * The privacy notice is a clearly marked placeholder for the provider to replace.
 */
return new class extends Migration
{
    public const NOTICE_VERSION = 'demo-2026-10-23';

    public function up(): void
    {
        if (! config('demo.site')) {
            return;
        }
        if (! Schema::hasTable('care_referral_settings_versions') || ! Schema::hasTable('care_referral_configuration')) {
            return;
        }

        DB::transaction(function (): void {
            DB::table('care_referral_configuration')->insertOrIgnore(['id' => 1, 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $pointer = DB::table('care_referral_configuration')->where('id', 1)->lockForUpdate()->first();
            if ($pointer->published_version_id !== null || ReferralSettingsVersion::query()->where('state', 'published')->exists()) {
                return;
            }

            $configuration = app(ReferralSettings::class)->validate($this->configuration());
            $version = ReferralSettingsVersion::query()->create([
                'version' => (int) ReferralSettingsVersion::query()->max('version') + 1,
                'state' => 'published',
                'configuration' => $configuration,
                'reason' => 'Demo website: published for the fictional provider Oblivion Living (DEMO_SITE is on).',
                'publication_note' => 'Installed by the demo-site migration. Replace the placeholder privacy notice before real use.',
                'published_at' => now(),
                'effective_at' => now(),
            ]);
            DB::table('care_referral_configuration')->where('id', 1)->update([
                'published_version_id' => $version->id, 'lock_version' => $pointer->lock_version + 1, 'updated_at' => now(),
            ]);
            ReferralEvent::query()->create([
                'actor_id' => null, 'kind' => 'settings.published', 'audience' => 'settings',
                'subject_type' => 'settings_version', 'subject_id' => $version->id,
                'payload' => ['version' => $version->version, 'base_version_id' => null, 'demo_site' => true], 'occurred_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        // Published settings history is never removed.
    }

    private function configuration(): array
    {
        $configuration = ReferralSettings::defaults();
        $hours = 'Monday to Friday, 8.30am to 5pm';
        $configuration['public'] = [
            ...$configuration['public'],
            'enabled' => true,
            'provider_name' => 'Oblivion Living',
            'contact_phone' => '0800 000 000',
            'response_hours' => $hours,
            'notice_version' => self::NOTICE_VERSION,
            'notice' => implode("\n\n", [
                'DEMO PLACEHOLDER — this is not a real privacy notice. Oblivion Living is a fictional provider used to demonstrate the website. The provider replaces this text with their own notice before the site is used.',
                'Example wording: we collect the details you give us so our intake team can contact you about support. We use them only for that, keep them secure, and share them only with your permission or where the law requires. You can ask to see or correct the information we hold about you.',
            ]),
            'acknowledgement_enabled' => false,
            'office_address' => 'Level 1, 18 Demo Street, Porirua 5022',
            'contact_email' => 'kiaora@oblivionliving.example',
            'opening_hours' => $hours,
        ];
        $configuration['services'] = [
            ['id' => 'supported_living', 'label' => 'Supported living', 'enabled' => true],
            ['id' => 'community_support', 'label' => 'In the community', 'enabled' => true],
            ['id' => 'short_breaks', 'label' => 'Short breaks', 'enabled' => true],
        ];
        // Funding pathways can apply to every demo service (personal income assistance never funds a service).
        $configuration['pathways'] = array_map(
            fn (array $pathway) => [...$pathway, 'service_ids' => $pathway['personal_income'] ? [] : array_column($configuration['services'], 'id')],
            $configuration['pathways'],
        );

        return $configuration;
    }
};
