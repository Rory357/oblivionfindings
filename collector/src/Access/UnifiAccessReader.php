<?php

namespace Oblivion\Collector\Access;

use DateTimeImmutable;
use DateTimeZone;
use Oblivion\Collector\Runtime\NativeOnvifCameraNetwork;
use Oblivion\Collector\Runtime\OnvifCameraNetwork;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use RuntimeException;
use Throwable;

/**
 * S&D Phase H (H0): reads what a Site's UniFi Access console holds, on the Site's own hub: doors,
 * people and their cards, visitors, access policies and door events. Read only: nothing here
 * changes anything in UniFi.
 *
 * The console's token goes only to the console's trusted key. With no trusted key the hub reads
 * the certificate, sends no token, and reports it so Setup can show it to trust. Card numbers'
 * secret tokens never leave the hub: only their SHA-256 goes up.
 */
final class UnifiAccessReader
{
    /** One snapshot stays well under the spool's 2 MB frame and the server's item limit. */
    public const int MAX_SNAPSHOT_BYTES = 1_000_000;

    private const int PAGE_SIZE = 100;

    private const int MAX_PEOPLE_PAGES = 50;

    private const int MAX_VISITOR_PAGES = 10;

    /** Door events are read an hour at a time, so a backlog catches up over a few rounds. */
    private const int EVENT_WINDOW_SECONDS = 3600;

    private const int MAX_EVENT_WINDOWS = 24;

    private const int MAX_EVENT_PAGES_PER_WINDOW = 10;

    private const int MAX_EVENTS = 2000;

    /** The furthest back the hub will ever read, whatever the cursor says. */
    private const int MAX_EVENT_AGE_SECONDS = 7 * 86_400;

    private const int CONNECT_TIMEOUT = 5;

    private const int RESPONSE_TIMEOUT = 20;

    private const int MAX_RESPONSE_BYTES = 2_000_000;

    public function __construct(
        private readonly CredentialLeaseDecryptor $credentials,
        private readonly OnvifCameraNetwork $network = new NativeOnvifCameraNetwork,
        private readonly int $maxSnapshotBytes = self::MAX_SNAPSHOT_BYTES,
    ) {}

    /**
     * One console's snapshot, ready to go in the spool.
     *
     * @param  array<string, mixed>  $console  one entry of the signed `unifi_access` section
     * @return array<string, mixed>
     */
    public function read(array $console, string $eventsSince, DateTimeImmutable $now): array
    {
        $base = [
            'item_type' => 'unifi_access_snapshot',
            'console_id' => (string) $console['console_id'],
            'site_id' => (int) $console['site_id'],
            'synced_at' => $now->format(DATE_ATOM),
        ];
        $address = (string) $console['address'];
        $port = (int) $console['port'];
        $pin = is_string($console['trusted_pin'] ?? null) ? $console['trusted_pin'] : null;

        if ($pin === null) {
            return $this->untrustedOrUnreachable($base, $address, $port, null);
        }

        $material = [];
        try {
            $material = $this->credentials->open($console, $now);
            $token = $this->token($material);
        } catch (Throwable) {
            $this->erase($material);

            return $base + ['state' => 'failed', 'reason' => 'The hub’s sign-in for UniFi Access has expired or isn’t valid. It tries again with the next work list.'];
        }

        try {
            $origin = 'https://'.(str_contains($address, ':') ? '['.$address.']' : $address).':'.$port;
            $call = fn (string $method, string $path, ?array $body = null): array => $this->call($origin, $method, $path, $body, $token, $pin);

            $doors = $this->doors($call('GET', '/api/v1/developer/doors'));
            [$people, $peopleComplete] = $this->people($call);
            [$visitors, $visitorsComplete] = $this->visitors($call);
            $policies = $this->policies($call('GET', '/api/v1/developer/access_policies'));
            [$windows, $eventsUntil] = $this->events($call, $eventsSince, $now);
        } catch (ConsoleUnreachable) {
            return $this->untrustedOrUnreachable($base, $address, $port, $pin);
        } catch (ConsoleRefused $refused) {
            return $base + ['state' => 'failed', 'reason' => $refused->getMessage()];
        } catch (Throwable) {
            return $base + ['state' => 'failed', 'reason' => 'UniFi Access sent an answer the hub couldn’t read.'];
        } finally {
            sodium_memzero($token);
            $this->erase($material);
        }

        return $this->bounded($base + [
            'state' => 'ok',
            'complete' => $peopleComplete && $visitorsComplete,
            'doors' => $doors,
            'people' => $people,
            'visitors' => $visitors,
            'policies' => $policies,
        ], $windows, $eventsSince, $eventsUntil);
    }

    /**
     * No answer, or no trusted key yet: read the certificate (never sending anything else). A
     * certificate that isn't the trusted one goes up to be checked and trusted in Setup.
     *
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function untrustedOrUnreachable(array $base, string $address, int $port, ?string $trustedPin): array
    {
        $certificate = null;
        try {
            $certificate = $this->network->inspectCertificate($address, $port, self::CONNECT_TIMEOUT);
        } catch (Throwable) {
            $certificate = null;
        }
        if (is_array($certificate) && is_string($certificate['pin'] ?? null) && $certificate['pin'] !== $trustedPin) {
            return $base + [
                'state' => 'untrusted',
                'reason' => $trustedPin === null ? 'The console’s certificate isn’t trusted yet.' : 'The console’s certificate has changed.',
                'certificate' => [
                    'pin' => $certificate['pin'],
                    'fingerprint' => is_string($certificate['fingerprint'] ?? null) ? $certificate['fingerprint'] : '',
                    'subject' => is_string($certificate['subject'] ?? null) ? substr($certificate['subject'], 0, 255) : null,
                    'valid_to' => is_int($certificate['valid_to'] ?? null) ? $certificate['valid_to'] : null,
                ],
            ];
        }

        return $base + ['state' => 'failed', 'reason' => 'The hub couldn’t reach the UniFi Access console.'];
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed> the decoded answer (code SUCCESS)
     */
    private function call(string $origin, string $method, string $path, ?array $body, string $token, string $pin): array
    {
        $response = $this->network->request(
            $method,
            $origin.$path,
            ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$token, 'Content-Type' => 'application/json'],
            $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $pin,
            self::CONNECT_TIMEOUT,
            self::RESPONSE_TIMEOUT,
            self::MAX_RESPONSE_BYTES,
        );
        $status = (int) $response['status'];
        if ($status === 0) {
            throw new ConsoleUnreachable;
        }
        if ($response['truncated'] ?? false) {
            throw new ConsoleRefused('UniFi Access sent more than the hub reads in one go.');
        }
        if ($status === 401 || $status === 403) {
            throw new ConsoleRefused('UniFi Access refused the token. Check it in UniFi Access › Settings › Advanced › API Token, with read rights for doors, users, visitors, policies and system logs.');
        }
        if ($status === 429) {
            throw new ConsoleRefused('UniFi Access asked the hub to slow down. It tries again next round.');
        }
        if ($status !== 200) {
            throw new ConsoleRefused('UniFi Access answered '.$status.'.');
        }
        try {
            $payload = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('UniFi Access sent an answer that isn’t JSON.');
        }
        if (! is_array($payload) || ($payload['code'] ?? null) !== 'SUCCESS') {
            throw new ConsoleRefused('UniFi Access didn’t accept the request.');
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload @return list<array<string, mixed>> */
    private function doors(array $payload): array
    {
        $rows = [];
        foreach (array_slice($this->list($payload['data'] ?? null), 0, 500) as $door) {
            $id = self::id($door['id'] ?? null);
            if ($id === null) {
                continue;
            }
            $relay = $door['door_lock_relay_status'] ?? null;
            $position = $door['door_position_status'] ?? null;
            $rows[] = [
                'id' => $id,
                'name' => self::text($door['full_name'] ?? $door['name'] ?? null, 191),
                'lock' => $relay === 'lock' ? 'locked' : ($relay === 'unlock' ? 'unlocked' : null),
                'position' => $position === 'open' ? 'open' : ($position === 'close' ? 'closed' : null),
                'hub_bound' => ($door['is_bind_hub'] ?? false) === true,
            ];
        }

        return $rows;
    }

    /** @return array{0: list<array<string, mixed>>, 1: bool} */
    private function people(callable $call): array
    {
        $rows = [];
        for ($page = 1; $page <= self::MAX_PEOPLE_PAGES; $page++) {
            $payload = $call('GET', '/api/v1/developer/users?expand%5B%5D=access_policy&page_num='.$page.'&page_size='.self::PAGE_SIZE);
            $users = $this->list($payload['data'] ?? null);
            foreach ($users as $user) {
                $id = self::id($user['id'] ?? null);
                if ($id === null) {
                    continue;
                }
                $policyIds = [];
                foreach ($this->list($user['access_policy_ids'] ?? null, scalars: true) as $policyId) {
                    $policyIds[] = self::id($policyId);
                }
                foreach ($this->list($user['access_policies'] ?? null) as $policy) {
                    $policyIds[] = self::id($policy['id'] ?? null);
                }
                $cards = [];
                foreach (array_slice($this->list($user['nfc_cards'] ?? null), 0, 20) as $card) {
                    $secret = $card['token'] ?? null;
                    if (! is_string($secret) || $secret === '' || strlen($secret) > 512) {
                        continue;
                    }
                    // The card's secret token never leaves the hub; its hash is enough to recognise it.
                    $cards[] = ['token_hash' => hash('sha256', $secret), 'display_id' => self::text($card['id'] ?? null, 32)];
                }
                $status = strtolower(is_string($user['status'] ?? null) ? $user['status'] : '');
                $rows[] = [
                    'id' => $id,
                    'first_name' => self::text($user['first_name'] ?? null, 120),
                    'last_name' => self::text($user['last_name'] ?? null, 120),
                    'employee_number' => self::text($user['employee_number'] ?? null, 64),
                    'status' => in_array($status, ['active', 'pending', 'deactivated'], true) ? $status : 'active',
                    'policy_ids' => array_values(array_unique(array_filter($policyIds, 'is_string'))),
                    'cards' => $cards,
                ];
            }
            if (count($users) < self::PAGE_SIZE || ! $this->morePages($payload, $page)) {
                return [$rows, true];
            }
        }

        return [$rows, false];
    }

    /** @return array{0: list<array<string, mixed>>, 1: bool} */
    private function visitors(callable $call): array
    {
        $rows = [];
        for ($page = 1; $page <= self::MAX_VISITOR_PAGES; $page++) {
            $payload = $call('GET', '/api/v1/developer/visitors?page_num='.$page.'&page_size='.self::PAGE_SIZE);
            $visitors = $this->list($payload['data'] ?? null);
            foreach ($visitors as $visitor) {
                $id = self::id($visitor['id'] ?? null);
                if ($id === null) {
                    continue;
                }
                $name = trim((self::text($visitor['first_name'] ?? null, 120) ?? '').' '.(self::text($visitor['last_name'] ?? null, 120) ?? ''));
                $rows[] = [
                    'id' => $id,
                    'name' => $name === '' ? null : $name,
                    'status' => self::text(is_string($visitor['status'] ?? null) ? strtolower($visitor['status']) : null, 24),
                    'starts_at' => self::unixTime($visitor['start_time'] ?? null),
                    'ends_at' => self::unixTime($visitor['end_time'] ?? null),
                ];
            }
            if (count($visitors) < self::PAGE_SIZE || ! $this->morePages($payload, $page)) {
                return [$rows, true];
            }
        }

        return [$rows, false];
    }

    /** @param array<string, mixed> $payload @return list<array<string, mixed>> */
    private function policies(array $payload): array
    {
        $rows = [];
        foreach (array_slice($this->list($payload['data'] ?? null), 0, 500) as $policy) {
            $id = self::id($policy['id'] ?? null);
            if ($id === null) {
                continue;
            }
            $doorIds = [];
            foreach ($this->list($policy['resources'] ?? null) as $resource) {
                if (($resource['type'] ?? null) === 'door' && ($doorId = self::id($resource['id'] ?? null)) !== null) {
                    $doorIds[] = $doorId;
                }
            }
            $scheduleId = self::id($policy['schedule_id'] ?? null);
            $rows[] = [
                'id' => $id,
                'name' => self::text($policy['name'] ?? null, 191),
                'door_ids' => array_values(array_unique($doorIds)),
                'schedule' => $scheduleId === null ? null : ['id' => $scheduleId],
            ];
        }

        return $rows;
    }

    /**
     * Door events since the cursor, an hour at a time. Each window is read in full (or as far as
     * the page limit allows) before the cursor moves past it, so a busy hour is never skipped.
     *
     * @return array{0: list<array{until: string, events: list<array<string, mixed>>}>, 1: string}
     */
    private function events(callable $call, string $eventsSince, DateTimeImmutable $now): array
    {
        try {
            $since = (new DateTimeImmutable($eventsSince))->getTimestamp();
        } catch (Throwable) {
            $since = $now->getTimestamp() - 86_400;
        }
        $end = $now->getTimestamp();
        $since = max($since, $end - self::MAX_EVENT_AGE_SECONDS);
        $windows = [];
        $count = 0;
        $cursor = $since;
        for ($window = 0; $window < self::MAX_EVENT_WINDOWS && $cursor < $end && $count < self::MAX_EVENTS; $window++) {
            $until = min($end, $cursor + self::EVENT_WINDOW_SECONDS);
            $events = [];
            for ($page = 1; $page <= self::MAX_EVENT_PAGES_PER_WINDOW; $page++) {
                $payload = $call('POST', '/api/v1/developer/system/logs?page_num='.$page.'&page_size='.self::PAGE_SIZE, [
                    'topic' => 'door_openings',
                    'since' => $cursor,
                    'until' => $until,
                ]);
                $data = $payload['data'] ?? null;
                $hits = $this->list(is_array($data) && array_key_exists('hits', $data) ? $data['hits'] : $data);
                foreach ($hits as $hit) {
                    $event = $this->event($hit);
                    if ($event !== null) {
                        $events[] = $event;
                    }
                }
                if (count($hits) < self::PAGE_SIZE || ! $this->morePages($payload, $page)) {
                    break;
                }
            }
            $windows[] = ['until' => gmdate(DATE_ATOM, $until), 'events' => $events];
            $count += count($events);
            $cursor = $until;
        }

        return [$windows, gmdate(DATE_ATOM, $cursor)];
    }

    /** @param array<string, mixed> $hit @return array<string, mixed>|null */
    private function event(array $hit): ?array
    {
        $source = is_array($hit['_source'] ?? null) ? $hit['_source'] : $hit;
        $id = self::id($hit['_id'] ?? $hit['id'] ?? null);
        $occurred = self::isoTime($hit['@timestamp'] ?? $source['@timestamp'] ?? null)
            ?? self::millisTime($source['event']['published'] ?? null);
        if ($id === null || $occurred === null) {
            return null;
        }
        $type = strtolower(is_string($source['event']['type'] ?? null) ? $source['event']['type'] : '');
        $result = strtoupper(is_string($source['event']['result'] ?? null) ? $source['event']['result'] : '');
        $provider = strtoupper(is_string($source['authentication']['credential_provider'] ?? null) ? $source['authentication']['credential_provider'] : '');
        $doorId = null;
        foreach ($this->list($source['target'] ?? null) as $target) {
            if (($target['type'] ?? null) === 'door') {
                $doorId = self::id($target['id'] ?? null);
                break;
            }
        }
        $actor = is_array($source['actor'] ?? null) ? $source['actor'] : [];
        $actorType = strtolower(is_string($actor['type'] ?? null) ? $actor['type'] : '');

        return [
            'id' => $id,
            'occurred_at' => $occurred,
            'door_id' => $doorId,
            'person_id' => $actorType === 'user' ? self::id($actor['id'] ?? null) : null,
            'kind' => match (true) {
                str_contains($type, 'forced') => 'forced',
                str_contains($type, 'held') => 'held_open',
                $result === 'BLOCKED' || $result === 'DENIED' || $result === 'FAILED' => 'denied',
                str_starts_with($provider, 'REMOTE') || $provider === 'API' => 'unlocked',
                $result === 'ACCESS' || $result === 'SUCCESS' || $result === '' => 'opened',
                default => 'other',
            },
            'result' => self::text($result === '' ? null : strtolower($result), 24),
            'method' => match (true) {
                $provider === 'NFC' || str_contains($provider, 'CARD') => 'card',
                str_contains($provider, 'PIN') => 'pin',
                str_contains($provider, 'MOBILE') || str_contains($provider, 'APP') => 'app',
                str_contains($provider, 'FACE') => 'face',
                str_contains($provider, 'FINGER') => 'fingerprint',
                str_contains($provider, 'BUTTON') || $provider === 'REX' => 'button',
                str_starts_with($provider, 'REMOTE') || $provider === 'API' => 'remote',
                default => 'other',
            },
            'actor_name' => self::text($actor['display_name'] ?? null, 191),
        ];
    }

    /**
     * Keep the snapshot under the size limit: drop the newest event windows first (the cursor
     * stays before them, so they come next round), then people, marking the snapshot incomplete
     * so the server deletes nothing it didn't see.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  list<array{until: string, events: list<array<string, mixed>>}>  $windows
     * @return array<string, mixed>
     */
    private function bounded(array $snapshot, array $windows, string $eventsSince, string $eventsUntil): array
    {
        while (true) {
            $snapshot['events'] = array_merge(...array_map(static fn (array $window): array => $window['events'], $windows ?: [['events' => []]]));
            $snapshot['events_until'] = $windows === [] ? $this->cursor($eventsSince) : $eventsUntil;
            $size = strlen(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
            if ($size <= min($this->maxSnapshotBytes, self::MAX_SNAPSHOT_BYTES)) {
                return $snapshot;
            }
            if ($windows !== []) {
                array_pop($windows);
                $eventsUntil = $windows === [] ? $this->cursor($eventsSince) : $windows[array_key_last($windows)]['until'];

                continue;
            }
            if ($snapshot['people'] === [] && $snapshot['visitors'] === []) {
                $snapshot['doors'] = [];
                $snapshot['policies'] = [];
                $snapshot['complete'] = false;

                return $snapshot;
            }
            $snapshot['complete'] = false;
            $snapshot['people'] = array_slice($snapshot['people'], 0, intdiv(count($snapshot['people']), 2));
            $snapshot['visitors'] = array_slice($snapshot['visitors'], 0, intdiv(count($snapshot['visitors']), 2));
        }
    }

    private function cursor(string $eventsSince): string
    {
        try {
            return (new DateTimeImmutable($eventsSince))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
        } catch (Throwable) {
            return gmdate(DATE_ATOM, time() - 86_400);
        }
    }

    /** @param array<string, mixed> $payload */
    private function morePages(array $payload, int $page): bool
    {
        $total = $payload['pagination']['total'] ?? null;

        return ! is_int($total) || $page * self::PAGE_SIZE < $total;
    }

    /** @return list<mixed> */
    private function list(mixed $value, bool $scalars = false): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $row): bool => $scalars ? is_string($row) : is_array($row)));
    }

    /** @param array<string, scalar|null> $material */
    private function token(array $material): string
    {
        $token = $material['api_token'] ?? $material['token'] ?? null;
        if (! is_string($token) || strlen($token) < 8 || strlen($token) > 4090
            || preg_match('/[\x00-\x20\x7f]/', $token) === 1) {
            throw new RuntimeException('UniFi Access credential material is invalid.');
        }

        return $token;
    }

    /** @param array<string, scalar|null> $material */
    private function erase(array &$material): void
    {
        foreach ($material as &$value) {
            if (is_string($value) && $value !== '') {
                sodium_memzero($value);
            }
            $value = null;
        }
        unset($value);
        $material = [];
    }

    public static function id(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9._:-]{1,64}\z/', $value) === 1 ? $value : null;
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $value));

        return $clean === '' ? null : mb_substr($clean, 0, $max);
    }

    private static function unixTime(mixed $value): ?string
    {
        return is_int($value) && $value > 0 && $value < 4_102_444_800 ? gmdate(DATE_ATOM, $value) : null;
    }

    private static function millisTime(mixed $value): ?string
    {
        return is_int($value) && $value > 0 ? self::unixTime(intdiv($value, 1000)) : null;
    }

    private static function isoTime(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 40) {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
        } catch (Throwable) {
            return null;
        }
    }
}
