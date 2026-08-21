<?php

namespace App\Services\Portal;

use App\Domain\SecurityDevices\Services\PersonalTrackingPrivacyService;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\FamilyPortalSetting;
use App\Models\NextOfKin;
use App\Models\User;
use App\Services\ConsentValidationService;
use App\Services\ShiftTimelineService;
use Illuminate\Database\Eloquent\Builder;

final class PortalClientSectionAccess
{
    public function __construct(
        private readonly PersonalTrackingPrivacyService $trackingPrivacy,
    ) {}

    /** @var array<int, string> */
    private const CARE_TIMELINE_TYPES = [
        'note',
        'shift_note',
        'progress_note',
        'handover',
        'family_note_created',
        'family_note_completed',
        'family_note_assigned_to_shift',
        'medication_given',
        'medication_refused',
        'medication_missed',
        'medication_prescribed',
        'medication_correction',
    ];

    /** @var array<int, string> */
    private const MEDICATION_TIMELINE_TYPES = [
        'medication_given',
        'medication_refused',
        'medication_missed',
        'medication_prescribed',
        'medication_correction',
    ];

    /** @return array<string, bool> */
    public function for(User $user, Client $client): array
    {
        $linked = $user->canAccessClientPortal($client);
        $isSelf = $linked
            && $user->hasRole('client')
            && $user->portalClients()
                ->whereKey($client->id)
                ->wherePivotIn('relation', ['self', 'client'])
                ->exists();

        $nok = $linked && ! $isSelf
            ? NextOfKin::query()
                ->where('user_id', $user->id)
                ->where('client_id', $client->id)
                ->first()
            : null;
        $hasFamilyInformationConsent = $linked
            && ! $isSelf
            && $nok !== null
            && $this->hasActiveFamilyInformationConsent($client);
        $settings = $linked
            ? FamilyPortalSetting::query()->where('client_id', $client->id)->first()
            : null;

        $familySetting = static function (
            ?FamilyPortalSetting $settings,
            string $attribute,
            bool $default,
        ): bool {
            if ($settings === null) {
                return $default;
            }

            return (bool) $settings->{$attribute};
        };

        $familyDisclosure = $linked && $hasFamilyInformationConsent;

        return [
            'linked' => $linked,
            'is_self' => $isSelf,
            'is_next_of_kin' => $linked && ! $isSelf && $nok !== null,
            'has_family_information_consent' => $isSelf || $hasFamilyInformationConsent,
            'show_shift_schedule' => $isSelf || (
                $familyDisclosure
                && $familySetting($settings, 'show_shift_schedule', true)
            ),
            'show_respite' => $isSelf || (
                $familyDisclosure
                && $familySetting($settings, 'show_respite', true)
            ),
            'show_care_notes' => $isSelf || (
                $familyDisclosure
                && $familySetting($settings, 'show_care_notes', true)
            ),
            'show_care_plans' => $isSelf || (
                $familyDisclosure
                && $familySetting($settings, 'show_care_plans', false)
            ),
            'show_medication_status' => $isSelf || (
                $familyDisclosure
                && $familySetting($settings, 'show_medication_status', false)
            ),
            'can_view_medical' => $isSelf || (
                $familyDisclosure
                && (bool) $nok?->can_view_medical
            ),
            'can_view_medications' => $isSelf || (
                $familyDisclosure
                && $familySetting($settings, 'show_medication_status', false)
                && (bool) $nok?->can_view_medications
            ),
            'can_view_incidents' => $isSelf || (
                $familyDisclosure
                && $familySetting($settings, 'show_incidents', false)
                && (bool) $nok?->can_view_incidents
            ),
        ];
    }

    public function hasActiveFamilyInformationConsent(Client $client): bool
    {
        return ClientConsent::query()
            ->where('client_id', $client->id)
            ->whereHas('consentType', function ($query) {
                $query->where('name', 'Information Sharing with Whānau / Family')
                    ->orWhere(function ($query) {
                        $query->where('category', 'communication')
                            ->where(function ($query) {
                                $query->where('name', 'like', '%family%')
                                    ->orWhere('name', 'like', '%whanau%')
                                    ->orWhere('name', 'like', '%whānau%');
                            });
                    });
            })
            ->with([
                'consentType',
                'consentTypeVersion',
                'sourceConsentRequest',
                'authorityScope.nextOfKin',
                'authorityScope.capacityEvidenceConsent',
            ])
            ->get()
            ->contains(fn (ClientConsent $consent): bool => ConsentValidationService::isConsumable(
                $consent,
                $client,
                $consent->consent_type_id,
                $consent->consentTypeVersion?->purpose,
            ));
    }

    public function activeLocationTrackingConsent(Client $client): ?ClientConsent
    {
        return $this->trackingPrivacy->activeConsentForClientAssignment($client);
    }

    /** @param array<string, bool> $access */
    public function constrainTimeline(Builder $query, array $access): void
    {
        if (! ($access['has_family_information_consent'] ?? false)) {
            $query->whereRaw('1 = 0');

            return;
        }

        if (! ($access['show_shift_schedule'] ?? false)) {
            $query->whereNotIn('type', ShiftTimelineService::shiftEventTypes());
        }

        if (! ($access['show_care_notes'] ?? false)) {
            $query->whereNotIn('type', self::CARE_TIMELINE_TYPES);
        }

        if (! ($access['can_view_medications'] ?? false)) {
            $query->whereNotIn('type', self::MEDICATION_TIMELINE_TYPES);
        }

        if (! ($access['can_view_incidents'] ?? false)) {
            $query->where('type', 'not like', '%incident%');
        }
    }
}
