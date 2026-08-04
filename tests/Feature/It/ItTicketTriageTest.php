<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\ItQueue;
use App\Models\ItService;
use App\Models\ItTicket;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\It\TicketCreatedNotification;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Notification;

function triageUser(string $role): User
{
    $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', $role)->first()->id,
    ]);

    return $user;
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->hr = triageUser('hr');
    $this->worker = triageUser('support_worker');
    $this->site = Site::factory()->create();
    foreach ([$this->hr, $this->worker] as $user) {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
        ]);
    }
});

function assignTriageUserToSite(User $user, Site $site): void
{
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'is_active' => true,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
    ]);
}

test('an agent logs a ticket on behalf of a colleague with full triage', function () {
    Notification::fake();
    $colleague = User::factory()->create();
    $assignee = triageUser('hr');
    $watcher = User::factory()->create();
    foreach ([$colleague, $assignee, $watcher] as $user) {
        assignTriageUserToSite($user, $this->site);
    }
    $asset = Asset::factory()->forSite($this->site)->create(['status' => 'active']);
    $service = ItService::factory()->create(['name' => 'Clinical device connectivity']);
    $queue = ItQueue::factory()->create([
        'filter_rules' => [
            'routing_priority' => 50,
            'is_default' => false,
            'work_types' => ['security_request'],
            'categories' => ['hardware'],
            'priorities' => ['high'],
            'service_ids' => [$service->id],
            'site_ids' => [$this->site->id],
        ],
    ]);

    $this->actingAs($this->hr)->post('/it/tickets', [
        'title' => 'Hoist controller unresponsive',
        'description' => 'Beeps but no movement.',
        'category' => 'hardware',
        'subcategory' => 'Mobility equipment',
        'priority' => 'high',
        'work_type' => 'security_request',
        'it_service_id' => $service->id,
        'site_id' => $this->site->id,
        'requester_user_id' => $colleague->id,
        'assigned_to_user_id' => $assignee->id,
        'asset_id' => $asset->id,
        'watchers' => [$watcher->id],
    ])->assertRedirect();

    $ticket = ItTicket::query()->firstWhere('title', 'Hoist controller unresponsive');
    expect((int) $ticket->requester_user_id)->toBe($colleague->id);
    expect($ticket->subcategory)->toBe('Mobility equipment');
    expect((int) $ticket->asset_id)->toBe($asset->id);
    expect((int) $ticket->assigned_to_user_id)->toBe($assignee->id);
    expect($ticket->work_type)->toBe('security_request');
    expect((int) $ticket->it_service_id)->toBe($service->id);
    expect((int) $ticket->queue_id)->toBe($queue->id);
    expect($ticket->status)->toBe('in_progress');
    expect($ticket->source)->toBe('agent');
    expect($ticket->watchers()->whereKey($watcher->id)->exists())->toBeTrue();

    $created = $ticket->events()->where('type', 'created')->first();
    expect($created->payload['on_behalf_of'] ?? null)->toBe($colleague->id);

    // The receipt goes to the requester (the colleague), never the acting agent.
    Notification::assertSentTo($colleague, TicketCreatedNotification::class);
    Notification::assertNotSentTo($this->hr, TicketCreatedNotification::class);
});

test('self-service requesters cannot use the agent triage fields', function () {
    $other = User::factory()->create();
    $asset = Asset::factory()->create(['status' => 'active']);
    $service = ItService::factory()->create();

    $this->actingAs($this->worker)->post('/it/tickets', [
        'title' => 'My laptop is slow',
        'category' => 'hardware',
        'priority' => 'normal',
        // All of these are silently dropped for a self-service requester.
        'requester_user_id' => $other->id,
        'subcategory' => 'Nope',
        'asset_id' => $asset->id,
        'assigned_to_user_id' => $other->id,
        'work_type' => 'security_request',
        'it_service_id' => $service->id,
        'watchers' => [$other->id],
    ])->assertRedirect();

    $ticket = ItTicket::query()->firstWhere('title', 'My laptop is slow');
    expect((int) $ticket->requester_user_id)->toBe($this->worker->id); // on-behalf ignored
    expect($ticket->subcategory)->toBeNull();
    expect($ticket->asset_id)->toBeNull();
    expect($ticket->assigned_to_user_id)->toBeNull();
    expect($ticket->work_type)->toBe('incident');
    expect($ticket->it_service_id)->toBeNull();
    expect($ticket->source)->toBe('portal');
    expect($ticket->watchers()->count())->toBe(0);
});
