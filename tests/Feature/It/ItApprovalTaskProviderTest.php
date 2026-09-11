<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Models\ItTicket;
use App\Models\ItTicketApproval;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Tasks\Contracts\AssignableTaskProvider;
use App\Services\Tasks\Providers\ItApprovalTaskProvider;
use App\Services\Tasks\TaskAggregator;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create();
    $person = function (): User {
        $user = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
        $user->roles()->sync(Role::query()->where('name', 'hr')->pluck('id'));
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id,
            'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null]);

        return $user;
    };
    $this->raiser = $person();
    $this->primary = $person();
    $this->cover = $person();
    $this->ticket = ItTicket::factory()->create(['site_id' => $this->site->id, 'requires_approval' => true,
        'status' => 'open', 'priority' => 'high', 'requester_user_id' => $this->raiser->id]);
    $this->approval = ItTicketApproval::create(['it_ticket_id' => $this->ticket->id, 'requested_by' => $this->raiser->id,
        'status' => 'pending', 'primary_approver_user_id' => $this->primary->id, 'cover_approver_user_id' => $this->cover->id,
        'request_reason' => 'Private approval request text', 'request_reason_recorded_at' => now(),
        'assignment_recorded_at' => now(), 'expires_at' => now()->addHour()]);
    $this->provider = new ItApprovalTaskProvider;
});

test('pending approval is projected once with its canonical owner deadline and decision link without copying reasons', function () {
    $aggregator = new TaskAggregator([$this->provider]);
    $items = $aggregator->itemsFor($this->primary, ['assigned' => 'me']);
    expect($items)->toHaveCount(1)->and($items[0]->id)->toBe('it_approval-'.$this->approval->id)
        ->and($items[0]->assignee['id'])->toBe($this->primary->id)->and($items[0]->dueAt)->toBe($this->approval->expires_at->toIso8601String())
        ->and($items[0]->link)->toBe('/it/tickets/'.$this->ticket->id.'?tab=approvals#approval-'.$this->approval->id)
        ->and($items[0]->displayState)->toBe('Awaiting approver')->and($items[0]->restricted)->toBeTrue()
        ->and(json_encode($items[0]->toArray()))->not->toContain('Private approval request text')
        ->and($this->provider)->not->toBeInstanceOf(AssignableTaskProvider::class);
    expect((new TaskAggregator([$this->provider]))->itemsFor($this->cover, ['assigned' => 'me']))->toBe([]);
});

test('approved absence moves personal approval work to eligible cover and unavailable cover stays unassigned', function () {
    HrLeaveRequest::factory()->create(['user_id' => $this->primary->id, 'status' => 'approved', 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
    expect((new TaskAggregator([$this->provider]))->itemsFor($this->primary, ['assigned' => 'me']))->toBe([]);
    $items = (new TaskAggregator([$this->provider]))->itemsFor($this->cover, ['assigned' => 'me']);
    expect($items)->toHaveCount(1)->and($items[0]->displayState)->toBe('Awaiting cover approver');
    HrEmployeeProfile::query()->where('user_id', $this->cover->id)->update(['is_active' => false]);
    $unassigned = $this->provider->authorizedTasks($this->raiser);
    expect($unassigned)->toHaveCount(1)->and($unassigned[0]->assignee)->toBeNull()->and($unassigned[0]->displayState)->toBe('Approver assignment needs review');
});

test('sensitive participation and out-of-site approvals are absent from personal lists and direct identity lookup', function () {
    $this->ticket->update(['is_sensitive' => true, 'requester_user_id' => $this->primary->id]);
    $aggregator = new TaskAggregator([$this->provider]);
    expect($aggregator->itemsFor($this->primary))->toBe([])->and($aggregator->findItemFor($this->primary, 'it_approval', $this->approval->id))->toBeNull();
    $this->ticket->update(['is_sensitive' => false, 'site_id' => Site::factory()->create()->id]);
    expect($this->provider->authorizedTasks($this->primary))->toBe([]);
    $this->primary->update(['approved_at' => null]);
    expect($this->provider->canView($this->primary))->toBeFalse();
});

test('expired approvals leave pending work before the scheduler runs and terminal tickets never advertise a decision', function () {
    $this->travel(2)->hours();
    expect($this->provider->authorizedTasks($this->primary, ['include_done' => true]))->toBe([])
        ->and($this->approval->fresh()->status)->toBe('pending');
    $this->travelBack();
    foreach (['resolved', 'closed'] as $status) {
        $this->ticket->update(['status' => $status]);
        expect($this->provider->authorizedTasks($this->primary))->toBe([]);
    }
    $this->ticket->update(['status' => 'open', 'merged_into_ticket_id' => ItTicket::factory()->create(['site_id' => $this->site->id])->id]);
    expect($this->provider->authorizedTasks($this->primary))->toBe([]);
});
