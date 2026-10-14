<?php

namespace Oblivion\Collector\Media;

use DateTimeImmutable;
use Oblivion\Collector\Http\HttpsCentralApi;
use RuntimeException;

/** Uses the same protected media lane, fixed HTTPS central transport and private
 * worker pipes. Discovery has a distinct versioned capability and result shape. */
final readonly class HikvisionDiscoveryLane
{
    public function __construct(private HttpsCentralApi $central, private HikvisionDiscoveryExchange $exchange, private ?HikvisionRecorderDiscoveryExchange $recorder = null) {}

    public function poll(array $identity, $input, $output, ?DateTimeImmutable $now = null): void
    {
        $capability = ['version' => 1, 'available' => true, 'profile' => 'hikvision_isapi_https_v1'];
        if ($this->recorder !== null) {
            $capability['recorder_profile'] = HikvisionRecorderDiscoveryExchange::PROFILE;
        }
        $work = $this->central->discoveryWork($identity['collector_id'], self::uuid(), $capability);
        self::exact($work, ['operations', 'server_now']);
        if (! is_string($work['server_now']) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/', $work['server_now']) !== 1) {
            self::invalid();
        }
        $serverTime = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $work['server_now'], new \DateTimeZone('UTC'));
        if (! $serverTime || $serverTime->format('Y-m-d\TH:i:s\Z') !== $work['server_now']) {
            self::invalid();
        }
        if (! is_array($work['operations']) || ! array_is_list($work['operations']) || count($work['operations']) > 1) {
            self::invalid();
        }
        // Work is minted during the HTTPS request. Capture the receiver time
        // afterwards; a caller timestamp from before that request can precede
        // a valid newly issued grant when the request crosses a second boundary.
        $receivedAt = $now ?? new DateTimeImmutable('now');
        $results = [];
        foreach ($work['operations'] as $operation) {
            if (! is_array($operation)) {
                self::invalid();
            }
            $kind = $operation['operation'] ?? null;
            if ($kind === 'discover_hikvision_recorder') {
                if ($this->recorder === null) {
                    self::invalid();
                }
                $results[] = $this->recorder->exchange($operation, $identity, $input, $output, $receivedAt);
            } elseif ($kind === 'discover_hikvision') {
                $results[] = $this->exchange->exchange($operation, $identity, $input, $output, $receivedAt);
            } else {
                self::invalid();
            }
        }
        if ($results === []) {
            return;
        }
        $reply = $this->central->discoveryResults($identity['collector_id'], self::uuid(), $results);
        self::exact($reply, ['accepted']);
        $expected = [];
        foreach ($results as $result) {
            // The canonical application receipt sorts only the top-level result
            // keys. Preserve the uploaded result and nested inventory bytes;
            // compare the acknowledgement with that same committed digest.
            $canonical = $result;
            ksort($canonical);
            $expected[] = ['request_uuid' => $result['request_uuid'], 'result_sha256' => hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))];
        }
        if ($reply['accepted'] !== $expected) {
            self::invalid();
        }
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 15) | 64);
        $b[8] = chr((ord($b[8]) & 63) | 128);
        $s = bin2hex($b);

        return substr($s, 0, 8).'-'.substr($s, 8, 4).'-'.substr($s, 12, 4).'-'.substr($s, 16, 4).'-'.substr($s, 20);
    }

    private static function exact(array $value, array $keys): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            self::invalid();
        }
    }

    private static function invalid(): never
    {
        throw new RuntimeException('Discovery response unavailable.');
    }
}
