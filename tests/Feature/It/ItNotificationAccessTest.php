<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItEmailDeliveryService;
use App\Jobs\DispatchItTicketNotifications;
use App\Models\ItEmailDelivery;
use App\Models\ItTeam;
use App\Models\ItTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\It\TicketAssignedNotification;
use App\Notifications\It\TicketReopenedNotification;
use App\Notifications\It\TicketRepliedNotification;
use App\Notifications\It\TicketResolvedNotification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;

function itNotificationActor(Site $site, array $permissions = ['it.view', 'it.manage']): User
{
    $actor = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $role = Role::query()->create([
        'name' => 'it-notification-'.str()->uuid(),
        'label' => 'IT notification access fixture',
        'level' => 50,
        'type' => 'custom',
    ]);
    foreach ($permissions as $key) {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'it', 'module' => 'Operations'],
        );
        $role->permissions()->attach($permission);
    }
    $actor->roles()->attach($role);
    ensureCanonicalHrStaffProfile($actor, $site);

    return $actor;
}

test('IT dispatch refuses an already revoked recipient even with a stale loaded user', function () {
    Bus::fake([DispatchItTicketNotifications::class]);
    $site = Site::factory()->create();
    $recipient = itNotificationActor($site);
    $ticket = ItTicket::factory()->create(['site_id' => $site->id]);
    $ticket->watchers()->attach($recipient->id);
    $recipient->load('roles.permissions', 'permissionOverrides');
    User::query()->whereKey($recipient->id)->update(['approved_at' => null]);

    app(ItEmailDeliveryService::class)->send($recipient, new TicketRepliedNotification($ticket, 'agent_side'));
    $delivery = ItEmailDelivery::query()->sole();

    expect($recipient->notifications()->count())->toBe(0)
        ->and(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0)
        ->and($delivery->fresh()->status)->toBe('failed')
        ->and($delivery->fresh()->dispatch_finished_at)->toBeNull();
    Bus::assertNothingDispatched();
});

test('persisted IT intents recheck current record access before either channel executes', function (string $revocation) {
    Bus::fake([DispatchItTicketNotifications::class]);
    $site = Site::factory()->create();
    $recipient = itNotificationActor($site);
    $ticket = ItTicket::factory()->create(['site_id' => $site->id]);
    $ticket->watchers()->attach($recipient->id);
    $team = null;
    if ($revocation === 'team') {
        $ticket->update(['site_id' => Site::factory()->create()->id]);
        $team = ItTeam::factory()->create(['is_active' => true, 'manager_user_id' => null]);
        $team->members()->attach($recipient);
        $ticket->update(['team_id' => $team->id]);
    }
    $recipient->load('roles.permissions', 'permissionOverrides');
    app(ItEmailDeliveryService::class)->send($recipient, new TicketRepliedNotification($ticket, 'agent_side'));
    $delivery = ItEmailDelivery::query()->sole();
    expect($delivery->dispatch_requested_at)->not->toBeNull();

    match ($revocation) {
        'approval' => User::query()->whereKey($recipient->id)->update(['approved_at' => null]),
        'site' => HrEmployeeProfile::query()->where('user_id', $recipient->id)->update(['is_active' => false]),
        'permission' => $recipient->roles()->detach(),
        'team' => $team->members()->detach($recipient),
        'sensitivity' => $ticket->update(['is_sensitive' => true]),
        'watcher' => $ticket->watchers()->detach($recipient->id),
    };

    expect(app(ItEmailDeliveryService::class)->dispatchPending(deliveryId: $delivery->id))->toBe(1)
        ->and($recipient->notifications()->count())->toBe(0)
        ->and(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0)
        ->and($delivery->fresh()->status)->toBe('failed')
        ->and($delivery->fresh()->last_error)->toBe('Delivery stopped because the recipient no longer has access.')
        ->and(app(ItEmailDeliveryService::class)->dispatchPending(deliveryId: $delivery->id))->toBe(0);
})->with(['approval', 'site', 'permission', 'team', 'sensitivity', 'watcher']);

test('permitted participant and assigned technician still receive both IT channels', function (string $access) {
    Bus::fake([DispatchItTicketNotifications::class]);
    $site = Site::factory()->create();
    $recipient = itNotificationActor($site, $access === 'participant' ? ['it.request'] : ['it.view', 'it.manage']);
    $ticket = ItTicket::factory()->create([
        'site_id' => Site::factory()->create()->id,
        'requester_user_id' => $access === 'participant' ? $recipient->id : User::factory(),
        'assigned_to_user_id' => $access === 'assignment' ? $recipient->id : null,
    ]);
    app(ItEmailDeliveryService::class)->send($recipient, new TicketRepliedNotification($ticket));
    $delivery = ItEmailDelivery::query()->sole();
    app(ItEmailDeliveryService::class)->dispatchPending(deliveryId: $delivery->id);

    expect($recipient->notifications()->count())->toBe(1)
        ->and(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(1)
        ->and(ItEmailDelivery::query()->sole()->status)->toBe('accepted');
})->with(['participant', 'assignment']);

test('watcher removal after intent persistence prevents both channels without erasing the terminal record', function () {
    Bus::fake([DispatchItTicketNotifications::class]);
    $site = Site::factory()->create();
    $recipient = itNotificationActor($site);
    $ticket = ItTicket::factory()->create(['site_id' => $site->id]);
    $ticket->watchers()->attach($recipient->id);
    app(ItEmailDeliveryService::class)->send($recipient, new TicketRepliedNotification($ticket, 'agent_side'));
    $delivery = ItEmailDelivery::query()->sole();
    $ticket->watchers()->detach($recipient->id);
    expect(app(ItEmailDeliveryService::class)->dispatchPending(deliveryId: $delivery->id))->toBe(1)
        ->and($recipient->notifications()->count())->toBe(0)
        ->and(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0)
        ->and($delivery->fresh()->status)->toBe('failed');
});

test('a stale assignment intent never mails the previous assignee who still has ticket access', function () {
    Bus::fake([DispatchItTicketNotifications::class]);
    $site = Site::factory()->create();
    $previousAssignee = itNotificationActor($site);
    $replacement = itNotificationActor($site);
    $ticket = ItTicket::factory()->create([
        'site_id' => $site->id,
        'assigned_to_user_id' => $previousAssignee->id,
    ]);
    $service = app(ItEmailDeliveryService::class);

    $service->send($previousAssignee, new TicketAssignedNotification($ticket));
    $delivery = ItEmailDelivery::query()->sole();
    expect($delivery->dispatch_requested_at)->not->toBeNull();

    $ticket->update(['assigned_to_user_id' => $replacement->id]);
    expect($service->dispatchPending(deliveryId: $delivery->id))->toBe(1)
        ->and($previousAssignee->notifications()->count())->toBe(0)
        ->and(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0)
        ->and($delivery->fresh()->status)->toBe('failed')
        ->and($delivery->fresh()->last_error)->toBe('Delivery stopped because the recipient no longer has access.')
        ->and($previousAssignee->fresh()->canDo('it.view'))->toBeTrue();
});

test('revoked and stale-assignment deliveries remain visible but cannot offer a retry', function () {
    $site = Site::factory()->create();
    $manager = itNotificationActor($site);
    $recipient = itNotificationActor($site);
    $replacement = itNotificationActor($site);
    $ticket = ItTicket::factory()->create([
        'site_id' => $site->id,
        'assigned_to_user_id' => $recipient->id,
    ]);
    $service = app(ItEmailDeliveryService::class);
    $service->prepare($recipient, new TicketAssignedNotification($ticket));
    $staleAssignment = ItEmailDelivery::query()->sole();
    $staleAssignment->forceFill(['status' => 'failed', 'failed_at' => now()])->save();
    $ticket->update(['assigned_to_user_id' => $replacement->id]);

    expect($service->visibleQuery($manager)->whereKey($staleAssignment->id)->exists())->toBeTrue()
        ->and($service->canOfferRetry($staleAssignment, $manager))->toBeFalse();

    $ticket->update(['assigned_to_user_id' => $recipient->id]);
    $service->prepare($recipient, new TicketAssignedNotification($ticket));
    $revokedRecipient = ItEmailDelivery::query()->whereKeyNot($staleAssignment->id)->sole();
    $revokedRecipient->forceFill(['status' => 'failed', 'failed_at' => now()])->save();
    User::query()->whereKey($recipient->id)->update(['approved_at' => null]);

    expect($service->visibleQuery($manager)->whereKey($revokedRecipient->id)->exists())->toBeTrue()
        ->and($service->canOfferRetry($revokedRecipient, $manager))->toBeFalse();
});

test('a removed watcher cannot retry failed delivery and an earlier sending outcome stays unknown', function () {
    $site = Site::factory()->create();
    $recipient = itNotificationActor($site);
    $manager = itNotificationActor($site);
    $ticket = ItTicket::factory()->create(['site_id' => $site->id, 'assigned_to_user_id' => $manager->id]);
    $ticket->watchers()->attach($recipient->id);
    $service = app(ItEmailDeliveryService::class);
    $service->prepare($recipient, new TicketRepliedNotification($ticket, 'agent_side'));
    $delivery = ItEmailDelivery::query()->sole();
    $delivery->forceFill(['status' => 'failed', 'failed_at' => now()])->save();
    $ticket->watchers()->detach($recipient->id);
    expect(fn () => $service->retry($delivery, $manager))->toThrow(DomainException::class, 'The recipient is no longer entitled');
    expect(ItEmailDelivery::query()->count())->toBe(1);
    $delivery->forceFill(['status' => 'sending', 'sending_at' => now(), 'failed_at' => null])->save();
    $service->dispatchPending(10, $ticket->id);
    expect($delivery->fresh()->status)->toBe('sending')->and($delivery->fresh()->failed_at)->toBeNull()
        ->and($delivery->fresh()->last_error)->toContain('earlier sending outcome is unknown');
});

test('persisted resolution and reopen watcher intents require current membership', function (string $kind) {
    Bus::fake([DispatchItTicketNotifications::class]);
    $site = Site::factory()->create();
    $recipient = itNotificationActor($site);
    $ticket = ItTicket::factory()->create(['site_id' => $site->id]);
    $ticket->watchers()->attach($recipient->id);
    $notification = $kind === 'resolution'
        ? new TicketResolvedNotification($ticket, 'watcher') : new TicketReopenedNotification($ticket);
    app(ItEmailDeliveryService::class)->send($recipient, $notification);
    $delivery = ItEmailDelivery::query()->sole();
    $ticket->watchers()->detach($recipient->id);
    expect(app(ItEmailDeliveryService::class)->dispatchPending(deliveryId: $delivery->id))->toBe(1)
        ->and($recipient->notifications()->count())->toBe(0)
        ->and(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0)
        ->and($delivery->fresh()->status)->toBe('failed');
})->with(['resolution', 'reopen']);

test('a current reopen assignee and resolution requester remain entitled without watcher membership', function (string $kind) {
    Bus::fake([DispatchItTicketNotifications::class]);
    $site = Site::factory()->create();
    $recipient = itNotificationActor($site, $kind === 'resolution' ? ['it.request'] : ['it.view', 'it.manage']);
    $ticket = ItTicket::factory()->create(['site_id' => $site->id,
        'requester_user_id' => $kind === 'resolution' ? $recipient->id : User::factory(),
        'assigned_to_user_id' => $kind === 'reopen' ? $recipient->id : null]);
    $notification = $kind === 'resolution'
        ? new TicketResolvedNotification($ticket) : new TicketReopenedNotification($ticket);
    app(ItEmailDeliveryService::class)->send($recipient, $notification);
    $delivery = ItEmailDelivery::query()->sole();
    app(ItEmailDeliveryService::class)->dispatchPending(deliveryId: $delivery->id);
    expect($recipient->notifications()->count())->toBe(1)
        ->and(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(1)
        ->and(ItEmailDelivery::query()->sole()->status)->toBe('accepted');
    if ($kind === 'reopen') {
        expect(implode(' ', $notification->toMail($recipient)->introLines))->not->toContain('assigned to you');
    }
})->with(['resolution', 'reopen']);
