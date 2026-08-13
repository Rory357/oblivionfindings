<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class ConsentValidationService
{
    private const TRACKING_CONSENT_TYPE_NAMES = [
        'Personal Tracker (Wandering Risk)',
        'Asset Location Tracking (Safety)',
        'Fleet Tracking',
    ];

    private const RESIDENT_LOCATION_CONSENT_TYPE_NAMES = [
        'Personal Tracker (Wandering Risk)',
    ];

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
     * Return the latest valid consent that can authorise a client location tracker.
     */
    public static function latestValidTrackingConsentForClient(Client $client): ?ClientConsent
    {
        foreach (self::TRACKING_CONSENT_TYPE_NAMES as $typeName) {
            $consent = self::validConsentQuery($client)
                ->whereHas('consentType', fn ($q) => $q->where('name', $typeName))
                ->latest('given_at')
                ->latest('id')
                ->first();

            if ($consent) {
                return $consent;
            }
        }

        return self::validConsentQuery($client)
            ->whereHas('consentType', function ($q): void {
                $q->where('name', 'like', '%Tracking%')
                    ->orWhere('name', 'like', '%Tracker%')
                    ->orWhere('name', 'like', '%Location%');
            })
            ->latest('given_at')
            ->latest('id')
            ->first();
    }

    /**
     * Confirm that a consent record is specifically for location tracking.
     * A valid consent for another purpose must never authorise location.
     */
    public static function isTrackingConsent(ClientConsent $consent): bool
    {
        $name = Str::lower((string) $consent->consentType?->name);

        return Str::contains($name, ['tracking', 'tracker', 'location']);
    }

    /**
     * Apply the full current-state boundary to an assignment-linked consent.
     */
    public static function isValidTrackingConsent(ClientConsent $consent): bool
    {
        return self::isTrackingConsent($consent)
            && $consent->isValid()
            && $consent->withdrawn_at === null
            && $consent->superseded_by_consent_id === null;
    }

    /**
     * Resident location is a distinct purpose from vehicle Fleet Tracking or
     * tracking a client-associated Asset. Only the personal wandering-risk
     * consent can authorise a Device assigned directly to a Client.
     */
    public static function isValidResidentLocationConsent(ClientConsent $consent): bool
    {
        return in_array((string) $consent->consentType?->name, self::RESIDENT_LOCATION_CONSENT_TYPE_NAMES, true)
            && (bool) $consent->consentType?->active
            && $consent->isValid()
            && $consent->given_at?->lessThanOrEqualTo(now())
            && $consent->withdrawn_at === null
            && $consent->superseded_by_consent_id === null;
    }

    public static function latestValidResidentLocationConsentForClient(Client $client): ?ClientConsent
    {
        return self::validConsentQuery($client)
            ->whereHas('consentType', fn ($query) => $query
                ->whereIn('name', self::RESIDENT_LOCATION_CONSENT_TYPE_NAMES)
                ->where('active', true))
            ->where('given_at', '<=', now())
            ->latest('given_at')
            ->latest('id')
            ->first();
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

    private static function validConsentQuery(Client $client)
    {
        return ClientConsent::query()
            ->where('client_id', $client->id)
            ->where('status', 'given')
            ->whereNull('withdrawn_at')
            ->whereNull('superseded_by_consent_id')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->with('consentType');
    }

    /**
     * Check if a client has all mandatory consents currently valid.
     */
    public static function hasAllMandatoryConsents(Client $client): bool
    {
        $mandatoryTypeIds = ConsentType::where('is_mandatory', true)
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

        return ConsentType::where('is_mandatory', true)
            ->where('active', true)
            ->whereNotIn('id', $activeConsentTypeIds)
            ->get();
    }
}
