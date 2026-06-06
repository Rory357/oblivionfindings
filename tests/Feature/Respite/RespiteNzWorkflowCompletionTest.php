<?php

namespace Tests\Feature\Respite;

use App\Domain\Governance\Models\NotifiableIncident;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\RespiteBooking;
use App\Models\RespiteBookingRequest;
use App\Models\RespiteEvidencePack;
use App\Models\RespiteReferral;
use App\Models\RespiteStay;
use App\Models\RestraintEvent;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RespiteNzWorkflowCompletionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ServiceContext $serviceContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->serviceContext = ServiceContext::factory()->create([
            'type' => 'planned_respite',
            'is_active' => true,
        ]);
    }

    public function test_intake_reuses_existing_client_by_nhi_hash_and_carries_cultural_and_carer_snapshot(): void
    {
        $existing = Client::factory()->create([
            'first_name' => 'Aroha',
            'last_name' => 'Rangi',
            'nhi_number' => 'ABC1234',
        ]);

        $this->actingAs($this->admin)
            ->post(route('respite.referrals.store'), [
                'new_client' => [
                    'first_name' => 'Duplicate',
                    'last_name' => 'Person',
                    'nhi_number' => 'abc1234',
                ],
                'referrer_type' => 'nasc',
                'referrer_name' => 'NASC Coordinator',
                'referrer_contact' => 'nasc@example.test',
                'referral_reason' => 'Planned respite allocation',
                'urgency' => 'crisis',
                'risk_level' => 'high',
                'funding_source' => 'whaikaha',
                'nhi_number' => 'abc1234',
                'third_party_source_type' => 'nasc',
                'third_party_source_name' => 'Local NASC',
                'third_party_collection_consent' => true,
                'is_maori' => true,
                'ethnicity' => 'Maori',
                'iwi' => 'Ngati Porou',
                'hapu' => 'Te Whanau a Hinerupe',
                'marae' => 'Hinemaurea ki Mangatuna',
                'interpreter_required' => true,
                'interpreter_language' => 'te reo Maori',
                'interpreter_arranged' => false,
                'cultural_considerations' => 'Prefers karakia before admission.',
                'cultural_dietary_needs' => 'No pork.',
                'primary_carer_name' => 'Moana Rangi',
                'primary_carer_relationship' => 'daughter',
                'primary_carer_contact' => '021000000',
                'carer_strain_level' => 'at_breakdown',
                'carer_breakdown_flag' => true,
                'booker_type' => 'whanau',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Client::where('nhi_hash', Client::nhiHash('ABC1234'))->count());
        $this->assertSame(1, Client::count());
        $this->assertSame(1, RespiteReferral::count());

        $referral = RespiteReferral::firstOrFail();
        $this->assertSame($existing->id, $referral->client_id);
        $this->assertSame('ABC1234', $referral->nhi_number);
        $this->assertSame(Client::nhiHash('ABC1234'), $referral->nhi_hash);
        $this->assertTrue($referral->is_maori);
        $this->assertSame('Ngati Porou', $referral->iwi);
        $this->assertSame('at_breakdown', $referral->carer_strain_level);
        $this->assertTrue($referral->carer_breakdown_flag);
    }

    public function test_capacity_gate_blocks_full_home_and_promotes_matching_waitlist_request(): void
    {
        Carbon::setTestNow('2026-06-06 09:00:00');

        $site = Site::factory()->create([
            'offers_respite' => true,
            'respite_capacity' => 1,
        ]);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $waitlistedClient = Client::factory()->create(['site_id' => $site->id]);
        $start = Carbon::parse('2026-06-10 09:00:00');
        $end = Carbon::parse('2026-06-12 17:00:00');

        RespiteBooking::factory()->create([
            'client_id' => $client->id,
            'location_id' => $site->id,
            'start_at' => $start,
            'end_at' => $end,
            'status' => 'confirmed',
        ]);

        $booking = RespiteBooking::factory()->create([
            'client_id' => $waitlistedClient->id,
            'location_id' => $site->id,
            'start_at' => $start->copy()->addHours(1),
            'end_at' => $end->copy()->subHours(1),
            'status' => 'pending',
        ]);

        $this->actingAs($this->admin)
            ->post(route('respite.bookings.confirm', $booking))
            ->assertSessionHasErrors('capacity');

        $waitlisted = RespiteBookingRequest::create([
            'client_id' => $waitlistedClient->id,
            'service_context_id' => $this->serviceContext->id,
            'requested_start' => $start,
            'requested_end' => $end,
            'status' => 'waitlisted',
            'waitlist_position' => 1,
            'priority' => 'crisis',
            'expected_availability_date' => $start->toDateString(),
            'created_by' => $this->admin->id,
        ]);

        RespiteBooking::where('location_id', $site->id)->update(['status' => 'cancelled']);

        $this->actingAs($this->admin)
            ->post(route('respite.requests.promote', $waitlisted), [
                'location_id' => $site->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', $waitlisted->refresh()->status);
        $promotedBooking = RespiteBooking::where('booking_request_id', $waitlisted->id)->firstOrFail();
        $this->assertSame('pending', $promotedBooking->status);
        $this->assertSame($site->id, $promotedBooking->location_id);
        $this->assertNotEmpty($promotedBooking->approvals['waitlist_promotion'] ?? null);

        Carbon::setTestNow();
    }

    public function test_respite_stay_records_restraint_and_notifiable_incident_then_blocks_discharge_until_reviewed(): void
    {
        $client = Client::factory()->create();
        $booking = RespiteBooking::factory()->create([
            'client_id' => $client->id,
            'status' => 'confirmed',
        ]);
        $stay = RespiteStay::create([
            'booking_id' => $booking->id,
            'client_id' => $client->id,
            'status' => 'active',
            'actual_start' => now()->subDay(),
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->post(route('respite.stays.restraints.store', $stay), [
                'started_at' => now()->subHours(2)->format('Y-m-d H:i:s'),
                'ended_at' => now()->subHour()->format('Y-m-d H:i:s'),
                'restraint_type' => 'physical',
                'severity' => 'high',
                'trigger_description' => 'Attempted to leave unsafely near traffic.',
                'de_escalation_attempted' => 'Verbal reassurance and quiet room offered.',
                'restraint_description' => 'Two-person supported hold.',
                'within_support_plan' => false,
                'deviation_reason' => 'Immediate safety risk.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $restraint = RestraintEvent::firstOrFail();
        $this->assertSame($stay->id, $restraint->stay_id);

        $this->actingAs($this->admin)
            ->post(route('respite.stays.incidents.store', $stay), [
                'type' => 'injury',
                'severity' => 'high',
                'title' => 'Fall during respite stay',
                'description' => 'Client fell and required clinical review.',
                'occurred_at' => now()->subHour()->format('Y-m-d H:i:s'),
                'is_notifiable' => true,
                'notification_authority' => 'health_nz',
                'incident_type' => 'serious_harm',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $incident = ClientIncident::firstOrFail();
        $this->assertSame($stay->id, $incident->respite_stay_id);
        $this->assertSame($incident->id, NotifiableIncident::firstOrFail()->related_incident_id);

        $this->actingAs($this->admin)
            ->post(route('respite.stays.discharge', $stay), [
                'discharge_summary' => 'Ready to leave.',
            ])
            ->assertSessionHasErrors('compliance');

        $restraint->update([
            'reviewed_by' => $this->admin->id,
            'reviewed_at' => now(),
            'review_notes' => 'Reviewed and actions closed.',
        ]);
        $incident->update([
            'status' => 'reviewed',
            'reviewed_by' => $this->admin->id,
            'reviewed_at' => now(),
            'review_notes' => 'Reviewed.',
        ]);
        NotifiableIncident::firstOrFail()->markNotified($this->admin->id, 'HNZ-123');

        $this->actingAs($this->admin)
            ->post(route('respite.stays.discharge', $stay), [
                'discharge_summary' => 'Ready to leave.',
                'discharge_reason' => 'planned',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    public function test_evidence_pack_manifest_blocks_sealing_until_required_items_complete(): void
    {
        $client = Client::factory()->create();
        $booking = RespiteBooking::factory()->create([
            'client_id' => $client->id,
            'consent_authority' => null,
        ]);
        $stay = RespiteStay::create([
            'booking_id' => $booking->id,
            'client_id' => $client->id,
            'status' => 'active',
            'actual_start' => now()->subDay(),
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->post(route('respite.evidence-packs.store'), [
                'stay_id' => $stay->id,
                'summary' => 'Audit pack',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $pack = RespiteEvidencePack::firstOrFail();
        $this->assertContains('consent_rights', collect($pack->items)->pluck('type')->all());
        $this->assertFalse(collect($pack->items)->firstWhere('type', 'consent_rights')['complete']);

        $this->actingAs($this->admin)
            ->post(route('respite.evidence-packs.seal', $pack), [
                'seal_reason' => 'Archive complete.',
            ])
            ->assertSessionHasErrors('manifest');

        $booking->update([
            'consent_authority' => 'self',
            'agreement_status' => 'signed',
        ]);

        $this->actingAs($this->admin)
            ->post(route('respite.evidence-packs.seal', $pack), [
                'seal_reason' => 'Archive complete.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }
}
