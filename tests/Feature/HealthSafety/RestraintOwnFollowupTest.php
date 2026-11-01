<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\RestraintEvent;
use App\Models\RestraintFollowup;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\RestraintOwnFollowupService;
use App\Services\HealthSafety\RestraintRegisterService;
use App\Services\HealthSafety\RestraintSourceProjectionService;
use Illuminate\Support\Str;

/**
 * Decision 4 (Stephan, 1 Nov 2026; board G4-5): a support worker can own a restraint follow-up such as "Tell Aroha's
 * family/whānau what happened". restraints.followups.own (grant migration 2026_11_01_233000, support_worker only) shows
 * them ONLY the follow-ups they own and lets them record the outcome — never the restraint record, its plan or its other
 * follow-ups, and never at a Site they don't work at.
 */
class RestraintOwnFollowupTest extends RestraintEventCommandTestCase
{
    private const KEY = 'restraints.followups.own';

    private function supportWorker(?Site $site = null, array $extra = []): User
    {
        // clients.viewAny stands in for the support worker's own person access at their Site.
        return $this->staff([self::KEY, 'restraints.report', 'clients.viewAny', ...$extra], $site);
    }

    private function followup(RestraintEvent $event, User $owner, string $notes): RestraintFollowup
    {
        $result = $this->service()->addFollowup($event, $this->actor, $this->command($event, [
            'notes' => $notes, 'assigned_to_user_id' => $owner->id, 'due_at' => now()->addDay()->toIso8601String(),
        ]));

        return RestraintFollowup::findOrFail($result['followup_id']);
    }

    private function outcome(int $version, array $overrides = []): array
    {
        return ['completed_at' => now()->subMinutes(5)->toIso8601String(), 'outcome' => 'contacted', 'notes' => 'Rang her daughter at 5 pm',
            'idempotency_key' => (string) Str::uuid(), 'expected_version' => $version, ...$overrides];
    }

    private function own(): RestraintOwnFollowupService
    {
        return app(RestraintOwnFollowupService::class);
    }

    public function test_a_support_worker_sees_only_their_own_follow_up_and_records_its_outcome(): void
    {
        $worker = $this->supportWorker();
        $reviewer = $this->staff(['restraints.view', 'restraints.review', 'clients.viewAny']);
        $event = $this->event();
        $mine = $this->followup($event, $worker, 'Tell Aroha’s family/whānau what happened');
        $this->followup($event, $reviewer, 'Talk to the behaviour support practitioner about the walker');

        $list = $this->own()->forViewer($worker)['followups'];

        $this->assertCount(1, $list);
        $this->assertSame($mine->id, $list[0]['id']);
        $this->assertSame('Tell Aroha’s family/whānau what happened', $list[0]['title']);
        // Only the follow-up's own facts: never the restraint, its person, its plan or the other follow-up.
        $this->assertSame(['id', 'title', 'due_at', 'completed_at', 'outcome', 'outcome_notes', 'expected_version', 'can_complete'], array_keys($list[0]));
        $json = json_encode($list);
        foreach (['Sensitive factual source', 'Factual trigger', 'Actual support attempted', 'behaviour support practitioner', $this->client->first_name] as $hidden) {
            $this->assertStringNotContainsString($hidden, $json);
        }

        $this->own()->complete($worker, $mine->id, $this->outcome($list[0]['expected_version']));

        $mine->refresh();
        $this->assertNotNull($mine->completed_at);
        $this->assertSame($worker->id, (int) $mine->completed_by_user_id);
        $this->assertSame('contacted', $mine->completion_outcome['outcome']);
        $done = $this->own()->forViewer($worker)['followups'][0];
        $this->assertSame('contacted', $done['outcome']);
        $this->assertFalse($done['can_complete']);
    }

    public function test_the_restraint_record_and_other_follow_ups_stay_closed_to_them(): void
    {
        $worker = $this->supportWorker();
        $reviewer = $this->staff(['restraints.view', 'restraints.review', 'clients.viewAny']);
        $event = $this->event();
        $this->followup($event, $worker, 'Tell the family/whānau');
        $theirs = $this->followup($event, $reviewer, 'Talk to the practitioner');
        $version = (int) $event->fresh()->lock_version;

        // The restraint record (and its review workspace) refuse them.
        $this->denied(fn () => app(RestraintSourceProjectionService::class)->event($event, $worker));
        $this->denied(fn () => app(RestraintSourceProjectionService::class)->event($event, $worker, true));
        // Someone else's follow-up: not found on their own list's path, refused on the command itself.
        $this->denied(fn () => $this->own()->complete($worker, $theirs->id, $this->outcome($version)));
        $this->denied(fn () => $this->service()->completeFollowup($event, $worker, [...$this->outcome($version), 'followup_id' => $theirs->id]));
        // A follow-up that doesn't exist reads the same as someone else's.
        $this->denied(fn () => $this->own()->complete($worker, $theirs->id + 1000, $this->outcome($version)));
        // No restraint commands beyond their own outcome.
        $this->denied(fn () => $this->service()->addFollowup($event, $worker, $this->command($event, ['notes' => 'Mine now'])));
        $this->denied(fn () => $this->service()->cancelFollowup($event, $worker, $this->command($event, ['followup_id' => $theirs->id, 'reason' => 'Not needed'])));
        $this->assertNull($theirs->fresh()->completed_at);
    }

    public function test_a_follow_up_at_a_site_they_no_longer_work_at_drops_out_and_cant_be_finished(): void
    {
        $worker = $this->supportWorker();
        $event = $this->event();
        $mine = $this->followup($event, $worker, 'Tell the family/whānau');
        $version = $this->own()->forViewer($worker)['followups'][0]['expected_version'];

        $elsewhere = Site::factory()->create(['is_active' => true]);
        HrEmployeeProfile::where('user_id', $worker->id)->update(['primary_site_id' => $elsewhere->id]);

        $this->assertSame([], $this->own()->forViewer($worker)['followups']);
        $this->denied(fn () => $this->own()->complete($worker, $mine->id, $this->outcome($version)));
        $this->assertNull($mine->fresh()->completed_at);
    }

    public function test_they_cant_be_chosen_as_owner_where_they_lack_site_access(): void
    {
        $here = $this->supportWorker();
        $elsewhere = $this->supportWorker(Site::factory()->create(['is_active' => true]));
        $event = $this->event();

        $owners = array_column(app(RestraintRegisterService::class)->pickers($this->actor, [], $event)['followupOwners'], 'id');
        $this->assertContains($here->id, $owners);
        $this->assertNotContains($elsewhere->id, $owners);

        $this->denied(fn () => $this->service()->addFollowup($event, $this->actor, $this->command($event, [
            'notes' => 'Tell the family/whānau', 'assigned_to_user_id' => $elsewhere->id,
        ])));
        $this->assertSame(0, RestraintFollowup::where('assigned_to_user_id', $elsewhere->id)->count());
    }

    public function test_without_the_key_a_support_worker_is_never_an_owner_and_has_no_list(): void
    {
        $plain = $this->staff(['restraints.report', 'clients.viewAny']);
        $event = $this->event();

        $owners = array_column(app(RestraintRegisterService::class)->pickers($this->actor, [], $event)['followupOwners'], 'id');
        $this->assertNotContains($plain->id, $owners);
        $this->denied(fn () => $this->service()->addFollowup($event, $this->actor, $this->command($event, [
            'notes' => 'Tell the family/whānau', 'assigned_to_user_id' => $plain->id,
        ])));
        $this->denied(fn () => $this->own()->forViewer($plain));
    }
}
