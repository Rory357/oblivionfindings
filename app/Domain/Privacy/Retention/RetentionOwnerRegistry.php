<?php

namespace App\Domain\Privacy\Retention;

use App\Models\Client;
use App\Models\ClientNote;
use App\Models\RespiteBooking;
use App\Models\RespiteBookingRequest;
use App\Models\RespiteDailyNote;
use App\Models\RespiteEvidencePack;
use App\Models\RespiteMedicationReconciliation;
use App\Models\RespiteReferral;
use App\Models\RespiteStay;

final class RetentionOwnerRegistry
{
    /** @var array<string, RetentionOwnerAdapter>|null */
    private ?array $owners = null;

    /** @return array<int, string> */
    public function identifiers(): array
    {
        return array_keys($this->owners());
    }

    /** @return array<int, array{value: string, label: string}> */
    public function options(): array
    {
        return array_values(array_map(
            fn (RetentionOwnerAdapter $owner): array => [
                'value' => $owner->key,
                'label' => $owner->label,
            ],
            $this->owners(),
        ));
    }

    public function resolve(string $identifier): RetentionOwnerAdapter
    {
        $owner = $this->owners()[$identifier] ?? null;

        if (! $owner) {
            throw new RetentionContractException(
                'unknown_owner',
                'This retention policy does not use a supported native record owner.',
            );
        }

        return $owner;
    }

    /** @return array<string, RetentionOwnerAdapter> */
    private function owners(): array
    {
        if ($this->owners !== null) {
            return $this->owners;
        }

        return $this->owners = [
            Client::class => new RetentionOwnerAdapter(
                Client::class,
                'Client profiles',
                Client::class,
                [
                    'status' => ['equals', 'in', 'not_in'],
                    'site_id' => ['equals', 'in'],
                    'service_context_id' => ['equals', 'in', 'null', 'not_null'],
                ],
                [
                    'first_name' => 'redact',
                    'last_name' => 'redact',
                    'preferred_name' => 'clear',
                    'email' => 'clear',
                    'phone' => 'clear',
                    'nhi_number' => 'clear',
                    'nhi_hash' => 'clear',
                    'date_of_birth' => 'clear',
                    'address_line_1' => 'clear',
                    'address_line_2' => 'clear',
                    'suburb' => 'clear',
                    'city' => 'clear',
                    'postcode' => 'clear',
                    'profile_photo_path' => 'clear',
                    'life_story' => 'clear',
                    'interests_hobbies' => 'clear',
                    'strengths_abilities' => 'clear',
                ],
                '@self',
            ),
            ClientNote::class => new RetentionOwnerAdapter(
                ClientNote::class,
                'Client notes',
                ClientNote::class,
                [
                    'client_id' => ['equals', 'in'],
                    'type' => ['equals', 'in', 'not_in'],
                    'visibility' => ['equals', 'in'],
                    'category' => ['equals', 'in', 'null', 'not_null'],
                    'is_private' => ['equals'],
                    'is_draft' => ['equals'],
                ],
                [
                    'subject' => 'redact',
                    'goal' => 'clear',
                    'body' => 'redact',
                    'flagged_reason' => 'clear',
                    'ai_summary' => 'clear',
                    'attachments' => 'clear',
                    'behaviour_tags' => 'clear',
                    'concerns_flags' => 'clear',
                    'follow_up_action' => 'clear',
                    'contact_person' => 'clear',
                    'contact_relationship' => 'clear',
                ],
                'client',
            ),
            RespiteReferral::class => new RetentionOwnerAdapter(
                RespiteReferral::class,
                'Respite referrals',
                RespiteReferral::class,
                [
                    'client_id' => ['equals', 'in'],
                    'status' => ['equals', 'in', 'not_in'],
                    'linked_booking_request_id' => ['equals', 'in', 'null', 'not_null'],
                    'urgency' => ['equals', 'in'],
                ],
                [
                    'nhi_number' => 'clear',
                    'nhi_hash' => 'clear',
                    'referrer_name' => 'redact',
                    'referrer_contact' => 'clear',
                    'third_party_source_name' => 'clear',
                    'referral_reason' => 'redact',
                    'funding_reference' => 'clear',
                    'triage_notes' => 'redact',
                    'ethnicity' => 'clear',
                    'iwi' => 'clear',
                    'hapu' => 'clear',
                    'marae' => 'clear',
                    'cultural_considerations' => 'clear',
                    'primary_carer_name' => 'clear',
                    'primary_carer_contact' => 'clear',
                ],
                'client',
            ),
            RespiteBookingRequest::class => new RetentionOwnerAdapter(
                RespiteBookingRequest::class,
                'Respite booking requests',
                RespiteBookingRequest::class,
                [
                    'client_id' => ['equals', 'in'],
                    'status' => ['equals', 'in', 'not_in'],
                    'funding_status' => ['equals', 'in', 'not_in'],
                    'is_emergency' => ['equals'],
                ],
                [
                    'requirements' => 'clear',
                    'intake_snapshot' => 'clear',
                    'preference_notes' => 'clear',
                    'funding_reference' => 'clear',
                    'funding_approved_ref' => 'clear',
                    'decision_notes' => 'clear',
                ],
                'client',
            ),
            RespiteBooking::class => new RetentionOwnerAdapter(
                RespiteBooking::class,
                'Respite bookings',
                RespiteBooking::class,
                [
                    'client_id' => ['equals', 'in'],
                    'status' => ['equals', 'in', 'not_in'],
                    'funding_status' => ['equals', 'in', 'not_in'],
                    'agreement_status' => ['equals', 'in', 'not_in'],
                ],
                [
                    'funding_reference' => 'clear',
                    'consent_authority_name' => 'clear',
                    'consent_authority_contact' => 'clear',
                    'consent_authority_evidence' => 'clear',
                    'funding_approved_ref' => 'clear',
                    'cancellation_reason' => 'clear',
                    'approvals' => 'clear',
                    'eligibility_checks' => 'clear',
                    'consent_records' => 'clear',
                    'funding_verification' => 'clear',
                    'pre_arrival_checklist' => 'clear',
                    'cultural_snapshot' => 'clear',
                    'cultural_placement_check' => 'clear',
                ],
                'client',
            ),
            RespiteStay::class => new RetentionOwnerAdapter(
                RespiteStay::class,
                'Respite stays',
                RespiteStay::class,
                [
                    'client_id' => ['equals', 'in'],
                    'status' => ['equals', 'in', 'not_in'],
                    'bed_hold_status' => ['equals', 'in'],
                ],
                [
                    'arrival_checklist' => 'clear',
                    'admission_risk_screen' => 'clear',
                    'discharge_summary' => 'redact',
                    'discharge_reason' => 'clear',
                    'discharge_medication_reconciliation' => 'clear',
                    'discharge_checklist' => 'clear',
                    'post_respite_summary' => 'redact',
                    'transport_arrangements' => 'clear',
                    'absence_records' => 'clear',
                    'bed_hold_reason' => 'clear',
                    'cultural_support_notes' => 'clear',
                ],
                'client',
            ),
            RespiteDailyNote::class => new RetentionOwnerAdapter(
                RespiteDailyNote::class,
                'Respite daily notes',
                RespiteDailyNote::class,
                [
                    'client_id' => ['equals', 'in'],
                    'shift_period' => ['equals', 'in'],
                    'incident_occurred' => ['equals'],
                    'sensitive_flag' => ['equals'],
                ],
                [
                    'activities' => 'clear',
                    'observations' => 'redact',
                    'concerns' => 'redact',
                    'goals_progress' => 'clear',
                    'medication_notes' => 'redact',
                    'personal_care_notes' => 'redact',
                    'nutrition_notes' => 'redact',
                    'attachments' => 'clear',
                ],
                'client',
            ),
            RespiteEvidencePack::class => new RetentionOwnerAdapter(
                RespiteEvidencePack::class,
                'Respite evidence packs',
                RespiteEvidencePack::class,
                ['status' => ['equals', 'in', 'not_in'], 'pack_type' => ['equals', 'in']],
                [
                    'summary' => 'redact',
                    'items' => 'clear',
                    'included_documents' => 'clear',
                    'included_incidents' => 'clear',
                    'included_medications' => 'clear',
                    'included_daily_notes' => 'clear',
                    'included_handovers' => 'clear',
                    'coordinator_notes' => 'redact',
                    'family_feedback' => 'redact',
                ],
                'stay.client',
            ),
            RespiteMedicationReconciliation::class => new RetentionOwnerAdapter(
                RespiteMedicationReconciliation::class,
                'Respite medication reconciliations',
                RespiteMedicationReconciliation::class,
                ['status' => ['equals', 'in', 'not_in'], 'type' => ['equals', 'in']],
                ['source' => 'clear', 'discrepancies' => 'clear', 'override_reason' => 'clear'],
                'stay.client',
            ),
        ];
    }
}
