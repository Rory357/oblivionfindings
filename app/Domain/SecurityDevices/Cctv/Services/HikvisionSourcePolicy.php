<?php

namespace App\Domain\SecurityDevices\Cctv\Services;

/** Select a direct camera stream only from original normalized observations and
 * an explicitly configured exact model/firmware transport witness. No inference
 * from an NVR input number, advertised HTTPS, or a codec family is permitted. */
final class HikvisionSourcePolicy
{
    public const PROFILE = 'hikvision_rtsp_https_digest_v1';

    public const MAX_OBSERVATION_AGE = 300;

    public static function supported(array $identity, mixed $profiles): ?array
    {
        if (! is_array($profiles) || ! array_is_list($profiles) || count($profiles) > 256) {
            throw new \InvalidArgumentException('Invalid supported profile configuration.');
        }
        $matches = [];
        foreach ($profiles as $entry) {
            if (! is_array($entry)) {
                throw new \InvalidArgumentException('Invalid supported profile configuration.');
            }
            CctvContract::exact($entry, ['model', 'firmware', 'firmware_build', 'transport', 'input_ids', 'witness_id']);
            foreach (['model', 'firmware', 'witness_id'] as $key) {
                CctvContract::text($entry[$key], 1, 128);
            }
            CctvContract::text($entry['firmware_build'], 0, 128);
            if ($entry['transport'] !== self::PROFILE || ! is_array($entry['input_ids']) || ! array_is_list($entry['input_ids']) || $entry['input_ids'] === [] || count($entry['input_ids']) > 256) {
                throw new \InvalidArgumentException('Invalid supported profile configuration.');
            }
            $seen = [];
            foreach ($entry['input_ids'] as $id) {
                if (! is_string($id) || ! preg_match('/\A[1-9][0-9]{0,8}\z/', $id) || isset($seen[$id])) {
                    throw new \InvalidArgumentException('Invalid supported profile configuration.');
                }$seen[$id] = true;
            }
            if ($entry['model'] === $identity['model'] && $entry['firmware'] === $identity['firmware'] && $entry['firmware_build'] === $identity['firmware_build']) {
                $matches[] = $entry;
            }
        }
        if (count($matches) > 1) {
            throw new \InvalidArgumentException('Ambiguous supported profile configuration.');
        }

        return $matches[0] ?? null;
    }

    public static function eligibility(array $channel, ?array $support): ?string
    {
        if (! $support) {
            return 'model_unsupported';
        }
        if (! in_array($channel['input_id'], $support['input_ids'], true)) {
            return 'input_unsupported';
        }
        if (! $channel['enabled'] || ! $channel['video_enabled']) {
            return 'channel_disabled';
        }
        // Official StreamingChannel schema names H.264. Retain the original
        // observation and accept only this notation and the normalized H264 alias.
        if (! in_array($channel['codec'], ['H264', 'H.264'], true)) {
            return 'codec_unsupported';
        }

        return null;
    }

    public static function select(array $result, array $canonical, string $channelId, string $profile, mixed $profiles, \DateTimeImmutable $now): array
    {
        HikvisionDiscoveryContract::result($result);
        if ($result['status'] !== 'observed' || $profile !== self::PROFILE) {
            throw new \InvalidArgumentException('Source unavailable.');
        }
        $observed = new \DateTimeImmutable($result['observed_at']);
        if ($observed > $now || $observed->modify('+'.self::MAX_OBSERVATION_AGE.' seconds') <= $now) {
            throw new \InvalidArgumentException('Observation unavailable.');
        }
        $identity = $result['inventory']['identity'];
        if (! is_string($canonical['model'] ?? null) || ! is_string($canonical['serial'] ?? null) || $identity['model'] !== $canonical['model'] || $identity['serial'] !== $canonical['serial']) {
            throw new \InvalidArgumentException('Identity unavailable.');
        }
        $support = self::supported($identity, $profiles);
        $matches = array_values(array_filter($result['inventory']['channels'], fn ($c) => $c['id'] === $channelId));
        if (count($matches) !== 1 || self::eligibility($matches[0], $support) !== null) {
            throw new \InvalidArgumentException('Channel unavailable.');
        }

        return ['identity' => $identity, 'channel' => $matches[0], 'transport_profile' => self::PROFILE, 'support' => $support, 'support_sha256' => CctvContract::hash($support), 'video_codec' => 'H264', 'audio_enabled' => false];
    }
}
