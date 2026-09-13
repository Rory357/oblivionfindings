<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingTask;
use App\Domain\Hr\Notifications\ItProvisioningCancelledNotification;
use App\Domain\It\Contracts\TracksItEmailDelivery;
use App\Domain\It\InboundEmailIngestor;
use App\Domain\It\Services\ItAutomationOperationsPresenter;
use App\Domain\It\Services\ItAutomationRunDiagnostics;
use App\Domain\It\Services\ItAutomationRunOutcome;
use App\Domain\It\Services\ItAutomationRunRecorder;
use App\Domain\It\Services\ItAutomationScheduleCatalog;
use App\Domain\It\Services\ItEmailDeliveryFailure;
use App\Domain\It\Services\ItEmailDeliveryService;
use App\Jobs\PollItMailboxJob;
use App\Mail\MailNotSubmitted;
use App\Mail\MailSubmissionRejected;
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
use App\Models\Permission;
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
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\Events\NotificationFailed;
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
    $this->manager->permissionOverrides()->syncWithoutDetaching(
        Permission::query()->whereIn('key', ['it.knowledge.author', 'it.knowledge.review'])
            ->pluck('id')->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all(),
    );
    $this->manager = $this->manager->fresh();
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
    foreach ([$this->manager, $owner] as $knowledgeActor) {
        $knowledgeActor->permissionOverrides()->syncWithoutDetaching(
            Permission::query()->whereIn('key', ['it.knowledge.author', 'it.knowledge.review'])
                ->pluck('id')->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all(),
        );
    }
    $this->manager = $this->manager->fresh();
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
    // HTTP requests execute their post-response jobs. Re-running the recovery
    // drain must not send either the original or its retry a second time.
    Notification::assertSentToTimes($this->worker, TicketRepliedNotification::class, 2);
    expect(app(ItEmailDeliveryService::class)->dispatchPending(deliveryId: $retry->id))->toBe(0);
    Notification::assertSentToTimes($this->worker, TicketRepliedNotification::class, 2);

    $this->actingAs($this->manager)
        ->post("/it/setup/email-deliveries/{$delivery->id}/retry")
        ->assertRedirect()
        ->assertSessionHas('error');
    expect(ItEmailDelivery::query()->count())->toBe(2);
});

test('delivery diagnostics never store arbitrary new failures or redisclose legacy provider messages', function () {
    Notification::fake();
    $site = serviceOperationsAssignSite($this->manager);
    $ticket = ItTicket::factory()->create(['site_id' => $site->id, 'requester_user_id' => $this->worker->id]);
    $service = app(ItEmailDeliveryService::class);
    $private = 'PRIVATE_PROVIDER_SECRET_AND_BODY';
    $delivery = ItEmailDelivery::factory()->create(['it_ticket_id' => $ticket->id, 'recipient_user_id' => $this->worker->id]);
    $service->recordProviderStatus($delivery->notification_uuid, 'failed', $private);
    expect($delivery->fresh()->getRawOriginal('last_error'))->toBe(ItEmailDeliveryFailure::MESSAGES['provider_failed']);
    $delivery->update(['last_error' => $private]);
    expect($delivery->fresh()->toArray())->not->toHaveKey('last_error');
    $this->actingAs($this->manager)->get('/it/setup?tab=operations')->assertDontSee($private)
        ->assertInertia(fn ($page) => $page->where('emailDeliveries.0.failure_category', 'legacy_failure')
            ->where('emailDeliveries.0.last_error', ItEmailDeliveryFailure::MESSAGES['legacy_failure']));
    // Presentation does not destructively rewrite historical evidence.
    expect($delivery->fresh()->getRawOriginal('last_error'))->toBe($private);
    $service->recordProviderStatus($delivery->notification_uuid, 'bounced', $private);
    expect($delivery->fresh()->getRawOriginal('last_error'))->toBe(ItEmailDeliveryFailure::MESSAGES['provider_bounced']);
});

test('transport failures keep safe categories and preserve uncertain sending and provider outcomes', function () {
    $site = serviceOperationsAssignSite($this->manager);
    $ticket = ItTicket::factory()->create(['site_id' => $site->id, 'requester_user_id' => $this->worker->id]);
    $service = app(ItEmailDeliveryService::class);
    foreach ([
        [new MailNotSubmitted('PRIVATE local preflight'), 'queued', 'not_submitted', 'failed'],
        [new MailSubmissionRejected('PRIVATE provider body'), 'sending', 'submission_rejected', 'failed'],
        [new RuntimeException('PRIVATE SMTP password'), 'queued', 'dispatch_failed', 'failed'],
        [new RuntimeException('PRIVATE unknown acceptance'), 'sending', 'outcome_unknown', 'sending'],
    ] as [$exception, $status, $category, $expectedStatus]) {
        $delivery = ItEmailDelivery::factory()->create(['it_ticket_id' => $ticket->id, 'recipient_user_id' => $this->worker->id,
            'status' => $status, 'sending_at' => $status === 'sending' ? now() : null]);
        $notification = new TicketCreatedNotification($ticket);
        $notification->id = $delivery->notification_uuid;
        $service->recordNotificationEvent(new NotificationFailed(
            $this->worker, $notification, 'mail', ['exception' => $exception],
        ));
        expect($delivery->fresh()->status)->toBe($expectedStatus)
            ->and($delivery->fresh()->getRawOriginal('last_error'))->toBe(ItEmailDeliveryFailure::MESSAGES[$category]);
    }
});

test('JSON delivery retry validates the current actor and returns one durable retry receipt', function () {
    $site = serviceOperationsAssignSite($this->manager);
    $ticket = ItTicket::factory()->create([
        'site_id' => $site->id,
        'requester_user_id' => $this->worker->id,
    ]);
    $delivery = ItEmailDelivery::factory()->create([
        'it_ticket_id' => $ticket->id,
        'recipient_user_id' => $this->worker->id,
        'recipient_email' => $this->worker->email,
        'notification_type' => 'ticket_created',
        'audience' => 'receipt',
        'notification_context' => [],
        'status' => 'failed',
        'failed_at' => now(),
    ]);
    $url = route('it.setup.email-deliveries.retry', $delivery);

    $this->actingAs($this->manager)->postJson($url, [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('expected_actor_id');
    $this->actingAs($this->manager)->postJson($url, ['expected_actor_id' => $this->worker->id])
        ->assertConflict();
    expect(ItEmailDelivery::query()->count())->toBe(1)
        ->and($delivery->fresh()->status)->toBe('failed');

    $response = $this->actingAs($this->manager)->postJson($url, ['expected_actor_id' => $this->manager->id])
        ->assertSuccessful()
        ->assertJsonPath('data.original_delivery_id', $delivery->id)
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.actor_id', $this->manager->id)
        ->assertJsonStructure(['data' => ['original_delivery_id', 'retry_delivery_id', 'status', 'actor_id']]);
    $retryId = $response->json('data.retry_delivery_id');
    expect($retryId)->toBeInt()
        ->and(ItEmailDelivery::query()->findOrFail($retryId)->retry_of_delivery_id)->toBe($delivery->id)
        ->and($delivery->fresh()->status)->toBe('retried');

    $this->actingAs($this->manager)->postJson($url, ['expected_actor_id' => $this->manager->id])
        ->assertConflict();
    expect(ItEmailDelivery::query()->count())->toBe(2);
});

test('JSON delivery retry conceals a failed delivery outside the actor work scope', function () {
    $site = serviceOperationsAssignSite($this->manager);
    $restrictedActor = serviceOperationsUser();
    serviceOperationsAssignSite($restrictedActor, Site::factory()->create());
    $ticket = ItTicket::factory()->create([
        'site_id' => $site->id,
        'requester_user_id' => $this->worker->id,
    ]);
    $delivery = ItEmailDelivery::factory()->create([
        'it_ticket_id' => $ticket->id,
        'recipient_user_id' => $this->worker->id,
        'recipient_email' => $this->worker->email,
        'notification_type' => 'ticket_created',
        'audience' => 'receipt',
        'notification_context' => [],
        'status' => 'failed',
        'failed_at' => now(),
    ]);

    $this->actingAs($restrictedActor)
        ->postJson(route('it.setup.email-deliveries.retry', $delivery), ['expected_actor_id' => $restrictedActor->id])
        ->assertNotFound();
    expect(ItEmailDelivery::query()->count())->toBe(1)
        ->and($delivery->fresh()->status)->toBe('failed');
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
    Notification::assertNothingSent();
    $deliveries->dispatchPending(deliveryId: $retry->id);
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

test('provider delivery status rejects excessive future skew without poisoning later ordering', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-31 12:00:00', 'UTC'));

    try {
        $deliveries = app(ItEmailDeliveryService::class);
        $receipt = now()->toImmutable()->utc();
        $boundary = ItEmailDelivery::factory()->create(['status' => 'queued']);
        $mutableBoundary = Carbon::instance(
            $receipt->addSeconds(300)->setTimezone('Pacific/Auckland'),
        );
        $mutableBoundaryBefore = [
            $mutableBoundary->format('Y-m-d H:i:s.uP'),
            $mutableBoundary->getTimezone()->getName(),
        ];
        $deliveries->recordProviderStatus(
            $boundary->notification_uuid,
            'delivered',
            null,
            'provider-boundary',
            $mutableBoundary,
        );

        expect($boundary->fresh()->status)->toBe('delivered')
            ->and($boundary->fresh()->provider_status_at?->equalTo($receipt->addSeconds(300)))->toBeTrue()
            ->and([
                $mutableBoundary->format('Y-m-d H:i:s.uP'),
                $mutableBoundary->getTimezone()->getName(),
            ])->toBe($mutableBoundaryBefore);

        $receiptDelivery = ItEmailDelivery::factory()->create(['status' => 'queued']);
        $deliveries->recordProviderStatus(
            $receiptDelivery->notification_uuid,
            'delivered',
            null,
            'provider-receipt',
        );
        expect($receiptDelivery->fresh()->provider_status_at?->equalTo($receipt))->toBeTrue()
            ->and($receiptDelivery->fresh()->delivered_at?->equalTo($receipt))->toBeTrue();

        $delivery = ItEmailDelivery::factory()->create(['status' => 'queued']);
        $rawBeforeRejection = $delivery->fresh()->getRawOriginal();
        expect(fn () => $deliveries->recordProviderStatus(
            $delivery->notification_uuid,
            'failed',
            'Forged future failure.',
            'provider-future',
            $receipt->addSeconds(300)->addMicrosecond(),
        ))->toThrow(DomainException::class, 'timestamp is too far in the future');

        $delivery->refresh();
        expect($delivery->getRawOriginal())->toBe($rawBeforeRejection);

        $deliveries->recordProviderStatus(
            $delivery->notification_uuid,
            'delivered',
            null,
            'provider-current',
            $receipt,
        );

        expect($delivery->fresh()->status)->toBe('delivered')
            ->and($delivery->fresh()->provider_message_id)->toBe('provider-current')
            ->and($delivery->fresh()->provider_status_at?->equalTo($receipt))->toBeTrue();
    } finally {
        Carbon::setTestNow();
    }
});

test('authenticated delivery callbacks reject excessive future skew without mutating the delivery', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-31 12:00:00', 'UTC'));

    try {
        config(['it.outbound_mail.status_secret' => 'delivery-secret']);
        $receipt = now()->toImmutable()->utc();
        $boundary = ItEmailDelivery::factory()->create(['status' => 'queued']);
        $this->postJson('/api/it/email/deliveries/status', [
            'notification_id' => $boundary->notification_uuid,
            'status' => 'delivered',
            'provider_message_id' => 'provider-boundary',
            'occurred_at' => $receipt->addSeconds(300)->format('Y-m-d\TH:i:s.uP'),
        ], ['X-IT-Delivery-Secret' => 'delivery-secret'])->assertOk();

        expect($boundary->fresh()->status)->toBe('delivered')
            ->and($boundary->fresh()->provider_status_at?->equalTo($receipt->addSeconds(300)))->toBeTrue();

        $offsetBoundary = ItEmailDelivery::factory()->create(['status' => 'queued']);
        $this->postJson('/api/it/email/deliveries/status', [
            'notification_id' => $offsetBoundary->notification_uuid,
            'status' => 'delivered',
            'provider_message_id' => 'provider-offset-boundary',
            'occurred_at' => $receipt->addSeconds(300)
                ->setTimezone('Pacific/Auckland')
                ->format('Y-m-d\TH:i:s.uP'),
        ], ['X-IT-Delivery-Secret' => 'delivery-secret'])->assertOk();

        expect($offsetBoundary->fresh()->provider_status_at?->equalTo($receipt->addSeconds(300)))->toBeTrue();

        $millisecond = ItEmailDelivery::factory()->create(['status' => 'queued']);
        $this->postJson('/api/it/email/deliveries/status', [
            'notification_id' => $millisecond->notification_uuid,
            'status' => 'delivered',
            'provider_message_id' => 'provider-millisecond',
            'occurred_at' => '2026-08-31T12:04:59.123Z',
        ], ['X-IT-Delivery-Secret' => 'delivery-secret'])->assertOk();

        expect($millisecond->fresh()->status)->toBe('delivered')
            ->and($millisecond->fresh()->provider_message_id)->toBe('provider-millisecond');

        $delivery = ItEmailDelivery::factory()->create(['status' => 'queued']);
        $rawBeforeRejections = $delivery->fresh()->getRawOriginal();

        $this->postJson('/api/it/email/deliveries/status', [
            'notification_id' => $delivery->notification_uuid,
            'status' => 'failed',
            'provider_message_id' => 'provider-future',
            'occurred_at' => $receipt->addSeconds(300)->addMicrosecond()->format('Y-m-d\TH:i:s.uP'),
            'error' => 'Forged future failure.',
        ], ['X-IT-Delivery-Secret' => 'delivery-secret'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('occurred_at');

        foreach (['+300 seconds', '2026-08-31T12:05:00.000000'] as $ambiguousTimestamp) {
            $this->postJson('/api/it/email/deliveries/status', [
                'notification_id' => $delivery->notification_uuid,
                'status' => 'failed',
                'provider_message_id' => 'provider-ambiguous',
                'occurred_at' => $ambiguousTimestamp,
                'error' => 'Ambiguous provider time.',
            ], ['X-IT-Delivery-Secret' => 'delivery-secret'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('occurred_at');
        }

        expect($delivery->fresh()->getRawOriginal())->toBe($rawBeforeRejections);

        $this->postJson('/api/it/email/deliveries/status', [
            'notification_id' => $delivery->notification_uuid,
            'status' => 'delivered',
            'provider_message_id' => 'provider-current',
            'occurred_at' => $receipt->format('Y-m-d\TH:i:s.uP'),
        ], ['X-IT-Delivery-Secret' => 'delivery-secret'])->assertOk();

        expect($delivery->fresh()->status)->toBe('delivered')
            ->and($delivery->fresh()->provider_message_id)->toBe('provider-current')
            ->and($delivery->fresh()->provider_status_at?->equalTo($receipt))->toBeTrue();
    } finally {
        Carbon::setTestNow();
    }
});

test('every IT mail type creates a visible delivery and can be retried safely', function () {
    Notification::fake();
    $site = serviceOperationsAssignSite($this->manager);
    $ticket = ItTicket::factory()->create([
        'site_id' => $site->id,
        'requester_user_id' => $this->worker->id,
        'assigned_to_user_id' => $this->manager->id,
    ]);
    $ticket->watchers()->attach($this->worker->id); // Reopen notices require a current watcher or assignee.
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
        $recipient = $notification instanceof TicketSlaNotification || $notification instanceof TicketApprovalNotification || $notification instanceof TicketAssignedNotification
            ? $this->manager : $this->worker;
        $deliveries->send($recipient, $notification);
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
        'it.check-approval-deadlines',
        'it.check-sla',
        'it.close-resolved',
        'it.dispatch-notifications',
        'it.poll-mailbox',
        'it.retry-attachment-cleanup',
    ])->and($events->every(fn ($event) => $event->withoutOverlapping && $event->onOneServer))->toBeTrue();

    $task = $events->firstWhere('description', 'it.close-resolved');
    $task->exitCode = 0;
    $recorder = app(ItAutomationRunRecorder::class);
    $recorder->starting(new ScheduledTaskStarting($task));
    $recorder->finished(new ScheduledTaskFinished($task, 1.25));

    $run = ItAutomationRun::query()->sole();
    expect($run->automation_key)->toBe('it.close-resolved')
        ->and($run->status)->toBe('succeeded')
        ->and($run->runtime_ms)->toBe(1250);

    $recorder->starting(new ScheduledTaskStarting($task));
    $recorder->failed(new ScheduledTaskFailed($task, new RuntimeException('provider unavailable')));
    expect(ItAutomationRun::query()->where('status', 'failed')->whereNotNull('error_summary')->exists())->toBeTrue();
});

test('SLA freshness reads only its own run evidence and agrees with the canonical catalogue', function () {
    $this->travelTo(Carbon::parse('2026-09-09 01:20:00', 'UTC'));
    $catalogue = app(ItAutomationScheduleCatalog::class);

    expect($catalogue->freshnessFor('it.check-sla')['state'])->toBe('unmeasured')
        ->and($catalogue->freshnessFor('it.unknown'))->toBeNull();

    ItAutomationRun::factory()->create([
        'automation_key' => 'it.check-sla',
        'status' => 'succeeded',
        'started_at' => now()->subHours(2)->subMinute(),
        'finished_at' => now()->subHours(2),
    ]);
    ItAutomationRun::factory()->create([
        'automation_key' => 'it.poll-mailbox',
        'status' => 'failed',
    ]);
    expect($catalogue->freshnessFor('it.check-sla')['state'])->toBe('stale');

    ItAutomationRun::factory()->create([
        'automation_key' => 'it.check-sla',
        'status' => 'succeeded',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);
    $freshness = $catalogue->freshnessFor('it.check-sla', now());
    $definition = collect($catalogue->definitions())->firstWhere('key', 'it.check-sla');

    expect($freshness['state'])->toBe('fresh')
        ->and($freshness)->toBe($definition['freshness'])
        ->and(ItAutomationRun::query()->count())->toBe(3);
});

test('automation completions update the exact run when executions overlap', function () {
    $task = collect(app(Schedule::class)->events())->firstWhere('description', 'it.close-resolved');
    $firstTask = clone $task;
    $firstTask->exitCode = 0;
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

test('a nonzero scheduler exit and its following failure event record one safe failed run', function () {
    $task = clone collect(app(Schedule::class)->events())->firstWhere('description', 'it.close-resolved');
    $recorder = app(ItAutomationRunRecorder::class);
    $recorder->starting(new ScheduledTaskStarting($task));
    $task->exitCode = 1;
    $recorder->finished(new ScheduledTaskFinished($task, 0.75));
    $recorder->failed(new ScheduledTaskFailed($task, new RuntimeException('Bearer private-secret /private/path?token=secret')));

    $run = ItAutomationRun::query()->sole();
    expect($run->status)->toBe('failed')->and($run->runtime_ms)->toBe(750)
        ->and($run->error_summary)->toBe(ItAutomationRunDiagnostics::failure('it.close-resolved'))
        ->and(app(ItAutomationScheduleCatalog::class)->freshnessFor('it.close-resolved')['last_success_at'])->toBeNull();

    // Reusing a scheduler task on the next tick must still create a new attempt.
    $recorder->starting(new ScheduledTaskStarting($task));
    $task->exitCode = 0;
    $recorder->finished(new ScheduledTaskFinished($task, 0.25));
    expect(ItAutomationRun::query()->count())->toBe(2)
        ->and($run->fresh()->status)->toBe('failed')
        ->and(ItAutomationRun::query()->latest('id')->first()->status)->toBe('succeeded');
});

test('scheduler completion without an exit result never fabricates a successful check', function () {
    $task = clone collect(app(Schedule::class)->events())->firstWhere('description', 'it.close-resolved');
    $task->exitCode = null;
    $recorder = app(ItAutomationRunRecorder::class);
    $recorder->starting(new ScheduledTaskStarting($task));
    $recorder->finished(new ScheduledTaskFinished($task, 0.01));
    expect(ItAutomationRun::query()->sole()->status)->toBe('failed');
});

test('stale automation completions preserve the first terminal outcome and safe stored diagnostics', function () {
    $recorder = app(ItAutomationRunRecorder::class);
    $run = $recorder->begin('it.check-sla');
    $stale = $run->fresh();
    $recorder->completeRun($run, 'failed', 25, 'private-secret', ['checked' => 2]);
    $finishedAt = $run->finished_at;
    $this->travel(10)->seconds();
    $recorder->completeRun($stale, 'succeeded', 200, 'another-secret', ['checked' => 99]);
    expect($stale->status)->toBe('failed')->and($stale->runtime_ms)->toBe(25)
        ->and($stale->finished_at->equalTo($finishedAt))->toBeTrue()
        ->and($stale->result_summary)->toBe(['checked' => 2])
        ->and($stale->error_summary)->toBe(ItAutomationRunDiagnostics::failure('it.check-sla'))
        ->and($stale->toArray())->not->toHaveKeys(['error_summary', 'result_summary']);
    expect(fn () => $recorder->completeRun($stale, 'running'))->toThrow(InvalidArgumentException::class);
});

test('operations conceals legacy scheduler exception text while preserving useful safe recovery guidance', function () {
    $run = ItAutomationRun::factory()->create([
        'automation_key' => 'it.close-resolved', 'status' => 'failed',
        'error_summary' => 'Bearer private-secret /private/path?token=secret',
        'result_summary' => ['private' => 'private-payload'],
    ]);
    $this->actingAs($this->manager)->get('/it/setup?tab=operations')
        ->assertInertia(fn ($page) => $page
            ->where('automationRuns.0.id', $run->id)
            ->where('automationRuns.0.error_summary', ItAutomationRunDiagnostics::failure('it.close-resolved'))
            ->missing('automationRuns.0.result_summary'));
    expect($run->fresh()->error_summary)->toContain('private-secret');
    $this->actingAs($this->worker)->get('/it/setup?tab=operations')->assertForbidden();
});

test('mailbox freshness requires a complete nonempty poll rather than a successful bounded batch', function () {
    $complete = ItAutomationRun::factory()->create(['automation_key' => 'it.poll-mailbox', 'status' => 'succeeded',
        'started_at' => now()->subMinutes(3), 'finished_at' => now()->subMinutes(2),
        'result_summary' => ['connections' => 2, 'failed' => 0, 'pending' => 0, 'skipped' => 0]]);
    foreach ([
        ['connections' => 2, 'failed' => 0, 'pending' => 1, 'skipped' => 0],
        ['connections' => 2, 'failed' => 0, 'pending' => 0, 'skipped' => 2],
        ['connections' => 0, 'failed' => 0, 'pending' => 0, 'skipped' => 0],
        ['connections' => '2', 'failed' => 0, 'pending' => 0, 'skipped' => 0],
        ['connections' => 2, 'failed' => 0, 'skipped' => 0],
        ['connections' => 2, 'failed' => -1, 'pending' => 0, 'skipped' => 0],
    ] as $summary) {
        $run = ItAutomationRun::factory()->create(['automation_key' => 'it.poll-mailbox', 'status' => 'succeeded',
            'started_at' => now()->subMinute(), 'finished_at' => now(), 'result_summary' => $summary]);
        expect(ItAutomationRunOutcome::state($run))->not->toBe('succeeded');
        $freshness = app(ItAutomationScheduleCatalog::class)->freshnessFor('it.poll-mailbox');
        expect($freshness['last_success_at'])->toBe($complete->finished_at->toIso8601String())
            ->and($freshness['state'])->toBe('unmeasured');
    }
    $recovered = ItAutomationRun::factory()->create(['automation_key' => 'it.poll-mailbox', 'status' => 'succeeded',
        'started_at' => now(), 'finished_at' => now(), 'result_summary' => ['connections' => 1, 'failed' => 0, 'pending' => 0, 'skipped' => 0]]);
    expect(app(ItAutomationScheduleCatalog::class)->freshnessFor('it.poll-mailbox')['state'])->toBe('fresh')
        ->and(ItAutomationRunOutcome::state($recovered))->toBe('succeeded');
});

test('mailbox outcomes reject inconsistent counts and distinguish failed pending skipped and no work', function () {
    foreach ([
        ['failed', ['connections' => 2, 'failed' => 1, 'pending' => 1, 'skipped' => 0]],
        ['pending', ['connections' => 2, 'failed' => 0, 'pending' => 1, 'skipped' => 1]],
        ['skipped', ['connections' => 2, 'failed' => 0, 'pending' => 0, 'skipped' => 1]],
        ['no_work', ['connections' => 0, 'failed' => 0, 'pending' => 0, 'skipped' => 0]],
        ['unknown', ['connections' => 1, 'failed' => 0, 'pending' => 2, 'skipped' => 0]],
        ['unknown', ['connections' => true, 'failed' => 0, 'pending' => 0, 'skipped' => 0]],
    ] as [$expected, $summary]) {
        $run = new ItAutomationRun(['automation_key' => 'it.poll-mailbox', 'status' => 'succeeded', 'finished_at' => now(), 'result_summary' => $summary]);
        expect(ItAutomationRunOutcome::state($run))->toBe($expected);
    }
});

test('automation history paginates all retained executions and preserves the selected period', function () {
    ItAutomationRun::factory()->count(27)->create(['automation_key' => 'it.close-resolved', 'status' => 'succeeded', 'started_at' => now(), 'finished_at' => now()]);
    ItAutomationRun::factory()->create(['automation_key' => 'it.close-resolved', 'status' => 'failed', 'started_at' => now()->subDays(5)]);
    $from = now()->toDateString();
    $this->actingAs($this->manager)->get('/it/setup?tab=operations&automation_from='.$from)
        ->assertInertia(fn ($page) => $page->where('operationsAudit.automation_history.total', 27)
            ->has('operationsAudit.automation_history.rows', 25)
            ->where('operationsAudit.automation_history.links.3.url', '/it/setup?tab=operations&automation_from='.$from.'&automation_page=2'));
    $this->get('/it/setup?tab=operations&automation_from='.$from.'&automation_page=99')
        ->assertInertia(fn ($page) => $page->where('operationsAudit.automation_history.page', 2)
            ->has('operationsAudit.automation_history.rows', 2)
            ->where('operationsAudit.automation_history.links.3.url', null));
});

test('automation recovery respects current mailbox authority and never serializes private run payloads', function () {
    $run = ItAutomationRun::factory()->create(['automation_key' => 'it.poll-mailbox', 'status' => 'succeeded', 'finished_at' => now(),
        'error_summary' => 'private-error', 'result_summary' => ['connections' => 2, 'failed' => 0, 'pending' => 1, 'skipped' => 0, 'private' => 'private-payload']]);
    $presenter = app(ItAutomationOperationsPresenter::class);
    $without = $presenter->operations($this->manager);
    expect($without['rows'][0])->toMatchArray(['id' => $run->id, 'outcome' => 'pending', 'execution_status' => 'succeeded', 'mailbox_counts' => null, 'recovery_url' => null]);
    $admin = serviceOperationsUser('admin');
    $with = $presenter->operations($admin);
    expect($with['rows'][0]['mailbox_counts'])->toMatchArray(['connections' => 2, 'failed' => 0, 'skipped' => 0, 'pending' => 1])
        ->and($with['rows'][0]['recovery_url'])->toBe(route('settings.it-mailbox', absolute: false))
        ->and(json_encode($with))->not->toContain('private-payload', 'private-error');
    expect($presenter->operations($this->worker))->toMatchArray(['can_view' => false, 'rows' => [], 'total' => null]);
});

test('unfinished automation totals cover the whole period instead of the current page', function () {
    $run = ItAutomationRun::factory()->create(['automation_key' => 'it.close-resolved', 'status' => 'running', 'started_at' => now()->subDay(), 'finished_at' => null]);
    ItAutomationRun::factory()->count(26)->create(['automation_key' => 'it.close-resolved', 'status' => 'skipped', 'finished_at' => now()]);
    $history = app(ItAutomationOperationsPresenter::class)->operations($this->manager);
    expect($history['unfinished'])->toBe(1)->and($history['oldest_unfinished_at'])->toBe($run->started_at->toIso8601String())
        ->and(collect($history['rows'])->pluck('id')->contains($run->id))->toBeFalse();
});

test('automation search covers retained pages and keeps its date period and query in navigation', function () {
    ItAutomationRun::factory()->count(27)->create(['automation_key' => 'it.close-resolved', 'status' => 'skipped', 'started_at' => now(), 'finished_at' => now()]);
    ItAutomationRun::factory()->create(['automation_key' => 'it.poll-mailbox', 'status' => 'failed', 'started_at' => now()]);
    ItAutomationRun::factory()->create(['automation_key' => 'it.close-resolved', 'status' => 'failed', 'started_at' => now()->subDays(3)]);
    $from = now()->toDateString();
    $this->actingAs($this->manager)->get('/it/setup?tab=operations&automation_from='.$from.'&q=Close%20resolved')
        ->assertInertia(fn ($page) => $page->where('operationsAudit.automation_history.total', 27)
            ->has('operationsAudit.automation_history.rows', 25)
            ->where('operationsAudit.automation_history.links.3.url', '/it/setup?tab=operations&automation_from='.$from.'&q=Close+resolved&automation_page=2'));
    $this->get('/it/setup?tab=operations&automation_from='.$from.'&q=Close%20resolved&automation_page=2')
        ->assertInertia(fn ($page) => $page->where('operationsAudit.automation_history.total', 27)
            ->where('operationsAudit.automation_history.page', 2)->has('operationsAudit.automation_history.rows', 2));
    $this->get('/it/setup?tab=operations&q=zz-no-matching-automation')
        ->assertInertia(fn ($page) => $page->where('operationsAudit.automation_history.total', 0)
            ->where('operationsAudit.automation_history.unfinished', 0)
            ->where('operationsAudit.automation_history.oldest_unfinished_at', null)
            ->has('operationsAudit.automation_history.rows', 0));
});

test('search outcome predicates agree with projected mailbox and legacy execution evidence', function () {
    $base = ['automation_key' => 'it.poll-mailbox', 'status' => 'succeeded', 'finished_at' => now()];
    $cases = [
        [...$base, 'result_summary' => ['connections' => 1, 'failed' => 0, 'skipped' => 0, 'pending' => 0]],
        [...$base, 'result_summary' => ['connections' => 1, 'failed' => 0, 'skipped' => 0, 'pending' => 1]],
        [...$base, 'result_summary' => ['connections' => 1, 'failed' => 0, 'skipped' => 1, 'pending' => 0]],
        [...$base, 'result_summary' => ['connections' => 1, 'failed' => 1, 'skipped' => 0, 'pending' => 0]],
        [...$base, 'result_summary' => ['connections' => 0, 'failed' => 0, 'skipped' => 0, 'pending' => 0]],
        [...$base, 'result_summary' => ['connections' => 1, 'failed' => 0, 'skipped' => 1, 'pending' => 1]],
        [...$base, 'result_summary' => ['connections' => '1', 'failed' => 0, 'skipped' => 0, 'pending' => 0]],
        [...$base, 'result_summary' => ['connections' => true, 'failed' => 0, 'skipped' => 0, 'pending' => 0]],
        [...$base, 'result_summary' => ['connections' => 1, 'failed' => -1, 'skipped' => 0, 'pending' => 0]],
        [...$base, 'result_summary' => ['connections' => 1]],
        [...$base, 'result_summary' => null],
        [...$base, 'result_summary' => ['connections' => ['private' => 'payload'], 'failed' => 0, 'skipped' => 0, 'pending' => 0]],
        [...$base, 'automation_key' => 'it.close-resolved'],
        [...$base, 'status' => 'running', 'finished_at' => null],
        [...$base, 'status' => 'running'],
        [...$base, 'finished_at' => null],
        [...$base, 'status' => 'failed'],
        [...$base, 'status' => 'skipped'],
    ];
    $runs = collect($cases)->map(fn ($attributes) => ItAutomationRun::factory()->create($attributes));
    foreach (['succeeded', 'failed', 'pending', 'running', 'skipped', 'no_work', 'unknown'] as $state) {
        $expected = $runs->filter(fn ($run) => ItAutomationRunOutcome::state($run) === $state)->pluck('id')->sort()->values()->all();
        $actual = ItAutomationRunOutcome::whereState(ItAutomationRun::query(), [$state])->orderBy('id')->pluck('id')->all();
        expect($actual)->toBe($expected);
    }
    $pending = app(ItAutomationOperationsPresenter::class)->operations($this->manager, ['q' => 'Mailbox scan pending']);
    expect($pending['total'])->toBe(1)->and($pending['rows'][0]['outcome'])->toBe('pending');
});

test('automation search cannot probe private error payloads or unknown command names', function () {
    $run = ItAutomationRun::factory()->create(['automation_key' => 'it.private-secret-marker', 'status' => 'failed',
        'error_summary' => 'private-secret-marker', 'result_summary' => ['private' => 'private-secret-marker']]);
    $presenter = app(ItAutomationOperationsPresenter::class);
    foreach (['private-secret-marker', '%', "' OR 1=1 --"] as $query) {
        expect($presenter->operations($this->manager, ['q' => $query]))->toMatchArray(['total' => 0, 'rows' => []]);
    }
    $byId = $presenter->operations($this->manager, ['q' => 'Run '.$run->id]);
    expect($byId['total'])->toBe(1)->and($byId['rows'][0]['id'])->toBe($run->id)
        ->and(json_encode($byId))->not->toContain('private-secret-marker');
    expect($presenter->operations($this->worker, ['q' => 'Run '.$run->id]))->toMatchArray(['can_view' => false, 'total' => null, 'rows' => []]);
    $this->actingAs($this->worker)->get('/it/setup?tab=operations&q=Failed')->assertForbidden();
});

test('searched unfinished totals and oldest evidence belong to the matched automation', function () {
    ItAutomationRun::factory()->create(['automation_key' => 'it.check-sla', 'status' => 'running', 'started_at' => now()->subDays(4), 'finished_at' => null]);
    $matched = ItAutomationRun::factory()->create(['automation_key' => 'it.close-resolved', 'status' => 'running', 'started_at' => now()->subDay(), 'finished_at' => null]);
    $history = app(ItAutomationOperationsPresenter::class)->operations($this->manager, ['q' => 'Close resolved']);
    expect($history['total'])->toBe(1)->and($history['unfinished'])->toBe(1)
        ->and($history['oldest_unfinished_at'])->toBe($matched->started_at->toIso8601String());
});

test('the automation catalogue remains visible when console routes are not loaded', function () {
    $catalog = new ItAutomationScheduleCatalog(new Schedule(app()));
    $definitions = $catalog->definitions();

    expect($definitions)
        ->toHaveCount(6)
        ->and($definitions[0])->toMatchArray(['key' => 'it.check-sla', 'label' => 'SLA watchdog'])
        ->and($definitions[1])->toMatchArray(['key' => 'it.close-resolved'])
        ->and($definitions[2])->toMatchArray(['key' => 'it.poll-mailbox'])
        ->and($definitions[3])->toMatchArray(['key' => 'it.dispatch-notifications', 'expression' => '* * * * *'])
        ->and($definitions[4])->toMatchArray(['key' => 'it.retry-attachment-cleanup', 'expression' => '*/5 * * * *', 'overlap_minutes' => 10])
        ->and($definitions[5])->toMatchArray(['key' => 'it.check-approval-deadlines', 'expression' => '* * * * *', 'overlap_minutes' => 10]);
});
