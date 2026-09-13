<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ItTicket;
use App\Models\ItTicketApproval;
use App\Models\ItTicketCommandReceipt;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create();
    $this->person = function (Site $site): User {
        $user = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
        $user->roles()->sync(Role::query()->where('name', 'hr')->pluck('id'));
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id,
            'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null]);

        return $user;
    };
    $this->actor = ($this->person)($this->site);
    $this->selected = ($this->person)($this->site);
    $this->ticket = ItTicket::factory()->create(['site_id' => $this->site->id, 'requires_approval' => true,
        'status' => 'open', 'workflow_state' => 'submitted', 'lock_version' => 1]);
    $this->path = '/it/tickets/'.$this->ticket->id.'/approval-candidates/validate';
    $this->candidate = ['actor_user_id' => $this->actor->id, 'purpose' => 'approval_work', 'operation' => 'request',
        'approval_id' => null, 'memory_uuid' => (string) Str::uuid(), 'candidate_uuid' => (string) Str::uuid(),
        'base_ticket_version' => 1, 'step_index' => 1,
        'fields' => ['reason' => 'Private unfinished approval proposal', 'primary_approver_user_id' => $this->selected->id],
        'bound_scopes' => []];
    $this->actingAs($this->actor);
});

test('explicit approval resume proves exact scope without persisting or echoing private unfinished text', function () {
    $input = [...$this->candidate, 'fields' => ['reason' => str_repeat('x', 1200), 'primary_approver_user_id' => null]];
    $response = $this->postJson($this->path, $input)->assertOk()
        ->assertJsonPath('candidate.actor_user_id', $this->actor->id)->assertJsonPath('candidate.candidate_uuid', $input['candidate_uuid'])
        ->assertJsonPath('candidate.memory_uuid', $input['memory_uuid'])->assertJsonPath('candidate.purpose', 'approval_work')
        ->assertJsonPath('candidate.context_key', 'ticket:'.$this->ticket->id.':approval:new:operation:request')
        ->assertJsonPath('candidate.base_ticket_version', 1)->assertJsonPath('candidate.current_ticket_version', 1)
        ->assertJsonPath('candidate.authorized', true)->assertHeader('Cache-Control', 'no-store, private')->assertSessionMissing('_old_input');
    expect($response->getContent())->not->toContain(str_repeat('x', 1200))
        ->and(ItTicketApproval::count())->toBe(0)->and(ItTicketCommandReceipt::count())->toBe(0)
        ->and($this->ticket->events()->count())->toBe(0)->and((int) $this->ticket->fresh()->lock_version)->toBe(1);
});

test('every retained selection is checked even after the active field was cleared', function () {
    $outside = ($this->person)(Site::factory()->create());
    foreach (['primary_approver_user_id', 'cover_approver_user_id'] as $field) {
        $this->postJson($this->path, [...$this->candidate, 'fields' => ['reason' => 'Keep this private', 'primary_approver_user_id' => null],
            'bound_scopes' => [[$field => $outside->id]]])->assertForbidden()->assertHeader('Cache-Control', 'no-store, private')
            ->assertDontSee('Keep this private')->assertSessionMissing('_old_input');
    }
    HrEmployeeProfile::query()->where('user_id', $this->selected->id)->update(['is_active' => false]);
    $this->postJson($this->path, $this->candidate)->assertForbidden();
});

test('actor parent operation and pending-command identity cannot be exchanged during recovery', function () {
    $this->postJson($this->path, [...$this->candidate, 'actor_user_id' => $this->selected->id])->assertForbidden();
    $otherTicket = ItTicket::factory()->create(['site_id' => $this->site->id]);
    $approval = ItTicketApproval::create(['it_ticket_id' => $otherTicket->id, 'requested_by' => $this->selected->id, 'status' => 'pending']);
    $this->postJson($this->path, [...$this->candidate, 'operation' => 'decide', 'approval_id' => $approval->id])->assertNotFound();
    $this->postJson($this->path, [...$this->candidate, 'operation' => 'withdraw', 'approval_id' => null])->assertUnprocessable();
    $pending = ['actor_user_id' => $this->actor->id, 'ticket_id' => $this->ticket->id,
        'request_uuid' => (string) Str::uuid(), 'operation' => 'request', 'approval_id' => null, 'expected_version' => 1,
        'fields' => $this->candidate['fields']];
    $this->postJson($this->path, [...$this->candidate, 'pending_approval' => $pending])->assertOk();
    foreach ([['actor_user_id' => $this->selected->id], ['ticket_id' => $otherTicket->id], ['operation' => 'withdraw'],
        ['approval_id' => $approval->id], ['expected_version' => 2]] as $change) {
        $this->postJson($this->path, [...$this->candidate, 'pending_approval' => [...$pending, ...$change]])->assertUnprocessable();
    }
    $this->postJson($this->path, [...$this->candidate, 'fields' => ['body' => 'Wrong audience']])->assertUnprocessable();
});

test('changed ticket and ended approval allow private review with an explicit mutation blocker', function () {
    $this->ticket->update(['subcategory' => 'Concurrent change']);
    $this->postJson($this->path, $this->candidate)->assertOk()->assertJsonPath('candidate.capabilities.submit', false)
        ->assertJsonPath('candidate.blocker.code', 'ticket_changed')->assertJsonPath('candidate.current_ticket_version', 2);
    $approval = ItTicketApproval::create(['it_ticket_id' => $this->ticket->id, 'requested_by' => $this->selected->id,
        'status' => 'expired', 'expired_at' => now()]);
    $this->postJson($this->path, [...$this->candidate, 'operation' => 'decide', 'approval_id' => $approval->id,
        'base_ticket_version' => 2, 'fields' => ['decision' => 'approve', 'reason' => 'Old unsent decision']])->assertOk()
        ->assertJsonPath('candidate.capabilities.submit', false)->assertJsonPath('candidate.blocker.code', 'approval_ended');
    expect($approval->fresh()->status)->toBe('expired')->and($approval->fresh()->approver_id)->toBeNull();
});

test('ticket projection shows named pending responsibility only to current work-authorized viewers', function () {
    $approval = ItTicketApproval::create(['it_ticket_id' => $this->ticket->id, 'requested_by' => $this->actor->id,
        'status' => 'pending', 'primary_approver_user_id' => $this->selected->id, 'assignment_recorded_at' => now(),
        'request_reason' => 'Private approval rationale', 'request_reason_recorded_at' => now(), 'expires_at' => now()->addDay()]);
    $this->getJson('/it/tickets/'.$this->ticket->id)->assertOk()->assertJsonPath('approval_work.current.id', $approval->id)
        ->assertJsonPath('approval_work.current.primary.id', $this->selected->id)
        ->assertJsonPath('approval_work.current.current_responsibility.basis', 'primary')
        ->assertJsonPath('approval_work.current.current_responsibility.person.id', $this->selected->id)
        ->assertJsonPath('approval_work.can_decide', false)->assertJsonPath('approval_work.can_withdraw', true);
    $this->actingAs($this->selected)->getJson('/it/tickets/'.$this->ticket->id)->assertOk()->assertJsonPath('approval_work.can_decide', true);

    $participant = ($this->person)($this->site);
    $participant->roles()->sync(Role::query()->where('name', 'support_worker')->pluck('id'));
    $participant->update(['role' => 'support_worker']);
    $this->ticket->update(['requester_user_id' => $participant->id]);
    $this->actingAs($participant->fresh())->getJson('/it/tickets/'.$this->ticket->id)->assertOk()
        ->assertJsonPath('approval_work', null)->assertJsonPath('ticket.approval.status', 'pending')
        ->assertJsonPath('ticket.approval.reason', null)->assertDontSee('Private approval rationale');
});

test('expired approval projection remains truthful before a scheduler check and does not invent a decider', function () {
    ItTicketApproval::create(['it_ticket_id' => $this->ticket->id, 'requested_by' => $this->actor->id,
        'status' => 'pending', 'primary_approver_user_id' => $this->selected->id, 'assignment_recorded_at' => now()->subDays(2),
        'expires_at' => now()->subHour()]);
    $this->getJson('/it/tickets/'.$this->ticket->id)->assertOk()->assertJsonPath('ticket.approval.status', 'expired')
        ->assertJsonPath('approval_work.current.status', 'expired')->assertJsonPath('approval_work.current.recorded_status', 'pending')
        ->assertJsonPath('approval_work.current.decided_by', null)->assertJsonPath('approval_work.current.expired_at', null)
        ->assertJsonPath('approval_work.current.current_responsibility', null)->assertJsonPath('approval_work.can_decide', false);
    expect(ItTicketApproval::query()->sole()->status)->toBe('pending')->and($this->ticket->events()->count())->toBe(0);
});

test('private approval history paginates canonical evidence and fences changed ticket versions', function () {
    $ids = [];
    for ($index = 0; $index < 12; $index++) {
        $ids[] = ItTicketApproval::create(['it_ticket_id' => $this->ticket->id, 'requested_by' => $this->actor->id,
            'status' => 'rejected', 'request_reason' => 'Private original request '.$index, 'request_reason_recorded_at' => now(),
            'decision_reason' => 'Private decision '.$index, 'decision_reason_recorded_at' => now()])->id;
    }
    $path = '/it/tickets/'.$this->ticket->id.'/approval-history';
    $parameters = ['actor_user_id' => $this->actor->id, 'review_nonce' => (string) Str::uuid(), 'page' => 1];
    $first = $this->getJson($path.'?'.http_build_query($parameters))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('review_nonce', $parameters['review_nonce'])->assertJsonPath('history.total', 12)
        ->assertJsonPath('history.next_page', 2)->assertJsonCount(10, 'history.records');
    expect(array_column($first->json('history.records'), 'id'))->toBe(array_reverse(array_slice($ids, 2)));
    $later = [...$parameters, 'page' => 2, 'expected_version' => 1];
    $second = $this->getJson($path.'?'.http_build_query($later))->assertOk()->assertJsonCount(2, 'history.records')->assertJsonPath('history.next_page', null);
    expect(array_column($second->json('history.records'), 'id'))->toBe([$ids[1], $ids[0]]);
    $target = $this->getJson($path.'?'.http_build_query([...$parameters, 'approval_id' => $ids[0]]))
        ->assertOk()->assertJsonPath('target_approval_id', $ids[0])->assertJsonPath('history.page', 2)
        ->assertJsonCount(2, 'history.records')->assertHeader('Cache-Control', 'no-store, private');
    expect(array_column($target->json('history.records'), 'id'))->toBe([$ids[1], $ids[0]]);
    $foreign = ItTicketApproval::create(['it_ticket_id' => ItTicket::factory()->create(['site_id' => $this->site->id])->id,
        'requested_by' => $this->actor->id, 'status' => 'pending', 'reason' => 'Foreign private request']);
    $this->getJson($path.'?'.http_build_query([...$parameters, 'approval_id' => $foreign->id]))
        ->assertNotFound()->assertDontSee('Foreign private')->assertDontSee('Private original');
    $this->getJson($path.'?'.http_build_query([...$parameters, 'approval_id' => $ids[0], 'page' => 2]))
        ->assertUnprocessable()->assertJsonValidationErrors('page');
    $this->getJson($path.'?'.http_build_query([...$parameters, 'page' => 2]))->assertUnprocessable()->assertJsonValidationErrors('expected_version');
    $this->ticket->update(['subcategory' => 'Concurrent change']);
    $this->getJson($path.'?'.http_build_query($later))->assertConflict()->assertJsonPath('code', 'history_changed')->assertDontSee('Private original');
    expect($this->ticket->events()->count())->toBe(0);
});

test('approval history rechecks actor site and sensitive work access on every direct page', function () {
    ItTicketApproval::create(['it_ticket_id' => $this->ticket->id, 'requested_by' => $this->actor->id,
        'status' => 'pending', 'reason' => 'Private historical note']);
    $parameters = ['actor_user_id' => $this->actor->id, 'review_nonce' => (string) Str::uuid()];
    $path = '/it/tickets/'.$this->ticket->id.'/approval-history?';
    $this->getJson($path.http_build_query([...$parameters, 'actor_user_id' => $this->selected->id]))->assertForbidden()->assertDontSee('Private historical');
    $this->ticket->update(['is_sensitive' => true, 'requester_user_id' => $this->actor->id]);
    $this->getJson($path.http_build_query($parameters))->assertNotFound()->assertDontSee('Private historical');
    $this->ticket->update(['is_sensitive' => false, 'site_id' => Site::factory()->create()->id]);
    $this->getJson($path.http_build_query($parameters))->assertNotFound()->assertDontSee('Private historical');
});
