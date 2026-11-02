<?php

namespace App\Domain\SecurityDevices\Care;

use App\Models\Client;
use App\Models\ClientConsent;
use App\Services\ConsentValidationService;

/**
 * S&D Phase F: whether a person's consent to monitoring sensors is active (the consent type added
 * by the care-links migration). A sensor is linked to their care only while it is, and unlinked
 * when it ends.
 */
final class SensorMonitoringConsent
{
    public const string TYPE = 'Monitoring Sensors (Bed, Door or Room)';

    /** @return array{active: bool, consent_id: int|null, until: string|null} */
    public function state(Client $client): array
    {
        $consent = ConsentValidationService::getActiveConsents($client)
            ->first(function (ClientConsent $consent): bool {
                $consent->loadMissing('consentType');

                return (string) $consent->consentType?->name === self::TYPE;
            });

        return [
            'active' => $consent instanceof ClientConsent,
            'consent_id' => $consent?->id,
            'until' => $consent?->expires_at?->toIso8601String(),
        ];
    }
}
