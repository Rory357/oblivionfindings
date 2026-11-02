<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\HsEvent;
use App\Models\HsEventOwnerChange;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\AppEventNotification;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use LogicException;
use Tests\TestCase;

/**
 * Q2 (Stephan, 31 Oct; G3 boards approved 1 Nov 2026): the people who manage health and safety at a safety review's
 * Site can change its owner, always with a reason. Each change is kept, audited, and the old and new owners are told.
 */
class HsEventOwnerChangeTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
    }

    public function test_an_owner_change_is_kept_audited_and_both_owners_are_told(): void
    {
        Notification::fake();
        $actor = $this->roleUser('health_safety_officer');
        $jordan = $this->roleUser('health_safety_officer');
        $dana = $this->roleUser('health_safety_officer');
        $event = $this->event($jordan);

        $this->actingAs($actor)
            ->post("/health-safety/events/{$event->id}/owner", ['owner_user_id' => $dana->id, 'reason' => 'Jordan is on leave until 16 Nov.'])
            ->assertSessionHas('success');

        $this->assertSame($dana->id, $event->fresh()->owner_user_id);
        $change = HsEventOwnerChange::query()->where('hs_event_id', $event->id)->sole();
        $this->assertSame([$jordan->id, $dana->id, $actor->id, 'Jordan is on leave until 16 Nov.'],
            [$change->previous_owner_user_id, $change->owner_user_id, $change->actor_user_id, $change->reason]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'healthSafety.event.ownerChanged', 'auditable_id' => $event->id, 'user_id' => $actor->id]);
        Notification::assertSentTo($dana, AppEventNotification::class);
        Notification::assertSentTo($jordan, AppEventNotification::class);
        Notification::assertNotSentTo($actor, AppEventNotification::class);

        $this->expectException(LogicException::class);
        $change->update(['reason' => 'Rewritten later.']);
    }

    public function test_a_reason_is_always_needed(): void
    {
        $actor = $this->roleUser('health_safety_officer');
        $event = $this->event($this->roleUser('health_safety_officer'));
        $next = $this->roleUser('health_safety_officer');

        foreach (['', 'Leave.'] as $reason) {
            $this->actingAs($actor)
                ->post("/health-safety/events/{$event->id}/owner", ['owner_user_id' => $next->id, 'reason' => $reason])
                ->assertSessionHasErrors('reason');
        }
        $this->assertSame(0, HsEventOwnerChange::query()->count());
    }

    public function test_only_people_who_manage_health_and_safety_here_can_become_owner(): void
    {
        $actor = $this->roleUser('health_safety_officer');
        $owner = $this->roleUser('health_safety_officer');
        $event = $this->event($owner);
        $worker = $this->roleUser('support_worker');

        $this->actingAs($actor)
            ->post("/health-safety/events/{$event->id}/owner", ['owner_user_id' => $worker->id, 'reason' => 'Trying to hand it to a support worker.'])
            ->assertSessionHasErrors('owner_user_id');
        $this->assertSame($owner->id, $event->fresh()->owner_user_id);
    }

    public function test_closed_reviews_and_reviews_still_waiting_for_their_handover_are_refused(): void
    {
        $actor = $this->roleUser('health_safety_officer');
        $next = $this->roleUser('health_safety_officer');
        $closed = $this->event($this->roleUser('health_safety_officer'), ['status' => HsEvent::STATUS_CLOSED, 'closed_at' => now()]);
        $awaiting = $this->event(null, ['handover_status' => HsEvent::HANDOVER_AWAITING_ACCEPTANCE]);
        $notReady = $this->event(null, ['handover_status' => HsEvent::HANDOVER_NOT_READY]);

        foreach ([$closed, $awaiting, $notReady] as $event) {
            $this->actingAs($actor)
                ->post("/health-safety/events/{$event->id}/owner", ['owner_user_id' => $next->id, 'reason' => 'Handing this over to someone else.'])
                ->assertSessionHasErrors('owner_user_id');
        }
        $this->assertSame(0, HsEventOwnerChange::query()->count());
    }

    /** Decision 3: a review needs an owner to close, so one that never had an owner (handover not required) can be given one. */
    public function test_a_review_with_no_owner_gets_its_first_owner_without_needing_a_reason(): void
    {
        Notification::fake();
        $actor = $this->roleUser('health_safety_officer');
        $dana = $this->roleUser('health_safety_officer');
        $event = $this->event(null);

        $this->actingAs($actor)
            ->post("/health-safety/events/{$event->id}/owner", ['owner_user_id' => $dana->id])
            ->assertSessionHas('success', 'Owner chosen.');

        $this->assertSame($dana->id, $event->fresh()->owner_user_id);
        $change = HsEventOwnerChange::query()->where('hs_event_id', $event->id)->sole();
        $this->assertSame([null, null, $dana->id, null], [$change->previous_owner_user_id, $change->previous_owner_name, $change->owner_user_id, $change->reason]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'healthSafety.event.ownerChanged', 'auditable_id' => $event->id, 'user_id' => $actor->id]);
        Notification::assertSentTo($dana, AppEventNotification::class);

        $owned = collect($this->actingAs($actor)->getJson("/health-safety/events/{$event->id}/activity")->assertOk()->json('entries'))->firstWhere('kind', 'owner');
        $this->assertSame('chose '.$dana->name.' as the owner', $owned['what']);

        // The same people rule applies to the first choice.
        $other = $this->event(null);
        $this->actingAs($actor)
            ->post("/health-safety/events/{$other->id}/owner", ['owner_user_id' => $this->roleUser('support_worker')->id])
            ->assertSessionHasErrors('owner_user_id');
        $this->assertNull($other->fresh()->owner_user_id);
        // And a support worker can't choose one at all.
        $this->actingAs($this->roleUser('support_worker'))
            ->post("/health-safety/events/{$other->id}/owner", ['owner_user_id' => $dana->id])
            ->assertForbidden();
        $this->assertNull($other->fresh()->owner_user_id);
    }

    private function event(?User $owner, array $attributes = []): HsEvent
    {
        return HsEvent::factory()->create([
            'site_id' => $this->site->id,
            'owner_user_id' => $owner?->id,
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
            ...$attributes,
        ]);
    }

    private function roleUser(string $roleName): User
    {
        $user = User::factory()->create(['role' => $roleName, 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', $roleName)->firstOrFail()->id]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'position_role' => $roleName, 'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true,
        ]);

        return $user->fresh();
    }
}
