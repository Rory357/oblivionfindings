<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientConsent;
use Illuminate\Database\Eloquent\Collection;

class ConsentValidationService
{
    /**
     * Check if a client has valid (active, non-expired) consent for a given consent type name.
     */
    public static function hasValidConsent(Client $client, string $consentTypeName): bool
    {
        return ClientConsent::where('client_id', $client->id)
            ->whereHas('consentType', fn ($q) => $q->where('name', $consentTypeName))
            ->where('status', 'given')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
    }

    /**
     * Check if a client has valid consent for a given consent type ID.
     */
    public static function hasValidConsentById(Client $client, int $consentTypeId): bool
    {
        return ClientConsent::where('client_id', $client->id)
            ->where('consent_type_id', $consentTypeId)
            ->where('status', 'given')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
    }

    /**
     * Get all active (valid, non-expired) consents for a client.
     */
    public static function getActiveConsents(Client $client): Collection
    {
        return ClientConsent::where('client_id', $client->id)
            ->where('status', 'given')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->with('consentType')
            ->get();
    }

    /**
     * Get all expired consents for a client (status is still 'given' but expires_at is past).
     */
    public static function getExpiredConsents(Client $client): Collection
    {
        return ClientConsent::where('client_id', $client->id)
            ->where('status', 'given')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->with('consentType')
            ->get();
    }

    /**
     * Get consents expiring within the given number of days.
     */
    public static function getExpiringConsents(Client $client, int $days = 30): Collection
    {
        return ClientConsent::where('client_id', $client->id)
            ->where('status', 'given')
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays($days)])
            ->with('consentType')
            ->get();
    }

    /**
     * Check if a client has all mandatory consents currently valid.
     */
    public static function hasAllMandatoryConsents(Client $client): bool
    {
        $mandatoryTypeIds = \App\Models\ConsentType::where('is_mandatory', true)
            ->where('active', true)
            ->pluck('id');

        if ($mandatoryTypeIds->isEmpty()) {
            return true;
        }

        $activeConsentTypeIds = ClientConsent::where('client_id', $client->id)
            ->where('status', 'given')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('consent_type_id');

        return $mandatoryTypeIds->diff($activeConsentTypeIds)->isEmpty();
    }

    /**
     * Get missing mandatory consents for a client.
     */
    public static function getMissingMandatoryConsents(Client $client): Collection
    {
        $activeConsentTypeIds = ClientConsent::where('client_id', $client->id)
            ->where('status', 'given')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('consent_type_id');

        return \App\Models\ConsentType::where('is_mandatory', true)
            ->where('active', true)
            ->whereNotIn('id', $activeConsentTypeIds)
            ->get();
    }
}
