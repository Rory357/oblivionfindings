<?php

use App\Domain\It\Services\ItSavedTicketFilterService;
use App\Domain\It\Services\ItTicketIntakeService;
use App\Domain\It\Services\ItTicketInteractionService;
use App\Models\ItTicket;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
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
    Notification::fake();
});

function conversationReply(ItTicket $ticket, User $actor, bool $internal = false): void
{
    app(ItTicketInteractionService::class)->addCommentCommand($ticket, $actor, [
        'actor_user_id' => $actor->id, 'request_uuid' => (string) Str::uuid(), 'expected_version' => $ticket->fresh()->lock_version,
        'body' => $internal ? 'Synthetic private investigation' : 'Synthetic public reply', 'is_internal' => $internal,
    ]);
}

test('intake requester replies and internal notes produce matching Awaiting IT counts rows lane and safe detail evidence', function () {
    $historical = ItTicket::factory()->create(['site_id' => $this->site->id, 'requester_user_id' => $this->worker->id,
        'status' => 'open', 'first_responded_at' => null]);
    $ticket = app(ItTicketIntakeService::class)->createCommand($this->worker, [
        'request_uuid' => (string) Str::uuid(), 'title' => 'Canonical conversation intake', 'category' => 'hardware', 'priority' => 'normal',
        'site_id' => $this->site->id,
    ])->ticket;
    $this->actingAs($this->agent)->get('/it?tab=tickets&view=awaiting_it')->assertOk()->assertInertia(fn ($page) => $page
        ->where('summary.tickets.conversation_ready', true)->where('summary.tickets.awaiting_it', 1)
        ->where('summary.tickets.views.awaiting_it', 1)->where('summary.tickets.awaiting_reply', 2)
        ->has('tickets.data', 1)->where('tickets.data.0.id', $ticket->id)
        ->where('tickets.data.0.conversation.state', 'awaiting_it')
        ->has('overview.awaiting_it_lane', 1)->where('overview.awaiting_it_lane.0.id', $ticket->id));
    conversationReply($ticket, $this->agent);
    $firstResponse = $ticket->fresh()->first_responded_at;
    $this->get('/it?tab=tickets&view=awaiting_it')->assertOk()->assertInertia(fn ($page) => $page
        ->where('summary.tickets.awaiting_it', 0)->where('summary.tickets.awaiting_reply', 1)->has('tickets.data', 0));
    conversationReply($ticket, $this->worker);
    $publicComment = $ticket->comments()->where('is_internal', false)->latest('id')->firstOrFail();
    conversationReply($ticket, $this->agent, true);
    $this->get('/it?tab=tickets&view=awaiting_it')->assertOk()->assertInertia(fn ($page) => $page
        ->where('summary.tickets.awaiting_it', 1)->where('summary.tickets.views.awaiting_it', 1)
        ->where('summary.tickets.awaiting_reply', 1)->has('tickets.data', 1)
        ->where('tickets.data.0.id', $ticket->id)->where('overview.awaiting_it_lane.0.id', $ticket->id));
    $this->actingAs($this->worker)->getJson('/it/tickets/'.$ticket->id)->assertOk()
        ->assertJsonPath('ticket.conversation.state', 'awaiting_it')
        ->assertJsonPath('ticket.conversation.last_public.comment_id', $publicComment->id)
        ->assertJsonPath('ticket.conversation.last_public.speaker_side', 'requester')
        ->assertJsonCount(2, 'comments')->assertJsonPath('comments.0.speaker_side', 'it')
        ->assertJsonPath('comments.1.speaker_side', 'requester')->assertJsonPath('comments.1.source_channel', 'browser')
        ->assertDontSee('Synthetic private investigation');
    expect($ticket->fresh()->first_responded_at->equalTo($firstResponse))->toBeTrue()
        ->and($historical->fresh()->next_response_party)->toBeNull();
});

test('the Awaiting IT saved view includes vendor waiting but excludes settled historical and inaccessible records', function () {
    $visible = ItTicket::factory()->create(['site_id' => $this->site->id, 'status' => 'waiting', 'waiting_party' => 'vendor',
        'first_responded_at' => now(), 'next_response_party' => 'it']);
    $outside = Site::factory()->create();
    ItTicket::factory()->create(['site_id' => $outside->id, 'title' => 'Inaccessible conversation subject',
        'status' => 'open', 'next_response_party' => 'it', 'assigned_to_user_id' => null, 'owner_user_id' => null, 'team_id' => null, 'queue_id' => null]);
    ItTicket::factory()->create(['site_id' => $this->site->id, 'status' => 'resolved', 'next_response_party' => 'it']);
    ItTicket::factory()->create(['site_id' => $this->site->id, 'status' => 'open', 'first_responded_at' => null]);
    $saved = app(ItSavedTicketFilterService::class)->store($this->agent, 'IT response due', ['view' => 'awaiting_it']);
    $this->actingAs($this->agent)->get('/it?tab=tickets&saved_filter='.$saved->id)->assertOk()
        ->assertDontSee('Inaccessible conversation subject')->assertInertia(fn ($page) => $page
        ->where('filters.view', 'awaiting_it')->where('summary.tickets.awaiting_it', 1)
        ->where('summary.tickets.views.awaiting_it', 1)->has('tickets.data', 1)->where('tickets.data.0.id', $visible->id)
        ->where('tickets.data.0.waiting_party', 'vendor')->where('tickets.data.0.conversation.state', 'awaiting_it')
        ->has('overview.awaiting_it_lane', 1)->where('overview.awaiting_it_lane.0.id', $visible->id));
    expect(ItSavedTicketFilterService::PREDEFINED_VIEWS['awaiting_reply'])->toBe('Awaiting first reply');
});

test('legacy canonical replies capture current provenance once the schema exists without reclassifying old comments', function () {
    $ticket = ItTicket::factory()->create(['site_id' => $this->site->id, 'requester_user_id' => $this->worker->id,
        'status' => 'open', 'next_response_party' => 'it', 'first_responded_at' => null]);
    $old = $ticket->comments()->create(['author_user_id' => $this->agent->id, 'body' => 'Historical unclassified reply', 'is_internal' => false]);
    $result = app(ItTicketInteractionService::class)->addComment($ticket, $this->agent, 'Current canonical reply', false);
    expect($old->fresh()->speaker_side)->toBeNull()->and($old->fresh()->source_channel)->toBeNull()
        ->and($result['comment']->speaker_side)->toBe('it')->and($ticket->fresh()->next_response_party)->toBe('requester');
});

test('pre-migration pages preserve compatibility and disclose unavailable conversation counts', function () {
    // Keep the initialized connection for every unrelated schema read.
    Schema::swap(Mockery::mock(Schema::getFacadeRoot())->makePartial());
    Schema::shouldReceive('hasColumn')->with('it_tickets', 'next_response_party')->andReturn(false);
    expect(Schema::hasTable('it_tickets'))->toBeTrue()->and(ItTicket::hasConversationEvidence())->toBeFalse();
    $this->actingAs($this->agent)->get('/it?tab=tickets')->assertOk()->assertInertia(fn ($page) => $page
        ->where('summary.tickets.conversation_ready', false)->where('summary.tickets.awaiting_it', null)
        ->where('overview.conversation_ready', false)->has('overview.awaiting_it_lane', 0));
    $this->get('/it?tab=tickets&view=awaiting_it')->assertStatus(503);
});
