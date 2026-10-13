<?php

namespace Oblivion\Collector\Media;

use DateTimeImmutable;
use Oblivion\Collector\Http\HttpsCentralApi;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\EnvelopeVerifier;
use RuntimeException;

final class MediaLane
{
    public function __construct(private HttpsCentralApi $central, private CredentialLeaseDecryptor $decryptor, private EnvelopeVerifier $verifier) {}

    public function run(array $identity, $input, $output): int
    {
        self::exchange($input, $output, json_encode(['version' => 1, 'operation' => 'configure', 'collector_uuid' => $identity['collector_id'], 'site_id' => $identity['site_id'], 'server_public_key' => $identity['central_signing_public_key']], JSON_THROW_ON_ERROR), null);
        while (true) {
            $healthRequest = self::uuid();
            $health = self::exchange($input, $output, json_encode(['version' => 1, 'operation' => 'health', 'request_uuid' => $healthRequest], JSON_THROW_ON_ERROR), $healthRequest);
            if ($health['status'] !== 'requested') {
                throw new RuntimeException('Media runtime unavailable.');
            }
            $profileRequest = self::uuid();
            $profile = self::exchange($input, $output, json_encode(['version' => 1, 'operation' => 'health_hikvision_live', 'request_uuid' => $profileRequest], JSON_THROW_ON_ERROR), $profileRequest);
            $hikvisionProfile = $profile['status'] === 'requested' ? HikvisionLiveExchange::PROFILE : null;
            $work = $this->central->mediaWork($identity['collector_id'], self::uuid(), ['version' => 1, 'available' => true, 'video_codec' => 'H264', 'audio' => false, 'hikvision_profile' => $hikvisionProfile]);
            $workKeys = array_keys($work);
            sort($workKeys);
            if ($workKeys !== ['operations', 'server_now'] || ! is_array($work['operations']) || count($work['operations']) > 4) {
                throw new RuntimeException('Media work unavailable.');
            }
            $results = [];
            foreach ($work['operations'] as $operation) {
                if (! is_array($operation)) {
                    throw new RuntimeException('Media work unavailable.');
                }
                $hikvision = null;
                $kind = $operation['operation'] ?? null;
                if ($kind === 'close') {
                    $message = ['version' => 1, 'operation' => 'close', 'request_uuid' => $operation['request_uuid'] ?? null, 'session_uuid' => $operation['session_uuid'] ?? null, 'generation' => $operation['generation'] ?? null];
                    $wire = json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                } elseif ($kind === 'open' || $kind === 'renew') {
                    $envelope = $operation['grant'] ?? null;
                    if (! is_array($envelope)) {
                        throw new RuntimeException('Media authority unavailable.');
                    }
                    $signed = $this->verifier->verify(json_encode($envelope, JSON_THROW_ON_ERROR));
                    if (($signed['type'] ?? null) === 'collector_hikvision_live') {
                        if ($hikvisionProfile === null) {
                            throw new RuntimeException('Live media unavailable.');
                        }
                        $hikvision = new HikvisionLiveExchange($this->decryptor, $this->verifier);
                        $wire = $hikvision->frame($operation, $identity, new DateTimeImmutable('now'));
                    } else {
                        if (($signed['collector_uuid'] ?? null) !== $identity['collector_id'] || ($signed['site_id'] ?? null) !== $identity['site_id'] || ($signed['type'] ?? null) !== 'collector_cctv_live') {
                            throw new RuntimeException('Media authority unavailable.');
                        }
                        $message = ['version' => 1, 'operation' => $kind, 'request_uuid' => $operation['request_uuid'] ?? null, 'grant' => $envelope];
                        $wire = json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        if ($kind === 'open') {
                            $lease = $operation['credential_lease'] ?? null;
                            $material = $this->decryptor->open(['credential_lease' => $lease], new DateTimeImmutable('now'));
                            foreach (['source_json', 'turn_json', 'offer_sdp'] as $key) {
                                if (! is_string($material[$key] ?? null)) {
                                    throw new RuntimeException('Media source unavailable.');
                                }
                            }
                            foreach (['source_json' => 'source_sha256', 'turn_json' => 'turn_sha256', 'offer_sdp' => 'offer_sha256'] as $key => $hash) {
                                if (! hash_equals($signed[$hash] ?? '', hash('sha256', $material[$key]))) {
                                    throw new RuntimeException('Media source unavailable.');
                                }
                            }
                            // Preserve the original signed source/turn JSON bytes. Never
                            // decode/re-encode secret locators or TURN material here.
                            json_decode($material['source_json'], true, 8, JSON_THROW_ON_ERROR);
                            json_decode($material['turn_json'], true, 8, JSON_THROW_ON_ERROR);
                            $wire = substr($wire, 0, -1).',"source":'.$material['source_json'].',"turn":'.$material['turn_json'].',"offer":'.json_encode(['type' => 'offer', 'sdp' => $material['offer_sdp']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).'}';
                        }
                    }
                } else {
                    throw new RuntimeException('Media work unavailable.');
                }
                try {
                    $reply = self::exchange($input, $output, $wire, $operation['request_uuid'] ?? null);
                    $results[] = $hikvision ? $hikvision->result($reply, $operation) : $reply;
                } finally {
                    sodium_memzero($wire);
                    if (isset($material)) {
                        foreach ($material as &$secret) {
                            if (is_string($secret)) {
                                sodium_memzero($secret);
                            }
                        }unset($secret,$material);
                    }
                }
            }
            if ($results !== []) {
                $this->central->mediaResults($identity['collector_id'], self::uuid(), $results);
            }
            $recorderRequest = self::uuid();
            $recorderHealth = self::exchange($input, $output, json_encode(['version' => 1, 'operation' => 'health_hikvision_recorder_discovery', 'request_uuid' => $recorderRequest], JSON_THROW_ON_ERROR), $recorderRequest);
            $recorder = $recorderHealth['status'] === 'requested' ? new HikvisionRecorderDiscoveryExchange($this->decryptor, $this->verifier) : null;
            (new HikvisionDiscoveryLane($this->central, new HikvisionDiscoveryExchange($this->decryptor, $this->verifier), $recorder))->poll($identity, $input, $output);
            // Both work lanes and their bounded batched results share the
            // current 60/minute API admission ceiling. At most four requests
            // per five-second cycle bound one media lane to at most 48/minute.
            // Current capability/session/credential expiry remains decisive.
            usleep(5000000);
        }
    }

    private static function exchange($input, $output, string $wire, ?string $request): array
    {
        PrivateFrames::write($output, $wire);
        for ($i = 0; $i < 8; $i++) {
            $reply = PrivateFrames::read($input);
            $keys = array_keys($reply);
            sort($keys);
            if ($keys !== ['answer', 'generation', 'reason_code', 'request_uuid', 'session_uuid', 'status', 'version'] || $reply['version'] !== 1) {
                throw new RuntimeException('Media response unavailable.');
            } if ($request === null || $reply['request_uuid'] === $request) {
                return $reply;
            }
        }
        throw new RuntimeException('Media response unavailable.');
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 15) | 64);
        $b[8] = chr((ord($b[8]) & 63) | 128);
        $s = bin2hex($b);

        return substr($s, 0, 8).'-'.substr($s, 8, 4).'-'.substr($s, 12, 4).'-'.substr($s, 16, 4).'-'.substr($s, 20);
    }
}
