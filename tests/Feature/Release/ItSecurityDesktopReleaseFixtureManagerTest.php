<?php

use App\Domain\It\Services\ItCatalogSubmissionService;
use App\Domain\It\Services\ItTicketInteractionService;
use App\Domain\Monitoring\Models\MonitoringIncidentEvidenceSnapshot;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Presenters\TrackingWorkspacePresenter;
use App\Models\AuditLog;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\Integration\IntegrationEvent;
use App\Models\ItAttachment;
use App\Models\ItCatalogItem;
use App\Models\ItCatalogSubmission;
use App\Models\ItEmailDelivery;
use App\Models\ItSecurityDesktopReleaseFixturePack;
use App\Models\ItTicket;
use App\Models\ItTicketComment;
use App\Models\ItTicketEvent;
use App\Models\Site;
use App\Models\User;
use App\Support\Release\ItSecurityDesktopReleaseFixtureManager;
use App\Support\Release\ItSecurityDesktopReleaseFixtureReadiness;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    Storage::fake('private');
    config()->set('it.desktop_release_fixtures.actor_password', 'release-only-password');
    config()->set('it.desktop_release_fixtures.reviewer_totp_secret', 'JBSWY3DPEHPK3PXP');
});

it('prepares one complete pack reuses it idempotently and removes only owned records', function (): void {
    $manager = app(ItSecurityDesktopReleaseFixtureManager::class);
    $firstRevision = str_repeat('a', 40);
    $secondRevision = str_repeat('b', 40);
    $unrelatedSite = Site::factory()->create(['name' => 'Unrelated retained Site']);
    $unrelatedUser = User::factory()->create(['email' => 'unrelated-retained@example.test']);

    $plan = $manager->plan('prepare', $firstRevision);
    $created = $manager->execute('prepare', $firstRevision);
    $pack = ItSecurityDesktopReleaseFixturePack::query()->sole();
    $ownedRecordCount = count($pack->manifest['records']);
    $actorCount = User::query()->whereIn(
        'email',
        array_keys(ItSecurityDesktopReleaseFixtureReadiness::ACTORS),
    )->count();
    $deviceCount = Device::query()->whereIn(
        'name',
        array_keys(ItSecurityDesktopReleaseFixtureReadiness::DEVICES),
    )->count();
    $ownedTrackingEvent = IntegrationEvent::query()
        ->where('source_event_id', ItSecurityDesktopReleaseFixtureReadiness::TRACKING_EVENT_SOURCE_ID)
        ->sole();
    $unrelatedDevice = Device::factory()->tracking()->create(['name' => 'Unrelated retained tracker']);
    $unrelatedEvent = IntegrationEvent::query()->create([
        'site_id' => $unrelatedSite->id,
        'canonical_device_id' => $unrelatedDevice->id,
        'provider' => 'unrelated_provider',
        'source_app' => 'unrelated_app',
        'source_event_id' => 'unrelated-retained-position',
        'occurred_at' => now()->subMinute(),
        'received_at' => now()->subMinute(),
        'severity' => IntegrationEvent::SEVERITY_INFO,
        'event_type' => 'location_report',
        'tags' => ['synthetic' => true],
        'normalized_payload' => ['lat' => 0.001, 'lng' => 0.001],
        'raw_payload' => null,
    ]);

    expect($plan)->toMatchArray([
        'state' => 'ready',
        'mode' => 'dry_run',
        'operation' => 'create',
        'fixture_mutation_applied' => false,
        'v10_release_evidence' => false,
    ])->and($created)->toMatchArray([
        'state' => 'ready',
        'mode' => 'execute',
        'operation' => 'created',
        'fixture_mutation_applied' => true,
        'v10_release_evidence' => false,
    ])->and($created['record_count'])->toBe($ownedRecordCount)
        ->and($ownedRecordCount)->toBeGreaterThan(40)
        ->and(app(ItSecurityDesktopReleaseFixtureReadiness::class)->assess()['state'])->toBe('ready')
        ->and(MonitoringIncidentEvidenceSnapshot::query()->count())->toBe(1);

    $hiddenSiteActor = User::query()
        ->where('email', 'release-denied@acceptance.invalid')
        ->sole();
    $sourceDeniedActor = User::query()
        ->where('email', 'release-source-denied@acceptance.invalid')
        ->sole();

    expect($hiddenSiteActor->canDo('controlRoom.viewAny'))->toBeTrue()
        ->and($hiddenSiteActor->canDo('controlRoom.alerts.view'))->toBeTrue()
        ->and($hiddenSiteActor->canDo('fleet.viewAny'))->toBeTrue()
        ->and($hiddenSiteActor->canDo('assets.telemetry.view'))->toBeTrue()
        ->and($hiddenSiteActor->canDo('assets.telemetry.export'))->toBeTrue()
        ->and($sourceDeniedActor->canDo('controlRoom.viewAny'))->toBeFalse()
        ->and($sourceDeniedActor->canDo('fleet.viewAny'))->toBeFalse()
        ->and($sourceDeniedActor->canDo('assets.telemetry.view'))->toBeFalse();

    Storage::disk('private')->assertExists('it-security-release-fixtures/release-network-evidence.txt');

    $reused = $manager->execute('prepare', $secondRevision);

    expect($reused)->toMatchArray([
        'state' => 'ready',
        'operation' => 'reused',
        'release_revision' => $secondRevision,
        'fixture_mutation_applied' => true,
    ])->and(ItSecurityDesktopReleaseFixturePack::query()->count())->toBe(1)
        ->and(ItSecurityDesktopReleaseFixturePack::query()->value('release_revision'))->toBe($secondRevision)
        ->and(User::query()->whereIn('email', array_keys(ItSecurityDesktopReleaseFixtureReadiness::ACTORS))->count())->toBe($actorCount)
        ->and(Device::query()->whereIn('name', array_keys(ItSecurityDesktopReleaseFixtureReadiness::DEVICES))->count())->toBe($deviceCount);

    $cleaned = $manager->execute('cleanup', $secondRevision);

    expect($cleaned)->toMatchArray([
        'state' => 'ready',
        'operation' => 'deleted_owned',
        'record_count' => $ownedRecordCount,
        'fixture_mutation_applied' => true,
    ])->and(ItSecurityDesktopReleaseFixturePack::query()->count())->toBe(0)
        ->and(User::query()->whereIn('email', array_keys(ItSecurityDesktopReleaseFixtureReadiness::ACTORS))->count())->toBe(0)
        ->and(Device::query()->whereIn('name', array_keys(ItSecurityDesktopReleaseFixtureReadiness::DEVICES))->count())->toBe(0)
        ->and(IntegrationEvent::query()->whereKey($ownedTrackingEvent->id)->exists())->toBeFalse()
        ->and(IntegrationEvent::query()->whereKey($unrelatedEvent->id)->exists())->toBeTrue()
        ->and(Device::query()->whereKey($unrelatedDevice->id)->exists())->toBeTrue()
        ->and(Site::query()->whereKey($unrelatedSite->id)->exists())->toBeTrue()
        ->and(User::query()->whereKey($unrelatedUser->id)->exists())->toBeTrue();
    Storage::disk('private')->assertMissing('it-security-release-fixtures/release-network-evidence.txt');
});

it('refuses a reserved identity before writing any owned pack record', function (): void {
    $manager = app(ItSecurityDesktopReleaseFixtureManager::class);
    $revision = str_repeat('c', 40);
    $reserved = Site::factory()->create(['name' => 'RELEASE Site Alpha']);

    $plan = $manager->plan('prepare', $revision);
    $executed = $manager->execute('prepare', $revision);

    expect($plan['state'])->toBe('failed')
        ->and($plan['gap_codes'])->toBe(['release_fixture_reserved_identity_present'])
        ->and($executed['state'])->toBe('failed')
        ->and($executed['fixture_mutation_applied'])->toBeFalse()
        ->and(ItSecurityDesktopReleaseFixturePack::query()->count())->toBe(0)
        ->and(Site::query()->whereKey($reserved->id)->exists())->toBeTrue()
        ->and(User::query()->whereIn('email', array_keys(ItSecurityDesktopReleaseFixtureReadiness::ACTORS))->count())->toBe(0);
});

it('refuses the exact reserved tracking event identity before fixture creation', function (): void {
    $site = Site::factory()->create();
    IntegrationEvent::query()->create([
        'site_id' => $site->id,
        'canonical_device_id' => null,
        'provider' => ItSecurityDesktopReleaseFixtureReadiness::TRACKING_EVENT_PROVIDER,
        'source_app' => ItSecurityDesktopReleaseFixtureReadiness::TRACKING_EVENT_SOURCE_APP,
        'source_event_id' => ItSecurityDesktopReleaseFixtureReadiness::TRACKING_EVENT_SOURCE_ID,
        'occurred_at' => now(),
        'received_at' => now(),
        'severity' => IntegrationEvent::SEVERITY_INFO,
        'event_type' => ItSecurityDesktopReleaseFixtureReadiness::TRACKING_EVENT_TYPE,
        'tags' => ItSecurityDesktopReleaseFixtureReadiness::TRACKING_EVENT_TAGS,
        'normalized_payload' => ItSecurityDesktopReleaseFixtureReadiness::TRACKING_EVENT_PAYLOAD,
        'raw_payload' => null,
    ]);

    $manager = app(ItSecurityDesktopReleaseFixtureManager::class);
    $revision = str_repeat('d', 40);

    expect($manager->plan('prepare', $revision))->toMatchArray([
        'state' => 'failed',
        'gap_codes' => ['release_fixture_reserved_identity_present'],
        'fixture_mutation_applied' => false,
    ])->and($manager->execute('prepare', $revision))->toMatchArray([
        'state' => 'failed',
        'gap_codes' => ['release_fixture_reserved_identity_present'],
        'fixture_mutation_applied' => false,
    ])->and(ItSecurityDesktopReleaseFixturePack::query()->count())->toBe(0);
});

it('withdraws and resets only the owned personal tracking consent baseline', function (): void {
    $manager = app(ItSecurityDesktopReleaseFixtureManager::class);
    $revision = str_repeat('f', 40);
    $manager->execute('prepare', $revision);

    $consent = ClientConsent::query()
        ->whereHas('consentType', fn ($query) => $query->where('name', 'RELEASE Client Location Tracking'))
        ->sole();
    $assignment = DeviceAssignment::query()->where('consent_id', $consent->id)->sole();
    $device = Device::query()->where('name', 'RELEASE Alpha Personal Tracker')->sole();
    $event = IntegrationEvent::query()
        ->where('source_event_id', ItSecurityDesktopReleaseFixtureReadiness::TRACKING_EVENT_SOURCE_ID)
        ->sole();
    $viewer = User::query()->where('email', 'release-control-room@acceptance.invalid')->sole();
    $eventSnapshotFields = [
        'id',
        'site_id',
        'canonical_device_id',
        'provider',
        'source_app',
        'source_event_id',
        'occurred_at',
        'received_at',
        'severity',
        'event_type',
        'tags',
        'normalized_payload',
        'raw_payload',
    ];
    $eventSnapshot = json_encode($event->only($eventSnapshotFields), JSON_THROW_ON_ERROR);
    $history = fn () => collect(app(TrackingWorkspacePresenter::class)->present(
        $viewer,
        Device::query()->whereKey($device->id),
        [
            'key' => 'history',
            'label' => 'History',
            'description' => 'Retained authorised fixture history.',
        ],
    )['activeTab']['history']);

    expect($consent->status)->toBe('given')
        ->and($assignment->isCollectionActive())->toBeTrue()
        ->and((float) $device->latitude)->toBe(ItSecurityDesktopReleaseFixtureReadiness::TRACKING_LATITUDE)
        ->and((float) $device->longitude)->toBe(ItSecurityDesktopReleaseFixtureReadiness::TRACKING_LONGITUDE)
        ->and($device->last_seen_at?->equalTo($event->occurred_at))->toBeTrue()
        ->and($event->provider)->toBe(ItSecurityDesktopReleaseFixtureReadiness::TRACKING_EVENT_PROVIDER)
        ->and($event->source_app)->toBe(ItSecurityDesktopReleaseFixtureReadiness::TRACKING_EVENT_SOURCE_APP)
        ->and($event->event_type)->toBe(ItSecurityDesktopReleaseFixtureReadiness::TRACKING_EVENT_TYPE)
        ->and(Arr::sortRecursive((array) $event->tags))->toBe(Arr::sortRecursive(ItSecurityDesktopReleaseFixtureReadiness::TRACKING_EVENT_TAGS))
        ->and(Arr::sortRecursive((array) $event->normalized_payload))->toBe(Arr::sortRecursive(ItSecurityDesktopReleaseFixtureReadiness::TRACKING_EVENT_PAYLOAD))
        ->and($event->raw_payload)->toBeNull()
        ->and($history()->pluck('deviceName')->all())->toBe(['RELEASE Alpha Personal Tracker'])
        ->and(app(ItSecurityDesktopReleaseFixtureReadiness::class)->assess()['state'])->toBe('ready')
        ->and($manager->plan('withdraw-tracking-consent', $revision)['state'])->toBe('ready');
    $this->actingAs($viewer)
        ->getJson("/operations/clients/{$consent->client_id}/location/history")
        ->assertOk()
        ->assertJsonCount(1, 'locations');

    $withdrawn = $manager->execute('withdraw-tracking-consent', $revision);
    $consent->refresh();
    $assignment->refresh();
    $device->refresh();
    $event->refresh();

    expect($withdrawn)->toMatchArray([
        'state' => 'ready',
        'operation' => 'withdrew_owned_tracking_consent',
        'fixture_mutation_applied' => true,
    ])->and($consent->status)->toBe('withdrawn')
        ->and($assignment->isCollectionActive())->toBeFalse()
        ->and($device->latitude)->toBeNull()
        ->and($device->longitude)->toBeNull()
        ->and($history())->toBeEmpty()
        ->and(json_encode($event->only($eventSnapshotFields), JSON_THROW_ON_ERROR))->toBe($eventSnapshot)
        ->and(AuditLog::query()->where('action', 'tracking.collection.stopped')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'tracking.consent.withdrawal_enforced')->exists())->toBeTrue()
        ->and($manager->plan('reset', $revision)['state'])->toBe('ready');
    $this->actingAs($viewer)
        ->getJson("/operations/clients/{$consent->client_id}/location/history")
        ->assertForbidden();

    $reset = $manager->execute('reset', $revision);
    $consent->refresh();
    $assignment->refresh();
    $device->refresh();
    $event->refresh();

    expect($reset)->toMatchArray([
        'state' => 'ready',
        'operation' => 'restored_owned_tracking_baseline',
        'fixture_mutation_applied' => true,
    ])->and($consent->status)->toBe('given')
        ->and($consent->withdrawn_at)->toBeNull()
        ->and($assignment->isCollectionActive())->toBeTrue()
        ->and((float) $device->latitude)->toBe(ItSecurityDesktopReleaseFixtureReadiness::TRACKING_LATITUDE)
        ->and((float) $device->longitude)->toBe(ItSecurityDesktopReleaseFixtureReadiness::TRACKING_LONGITUDE)
        ->and($device->last_seen_at?->equalTo($event->occurred_at))->toBeTrue()
        ->and($history()->pluck('deviceName')->all())->toBe(['RELEASE Alpha Personal Tracker'])
        ->and(json_encode($event->only($eventSnapshotFields), JSON_THROW_ON_ERROR))->toBe($eventSnapshot)
        ->and(AuditLog::query()->where('action', 'tracking.collection.stopped')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'tracking.consent.withdrawal_enforced')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'tracking.collection.resumed')->exists())->toBeTrue()
        ->and(ConsentType::query()->where('name', 'RELEASE Client Location Tracking')->count())->toBe(1)
        ->and(app(ItSecurityDesktopReleaseFixtureReadiness::class)->assess()['state'])->toBe('ready');
    $this->actingAs($viewer)
        ->getJson("/operations/clients/{$consent->client_id}/location/history")
        ->assertOk()
        ->assertJsonCount(1, 'locations');
});

it('refuses withdrawal and reset when the owned consent is shared with a non manifest assignment', function (): void {
    $manager = app(ItSecurityDesktopReleaseFixtureManager::class);
    $revision = str_repeat('9', 40);
    $manager->execute('prepare', $revision);

    $consent = ClientConsent::query()
        ->whereHas('consentType', fn ($query) => $query->where('name', 'RELEASE Client Location Tracking'))
        ->sole();
    $fixtureAssignment = DeviceAssignment::query()->where('consent_id', $consent->id)->sole();
    $fixtureDevice = Device::query()->where('name', 'RELEASE Alpha Personal Tracker')->sole();
    $actor = User::query()->where('email', 'release-control-room@acceptance.invalid')->sole();
    $extraDevice = Device::factory()->tracking()->create(['name' => 'Non manifest consent-sharing tracker']);
    $extraAssignment = DeviceAssignment::query()->create([
        'device_id' => $extraDevice->id,
        'assignable_type' => DeviceAssignment::TARGET_CLIENT,
        'assignable_id' => $consent->client_id,
        'assignment_type' => 'permanent',
        'assigned_at' => now(),
        'assigned_by_user_id' => $actor->id,
        'consent_id' => $consent->id,
        'tracking_purpose' => 'Client personal safety tracking',
        'authority_basis' => 'assignment_linked_client_consent',
        'access_audience' => ['authorised_client_care', 'control_room', 'health_and_safety'],
        'retention_days' => 90,
        'collection_started_at' => now(),
    ]);
    $before = json_encode([
        'consent' => $consent->fresh()->only(['status', 'withdrawn_at', 'withdrawn_by_user_id', 'withdrawal_reason']),
        'fixture_assignment' => $fixtureAssignment->fresh()->only(['collection_started_at', 'collection_stopped_at', 'collection_stop_reason', 'withdrawal_outcome']),
        'extra_assignment' => $extraAssignment->fresh()->only(['collection_started_at', 'collection_stopped_at', 'collection_stop_reason', 'withdrawal_outcome']),
        'fixture_device' => $fixtureDevice->fresh()->only(['latitude', 'longitude', 'last_seen_at']),
        'audit_count' => AuditLog::query()->count(),
    ], JSON_THROW_ON_ERROR);
    $expectedGap = ['release_fixture_tracking_consent_assignment_scope_mismatch'];

    foreach (['withdraw-tracking-consent', 'reset'] as $action) {
        expect($manager->plan($action, $revision))->toMatchArray([
            'state' => 'failed',
            'gap_codes' => $expectedGap,
            'fixture_mutation_applied' => false,
        ])->and($manager->execute($action, $revision))->toMatchArray([
            'state' => 'failed',
            'gap_codes' => $expectedGap,
            'fixture_mutation_applied' => false,
        ]);
    }

    $after = json_encode([
        'consent' => $consent->fresh()->only(['status', 'withdrawn_at', 'withdrawn_by_user_id', 'withdrawal_reason']),
        'fixture_assignment' => $fixtureAssignment->fresh()->only(['collection_started_at', 'collection_stopped_at', 'collection_stop_reason', 'withdrawal_outcome']),
        'extra_assignment' => $extraAssignment->fresh()->only(['collection_started_at', 'collection_stopped_at', 'collection_stop_reason', 'withdrawal_outcome']),
        'fixture_device' => $fixtureDevice->fresh()->only(['latitude', 'longitude', 'last_seen_at']),
        'audit_count' => AuditLog::query()->count(),
    ], JSON_THROW_ON_ERROR);

    expect($after)->toBe($before);
});

it('reports a value free readiness gap when retained tracking history drifts', function (): void {
    $manager = app(ItSecurityDesktopReleaseFixtureManager::class);
    $revision = str_repeat('8', 40);
    $manager->execute('prepare', $revision);

    IntegrationEvent::query()
        ->where('source_event_id', ItSecurityDesktopReleaseFixtureReadiness::TRACKING_EVENT_SOURCE_ID)
        ->sole()
        ->update(['normalized_payload' => ['synthetic' => true]]);

    $readiness = app(ItSecurityDesktopReleaseFixtureReadiness::class)->assess();

    expect($readiness['state'])->toBe('not_ready')
        ->and($readiness['gap_codes'])->toContain('release_personal_tracking_history_baseline_missing')
        ->and($manager->plan('reset', $revision)['gap_codes'])->toBe([
            'release_fixture_tracking_history_baseline_mismatch',
        ]);
});

it('cleans every D01 attempt for the exact owned requester and catalogue pair while preserving lookalikes', function (): void {
    $manager = app(ItSecurityDesktopReleaseFixtureManager::class);
    $revision = str_repeat('e', 40);
    $manager->execute('prepare', $revision);

    $requester = User::query()->where('email', 'release-requester@acceptance.invalid')->sole();
    $catalog = ItCatalogItem::query()->where('slug', 'release-access-request')->sole();
    $outcomes = collect(['d01-browser-run-1', 'd01-browser-run-2'])
        ->map(fn (string $idempotencyKey): array => app(ItCatalogSubmissionService::class)->submit(
            $catalog,
            $requester,
            [
                'schema_version' => $catalog->form_schema_version,
                'values' => [],
                'idempotency_key' => $idempotencyKey,
            ],
        ));
    $tickets = $outcomes->pluck('result');
    $this->actingAs($requester);
    $comments = $tickets->map(fn (ItTicket $ticket, int $index) => app(ItTicketInteractionService::class)->addComment(
        $ticket,
        $requester,
        'D01 requester-visible comment '.($index + 1).'.',
        false,
    )['comment']);
    /** @var ItTicket $firstTicket */
    $firstTicket = $tickets->first();
    $delivery = ItEmailDelivery::query()->create([
        'notification_uuid' => (string) Str::uuid(),
        'it_ticket_id' => $firstTicket->id,
        'recipient_user_id' => $requester->id,
        'recipient_email' => $requester->email,
        'notification_type' => 'ticket_created',
        'subject' => 'D01 receipt',
        'status' => 'queued',
    ]);
    $retry = ItEmailDelivery::query()->create([
        'notification_uuid' => (string) Str::uuid(),
        'retry_of_delivery_id' => $delivery->id,
        'it_ticket_id' => $firstTicket->id,
        'recipient_user_id' => $requester->id,
        'recipient_email' => $requester->email,
        'notification_type' => 'ticket_created',
        'subject' => 'D01 receipt retry',
        'status' => 'queued',
    ]);
    $unrelatedRequester = User::factory()->create();
    $unrelatedCatalog = ItCatalogItem::query()->create([
        'name' => 'RELEASE Access Request lookalike',
        'slug' => 'release-access-request-lookalike',
        'description' => 'Unrelated retained catalogue item.',
        'outcome_type' => 'service_request',
        'category' => 'account',
        'default_priority' => 'normal',
        'requires_approval' => false,
        'is_published' => true,
        'internal_only' => false,
        'form_schema_version' => 1,
        'form_schema' => ['fields' => []],
        'sort_order' => 999,
    ]);
    $unrelatedTicket = ItTicket::factory()->create([
        'requester_user_id' => $unrelatedRequester->id,
        'title' => 'RELEASE Access Request lookalike',
    ]);
    $unrelatedSubmission = ItCatalogSubmission::query()->create([
        'catalog_item_id' => $unrelatedCatalog->id,
        'requester_user_id' => $unrelatedRequester->id,
        'schema_version' => 1,
        'schema_snapshot' => ['fields' => []],
        'submitted_values' => [],
        'idempotency_key' => 'unrelated-d01-lookalike',
        'result_type' => $unrelatedTicket->getMorphClass(),
        'result_id' => $unrelatedTicket->id,
        'submitted_at' => now(),
    ]);

    $manager->execute('cleanup', $revision);

    $submissionIds = $outcomes->pluck('submission.id');
    $ticketIds = $tickets->pluck('id');
    expect(ItCatalogSubmission::query()->whereIn('id', $submissionIds)->exists())->toBeFalse()
        ->and(ItTicket::query()->whereIn('id', $ticketIds)->exists())->toBeFalse()
        ->and(ItTicketComment::query()->whereIn('id', $comments->pluck('id'))->exists())->toBeFalse()
        ->and(ItTicketEvent::query()->where('subject_type', $firstTicket->getMorphClass())->whereIn('subject_id', $ticketIds)->exists())->toBeFalse()
        ->and(ItEmailDelivery::query()->whereIn('id', [$delivery->id, $retry->id])->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'it.ticket.comment.added')->whereIn('auditable_id', $ticketIds)->count())->toBe(2)
        ->and(ItCatalogItem::query()->whereKey($unrelatedCatalog->id)->exists())->toBeTrue()
        ->and(ItCatalogSubmission::query()->whereKey($unrelatedSubmission->id)->exists())->toBeTrue()
        ->and(ItTicket::query()->whereKey($unrelatedTicket->id)->exists())->toBeTrue();
});

it('fails closed when D01 has an unexpected private attachment', function (): void {
    $manager = app(ItSecurityDesktopReleaseFixtureManager::class);
    $revision = str_repeat('g', 40);
    $manager->execute('prepare', $revision);

    $requester = User::query()->where('email', 'release-requester@acceptance.invalid')->sole();
    $catalog = ItCatalogItem::query()->where('slug', 'release-access-request')->sole();
    $outcome = app(ItCatalogSubmissionService::class)->submit($catalog, $requester, [
        'schema_version' => $catalog->form_schema_version,
        'values' => [],
        'idempotency_key' => 'd01-unexpected-attachment',
    ]);
    /** @var ItTicket $ticket */
    $ticket = $outcome['result'];
    $attachment = ItAttachment::query()->create([
        'attachable_type' => $ticket->getMorphClass(),
        'attachable_id' => $ticket->id,
        'path' => 'it-security-release-fixtures/d01-unexpected.txt',
        'original_name' => 'd01-unexpected.txt',
        'mime' => 'text/plain',
        'size' => 1,
        'uploaded_by' => $requester->id,
    ]);

    expect(fn () => $manager->execute('cleanup', $revision))
        ->toThrow(DomainException::class, 'D01 release acceptance does not permit private attachments.')
        ->and(ItSecurityDesktopReleaseFixturePack::query()->sole()->state)->toBe(ItSecurityDesktopReleaseFixturePack::STATE_READY)
        ->and(ItCatalogSubmission::query()->whereKey($outcome['submission']->id)->exists())->toBeTrue()
        ->and(ItAttachment::query()->whereKey($attachment->id)->exists())->toBeTrue();
});

it('resumes a durable pending file-cleanup journal idempotently', function (): void {
    $manager = app(ItSecurityDesktopReleaseFixtureManager::class);
    $revision = str_repeat('h', 40);
    $manager->execute('prepare', $revision);
    $pack = ItSecurityDesktopReleaseFixturePack::query()->sole();
    $pack->update(['state' => ItSecurityDesktopReleaseFixturePack::STATE_CLEANUP_FILES_PENDING]);

    $cleaned = $manager->execute('cleanup', $revision);

    expect($cleaned['operation'])->toBe('deleted_owned')
        ->and(ItSecurityDesktopReleaseFixturePack::query()->count())->toBe(0);
    Storage::disk('private')->assertMissing('it-security-release-fixtures/release-network-evidence.txt');
});

it('refuses cleanup when an owned file is missing or the manifest is corrupt and preserves every record', function (): void {
    $manager = app(ItSecurityDesktopReleaseFixtureManager::class);
    $revision = str_repeat('d', 40);
    $manager->execute('prepare', $revision);
    $pack = ItSecurityDesktopReleaseFixturePack::query()->sole();
    $deviceCount = Device::query()->whereIn(
        'name',
        array_keys(ItSecurityDesktopReleaseFixtureReadiness::DEVICES),
    )->count();
    Storage::disk('private')->delete('it-security-release-fixtures/release-network-evidence.txt');

    $missingFileReport = $manager->execute('cleanup', $revision);

    expect($missingFileReport['state'])->toBe('failed')
        ->and($missingFileReport['gap_codes'])->toBe(['release_fixture_owned_file_mismatch'])
        ->and($missingFileReport['fixture_mutation_applied'])->toBeFalse()
        ->and(ItSecurityDesktopReleaseFixturePack::query()->count())->toBe(1)
        ->and(Device::query()->whereIn('name', array_keys(ItSecurityDesktopReleaseFixtureReadiness::DEVICES))->count())->toBe($deviceCount);

    Storage::disk('private')->put(
        'it-security-release-fixtures/release-network-evidence.txt',
        "Non-sensitive desktop release acceptance evidence.\n",
    );
    $pack->update(['manifest_sha256' => str_repeat('0', 64)]);

    $report = $manager->execute('cleanup', $revision);

    expect($report['state'])->toBe('failed')
        ->and($report['gap_codes'])->toBe(['release_fixture_pack_integrity_failed'])
        ->and($report['fixture_mutation_applied'])->toBeFalse()
        ->and(ItSecurityDesktopReleaseFixturePack::query()->count())->toBe(1)
        ->and(Device::query()->whereIn('name', array_keys(ItSecurityDesktopReleaseFixtureReadiness::DEVICES))->count())->toBe($deviceCount);
    Storage::disk('private')->assertExists('it-security-release-fixtures/release-network-evidence.txt');
});

it('retains the fixture pack when immutable D16 batch evidence references an owned door', function (): void {
    $revision = str_repeat('d', 40);
    $manager = app(ItSecurityDesktopReleaseFixtureManager::class);
    expect($manager->execute('prepare', $revision)['state'])->toBe('ready');

    $managerUserId = (int) User::query()
        ->where('email', 'release-it-manager@acceptance.invalid')
        ->value('id');
    $doorId = (int) Device::query()
        ->where('name', 'RELEASE Alpha Door')
        ->value('id');
    $siteId = (int) Site::query()
        ->where('name', 'RELEASE Site Alpha')
        ->value('id');
    $batchId = DB::table('device_command_batches')->insertGetId([
        'batch_uuid' => (string) Str::uuid(),
        'requested_by_user_id' => $managerUserId,
        'workspace' => 'security',
        'capability' => 'access.door.unlock_timed',
        'capability_version' => 1,
        'risk' => 'high',
        'confirmation_mode' => 'acknowledge_impact',
        'reason' => 'Retained simulated D16 partial bulk lifecycle evidence.',
        'safe_parameter_summary' => json_encode(['duration_seconds' => 15], JSON_THROW_ON_ERROR),
        'idempotency_key' => 'retained-d16-fixture-batch',
        'contract_hash' => hash('sha256', 'retained-d16-fixture-batch'),
        'target_count' => 1,
        'included_count' => 0,
        'excluded_count' => 1,
        'site_count' => 0,
        'impact_acknowledged_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('device_command_batch_targets')->insert([
        'device_command_batch_id' => $batchId,
        'device_id' => $doorId,
        'site_id' => $siteId,
        'device_command_request_id' => null,
        'position' => 1,
        'inclusion_status' => 'excluded',
        'safe_exclusion_code' => 'simulated_partial_result',
        'safe_exclusion_reason' => 'Retained non-provider fixture outcome.',
        'created_at' => now(),
    ]);

    $plan = $manager->plan('cleanup', $revision);
    $execution = $manager->execute('cleanup', $revision);

    expect($plan)->toMatchArray([
        'state' => 'failed',
        'fixture_mutation_applied' => false,
        'gap_codes' => ['release_fixture_retained_d16_evidence_requires_pack_archive'],
    ])->and($execution)->toMatchArray([
        'state' => 'failed',
        'fixture_mutation_applied' => false,
        'gap_codes' => ['release_fixture_retained_d16_evidence_requires_pack_archive'],
    ])->and(ItSecurityDesktopReleaseFixturePack::query()->count())->toBe(1)
        ->and(Device::query()->whereKey($doorId)->exists())->toBeTrue()
        ->and(DB::table('device_command_batches')->where('id', $batchId)->exists())->toBeTrue();
});

it('rechecks retained D16 evidence under the cleanup lock before deleting any owned record', function (): void {
    $revision = str_repeat('e', 40);
    $manager = app(ItSecurityDesktopReleaseFixtureManager::class);
    expect($manager->execute('prepare', $revision)['state'])->toBe('ready');

    $managerUserId = (int) User::query()
        ->where('email', 'release-it-manager@acceptance.invalid')
        ->value('id');
    $doorId = (int) Device::query()->where('name', 'RELEASE Alpha Door')->value('id');
    $siteId = (int) Site::query()->where('name', 'RELEASE Site Alpha')->value('id');
    $catalogueId = (int) ItCatalogItem::query()->where('name', 'RELEASE Access Request')->value('id');
    $packSelects = 0;
    $evidenceInserted = false;

    DB::listen(function ($query) use (
        $managerUserId,
        $doorId,
        $siteId,
        &$packSelects,
        &$evidenceInserted,
    ): void {
        $sql = strtolower(ltrim((string) $query->sql));
        if (! str_starts_with($sql, 'select')
            || ! str_contains($sql, 'it_security_desktop_release_fixture_packs')) {
            return;
        }
        $packSelects++;
        if ($packSelects !== 2 || $evidenceInserted) {
            return;
        }
        $evidenceInserted = true;
        $batchId = DB::table('device_command_batches')->insertGetId([
            'batch_uuid' => (string) Str::uuid(),
            'requested_by_user_id' => $managerUserId,
            'workspace' => 'security',
            'capability' => 'access.door.unlock_timed',
            'capability_version' => 1,
            'risk' => 'high',
            'confirmation_mode' => 'acknowledge_impact',
            'reason' => 'D16 evidence inserted after cleanup planning for the lock regression.',
            'safe_parameter_summary' => json_encode(['duration_seconds' => 15], JSON_THROW_ON_ERROR),
            'idempotency_key' => 'd16-cleanup-lock-regression',
            'contract_hash' => hash('sha256', 'd16-cleanup-lock-regression'),
            'target_count' => 1,
            'included_count' => 0,
            'excluded_count' => 1,
            'site_count' => 0,
            'impact_acknowledged_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('device_command_batch_targets')->insert([
            'device_command_batch_id' => $batchId,
            'device_id' => $doorId,
            'site_id' => $siteId,
            'device_command_request_id' => null,
            'position' => 1,
            'inclusion_status' => 'excluded',
            'safe_exclusion_code' => 'simulated_partial_result',
            'safe_exclusion_reason' => 'Retained no-network fixture outcome.',
            'created_at' => now(),
        ]);
    });

    expect(fn () => $manager->execute('cleanup', $revision))
        ->toThrow(DomainException::class, 'release_fixture_retained_d16_evidence_requires_pack_archive');

    expect($evidenceInserted)->toBeTrue()
        ->and(ItSecurityDesktopReleaseFixturePack::query()->count())->toBe(1)
        ->and(Device::query()->whereKey($doorId)->exists())->toBeTrue()
        ->and(ItCatalogItem::query()->whereKey($catalogueId)->exists())->toBeTrue();
});
