<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Governance\Models\ComplianceObligation;
use App\Domain\Governance\Models\ComplianceReminder;
use App\Models\HsCommittee;
use App\Models\HsCommitteeMeeting;
use App\Models\HsConsultation;
use App\Models\HsRepresentative;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\HealthSafety\CommitteeMeetingScheduled;
use App\Services\Sites\Calendar\Providers\WorkerParticipationObligationProvider;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * /health-safety/worker-participation — the HSWA 2015 participation register:
 * index payload (paginate/tabCounts/hero/detail/can), the reconciled
 * consultation lifecycle, the attendee pivot + meeting notifications, the
 * compliance obligations a representative implies (with reminders), the Site
 * Calendar obligation provider, document upload/download, CSV export, and
 * permission gating.
 */
class WorkerParticipationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    private ?User $actor = null;

    private function officer(): User
    {
        $user = User::factory()->create(['role' => 'health_safety_officer', 'approved_at' => now()]);
        if ($role = Role::where('name', 'health_safety_officer')->first()) {
            $user->roles()->attach($role);
        }

        return $user;
    }

    /** A persisted creator/initiator for seeded rows (FK-safe). */
    private function actorId(): int
    {
        return ($this->actor ??= User::factory()->create())->id;
    }

    private function rep(array $attrs = []): HsRepresentative
    {
        return HsRepresentative::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'site_id' => Site::factory()->create()->id,
            'election_method' => 'elected',
            'elected_at' => now()->subYear(),
            'status' => 'active',
            'training_days_completed' => 2,
            'created_by' => $this->actorId(),
        ], $attrs));
    }

    private function committee(array $attrs = []): HsCommittee
    {
        return HsCommittee::create(array_merge([
            'name' => 'Te Whare H&S Committee',
            'site_id' => Site::factory()->create()->id,
            'meeting_frequency' => 'monthly',
            'established_at' => now()->subYear(),
            'status' => 'active',
            'members' => [],
            'created_by' => $this->actorId(),
        ], $attrs));
    }

    private function consultation(array $attrs = []): HsConsultation
    {
        return HsConsultation::create(array_merge([
            'title' => 'New hoist procedure',
            'consultation_type' => 'procedure_change',
            'description' => 'Consulting kaimahi on the revised hoist transfer procedure.',
            'site_id' => Site::factory()->create()->id,
            'consultation_date' => now(),
            'status' => 'open',
            'initiated_by' => $this->actorId(),
            'created_by' => $this->actorId(),
        ], $attrs));
    }

    /* ---- index payload ---------------------------------------------- */

    public function test_index_renders_gold_standard_payload(): void
    {
        $this->rep();

        $this->actingAs($this->officer())
            ->get('/health-safety/worker-participation')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-safety/worker-participation/index')
                ->where('tab', 'representatives')
                ->has('filters.period')
                ->has('tabCounts.representatives')
                ->has('tabCounts.meetings')
                ->has('tabCounts.consultations')
                ->has('rows.data')
                ->has('hero.clusters.participation.active_reps')
                ->has('hero.clusters.consultation.open')
                ->has('hero.badges.reps_coverage_pct')
                ->has('hero.badges.training_below_minimum')
                ->where('can.manage', true)
                ->has('sites')
                ->has('staff')
                ->has('committees')
            );
    }

    public function test_index_paginates_active_tab_and_returns_detail(): void
    {
        $c = $this->committee();
        $meeting = HsCommitteeMeeting::create([
            'hs_committee_id' => $c->id, 'scheduled_at' => now()->addDays(5), 'status' => 'scheduled', 'created_by' => $this->actorId(),
        ]);

        $this->actingAs($this->officer())
            ->get('/health-safety/worker-participation?tab=meetings&meeting='.$meeting->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tab', 'meetings')
                ->has('rows.data', 1)
                ->where('detail.kind', 'meeting')
                ->where('detail.data.id', $meeting->id)
            );
    }

    /* ---- representatives + compliance obligations -------------------- */

    public function test_store_representative_creates_obligation_with_reminders(): void
    {
        $site = Site::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($this->officer())
            ->post('/health-safety/worker-participation/representatives', [
                'user_id' => $user->id,
                'site_id' => $site->id,
                'work_group' => 'Night shift',
                'election_method' => 'elected',
                'elected_at' => now()->subMonth()->toDateString(),
                'term_expires_at' => now()->addYears(2)->toDateString(),
                'training_days_completed' => 0,
            ])
            ->assertRedirect();

        $rep = HsRepresentative::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('Night shift', $rep->work_group);

        // Term re-election + training obligations, each with reminders scheduled.
        $term = ComplianceObligation::where('obligation_code', "HSR-TERM-{$rep->id}")->first();
        $this->assertNotNull($term);
        $this->assertSame('hswa', $term->framework);
        $this->assertTrue(ComplianceReminder::where('compliance_obligation_id', $term->id)->exists());
        $this->assertNotNull(ComplianceObligation::where('obligation_code', "HSR-TRAINING-{$rep->id}")->first());
    }

    public function test_representative_obligations_are_not_duplicated_on_re_save(): void
    {
        $rep = $this->rep(['term_expires_at' => now()->addYear(), 'training_days_completed' => 0]);

        $actor = $this->officer();
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($actor)->put("/health-safety/worker-participation/representatives/{$rep->id}", [
                'notes' => "touch {$i}",
            ])->assertRedirect();
        }

        $this->assertSame(1, ComplianceObligation::where('obligation_code', "HSR-TERM-{$rep->id}")->count());
    }

    public function test_stand_down_representative_via_status_update(): void
    {
        $rep = $this->rep();

        $this->actingAs($this->officer())
            ->put("/health-safety/worker-participation/representatives/{$rep->id}", ['status' => 'inactive'])
            ->assertRedirect();

        $this->assertSame('inactive', $rep->fresh()->status);
    }

    public function test_recording_initial_hsr_training_creates_a_tracked_staff_credential(): void
    {
        $user = User::factory()->create();
        $rep = $this->rep(['user_id' => $user->id, 'initial_training_completed_at' => null]);
        $type = 'HSR Initial Training (NZQA US 29315)';

        // No credential until initial training (NZQA US 29315) is recorded.
        $this->assertDatabaseMissing('staff_credentials', ['user_id' => $user->id, 'type' => $type]);

        $this->actingAs($this->officer())
            ->put("/health-safety/worker-participation/representatives/{$rep->id}", [
                'initial_training_completed_at' => now()->subMonth()->toDateString(),
            ])
            ->assertRedirect();

        // Cross-module: surfaced as a tracked HR credential on the rep's staff record.
        $this->assertDatabaseHas('staff_credentials', [
            'user_id' => $user->id,
            'type' => $type,
            'issuer' => 'NZQA',
            'reference' => 'US 29315',
        ]);

        // Idempotent — re-saving does not duplicate the credential.
        $this->actingAs($this->officer())
            ->put("/health-safety/worker-participation/representatives/{$rep->id}", [
                'initial_training_completed_at' => now()->subMonth()->toDateString(),
            ])
            ->assertRedirect();
        $this->assertSame(1, \App\Models\StaffCredential::where('user_id', $user->id)->where('type', $type)->count());
    }

    public function test_store_committee_flashes_created_committee_id_for_the_meeting_chain(): void
    {
        $site = Site::factory()->create();
        $member = User::factory()->create();

        // The schedule-meeting wizard's "create new committee" path chains the
        // meeting POST onto the freshly created committee using this flashed id
        // (shared via HandleInertiaRequests). Regression guard for the blocker.
        $this->actingAs($this->officer())
            ->post('/health-safety/worker-participation/committees', [
                'name' => 'New build committee',
                'site_id' => $site->id,
                'meeting_frequency' => 'monthly',
                'established_at' => now()->toDateString(),
                'members' => [$member->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('created_committee_id', HsCommittee::where('name', 'New build committee')->value('id'));
    }

    public function test_store_committee_schedules_its_first_meeting_atomically(): void
    {
        Notification::fake();
        $site = Site::factory()->create();
        $member = User::factory()->create();

        // The schedule-meeting wizard's "new committee" path posts the committee
        // AND its first meeting in one request, so the meeting can never be
        // dropped/orphaned by a fragile two-POST chain across a redirect.
        $this->actingAs($this->officer())
            ->post('/health-safety/worker-participation/committees', [
                'name' => 'Atomic Committee',
                'site_id' => $site->id,
                'meeting_frequency' => 'quarterly',
                'established_at' => now()->toDateString(),
                'members' => [$member->id],
                'schedule_meeting' => true,
                'scheduled_at' => now()->addWeek()->toDateTimeString(),
                'location' => 'Staff room',
                'attendees' => [$member->id],
            ])
            ->assertRedirect();

        $committee = HsCommittee::where('name', 'Atomic Committee')->firstOrFail();
        $meeting = HsCommitteeMeeting::where('hs_committee_id', $committee->id)->firstOrFail();
        $this->assertSame('scheduled', $meeting->status);
        $this->assertTrue($meeting->attendeeUsers()->where('users.id', $member->id)->exists());
        Notification::assertSentTo($member, CommitteeMeetingScheduled::class);
    }

    /* ---- meetings: pivot attendees + notifications ------------------- */

    public function test_schedule_meeting_syncs_pivot_and_notifies_attendees(): void
    {
        Notification::fake();
        $attendee = User::factory()->create();
        $c = $this->committee(['members' => [$attendee->id]]);

        $this->actingAs($this->officer())
            ->post("/health-safety/worker-participation/committees/{$c->id}/meetings", [
                'scheduled_at' => now()->addWeek()->toDateTimeString(),
                'location' => 'Staff room',
                'attendees' => [$attendee->id],
            ])
            ->assertRedirect();

        $meeting = HsCommitteeMeeting::where('hs_committee_id', $c->id)->firstOrFail();
        $this->assertTrue($meeting->attendeeUsers()->where('users.id', $attendee->id)->exists());
        Notification::assertSentTo($attendee, CommitteeMeetingScheduled::class);
    }

    public function test_complete_meeting_records_attendance_and_actions_due(): void
    {
        $c = $this->committee();
        $attendee = User::factory()->create();
        $meeting = HsCommitteeMeeting::create([
            'hs_committee_id' => $c->id, 'scheduled_at' => now()->subDay(), 'status' => 'scheduled', 'created_by' => $this->actorId(),
        ]);

        $this->actingAs($this->officer())
            ->put("/health-safety/worker-participation/meetings/{$meeting->id}/complete", [
                'minutes' => 'Discussed manual handling.',
                'action_items' => [
                    ['description' => 'Order new slide sheets', 'status' => 'open'],
                    ['description' => 'Brief team', 'status' => 'done'],
                ],
                'actual_attendee_ids' => [$attendee->id],
            ])
            ->assertRedirect();

        $meeting->refresh();
        $this->assertSame('completed', $meeting->status);
        $this->assertSame(1, $meeting->actions_due_count);
        $this->assertTrue($meeting->attendeeUsers()->wherePivot('attended', true)->where('users.id', $attendee->id)->exists());
    }

    public function test_cancel_meeting(): void
    {
        $c = $this->committee();
        $meeting = HsCommitteeMeeting::create([
            'hs_committee_id' => $c->id, 'scheduled_at' => now()->addDay(), 'status' => 'scheduled', 'created_by' => $this->actorId(),
        ]);

        $this->actingAs($this->officer())
            ->put("/health-safety/worker-participation/meetings/{$meeting->id}/cancel")
            ->assertRedirect();

        $this->assertSame('cancelled', $meeting->fresh()->status);
    }

    /* ---- consultations: lifecycle + documents ----------------------- */

    public function test_consultation_lifecycle_advances_through_canonical_states(): void
    {
        $consultation = $this->consultation();
        $officer = $this->officer();

        $this->actingAs($officer)->put("/health-safety/worker-participation/consultations/{$consultation->id}/status", [
            'status' => 'feedback_received', 'worker_feedback_summary' => 'Kaimahi want extra training.',
        ])->assertRedirect();
        $this->assertSame('feedback_received', $consultation->fresh()->status);

        $this->actingAs($officer)->put("/health-safety/worker-participation/consultations/{$consultation->id}/status", [
            'status' => 'actioned', 'outcome' => 'Training scheduled.', 'changes_made' => 'Added to induction.',
        ])->assertRedirect();
        $this->assertSame('actioned', $consultation->fresh()->status);

        $this->actingAs($officer)->put("/health-safety/worker-participation/consultations/{$consultation->id}/status", [
            'status' => 'closed',
        ])->assertRedirect();
        $this->assertSame('closed', $consultation->fresh()->status);
    }

    public function test_consultation_status_never_regresses(): void
    {
        $consultation = $this->consultation(['status' => 'actioned', 'outcome' => 'Training scheduled.']);

        // Recording late feedback must not drag an actioned consultation back to
        // feedback_received — the stage is preserved, but the content still saves.
        $this->actingAs($this->officer())
            ->put("/health-safety/worker-participation/consultations/{$consultation->id}/status", [
                'status' => 'feedback_received',
                'worker_feedback_summary' => 'Late feedback captured.',
            ])
            ->assertRedirect();

        $consultation->refresh();
        $this->assertSame('actioned', $consultation->status);
        $this->assertSame('Late feedback captured.', $consultation->worker_feedback_summary);
    }

    public function test_store_consultation_with_supporting_document(): void
    {
        Storage::fake('private');
        $site = Site::factory()->create();

        $this->actingAs($this->officer())
            ->post('/health-safety/worker-participation/consultations', [
                'title' => 'PPE change',
                'consultation_type' => 'equipment_change',
                'description' => 'New glove supplier.',
                'site_id' => $site->id,
                'consultation_date' => now()->toDateString(),
                'document' => UploadedFile::fake()->create('brief.pdf', 200, 'application/pdf'),
            ])
            ->assertRedirect();

        $consultation = HsConsultation::where('title', 'PPE change')->firstOrFail();
        $this->assertNotNull($consultation->document_path);
        Storage::disk('private')->assertExists($consultation->document_path);
    }

    public function test_consultation_document_download_is_available_to_viewers(): void
    {
        Storage::fake('private');
        $consultation = $this->consultation();
        $consultation->update([
            'document_path' => UploadedFile::fake()->create('doc.pdf', 10)->store('health-safety/consultations/'.$consultation->id, 'private'),
            'document_name' => 'doc.pdf',
        ]);

        $this->actingAs($this->officer())
            ->get("/health-safety/worker-participation/consultations/{$consultation->id}/documents/document")
            ->assertOk();
    }

    /* ---- export ----------------------------------------------------- */

    public function test_export_streams_csv(): void
    {
        $this->rep();

        $response = $this->actingAs($this->officer())->get('/health-safety/worker-participation/export');
        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
    }

    /* ---- calendar obligation provider ------------------------------- */

    public function test_provider_surfaces_meeting_consultation_and_rep_term(): void
    {
        $site = Site::factory()->create();
        $c = $this->committee(['site_id' => $site->id]);
        HsCommitteeMeeting::create(['hs_committee_id' => $c->id, 'scheduled_at' => now()->addDays(10), 'status' => 'scheduled', 'created_by' => $this->actorId()]);
        $this->consultation(['site_id' => $site->id, 'consultation_date' => now()->addDays(3)]);
        $this->rep(['site_id' => $site->id, 'term_expires_at' => now()->addDays(20)]);

        $items = (new WorkerParticipationObligationProvider())
            ->obligations([$site->id], Carbon::now()->subMonth(), Carbon::now()->addMonths(2));

        $sources = collect($items);
        $this->assertTrue($sources->contains(fn ($i) => str_starts_with($i->id, 'participation-meeting-')));
        $this->assertTrue($sources->contains(fn ($i) => str_starts_with($i->id, 'participation-consultation-')));
        $this->assertTrue($sources->contains(fn ($i) => str_starts_with($i->id, 'participation-rep-term-')));
        $this->assertTrue($sources->every(fn ($i) => $i->source === 'participation'));
    }

    /* ---- permission gating ------------------------------------------ */

    public function test_index_requires_hazards_view_permission(): void
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        if ($role = Role::where('name', 'support_worker')->first()) {
            $user->roles()->attach($role);
        }

        $this->actingAs($user)->get('/health-safety/worker-participation')->assertForbidden();
    }

    public function test_writes_require_hazards_manage_permission(): void
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        if ($role = Role::where('name', 'support_worker')->first()) {
            $user->roles()->attach($role);
        }

        $this->actingAs($user)
            ->post('/health-safety/worker-participation/representatives', [
                'user_id' => $user->id,
                'site_id' => Site::factory()->create()->id,
                'election_method' => 'elected',
                'elected_at' => now()->toDateString(),
            ])
            ->assertForbidden();
    }
}
