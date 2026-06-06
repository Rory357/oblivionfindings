<?php

namespace Database\Seeders;

use App\Domain\Governance\Models\NotifiableIncident;
use App\Models\BehaviourSupportPlan;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\MedicationAllergy;
use App\Models\RespiteBooking;
use App\Models\RespiteBookingRequest;
use App\Models\RespiteComplaint;
use App\Models\RespiteEvidencePack;
use App\Models\RespiteMedicationReconciliation;
use App\Models\RespiteReferral;
use App\Models\RespiteStay;
use App\Models\RestraintEvent;
use App\Models\ServiceAgreement;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class RespiteDemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('role', 'admin')->first() ?? User::first();

        if (! $user) {
            $this->command?->warn('No users found; skipping respite demo data.');

            return;
        }

        $site = Site::query()->where('name', 'Aroha Respite')->first()
            ?? Site::query()->create([
                'name' => 'Aroha Respite',
                'type' => 'house',
                'city' => 'Auckland',
                'country' => 'New Zealand',
                'is_active' => true,
            ]);
        $site->forceFill([
            'offers_respite' => true,
            'respite_capacity' => 2,
            'respite_description' => 'Short-break respite home configured for NZ demo workflows.',
        ])->save();

        $context = ServiceContext::query()->firstOrCreate(
            ['type' => 'planned_respite'],
            ['name' => 'Planned respite', 'is_active' => true]
        );

        $client = Client::query()
            ->where('first_name', 'Aroha')
            ->where('last_name', 'Rangi')
            ->first()
            ?? Client::query()->create([
                'first_name' => 'Aroha',
                'last_name' => 'Rangi',
                'nhi_number' => 'ARH1234',
                'date_of_birth' => '1988-04-12',
                'site_id' => $site->id,
                'service_context_id' => $context->id,
                'status' => 'active',
                'ethnicity' => 'Maori',
                'iwi' => 'Ngati Porou',
                'hapu' => 'Te Whanau a Hinerupe',
                'cultural_dietary_needs' => 'No pork; prefers karakia before admission.',
            ]);
        $client->forceFill([
            'site_id' => $site->id,
            'service_context_id' => $context->id,
            'status' => 'active',
        ])->save();

        MedicationAllergy::query()->updateOrCreate(
            ['client_id' => $client->id, 'allergen' => 'Peanuts'],
            [
                'reaction' => 'Anaphylaxis',
                'severity' => 'life_threatening',
                'recorded_by' => $user->id,
            ]
        );
        ClientMedication::query()->firstOrCreate(
            ['client_id' => $client->id, 'name' => 'Salbutamol inhaler'],
            [
                'dosage' => '100 micrograms',
                'frequency' => 'As required',
                'active' => true,
                'state' => 'active',
                'approval_status' => 'verified',
                'verified_by' => $user->id,
                'verified_at' => now()->subWeeks(2),
            ]
        );
        $bsp = BehaviourSupportPlan::query()->updateOrCreate(
            ['client_id' => $client->id, 'title' => 'Aroha respite BSP'],
            [
                'approved_interventions' => 'Low-arousal redirection and sensory-room support.',
                'restrictive_practice_type' => 'physical',
                'status' => 'active',
                'developed_by' => $user->id,
                'developed_at' => now()->subMonths(2),
                'review_date' => now()->addMonths(4),
                'created_by' => $user->id,
            ]
        );

        $agreement = ServiceAgreement::query()->updateOrCreate(
            ['reference_number' => 'RESP-DEMO-001'],
            [
                'client_id' => $client->id,
                'title' => 'Whaikaha respite allocation',
                'agreement_type' => 'carer_support',
                'funding_body' => 'Whaikaha',
                'funding_type' => 'carer_support',
                'status' => 'active',
                'starts_at' => now()->subMonth(),
                'ends_at' => now()->addMonths(6),
                'signed_at' => now()->subWeeks(2),
                'signed_date' => now()->subWeeks(2)->toDateString(),
                'daily_rate' => 240,
                'total_budget' => 3600,
                'budget_used' => 720,
                'total_hours' => 240,
                'hours_used' => 48,
                'carer_support_days_allocated' => 20,
                'carer_support_days_used' => 4,
                'carer_support_entitlement_year' => '2026-2027',
                'created_by' => $user->id,
            ]
        );

        $referral = RespiteReferral::query()->updateOrCreate(
            [
                'client_id' => $client->id,
                'referrer_name' => 'Demo NASC Coordinator',
                'referral_reason' => 'Planned carer-break respite demo pathway.',
            ],
            [
                'referrer_type' => 'nasc',
                'referrer_contact' => 'nasc@example.test',
                'third_party_source_type' => 'nasc',
                'third_party_source_name' => 'Demo NASC',
                'third_party_collection_consent' => true,
                'funding_source' => 'whaikaha',
                'funding_reference' => 'WK-DEMO-44213',
                'urgency' => 'planned',
                'status' => 'accepted',
                'received_at' => now()->subWeeks(3),
                'risk_level' => 'medium',
                'is_maori' => true,
                'ethnicity' => 'Maori',
                'iwi' => 'Ngati Porou',
                'hapu' => 'Te Whanau a Hinerupe',
                'marae' => 'Hinemaurea ki Mangatuna',
                'interpreter_required' => false,
                'interpreter_arranged' => false,
                'cultural_considerations' => 'Karakia on arrival; whanau visit expected.',
                'cultural_dietary_needs' => 'No pork.',
                'primary_carer_name' => 'Moana Rangi',
                'primary_carer_relationship' => 'daughter',
                'primary_carer_contact' => '021000000',
                'carer_strain_level' => 'at_breakdown',
                'carer_breakdown_flag' => true,
                'booker_type' => 'whanau',
                'created_by' => $user->id,
            ]
        );

        $start = Carbon::now()->subDay()->setTime(10, 0);
        $end = Carbon::now()->addDays(3)->setTime(10, 0);
        $request = RespiteBookingRequest::query()->updateOrCreate(
            ['referral_id' => $referral->id, 'client_id' => $client->id],
            [
                'service_context_id' => $context->id,
                'requested_start' => $start,
                'requested_end' => $end,
                'requirements' => ['room' => 'quiet room near staff base'],
                'intake_snapshot' => [
                    'cultural' => [
                        'is_maori' => true,
                        'iwi' => 'Ngati Porou',
                        'cultural_dietary_needs' => 'No pork.',
                    ],
                    'carer' => [
                        'name' => 'Moana Rangi',
                        'strain' => 'at_breakdown',
                    ],
                ],
                'funding_source' => 'whaikaha',
                'funding_reference' => 'WK-DEMO-44213',
                'service_agreement_id' => $agreement->id,
                'funding_status' => 'approved',
                'funding_approved_ref' => 'AUTH-DEMO-001',
                'funding_approved_at' => now()->subWeeks(2),
                'status' => 'approved',
                'priority' => 'high',
                'is_emergency' => false,
                'fast_tracked' => false,
                'allocated_days' => 4,
                'approved_by_user_id' => $user->id,
                'approved_at' => now()->subWeeks(2),
                'created_by' => $user->id,
            ]
        );

        $booking = RespiteBooking::query()->updateOrCreate(
            ['booking_request_id' => $request->id],
            [
                'client_id' => $client->id,
                'start_at' => $start,
                'end_at' => $end,
                'status' => 'confirmed',
                'location_id' => $site->id,
                'funding_source' => 'whaikaha',
                'funding_reference' => 'WK-DEMO-44213',
                'service_agreement_id' => $agreement->id,
                'funding_status' => 'approved',
                'agreement_status' => 'signed',
                'consent_authority' => 'welfare_guardian',
                'consent_authority_name' => 'Moana Rangi',
                'consent_authority_contact' => '021000000',
                'eligibility_checks' => ['eligible' => true],
                'pre_arrival_checklist' => ['receiving_home_ready' => true],
                'medications_reconciled' => true,
                'medications_reconciled_at' => now()->subDay(),
                'medications_reconciled_by' => $user->id,
                'code_of_rights_provided' => true,
                'consent_to_respite' => true,
                'consent_capacity_basis' => 'substitute_decision',
                'advocate_offered' => true,
                'rights_format_provided' => 'written',
                'rights_recorded_by' => $user->id,
                'rights_recorded_at' => now()->subWeeks(2),
                'cultural_snapshot' => [
                    'is_maori' => true,
                    'iwi' => 'Ngati Porou',
                    'cultural_dietary_needs' => 'No pork.',
                ],
                'cultural_placement_check' => [
                    'confirmed_by' => $user->id,
                    'notes' => 'Whanau support, karakia and food preferences confirmed.',
                ],
                'setting_restriction' => 'none',
                'interpreter_arranged' => false,
                'created_by' => $user->id,
            ]
        );

        $stay = RespiteStay::query()->updateOrCreate(
            ['booking_id' => $booking->id],
            [
                'client_id' => $client->id,
                'status' => 'active',
                'actual_start' => $start,
                'admission_risk_screen' => [
                    'anaphylaxis_acknowledgement' => [
                        'acknowledged' => true,
                        'epipen_location' => 'Medication cupboard, red emergency pouch.',
                        'escalation_note' => 'Call 111, administer EpiPen and notify on-call nurse.',
                        'recorded_by' => $user->id,
                        'recorded_at' => now()->subDay()->toIso8601String(),
                    ],
                ],
                'created_by' => $user->id,
            ]
        );

        RespiteMedicationReconciliation::query()->updateOrCreate(
            ['stay_id' => $stay->id, 'type' => 'admission'],
            [
                'status' => 'completed',
                'source' => 'pharmacy_pack',
                'count_received' => 4,
                'discrepancies' => [],
                'first_dose_due_at' => now()->addHours(4),
                'reconciled_by_user_id' => $user->id,
                'reconciled_at' => now()->subDay(),
                'created_by' => $user->id,
            ]
        );

        RestraintEvent::query()->updateOrCreate(
            ['stay_id' => $stay->id, 'restraint_type' => 'physical'],
            [
                'client_id' => $client->id,
                'behaviour_support_plan_id' => $bsp->id,
                'site_id' => $site->id,
                'started_at' => now()->subHours(6),
                'ended_at' => now()->subHours(6)->addMinutes(8),
                'duration_minutes' => 8,
                'severity' => 'medium',
                'trigger_description' => 'Moved toward the driveway while distressed.',
                'de_escalation_attempted' => 'Quiet-room prompt and whanau call offered.',
                'restraint_description' => 'Brief approved redirection away from driveway.',
                'within_support_plan' => true,
                'reviewed_by' => $user->id,
                'reviewed_at' => now()->subHours(3),
                'review_notes' => 'Reviewed; BSP approach remains current.',
                'created_by' => $user->id,
            ]
        );

        $incident = ClientIncident::query()->updateOrCreate(
            ['respite_stay_id' => $stay->id, 'title' => 'Respite demo fall'],
            [
                'client_id' => $client->id,
                'reported_by' => $user->id,
                'type' => 'injury',
                'severity' => 'high',
                'status' => 'reviewed',
                'submitted_at' => now()->subHours(5),
                'occurred_at' => now()->subHours(5),
                'description' => 'Slip in hallway; nurse reviewed and no transfer required.',
                'requires_followup' => true,
                'immediate_action_taken' => 'First aid, nurse review, whanau notified.',
                'is_notifiable' => true,
            ]
        );
        NotifiableIncident::query()->updateOrCreate(
            ['related_incident_id' => $incident->id],
            [
                'incident_type' => 'serious_harm',
                'notification_authority' => 'health_nz',
                'title' => $incident->title,
                'description' => $incident->description,
                'severity' => 'high',
                'status' => 'notified',
                'occurred_at' => $incident->occurred_at,
                'discovered_at' => now()->subHours(5),
                'notification_deadline' => now()->addHours(19),
                'submitted_by' => $user->id,
                'notified_by' => $user->id,
                'notified_at' => now()->subHours(2),
                'notification_reference' => 'HNZ-DEMO-001',
                'evidence' => [['type' => 'respite_stay', 'id' => $stay->id]],
            ]
        );

        RespiteComplaint::query()->updateOrCreate(
            ['stay_id' => $stay->id, 'nature' => 'Dinner preference missed'],
            [
                'client_id' => $client->id,
                'source' => 'whanau',
                'received_at' => now()->subHours(12),
                'details' => 'Whanau noted one meal did not follow the no-pork preference.',
                'acknowledged_at' => now()->subHours(10),
                'resolution' => 'Kitchen preference updated and team reminded.',
                'escalated_to_hdc' => 'offered',
                'status' => 'resolved',
                'created_by' => $user->id,
            ]
        );

        RespiteEvidencePack::query()->updateOrCreate(
            ['stay_id' => $stay->id],
            [
                'booking_id' => $booking->id,
                'status' => 'draft',
                'summary' => 'Demo respite evidence pack with NZ rights, anaphylaxis, restraint and incident records.',
                'items' => [],
                'created_by' => $user->id,
            ]
        );

        $pendingClient = Client::query()
            ->where('first_name', 'Wiremu')
            ->where('last_name', 'Clarke')
            ->first()
            ?? Client::query()->create([
                'first_name' => 'Wiremu',
                'last_name' => 'Clarke',
                'nhi_number' => 'WRC5678',
                'date_of_birth' => '1979-09-20',
                'site_id' => $site->id,
                'service_context_id' => $context->id,
                'status' => 'active',
            ]);
        RespiteBooking::query()->updateOrCreate(
            ['client_id' => $pendingClient->id, 'start_at' => now()->addDays(7)->setTime(10, 0)],
            [
                'end_at' => now()->addDays(10)->setTime(10, 0),
                'status' => 'pending',
                'location_id' => $site->id,
                'funding_status' => 'pending_approval',
                'agreement_status' => 'sent',
                'created_by' => $user->id,
            ]
        );

        $this->command?->info('Respite demo data seeded.');
    }
}
