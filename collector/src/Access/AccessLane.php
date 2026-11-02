<?php

namespace Oblivion\Collector\Access;

use Closure;
use DateTimeImmutable;
use Oblivion\Collector\Runtime\NativeOnvifCameraNetwork;
use Oblivion\Collector\Security\CredentialLeaseDecryptor;
use Oblivion\Collector\Security\EnvelopeVerifier;
use Throwable;

/**
 * S&D Phase H (H1): UniFi Access changes, run inside the hub's always-on relay process. Each poll
 * sends what was done and gets the next changes.
 *
 * A change runs only when Oblivion signed it, it hasn't expired, the console is on a private
 * address with a trusted key, and the sealed token is for exactly that console. Changes are
 * administration only (people, doors they can open, cards, visitor passes). The journal means a
 * change is never run twice: one the hub started but never finished is reported as "uncertain",
 * not repeated. A card enrolment waits for its tap across polls, so the relay never stalls.
 */
final class AccessLane
{
    private const array DETAIL_KEYS = ['job', 'kind', 'params', 'console_id', 'site_id', 'address', 'port', 'trusted_pin', 'expires_at', 'credential_lease', 'signature'];

    /** Each kind's parameters, exactly (the server sends the same). */
    private const array KINDS = [
        'user_create' => ['first_name', 'last_name', 'employee_number', 'policy_ids'],
        'user_status' => ['user_id', 'status'],
        'user_policies' => ['user_id', 'policy_ids'],
        'card_enrol' => ['user_id', 'reader_id'],
        'card_remove' => ['user_id', 'token_hash'],
        'visitor_create' => ['first_name', 'last_name', 'starts_at', 'ends_at', 'door_ids'],
        'visitor_delete' => ['visitor_id'],
    ];

    /** How long a reader listens for a new card. */
    private const int ENROL_SECONDS = 60;

    private const int JOURNAL_DAYS = 2;

    /** @var list<array<string, mixed>> results to send with the next poll */
    private array $pending = [];

    /** @var array<string, array{details: array<string, mixed>, session: string, deadline: float, next: float}> */
    private array $enrolments = [];

    private float $nextPollAt = 0.0;

    private float $activeUntil = 0.0;

    private readonly UnifiAccessAdmin $admin;

    /**
     * @param  Closure(array<string, mixed>): array<string, mixed>  $work
     * @param  (Closure(): float)|null  $clock
     */
    public function __construct(
        private readonly Closure $work,
        private readonly EnvelopeVerifier $verifier,
        private readonly CredentialLeaseDecryptor $credentials,
        private readonly string $journalFile,
        ?UnifiAccessAdmin $admin = null,
        private ?Closure $clock = null,
    ) {
        $this->admin = $admin ?? new UnifiAccessAdmin(new NativeOnvifCameraNetwork);
        $this->clock ??= static fn (): float => microtime(true);
        // Anything started before a restart and never finished is reported once as uncertain.
        $journal = $this->journal();
        foreach ($journal as $uuid => $entry) {
            if (($entry['state'] ?? null) === 'started') {
                $row = ['job' => $uuid, 'state' => 'uncertain', 'reason' => 'The hub restarted while making this change, so it wasn’t repeated. Check UniFi Access.'];
                $journal[$uuid] = ['state' => 'done', 'row' => $row, 'at' => time()];
                $this->pending[] = $row;
            }
        }
        $this->saveJournal($journal);
    }

    public function active(): bool
    {
        return ($this->clock)() < $this->activeUntil || $this->enrolments !== [];
    }

    public function tick(): void
    {
        $now = ($this->clock)();
        $this->stepEnrolments($now);
        if ($now < $this->nextPollAt && $this->pending === []) {
            return;
        }
        try {
            $response = ($this->work)(['results' => $this->pending]);
        } catch (Throwable) {
            $this->nextPollAt = $now + 5.0;

            return;
        }
        $this->pending = [];
        $jobs = array_slice(is_array($response['jobs'] ?? null) ? $response['jobs'] : [], 0, 4);
        foreach ($jobs as $details) {
            if (is_array($details)) {
                $this->handle($details, $now);
            }
        }
        if ($jobs !== []) {
            $this->activeUntil = $now + 60.0;
        }
        $this->nextPollAt = $now + ($this->active() ? 1.0 : 5.0);
    }

    /** @param array<string, mixed> $details */
    private function handle(array $details, float $now): void
    {
        if (array_keys($details) !== self::DETAIL_KEYS || ! is_string($details['signature'])
            || ! $this->verifier->verifyDetached(self::canonical($details), $details['signature'])) {
            return; // Not from Oblivion: nothing is run and nothing is said.
        }
        $uuid = strtolower((string) $details['job']);
        $journal = $this->journal();
        if (isset($journal[$uuid])) {
            // Asked again (our answer was lost): say the same thing again, never run it twice.
            $entry = $journal[$uuid];
            if (($entry['state'] ?? null) === 'done' && is_array($entry['row'] ?? null)) {
                $this->pending[] = $entry['row'];
            }

            return;
        }
        $refusal = $this->refusal($details, $now);
        if ($refusal !== null) {
            $this->finish($uuid, ['job' => $uuid, 'state' => 'failed', 'reason' => $refusal]);

            return;
        }
        $material = [];
        try {
            $material = $this->credentials->open($details, new DateTimeImmutable('@'.(int) $now));
            $token = self::token($material);
        } catch (Throwable) {
            $this->erase($material);
            $this->finish($uuid, ['job' => $uuid, 'state' => 'failed', 'reason' => 'The hub’s sign-in for UniFi Access has expired or isn’t valid. Nothing was changed.']);

            return;
        }
        $journal[$uuid] = ['state' => 'started', 'at' => time()];
        $this->saveJournal($journal);
        try {
            $outcome = $this->admin->run($details, $token);
        } finally {
            sodium_memzero($token);
            $this->erase($material);
        }
        if ($outcome['state'] === 'waiting' && is_string($outcome['enrol_session'] ?? null)) {
            $this->enrolments[$uuid] = ['details' => $details, 'session' => $outcome['enrol_session'], 'deadline' => $now + self::ENROL_SECONDS, 'next' => $now + 2.0];

            return;
        }
        $this->finish($uuid, ['job' => $uuid] + $outcome);
    }

    private function stepEnrolments(float $now): void
    {
        foreach ($this->enrolments as $uuid => $enrolment) {
            if ($now < $enrolment['next']) {
                continue;
            }
            $material = [];
            try {
                $material = $this->credentials->open($enrolment['details'], new DateTimeImmutable('@'.(int) $now));
                $token = self::token($material);
            } catch (Throwable) {
                $this->erase($material);
                unset($this->enrolments[$uuid]);
                $this->finish($uuid, ['job' => $uuid, 'state' => 'uncertain', 'reason' => 'The hub’s sign-in ran out while waiting for the card. Check the person in UniFi Access.']);

                continue;
            }
            try {
                $outcome = $this->admin->enrolPoll($enrolment['details'], $token, $enrolment['session'], $now >= $enrolment['deadline']);
            } finally {
                sodium_memzero($token);
                $this->erase($material);
            }
            if ($outcome === null) {
                $this->enrolments[$uuid]['next'] = $now + 2.0;

                continue;
            }
            unset($this->enrolments[$uuid]);
            $this->finish($uuid, ['job' => $uuid] + $outcome);
        }
    }

    /** @param array<string, mixed> $row */
    private function finish(string $uuid, array $row): void
    {
        $journal = $this->journal();
        $journal[$uuid] = ['state' => 'done', 'row' => $row, 'at' => time()];
        $this->saveJournal($journal);
        $this->pending[] = $row;
        $this->nextPollAt = 0.0;
    }

    /** Why a signed change still can't run, or null. @param array<string, mixed> $details */
    private function refusal(array $details, float $now): ?string
    {
        try {
            $expires = new DateTimeImmutable((string) $details['expires_at']);
        } catch (Throwable) {
            return 'The change had no valid end time.';
        }
        if ($expires->getTimestamp() < (int) $now) {
            return 'The change reached the hub too late, so nothing was changed.';
        }
        $address = $details['address'];
        $lease = $details['credential_lease'];
        $kind = $details['kind'];
        $params = $details['params'];
        if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false
            || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            || ! in_array($details['port'], [443, 12445], true)
            || ! is_string($details['trusted_pin']) || preg_match('/\Asha256\/\/[A-Za-z0-9+\/]{43}=\z/', $details['trusted_pin']) !== 1
            || ! is_string($details['console_id']) || preg_match('/\A[1-9][0-9]{0,18}\z/', $details['console_id']) !== 1
            || ! is_int($details['site_id'])
            || ! is_array($lease) || ($lease['protocol'] ?? null) !== 'access.unifi' || ($lease['target'] ?? null) !== $address
            || ($lease['device_id'] ?? null) !== 'access-console-'.$details['console_id'] || ($lease['site_id'] ?? null) !== $details['site_id']) {
            return 'The change isn’t for a UniFi Access console this hub can reach safely.';
        }
        if (! is_string($kind) || ! isset(self::KINDS[$kind]) || ! is_array($params) || array_keys($params) !== self::KINDS[$kind] || ! self::validParams($kind, $params)) {
            return 'The hub doesn’t make that kind of change.';
        }

        return null;
    }

    /** @param array<string, mixed> $params */
    private static function validParams(string $kind, array $params): bool
    {
        $id = static fn (mixed $value): bool => UnifiAccessReader::id($value) !== null;
        $ids = static fn (mixed $value): bool => is_array($value) && array_is_list($value) && count($value) <= 20 && array_filter($value, $id) === $value;
        $name = static fn (mixed $value, bool $required): bool => is_string($value) && mb_strlen($value) <= 60 && ($required ? trim($value) !== '' : true)
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;

        return match ($kind) {
            'user_create' => $name($params['first_name'], true) && $name($params['last_name'], false)
                && is_string($params['employee_number']) && preg_match('/\A[A-Za-z0-9._-]{0,30}\z/', $params['employee_number']) === 1
                && $ids($params['policy_ids']),
            'user_status' => $id($params['user_id']) && in_array($params['status'], ['active', 'deactivated'], true),
            'user_policies' => $id($params['user_id']) && $ids($params['policy_ids']),
            'card_enrol' => $id($params['user_id']) && $id($params['reader_id']),
            'card_remove' => $id($params['user_id']) && is_string($params['token_hash']) && preg_match('/\A[0-9a-f]{64}\z/', $params['token_hash']) === 1,
            'visitor_create' => $name($params['first_name'], true) && $name($params['last_name'], false)
                && is_int($params['starts_at']) && is_int($params['ends_at']) && $params['starts_at'] > 1_700_000_000
                && $params['ends_at'] > $params['starts_at'] && $params['ends_at'] - $params['starts_at'] <= 31 * 86_400
                && $ids($params['door_ids']) && $params['door_ids'] !== [],
            'visitor_delete' => $id($params['visitor_id']),
            default => false,
        };
    }

    /** The exact bytes Oblivion signs: the details without their signature. @param array<string, mixed> $details */
    public static function canonical(array $details): string
    {
        unset($details['signature']);

        return json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, scalar|null> $material */
    private static function token(array $material): string
    {
        $token = $material['api_token'] ?? $material['token'] ?? null;
        if (! is_string($token) || strlen($token) < 8 || strlen($token) > 4090 || preg_match('/[\x00-\x20\x7f]/', $token) === 1) {
            throw new \RuntimeException('UniFi Access credential material is invalid.');
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

    /** @return array<string, array<string, mixed>> */
    private function journal(): array
    {
        if (! is_file($this->journalFile) || filesize($this->journalFile) > 1_048_576) {
            return [];
        }
        try {
            $decoded = json_decode((string) file_get_contents($this->journalFile), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) ? array_filter($decoded, 'is_array') : [];
    }

    /** @param array<string, array<string, mixed>> $journal */
    private function saveJournal(array $journal): void
    {
        $cutoff = time() - self::JOURNAL_DAYS * 86_400;
        $journal = array_filter($journal, static fn (array $entry): bool => ($entry['at'] ?? 0) >= $cutoff);
        $temporary = $this->journalFile.'.tmp';
        if (@file_put_contents($temporary, json_encode((object) $journal, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) {
            @chmod($temporary, 0600);
            @rename($temporary, $this->journalFile);
        }
    }
}
