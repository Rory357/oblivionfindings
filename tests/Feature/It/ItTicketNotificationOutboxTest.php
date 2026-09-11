<?php

use App\Domain\It\InboundEmailIngestor;
use App\Domain\It\Services\ItEmailDeliveryService;
use App\Domain\It\Services\ItTicketIntakeService;
use App\Domain\It\Services\ItTicketTriageService;
use App\Jobs\DispatchItTicketNotifications;
use App\Models\AuditLog;
use App\Models\ItEmailDelivery;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\It\TicketCreatedNotification;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->site = Site::factory()->create();
    $this->worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $role = Role::query()->create(['name' => 'it-outbox-'.Str::uuid(), 'label' => 'IT outbox fixture', 'level' => 50, 'type' => 'custom']);
    $permission = Permission::query()->firstOrCreate(['key' => 'it.request'], ['description' => 'Request IT support', 'group' => 'it', 'module' => 'Operations']);
    $role->permissions()->attach($permission);
    $this->worker->roles()->attach($role);
    ensureCanonicalHrStaffProfile($this->worker, $this->site);
    $this->input = ['request_uuid' => (string) Str::uuid(), 'title' => 'Isolated durable intake', 'category' => 'hardware', 'priority' => 'normal'];
    $this->deliveries = app(ItEmailDeliveryService::class);
});

test('a committed intent survives a lost post-response dispatch and drains only once', function () {
    Bus::fake([DispatchItTicketNotifications::class]);
    $created = $this->actingAs($this->worker)->postJson('/it/tickets', $this->input)->assertCreated();
    Bus::assertDispatchedAfterResponse(DispatchItTicketNotifications::class);
    expect($this->worker->notifications()->count())->toBe(0)
        ->and(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0);
    $delivery = ItEmailDelivery::query()->sole();
    expect($delivery->dispatch_requested_at)->not->toBeNull()
        ->and($delivery->dispatch_finished_at)->toBeNull();

    $this->artisan('it:dispatch-notifications', ['--limit' => 1])->assertSuccessful();
    $this->postJson('/it/tickets', $this->input)->assertOk()->assertJsonPath('data.id', $created->json('data.id'));
    expect($this->deliveries->dispatchPending())->toBe(0)
        ->and($this->worker->notifications()->count())->toBe(1)
        ->and(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(1)
        ->and($delivery->fresh()->status)->toBe('accepted')
        ->and($delivery->fresh()->attempt_count)->toBe(1)
        ->and(ItEmailDelivery::query()->count())->toBe(1);

    $message = Mail::mailer('array')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
    expect(iterator_to_array($message->getHeaders()->all('Auto-Submitted')))->toHaveCount(1)
        ->and($message->getHeaders()->get('Auto-Submitted')->getBodyAsString())->toBe('auto-generated')
        ->and($message->getHeaders()->get('X-Auto-Response-Suppress')->getBodyAsString())->toBe('All');
    $loop = app(InboundEmailIngestor::class)->ingest([
        'from' => $this->worker->email, 'subject' => $message->getSubject(), 'text' => 'Synthetic returned notification',
        'message_id' => '<returned-notification@example.test>',
        'auto_submitted' => $message->getHeaders()->get('Auto-Submitted')->getBodyAsString(),
    ]);
    expect($loop->status)->toBe('quarantined')->and($loop->quarantine_reason)->toBe('automatic_message')
        ->and($loop->it_ticket_id)->toBeNull()->and($loop->body_preview)->toBeNull()
        ->and(ItEmailDelivery::query()->count())->toBe(1)->and(ItTicket::query()->count())->toBe(1);
});

test('legacy direct send persists an exact intent before post-response dispatch and the recovery drain sends it once', function () {
    Bus::fake([DispatchItTicketNotifications::class]);
    $ticket = ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'requester_user_id' => $this->worker->id,
    ]);

    $this->deliveries->send($this->worker, new TicketCreatedNotification($ticket, 'receipt'));

    $delivery = ItEmailDelivery::query()->sole();
    expect($delivery->status)->toBe('queued')
        ->and($delivery->dispatch_requested_at)->not->toBeNull()
        ->and($delivery->dispatch_finished_at)->toBeNull()
        ->and($this->worker->notifications()->count())->toBe(0)
        ->and(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0);
    Bus::assertDispatchedAfterResponse(DispatchItTicketNotifications::class, fn (DispatchItTicketNotifications $job): bool => $job->ticketId === null && $job->deliveryId === $delivery->id);

    // Simulate process loss before the post-response callback; the scheduled
    // drain must find the committed exact-delivery intent and never resend it.
    expect($this->deliveries->dispatchPending(deliveryId: $delivery->id))->toBe(1)
        ->and($this->deliveries->dispatchPending(deliveryId: $delivery->id))->toBe(0)
        ->and($delivery->fresh()->status)->toBe('accepted')
        ->and($delivery->fresh()->attempt_count)->toBe(1)
        ->and($this->worker->notifications()->count())->toBe(1)
        ->and(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(1);
});

test('a triage assignment rolled back by its outer transaction leaves no notification intent or dispatch', function () {
    Bus::fake([DispatchItTicketNotifications::class]);
    $role = Role::query()->create(['name' => 'it-triage-'.Str::uuid(), 'label' => 'IT triage fixture', 'level' => 50, 'type' => 'custom']);
    foreach (['it.view', 'it.manage'] as $key) {
        $permission = Permission::query()->firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'it', 'module' => 'Operations']);
        $role->permissions()->attach($permission);
    }
    $actor = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $assignee = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    foreach ([$actor, $assignee] as $user) {
        $user->roles()->attach($role);
        ensureCanonicalHrStaffProfile($user, $this->site);
    }
    $ticket = ItTicket::factory()->create(['site_id' => $this->site->id]);

    expect(fn () => DB::transaction(function () use ($ticket, $actor, $assignee): void {
        app(ItTicketTriageService::class)->update($ticket, $actor, [
            'expected_version' => $ticket->lock_version,
            'assigned_to_user_id' => $assignee->id,
            'routing_reason' => 'Synthetic rollback after canonical assignment.',
        ]);
        throw new RuntimeException('Synthetic outer rollback');
    }))->toThrow(RuntimeException::class, 'Synthetic outer rollback');

    expect(ItEmailDelivery::query()->count())->toBe(0)
        ->and($ticket->fresh()->assigned_to_user_id)->toBeNull();
    Bus::assertNothingDispatched();
});

test('notification intent persistence failure rolls back the entire ticket command', function () {
    $event = 'eloquent.creating: '.ItEmailDelivery::class;
    Event::listen($event, fn () => throw new RuntimeException('Synthetic intent storage failure'));
    try {
        expect(fn () => app(ItTicketIntakeService::class)->createCommand($this->worker, $this->input))
            ->toThrow(RuntimeException::class, 'Synthetic intent storage failure');
    } finally {
        Event::forget($event);
    }
    expect(ItTicket::query()->count())->toBe(0)
        ->and(ItTicketCommandReceipt::query()->count())->toBe(0)
        ->and(ItEmailDelivery::query()->count())->toBe(0);
});

test('pending intent checks revoked access before either channel executes', function () {
    app(ItTicketIntakeService::class)->createCommand($this->worker, $this->input);
    User::query()->whereKey($this->worker->id)->update(['approved_at' => null]);
    expect($this->deliveries->dispatchPending())->toBe(1)
        ->and($this->worker->notifications()->count())->toBe(0)
        ->and(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0)
        ->and(ItEmailDelivery::query()->sole()->status)->toBe('failed');
});

test('recovery does not repeat accepted uncertain or failed provider attempts', function (string $status) {
    app(ItTicketIntakeService::class)->createCommand($this->worker, $this->input);
    ItEmailDelivery::query()->sole()->forceFill(['status' => $status, 'attempt_count' => 1])->save();
    expect($this->deliveries->dispatchPending())->toBe(1)
        ->and($this->deliveries->dispatchPending())->toBe(0)
        ->and(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0)
        ->and(ItEmailDelivery::query()->sole()->status)->toBe($status)
        ->and(ItEmailDelivery::query()->sole()->attempt_count)->toBe(1);
})->with(['accepted', 'sending', 'failed']);

test('recovery skips a database channel already persisted before interruption', function () {
    app(ItTicketIntakeService::class)->createCommand($this->worker, $this->input);
    $delivery = ItEmailDelivery::query()->sole();
    $this->worker->notifications()->create(['id' => $delivery->notification_uuid, 'type' => 'App\\Notifications\\It\\TicketCreatedNotification', 'data' => ['reference' => 'Synthetic persisted notification']]);
    $this->deliveries->dispatchPending();
    expect($this->worker->notifications()->count())->toBe(1)
        ->and(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(1)
        ->and($delivery->fresh()->status)->toBe('accepted');
});

test('a transport exception after sending starts stays uncertain until provider reconciliation', function () {
    app(ItTicketIntakeService::class)->createCommand($this->worker, $this->input);
    Notification::shouldReceive('sendNow')->once()->andReturnUsing(function ($recipient, $notification) {
        Event::until(new NotificationSending($recipient, $notification, 'mail'));
        $exception = new RuntimeException('Synthetic lost provider acknowledgement');
        Event::dispatch(new NotificationFailed($recipient, $notification, 'mail', ['exception' => $exception]));
        throw $exception;
    });
    expect($this->deliveries->dispatchPending())->toBe(1)
        ->and($this->deliveries->dispatchPending())->toBe(0);
    $delivery = ItEmailDelivery::query()->sole();
    expect($delivery->status)->toBe('sending')
        ->and($delivery->failed_at)->toBeNull()
        ->and($delivery->last_error)->toBe('Delivery outcome is unknown. Reconcile the provider result before retrying.')
        ->and($delivery->attempt_count)->toBe(1);
});

test('access revocation does not turn an interrupted send into a retryable failure', function () {
    app(ItTicketIntakeService::class)->createCommand($this->worker, $this->input);
    $delivery = ItEmailDelivery::query()->sole();
    $delivery->forceFill(['status' => 'sending', 'sending_at' => now(), 'attempt_count' => 1])->save();
    $notification = new TicketCreatedNotification($delivery->ticket, 'receipt');
    $notification->id = $delivery->notification_uuid;
    User::query()->whereKey($this->worker->id)->update(['approved_at' => null]);

    expect($this->deliveries->dispatchPending())->toBe(1);
    foreach (['database', 'mail'] as $channel) {
        expect($this->deliveries->recordNotificationEvent(new NotificationSending($this->worker, $notification, $channel)))
            ->toBeFalse();
    }
    expect($delivery->fresh()->status)->toBe('sending')
        ->and($delivery->fresh()->failed_at)->toBeNull()
        ->and($delivery->fresh()->last_error)->toContain('earlier sending outcome is unknown')
        ->and(AuditLog::query()->where('action', 'it.email.delivery.access_revoked')->where('auditable_id', $delivery->id)->count())->toBe(1)
        ->and($this->worker->notifications()->count())->toBe(0)
        ->and(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0);

    $permission = Permission::query()->firstOrCreate(['key' => 'it.manage'], ['description' => 'Manage IT', 'group' => 'it', 'module' => 'Operations']);
    $this->worker->roles()->first()->permissions()->syncWithoutDetaching([$permission->id]);
    User::query()->whereKey($this->worker->id)->update(['approved_at' => now()]);
    expect(fn () => $this->deliveries->retry($delivery, $this->worker->fresh()))
        ->toThrow(DomainException::class, 'Only failed or bounced email can be retried.');
    expect(ItEmailDelivery::query()->count())->toBe(1);
});

test('a local failure after a provider callback never regresses the authoritative result', function (string $providerStatus) {
    app(ItTicketIntakeService::class)->createCommand($this->worker, $this->input);
    Notification::shouldReceive('sendNow')->once()->andReturnUsing(function ($recipient, $notification) use ($providerStatus) {
        Event::until(new NotificationSending($recipient, $notification, 'mail'));
        $this->deliveries->recordProviderStatus(
            $notification->id,
            $providerStatus,
            $providerStatus === 'delivered' ? null : 'Synthetic authoritative provider failure',
            'synthetic-provider-acknowledgement',
        );
        $exception = new RuntimeException('Synthetic late local failure');
        Event::dispatch(new NotificationFailed($recipient, $notification, 'mail', ['exception' => $exception]));
        throw $exception;
    });

    expect($this->deliveries->dispatchPending())->toBe(1)
        ->and($this->deliveries->dispatchPending())->toBe(0);
    $delivery = ItEmailDelivery::query()->sole();
    expect($delivery->status)->toBe($providerStatus)
        ->and($delivery->provider_status_at)->not->toBeNull()
        ->and($delivery->provider_message_id)->toBe('synthetic-provider-acknowledgement')
        ->and($delivery->last_error)->toBe($providerStatus === 'delivered' ? null : 'Synthetic authoritative provider failure')
        ->and($delivery->attempt_count)->toBe(1)
        ->and(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0);
})->with(['delivered', 'bounced', 'failed']);

test('only an authoritative provider failure unlocks retry after a lost acknowledgement', function () {
    app(ItTicketIntakeService::class)->createCommand($this->worker, $this->input);
    $delivery = ItEmailDelivery::query()->sole();
    $notification = new TicketCreatedNotification($delivery->ticket, 'receipt');
    $notification->id = $delivery->notification_uuid;
    $permission = Permission::query()->firstOrCreate(['key' => 'it.manage'], ['description' => 'Manage IT', 'group' => 'it', 'module' => 'Operations']);
    $this->worker->roles()->first()->permissions()->syncWithoutDetaching([$permission->id]);
    $actor = $this->worker->fresh();

    Event::until(new NotificationSending($this->worker, $notification, 'mail'));
    $failure = new NotificationFailed($this->worker, $notification, 'mail', ['exception' => new RuntimeException('Synthetic lost acknowledgement')]);
    Event::dispatch($failure);
    expect($delivery->fresh()->status)->toBe('sending')
        ->and($delivery->fresh()->failed_at)->toBeNull();
    expect(fn () => $this->deliveries->retry($delivery, $actor))
        ->toThrow(DomainException::class, 'Only failed or bounced email can be retried.');

    $this->deliveries->recordProviderStatus($delivery->notification_uuid, 'failed', 'Synthetic confirmed rejection', 'synthetic-rejected');
    Event::dispatch($failure);
    expect($delivery->fresh()->status)->toBe('failed')
        ->and($delivery->fresh()->last_error)->toBe('Synthetic confirmed rejection');
    Notification::fake();
    $retry = $this->deliveries->retry($delivery, $actor);
    expect($delivery->fresh()->status)->toBe('retried')
        ->and($retry->retry_of_delivery_id)->toBe($delivery->id)
        ->and($retry->notification_uuid)->not->toBe($delivery->notification_uuid)
        ->and($retry->dispatch_requested_at)->not->toBeNull()
        ->and(ItEmailDelivery::query()->count())->toBe(2);
    Notification::assertNothingSent();
    $this->deliveries->dispatchPending(deliveryId: $retry->id);
    Notification::assertSentToTimes($this->worker, TicketCreatedNotification::class, 1);
});

test('a contending dispatcher leaves an intent pending for the lock owner', function () {
    app(ItTicketIntakeService::class)->createCommand($this->worker, $this->input);
    $delivery = ItEmailDelivery::query()->sole();
    $name = 'it-mail:'.substr(hash('sha256', DB::connection()->getDatabaseName().':'.$delivery->id), 0, 48);
    config(['database.connections.it_outbox_contender' => config('database.connections.mysql')]);
    $other = DB::connection('it_outbox_contender');
    try {
        expect((int) $other->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$name])->acquired)->toBe(1)
            ->and($this->deliveries->dispatchPending())->toBe(0)
            ->and($delivery->fresh()->dispatch_finished_at)->toBeNull();
    } finally {
        $other->selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
        DB::purge('it_outbox_contender');
    }
    expect($this->deliveries->dispatchPending())->toBe(1)
        ->and(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(1);
});

test('a committed retry survives the loss of post-response dispatch and the drain submits it once', function () {
    app(ItTicketIntakeService::class)->createCommand($this->worker, $this->input);
    $this->deliveries->dispatchPending();
    $original = ItEmailDelivery::query()->sole();
    $this->deliveries->recordProviderStatus($original->notification_uuid, 'bounced', 'Synthetic bounce');
    $permission = Permission::query()->firstOrCreate(['key' => 'it.manage'], ['description' => 'Manage IT', 'group' => 'it', 'module' => 'Operations']);
    $this->worker->roles()->first()->permissions()->syncWithoutDetaching([$permission->id]);
    $actor = $this->worker->fresh();
    $retry = $this->deliveries->retry($original, $actor);
    expect($retry->status)->toBe('queued')->and($retry->dispatch_requested_at)->not->toBeNull()
        ->and($retry->dispatch_finished_at)->toBeNull()
        ->and(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(1);
    // No post-response job is run: the scheduled drain discovers the intent.
    expect($this->deliveries->dispatchPending())->toBe(1)->and($this->deliveries->dispatchPending())->toBe(0)
        ->and($retry->fresh()->status)->toBe('accepted')->and($retry->fresh()->attempt_count)->toBe(1)
        ->and(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(2);
    expect(fn () => $this->deliveries->retry($original, $actor))->toThrow(DomainException::class);
});

test('rolling back a retry caller does not submit a notification or leave an intent', function () {
    app(ItTicketIntakeService::class)->createCommand($this->worker, $this->input);
    $original = ItEmailDelivery::query()->sole();
    $original->update(['status' => 'failed', 'failed_at' => now(), 'dispatch_finished_at' => now()]);
    $permission = Permission::query()->firstOrCreate(['key' => 'it.manage'], ['description' => 'Manage IT', 'group' => 'it', 'module' => 'Operations']);
    $this->worker->roles()->first()->permissions()->syncWithoutDetaching([$permission->id]);
    DB::beginTransaction();
    try {
        $retry = $this->deliveries->retry($original, $this->worker->fresh());
        expect($retry->dispatch_requested_at)->not->toBeNull()
            ->and(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0);
    } finally {
        DB::rollBack();
    }
    expect(ItEmailDelivery::query()->count())->toBe(1)->and($original->fresh()->status)->toBe('failed')
        ->and(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0);
});
