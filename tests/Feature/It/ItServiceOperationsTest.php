<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingTask;
use App\Domain\Hr\Notifications\ItProvisioningCancelledNotification;
use App\Domain\It\Contracts\TracksItEmailDelivery;
use App\Domain\It\InboundEmailIngestor;
use App\Domain\It\Services\ItAutomationRunRecorder;
use App\Domain\It\Services\ItAutomationScheduleCatalog;
use App\Domain\It\Services\ItEmailDeliveryService;
use App\Jobs\PollItMailboxJob;
use App\Models\AuditLog;
use App\Models\ItAutomationRun;
use App\Models\ItChange;
use App\Models\ItEmailDelivery;
use App\Models\ItKbArticle;
use App\Models\ItKbInteraction;
use App\Models\ItMajorIncident;
use App\Models\ItProblem;
use App\Models\ItProvisioningRequest;
use App\Models\ItService;
use App\Models\ItTicket;
use App\Models\ItTicketComment;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\It\TicketApprovalNotification;
use App\Notifications\It\TicketAssignedNotification;
use App\Notifications\It\TicketCreatedNotification;
use App\Notifications\It\TicketReopenedNotification;
use App\Notifications\It\TicketRepliedNotification;
use App\Notifications\It\TicketResolvedNotification;
use App\Notifications\It\TicketSlaNotification;
use Database\Seeders\RbacSeeder;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

function serviceOperationsUser(string $role = 'hr'): User
{
    $user = User::factory()->create([
        'role' => $role,
        'approved_at' => now(),
    ]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', $role)->first()->id,
    ]);

    return $user;
}

function serviceOperationsAssignSite(User $user, ?Site $site = null): Site
{
    $site ??= Site::factory()->create();
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    return $site;
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->manager = serviceOperationsUser();
    $this->worker = serviceOperationsUser('support_worker');
});

test('service operations schema records knowledge evidence email delivery and canonical scheduler runs', function () {
    expect(Schema::hasColumns('it_kb_articles', [
        'audience', 'site_scope', 'owner_user_id', 'reviewed_by_user_id',
        'related_service_id', 'review_due_at', 'review_started_at', 'published_at',
        'retired_at', 'deflection_count',
    ]))->toBeTrue()
        ->and(Schema::hasTable('it_kb_interactions'))->toBeTrue()
        ->and(Schema::hasTable('it_email_deliveries'))->toBeTrue()
        ->and(Schema::hasColumns('it_email_deliveries', [
            'retry_of_delivery_id', 'it_provisioning_request_id', 'notification_context',
            'accepted_at', 'provider_status_at',
        ]))->toBeTrue()
        ->and(Schema::hasTable('it_automation_runs'))->toBeTrue();
});

test('knowledge can only enter managed lifecycle states through lifecycle actions', function () {
    $payload = [
        'title' => 'Managed lifecycle only',
        'category' => 'network',
        'body' => 'Reviewed operating guidance.',
        'status' => 'published',
    ];

    $this->actingAs($this->manager)
        ->post('/it/kb', $payload)
        ->assertSessionHasErrors('status');
    expect(ItKbArticle::query()->count())->toBe(0);

    unset($payload['status']);
    $this->actingAs($this->manager)
        ->post('/it/kb', $payload)
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();
    $article = ItKbArticle::query()->sole();
    expect($article->status)->toBe('draft');

    $this->actingAs($this->manager)
        ->patch("/it/kb/{$article->id}", ['status' => 'published'])
        ->assertSessionHasErrors('status');
    $this->actingAs($this->manager)
        ->post("/it/kb/{$article->id}/publish")
        ->assertRedirect();
    expect($article->fresh()->status)->toBe('draft');

    $this->actingAs($this->manager)
        ->post("/it/kb/{$article->id}/submit-review")
        ->assertRedirect();
    $this->actingAs($this->manager)
        ->post("/it/kb/{$article->id}/publish")
        ->assertRedirect();
    expect($article->fresh()->status)->toBe('published');

    $this->actingAs($this->manager)
        ->post("/it/kb/{$article->id}/retire")
        ->assertSessionHasErrors('reason');
    expect($article->fresh()->status)->toBe('published');
});

test('knowledge follows review publish and retire lifecycle with ownership scope and evidence', function () {
    $site = Site::factory()->create();
    $service = ItService::factory()->create();
    $owner = serviceOperationsUser();
    serviceOperationsAssignSite($this->manager, $site);
    serviceOperationsAssignSite($owner, $site);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->worker->id,
        'primary_site_id' => $site->id,
    ]);

    $this->actingAs($this->manager)->post('/it/kb', [
        'title' => 'Reset the site Wi-Fi controller',
        'category' => 'network',
        'body' => 'Use the approved recovery workflow.',
        'audience' => 'specific_sites',
        'site_scope' => [$site->id],
        'owner_user_id' => $owner->id,
        'related_service_id' => $service->id,
        'review_due_at' => now()->addMonth()->toDateString(),
    ])->assertRedirect()->assertSessionDoesntHaveErrors();

    $article = ItKbArticle::query()->sole();
    expect($article->status)->toBe('draft')
        ->and($article->site_scope)->toBe([$site->id])
        ->and($article->owner->is($owner))->toBeTrue()
        ->and($article->service->is($service))->toBeTrue();

    $this->actingAs($this->manager)
        ->post("/it/kb/{$article->id}/submit-review")
        ->assertRedirect();
    expect($article->fresh()->status)->toBe('in_review')
        ->and($article->fresh()->review_started_at)->not->toBeNull();

    $this->actingAs($this->manager)
        ->post("/it/kb/{$article->id}/publish")
        ->assertRedirect();
    expect($article->fresh()->status)->toBe('published')
        ->and($article->fresh()->published_at)->not->toBeNull()
        ->and($article->fresh()->reviewed_by_user_id)->toBe($this->manager->id);

    $this->actingAs($this->worker)
        ->get('/it')
        ->assertInertia(fn ($page) => $page
            ->has('kbPublished', 1)
            ->where('kbPublished.0.related_service', $service->name));

    $this->actingAs($this->worker)->post("/it/kb/{$article->id}/view")->assertRedirect();
    $this->actingAs($this->worker)
        ->post("/it/kb/{$article->id}/helpful", ['helpful' => true])
        ->assertRedirect();
    expect(ItKbInteraction::query()->where('it_kb_article_id', $article->id)->count())->toBe(2)
        ->and($article->fresh()->deflection_count)->toBe(1);

    $this->actingAs($this->manager)
        ->post("/it/kb/{$article->id}/retire", ['reason' => 'Superseded by managed recovery.'])
        ->assertRedirect();
    expect($article->fresh()->status)->toBe('retired')
        ->and($article->fresh()->retired_at)->not->toBeNull();
});

test('site-scoped and agent-only knowledge never leaks to the wrong requester', function () {
    $allowed = Site::factory()->create();
    $other = Site::factory()->create();
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->worker->id,
        'primary_site_id' => $other->id,
    ]);
    ItKbArticle::factory()->published()->create([
        'audience' => 'specific_sites',
        'site_scope' => [$allowed->id],
    ]);
    ItKbArticle::factory()->published()->create([
        'audience' => 'it_agents',
    ]);

    $this->actingAs($this->worker)
        ->get('/it')
        ->assertInertia(fn ($page) => $page->has('kbPublished', 0));
});

test('public ticket replies create visible outbound delivery records and failed mail can be retried', function () {
    Notification::fake();
    $site = serviceOperationsAssignSite($this->manager);
    $ticket = ItTicket::factory()->create([
        'site_id' => $site->id,
        'requester_user_id' => $this->worker->id,
    ]);

    $this->actingAs($this->manager)->post("/it/tickets/{$ticket->id}/comments", [
        'body' => 'We have restored your access.',
        'is_internal' => false,
    ])->assertRedirect();

    $delivery = ItEmailDelivery::query()->sole();
    expect($delivery->it_ticket_id)->toBe($ticket->id)
        ->and($delivery->it_ticket_comment_id)->not->toBeNull()
        ->and($delivery->recipient_user_id)->toBe($this->worker->id)
        ->and($delivery->status)->toBe('queued')
        ->and($delivery->subject)->toContain($ticket->reference);

    app(ItEmailDeliveryService::class)->recordProviderStatus(
        $delivery->notification_uuid,
        'bounced',
        'Mailbox rejected the message.',
        'provider-123',
    );
    expect($delivery->fresh()->status)->toBe('bounced')
        ->and($delivery->fresh()->last_error)->toContain('rejected');
    $originalUuid = $delivery->notification_uuid;

    $this->actingAs($this->manager)
        ->post("/it/setup/email-deliveries/{$delivery->id}/retry")
        ->assertRedirect();
    $retry = ItEmailDelivery::query()->whereKeyNot($delivery->id)->sole();
    expect($delivery->fresh()->status)->toBe('retried')
        ->and($delivery->fresh()->notification_uuid)->toBe($originalUuid)
        ->and($delivery->fresh()->last_error)->toContain('rejected')
        ->and($retry->status)->toBe('queued')
        ->and($retry->retry_of_delivery_id)->toBe($delivery->id)
        ->and($retry->notification_uuid)->not->toBe($originalUuid)
        ->and($retry->retry_count)->toBe(1)
        ->and(AuditLog::query()->where('action', 'it.email.delivery.retried')->exists())->toBeTrue();
    Notification::assertSentTo($this->worker, TicketRepliedNotification::class);

    $this->actingAs($this->manager)
        ->post("/it/setup/email-deliveries/{$delivery->id}/retry")
        ->assertRedirect()
        ->assertSessionHas('error');
    expect(ItEmailDelivery::query()->count())->toBe(2);
});

test('every mail-capable IT notification exposes delivery tracking context', function () {
    foreach ([
        TicketApprovalNotification::class,
        TicketAssignedNotification::class,
        TicketCreatedNotification::class,
        TicketReopenedNotification::class,
        TicketRepliedNotification::class,
        TicketResolvedNotification::class,
        TicketSlaNotification::class,
        ItProvisioningCancelledNotification::class,
    ] as $notification) {
        expect(is_a($notification, TracksItEmailDelivery::class, true))->toBeTrue($notification);
    }
});

test('provisioning cancellation mail is visible and can be retried safely', function () {
    Notification::fake();
    $profile = HrEmployeeProfile::factory()->create([
        'user_id' => $this->worker->id,
    ]);
    $checklist = HrOnboardingChecklist::query()->create([
        'employee_profile_id' => $profile->id,
        'template_key' => 'support_worker:all',
        'status' => 'in_progress',
        'started_at' => now(),
        'due_date' => now()->addDays(20),
        'created_by' => $this->manager->id,
    ]);
    $task = HrOnboardingTask::query()->create([
        'checklist_id' => $checklist->id,
        'category' => 'it',
        'title' => 'Create Microsoft 365 account',
        'is_required' => true,
        'sort_order' => 1,
        'status' => 'pending',
    ]);
    $provisioning = ItProvisioningRequest::query()->create([
        'employee_profile_id' => $profile->id,
        'onboarding_task_id' => $task->id,
        'type' => 'account',
        'item' => $task->title,
        'assigned_to_user_id' => $this->manager->id,
        'status' => 'cancelled',
        'created_by' => $this->manager->id,
    ]);
    $deliveries = app(ItEmailDeliveryService::class);

    $deliveries->send(
        $this->manager,
        new ItProvisioningCancelledNotification($provisioning, $task, 'Duplicate request'),
    );
    $delivery = ItEmailDelivery::query()->sole();
    expect($delivery->it_provisioning_request_id)->toBe($provisioning->id)
        ->and($delivery->notification_type)->toBe('it_provisioning_cancelled')
        ->and($delivery->notification_context)->toMatchArray([
            'task_id' => $task->id,
            'reason' => 'Duplicate request',
        ]);

    $deliveries->recordProviderStatus($delivery->notification_uuid, 'failed', 'Provider unavailable.');
    $retry = $deliveries->retry($delivery, $this->manager);

    expect($delivery->fresh()->status)->toBe('retried')
        ->and($retry->it_provisioning_request_id)->toBe($provisioning->id)
        ->and($retry->retry_of_delivery_id)->toBe($delivery->id);
    Notification::assertSentTo($this->manager, ItProvisioningCancelledNotification::class);
});

test('local mail acceptance remains distinct from provider delivery and provider events are ordered', function () {
    Notification::fake();
    $ticket = ItTicket::factory()->create([
        'requester_user_id' => $this->worker->id,
    ]);
    $deliveries = app(ItEmailDeliveryService::class);
    $deliveries->send($this->worker, new TicketCreatedNotification($ticket, 'receipt'));
    $delivery = ItEmailDelivery::query()->sole();
    $notification = new TicketCreatedNotification($ticket, 'receipt');
    $notification->id = $delivery->notification_uuid;

    $deliveries->recordNotificationEvent(new NotificationSent(
        $this->worker,
        $notification,
        'mail',
        new class
        {
            public function getMessageId(): string
            {
                return 'provider-accepted-42';
            }
        },
    ));

    expect($delivery->fresh()->status)->toBe('accepted')
        ->and($delivery->fresh()->accepted_at)->not->toBeNull()
        ->and($delivery->fresh()->delivered_at)->toBeNull();

    $failedAt = now()->addMinute()->startOfSecond();
    $deliveries->recordProviderStatus(
        $delivery->notification_uuid,
        'failed',
        'Provider rejected after accepting the message.',
        null,
        $failedAt,
    );
    expect($delivery->fresh()->status)->toBe('failed')
        ->and($delivery->fresh()->provider_status_at?->equalTo($failedAt))->toBeTrue();

    $deliveries->recordProviderStatus(
        $delivery->notification_uuid,
        'delivered',
        null,
        null,
        $failedAt->copy()->subSecond(),
    );
    expect($delivery->fresh()->status)->toBe('failed');

    $deliveries->recordProviderStatus(
        $delivery->notification_uuid,
        'bounced',
        'Recipient rejected.',
        null,
        $failedAt,
    );
    expect($delivery->fresh()->status)->toBe('bounced');

    // Provider callbacks can beat Laravel's local NotificationSent event.
    // A late local acceptance must never regress provider-final state.
    $deliveries->recordNotificationEvent(new NotificationSent(
        $this->worker,
        $notification,
        'mail',
        null,
    ));
    expect($delivery->fresh()->status)->toBe('bounced');
});

test('every IT mail type creates a visible delivery and can be retried safely', function () {
    Notification::fake();
    $site = serviceOperationsAssignSite($this->manager);
    $ticket = ItTicket::factory()->create([
        'site_id' => $site->id,
        'requester_user_id' => $this->worker->id,
    ]);
    $notifications = [
        new TicketApprovalNotification($ticket, 'requested'),
        new TicketAssignedNotification($ticket),
        new TicketCreatedNotification($ticket, 'receipt'),
        new TicketReopenedNotification($ticket),
        new TicketRepliedNotification($ticket, 'requester'),
        new TicketResolvedNotification($ticket, 'requester'),
        new TicketSlaNotification($ticket, 'at_risk', 'resolution'),
    ];
    $deliveries = app(ItEmailDeliveryService::class);

    foreach ($notifications as $notification) {
        $deliveries->send($this->worker, $notification);
    }
    expect(ItEmailDelivery::query()->count())->toBe(7);

    foreach (ItEmailDelivery::query()->get() as $delivery) {
        $deliveries->recordProviderStatus($delivery->notification_uuid, 'failed', 'Provider unavailable.');
        $deliveries->retry($delivery, $this->manager);
    }
    expect(ItEmailDelivery::query()->count())->toBe(14)
        ->and(ItEmailDelivery::query()->where('status', 'retried')->count())->toBe(7)
        ->and(ItEmailDelivery::query()->where('status', 'queued')->count())->toBe(7);
});

test('the authenticated delivery callback records ordered bounces without exposing other deliveries', function () {
    config(['it.outbound_mail.status_secret' => 'delivery-secret']);
    $delivery = ItEmailDelivery::factory()->create(['status' => 'queued']);

    $this->postJson('/api/it/email/deliveries/status', [
        'notification_id' => $delivery->notification_uuid,
        'status' => 'bounced',
        'provider_message_id' => 'mail-42',
        'error' => 'Recipient rejected.',
    ], ['X-IT-Delivery-Secret' => 'delivery-secret'])->assertOk();

    expect($delivery->fresh()->status)->toBe('bounced')
        ->and($delivery->fresh()->provider_message_id)->toBe('mail-42');

    $this->postJson('/api/it/email/deliveries/status', [
        'notification_id' => $delivery->notification_uuid,
        'status' => 'delivered',
    ], ['X-IT-Delivery-Secret' => 'delivery-secret'])->assertOk();
    expect($delivery->fresh()->status)->toBe('bounced');

    $this->postJson('/api/it/email/deliveries/status', [
        'notification_id' => $delivery->notification_uuid,
        'status' => 'delivered',
    ])->assertForbidden();
});

test('existing IT schedules are named once and their runs are recorded from Laravel scheduler events', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_starts_with((string) $event->description, 'it.'));
    expect($events->pluck('description')->sort()->values()->all())->toBe([
        'it.check-sla',
        'it.close-resolved',
        'it.poll-mailbox',
    ])->and($events->every(fn ($event) => $event->withoutOverlapping && $event->onOneServer))->toBeTrue();

    $task = $events->firstWhere('description', 'it.check-sla');
    $recorder = app(ItAutomationRunRecorder::class);
    $recorder->starting(new ScheduledTaskStarting($task));
    $recorder->finished(new ScheduledTaskFinished($task, 1.25));

    $run = ItAutomationRun::query()->sole();
    expect($run->automation_key)->toBe('it.check-sla')
        ->and($run->status)->toBe('succeeded')
        ->and($run->runtime_ms)->toBe(1250);

    $recorder->starting(new ScheduledTaskStarting($task));
    $recorder->failed(new ScheduledTaskFailed($task, new RuntimeException('provider unavailable')));
    expect(ItAutomationRun::query()->where('status', 'failed')->whereNotNull('error_summary')->exists())->toBeTrue();
});

test('automation completions update the exact run when executions overlap', function () {
    $task = collect(app(Schedule::class)->events())->firstWhere('description', 'it.check-sla');
    $firstTask = clone $task;
    $secondTask = clone $task;
    $recorder = app(ItAutomationRunRecorder::class);

    $recorder->starting(new ScheduledTaskStarting($firstTask));
    $firstRun = ItAutomationRun::query()->latest('id')->firstOrFail();
    $recorder->starting(new ScheduledTaskStarting($secondTask));
    $secondRun = ItAutomationRun::query()->latest('id')->firstOrFail();
    $recorder->finished(new ScheduledTaskFinished($firstTask, 0.5));

    expect($firstRun->fresh()->status)->toBe('succeeded')
        ->and($secondRun->fresh()->status)->toBe('running');

    $recorder->failed(new ScheduledTaskFailed($secondTask, new RuntimeException('second run failed')));
    expect($secondRun->fresh()->status)->toBe('failed');
});

test('mailbox polling records the queued job outcome rather than scheduler dispatch', function () {
    $task = collect(app(Schedule::class)->events())->firstWhere('description', 'it.poll-mailbox');
    $recorder = app(ItAutomationRunRecorder::class);
    $recorder->starting(new ScheduledTaskStarting($task));
    $recorder->finished(new ScheduledTaskFinished($task, 0.1));
    expect(ItAutomationRun::query()->count())->toBe(0);

    app(PollItMailboxJob::class)->handle(app(InboundEmailIngestor::class), $recorder);
    $run = ItAutomationRun::query()->sole();
    expect($run->status)->toBe('succeeded')
        ->and($run->result_summary)->toMatchArray(['connections' => 0, 'failed' => 0]);
});

test('reports reconcile expanded operations metrics to drill-down filters', function () {
    $site = serviceOperationsAssignSite($this->manager);
    $service = ItService::factory()->create();
    ItTicket::factory()->create([
        'site_id' => $site->id,
        'status' => 'open',
        'source' => 'email',
        'it_service_id' => null,
        'queue_id' => null,
        'assigned_to_user_id' => null,
        'created_at' => now()->subDays(20),
    ]);
    $resolved = ItTicket::factory()->create([
        'site_id' => $site->id,
        'status' => 'resolved',
        'source' => 'portal',
        'it_service_id' => $service->id,
        'resolved_at' => now(),
        'reopened_count' => 1,
    ]);
    ItProblem::factory()->create([
        'ticket_id' => ItTicket::factory()->create(['site_id' => $site->id])->id,
    ]);
    ItChange::factory()->create([
        'ticket_id' => ItTicket::factory()->create(['site_id' => $site->id])->id,
        'validation_result' => 'successful',
        'validated_at' => now(),
    ]);
    ItMajorIncident::factory()->create([
        'ticket_id' => ItTicket::factory()->create(['site_id' => $site->id])->id,
        'declared_at' => now(),
        'restored_at' => now(),
    ]);
    ItAutomationRun::factory()->create(['automation_key' => 'it.check-sla', 'status' => 'failed']);

    $from = now()->subDays(30)->toDateString();
    $to = now()->toDateString();
    $data = $this->actingAs($this->manager)
        ->getJson("/it/reports/data?from={$from}&to={$to}")
        ->assertOk()
        ->json();
    expect($data['backlog_age'])->toHaveKeys(['under_2_days', 'days_2_to_7', 'days_8_to_30', 'over_30_days'])
        ->and($data['backlog_age']['days_8_to_30']['count'])->toBe(1)
        ->and($data['backlog_age']['days_8_to_30']['href'])->toContain('age=8_30')
        ->and($data['quality']['missing_service']['count'])->toBeGreaterThanOrEqual(1)
        ->and($data['quality']['missing_service']['href'])->toContain('missing=service')
        ->and($data['channels'])->toHaveKeys(['email', 'portal'])
        ->and($data['reopen_rate']['reopened'])->toBeGreaterThanOrEqual(1)
        ->and($data['reopen_rate']['href'])->toContain("resolved_from={$from}")
        ->and($data['reopen_rate']['href'])->toContain("resolved_to={$to}")
        ->and($data['channels']['email']['href'])->toContain("from={$from}")
        ->and($data['channels']['email']['href'])->toContain("to={$to}")
        ->and($data['service_reliability'][0]['href'])->toContain("from={$from}")
        ->and($data['major_incidents']['declared'])->toBe(1)
        ->and($data['change_success']['successful'])->toBe(1)
        ->and($data['recurring_problems']['total'])->toBe(1)
        ->and($data['automation_outcomes']['failed'])->toBeGreaterThanOrEqual(1)
        ->and($data['service_reliability'][0]['service'])->toBe($service->name);
});

test('first contact resolution includes tickets with internal notes and reconciles to its drill down', function () {
    $site = serviceOperationsAssignSite($this->manager);
    $ticket = ItTicket::factory()->resolved()->create([
        'site_id' => $site->id,
        'reopened_count' => 0,
    ]);
    ItTicketComment::factory()->internal()->create([
        'ticket_id' => $ticket->id,
        'author_user_id' => $this->manager->id,
    ]);
    $from = now()->subDay()->toDateString();
    $to = now()->toDateString();

    $metric = $this->actingAs($this->manager)
        ->getJson("/it/reports/data?from={$from}&to={$to}")
        ->assertOk()
        ->json('first_contact_resolution');
    expect($metric['resolved'])->toBe(1)
        ->and($metric['first_contact'])->toBe(1)
        ->and($metric['href'])->toContain('first_contact=1');

    $this->actingAs($this->manager)
        ->get($metric['href'])
        ->assertInertia(fn ($page) => $page
            ->where('tickets.total', 1)
            ->where('tickets.data.0.id', $ticket->id));
});

test('backlog age buckets are half open and never double count boundaries', function () {
    $site = serviceOperationsAssignSite($this->manager);
    $now = now()->startOfSecond();
    $this->travelTo($now);
    foreach ([2, 7, 30] as $days) {
        ItTicket::factory()->create([
            'site_id' => $site->id,
            'status' => 'open',
            'created_at' => $now->copy()->subDays($days),
        ]);
    }

    $data = $this->actingAs($this->manager)->getJson('/it/reports/data')->assertOk()->json('backlog_age');
    $sum = collect($data)->sum('count');
    expect($sum)->toBe(3)
        ->and($data['under_2_days']['count'])->toBe(1)
        ->and($data['days_2_to_7']['count'])->toBe(1)
        ->and($data['days_8_to_30']['count'])->toBe(1)
        ->and($data['over_30_days']['count'])->toBe(0);
});

test('setup shows an access-safe operations audit for channels automations and configuration gaps', function () {
    $site = serviceOperationsAssignSite($this->manager);
    $ticket = ItTicket::factory()->create(['site_id' => $site->id]);
    ItEmailDelivery::factory()->create([
        'it_ticket_id' => $ticket->id,
        'status' => 'bounced',
    ]);
    ItEmailDelivery::factory()->create(['status' => 'bounced']);
    ItAutomationRun::factory()->create(['automation_key' => 'it.poll-mailbox', 'status' => 'failed']);

    $this->actingAs($this->manager)
        ->get('/it/setup')
        ->assertInertia(fn ($page) => $page
            ->has('operationsAudit')
            ->where('operationsAudit.email.failed_or_bounced', 1)
            ->where('emailDeliveries.0.status', 'bounced')
            ->where('automationDefinitions.0.key', 'it.check-sla')
            ->has('automationRuns'));
});

test('the automation catalogue remains visible when console routes are not loaded', function () {
    $catalog = new ItAutomationScheduleCatalog(new Schedule(app()));
    $definitions = $catalog->definitions();

    expect($definitions)
        ->toHaveCount(3)
        ->and($definitions[0])->toMatchArray(['key' => 'it.check-sla', 'label' => 'SLA watchdog'])
        ->and($definitions[1])->toMatchArray(['key' => 'it.close-resolved'])
        ->and($definitions[2])->toMatchArray(['key' => 'it.poll-mailbox']);
});
