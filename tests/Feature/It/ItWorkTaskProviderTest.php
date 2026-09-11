<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItWorkTaskService;
use App\Models\ItTicket;
use App\Models\ItWorkTask;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Tasks\Contracts\AssignableTaskProvider;
use App\Services\Tasks\Providers\ItWorkTaskProvider;
use App\Services\Tasks\TaskAggregator;
use App\Services\Tasks\TaskItem;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    Notification::fake();
    $this->site = Site::factory()->create();
    $this->actor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->actor->roles()->sync(Role::query()->where('name', 'hr')->pluck('id'));
    $this->profile = HrEmployeeProfile::factory()->create(['user_id' => $this->actor->id, 'primary_site_id' => $this->site->id,
        'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null]);
    $this->ticket = ItTicket::factory()->create(['site_id' => $this->site->id, 'status' => 'open', 'priority' => 'high']);
    $this->task = ItWorkTask::factory()->create(['ticket_id' => $this->ticket->id, 'assigned_to_user_id' => $this->actor->id,
        'description' => 'Private implementation note', 'due_at' => now()->subHour()]);
    $this->provider = new ItWorkTaskProvider;
});

test('personal work uses the same canonical task identity assignment due time and corrective ticket link', function () {
    $aggregator = new TaskAggregator([$this->provider]);
    $items = $aggregator->itemsFor($this->actor, ['assigned' => 'me']);
    expect($items)->toHaveCount(1)->and($items[0]->id)->toBe('it_work_task-'.$this->task->id)
        ->and($items[0]->assignee['id'])->toBe($this->actor->id)
        ->and($items[0]->isOverdue())->toBeTrue()->and($items[0]->severity)->toBe('high')
        ->and($items[0]->link)->toBe('/it/tickets/'.$this->ticket->id.'?tab=tasks#task-'.$this->task->id)
        ->and($items[0]->restricted)->toBeTrue()->and($items[0]->description)->toBeNull()
        ->and($this->provider)->not->toBeInstanceOf(AssignableTaskProvider::class);
    expect(json_encode($items[0]->toArray()))->not->toContain('Private implementation note');
    $other = User::factory()->create();
    $this->task->forceFill(['assigned_to_user_id' => $other->id])->save();
    expect((new TaskAggregator([$this->provider]))->itemsFor($this->actor, ['assigned' => 'me']))->toBe([]);
});

test('participant and out-of-scope tasks stay out of list counts search and direct task identity lookup', function () {
    $this->ticket->forceFill(['is_sensitive' => true, 'requester_user_id' => $this->actor->id])->save();
    $aggregator = new TaskAggregator([$this->provider]);
    expect($aggregator->itemsFor($this->actor))->toBe([])
        ->and($aggregator->findItemFor($this->actor, 'it_work_task', $this->task->id))->toBeNull();
    $this->ticket->forceFill(['is_sensitive' => false, 'requester_user_id' => User::factory()->create()->id,
        'site_id' => Site::factory()->create()->id])->save();
    expect((new TaskAggregator([$this->provider]))->itemsFor($this->actor, ['q' => $this->task->title]))->toBe([]);
    $this->actor->forceFill(['approved_at' => null])->save();
    expect($this->provider->authorizedTasks($this->actor, ['include_done' => true]))->toBe([]);
});

test('required dependent work returns to the personal feed after prerequisite evidence becomes stale', function () {
    $prerequisite = ItWorkTask::factory()->create(['ticket_id' => $this->ticket->id, 'is_required' => false]);
    $this->task->dependencies()->attach($prerequisite->id);
    $service = app(ItWorkTaskService::class);
    $this->actingAs($this->actor);
    $blocked = $this->provider->authorizedTasks($this->actor, ['id' => $this->task->id]);
    expect($blocked[0]->displayState)->toBe('Waiting for prerequisites')->and($blocked[0]->bucket)->toBe(TaskItem::BUCKET_OPEN);
    $service->complete($this->ticket, $prerequisite, $this->actor, ['evidence' => ['Original prerequisite evidence']]);
    $service->complete($this->ticket, $this->task, $this->actor, ['evidence' => ['Private completion reference']]);
    expect($this->provider->authorizedTasks($this->actor, ['id' => $this->task->id]))->toBe([]);
    $service->reopen($this->ticket, $prerequisite, $this->actor, 'The implementation changed.');
    $items = $this->provider->authorizedTasks($this->actor, ['id' => $this->task->id]);
    expect($items)->toHaveCount(1)->and($items[0]->displayState)->toBe('Completion needs review')
        ->and($items[0]->bucket)->toBe(TaskItem::BUCKET_OPEN)
        ->and(json_encode($items[0]->toArray()))->not->toContain('Private completion reference');
});

test('terminal task and ticket states are explicit and do not duplicate open personal work', function () {
    $this->task->forceFill(['status' => 'cancelled'])->save();
    expect($this->provider->authorizedTasks($this->actor))->toBe([]);
    $done = $this->provider->authorizedTasks($this->actor, ['include_done' => true]);
    expect($done)->toHaveCount(1)->and($done[0]->bucket)->toBe(TaskItem::BUCKET_DONE)->and($done[0]->displayState)->toBe('Cancelled');
    $this->task->forceFill(['status' => 'pending'])->save();
    $this->ticket->forceFill(['status' => 'resolved', 'resolved_at' => now()])->save();
    expect($this->provider->authorizedTasks($this->actor))->toBe([]);
    $this->ticket->forceFill(['merged_into_ticket_id' => ItTicket::factory()->create(['site_id' => $this->site->id])->id])->save();
    expect($this->provider->authorizedTasks($this->actor, ['include_done' => true]))->toBe([]);
});

test('loss of the last approved site removes existing personal work and retained direct links', function () {
    expect($this->provider->authorizedTasks($this->actor))->toHaveCount(1);
    $this->profile->forceFill(['is_active' => false])->save();
    expect($this->provider->authorizedTasks($this->actor))->toBe([])
        ->and((new TaskAggregator([$this->provider]))->findItemFor($this->actor, 'it_work_task', $this->task->id))->toBeNull();
});
