<?php

use App\Domain\It\Presenters\ItTicketCommentDeliveryPresenter;
use App\Domain\It\Services\ItTicketInteractionService;
use App\Jobs\DispatchItTicketNotifications;
use App\Models\ItEmailDelivery;
use App\Models\ItTicket;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->agent = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    foreach ([$this->worker, $this->agent] as $actor) {
        $actor->roles()->syncWithoutDetaching(Role::query()->where('name', $actor->role)->pluck('id'));
        ensureCanonicalHrStaffProfile($actor, $this->site);
    }
    $this->ticket = ItTicket::factory()->create(['site_id' => $this->site->id,
        'requester_user_id' => $this->worker->id, 'assigned_to_user_id' => $this->agent->id, 'status' => 'open']);
    Notification::fake();
    Bus::fake([DispatchItTicketNotifications::class]);
});

function deliveryEvidenceReply(ItTicket $ticket, User $actor, bool $internal = false)
{
    return app(ItTicketInteractionService::class)->addCommentCommand($ticket, $actor, [
        'actor_user_id' => $actor->id, 'request_uuid' => (string) Str::uuid(),
        'expected_version' => $ticket->fresh()->lock_version, 'is_internal' => $internal,
        'body' => $internal ? 'Synthetic private note' : 'Synthetic public update',
    ])->comment;
}

test('saved reply details refresh current delivery evidence without exposing private transport fields', function () {
    $comment = deliveryEvidenceReply($this->ticket, $this->agent);
    $delivery = ItEmailDelivery::query()->where('it_ticket_comment_id', $comment->id)->sole();
    $this->actingAs($this->agent)->getJson('/it/tickets/'.$this->ticket->id)->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('comments.0.delivery.tracking', 'recorded')
        ->assertJsonPath('comments.0.delivery.attempt_statuses.queued', 1)
        ->assertJsonPath('comments.0.delivery.review_url', '/it/setup?tab=operations&delivery_comment_id='.$comment->id);
    $delivery->update(['status' => 'failed', 'last_error' => 'PRIVATE provider diagnostic 123',
        'recipient_email' => 'synthetic-recipient@demo.test']);
    $this->getJson('/it/tickets/'.$this->ticket->id)->assertOk()
        ->assertJsonPath('comments.0.delivery.attempt_statuses.failed', 1)
        ->assertJsonMissingPath('comments.0.delivery.attempt_statuses.queued')
        ->assertDontSee('PRIVATE provider diagnostic')->assertDontSee('synthetic-recipient@demo.test');
    Notification::assertNothingSent();
});

test('only current leaf attempts count and their comment identity survives a moved canonical parent', function () {
    $comment = deliveryEvidenceReply($this->ticket, $this->agent);
    $original = ItEmailDelivery::query()->where('it_ticket_comment_id', $comment->id)->sole();
    $original->update(['status' => 'retried']);
    $retry = ItEmailDelivery::factory()->create(['it_ticket_id' => $this->ticket->id,
        'it_ticket_comment_id' => $comment->id, 'recipient_user_id' => $this->worker->id,
        'retry_of_delivery_id' => $original->id, 'status' => 'accepted']);
    // Model the already-completed merge projection: the comment identity stays
    // fixed while existing outbox attempts retain their historical ticket FK.
    $survivor = ItTicket::factory()->create(['site_id' => $this->site->id, 'requester_user_id' => $this->worker->id]);
    $comment->update(['ticket_id' => $survivor->id]);
    $this->actingAs($this->agent)->getJson('/it/tickets/'.$survivor->id)->assertOk()
        ->assertJsonPath('comments.0.delivery.attempt_statuses.accepted', 1)
        ->assertJsonMissingPath('comments.0.delivery.attempt_statuses.retried');
    $retry->update(['status' => 'delivered', 'delivered_at' => now()]);
    $this->getJson('/it/tickets/'.$survivor->id)->assertOk()
        ->assertJsonPath('comments.0.delivery.attempt_statuses.delivered', 1)
        ->assertJsonMissingPath('comments.0.delivery.attempt_statuses.accepted');
});

test('absent historical evidence differs from a receipted reply that requested no notification', function () {
    $old = $this->ticket->comments()->create(['author_user_id' => $this->worker->id,
        'is_internal' => false, 'body' => 'Historical reply without delivery evidence']);
    $this->ticket->update(['assigned_to_user_id' => null]);
    $current = deliveryEvidenceReply($this->ticket, $this->worker);
    $result = app(ItTicketCommentDeliveryPresenter::class)->presentMany($this->ticket, $this->worker, collect([$old, $current]));
    expect($result[$old->id]['tracking'])->toBe('unrecorded')
        ->and($result[$current->id]['tracking'])->toBe('recorded')
        ->and($result[$current->id]['requested'])->toBeFalse()
        ->and((array) $result[$current->id]['attempt_statuses'])->toBe([])
        ->and($result[$current->id]['review_url'])->toBeNull();
});

test('public participants do not receive other authors delivery evidence and internal notes never gain a mail projection', function () {
    deliveryEvidenceReply($this->ticket, $this->agent);
    deliveryEvidenceReply($this->ticket, $this->agent, true);
    $own = deliveryEvidenceReply($this->ticket, $this->worker);
    $this->actingAs($this->worker)->getJson('/it/tickets/'.$this->ticket->id)->assertOk()
        ->assertJsonCount(2, 'comments')->assertJsonPath('comments.0.delivery', null)
        ->assertJsonPath('comments.1.id', $own->id)->assertJsonPath('comments.1.delivery.tracking', 'recorded')
        ->assertJsonPath('comments.1.delivery.review_url', null)->assertDontSee('Synthetic private note');
    $this->actingAs($this->agent)->getJson('/it/tickets/'.$this->ticket->id)->assertOk()
        ->assertJsonPath('comments.1.is_internal', true)->assertJsonPath('comments.1.delivery', null);
});

test('delivery projection refreshes revoked actor and canonical comment ownership', function () {
    $comment = deliveryEvidenceReply($this->ticket, $this->agent);
    $outside = ItTicket::factory()->create(['site_id' => Site::factory()->create()->id,
        'requester_user_id' => User::factory()->create()->id, 'assigned_to_user_id' => null,
        'owner_user_id' => null, 'team_id' => null, 'queue_id' => null]);
    $foreign = $outside->comments()->create(['author_user_id' => $this->agent->id, 'is_internal' => false, 'body' => 'Outside site']);
    $presenter = app(ItTicketCommentDeliveryPresenter::class);
    expect($presenter->presentMany($this->ticket, $this->agent, collect([$foreign])))->toBe([])
        ->and($presenter->presentMany($outside, $this->agent, collect([$foreign])))->toBe([]);
    User::query()->whereKey($this->agent->id)->update(['approved_at' => null]);
    expect($presenter->presentMany($this->ticket, $this->agent, collect([$comment])))->toBe([]);
});

test('canonical delivery log filters older replies before pagination and rejects inaccessible or internal comment IDs', function () {
    $comment = deliveryEvidenceReply($this->ticket, $this->agent);
    ItEmailDelivery::factory()->count(50)->create(['it_ticket_id' => $this->ticket->id,
        'it_ticket_comment_id' => $comment->id, 'recipient_user_id' => $this->worker->id]);
    $later = deliveryEvidenceReply($this->ticket, $this->agent);
    ItEmailDelivery::factory()->count(101)->create(['it_ticket_id' => $this->ticket->id,
        'it_ticket_comment_id' => $later->id, 'recipient_user_id' => $this->worker->id]);
    $path = '/it/setup?tab=operations&delivery_comment_id='.$comment->id;
    $this->actingAs($this->agent)->get($path)->assertOk()->assertInertia(fn ($page) => $page
        ->where('emailDeliveryFilter.comment_id', $comment->id)->where('emailDeliveryFilter.total', 51)
        ->where('emailDeliveryFilter.page', 1)->where('emailDeliveryFilter.last_page', 2)->has('emailDeliveries', 50));
    $this->get($path.'&delivery_page=2')->assertOk()->assertInertia(fn ($page) => $page
        ->where('emailDeliveryFilter.page', 2)->has('emailDeliveries', 1));
    $internal = deliveryEvidenceReply($this->ticket, $this->agent, true);
    $this->get('/it/setup?tab=operations&delivery_comment_id='.$internal->id)->assertNotFound();
    $outside = ItTicket::factory()->create(['site_id' => Site::factory()->create()->id,
        'requester_user_id' => User::factory()->create()->id, 'assigned_to_user_id' => null,
        'owner_user_id' => null, 'team_id' => null, 'queue_id' => null]);
    $hidden = $outside->comments()->create(['author_user_id' => $this->worker->id, 'is_internal' => false, 'body' => 'Inaccessible']);
    $this->get('/it/setup?tab=operations&delivery_comment_id='.$hidden->id)->assertNotFound();
    $this->actingAs($this->worker)->get($path)->assertForbidden();
});

test('missing additive delivery columns remain explicitly unrecorded', function () {
    $comment = $this->ticket->comments()->create(['author_user_id' => $this->agent->id,
        'is_internal' => false, 'body' => 'Pre-migration reply']);
    Schema::swap(Mockery::mock(Schema::getFacadeRoot())->makePartial());
    Schema::shouldReceive('hasColumn')->with('it_email_deliveries', 'it_ticket_comment_id')->andReturn(false);
    $result = app(ItTicketCommentDeliveryPresenter::class)->presentMany($this->ticket, $this->agent, collect([$comment]));
    expect($result[$comment->id]['tracking'])->toBe('unrecorded')->and($result[$comment->id]['review_url'])->toBeNull();
});
