<?php

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointReleaseCatalogue;
use App\Domain\SecurityDevices\Hubs\Services\HubUpdatePolicy;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 * Hub updates wait for the test hub (Stephan, 3 Nov: "Wait for the test hub"). Hubs that aren't test-first take
 * a release only after a test-first hub has it working AND a day has passed since it was published. A test hub
 * whose update failed or rolled back holds the others on their version. With no test-first hub, the day's wait
 * is the only rule.
 */

beforeEach(function (): void {
    $this->now = CarbonImmutable::parse('2026-11-03 01:00:00', 'UTC');
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sd-hub-ring-'.Str::lower((string) Str::uuid());
    mkdir($this->dir, 0700, true);
    $keys = sodium_crypto_sign_keypair();
    $this->secret = sodium_crypto_sign_secretkey($keys);
    $this->public = base64_encode(sodium_crypto_sign_publickey($keys));

    // A signed release list with 1.3.2 for Windows hubs, published $hoursAgo before "now".
    $this->publish = function (int $hoursAgo): HubUpdatePolicy {
        $issued = $this->now->subHours($hoursAgo);
        $payload = json_encode([
            'schema' => 1,
            'product' => 'oblivion-downloads',
            'revision' => 7,
            'issued_at' => $issued->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => $issued->addDays(30)->format('Y-m-d\TH:i:s\Z'),
            'artifacts' => [[
                'id' => 'hub-windows-amd64',
                'platform' => 'windows',
                'architecture' => 'amd64',
                'roles' => ['site'],
                'version' => '1.3.2',
                'release_revision' => 12,
                'filename' => 'OblivionHub-1.3.2.exe',
                'size' => 1024,
                'sha256' => str_repeat('a', 64),
            ]],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.'downloads.json', json_encode([
            'payload' => base64_encode($payload),
            'signature' => base64_encode(sodium_crypto_sign_detached(EndpointReleaseCatalogue::DOMAIN.$payload, $this->secret)),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return new HubUpdatePolicy(new EndpointReleaseCatalogue($this->dir, $this->public, 1));
    };
    $this->hub = fn (string $ring, ?array $updateState = null, string $running = '1.3.1'): MonitoringCollector => MonitoringCollector::factory()->create([
        'site_id' => $this->site->id,
        'update_ring' => $ring,
        'approved_at' => $this->now->subDays(30),
        'revoked_at' => null,
        'runtime_status' => ['platform' => 'windows', 'version' => $running],
        'update_state' => $updateState,
    ]);
    $this->report = fn (string $outcome): array => [
        'outcome' => $outcome, 'from_version' => '1.3.1', 'to_version' => '1.3.2', 'detail' => null,
        'reported_at' => $this->now->subHours(20)->toIso8601String(),
    ];
});

afterEach(function (): void {
    @unlink($this->dir.DIRECTORY_SEPARATOR.'downloads.json');
    @rmdir($this->dir);
});

it('gives a test-first hub a release straight away', function (): void {
    $policy = ($this->publish)(1);
    $test = ($this->hub)('early');

    expect($policy->instructionFor($test, $this->now)['target']['version'] ?? null)->toBe('1.3.2');
});

it('lets the others take it a day after release once the test hub has it working', function (): void {
    ($this->hub)('early', ($this->report)('installed'), '1.3.2');
    $other = ($this->hub)('everyone');

    // Inside the day: still waiting, even though the test hub has it.
    $policy = ($this->publish)(10);
    expect($policy->instructionFor($other, $this->now)['target'])->toBeNull()
        ->and($policy->waitingFor($other, $this->now)['state'])->toBe('day');

    $policy = ($this->publish)(30);
    expect($policy->instructionFor($other, $this->now)['target']['version'] ?? null)->toBe('1.3.2')
        ->and($policy->waitingFor($other, $this->now)['state'])->toBe('ready');
});

it('holds the others on their version when the test hub’s update failed or rolled back', function (string $outcome): void {
    $test = ($this->hub)('early', ($this->report)($outcome));
    $other = ($this->hub)('everyone');
    $policy = ($this->publish)(30);

    expect($policy->instructionFor($other, $this->now)['target'])->toBeNull();
    $waiting = $policy->waitingFor($other, $this->now);
    expect($waiting['state'])->toBe('held')
        ->and($waiting['version'])->toBe('1.3.2')
        ->and($waiting['test_hub']?->id)->toBe($test->id);

    // Once a test hub reports it working, the others go.
    $test->forceFill(['update_state' => ($this->report)('installed'), 'runtime_status' => ['platform' => 'windows', 'version' => '1.3.2']])->save();
    expect($policy->instructionFor($other->fresh(), $this->now)['target']['version'] ?? null)->toBe('1.3.2');
})->with(['rolled_back', 'failed']);

it('makes the others wait while the test hub hasn’t taken it yet', function (): void {
    ($this->hub)('early');
    $other = ($this->hub)('everyone');
    $policy = ($this->publish)(30);

    expect($policy->instructionFor($other, $this->now)['target'])->toBeNull()
        ->and($policy->waitingFor($other, $this->now)['state'])->toBe('waiting');
});

it('keeps the day’s wait as the only rule when there is no test-first hub', function (): void {
    $other = ($this->hub)('everyone');

    expect(($this->publish)(10)->instructionFor($other, $this->now)['target'])->toBeNull()
        ->and(($this->publish)(30)->instructionFor($other, $this->now)['target']['version'] ?? null)->toBe('1.3.2');
});

it('ignores a removed or unapproved test hub', function (): void {
    ($this->hub)('early', ($this->report)('rolled_back'))->forceFill(['revoked_at' => $this->now->subDay()])->save();
    $unapproved = ($this->hub)('early', ($this->report)('failed'));
    $unapproved->forceFill(['approved_at' => null])->save();
    $other = ($this->hub)('everyone');

    expect(($this->publish)(30)->instructionFor($other, $this->now)['target']['version'] ?? null)->toBe('1.3.2');
});
