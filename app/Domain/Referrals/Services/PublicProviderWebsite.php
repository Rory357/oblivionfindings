<?php

namespace App\Domain\Referrals\Services;

final class PublicProviderWebsite
{
    /** Explicit public allow-list: never expose operational settings or drafts. */
    public function content(): array
    {
        $version = app(ReferralSettings::class)->effective();
        $configuration = $version?->configuration ?? ReferralSettings::defaults();

        return $this->fromConfiguration($configuration);
    }

    public function fromConfiguration(array $configuration): array
    {
        return [
            'name' => $configuration['public']['provider_name'],
            'phone' => $configuration['public']['contact_phone'] ?? '',
            'response_hours' => $configuration['public']['response_hours'],
            // Contact us page details (Settings › Referrals › Intake); blank when not set.
            'email' => (string) ($configuration['public']['contact_email'] ?? ''),
            'address' => (string) ($configuration['public']['office_address'] ?? ''),
            'opening_hours' => (string) ($configuration['public']['opening_hours'] ?? ''),
            'intake_enabled' => (bool) $configuration['public']['enabled'],
            'notice' => $configuration['public']['notice'] ?? '',
            'notice_version' => $configuration['public']['notice_version'],
            'services' => array_values(array_map(
                fn ($service) => ['id' => $service['id'], 'label' => $service['label']],
                array_filter($configuration['services'], fn ($service) => $service['enabled']),
            )),
        ];
    }
}
