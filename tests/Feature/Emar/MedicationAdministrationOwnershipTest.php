<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationError;
use App\Models\MedicationRefusalFollowup;
use App\Models\MedicationRound;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Notifications\AppEventNotification;
use App\Services\MedicationIncidentIntegrationService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\TestCase;

class MedicationAdministrationOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_broad_client_and_order_permissions_never_substitute_for_administration_permissions(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $medication = $this->medication($client);
        $reporter = User::factory()->create();
        $administration = $this->administration($client, $medication, $reporter, 'refused');
        $error = $this->error($client, $reporter, 'resolved');
        $actor = $this->userWithPermissions(
            ['clients.update', 'medications.orders.manage'],
            $site,
            $client,
        );
        $this->denyPermissions($actor, [
            'medications.administer.record',
            'medications.administer.correct',
        ]);

        $this->actingAs($actor)
            ->post(route('clients.mar.administrations.corrections.store', [$client, $administration]), [
                'status' => 'withheld',
            ])
            ->assertForbidden();
        $this->actingAs($actor)
            ->post(route('emar.refusal_followups.store'), $this->followupPayload($client, $administration))
            ->assertForbidden();
        $this->actingAs($actor)
            ->post(route('emar.errors.store'), $this->errorPayload($client))
            ->assertForbidden();
        $this->actingAs($actor)
            ->post(route('emar.errors.close', $error), ['close_note' => 'Not authorised'])
            ->assertForbidden();

        $this->assertDatabaseCount('medication_refusal_followups', 0);
        $this->assertDatabaseCount('medication_errors', 1);
        $this->assertDatabaseMissing('client_medication_administrations', [
            'corrected_of_id' => $administration->id,
            'is_correction' => true,
        ]);
    }

    public function test_exact_corrector_can_update_local_records_but_foreign_site_direct_objects_are_concealed(): void
    {
        $localSite = Site::factory()->create(['is_active' => true]);
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $localClient = Client::factory()->create(['site_id' => $localSite->id]);
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);
        $localMedication = $this->medication($localClient);
        $foreignMedication = $this->medication($foreignClient);
        $reporter = User::factory()->create();
        $localOriginal = $this->administration($localClient, $localMedication, $reporter, 'refused');
        $foreignOriginal = $this->administration($foreignClient, $foreignMedication, $reporter, 'refused');
        $localCorrection = $this->correction($localOriginal, $reporter);
        $foreignCorrection = $this->correction($foreignOriginal, $reporter);
        $localFollowup = $this->followup($localClient, $localOriginal, $reporter);
        $foreignFollowup = $this->followup($foreignClient, $foreignOriginal, $reporter);
        $localError = $this->error($localClient, $reporter, 'resolved');
        $foreignError = $this->error($foreignClient, $reporter, 'resolved');
        $corrector = $this->userWithPermissions(
            ['medications.administer.correct'],
            $localSite,
        );

        $this->actingAs($corrector)
            ->post(route('emar.corrections.approve', $localCorrection))
            ->assertRedirect();
        $this->assertSame('approved', $localCorrection->fresh()->correction_status);

        $this->actingAs($corrector)
            ->post(route('emar.corrections.approve', $foreignCorrection))
            ->assertNotFound();
        $this->actingAs($corrector)
            ->post(route('emar.refusal_followups.complete', $localFollowup), ['outcome' => 'Reviewed locally.'])
            ->assertRedirect();
        $this->actingAs($corrector)
            ->post(route('emar.refusal_followups.complete', $foreignFollowup), ['outcome' => 'Must remain hidden.'])
            ->assertNotFound();
        $this->actingAs($corrector)
            ->post(route('emar.errors.close', $localError), ['close_note' => 'Local learning recorded.'])
            ->assertRedirect();
        $this->actingAs($corrector)
            ->post(route('emar.errors.close', $foreignError), ['close_note' => 'Must remain hidden.'])
            ->assertNotFound();

        $this->assertSame('pending', $foreignCorrection->fresh()->correction_status);
        $this->assertNull($foreignFollowup->fresh()->follow_up_completed_at);
        $this->assertSame('resolved', $foreignError->fresh()->status);
    }

    public function test_record_actions_use_the_canonical_owner_and_require_a_current_client_assignment(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $assignedClient = Client::factory()->create(['site_id' => $site->id]);
        $unassignedClient = Client::factory()->create(['site_id' => $site->id]);
        $assignedMedication = $this->medication($assignedClient);
        $unassignedMedication = $this->medication($unassignedClient);
        $reporter = User::factory()->create();
        $assignedRefusal = $this->administration($assignedClient, $assignedMedication, $reporter, 'refused');
        $unassignedRefusal = $this->administration($unassignedClient, $unassignedMedication, $reporter, 'refused');
        $recorder = $this->userWithPermissions(
            ['medications.administer.record'],
            $site,
            $assignedClient,
        );

        $this->actingAs($recorder)
            ->post(route('emar.refusal_followups.store'), $this->followupPayload($assignedClient, $assignedRefusal))
            ->assertRedirect();
        $this->actingAs($recorder)
            ->post(route('emar.refusal_followups.store'), $this->followupPayload($assignedClient, $unassignedRefusal))
            ->assertNotFound();
        $this->actingAs($recorder)
            ->post(route('emar.refusal_followups.store'), $this->followupPayload($unassignedClient, $unassignedRefusal))
            ->assertNotFound();

        $this->actingAs($recorder)
            ->post(route('emar.errors.store'), $this->errorPayload($assignedClient, $assignedMedication))
            ->assertRedirect();
        $this->actingAs($recorder)
            ->post(route('emar.errors.store'), $this->errorPayload($assignedClient, $unassignedMedication))
            ->assertNotFound();
        $this->actingAs($recorder)
            ->post(route('emar.errors.store'), $this->errorPayload($unassignedClient, $unassignedMedication))
            ->assertNotFound();

        $this->assertDatabaseCount('medication_refusal_followups', 1);
        $this->assertDatabaseCount('medication_errors', 1);
    }

    public function test_web_correction_clears_replay_identity_and_enters_the_two_person_workflow(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $medication = $this->medication($client);
        $performer = User::factory()->create();
        $witness = User::factory()->create();
        $original = $this->administration($client, $medication, $performer, 'given');
        $original->forceFill([
            'client_request_uuid' => '22222222-2222-4222-8222-222222222222',
            'witnessed_by' => $witness->id,
            'witnessed_at' => now(),
        ])->save();
        $corrector = $this->userWithPermissions(['medications.administer.correct'], $site);
        $approver = $this->userWithPermissions(['medications.administer.correct'], $site);

        $this->actingAs($corrector)
            ->post(route('clients.mar.administrations.corrections.store', [$client, $original]), [
                'status' => 'refused',
                'reason' => 'Client declined the dose.',
                'correction_reason' => 'The original outcome was charted incorrectly.',
            ])
            ->assertRedirect();

        $correction = ClientMedicationAdministration::query()
            ->where('corrected_of_id', $original->id)
            ->sole();
        $this->assertNull($correction->client_request_uuid);
        $this->assertSame('pending', $correction->correction_status);
        $this->assertSame($performer->id, (int) $correction->administered_by);
        $this->assertSame($witness->id, (int) $correction->witnessed_by);
        $this->assertSame($corrector->id, (int) $correction->correction_requested_by);
        $this->assertSame('given', $original->fresh()->status);

        $this->actingAs($corrector)
            ->post(route('clients.mar.administrations.corrections.store', [$client, $correction]), [
                'status' => 'withheld',
                'correction_reason' => 'A pending correction cannot itself be corrected.',
            ])
            ->assertNotFound();

        $this->actingAs($corrector)
            ->post(route('emar.corrections.approve', $correction))
            ->assertSessionHas('error');
        $this->assertSame('pending', $correction->fresh()->correction_status);

        $this->actingAs($approver)
            ->post(route('emar.corrections.approve', $correction))
            ->assertRedirect();
        $approved = $correction->fresh();
        $this->assertSame('approved', $approved->correction_status);
        $this->assertSame($approver->id, (int) $approved->correction_approved_by);
        $this->assertSame($performer->id, (int) $approved->administered_by);
        $this->assertSame($witness->id, (int) $approved->witnessed_by);
        $this->assertSame($corrector->id, (int) $approved->correction_requested_by);
        $this->assertSame([$approved->id], ClientMedicationAdministration::query()
            ->effectiveClinicalEvidence()
            ->where(fn ($query) => $query
                ->whereKey($original->id)
                ->orWhere('corrected_of_id', $original->id))
            ->pluck('id')
            ->all());
        $this->assertSame(1, ClientMedicationAdministration::query()
            ->where('corrected_of_id', $original->id)
            ->count());
    }

    public function test_legacy_correction_approval_uses_administered_by_as_requester_fallback(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $medication = $this->medication($client);
        $performer = User::factory()->create();
        $original = $this->administration($client, $medication, $performer, 'given');
        $legacyRequester = $this->userWithPermissions(['medications.administer.correct'], $site);
        $independentApprover = $this->userWithPermissions(['medications.administer.correct'], $site);
        $legacyCorrection = $this->correction($original, $legacyRequester);

        $this->assertNull($legacyCorrection->correction_requested_by);
        $this->actingAs($legacyRequester)
            ->post(route('emar.corrections.approve', $legacyCorrection))
            ->assertSessionHas('error');
        $this->assertSame('pending', $legacyCorrection->fresh()->correction_status);

        $this->actingAs($independentApprover)
            ->post(route('emar.corrections.approve', $legacyCorrection))
            ->assertRedirect();
        $this->assertSame('approved', $legacyCorrection->fresh()->correction_status);
    }

    public function test_controlled_correction_rejects_collapsed_performer_and_witness_provenance(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $medication = $this->medication($client);
        $medication->forceFill(['controlled_drug' => true])->save();
        $performer = User::factory()->create();
        $original = $this->administration($client, $medication, $performer, 'given');
        $original->forceFill([
            'witnessed_by' => $performer->id,
            'witnessed_at' => now(),
        ])->save();
        $corrector = $this->userWithPermissions([
            'medications.administer.correct',
            'medications.controlled.record',
        ], $site);

        $this->actingAs($corrector)
            ->post(route('clients.mar.administrations.corrections.store', [$client, $original]), [
                'status' => 'given',
                'notes' => 'This must not clone invalid controlled provenance.',
                'correction_reason' => 'Attempted correction of collapsed evidence.',
            ])
            ->assertSessionHasErrors('administration');

        $this->assertDatabaseMissing('client_medication_administrations', [
            'corrected_of_id' => $original->id,
            'is_correction' => true,
        ]);

        $legacyPending = $original->replicate([
            'id',
            'client_request_uuid',
            'created_at',
            'updated_at',
        ]);
        $legacyPending->forceFill([
            'is_correction' => true,
            'corrected_of_id' => $original->id,
            'correction_reason' => 'Legacy collapsed controlled evidence.',
            'correction_requested_by' => $corrector->id,
            'correction_status' => 'pending',
        ])->save();
        $approver = $this->userWithPermissions([
            'medications.administer.correct',
            'medications.controlled.record',
        ], $site);

        $this->actingAs($approver)
            ->post(route('emar.corrections.approve', $legacyPending))
            ->assertSessionHasErrors('administration');
        $this->assertSame('pending', $legacyPending->fresh()->correction_status);
    }

    public function test_refusal_followups_use_only_original_or_approved_correction_evidence_and_complete_once(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $medication = $this->medication($client);
        $reporter = User::factory()->create();
        $original = $this->administration($client, $medication, $reporter, 'refused');
        $pending = $this->correction($original, $reporter);
        $rejected = $this->correction($original, $reporter);
        $rejected->forceFill(['correction_status' => 'rejected'])->save();
        $approved = $this->correction($original, $reporter);
        $approved->forceFill(['correction_status' => 'approved'])->save();
        $recorder = $this->userWithPermissions(
            ['medications.administer.record'],
            $site,
            $client,
        );

        foreach ([$pending, $rejected] as $ineffectiveRefusal) {
            $this->actingAs($recorder)
                ->post(
                    route('emar.refusal_followups.store'),
                    $this->followupPayload($client, $ineffectiveRefusal),
                )
                ->assertNotFound();
        }
        $pending->forceFill(['correction_status' => 'rejected'])->save();

        $correctedToGivenOriginal = $this->administration($client, $medication, $reporter, 'refused');
        $correctedToGiven = $this->correction($correctedToGivenOriginal, $reporter);
        $correctedToGiven->forceFill([
            'status' => 'given',
            'correction_status' => 'approved',
            'correction_approved_at' => now(),
        ])->save();
        foreach ([$correctedToGivenOriginal, $correctedToGiven] as $nonRefusalEvidence) {
            $this->actingAs($recorder)
                ->post(
                    route('emar.refusal_followups.store'),
                    $this->followupPayload($client, $nonRefusalEvidence),
                )
                ->assertNotFound();
        }

        $this->actingAs($recorder)
            ->post(route('emar.refusal_followups.store'), $this->followupPayload($client, $original))
            ->assertRedirect();
        $followup = MedicationRefusalFollowup::query()->sole();
        $this->assertSame($original->id, (int) $followup->client_medication_administration_id);
        $this->assertFalse((bool) $followup->escalated_to_manager);

        $corrector = $this->userWithPermissions(['medications.administer.correct'], $site);
        $this->actingAs($corrector)
            ->post(route('emar.refusal_followups.complete', $followup), ['outcome' => 'Original completion evidence.'])
            ->assertRedirect();
        $completed = $followup->fresh();
        $this->actingAs($corrector)
            ->post(route('emar.refusal_followups.complete', $followup), ['outcome' => 'Must not overwrite evidence.'])
            ->assertRedirect();
        $replayed = $followup->fresh();

        $this->assertSame('Original completion evidence.', $replayed->follow_up_outcome);
        $this->assertSame($completed->follow_up_completed_by, $replayed->follow_up_completed_by);
        $this->assertTrue($completed->follow_up_completed_at->equalTo($replayed->follow_up_completed_at));

        $this->actingAs($corrector)
            ->post(route('emar.refusal_followups.notify_gp', $followup), ['gp_response' => 'GP reviewed the plan.'])
            ->assertRedirect();
        $notified = $followup->fresh();
        $this->actingAs($corrector)
            ->post(route('emar.refusal_followups.notify_gp', $followup))
            ->assertRedirect();
        $replayedNotification = $followup->fresh();
        $this->assertSame('GP reviewed the plan.', $replayedNotification->gp_response);
        $this->assertSame($notified->gp_notified_by, $replayedNotification->gp_notified_by);
        $this->assertTrue($notified->gp_notified_at->equalTo($replayedNotification->gp_notified_at));
    }

    public function test_correction_aggregate_keeps_one_pending_and_approval_retires_legacy_siblings(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $medication = $this->medication($client);
        $reporter = User::factory()->create();
        $original = $this->administration($client, $medication, $reporter, 'given');
        $submitter = $this->userWithPermissions(['medications.administer.correct'], $site);
        $approver = $this->userWithPermissions(['medications.administer.correct'], $site);
        $payload = [
            'status' => 'refused',
            'reason' => 'Client declined.',
            'correction_reason' => 'The original outcome was charted incorrectly.',
        ];

        $this->actingAs($submitter)
            ->post(route('clients.mar.administrations.corrections.store', [$client, $original]), $payload)
            ->assertRedirect();
        $pending = ClientMedicationAdministration::query()
            ->where('corrected_of_id', $original->id)
            ->where('correction_status', 'pending')
            ->sole();

        $this->actingAs($submitter)
            ->post(route('clients.mar.administrations.corrections.store', [$client, $original]), $payload)
            ->assertSessionHas('error');
        $this->actingAs($submitter)
            ->postJson(route('api.medications.administrations.correct', [
                'client' => $client,
                'administration' => $original,
            ]), $payload)
            ->assertConflict();
        $this->assertSame(1, ClientMedicationAdministration::query()
            ->where('corrected_of_id', $original->id)
            ->where('correction_status', 'pending')
            ->count());

        $legacyApproved = $this->correction($original, $reporter);
        $legacyApproved->forceFill([
            'correction_status' => 'approved',
            'correction_approved_at' => now()->subMinute(),
        ])->save();
        $legacyPending = $this->correction($original, $reporter);

        $this->actingAs($approver)
            ->post(route('emar.corrections.approve', $pending))
            ->assertRedirect();

        $this->assertSame('approved', $pending->fresh()->correction_status);
        $this->assertSame('rejected', $legacyApproved->fresh()->correction_status);
        $this->assertSame('rejected', $legacyPending->fresh()->correction_status);
        $this->assertSame([$pending->id], ClientMedicationAdministration::query()
            ->effectiveClinicalEvidence()
            ->where(fn ($query) => $query
                ->whereKey($original->id)
                ->orWhere('corrected_of_id', $original->id))
            ->pluck('id')
            ->all());
    }

    public function test_correction_approval_refreshes_completed_round_counters_and_rejection_leaves_them_unchanged(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $medication = $this->medication($client);
        $reporter = User::factory()->create();
        $submitter = $this->userWithPermissions(['medications.administer.correct'], $site);
        $approver = $this->userWithPermissions(['medications.administer.correct'], $site);
        $round = $this->completedMedicationRound($client);
        $original = $this->administration($client, $medication, $reporter, 'given');
        $original->forceFill(['medication_round_id' => $round->id])->save();
        $round->updateCounts();
        $completedAt = $round->fresh()->completed_at;

        $this->assertRoundCounts($round, administered: 1, refused: 0, withheld: 0);

        $this->actingAs($submitter)
            ->post(route('clients.mar.administrations.corrections.store', [$client, $original]), [
                'status' => 'refused',
                'reason' => 'Client declined the dose.',
                'correction_reason' => 'The original outcome was charted incorrectly.',
            ])
            ->assertRedirect();
        $refused = ClientMedicationAdministration::query()
            ->where('corrected_of_id', $original->id)
            ->where('correction_status', 'pending')
            ->sole();

        $this->actingAs($approver)
            ->post(route('emar.corrections.approve', $refused))
            ->assertRedirect();

        $this->assertRoundCounts($round, administered: 0, refused: 1, withheld: 0);
        $this->assertSame('completed', $round->fresh()->status);
        $this->assertTrue($completedAt->equalTo($round->fresh()->completed_at));

        $this->actingAs($submitter)
            ->post(route('clients.mar.administrations.corrections.store', [$client, $original]), [
                'status' => 'withheld',
                'reason' => 'The dose was clinically withheld.',
                'correction_reason' => 'The corrected outcome also required replacement.',
            ])
            ->assertRedirect();
        $withheld = ClientMedicationAdministration::query()
            ->where('corrected_of_id', $original->id)
            ->where('correction_status', 'pending')
            ->sole();

        $this->actingAs($approver)
            ->post(route('emar.corrections.approve', $withheld))
            ->assertRedirect();

        $this->assertRoundCounts($round, administered: 0, refused: 0, withheld: 1);

        $this->actingAs($submitter)
            ->post(route('clients.mar.administrations.corrections.store', [$client, $original]), [
                'status' => 'missed',
                'reason' => 'Proposed replacement outcome.',
                'correction_reason' => 'This proposal should be rejected.',
            ])
            ->assertRedirect();
        $rejected = ClientMedicationAdministration::query()
            ->where('corrected_of_id', $original->id)
            ->where('correction_status', 'pending')
            ->sole();
        $round->forceFill(['updated_at' => now()->subMinutes(5)])->saveQuietly();
        $roundUpdatedAtBeforeRejection = $round->fresh()->updated_at;

        $this->actingAs($approver)
            ->post(route('emar.corrections.reject', $rejected), ['reason' => 'The existing correction is accurate.'])
            ->assertRedirect();

        $this->assertSame('rejected', $rejected->fresh()->correction_status);
        $this->assertRoundCounts($round, administered: 0, refused: 0, withheld: 1);
        $this->assertSame('completed', $round->fresh()->status);
        $this->assertTrue($completedAt->equalTo($round->fresh()->completed_at));
        $this->assertTrue($roundUpdatedAtBeforeRejection->equalTo($round->fresh()->updated_at));
    }

    public function test_correction_approval_adopts_the_effective_legacy_round_on_the_new_winner_only(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $medication = $this->medication($client);
        $reporter = User::factory()->create();
        $submitter = $this->userWithPermissions(['medications.administer.correct'], $site);
        $approver = $this->userWithPermissions(['medications.administer.correct'], $site);
        $round = $this->completedMedicationRound($client);
        $original = $this->administration($client, $medication, $reporter, 'given');
        $approvedWinner = $this->correction($original, $reporter);
        $approvedWinner->forceFill([
            'medication_round_id' => $round->id,
            'status' => 'refused',
            'correction_status' => 'approved',
            'correction_approved_at' => now()->subMinute(),
        ])->save();
        $pending = $this->correction($original, $submitter);
        $pending->forceFill([
            'status' => 'withheld',
            'client_request_uuid' => 'legacy-round-correction-request',
        ])->save();
        $round->updateCounts();
        $requestActorId = (int) $pending->administered_by;
        $requestUuid = $pending->client_request_uuid;

        $this->assertNull($original->medication_round_id);
        $this->assertNull($pending->medication_round_id);
        $this->assertRoundCounts($round, administered: 0, refused: 1, withheld: 0);

        $this->actingAs($approver)
            ->post(route('emar.corrections.approve', $pending))
            ->assertRedirect();

        $freshPending = $pending->fresh();
        $this->assertSame('approved', $freshPending->correction_status);
        $this->assertSame($round->id, (int) $freshPending->medication_round_id);
        $this->assertSame($requestActorId, (int) $freshPending->administered_by);
        $this->assertSame($requestUuid, $freshPending->client_request_uuid);
        $this->assertNull($original->fresh()->medication_round_id);
        $this->assertSame($round->id, (int) $approvedWinner->fresh()->medication_round_id);
        $this->assertSame('rejected', $approvedWinner->fresh()->correction_status);
        $this->assertRoundCounts($round, administered: 0, refused: 0, withheld: 1);
    }

    public function test_correction_approval_fails_closed_and_rolls_back_for_a_cross_round_cluster(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $medication = $this->medication($client);
        $reporter = User::factory()->create();
        $submitter = $this->userWithPermissions(['medications.administer.correct'], $site);
        $approver = $this->userWithPermissions(['medications.administer.correct'], $site);
        $originalRound = $this->completedMedicationRound($client);
        $foreignRound = $this->completedMedicationRound($client);
        $original = $this->administration($client, $medication, $reporter, 'given');
        $original->forceFill(['medication_round_id' => $originalRound->id])->save();
        $pending = $this->correction($original, $submitter);
        $pending->forceFill([
            'medication_round_id' => $foreignRound->id,
            'status' => 'refused',
        ])->save();
        $originalRound->updateCounts();
        $foreignRound->updateCounts();

        $this->actingAs($approver)
            ->post(route('emar.corrections.approve', $pending))
            ->assertSessionHasErrors('medication_round_id');

        $freshPending = $pending->fresh();
        $this->assertSame('pending', $freshPending->correction_status);
        $this->assertNull($freshPending->correction_approved_by);
        $this->assertNull($freshPending->correction_approved_at);
        $this->assertSame($originalRound->id, (int) $original->fresh()->medication_round_id);
        $this->assertSame($foreignRound->id, (int) $freshPending->medication_round_id);
        $this->assertRoundCounts($originalRound, administered: 1, refused: 0, withheld: 0);
        $this->assertRoundCounts($foreignRound, administered: 0, refused: 0, withheld: 0);
    }

    public function test_a_later_controlled_correction_resolves_the_effective_winner_but_keeps_the_original_aggregate_key(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $medication = $this->medication($client);
        $medication->forceFill(['controlled_drug' => true])->save();
        $reporter = User::factory()->create();
        $original = $this->administration($client, $medication, $reporter, 'given');
        $approvedWinner = $this->correction($original, $reporter);
        $approvedWinner->forceFill([
            'status' => 'refused',
            'correction_status' => 'approved',
            'correction_approved_at' => now()->subMinute(),
        ])->save();
        $submitter = $this->userWithPermissions([
            'medications.administer.correct',
            'medications.controlled.record',
        ], $site);
        $approver = $this->userWithPermissions([
            'medications.administer.correct',
            'medications.controlled.record',
        ], $site);

        $this->actingAs($submitter)
            ->post(route('clients.mar.administrations.corrections.store', [$client, $original]), [
                'status' => 'withheld',
                'reason' => 'Dose remained not given.',
                'correction_reason' => 'The effective refusal reason was documented incorrectly.',
            ])
            ->assertRedirect();

        $laterCorrection = ClientMedicationAdministration::query()
            ->where('corrected_of_id', $original->id)
            ->where('correction_status', 'pending')
            ->sole();
        $this->assertSame($original->id, (int) $laterCorrection->corrected_of_id);
        $this->assertSame('withheld', $laterCorrection->status);

        $this->actingAs($approver)
            ->post(route('emar.corrections.approve', $laterCorrection))
            ->assertRedirect();

        $this->assertSame('approved', $laterCorrection->fresh()->correction_status);
        $this->assertSame('rejected', $approvedWinner->fresh()->correction_status);
        $this->assertSame([$laterCorrection->id], ClientMedicationAdministration::query()
            ->effectiveClinicalEvidence()
            ->where(fn ($query) => $query
                ->whereKey($original->id)
                ->orWhere('corrected_of_id', $original->id))
            ->pluck('id')
            ->all());

        $recorder = $this->userWithPermissions([
            'medications.administer.record',
            'medications.controlled.record',
        ], $site, $client);
        $this->actingAs($recorder)
            ->post(route('emar.refusal_followups.store'), $this->followupPayload($client, $original))
            ->assertRedirect();
        $this->assertSame(
            $original->id,
            (int) MedicationRefusalFollowup::query()->sole()->client_medication_administration_id,
        );
    }

    public function test_status_only_correction_inherits_nullable_evidence_from_the_approved_winner_until_explicitly_cleared(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $medication = $this->medication($client);
        $reporter = User::factory()->create();
        $original = $this->administration($client, $medication, $reporter, 'given');
        $original->forceFill([
            'reason' => 'Original reason.',
            'dose_given' => '1 tablet',
            'notes' => 'Original note.',
        ])->save();
        $approvedWinner = $this->correction($original, $reporter);
        $approvedWinner->forceFill([
            'status' => 'refused',
            'reason' => 'Effective refusal reason.',
            'dose_given' => 'No dose given',
            'notes' => 'Effective clinical note.',
            'correction_status' => 'approved',
            'correction_approved_at' => now()->subMinute(),
        ])->save();
        $submitter = $this->userWithPermissions(['medications.administer.correct'], $site);
        $approver = $this->userWithPermissions(['medications.administer.correct'], $site);

        $this->actingAs($submitter)
            ->post(route('clients.mar.administrations.corrections.store', [$client, $original]), [
                'status' => 'withheld',
                'administered_at' => now()->subMinute()->toDateTimeString(),
                'correction_reason' => 'Only the effective status and time needed correction.',
            ])
            ->assertRedirect();

        $inherited = ClientMedicationAdministration::query()
            ->where('corrected_of_id', $original->id)
            ->where('correction_status', 'pending')
            ->sole();
        $this->assertSame('Effective refusal reason.', $inherited->reason);
        $this->assertSame('No dose given', $inherited->dose_given);
        $this->assertSame('Effective clinical note.', $inherited->notes);

        $this->actingAs($approver)
            ->post(route('emar.corrections.approve', $inherited))
            ->assertRedirect();
        $this->assertSame('approved', $inherited->fresh()->correction_status);
        $this->assertSame('rejected', $approvedWinner->fresh()->correction_status);

        $this->actingAs($submitter)
            ->post(route('clients.mar.administrations.corrections.store', [$client, $original]), [
                'status' => 'withheld',
                'reason' => null,
                'dose_given' => null,
                'notes' => null,
                'correction_reason' => 'The inherited optional evidence was explicitly cleared.',
            ])
            ->assertRedirect();

        $explicitClear = ClientMedicationAdministration::query()
            ->where('corrected_of_id', $original->id)
            ->where('correction_status', 'pending')
            ->sole();
        $this->assertNull($explicitClear->reason);
        $this->assertNull($explicitClear->dose_given);
        $this->assertNull($explicitClear->notes);
    }

    public function test_effective_clinical_evidence_selects_one_deterministic_legacy_approved_correction(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $medication = $this->medication($client);
        $reporter = User::factory()->create();
        $original = $this->administration($client, $medication, $reporter, 'refused');
        $pending = $this->correction($original, $reporter);
        $rejected = $this->correction($original, $reporter);
        $rejected->forceFill(['correction_status' => 'rejected'])->save();
        $olderApproved = $this->correction($original, $reporter);
        $olderApproved->forceFill([
            'status' => 'given',
            'correction_status' => 'approved',
            'correction_approved_at' => now()->subMinute(),
        ])->save();
        $winner = $this->correction($original, $reporter);
        $winner->forceFill([
            'status' => 'withheld',
            'correction_status' => 'approved',
            'correction_approved_at' => now(),
        ])->save();

        $this->assertSame(5, ClientMedicationAdministration::query()
            ->where(fn ($query) => $query
                ->whereKey($original->id)
                ->orWhere('corrected_of_id', $original->id))
            ->count());
        $this->assertSame([$winner->id], ClientMedicationAdministration::query()
            ->effectiveClinicalEvidence()
            ->where(fn ($query) => $query
                ->whereKey($original->id)
                ->orWhere('corrected_of_id', $original->id))
            ->pluck('id')
            ->all());
    }

    public function test_correction_review_source_locks_the_round_before_the_original_aggregate_and_child(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/MedicationAdministrationCorrectionController.php'));
        $roundLock = strpos($source, '$round = $this->lockCanonicalAdministrationRound(');
        $originalLock = strpos($source, '$root = $rootQuery->lockForUpdate()->first();');
        $childLock = strpos($source, '$corrections = $correctionQuery');

        $this->assertIsInt($roundLock);
        $this->assertIsInt($originalLock);
        $this->assertIsInt($childLock);
        $this->assertLessThan($originalLock, $roundLock);
        $this->assertLessThan($childLock, $originalLock);
    }

    public function test_controlled_correction_and_refusal_transitions_require_exact_controlled_authority(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $medication = $this->medication($client);
        $medication->forceFill(['controlled_drug' => true])->save();
        $reporter = User::factory()->create();
        $original = $this->administration($client, $medication, $reporter, 'refused');
        $pending = $this->correction($original, $reporter);
        $followup = $this->followup($client, $original, $reporter);
        $ordinaryCorrector = $this->userWithPermissions(['medications.administer.correct'], $site);
        $ordinaryRecorder = $this->userWithPermissions(['medications.administer.record'], $site, $client);

        $this->actingAs($ordinaryCorrector)
            ->post(route('clients.mar.administrations.corrections.store', [$client, $original]), [
                'status' => 'refused',
                'correction_reason' => 'Must remain hidden without controlled authority.',
            ])
            ->assertNotFound();
        $this->actingAs($ordinaryCorrector)
            ->post(route('emar.corrections.approve', $pending))
            ->assertNotFound();
        $this->actingAs($ordinaryCorrector)
            ->post(route('emar.corrections.reject', $pending), ['reason' => 'Must remain hidden.'])
            ->assertNotFound();
        $this->actingAs($ordinaryRecorder)
            ->post(route('emar.refusal_followups.store'), $this->followupPayload($client, $original))
            ->assertNotFound();
        $this->actingAs($ordinaryCorrector)
            ->post(route('emar.refusal_followups.complete', $followup), ['outcome' => 'Must remain hidden.'])
            ->assertNotFound();
        $this->actingAs($ordinaryCorrector)
            ->post(route('emar.refusal_followups.notify_gp', $followup), ['gp_response' => 'Must remain hidden.'])
            ->assertNotFound();

        $this->assertSame('pending', $pending->fresh()->correction_status);
        $this->assertNull($followup->fresh()->follow_up_completed_at);
        $this->assertNull($followup->fresh()->gp_notified_at);

        $controlledCorrector = $this->userWithPermissions([
            'medications.administer.correct',
            'medications.controlled.record',
        ], $site);
        $controlledRecorder = $this->userWithPermissions([
            'medications.administer.record',
            'medications.controlled.record',
        ], $site, $client);

        $this->actingAs($controlledCorrector)
            ->post(route('emar.corrections.approve', $pending))
            ->assertRedirect();
        $this->assertSame('approved', $pending->fresh()->correction_status);
        $this->actingAs($controlledRecorder)
            ->post(route('emar.refusal_followups.store'), $this->followupPayload($client, $pending))
            ->assertRedirect();
        $effectiveFollowup = MedicationRefusalFollowup::query()
            ->where('client_medication_administration_id', $original->id)
            ->where('id', '!=', $followup->id)
            ->sole();
        $this->assertDatabaseMissing('medication_refusal_followups', [
            'client_medication_administration_id' => $pending->id,
        ]);
        $this->actingAs($controlledCorrector)
            ->post(route('emar.refusal_followups.complete', $effectiveFollowup), ['outcome' => 'Controlled follow-up completed.'])
            ->assertRedirect();
        $this->actingAs($controlledCorrector)
            ->post(route('emar.refusal_followups.notify_gp', $effectiveFollowup), ['gp_response' => 'Controlled GP response.'])
            ->assertRedirect();

        $this->assertSame('Controlled follow-up completed.', $effectiveFollowup->fresh()->follow_up_outcome);
        $this->assertSame('Controlled GP response.', $effectiveFollowup->fresh()->gp_response);
    }

    public function test_controlled_corrections_cannot_cross_the_stock_affecting_given_boundary(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $medication = $this->medication($client);
        $medication->forceFill(['controlled_drug' => true])->save();
        $reporter = User::factory()->create();
        $original = $this->administration($client, $medication, $reporter, 'given');
        $corrector = $this->userWithPermissions([
            'medications.administer.correct',
            'medications.controlled.record',
        ], $site);

        $this->actingAs($corrector)
            ->post(route('clients.mar.administrations.corrections.store', [$client, $original]), [
                'status' => 'given',
                'dose_given' => '2 tablets',
                'correction_reason' => 'Attempted controlled dose drift.',
            ])
            ->assertSessionHasErrors('dose_given');
        $this->actingAs($corrector)
            ->post(route('clients.mar.administrations.corrections.store', [$client, $original]), [
                'status' => 'given',
                'administered_at' => $original->administered_at->copy()->addMinute()->toDateTimeString(),
                'correction_reason' => 'Attempted controlled administration-time drift.',
            ])
            ->assertSessionHasErrors('administered_at');

        $this->actingAs($corrector)
            ->post(route('clients.mar.administrations.corrections.store', [$client, $original]), [
                'status' => 'refused',
                'reason' => 'Client declined.',
                'correction_reason' => 'Attempted stock-affecting correction.',
            ])
            ->assertSessionHasErrors('status');
        $this->assertDatabaseMissing('client_medication_administrations', [
            'corrected_of_id' => $original->id,
            'is_correction' => true,
        ]);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);

        $legacyUnsafe = $this->correction($original, $reporter);
        $legacyUnsafe->forceFill(['status' => 'withheld'])->save();
        $this->actingAs($corrector)
            ->post(route('emar.corrections.approve', $legacyUnsafe))
            ->assertSessionHasErrors('status');
        $this->assertSame('pending', $legacyUnsafe->fresh()->correction_status);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
    }

    public function test_non_given_corrections_clear_route_evidence_and_cannot_create_a_given_administration(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $performer = User::factory()->create();
        $corrector = $this->userWithPermissions(['medications.administer.correct'], $site);
        $cases = [
            [
                'form' => 'insulin',
                'route' => 'subcutaneous',
                'evidence' => [
                    'blood_glucose_level' => 7.4,
                    'insulin_units_given' => 6,
                    'injection_site' => 'left abdomen',
                ],
            ],
            [
                'form' => 'inhaler',
                'route' => 'inhaled',
                'evidence' => [
                    'inhaler_technique_observed' => true,
                    'spacer_used' => true,
                    'peak_flow_before' => 280,
                    'peak_flow_after' => 310,
                ],
            ],
            [
                'form' => 'cream',
                'route' => 'topical',
                'evidence' => [
                    'topical_area' => 'left forearm',
                    'topical_skin_condition' => 'intact',
                ],
            ],
        ];

        foreach ($cases as $case) {
            $medication = $this->medication($client);
            $medication->forceFill([
                'form' => $case['form'],
                'route' => $case['route'],
            ])->saveQuietly();
            $given = $this->administration($client, $medication, $performer, 'given');
            $given->forceFill($case['evidence'])->save();

            $this->actingAs($corrector)
                ->post(route('clients.mar.administrations.corrections.store', [$client, $given]), [
                    'status' => 'refused',
                    'reason' => 'The dose was not administered.',
                    'correction_reason' => 'Correct the outcome and remove administration-only evidence.',
                ])
                ->assertRedirect();
            $pending = ClientMedicationAdministration::query()
                ->where('corrected_of_id', $given->id)
                ->sole();
            foreach (ClientMedicationAdministration::ADMINISTRATION_ONLY_EVIDENCE_FIELDS as $field) {
                $this->assertNull($pending->{$field}, "{$field} remained on a non-given correction.");
            }

            $notGiven = $this->administration($client, $medication, $performer, 'refused');
            $this->actingAs($corrector)
                ->post(route('clients.mar.administrations.corrections.store', [$client, $notGiven]), [
                    'status' => 'given',
                    'correction_reason' => 'Attempted to create a given event without route evidence.',
                ])
                ->assertSessionHasErrors('status');
            $this->assertDatabaseMissing('client_medication_administrations', [
                'corrected_of_id' => $notGiven->id,
                'is_correction' => true,
            ]);
        }
    }

    public function test_only_approved_non_refusal_correction_cancels_open_refusal_followup_and_resolves_escalation(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $medication = $this->medication($client);
        $reporter = User::factory()->create();
        $original = $this->administration($client, $medication, $reporter, 'refused');
        $followup = $this->followup($client, $original, $reporter);
        $followup->forceFill([
            'gp_notification_required' => true,
            'follow_up_due_at' => now()->addDay(),
            'escalated_to_manager' => true,
            'escalated_at' => now(),
        ])->save();
        $corrector = $this->userWithPermissions(['medications.administer.correct'], $site);
        $integration = Mockery::mock(MedicationIncidentIntegrationService::class);
        $integration->shouldReceive('resolveUnsafeCorrection')->twice();
        $integration->shouldReceive('resolveRefusalEscalation')
            ->once()
            ->withArgs(fn (
                MedicationRefusalFollowup $resolvedFollowup,
                string $reason,
                ?int $resolvedBy,
            ): bool => $resolvedFollowup->is($followup)
                && str_contains($reason, 'cancelled')
                && $resolvedBy === $corrector->id);
        $this->app->instance(MedicationIncidentIntegrationService::class, $integration);

        $rejected = $this->correction($original, $reporter);
        $rejected->forceFill(['status' => 'given'])->save();
        $this->assertNull($followup->fresh()->follow_up_completed_at);
        $this->actingAs($corrector)
            ->post(route('emar.corrections.reject', $rejected), ['reason' => 'Correction not accepted.'])
            ->assertRedirect();
        $this->assertNull($followup->fresh()->follow_up_completed_at);

        $approved = $this->correction($original, $reporter);
        $approved->forceFill(['status' => 'given'])->save();
        $this->assertNull($followup->fresh()->follow_up_completed_at);
        $this->actingAs($corrector)
            ->post(route('emar.corrections.approve', $approved))
            ->assertRedirect();

        $terminal = $followup->fresh();
        $this->assertNotNull($terminal->follow_up_completed_at);
        $this->assertSame($corrector->id, $terminal->follow_up_completed_by);
        $this->assertStringContainsString('Cancelled automatically', (string) $terminal->follow_up_outcome);
        $this->assertStringContainsString('given', (string) $terminal->follow_up_outcome);
        $this->assertFalse(MedicationRefusalFollowup::query()->requiresGpNotification()->whereKey($followup->id)->exists());

        $this->actingAs($corrector)
            ->post(route('emar.refusal_followups.complete', $followup), ['outcome' => 'Must stay terminal.'])
            ->assertNotFound();
        $this->assertSame($terminal->follow_up_outcome, $followup->fresh()->follow_up_outcome);
    }

    public function test_correction_notifications_are_limited_to_exact_local_controlled_reviewers(): void
    {
        Notification::fake();
        $site = Site::factory()->create(['is_active' => true]);
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $medication = $this->medication($client);
        $medication->forceFill(['controlled_drug' => true])->save();
        $reporter = User::factory()->create();
        $original = $this->administration($client, $medication, $reporter, 'refused');
        $submitter = $this->userWithPermissions([
            'medications.administer.correct',
            'medications.controlled.record',
        ], $site);
        $eligible = $this->userWithPermissions([
            'medications.administer.correct',
            'medications.controlled.record',
            'medications.controlled.view',
        ], $site);
        $missingControlledView = $this->userWithPermissions([
            'medications.administer.correct',
            'medications.controlled.record',
        ], $site);
        $missingControlledRecord = $this->userWithPermissions([
            'medications.administer.correct',
            'medications.controlled.view',
        ], $site);
        $foreignEligible = $this->userWithPermissions([
            'medications.administer.correct',
            'medications.controlled.record',
            'medications.controlled.view',
        ], $foreignSite);

        $this->actingAs($submitter)
            ->post(route('clients.mar.administrations.corrections.store', [$client, $original]), [
                'status' => 'refused',
                'notes' => 'Corrected clinical note.',
                'correction_reason' => 'The original note was incomplete.',
            ])
            ->assertRedirect();

        Notification::assertSentTo($eligible, AppEventNotification::class);
        Notification::assertNotSentTo($submitter, AppEventNotification::class);
        Notification::assertNotSentTo($missingControlledView, AppEventNotification::class);
        Notification::assertNotSentTo($missingControlledRecord, AppEventNotification::class);
        Notification::assertNotSentTo($foreignEligible, AppEventNotification::class);
    }

    public function test_error_register_conceals_foreign_site_rows_stats_and_pickers(): void
    {
        $localSite = Site::factory()->create(['is_active' => true]);
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $localClient = Client::factory()->create(['site_id' => $localSite->id]);
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);
        $reporter = User::factory()->create();
        $localError = $this->error($localClient, $reporter, 'reported');
        $this->error($foreignClient, $reporter, 'reported');
        $reader = $this->userWithPermissions(['medications.view'], $localSite);
        $foreignStaff = $this->userWithPermissions(['medications.view'], $foreignSite);

        $response = $this->actingAs($reader)
            ->get(route('emar.errors'))
            ->assertOk();

        $this->assertSame(
            [$localError->id],
            collect($response->inertiaProps('errors'))->pluck('id')->all(),
        );
        $this->assertSame(1, $response->inertiaProps('stats.total_open'));
        $this->assertSame([$localClient->id], collect($response->inertiaProps('clients'))->pluck('id')->all());
        $this->assertContains($reader->id, collect($response->inertiaProps('staff'))->pluck('id')->all());
        $this->assertNotContains($foreignStaff->id, collect($response->inertiaProps('staff'))->pluck('id')->all());
        $this->assertSame([$localSite->id], collect($response->inertiaProps('sites'))->pluck('id')->all());

        $this->actingAs($reader)
            ->get(route('emar.errors', ['site_id' => $foreignSite->id]))
            ->assertNotFound();
    }

    public function test_error_lifecycle_uses_only_monotonic_dedicated_transitions(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id]);
        $reporter = User::factory()->create();
        $error = $this->error($client, $reporter, 'reported');
        $corrector = $this->userWithPermissions(['medications.administer.correct'], $site);

        $this->actingAs($corrector)
            ->put(route('emar.errors.update', $error), [
                'description' => 'Corrected description without a lifecycle bypass.',
                'status' => 'closed',
            ])
            ->assertRedirect();
        $this->assertSame('reported', $error->fresh()->status);

        $this->actingAs($corrector)
            ->post(route('emar.errors.review', $error), [
                'review_notes' => 'Clinical review started.',
                'status' => 'closed',
            ])
            ->assertRedirect();
        $this->assertSame('investigating', $error->fresh()->status);

        $this->actingAs($corrector)
            ->post(route('emar.errors.resolve', $error), [
                'outcome' => 'The chart was corrected and the client remained well.',
                'preventive_actions' => 'A second-person chart review was introduced.',
            ])
            ->assertRedirect();
        $this->assertSame('resolved', $error->fresh()->status);

        $this->actingAs($corrector)
            ->put(route('emar.errors.update', $error), ['description' => 'Must remain immutable.'])
            ->assertStatus(409);
        $this->actingAs($corrector)
            ->post(route('emar.errors.review', $error), ['review_notes' => 'Must not reopen.'])
            ->assertStatus(409);
        $this->actingAs($corrector)
            ->post(route('emar.errors.resolve', $error), [
                'outcome' => 'Must not resolve twice.',
                'preventive_actions' => 'Must not replace prior evidence.',
            ])
            ->assertStatus(409);

        $this->actingAs($corrector)
            ->post(route('emar.errors.close', $error), ['close_note' => 'Governed close-out complete.'])
            ->assertRedirect();
        $closed = $error->fresh();
        $this->actingAs($corrector)
            ->post(route('emar.errors.close', $error), ['close_note' => 'Must not overwrite close-out evidence.'])
            ->assertRedirect();
        $replayedClose = $error->fresh();
        $this->assertSame('closed', $replayedClose->status);
        $this->assertSame($closed->close_note, $replayedClose->close_note);
        $this->assertSame($closed->closed_by, $replayedClose->closed_by);
        $this->assertTrue($closed->closed_at->equalTo($replayedClose->closed_at));
    }

    private function completedMedicationRound(Client $client): MedicationRound
    {
        return MedicationRound::query()->create([
            'service_context_id' => $client->service_context_id,
            'site_id' => $client->site_id,
            'name' => 'Completed correction counter round',
            'scheduled_time' => '08:00',
            'round_date' => today(),
            'status' => 'completed',
            'completed_at' => now()->subMinute(),
            'total_medications' => 1,
        ]);
    }

    private function assertRoundCounts(
        MedicationRound $round,
        int $administered,
        int $refused,
        int $withheld,
        int $missed = 0,
    ): void {
        $freshRound = $round->fresh();

        $this->assertSame($administered, $freshRound->administered_count);
        $this->assertSame($refused, $freshRound->refused_count);
        $this->assertSame($withheld, $freshRound->withheld_count);
        $this->assertSame($missed, $freshRound->missed_count);
    }

    private function medication(Client $client): ClientMedication
    {
        return ClientMedication::factory()->create([
            'client_id' => $client->id,
            'active' => true,
            'state' => 'active',
        ]);
    }

    private function administration(
        Client $client,
        ClientMedication $medication,
        User $reporter,
        string $status,
    ): ClientMedicationAdministration {
        return ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'service_context_id' => $client->service_context_id,
            'administered_by' => $reporter->id,
            'scheduled_for' => now(),
            'administered_at' => now(),
            'status' => $status,
        ]);
    }

    private function correction(
        ClientMedicationAdministration $original,
        User $reporter,
    ): ClientMedicationAdministration {
        return ClientMedicationAdministration::query()->create([
            'client_id' => $original->client_id,
            'client_medication_id' => $original->client_medication_id,
            'service_context_id' => $original->service_context_id,
            'administered_by' => $reporter->id,
            'scheduled_for' => $original->scheduled_for,
            'administered_at' => $original->administered_at,
            'status' => $original->status,
            'is_correction' => true,
            'corrected_of_id' => $original->id,
            'correction_status' => 'pending',
        ]);
    }

    private function followup(
        Client $client,
        ClientMedicationAdministration $administration,
        User $reporter,
    ): MedicationRefusalFollowup {
        return MedicationRefusalFollowup::query()->create([
            'client_id' => $client->id,
            'client_medication_administration_id' => $administration->id,
            'reason_category' => 'personal_choice',
            'client_capacity_at_time' => 'has_capacity',
            'created_by' => $reporter->id,
        ]);
    }

    private function error(Client $client, User $reporter, string $status): MedicationError
    {
        return MedicationError::query()->create([
            'client_id' => $client->id,
            'error_type' => 'documentation',
            'severity' => 'near_miss',
            'description' => 'Medication documentation error.',
            'status' => $status,
            'reported_by' => $reporter->id,
            'reported_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function followupPayload(Client $client, ClientMedicationAdministration $administration): array
    {
        return [
            'client_id' => $client->id,
            'client_medication_administration_id' => $administration->id,
            'reason_category' => 'personal_choice',
            'client_capacity_at_time' => 'has_capacity',
        ];
    }

    /** @return array<string, mixed> */
    private function errorPayload(Client $client, ?ClientMedication $medication = null): array
    {
        return [
            'client_id' => $client->id,
            'client_medication_id' => $medication?->id,
            'error_type' => 'documentation',
            'severity' => 'near_miss',
            'description' => 'Medication documentation error.',
        ];
    }

    /** @param array<int, string> $permissions */
    private function userWithPermissions(
        array $permissions,
        Site $site,
        ?Client $assignedClient = null,
    ): User {
        $user = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => today()->subDay(),
        ]);
        $permissionIds = Permission::query()->whereIn('key', $permissions)->pluck('id');
        $this->assertCount(count($permissions), $permissionIds, 'Missing seeded permission in test setup.');
        $user->permissionOverrides()->sync(
            $permissionIds->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all(),
        );

        if ($assignedClient !== null) {
            Shift::factory()->create([
                'client_id' => $assignedClient->id,
                'site_id' => $site->id,
                'service_context_id' => $assignedClient->service_context_id,
                'user_id' => $user->id,
                'starts_at' => now()->subHour(),
                'ends_at' => now()->addHours(7),
                'actual_starts_at' => now()->subHour(),
                'actual_ends_at' => null,
                'started_by' => $user->id,
                'status' => 'in_progress',
            ]);
        }

        return $user->refresh();
    }

    /** @param array<int, string> $permissions */
    private function denyPermissions(User $user, array $permissions): void
    {
        $permissionIds = Permission::query()->whereIn('key', $permissions)->pluck('id');
        $this->assertCount(count($permissions), $permissionIds, 'Missing seeded permission in test setup.');
        $user->permissionOverrides()->syncWithoutDetaching(
            $permissionIds->mapWithKeys(fn ($id) => [$id => ['allowed' => false]])->all(),
        );
    }
}
