<?php

use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\SecurityDevices\Alarms\SiaDc09\SiaDc09FrameDecoder;
use App\Domain\SecurityDevices\Alarms\SiaDc09\SiaDc09IntakeService;
use App\Domain\SecurityDevices\Alarms\SiaDc09\SiaDc09SocketServer;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialRotationStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialTestStatus;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Services\ListenerHeartbeat;
use App\Models\Site;
use App\Services\System\ServerReadinessService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/*
 * Phase A, item A3 (docs/security-devices/GOAL.md): the alarm receiver end to end. The frames are the
 * pysiaalarm reference samples (MIT, Copyright (c) 2021 E.A. van Valkenburg, commit c445c15d), not
 * frames made by our own encoder. See tests/Unit/SecurityDevices/SiaDc09FrameDecoderTest.php.
 */

const SIA_CLOSING_FRAME = 'E5D50078"SIA-DCS"6002L0#AAA[|Nri1/CL501]_14:12:04,09-25-2019';
const SIA_ENCRYPTED_NULL_FRAME = '76D80055"*NULL"0000R0L0#AAAB[B4BC8B40D0E6D959D6BEA78E88CC0B2155741A3C44FBB96D476A3E557CAD64D9';
const SIA_TEST_KEY = 'AAAAAAAAAAAAAAAA';

beforeEach(function (): void {
    $this->site = Site::factory()->create(['name' => 'Aurora House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    DiscoveryScope::factory()->create([
        'site_id' => $this->site->id,
        'cidrs' => ['127.0.0.0/8'],
        'protocols' => ['tcp'],
        'exclusions' => [],
        'status' => 'active',
    ]);

    $this->panel = function (string $account, string $name): Device {
        $device = Device::factory()->create([
            'name' => $name,
            'domain' => 'security',
            'category' => 'alarm',
            'manufacturer' => 'Ajax Systems',
            'model' => 'Hub 2 Plus',
            'config' => ['sia_account' => $account],
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $this->site->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now()->subHour(),
        ]);

        return $device;
    };
});

afterEach(function (): void {
    $this->travelBack();
});

/** The Site's stored key, served by a stand-in for the encrypted credential store. */
function bindSiaKey(Site $site, string $key): void
{
    CredentialReference::query()->create([
        'reference_key' => 'database:sia_dc09/'.$site->id.'/panel',
        'site_id' => $site->id,
        'provider' => 'sia_dc09',
        'purpose' => 'monitoring',
        'capabilities' => ['monitor:sia_dc09.decrypt'],
        'secret_manager_reference' => 'secret/data/sites/'.$site->id.'/sia',
        'secret_manager_reference_hash' => hash('sha256', 'sia-'.$site->id),
        'status' => CredentialReferenceStatus::Active,
        'rotation_status' => CredentialRotationStatus::Current,
        'test_status' => CredentialTestStatus::Passed,
        'version' => 1,
        'last_tested_at' => now(),
    ]);

    app()->instance(CredentialLeaseProvider::class, new class($key) implements CredentialLeaseProvider
    {
        public function __construct(private readonly string $key) {}

        public function acquire(int $siteId, string $reference, array $capabilities): CredentialLease
        {
            return new CredentialLease('lease-'.Str::uuid(), CarbonImmutable::now('UTC')->addMinutes(5), ['aes_key' => $this->key]);
        }
    });
    app()->forgetInstance(SiaDc09IntakeService::class);
    app()->forgetInstance(SiaDc09SocketServer::class);
}

it('records a reference closing report and answers a plain ACK', function (): void {
    $this->travelTo(CarbonImmutable::parse('2019-09-25T14:12:10Z'));
    $panel = ($this->panel)('AAA', 'Aurora House alarm');

    $result = app(SiaDc09IntakeService::class)->ingest("\n".SIA_CLOSING_FRAME."\r", '127.0.0.1');

    expect($result['accepted'])->toBeTrue()
        ->and($result['reason_code'])->toBe('event_recorded')
        ->and($result['response_frame'])->toBe(SiaDc09FrameDecoder::buildAck('6002', null, 'L0', 'AAA'));

    $event = DeviceEvent::query()->findOrFail($result['event_id']);
    expect($event->device_id)->toBe($panel->id)
        ->and($event->event_type)->toBe('alarm_armed')
        ->and($event->occurred_at->utc()->format(DATE_ATOM))->toBe('2019-09-25T14:12:04+00:00');
});

it('answers NAK with our time, and records nothing, when the panel clock is out by more than the window', function (): void {
    $this->travelTo(CarbonImmutable::parse('2019-09-25T14:14:00Z'));
    ($this->panel)('AAA', 'Aurora House alarm');

    $result = app(SiaDc09IntakeService::class)->ingest(SIA_CLOSING_FRAME, '127.0.0.1');

    expect($result['accepted'])->toBeFalse()
        ->and($result['reason_code'])->toBe('timestamp_out_of_window')
        ->and($result['response_frame'])->toBe(SiaDc09FrameDecoder::buildNak(new DateTimeImmutable('2019-09-25 14:14:00', new DateTimeZone('UTC'))))
        ->and(DeviceEvent::query()->count())->toBe(0);
});

it('decrypts the reference encrypted NULL with the Site key and answers an encrypted ACK', function (): void {
    $this->travelTo(CarbonImmutable::parse('2020-07-05T18:24:55Z'));
    $panel = ($this->panel)('AAAB', 'Kauri House alarm');
    bindSiaKey($this->site, SIA_TEST_KEY);

    $result = app(SiaDc09IntakeService::class)->ingest(SIA_ENCRYPTED_NULL_FRAME, '127.0.0.1');

    expect($result['accepted'])->toBeTrue()
        ->and($result['reason_code'])->toBe('heartbeat_acknowledged')
        ->and($result['response_frame'])->toBe(SiaDc09FrameDecoder::buildEncryptedAck(
            '0000', 'R0', 'L0', 'AAAB', SIA_TEST_KEY, new DateTimeImmutable('2020-07-05 18:24:55', new DateTimeZone('UTC')),
        ))
        ->and($result['response_frame'])->toContain('"*ACK"0000R0L0#AAAB[')
        ->and($panel->fresh()->last_seen_at?->utc()->format(DATE_ATOM))->toBe('2020-07-05T18:24:55+00:00');
});

it('also accepts a Site key stored as 32 hex digits for the same 16 characters', function (): void {
    $this->travelTo(CarbonImmutable::parse('2020-07-05T18:24:55Z'));
    ($this->panel)('AAAB', 'Kauri House alarm');
    bindSiaKey($this->site, bin2hex(SIA_TEST_KEY));

    $result = app(SiaDc09IntakeService::class)->ingest(SIA_ENCRYPTED_NULL_FRAME, '127.0.0.1');

    expect($result['accepted'])->toBeTrue();
});

it('answers NAK for a wrong Site key and for an unknown account', function (): void {
    $this->travelTo(CarbonImmutable::parse('2020-07-05T18:24:55Z'));
    ($this->panel)('AAAB', 'Kauri House alarm');
    bindSiaKey($this->site, 'BBBBBBBBBBBBBBBB');
    $intake = app(SiaDc09IntakeService::class);
    $nak = SiaDc09FrameDecoder::buildNak(new DateTimeImmutable('2020-07-05 18:24:55', new DateTimeZone('UTC')));

    $wrongKey = $intake->ingest(SIA_ENCRYPTED_NULL_FRAME, '127.0.0.1');
    $unknown = $intake->ingest(SIA_CLOSING_FRAME, '127.0.0.1');

    expect($wrongKey['reason_code'])->toBe('decryption_failed')
        ->and($wrongKey['response_frame'])->toBe($nak)
        ->and($unknown['reason_code'])->toBe('unknown_account')
        ->and($unknown['response_frame'])->toBe($nak);
});

it('answers NAK to a damaged frame and DUH to an unknown message type', function (): void {
    $this->travelTo(CarbonImmutable::parse('2019-09-25T14:12:10Z'));
    ($this->panel)('AAA', 'Aurora House alarm');
    $intake = app(SiaDc09IntakeService::class);

    $damaged = $intake->ingest(str_replace('CL501', 'OP501', SIA_CLOSING_FRAME), '127.0.0.1');
    $unknownType = $intake->ingest(SiaDc09FrameDecoder::wrapBody('"XYZ-NEW"0042L0#AAA[]'), '127.0.0.1');

    expect($damaged['reason_code'])->toBe('crc_mismatch')
        ->and($damaged['response_frame'])->toStartWith("\n")->toContain('"NAK"0000R0L0A0[]_14:12:10,09-25-2019')
        ->and($unknownType['reason_code'])->toBe('unsupported_token')
        ->and($unknownType['response_frame'])->toBe(SiaDc09FrameDecoder::buildDuh('0042', null, 'L0', 'AAA'));
});

it('gives a sender outside the Site no reply at all, even when encrypted', function (): void {
    $this->travelTo(CarbonImmutable::parse('2020-07-05T18:24:55Z'));
    ($this->panel)('AAA', 'Aurora House alarm');
    ($this->panel)('AAAB', 'Kauri House alarm');
    bindSiaKey($this->site, SIA_TEST_KEY);
    $intake = app(SiaDc09IntakeService::class);

    foreach ([SIA_CLOSING_FRAME, SIA_ENCRYPTED_NULL_FRAME] as $frame) {
        $result = $intake->ingest($frame, '203.0.113.99');
        expect($result['accepted'])->toBeFalse()
            ->and($result['reason_code'])->toBe('sender_out_of_scope')
            ->and($result['response_frame'])->toBeNull();
    }
});

it('answers every frame on one TCP connection over a real socket', function (): void {
    $this->travelTo(CarbonImmutable::parse('2019-09-25T14:12:10Z'));
    ($this->panel)('AAA', 'Aurora House alarm');
    $second = SiaDc09FrameDecoder::wrapBody('"SIA-DCS"6003L0#AAA[|Nri1/OP501]_14:12:08,09-25-2019');

    $server = app(SiaDc09SocketServer::class);
    $server->open('127.0.0.1', 0);

    try {
        $client = stream_socket_client('tcp://'.$server->boundAddress(), $errorNumber, $errorText, 2.0);
        expect($client)->toBeResource();
        fwrite($client, "\n".SIA_CLOSING_FRAME."\r".$second);
        stream_socket_shutdown($client, STREAM_SHUT_WR);

        expect($server->poll(2.0))->toBe(2);

        stream_set_timeout($client, 2);
        $replies = stream_get_contents($client);
        fclose($client);
    } finally {
        $server->close();
    }

    expect($replies)->toBe(
        SiaDc09FrameDecoder::buildAck('6002', null, 'L0', 'AAA').SiaDc09FrameDecoder::buildAck('6003', null, 'L0', 'AAA'),
    )->and(DeviceEvent::query()->pluck('event_type')->sort()->values()->all())->toBe(['alarm_armed', 'alarm_disarmed']);
});

it('answers a frame sent over UDP', function (): void {
    $this->travelTo(CarbonImmutable::parse('2019-09-25T14:12:10Z'));
    ($this->panel)('AAA', 'Aurora House alarm');

    $server = app(SiaDc09SocketServer::class);
    $server->open('127.0.0.1', 0);

    try {
        expect($server->listensOnUdp())->toBeTrue();
        $client = stream_socket_client('udp://'.$server->boundAddress(), $errorNumber, $errorText, 2.0);
        fwrite($client, "\n".SIA_CLOSING_FRAME."\r");

        expect($server->poll(2.0))->toBe(1);

        stream_set_timeout($client, 2);
        $reply = fread($client, 2048);
        fclose($client);
    } finally {
        $server->close();
    }

    expect($reply)->toBe(SiaDc09FrameDecoder::buildAck('6002', null, 'L0', 'AAA'));
});

it('shows the alarm receiver on Server readiness only when switched on, from its heartbeat', function (): void {
    Redis::shouldReceive('connection->command')->with('ping')->andThrow(new RuntimeException('No Redis in this test.'));
    $findCheck = fn (): ?array => collect(app(ServerReadinessService::class)->evaluate(useCachedPing: true)['checks'])
        ->firstWhere('key', 'listener_sia_dc09');

    config()->set('security_devices.listeners.sia_dc09.enabled', false);
    expect($findCheck())->toBeNull();

    config()->set('security_devices.listeners.sia_dc09.enabled', true);
    $notRunning = $findCheck();
    expect($notRunning['status'])->toBe('fail')
        ->and($notRunning['summary'])->toBe('Not running');

    app(ListenerHeartbeat::class)->beat(ListenerHeartbeat::SIA_DC09);
    expect($findCheck()['status'])->toBe('pass');

    $this->travel(5)->minutes();
    expect($findCheck()['summary'])->toBe('Stopped');
});
