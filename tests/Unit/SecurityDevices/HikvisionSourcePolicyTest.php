<?php

namespace Tests\Unit\SecurityDevices;

use App\Domain\SecurityDevices\Cctv\Services\CctvContract;
use App\Domain\SecurityDevices\Cctv\Services\HikvisionSourcePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HikvisionSourcePolicyTest extends TestCase
{
    private function observation(): array
    {
        return ['version' => 1, 'operation' => 'discover_hikvision', 'request_uuid' => '44444444-4444-4444-8444-444444444444', 'device_id' => 2, 'context_sha256' => str_repeat('b', 64), 'credential_binding_sha256' => str_repeat('c', 64), 'observed_at' => '2026-10-11T08:00:00Z', 'status' => 'observed', 'inventory' => ['identity' => ['model' => 'fixture-model', 'serial' => 'fixture-serial', 'device_id' => 'vendor-id', 'firmware' => 'fixture-version', 'firmware_build' => 'build-A'], 'channels' => [['id' => '101', 'input_id' => '1', 'name' => 'Reception', 'codec' => 'H.264', 'enabled' => true, 'video_enabled' => true, 'width' => 1920, 'height' => 1080, 'fps100' => 2500, 'audio_enabled' => null]]], 'reason_code' => null];
    }

    private function support(): array
    {
        return ['model' => 'fixture-model', 'firmware' => 'fixture-version', 'firmware_build' => 'build-A', 'transport' => HikvisionSourcePolicy::PROFILE, 'input_ids' => ['1'], 'witness_id' => 'synthetic-only'];
    }

    private function select(array $result, array $profiles, string $at = '2026-10-11T08:00:01Z'): array
    {
        return HikvisionSourcePolicy::select($result, ['model' => 'fixture-model', 'serial' => 'fixture-serial'], '101', HikvisionSourcePolicy::PROFILE, $profiles, new \DateTimeImmutable($at));
    }

    public function test_exact_original_h264_observation_is_retained_without_turning_unknown_audio_into_a_claim(): void
    {
        $result = $this->observation();
        $digest = CctvContract::hash($result);
        $selected = $this->select($result, [$this->support()]);
        self::assertSame($result['inventory']['channels'][0], $selected['channel']);
        self::assertSame('H264', $selected['video_codec']);
        self::assertFalse($selected['audio_enabled']);
        self::assertNull($selected['channel']['audio_enabled']);
        self::assertSame($digest, CctvContract::hash($result));
        self::assertSame(CctvContract::hash($this->support()), $selected['support_sha256']);
        $result['inventory']['channels'][0]['codec'] = 'H264';
        self::assertSame('H264', $this->select($result, [$this->support()])['channel']['codec']);
    }

    public static function rejected(): array
    {
        return array_map(fn ($v) => [$v], ['model', 'serial', 'firmware', 'build', 'input', 'H265', 'HK264', 'disabled', 'video_disabled', 'duplicate_channel', 'expired', 'future', 'unsupported', 'manual_url']);
    }

    #[DataProvider('rejected')]
    public function test_selection_requires_exact_supported_identity_channel_freshness_and_closed_observation(string $kind): void
    {
        $r = $this->observation();
        match ($kind) {
            'model' => $r['inventory']['identity']['model'] = 'foreign-model',
            'serial' => $r['inventory']['identity']['serial'] = 'foreign-serial',
            'firmware' => $r['inventory']['identity']['firmware'] = 'other-version',
            'build' => $r['inventory']['identity']['firmware_build'] = 'build-B',
            'input' => $r['inventory']['channels'][0]['input_id'] = '2',
            'H265' => $r['inventory']['channels'][0]['codec'] = 'H.265',
            'HK264' => $r['inventory']['channels'][0]['codec'] = 'HK.264',
            'disabled' => $r['inventory']['channels'][0]['enabled'] = false,
            'video_disabled' => $r['inventory']['channels'][0]['video_enabled'] = false,
            'duplicate_channel' => $r['inventory']['channels'][] = $r['inventory']['channels'][0],
            'manual_url' => $r['url'] = 'https://manual.test',
            default => null,
        };
        $this->expectException(\InvalidArgumentException::class);
        $this->select($r, $kind === 'unsupported' ? [] : [$this->support()], match ($kind) {
            'expired' => '2026-10-11T08:05:00Z',
            'future' => '2026-10-11T07:59:59Z',
            default => '2026-10-11T08:00:01Z',
        });
    }

    public static function invalidProfiles(): array
    {
        return array_map(fn ($v) => [$v], ['duplicate_match', 'duplicate_input', 'numeric_input', 'unknown_key', 'empty_inputs', 'unbounded', 'wrong_transport', 'not_list']);
    }

    #[DataProvider('invalidProfiles')]
    public function test_ambiguous_or_malformed_protected_support_configuration_fails_closed(string $kind): void
    {
        $profile = $this->support();
        match ($kind) {
            'duplicate_input' => $profile['input_ids'] = ['1', '1'],
            'numeric_input' => $profile['input_ids'] = [1],
            'unknown_key' => $profile['url'] = 'https://arbitrary.test',
            'empty_inputs' => $profile['input_ids'] = [],
            'wrong_transport' => $profile['transport'] = 'plaintext_rtsp',
            default => null,
        };
        $profiles = match ($kind) {
            'duplicate_match' => [$profile, $profile],
            'unbounded' => array_fill(0, 257, $profile),
            'not_list' => ['named' => $profile],
            default => [$profile],
        };
        $this->expectException(\InvalidArgumentException::class);
        HikvisionSourcePolicy::supported($this->observation()['inventory']['identity'], $profiles);
    }

    public function test_age_boundary_allows_299_seconds_and_rejects_300_without_an_extended_receipt(): void
    {
        self::assertSame('101', $this->select($this->observation(), [$this->support()], '2026-10-11T08:04:59Z')['channel']['id']);
        $this->expectException(\InvalidArgumentException::class);
        $this->select($this->observation(), [$this->support()], '2026-10-11T08:05:00Z');
    }
}
