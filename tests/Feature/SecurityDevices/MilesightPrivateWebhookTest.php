<?php

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Integration\IntegrationSiteSecret;
use App\Models\Site;
use Illuminate\Support\Facades\Crypt;

/*
 * Phase A, item A8 with D23 (Stephan, 30 Oct; docs/security-devices/GOAL.md): Milesight's HTTPS push is
 * answered only over the private network, and only with the Site's secret typed into the gateway the
 * way Milesight documents (an HTTP header, or an account and password).
 */

const MILESIGHT_TEST_SECRET = 'milesight-gateway-site-secret-2026';

beforeEach(function (): void {
    $this->site = Site::factory()->create(['name' => 'Kauri House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    $sensor = Device::factory()->create([
        'name' => 'Lounge air quality AM307', 'domain' => 'iot_healthcare', 'category' => 'environmental',
        'manufacturer' => 'Milesight', 'model' => 'AM307', 'config' => ['lorawan' => ['dev_eui' => '24E124707E043923']],
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $sensor->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $this->site->id,
        'assignment_type' => 'permanent', 'assigned_at' => now()->subHour(),
    ]);
    IntegrationSiteSecret::query()->create([
        'site_id' => $this->site->id, 'provider' => 'milesight_lorawan', 'capability' => 'gateway_webhook',
        'secret_encrypted' => Crypt::encryptString(MILESIGHT_TEST_SECRET), 'is_enabled' => true,
    ]);

    $this->push = function (array $server) {
        $body = json_encode([
            'devEUI' => '24:E1:24:70:7E:04:39:23', 'deviceName' => 'AM307-Lounge', 'rssi' => -67, 'snr' => 9.5,
            'data' => base64_encode((string) hex2bin('01755c0367ec0004686e077d5802')),
        ], JSON_THROW_ON_ERROR);

        return $this->call('POST', "/webhooks/milesight-gateway/{$this->site->id}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.MILESIGHT_TEST_SECRET,
            ...$server,
        ], $body);
    };
});

it('refuses a push from the public internet, even with the right secret', function (string $sender): void {
    ($this->push)(['REMOTE_ADDR' => $sender])->assertNotFound();
})->with(['public IPv4' => '203.0.113.5', 'public IPv6' => '2001:db8::5', 'carrier-grade NAT' => '100.64.1.1']);

it('refuses a public sender relayed by the local proxy (nginx sets X-Real-IP)', function (): void {
    ($this->push)(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_REAL_IP' => '203.0.113.5'])->assertNotFound();
});

it('ignores an X-Real-IP header sent by a public sender itself', function (): void {
    ($this->push)(['REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_REAL_IP' => '10.20.0.36'])->assertNotFound();
});

it('answers a gateway on the private network', function (array $server): void {
    ($this->push)($server)->assertOk()->assertJsonPath('accepted', true);
})->with([
    'SD-WAN address' => [['REMOTE_ADDR' => '10.20.0.36']],
    'home network' => [['REMOTE_ADDR' => '172.16.0.50']],
    'IPv6 unique-local' => [['REMOTE_ADDR' => 'fd12:3456::36']],
    'private sender via the local proxy' => [['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_REAL_IP' => '192.168.10.36']],
]);

it('still needs the Site’s secret on the private network', function (): void {
    ($this->push)(['REMOTE_ADDR' => '10.20.0.36', 'HTTP_AUTHORIZATION' => 'Bearer not-the-secret'])->assertUnauthorized();
});
