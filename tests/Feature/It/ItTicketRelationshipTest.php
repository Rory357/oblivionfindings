<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Presenters\ItTicketActivityPresenter;
use App\Domain\It\Services\ItTicketLinkService;
use App\Models\AuditLog;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\ItTicketEvent;
use App\Models\ItTicketLink;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->freezeTime();
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create();
    foreach (['agent' => 'hr', 'requester' => 'support_worker'] as $key => $role) {
        $this->{$key} = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $this->{$key}->roles()->syncWithoutDetaching([Role::where('name', $role)->firstOrFail()->id]);
        HrEmployeeProfile::factory()->create(['user_id' => $this->{$key}->id,
            'primary_site_id' => $this->site->id, 'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(), 'end_date' => null]);
    }
    $this->source = ItTicket::factory()->create(['site_id' => $this->site->id,
        'requester_user_id' => $this->requester->id, 'requested_for_user_id' => $this->requester->id,
        'status' => 'open', 'work_type' => 'incident']);
    $this->relatedTarget = ItTicket::factory()->create(['site_id' => $this->site->id,
        'requester_user_id' => User::factory()->create(['role' => 'support_worker', 'approved_at' => now()])->id,
        'status' => 'open', 'work_type' => 'incident']);
});

function relationshipInput(array $overrides = []): array
{
    return ['actor_user_id' => test()->agent->id, 'target_ticket_id' => test()->relatedTarget->id,
        'source_version' => test()->source->fresh()->lock_version, 'target_version' => test()->relatedTarget->fresh()->lock_version,
        'request_uuid' => (string) Str::uuid(), 'relationship' => 'related_ticket', 'action' => 'add', ...$overrides];
}

function relationshipUrl(): string
{
    return '/it/tickets/'.test()->source->id.'/related-work';
}

function relationshipRecovery(array $input, bool $cancel = false): string
{
    return '/it/tickets/'.test()->source->id.'/relationship-commands/'.$input['request_uuid'].($cancel ? '/cancel' : '');
}

test('related work persists reciprocal canonical links and audits without copying conversations or settling either ticket', function () {
    $comment = $this->source->comments()->create(['author_user_id' => $this->requester->id, 'body' => 'Source only', 'is_internal' => false]);
    $input = relationshipInput();
    $this->actingAs($this->agent)->postJson(relationshipUrl(), $input)->assertOk()->assertJsonPath('status', 'committed')
        ->assertJsonPath('data.changed', true)->assertJsonPath('data.source_version', $input['source_version'] + 1)
        ->assertJsonPath('data.target_version', $input['target_version'] + 1);
    expect(ItTicketLink::where('relationship', 'related_ticket')->count())->toBe(2)
        ->and($comment->fresh()->ticket_id)->toBe($this->source->id)
        ->and($this->relatedTarget->comments()->count())->toBe(0)
        ->and($this->source->fresh()->status)->toBe('open')->and($this->relatedTarget->fresh()->status)->toBe('open')
        ->and(ItTicketEvent::where('type', 'related_work_linked')->count())->toBe(2)
        ->and(AuditLog::where('action', 'it.ticket.relationship.add')->count())->toBe(2);
    $this->postJson(relationshipUrl(), $input)->assertOk()->assertJsonPath('data.replayed', true);
    $this->getJson('/it/tickets/'.$this->source->id)->assertOk()->assertJsonPath('linked_context.related_tickets_count', 1);
    expect(ItTicketCommandReceipt::where('operation', ItTicketCommandReceipt::RELATIONSHIP_OPERATION)->count())->toBe(1)
        ->and(ItTicketEvent::where('type', 'related_work_linked')->count())->toBe(2);
});

test('removal and old command recovery preserve historical outcomes without recreating links', function () {
    $add = relationshipInput(['relationship' => 'duplicate_ticket']);
    $this->actingAs($this->agent)->postJson(relationshipUrl(), $add)->assertOk();
    $remove = relationshipInput(['relationship' => 'duplicate_ticket', 'action' => 'remove']);
    $this->postJson(relationshipUrl(), $remove)->assertOk()->assertJsonPath('data.changed', true);
    $this->postJson(relationshipUrl(), $add)->assertOk()->assertJsonPath('data.replayed', true);
    $this->getJson(relationshipRecovery($add).'?'.http_build_query($add))->assertOk()->assertJsonPath('status', 'committed');
    $this->postJson(relationshipRecovery($add, true), $add)->assertOk()->assertJsonPath('status', 'committed');
    expect(ItTicketLink::whereIn('relationship', ItTicketLink::BROWSER_RELATIONSHIPS)->count())->toBe(0)
        ->and(ItTicketEvent::where('type', 'related_work_unlinked')->count())->toBe(2);
});

test('unconfirmed commands can be durably cancelled before a late request arrives', function () {
    $input = relationshipInput();
    $this->actingAs($this->agent)->getJson(relationshipRecovery($input).'?'.http_build_query($input))
        ->assertOk()->assertJsonPath('status', 'unconfirmed');
    $this->postJson(relationshipRecovery($input, true), $input)->assertOk()->assertJsonPath('status', 'cancelled');
    $this->postJson(relationshipUrl(), $input)->assertOk()->assertJsonPath('status', 'cancelled');
    expect(ItTicketLink::whereIn('relationship', ItTicketLink::BROWSER_RELATIONSHIPS)->count())->toBe(0)
        ->and($this->source->fresh()->lock_version)->toBe($input['source_version'])
        ->and(AuditLog::where('action', 'it.ticket.relationship.command.cancelled')->count())->toBe(1);
});

test('both parent versions reject stale relationship writes before any reciprocal change', function (string $parent) {
    $input = relationshipInput();
    $this->{$parent}->update(['title' => 'Changed during link review']);
    $this->actingAs($this->agent)->postJson(relationshipUrl(), $input)->assertStatus(409)->assertJsonPath('code', 'stale_ticket');
    expect(ItTicketLink::whereIn('relationship', ItTicketLink::BROWSER_RELATIONSHIPS)->count())->toBe(0)
        ->and(ItTicketCommandReceipt::where('operation', ItTicketCommandReceipt::RELATIONSHIP_OPERATION)->count())->toBe(0);
})->with(['source', 'relatedTarget']);

test('relationship commands bind actor and intent and conceal inaccessible counterparts', function () {
    $input = relationshipInput();
    $this->actingAs($this->agent)->postJson(relationshipUrl(), [...$input, 'actor_user_id' => $this->requester->id])->assertForbidden();
    $this->actingAs($this->requester)->postJson(relationshipUrl(), [...$input, 'actor_user_id' => $this->requester->id])->assertForbidden();
    $this->actingAs($this->agent)->postJson(relationshipUrl(), $input)->assertOk();
    $this->postJson(relationshipUrl(), [...$input, 'relationship' => 'duplicate_ticket'])->assertStatus(409);
    $this->relatedTarget->update(['site_id' => Site::factory()->create()->id, 'title' => 'Private counterpart']);
    $this->getJson(relationshipRecovery($input).'?'.http_build_query($input))->assertNotFound()->assertDontSee('Private counterpart');
    $this->getJson(relationshipUrl().'?actor_user_id='.$this->agent->id)->assertOk()
        ->assertJsonPath('data.links.data', [])->assertJsonPath('data.candidates.data', []);
    $this->getJson('/it/tickets/'.$this->source->id)->assertOk()->assertJsonPath('linked_context.related_tickets_count', 0);
    $events = app(ItTicketActivityPresenter::class)->present($this->source, $this->agent);
    expect(collect($events)->firstWhere('type', 'related_work_linked')['payload'])->toBe(['relationship' => 'related_ticket'])
        ->and(collect(app(ItTicketActivityPresenter::class)->present($this->source, $this->requester))->pluck('type')->all())
        ->not->toContain('related_work_linked');
});

test('ordinary relationship actions cannot self-link merge originals or alter specialized memberships', function () {
    $this->actingAs($this->agent)->postJson(relationshipUrl(), relationshipInput(['target_ticket_id' => $this->source->id]))
        ->assertUnprocessable()->assertJsonValidationErrors('target_ticket_id');
    $this->postJson(relationshipUrl(), relationshipInput(['relationship' => 'major_incident_member']))
        ->assertUnprocessable()->assertJsonValidationErrors('relationship');
    $this->source->update(['status' => 'closed']);
    $this->postJson(relationshipUrl(), relationshipInput())->assertUnprocessable()->assertJsonValidationErrors('form');
    $this->source->update(['status' => 'open']);
    $this->relatedTarget->update(['merged_into_ticket_id' => $this->source->id]);
    $this->postJson(relationshipUrl(), relationshipInput())->assertUnprocessable()->assertJsonValidationErrors('form');
    expect(ItTicketLink::whereIn('relationship', ItTicketLink::BROWSER_RELATIONSHIPS)->count())->toBe(0);
});

test('no-op links do not change versions and relationship type changes require deliberate removal', function () {
    $this->actingAs($this->agent)->postJson(relationshipUrl(), relationshipInput())->assertOk();
    $input = relationshipInput();
    $this->postJson(relationshipUrl(), $input)->assertOk()->assertJsonPath('data.changed', false)
        ->assertJsonPath('data.source_version', $input['source_version']);
    $this->postJson(relationshipUrl(), relationshipInput(['relationship' => 'duplicate_ticket']))->assertUnprocessable();
    expect(ItTicketLink::where('relationship', 'related_ticket')->count())->toBe(2)
        ->and(ItTicketLink::where('relationship', 'duplicate_ticket')->count())->toBe(0);
});

test('audit failure rolls back both relationship rows versions and command receipt before retry', function () {
    $input = relationshipInput();
    $event = 'eloquent.creating: '.AuditLog::class;
    Event::listen($event, function (AuditLog $log) {
        if ($log->action === 'it.ticket.relationship.add') {
            throw new RuntimeException('Synthetic relationship audit failure');
        }
    });
    try {
        expect(fn () => app(ItTicketLinkService::class)->changeRelated($this->source, $this->relatedTarget, $this->agent, $input))
            ->toThrow(RuntimeException::class, 'Synthetic relationship audit failure');
    } finally {
        Event::forget($event);
    }
    expect(ItTicketLink::whereIn('relationship', ItTicketLink::BROWSER_RELATIONSHIPS)->count())->toBe(0)
        ->and($this->source->fresh()->lock_version)->toBe($input['source_version'])
        ->and($this->relatedTarget->fresh()->lock_version)->toBe($input['target_version'])
        ->and(ItTicketCommandReceipt::where('operation', ItTicketCommandReceipt::RELATIONSHIP_OPERATION)->count())->toBe(0)
        ->and(ItTicketEvent::where('type', 'related_work_linked')->count())->toBe(0);
    expect(app(ItTicketLinkService::class)->changeRelated($this->source, $this->relatedTarget, $this->agent, $input)['status'])->toBe('committed');
});
