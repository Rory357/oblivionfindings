<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Data\RuntimeEnvelope;
use App\Domain\Monitoring\Enums\RuntimeMessageType;
use App\Domain\Monitoring\Models\MonitoringDeadLetter;
use App\Domain\Monitoring\Services\RuntimeEnvelopeCodec;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;

/** @return array{user: User, site: Site, replay: MonitoringDeadLetter, discard: MonitoringDeadLetter, hiddenSite: Site, raw: string} */
function monitoringDeliveryRecoveryEvidenceFixture(string $suffix): array
{
    $run = Str::lower((string) Str::uuid());
    $site = Site::factory()->create([
        'name' => 'A04 '.Str::headline($suffix).' Delivery Site',
        'is_active' => true,
        'archived' => false,
    ]);
    $hiddenSite = Site::factory()->create([
        'name' => 'A04 Hidden Delivery Site '.$run,
        'is_active' => true,
        'archived' => false,
    ]);
    $user = User::factory()->create([
        'name' => 'A04 '.Str::headline($suffix).' Monitoring Operator',
        'email' => "a04.operator.{$suffix}.{$run}@example.test",
        'approved_at' => now(),
    ]);
    $role = Role::query()->firstOrCreate(
        ['name' => 'a04_delivery_recovery_browser_evidence'],
        [
            'label' => 'A04 delivery recovery browser evidence',
            'level' => 50,
            'type' => 'custom',
        ],
    );
    $user->roles()->attach($role);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
    ]);

    $permissionKeys = [
        'securityDevices.viewAny',
        'securityDevices.events.view',
        'securityDevices.integrations.manage',
    ];
    foreach ($permissionKeys as $permissionKey) {
        Permission::query()->firstOrCreate(
            ['key' => $permissionKey],
            [
                'description' => $permissionKey,
                'group' => 'Security & Devices',
                'module' => 'Security & Devices',
            ],
        );
    }
    $permissions = Permission::query()->whereIn('key', $permissionKeys)->pluck('id');
    $user->permissionOverrides()->syncWithoutDetaching(
        $permissions->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])->all(),
    );

    $at = CarbonImmutable::parse('2026-07-27T01:02:03.456789Z');
    $encode = static function (string $messageId, int $payloadSiteId) use ($at): string {
        return app(RuntimeEnvelopeCodec::class)->encode(new RuntimeEnvelope(
            schemaVersion: 1,
            messageId: $messageId,
            type: RuntimeMessageType::Event,
            source: 'collector:a04-browser',
            sequence: 2,
            occurredAt: $at,
            ingestedAt: $at,
            idempotencyKey: 'a04-browser:'.$messageId,
            traceId: '018f0000-0000-7000-8000-000000000499',
            payload: ['site_id' => $payloadSiteId],
        ));
    };

    $replayMessageId = '018f0000-0000-7000-8000-000000000401';
    $replay = MonitoringDeadLetter::query()->create([
        'message_id' => $replayMessageId,
        'consumer' => 'event-projector',
        'source' => 'collector:a04-browser',
        'sequence' => 2,
        'idempotency_key' => 'a04-browser:'.$replayMessageId,
        'reason_code' => 'sequence_gap',
        'reason_message' => 'Expected sequence 1.',
        'envelope_bytes' => $encode($replayMessageId, $site->id),
        'site_id' => $site->id,
    ]);

    $hiddenMessageId = '018f0000-0000-7000-8000-000000000402';
    MonitoringDeadLetter::query()->create([
        'message_id' => $hiddenMessageId,
        'consumer' => 'event-projector',
        'source' => 'collector:a04-browser',
        'sequence' => 2,
        'idempotency_key' => 'a04-browser:'.$hiddenMessageId,
        'reason_code' => 'sequence_gap',
        'reason_message' => 'Expected sequence 1.',
        'envelope_bytes' => $encode($hiddenMessageId, $hiddenSite->id),
        'site_id' => $site->id,
    ]);

    $raw = '{A04-BROWSER-RAW-SECRET-'.$run;
    $discard = MonitoringDeadLetter::query()->create([
        'message_id' => '018f0000-0000-7000-8000-000000000403',
        'consumer' => 'event-projector',
        'source' => 'untrusted',
        'sequence' => 1,
        'idempotency_key' => 'a04-browser-invalid:'.$run,
        'reason_code' => 'invalid_signature',
        'reason_message' => 'Envelope authentication failed.',
        'envelope_bytes' => $raw,
        'site_id' => $site->id,
    ]);

    return compact('user', 'site', 'replay', 'discard', 'hiddenSite', 'raw');
}

test('monitoring delivery recovery stays clear safe and usable on desktop', function () {
    $severeLogs = collect();

    foreach ([
        ['width' => 1440, 'height' => 900, 'suffix' => 'wide'],
        ['width' => 1280, 'height' => 800, 'suffix' => 'compact'],
    ] as $viewport) {
        $fixture = monitoringDeliveryRecoveryEvidenceFixture($viewport['suffix']);

        $this->browse(function (Browser $browser) use ($fixture, $viewport, $severeLogs): void {
            $browser->resize($viewport['width'], $viewport['height'])
                ->loginAs($fixture['user'])
                ->visit('/security-devices/monitoring?tab=collection')
                ->waitForText('Delivery contracts & recovery', 30)
                ->assertSee('TRANSPORT ENVELOPE')
                ->assertSee('OBSERVATION & EVENT PAYLOADS')
                ->assertSee('DEVICE COMMANDS')
                ->assertSee('Current v2')
                ->assertSee('Accepts v1, 2')
                ->assertSee('Standard v6 · Break glass v7')
                ->assertSee('Reconcile actual state before any retry')
                ->assertSee('Sequence Gap')
                ->assertSee('Invalid Signature')
                ->assertSee('Replay consumes the original signed bytes and does not re-run a device command.')
                ->assertDontSee($fixture['hiddenSite']->name)
                ->assertDontSee('00000402')
                ->assertDontSee($fixture['raw'])
                ->assertScript(
                    'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
                    true,
                );

            $browser->script('document.querySelector(\'[data-test^="monitoring-replay-"]\')?.scrollIntoView({ block: "center" });');
            $browser->click('[data-test^="monitoring-replay-"]')
                ->waitForText('Replay signed monitoring evidence')
                ->assertSee('This never re-runs a device command.')
                ->type('#monitoring-recovery-reason', 'Missing sequence restored and verified in browser acceptance.')
                ->press('Queue replay')
                ->waitForText('Replay queued', 30)
                ->assertMissing('[data-test^="monitoring-replay-"]');

            expect($fixture['replay']->fresh()->replay_requested_at)->not->toBeNull();

            $browser->script('document.querySelector(\'[data-test^="monitoring-discard-invalid_signature-"]\')?.scrollIntoView({ block: "center" });');
            $browser->click('[data-test^="monitoring-discard-invalid_signature-"]')
                ->waitForText('Discard from processing')
                ->clear('#monitoring-recovery-reason')
                ->type('#monitoring-recovery-reason', 'Invalid authentication evidence confirmed in browser acceptance.')
                ->press('Discard and retain evidence')
                ->waitUntilMissingText('Invalid Signature', 30)
                ->assertDontSee($fixture['raw'])
                ->assertScript(
                    'document.documentElement.scrollWidth <= document.documentElement.clientWidth',
                    true,
                );

            expect($fixture['discard']->fresh()->resolved_at)->not->toBeNull();

            $severeLogs->push(...collect($browser->driver->manage()->getLog('browser'))
                ->filter(fn (array $entry): bool => strtoupper((string) ($entry['level'] ?? '')) === 'SEVERE')
                ->all());
        });
    }

    expect($severeLogs)->toBeEmpty();
});
