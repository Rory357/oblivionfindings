<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\InboundEmailIngestor;
use App\Domain\It\Presenters\ItTicketContextPresenter;
use App\Domain\It\Services\ItEmailDeliveryService;
use App\Domain\It\Services\ItTicketLinkService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\ControlRoom\Device as ControlRoomDevice;
use App\Models\ControlRoomAlert;
use App\Models\ItChange;
use App\Models\ItEmailDelivery;
use App\Models\ItInboundEmail;
use App\Models\ItMailboxConnection;
use App\Models\ItProvisioningRequest;
use App\Models\ItTeam;
use App\Models\ItTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\SecurityDevicesSignalSeeder;
use Illuminate\Support\Facades\Notification;

/** @param array<int, string> $permissionKeys */
function ingressContextActor(Site $site, array $permissionKeys): User
{
    $actor = User::factory()->create(['approved_at' => now()]);
    $role = Role::query()->create([
        'name' => 'ingress-context-'.str()->uuid(),
        'label' => 'Ingress context test role',
        'level' => 50,
        'type' => 'custom',
    ]);

    foreach ($permissionKeys as $key) {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'it', 'module' => 'Operations'],
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);
    }
    $actor->roles()->attach($role);

    HrEmployeeProfile::factory()->create([
        'user_id' => $actor->id,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
    ]);

    return $actor;
}

function assignIngressDeviceToSite(Device $device, Site $site, User $actor): void
{
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now(),
        'assigned_by_user_id' => $actor->id,
    ]);
}

function linkIngressContext(ItTicket $ticket, object $target, string $relationship): void
{
    $ticket->links()->create([
        'tenant_id' => $ticket->tenant_id,
        'relationship' => $relationship,
        'linkable_type' => $target->getMorphClass(),
        'linkable_id' => $target->getKey(),
    ]);
}

test('linked context includes only canonical device alert and related work visible to the viewer', function () {
    $allowedSite = Site::factory()->create();
    $hiddenSite = Site::factory()->create();
    $viewer = ingressContextActor($allowedSite, [
        'it.view',
        'securityDevices.devices.view',
        'controlRoom.alerts.view',
    ]);
    $ticket = ItTicket::factory()->create(['site_id' => $allowedSite->id]);

    $allowedDevice = Device::factory()->itInfrastructure()->create();
    $hiddenDevice = Device::factory()->itInfrastructure()->create();
    assignIngressDeviceToSite($allowedDevice, $allowedSite, $viewer);
    assignIngressDeviceToSite($hiddenDevice, $hiddenSite, $viewer);

    $allowedAlert = ControlRoomAlert::factory()->create(['site_id' => $allowedSite->id]);
    $hiddenAlert = ControlRoomAlert::factory()->create(['site_id' => $hiddenSite->id]);

    $allowedChange = ItChange::factory()->create();
    $allowedChange->ticket()->update(['site_id' => $allowedSite->id]);
    $hiddenChange = ItChange::factory()->create();
    $hiddenChange->ticket()->update([
        'site_id' => $hiddenSite->id,
        'is_sensitive' => true,
    ]);

    linkIngressContext($ticket, $allowedDevice, 'affected_device');
    linkIngressContext($ticket, $hiddenDevice, 'affected_device');
    linkIngressContext($ticket, $allowedAlert, 'source_alert');
    linkIngressContext($ticket, $hiddenAlert, 'source_alert');
    linkIngressContext($ticket, $allowedChange->ticket, 'related_change');
    linkIngressContext($ticket, $hiddenChange->ticket, 'related_change');

    $context = app(ItTicketContextPresenter::class)->present($ticket->fresh(), $viewer);

    expect(collect($context['devices'])->pluck('id')->all())->toBe([$allowedDevice->id])
        ->and(collect($context['alerts'])->pluck('id')->all())->toBe([$allowedAlert->id])
        ->and(collect($context['changes'])->pluck('id')->all())->toBe([$allowedChange->id]);
});

test('inbound replies quarantine unknown inactive ambiguous sensitive and unrelated senders without comments', function () {
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $requester = ingressContextActor($site, ['it.request']);
    $unrelated = ingressContextActor($site, ['it.request']);
    $sensitiveAgent = ingressContextActor($site, ['it.view', 'it.manage']);
    $inactive = ingressContextActor($site, ['it.request']);
    $inactive->update(['approved_at' => null]);
    $ticket = ItTicket::factory()->create([
        'site_id' => $site->id,
        'requester_user_id' => $requester->id,
        'reference' => 'IT-90001',
    ]);
    $sensitive = ItTicket::factory()->create([
        'site_id' => $site->id,
        'requester_user_id' => $requester->id,
        'assigned_to_user_id' => $sensitiveAgent->id,
        'reference' => 'IT-90002',
        'is_sensitive' => true,
    ]);
    $ambiguousA = ItTicket::factory()->create([
        'tenant_id' => 41,
        'site_id' => $site->id,
        'requester_user_id' => $requester->id,
        'reference' => 'IT-90003',
    ]);
    $ambiguousB = ItTicket::factory()->create([
        'tenant_id' => 42,
        'site_id' => $otherSite->id,
        'requester_user_id' => $requester->id,
        'reference' => 'IT-90003',
    ]);
    $ingestor = app(InboundEmailIngestor::class);

    $cases = [
        ['nobody@example.test', 'IT-90001', 'sender_unknown'],
        [$inactive->email, 'IT-90001', 'sender_inactive'],
        [$unrelated->email, 'IT-90001', 'sender_unauthorized'],
        [$sensitiveAgent->email, 'IT-90002', 'sensitive_work'],
        [$requester->email, 'IT-90003', 'reference_ambiguous'],
    ];

    foreach ($cases as $index => [$from, $reference, $reason]) {
        $secretToken = "sk-quarantine-private-token-{$index}";
        $inbound = $ingestor->ingest([
            'from' => $from,
            'subject' => "Sensitive subject sentinel {$index} {$secretToken} — Re: {$reference}",
            'text' => 'Private content that must not be retained in quarantine preview.',
            'message_id' => "<quarantine-{$index}@example.test>",
        ]);

        expect($inbound->status)->toBe('quarantined')
            ->and($inbound->quarantine_reason)->toBe($reason)
            ->and($inbound->body_preview)->toBeNull()
            ->and($inbound->subject)->toBe($reference)
            ->and($inbound->subject)->not->toContain($secretToken)
            ->and($inbound->it_ticket_id)->toBeNull();
    }

    expect($ticket->comments()->count())->toBe(0)
        ->and($sensitive->comments()->count())->toBe(0)
        ->and($ambiguousA->comments()->count())->toBe(0)
        ->and($ambiguousB->comments()->count())->toBe(0);
});

test('inbound replies accept participants responsible staff watcher and explicit mailbox principal only through current work access', function () {
    $site = Site::factory()->create();
    $requester = ingressContextActor($site, ['it.request']);
    $requestedFor = ingressContextActor($site, ['it.request']);
    $responsible = ingressContextActor($site, ['it.view', 'it.manage']);
    $watcher = ingressContextActor($site, ['it.view', 'it.manage']);
    $mailboxPrincipal = ingressContextActor($site, ['it.view', 'it.manage']);
    $genericAgent = ingressContextActor($site, ['it.view', 'it.manage']);
    $team = ItTeam::factory()->create();
    $team->members()->attach($responsible, ['role' => 'member']);
    $ticket = ItTicket::factory()->create([
        'site_id' => $site->id,
        'requester_user_id' => $requester->id,
        'requested_for_user_id' => $requestedFor->id,
        'team_id' => $team->id,
        'reference' => 'IT-90101',
    ]);
    $ticket->watchers()->attach($watcher->id);
    $sensitiveParticipantTicket = ItTicket::factory()->create([
        'site_id' => $site->id,
        'requester_user_id' => $requester->id,
        'reference' => 'IT-90102',
        'is_sensitive' => true,
    ]);
    ItMailboxConnection::query()->create([
        'tenant_id' => 0,
        'provider' => ItMailboxConnection::PROVIDER_MICROSOFT,
        'status' => ItMailboxConnection::STATUS_CONNECTED,
        'access_token' => 'connected-mailbox-token',
        'account_email' => $mailboxPrincipal->email,
        'mailbox_email' => $mailboxPrincipal->email,
        'created_by' => $mailboxPrincipal->id,
    ]);
    $ingestor = app(InboundEmailIngestor::class);

    foreach ([$requester, $requestedFor, $responsible, $watcher, $mailboxPrincipal] as $index => $sender) {
        $inbound = $ingestor->ingest([
            'from' => $sender->email,
            'subject' => "Re: {$ticket->reference}",
            'text' => "Authorised reply {$index}",
            'message_id' => "<authorised-{$index}@example.test>",
        ]);
        expect($inbound->status)->toBe('processed')
            ->and($inbound->it_ticket_id)->toBe($ticket->id);
    }

    $denied = $ingestor->ingest([
        'from' => $genericAgent->email,
        'subject' => "Re: {$ticket->reference}",
        'text' => 'Generic Site access alone is not an email-reply responsibility.',
        'message_id' => '<generic-agent@example.test>',
    ]);
    $sensitiveParticipantReply = $ingestor->ingest([
        'from' => $requester->email,
        'subject' => "Re: {$sensitiveParticipantTicket->reference}",
        'text' => 'The requester may continue their own sensitive conversation.',
        'message_id' => '<sensitive-participant@example.test>',
    ]);

    expect($ticket->comments()->count())->toBe(5)
        ->and($denied->status)->toBe('quarantined')
        ->and($denied->quarantine_reason)->toBe('sender_unauthorized')
        ->and($sensitiveParticipantReply->status)->toBe('processed')
        ->and($sensitiveParticipantTicket->comments()->count())->toBe(1);
});

test('inbound message ids are idempotent and new email tickets require a current approved site', function () {
    $site = Site::factory()->create();
    $sender = ingressContextActor($site, ['it.request']);
    $ingestor = app(InboundEmailIngestor::class);
    $message = [
        'from' => $sender->email,
        'subject' => 'New workstation cannot connect',
        'text' => 'The wired connection has no network access.',
        'message_id' => '<one-message@example.test>',
    ];

    $first = $ingestor->ingest($message);
    $second = $ingestor->ingest($message);

    expect($first->id)->toBe($second->id)
        ->and(ItInboundEmail::query()->where('message_id', $message['message_id'])->count())->toBe(1)
        ->and(ItTicket::query()->count())->toBe(1)
        ->and(ItTicket::query()->sole()->site_id)->toBe($site->id)
        ->and(ItTicket::query()->sole()->reference)->toMatch('/^IT-\d{6}$/');

    $missingIdentity = $ingestor->ingest([
        'from' => $sender->email,
        'subject' => 'Transport retry without stable identity',
        'text' => 'This must never create or append work.',
    ]);
    expect($missingIdentity->status)->toBe('quarantined')
        ->and($missingIdentity->quarantine_reason)->toBe('missing_message_id')
        ->and(ItTicket::query()->count())->toBe(1);

    $noSite = ingressContextActor($site, ['it.request']);
    $noSite->update(['email' => 'no-site@example.test']);
    $noSite->hrEmployeeProfile()->delete();
    $quarantined = $ingestor->ingest([
        'from' => $noSite->email,
        'subject' => 'Cannot create accidental global ticket',
        'text' => 'No active Site assignment exists.',
        'message_id' => '<no-site@example.test>',
    ]);

    expect($quarantined->status)->toBe('quarantined')
        ->and($quarantined->quarantine_reason)->toBe('sender_site_unresolved')
        ->and(ItTicket::query()->count())->toBe(1);
});

test('ticket links require a current responsible actor and canonical target visibility', function () {
    $site = Site::factory()->create();
    $hiddenSite = Site::factory()->create();
    $actor = ingressContextActor($site, [
        'it.view',
        'it.manage',
        'securityDevices.devices.view',
        'controlRoom.alerts.view',
    ]);
    $ticket = ItTicket::factory()->create(['site_id' => $site->id]);
    $allowedDevice = Device::factory()->itInfrastructure()->create();
    $hiddenDevice = Device::factory()->itInfrastructure()->create();
    assignIngressDeviceToSite($allowedDevice, $site, $actor);
    assignIngressDeviceToSite($hiddenDevice, $hiddenSite, $actor);
    $allowedAlert = ControlRoomAlert::factory()->create(['site_id' => $site->id]);
    $hiddenAlert = ControlRoomAlert::factory()->create(['site_id' => $hiddenSite->id]);
    $allowedRelated = ItTicket::factory()->create([
        'site_id' => $site->id,
        'work_type' => 'change',
    ]);
    $hiddenRelated = ItTicket::factory()->create([
        'site_id' => $hiddenSite->id,
        'work_type' => 'change',
        'is_sensitive' => true,
    ]);
    $links = app(ItTicketLinkService::class);

    expect(fn () => $links->link($ticket, $allowedDevice, 'affected_device'))
        ->toThrow(DomainException::class, 'responsible actor');

    $links->link($ticket, $allowedDevice, 'affected_device', [], $actor->id);
    $links->link($ticket, $allowedAlert, 'source_alert', [], $actor->id);
    $links->link($ticket, $allowedRelated, 'related_change', [], $actor->id);

    foreach ([
        [$hiddenDevice, 'affected_device'],
        [$hiddenAlert, 'source_alert'],
        [$hiddenRelated, 'related_change'],
    ] as [$target, $relationship]) {
        expect(fn () => $links->link($ticket, $target, $relationship, [], $actor->id))
            ->toThrow(DomainException::class, 'not accessible');
    }

    $unrelatedActor = ingressContextActor($hiddenSite, [
        'it.view',
        'it.manage',
        'securityDevices.devices.view',
    ]);
    expect(fn () => $links->unlink(
        $ticket,
        $allowedDevice,
        'affected_device',
        $unrelatedActor->id,
    ))->toThrow(DomainException::class, 'not accessible');

    $staleSource = ItTicket::factory()->create(['site_id' => $site->id]);
    ItTicket::query()->whereKey($staleSource->id)->update(['site_id' => $hiddenSite->id]);
    expect(fn () => $links->link(
        $staleSource,
        $allowedDevice,
        'affected_device',
        [],
        $actor->id,
    ))->toThrow(DomainException::class, 'not accessible');

    DeviceAssignment::query()
        ->where('device_id', $allowedDevice->id)
        ->update(['assignable_id' => $hiddenSite->id]);
    expect(fn () => $links->unlink(
        $ticket,
        $allowedDevice,
        'affected_device',
        $actor->id,
    ))->toThrow(DomainException::class, 'not accessible');

    expect($ticket->links()->count())->toBe(3)
        ->and($ticket->links()->where('relationship', 'affected_device')
            ->where('linkable_id', $allowedDevice->id)->exists())->toBeTrue()
        ->and($ticket->links()->where('linkable_id', $hiddenDevice->id)
            ->where('linkable_type', $hiddenDevice->getMorphClass())->exists())->toBeFalse();
});

test('monitoring creates site scoped work only from agreeing canonical device and alert evidence', function () {
    config()->set('queue.default', 'sync');
    $this->seed(SecurityDevicesSignalSeeder::class);
    $site = Site::factory()->create();
    $actor = ingressContextActor($site, ['securityDevices.devices.view']);
    $device = Device::factory()->itInfrastructure()->create();
    assignIngressDeviceToSite($device, $site, $actor);
    ControlRoomDevice::query()->create([
        'name' => $device->name,
        'canonical_device_id' => $device->id,
        'site_id' => $site->id,
        'type' => ControlRoomDevice::TYPE_NETWORK,
    ]);

    DeviceEvent::query()->create([
        'device_id' => $device->id,
        'event_type' => 'offline',
        'severity' => 'high',
        'source' => 'oblivion_monitoring',
        'occurred_at' => now(),
    ]);

    $ticket = ItTicket::query()->sole();
    $created = $ticket->events()->where('type', 'created_from_monitoring')->sole();
    expect($ticket->site_id)->toBe($site->id)
        ->and($ticket->is_organisation_wide)->toBeFalse()
        ->and($ticket->requester_user_id)->toBeNull()
        ->and($ticket->reference)->toMatch('/^IT-\d{6}$/')
        ->and($created->payload['system_principal'] ?? null)->toBe('oblivion_monitoring_ticketing')
        ->and($created->payload['operation'] ?? null)->toBe('work:create-monitoring');
});

test('monitoring fails closed when device site evidence is absent or contradicts the alert projection', function (string $mode) {
    config()->set('queue.default', 'sync');
    $this->seed(SecurityDevicesSignalSeeder::class);
    $device = Device::factory()->itInfrastructure()->create();
    $projectionSite = Site::factory()->create();

    if ($mode === 'contradictory') {
        $canonicalSite = Site::factory()->create();
        $actor = ingressContextActor($canonicalSite, ['securityDevices.devices.view']);
        assignIngressDeviceToSite($device, $canonicalSite, $actor);
    }

    ControlRoomDevice::query()->create([
        'name' => $device->name,
        'canonical_device_id' => $device->id,
        'site_id' => $projectionSite->id,
        'type' => ControlRoomDevice::TYPE_NETWORK,
    ]);

    DeviceEvent::query()->create([
        'device_id' => $device->id,
        'event_type' => 'offline',
        'severity' => 'high',
        'source' => 'oblivion_monitoring',
        'occurred_at' => now(),
    ]);

    expect(ItTicket::query()->count())->toBe(0);
})->with(['unassigned', 'contradictory']);

test('email delivery retry conceals ticket backed deliveries outside current work access', function () {
    Notification::fake();
    $allowedSite = Site::factory()->create();
    $hiddenSite = Site::factory()->create();
    $actor = ingressContextActor($allowedSite, ['it.view', 'it.manage']);
    $recipient = User::factory()->create();
    $hiddenTicket = ItTicket::factory()->create([
        'site_id' => $hiddenSite->id,
        'is_sensitive' => true,
    ]);
    $delivery = ItEmailDelivery::factory()->create([
        'it_ticket_id' => $hiddenTicket->id,
        'recipient_user_id' => $recipient->id,
        'recipient_email' => $recipient->email,
        'status' => 'failed',
        'failed_at' => now(),
    ]);

    $this->actingAs($actor)
        ->post(route('it.setup.email-deliveries.retry', $delivery))
        ->assertNotFound();
    expect(fn () => app(ItEmailDeliveryService::class)->retry($delivery, $actor))
        ->toThrow(DomainException::class, 'not accessible');

    expect($delivery->fresh()->status)->toBe('failed')
        ->and($delivery->retryAttempt()->exists())->toBeFalse();
    Notification::assertNothingSent();
});

test('email delivery retry rechecks current ticket scope and fails closed for unowned provisioning work', function () {
    Notification::fake();
    $allowedSite = Site::factory()->create();
    $hiddenSite = Site::factory()->create();
    $actor = ingressContextActor($allowedSite, ['it.view', 'it.manage']);
    $recipient = User::factory()->create();
    $ticket = ItTicket::factory()->create(['site_id' => $allowedSite->id]);
    $staleDelivery = ItEmailDelivery::factory()->create([
        'it_ticket_id' => $ticket->id,
        'recipient_user_id' => $recipient->id,
        'recipient_email' => $recipient->email,
        'status' => 'failed',
        'failed_at' => now(),
    ]);

    ItTicket::query()->whereKey($ticket->id)->update(['site_id' => $hiddenSite->id]);

    expect(fn () => app(ItEmailDeliveryService::class)->retry($staleDelivery, $actor))
        ->toThrow(DomainException::class, 'not accessible');

    $profile = HrEmployeeProfile::factory()->create([
        'user_id' => $recipient->id,
        'primary_site_id' => null,
        'secondary_site_ids' => [],
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ]);
    $provisioning = ItProvisioningRequest::query()->create([
        'tenant_id' => 0,
        'employee_profile_id' => $profile->id,
        'type' => 'account',
        'item' => 'Create mailbox',
        'status' => 'pending',
        'assigned_to_user_id' => null,
        'responsible_team_id' => null,
        'created_by' => $actor->id,
    ]);
    $provisioningDelivery = ItEmailDelivery::factory()->create([
        'it_ticket_id' => null,
        'it_provisioning_request_id' => $provisioning->id,
        'recipient_user_id' => $recipient->id,
        'recipient_email' => $recipient->email,
        'notification_type' => 'it_provisioning_cancelled',
        'status' => 'failed',
        'failed_at' => now(),
    ]);

    $this->actingAs($actor)
        ->post(route('it.setup.email-deliveries.retry', $provisioningDelivery))
        ->assertNotFound();
    expect(fn () => app(ItEmailDeliveryService::class)->retry($provisioningDelivery, $actor))
        ->toThrow(DomainException::class, 'not accessible');

    expect($staleDelivery->fresh()->status)->toBe('failed')
        ->and($provisioningDelivery->fresh()->status)->toBe('failed')
        ->and(ItEmailDelivery::query()->whereNotNull('retry_of_delivery_id')->exists())->toBeFalse();
    Notification::assertNothingSent();
});

test('email retry and setup metadata recheck the current recipient and manager Site boundaries', function () {
    Notification::fake();
    $allowedSite = Site::factory()->create();
    $hiddenSite = Site::factory()->create();
    $manager = ingressContextActor($allowedSite, ['it.view', 'it.manage']);
    $recipient = ingressContextActor($allowedSite, ['it.view', 'it.manage']);
    $visibleTicket = ItTicket::factory()->create([
        'site_id' => $allowedSite->id,
        'assigned_to_user_id' => $recipient->id,
    ]);
    $hiddenTicket = ItTicket::factory()->create(['site_id' => $hiddenSite->id]);
    $visibleDelivery = ItEmailDelivery::factory()->create([
        'it_ticket_id' => $visibleTicket->id,
        'recipient_user_id' => $recipient->id,
        'recipient_email' => $recipient->email,
        'status' => 'failed',
        'failed_at' => now(),
    ]);
    $hiddenDelivery = ItEmailDelivery::factory()->create([
        'it_ticket_id' => $hiddenTicket->id,
        'recipient_user_id' => $recipient->id,
        'recipient_email' => $recipient->email,
        'status' => 'failed',
        'failed_at' => now(),
    ]);

    $this->actingAs($manager)
        ->get('/it/setup')
        ->assertInertia(fn ($page) => $page
            ->where('emailDeliveries.0.id', $visibleDelivery->id)
            ->has('emailDeliveries', 1));

    $visibleTicket->update(['assigned_to_user_id' => null]);
    $recipient->hrEmployeeProfile()->update(['primary_site_id' => $hiddenSite->id]);

    expect(fn () => app(ItEmailDeliveryService::class)->retry($visibleDelivery, $manager))
        ->toThrow(DomainException::class, 'recipient is no longer entitled');
    expect($visibleDelivery->fresh()->status)->toBe('failed')
        ->and($visibleDelivery->retryAttempt()->exists())->toBeFalse()
        ->and($hiddenDelivery->fresh()->status)->toBe('failed');
    Notification::assertNothingSent();
});
