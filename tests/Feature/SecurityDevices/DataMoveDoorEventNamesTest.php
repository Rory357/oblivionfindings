<?php

use App\Domain\SecurityDevices\Access\Models\AccessConsole;
use App\Domain\SecurityDevices\Access\Models\AccessEvent;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\AuditLog;
use App\Models\ControlRoom\Signal;
use App\Models\Integration\IntegrationEvent;
use App\Models\Site;
use App\Models\TimelineEvent;
use App\Models\User;
use Illuminate\Support\Str;

/*
 * S&D data move D14, "Names on old door events older than 90 days" (security-devices:moves:door-event-names).
 * The dry run only counts. Clearing can't be undone, so it needs --apply --i-have-a-backup; it clears
 * names (and the name inside summaries) on rows older than 90 days only, writes one audit row each
 * without the name, leaves newer rows and the new Access tables alone, and a second run does nothing.
 * Control Room signals copied from door events are cleared the same way (Stephan, 2 Nov: "Yes, same 90 days").
 */

beforeEach(function (): void {
    $this->site = Site::factory()->create(['name' => 'Synthetic House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    $this->actor = User::factory()->create(['name' => 'Synthetic Mover', 'approved_at' => now()]);
    $this->door = Device::factory()->security()->create(['name' => 'Synthetic front door', 'category' => 'access_control', 'subcategory' => 'card_reader', 'provider' => 'unifi']);

    $this->integration = fn (int $daysAgo, ?string $actor) => IntegrationEvent::factory()->create([
        'site_id' => $this->site->id, 'canonical_device_id' => $this->door->id, 'hardware_id' => null,
        'provider' => 'unifi', 'source_app' => 'access', 'source_event_id' => 'access-log-'.Str::uuid(),
        'occurred_at' => now()->subDays($daysAgo), 'received_at' => now()->subDays($daysAgo), 'severity' => 'info', 'event_type' => 'door_opened',
        'normalized_payload' => array_filter(['summary' => 'NFC: '.($actor ?? 'Unknown user').' at Front door', 'actor_name' => $actor, 'door_name' => 'Front door', 'direction' => 'NFC']),
        'raw_payload' => [],
    ]);
    $this->timeline = fn (int $daysAgo, int $sourceId) => TimelineEvent::factory()->create([
        'source_type' => 'unifi_access', 'source_id' => $sourceId, 'type' => 'unifi_access', 'site_id' => $this->site->id,
        'occurred_at' => now()->subDays($daysAgo), 'subject' => 'NFC: Mereana Synthetica at Front door', 'body' => 'NFC: Mereana Synthetica at Front door',
        'meta' => ['event_type' => 'door_opened', 'door' => 'Front door', 'user' => 'Mereana Synthetica', 'direction' => 'NFC', 'provider' => 'unifi'],
    ]);
    $this->deviceEvent = fn (int $daysAgo) => DeviceEvent::query()->create([
        'device_id' => $this->door->id, 'event_type' => 'door_opened', 'severity' => 'info', 'source' => 'test',
        'payload' => ['actor_name' => 'Mereana Synthetica', 'summary' => 'NFC: Mereana Synthetica', 'door_name' => 'Front door', 'direction' => 'NFC'],
        'occurred_at' => now()->subDays($daysAgo),
    ]);

    $this->signal = fn (int $daysAgo) => Signal::query()->create([
        'signal_type_code' => 'integration_door_forced', 'site_id' => $this->site->id, 'severity_hint' => 'high',
        'idempotency_key' => 'door-'.Str::uuid(), 'occurred_at' => now()->subDays($daysAgo), 'status' => 'processed',
        'payload' => ['summary' => 'FORCED: Mereana Synthetica at Front door', 'actor_name' => 'Mereana Synthetica', 'door_name' => 'Front door', 'direction' => 'FORCED'],
        'normalized_data' => ['provider' => 'unifi', 'title' => 'Door forced open', 'description' => 'FORCED: Mereana Synthetica at Front door', 'site_id' => $this->site->id],
    ]);

    $this->oldIntegration = ($this->integration)(100, 'Mereana Synthetica');
    $this->newIntegration = ($this->integration)(10, 'Mereana Synthetica');
    $this->oldUnnamed = ($this->integration)(100, null);
    $this->oldTimeline = ($this->timeline)(100, 9001);
    $this->newTimeline = ($this->timeline)(10, 9002);
    $this->oldDeviceEvent = ($this->deviceEvent)(100);
    $this->newDeviceEvent = ($this->deviceEvent)(10);
    $this->oldSignal = ($this->signal)(100);
    $this->newSignal = ($this->signal)(10);

    // The new Access tables clear their own names; this move never touches them.
    $console = AccessConsole::query()->create(['site_id' => $this->site->id, 'address' => '10.78.0.40', 'port' => 12445, 'state' => 'ok']);
    $this->accessEvent = AccessEvent::query()->create([
        'console_id' => $console->id, 'event_key' => hash('sha256', (string) Str::uuid()), 'kind' => 'opened', 'method' => 'card',
        'actor_name' => 'Mereana Synthetica', 'occurred_at' => now()->subDays(100),
    ]);

    $this->cleared = fn (): int => AuditLog::query()->where('action', 'security_devices.data_move.door_event_names.cleared')->count();
});

it('counts names older than 90 days in a dry run and changes nothing', function (): void {
    $this->artisan('security-devices:moves:door-event-names')
        ->expectsOutputToContain('normalized_payload: actor_name and summary')
        ->expectsOutputToContain('Not touched: cleared by the Access sync itself')
        ->expectsOutputToContain('payload: actor_name and summary; normalized_data: description')
        ->expectsOutputToContain('Clearing can’t be undone')
        ->expectsOutputToContain('Dry run: nothing changed')
        ->assertSuccessful();

    expect($this->oldIntegration->fresh()->normalized_payload['actor_name'])->toBe('Mereana Synthetica')
        ->and($this->oldTimeline->fresh()->meta['user'])->toBe('Mereana Synthetica')
        ->and($this->oldDeviceEvent->fresh()->payload['actor_name'])->toBe('Mereana Synthetica')
        ->and($this->oldSignal->fresh()->payload['actor_name'])->toBe('Mereana Synthetica')
        ->and(($this->cleared)())->toBe(0);
});

it('refuses --apply without --i-have-a-backup, and in production without --force', function (): void {
    $this->artisan('security-devices:moves:door-event-names', ['--apply' => true, '--actor' => $this->actor->id])
        ->expectsOutputToContain('can’t be undone')
        ->assertFailed();

    $this->app['env'] = 'production';
    $this->artisan('security-devices:moves:door-event-names', ['--apply' => true, '--i-have-a-backup' => true, '--actor' => $this->actor->id])
        ->assertFailed();

    expect($this->oldIntegration->fresh()->normalized_payload['actor_name'])->toBe('Mereana Synthetica')
        ->and(($this->cleared)())->toBe(0);
});

it('clears only names older than 90 days, audits each row without the name, and is idempotent', function (): void {
    $this->artisan('security-devices:moves:door-event-names', ['--apply' => true, '--i-have-a-backup' => true, '--actor' => $this->actor->id])
        ->expectsOutputToContain('Clearing names on 4 rows now')
        ->assertSuccessful();

    $integration = $this->oldIntegration->fresh()->normalized_payload;
    expect($integration)->not->toHaveKey('actor_name')
        ->and($integration['summary'])->toBe('NFC at Front door (name cleared after 90 days)')
        ->and($integration['door_name'])->toBe('Front door');

    $timeline = $this->oldTimeline->fresh();
    expect($timeline->meta)->not->toHaveKey('user')
        ->and($timeline->subject)->toBe('NFC at Front door (name cleared after 90 days)')
        ->and($timeline->body)->not->toContain('Mereana');

    expect($this->oldDeviceEvent->fresh()->payload)->not->toHaveKey('actor_name')
        ->and($this->oldDeviceEvent->fresh()->payload['summary'])->not->toContain('Mereana');

    // The Control Room's copy: the name goes from the payload and from the operator-facing description.
    $signal = $this->oldSignal->fresh();
    expect($signal->payload)->not->toHaveKey('actor_name')
        ->and($signal->payload['summary'])->toBe('FORCED at Front door (name cleared after 90 days)')
        ->and($signal->normalized_data['description'])->toBe('FORCED at Front door (name cleared after 90 days)')
        ->and($signal->normalized_data['title'])->toBe('Door forced open')
        ->and(json_encode([$signal->payload, $signal->normalized_data]))->not->toContain('Mereana');

    // Newer than 90 days, the unnamed row and the new Access tables: untouched.
    expect($this->newIntegration->fresh()->normalized_payload['actor_name'])->toBe('Mereana Synthetica')
        ->and($this->newTimeline->fresh()->meta['user'])->toBe('Mereana Synthetica')
        ->and($this->newDeviceEvent->fresh()->payload['actor_name'])->toBe('Mereana Synthetica')
        ->and($this->oldUnnamed->fresh()->normalized_payload)->toEqual($this->oldUnnamed->normalized_payload)
        ->and($this->accessEvent->fresh()->actor_name)->toBe('Mereana Synthetica')
        ->and($this->newSignal->fresh()->payload['actor_name'])->toBe('Mereana Synthetica')
        ->and($this->newSignal->fresh()->normalized_data['description'])->toContain('Mereana Synthetica');

    // One audit row per cleared row, recorded against the actor, never carrying the name; no model-level
    // update audit copies the old name either.
    $audits = AuditLog::query()->where('action', 'security_devices.data_move.door_event_names.cleared')->get();
    expect($audits)->toHaveCount(4)
        ->and($audits->pluck('user_id')->unique()->all())->toBe([$this->actor->id])
        ->and($audits->contains(fn (AuditLog $log): bool => str_contains(json_encode($log->meta), 'Mereana')))->toBeFalse()
        ->and(AuditLog::query()->whereIn('action', ['timelineevent.update', 'integrationevent.update'])->exists())->toBeFalse();

    $this->artisan('security-devices:moves:door-event-names', ['--apply' => true, '--i-have-a-backup' => true, '--actor' => $this->actor->id])
        ->assertSuccessful();
    expect(($this->cleared)())->toBe(4);
});
