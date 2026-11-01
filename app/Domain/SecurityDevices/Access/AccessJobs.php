<?php

namespace App\Domain\SecurityDevices\Access;

use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\CollectorCredentialLeaseSealer;
use App\Domain\SecurityDevices\Access\Models\AccessCard;
use App\Domain\SecurityDevices\Access\Models\AccessConsole;
use App\Domain\SecurityDevices\Access\Models\AccessJob;
use App\Domain\SecurityDevices\Access\Models\AccessPerson;
use App\Domain\SecurityDevices\Access\Models\AccessVisitor;
use App\Domain\SecurityDevices\Cctv\Services\CameraEndpointTrustService;
use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Management\Services\HubJobTypes;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * S&D Phase H (H1): changes Oblivion asks a Site's hub to make in UniFi Access: add a person, turn
 * one on or off, set which doors they can open, add or remove a card, add or remove a visitor pass.
 *
 * These are administration, not door actions: no job here unlocks a door or changes an emergency
 * mode (those stay governed commands). The hub picks jobs up in its always-on process, each signed
 * by Oblivion with the console's token sealed to that hub; it never repeats a job it has started.
 * Every request and every outcome is audited.
 */
final class AccessJobs
{
    public const string HUB_FEATURE = 'unifi_access_admin_v1';

    /** Each kind's parameters, exactly. Keys starting with "_" stay on the server (never sent). */
    public const array KINDS = [
        'user_create' => ['first_name', 'last_name', 'employee_number', 'policy_ids'],
        'user_status' => ['user_id', 'status'],
        'user_policies' => ['user_id', 'policy_ids'],
        'card_enrol' => ['user_id', 'reader_id'],
        'card_remove' => ['user_id', 'token_hash'],
        'visitor_create' => ['first_name', 'last_name', 'starts_at', 'ends_at', 'door_ids'],
        'visitor_delete' => ['visitor_id'],
    ];

    /** A job not picked up by then is dropped; one picked up but not answered becomes "uncertain". */
    public const int DEFAULT_TTL_SECONDS = 600;

    /** A sent job with no answer after this is offered again (the hub never repeats one it started). */
    private const int RESEND_AFTER_SECONDS = 90;

    public function __construct(
        private readonly CoveringHubResolver $hubs,
        private readonly CameraEndpointTrustService $trust,
        private readonly CollectorCredentialLeaseSealer $sealer,
        private readonly CredentialLeaseProvider $leases,
    ) {}

    /**
     * Ask the console's hub to make one change.
     *
     * @param  array<string, mixed>  $params
     */
    public function queue(AccessConsole $console, string $kind, array $params, ?User $actor, ?string $reason = null, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): AccessJob
    {
        $sent = self::validate($kind, $params);
        // A person's change waits on Setup's switch; turning a leaver off (no person) never does.
        if ($actor !== null && ! app(\App\Domain\SecurityDevices\Setup\SecurityDevicesSettings::class)->bool(\App\Domain\SecurityDevices\Setup\SecurityDevicesSettings::ACCESS_CHANGES)) {
            throw new DomainException('Changes to UniFi Access from Oblivion are turned off in Setup › Doors & access.');
        }
        $hub = $this->hubs->forSite((int) $console->site_id);
        if (! $hub instanceof MonitoringCollector) {
            throw new DomainException('No hub covers this Site, so UniFi Access can’t be changed from Oblivion.');
        }
        if (! HubJobTypes::runs($hub, self::HUB_FEATURE)) {
            throw new DomainException('This Site’s hub needs an update before it can change UniFi Access.');
        }
        if (UnifiAccessSync::reference((int) $console->site_id) === null) {
            throw new DomainException('Save the UniFi Access login in Setup first.');
        }
        $pin = $this->trust->record((int) $console->site_id, $console->address, (int) $console->port)?->trusted_pin;
        if (! is_string($pin) || preg_match(CameraEndpointTrustService::PIN_PATTERN, $pin) !== 1) {
            throw new DomainException('Trust the console’s certificate in Setup first.');
        }

        return DB::transaction(function () use ($console, $kind, $params, $sent, $actor, $reason, $ttlSeconds): AccessJob {
            $job = AccessJob::query()->create([
                'uuid' => (string) Str::uuid(),
                'console_id' => $console->id,
                'kind' => $kind,
                'params' => [...$sent, ...array_filter($params, static fn (string $key): bool => str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY)],
                'requested_by_user_id' => $actor?->id,
                'reason' => $reason !== null ? mb_substr($reason, 0, 191) : null,
                'state' => 'queued',
                'expires_at' => CarbonImmutable::now('UTC')->addSeconds(max(60, min(3600, $ttlSeconds))),
            ]);
            AuditLogger::logOrFail('security_devices.access.job_requested', $job, [
                'kind' => $kind,
                'console_id' => (int) $console->id,
                'site_id' => (int) $console->site_id,
                'by' => $actor !== null ? 'person' : 'oblivion',
                'reason' => $job->reason,
            ]);

            return $job;
        });
    }

    /**
     * The hub's poll: record what it did, hand it what's waiting for its Sites' consoles.
     *
     * @param  array<string, mixed>  $report
     * @return array{jobs: list<array<string, mixed>>}
     */
    public function hubWork(MonitoringCollector $hub, array $report): array
    {
        foreach (array_slice(is_array($report['results'] ?? null) ? $report['results'] : [], 0, 20) as $row) {
            if (is_array($row) && is_string($row['job'] ?? null)) {
                $this->record($hub, $row);
            }
        }
        if (! HubJobTypes::runs($hub, self::HUB_FEATURE) || ! $hub->isApprovedForWork()) {
            return ['jobs' => []];
        }
        $this->expire();
        $now = CarbonImmutable::now('UTC');
        $siteIds = $hub->authorisedSiteIds();
        $out = [];
        $candidates = AccessJob::query()
            ->whereHas('console', fn ($query) => $query->whereIn('site_id', $siteIds))
            ->where('expires_at', '>', $now)
            ->where(fn ($query) => $query->where('state', 'queued')
                ->orWhere(fn ($sent) => $sent->where('state', 'sent')->where('collector_id', $hub->id)->where('sent_at', '<', $now->subSeconds(self::RESEND_AFTER_SECONDS))))
            ->orderBy('id')
            ->limit(4)
            ->get();
        foreach ($candidates as $candidate) {
            $payload = DB::transaction(function () use ($candidate, $hub, $now): ?array {
                $job = AccessJob::query()->whereKey($candidate->id)->lockForUpdate()->first();
                if (! $job instanceof AccessJob || ! in_array($job->state, ['queued', 'sent'], true)) {
                    return null;
                }
                if ($job->state === 'sent' && (int) $job->collector_id !== (int) $hub->id) {
                    return null;
                }
                try {
                    $details = $this->details($hub, $job);
                } catch (DomainException $refused) {
                    $this->finish($job, 'failed', null, $refused->getMessage());

                    return null;
                } catch (Throwable) {
                    return null; // Tried again next poll.
                }
                $job->forceFill(['state' => 'sent', 'collector_id' => $hub->id, 'sent_at' => $now])->save();

                return $details;
            });
            if ($payload !== null) {
                $out[] = $payload;
            }
        }

        return ['jobs' => $out];
    }

    /** Jobs never picked up are dropped; jobs picked up but never answered are "uncertain". */
    public function expire(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now('UTC');
        $count = 0;
        AccessJob::query()->whereIn('state', ['queued', 'sent'])->where('expires_at', '<=', $now)->orderBy('id')->limit(200)->get()
            ->each(function (AccessJob $job) use (&$count): void {
                $this->finish($job, $job->state === 'sent' ? 'uncertain' : 'expired', null, $job->state === 'sent'
                    ? 'The hub didn’t say whether this was done. Check UniFi Access before trying again.'
                    : 'The hub didn’t pick this up in time, so nothing was changed.');
                $count++;
            });

        return $count;
    }

    /** The exact bytes the server signs and the hub verifies: the details without their signature. */
    public static function canonical(array $details): string
    {
        unset($details['signature']);

        return json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Strict parameters for each kind (the hub checks the same). Returns what is sent to the hub.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public static function validate(string $kind, array $params): array
    {
        $keys = self::KINDS[$kind] ?? null;
        if ($keys === null) {
            throw new DomainException('That isn’t a change Oblivion can make in UniFi Access.');
        }
        $sent = array_filter($params, static fn (string $key): bool => ! str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);
        if (array_keys($sent) !== $keys) {
            throw new DomainException('The change is missing details.');
        }
        $id = static fn (mixed $value): bool => UnifiAccessSync::id($value) !== null;
        $ids = static fn (mixed $value): bool => is_array($value) && array_is_list($value) && count($value) <= 20 && array_filter($value, $id) === $value;
        $name = static fn (mixed $value, bool $required): bool => is_string($value) && mb_strlen($value) <= 60 && ($required ? trim($value) !== '' : true)
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
        $ok = match ($kind) {
            'user_create' => $name($sent['first_name'], true) && $name($sent['last_name'], false)
                && is_string($sent['employee_number']) && preg_match('/\A[A-Za-z0-9._-]{0,30}\z/', $sent['employee_number']) === 1
                && $ids($sent['policy_ids']),
            'user_status' => $id($sent['user_id']) && in_array($sent['status'], ['active', 'deactivated'], true),
            'user_policies' => $id($sent['user_id']) && $ids($sent['policy_ids']),
            'card_enrol' => $id($sent['user_id']) && $id($sent['reader_id']),
            'card_remove' => $id($sent['user_id']) && is_string($sent['token_hash']) && preg_match('/\A[0-9a-f]{64}\z/', $sent['token_hash']) === 1,
            'visitor_create' => $name($sent['first_name'], true) && $name($sent['last_name'], false)
                && is_int($sent['starts_at']) && is_int($sent['ends_at']) && $sent['starts_at'] > 1_700_000_000
                && $sent['ends_at'] > $sent['starts_at'] && $sent['ends_at'] - $sent['starts_at'] <= 31 * 86_400
                && $ids($sent['door_ids']) && $sent['door_ids'] !== [],
            'visitor_delete' => $id($sent['visitor_id']),
        };
        if (! $ok) {
            throw new DomainException('The change has a detail Oblivion can’t send.');
        }

        return $sent;
    }

    /** @return array<string, mixed> */
    private function details(MonitoringCollector $hub, AccessJob $job): array
    {
        $console = $job->console;
        if (! $console instanceof AccessConsole || ! in_array((int) $console->site_id, $hub->authorisedSiteIds(), true)) {
            throw new DomainException('This console isn’t at one of the hub’s Sites.');
        }
        $pin = $this->trust->record((int) $console->site_id, $console->address, (int) $console->port)?->trusted_pin;
        if (! is_string($pin) || preg_match(CameraEndpointTrustService::PIN_PATTERN, $pin) !== 1) {
            throw new DomainException('Trust the console’s certificate in Setup first.');
        }
        $reference = UnifiAccessSync::reference((int) $console->site_id);
        $capabilities = $reference ? array_values(array_filter($reference->capabilities ?? [], 'is_string')) : [];
        if ($reference === null || $capabilities === []) {
            throw new DomainException('Save the UniFi Access login in Setup first.');
        }
        $lease = $this->leases->acquire((int) $console->site_id, $reference->reference_key, [in_array('write:access.admin', $capabilities, true) ? 'write:access.admin' : $capabilities[0]]);
        $details = [
            'job' => $job->uuid,
            'kind' => $job->kind,
            'params' => self::validate($job->kind, (array) $job->params),
            'console_id' => (string) $console->id,
            'site_id' => (int) $console->site_id,
            'address' => $console->address,
            'port' => (int) $console->port,
            'trusted_pin' => $pin,
            'expires_at' => $job->expires_at->utc()->format(DATE_ATOM),
            'credential_lease' => $this->sealer->seal($hub, 'access-console-'.$console->id, UnifiAccessSync::PROTOCOL, $console->address, $lease, (int) $console->site_id),
        ];
        $details['signature'] = base64_encode(sodium_crypto_sign_detached(self::canonical($details), $this->signingKey()));

        return $details;
    }

    /** @param array<string, mixed> $row */
    private function record(MonitoringCollector $hub, array $row): void
    {
        DB::transaction(function () use ($hub, $row): void {
            $job = AccessJob::query()->where('uuid', strtolower((string) $row['job']))->lockForUpdate()->first();
            if (! $job instanceof AccessJob || (int) $job->collector_id !== (int) $hub->id || ! in_array($job->state, ['sent', 'uncertain'], true)) {
                return;
            }
            $state = $row['state'] ?? null;
            $result = is_array($row['result'] ?? null) ? $row['result'] : [];
            $reason = is_string($row['reason'] ?? null) ? mb_substr(trim($row['reason']), 0, 191) : null;
            if ($state === 'succeeded') {
                $clean = $this->successResult($job->kind, $result);
                if ($clean === null) {
                    $this->finish($job, 'uncertain', null, 'The hub’s answer didn’t look right. Check UniFi Access before trying again.');

                    return;
                }
                $this->apply($job, $clean);
                $this->finish($job, 'succeeded', $clean, null);

                return;
            }
            $this->finish($job, $state === 'uncertain' ? 'uncertain' : 'failed', null, $reason ?? 'UniFi Access didn’t make the change.');
        });
    }

    /** @param array<string, mixed> $result @return array<string, mixed>|null */
    private function successResult(string $kind, array $result): ?array
    {
        return match ($kind) {
            'user_create' => UnifiAccessSync::id($result['user_id'] ?? null) !== null ? ['user_id' => $result['user_id']] : null,
            'user_status' => in_array($result['status'] ?? null, ['active', 'deactivated'], true) ? ['status' => $result['status']] : null,
            'user_policies' => is_array($result['policy_ids'] ?? null) && count($result['policy_ids']) <= 50
                ? ['policy_ids' => array_values(array_filter($result['policy_ids'], static fn (mixed $id): bool => UnifiAccessSync::id($id) !== null))] : null,
            'card_enrol' => is_string($result['token_hash'] ?? null) && preg_match('/\A[0-9a-f]{64}\z/', $result['token_hash']) === 1
                ? ['token_hash' => $result['token_hash'], 'display_id' => is_string($result['display_id'] ?? null) ? mb_substr($result['display_id'], 0, 32) : null] : null,
            'card_remove' => ($result['removed'] ?? null) === true ? ['removed' => true] : null,
            'visitor_create' => UnifiAccessSync::id($result['visitor_id'] ?? null) !== null ? ['visitor_id' => $result['visitor_id']] : null,
            'visitor_delete' => ($result['deleted'] ?? null) === true ? ['deleted' => true] : null,
            default => null,
        };
    }

    /**
     * Show the change straight away; the next read of the console confirms it.
     *
     * @param  array<string, mixed>  $result
     */
    private function apply(AccessJob $job, array $result): void
    {
        $params = (array) $job->params;
        $consoleId = (int) $job->console_id;
        $now = CarbonImmutable::now('UTC');
        $person = isset($params['user_id'])
            ? AccessPerson::query()->where('console_id', $consoleId)->where('external_id', $params['user_id'])->first()
            : null;
        match ($job->kind) {
            'user_create' => AccessPerson::query()->updateOrCreate(['console_id' => $consoleId, 'external_id' => $result['user_id']], [
                'first_name' => $params['first_name'],
                'last_name' => $params['last_name'] !== '' ? $params['last_name'] : null,
                'employee_number' => $params['employee_number'] !== '' ? $params['employee_number'] : null,
                'status' => 'active',
                'policy_ids' => $params['policy_ids'],
                'kind' => is_string($params['_kind'] ?? null) ? $params['_kind'] : 'unknown',
                'user_id' => is_int($params['_user_id'] ?? null) ? $params['_user_id'] : null,
                'client_id' => is_int($params['_client_id'] ?? null) ? $params['_client_id'] : null,
                'match_state' => is_int($params['_user_id'] ?? null) || is_int($params['_client_id'] ?? null) ? 'matched' : 'unmatched',
                'seen_at' => $now,
            ]),
            'user_status' => $person?->forceFill(['status' => $result['status']])->save(),
            'user_policies' => $person?->forceFill(['policy_ids' => $result['policy_ids']])->save(),
            'card_enrol' => $person !== null ? AccessCard::query()->updateOrCreate(['person_id' => $person->id, 'token_hash' => $result['token_hash']], [
                'display_id' => $result['display_id'], 'status' => 'active', 'seen_at' => $now,
            ]) : null,
            'card_remove' => $person !== null ? AccessCard::query()->where('person_id', $person->id)->where('token_hash', $params['token_hash'])->delete() : null,
            'visitor_create' => AccessVisitor::query()->updateOrCreate(['console_id' => $consoleId, 'external_id' => $result['visitor_id']], [
                'name' => trim($params['first_name'].' '.$params['last_name']),
                'status' => 'upcoming',
                'starts_at' => CarbonImmutable::createFromTimestampUTC($params['starts_at']),
                'ends_at' => CarbonImmutable::createFromTimestampUTC($params['ends_at']),
                'seen_at' => $now,
            ]),
            'visitor_delete' => AccessVisitor::query()->where('console_id', $consoleId)->where('external_id', $params['visitor_id'])->delete(),
            default => null,
        };
    }

    /** @param array<string, mixed>|null $result */
    private function finish(AccessJob $job, string $state, ?array $result, ?string $failure): void
    {
        $job->forceFill([
            'state' => $state,
            'result' => $result,
            'failure' => $failure !== null ? mb_substr($failure, 0, 191) : null,
            'completed_at' => CarbonImmutable::now('UTC'),
        ])->save();
        AuditLogger::logOrFail('security_devices.access.job_'.$state, $job, [
            'kind' => $job->kind,
            'console_id' => (int) $job->console_id,
            'failure' => $job->failure,
        ]);
    }

    private function signingKey(): string
    {
        $key = base64_decode((string) config('monitoring.collector.signing_secret_key'), true);
        if (! is_string($key) || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('The collector signing key is not set.');
        }

        return $key;
    }
}
