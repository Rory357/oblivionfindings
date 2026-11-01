<?php

namespace App\Domain\SecurityDevices\Access;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\CollectorCredentialLeaseSealer;
use App\Domain\SecurityDevices\Access\Models\AccessCard;
use App\Domain\SecurityDevices\Access\Models\AccessConsole;
use App\Domain\SecurityDevices\Access\Models\AccessDoor;
use App\Domain\SecurityDevices\Access\Models\AccessEvent;
use App\Domain\SecurityDevices\Access\Models\AccessPerson;
use App\Domain\SecurityDevices\Access\Models\AccessPolicy;
use App\Domain\SecurityDevices\Access\Models\AccessVisitor;
use App\Domain\SecurityDevices\Cctv\Services\CameraEndpointTrustService;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialRotationStatus;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Management\Services\HubJobTypes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * S&D Phase H: UniFi Access kept in step through each Site's hub (its API answers only on the
 * Site's own network). Each hub that runs it is given its consoles in its signed work, with the
 * API token sealed to it and the console's trusted certificate key. It sends a snapshot back
 * every few minutes: doors, people with their cards, visitors, policies and new door events.
 *
 * Nothing here changes anything in UniFi. Card tokens are never stored, only a hash and the
 * number printed on the card. People are matched to staff by employee number only as a
 * suggestion until a manager confirms.
 */
final class UnifiAccessSync
{
    public const string HUB_FEATURE = 'unifi_access_v1';

    public const string PROTOCOL = 'access.unifi';

    public const int INTERVAL_SECONDS = 300;

    public function __construct(
        private readonly CameraEndpointTrustService $trust,
        private readonly CollectorCredentialLeaseSealer $sealer,
        private readonly CredentialLeaseProvider $leases,
    ) {}

    /**
     * The `unifi_access` section of a hub's signed work, or null when it runs none.
     *
     * @return array{consoles: list<array<string, mixed>>}|null
     */
    public function hubSection(MonitoringCollector $hub): ?array
    {
        if (! HubJobTypes::runs($hub, self::HUB_FEATURE)) {
            return null;
        }
        $entries = [];
        AccessConsole::query()->whereIn('site_id', $hub->authorisedSiteIds())->orderBy('id')->limit(20)->get()
            ->each(function (AccessConsole $console) use ($hub, &$entries): void {
                try {
                    $reference = self::reference((int) $console->site_id);
                    $capabilities = $reference ? array_values(array_filter($reference->capabilities ?? [], 'is_string')) : [];
                    if (! $reference || $capabilities === [] || filter_var($console->address, FILTER_VALIDATE_IP) === false) {
                        return;
                    }
                    $lease = $this->leases->acquire((int) $console->site_id, $reference->reference_key, [$capabilities[0]]);
                    $pin = $this->trust->record((int) $console->site_id, $console->address, (int) $console->port)?->trusted_pin;
                    $entries[] = [
                        'console_id' => (string) $console->id,
                        'site_id' => (int) $console->site_id,
                        'address' => $console->address,
                        'port' => (int) $console->port,
                        'trusted_pin' => is_string($pin) && preg_match(CameraEndpointTrustService::PIN_PATTERN, $pin) === 1 ? $pin : null,
                        'interval_seconds' => self::INTERVAL_SECONDS,
                        'events_since' => ($console->events_until ?? CarbonImmutable::now('UTC')->subDay())->utc()->format(DATE_ATOM),
                        'credential_lease' => $this->sealer->seal($hub, 'access-console-'.$console->id, self::PROTOCOL, $console->address, $lease, (int) $console->site_id),
                    ];
                } catch (Throwable) {
                    // Left out this round; the next work list tries again.
                }
            });

        return $entries === [] ? null : ['consoles' => $entries];
    }

    /** A snapshot from the hub. @param array<string, mixed> $payload */
    public function ingest(MonitoringCollector $hub, array $payload): void
    {
        $console = AccessConsole::query()->whereKey((int) ($payload['console_id'] ?? 0))->first();
        if (! $console instanceof AccessConsole || ! in_array((int) $console->site_id, $hub->authorisedSiteIds(), true)) {
            return;
        }
        try {
            $syncedAt = CarbonImmutable::parse((string) ($payload['synced_at'] ?? 'now'))->utc();
        } catch (Throwable) {
            $syncedAt = CarbonImmutable::now('UTC');
        }
        $state = $payload['state'] ?? null;
        $certificate = $payload['certificate'] ?? null;
        if ($state === 'untrusted' && is_array($certificate)) {
            // The hub sent no token: the console's certificate isn't trusted (or has changed). Setup shows it to trust.
            $this->trust->recordOfferedFromHub((int) $console->site_id, $console->address, (int) $console->port, [
                'pin' => $certificate['pin'] ?? null,
                'fingerprint' => $certificate['fingerprint'] ?? null,
                'subject' => $certificate['subject'] ?? null,
                'valid_to' => $certificate['valid_to'] ?? null,
            ]);
            $console->forceFill(['state' => 'untrusted', 'last_error' => self::text($payload['reason'] ?? null, 191) ?? 'The console’s certificate isn’t trusted yet.'])->save();

            return;
        }
        if ($state !== 'ok') {
            $console->forceFill(['state' => 'failed', 'last_error' => mb_substr(is_string($payload['reason'] ?? null) ? $payload['reason'] : 'The hub couldn’t read UniFi Access.', 0, 191)])->save();

            return;
        }

        try {
            $this->store($console, $payload, $syncedAt);
        } catch (Throwable $exception) {
            // A snapshot that can't be stored never blocks the hub's other uploads; the next one tries again.
            report($exception);
            $console->forceFill(['state' => 'failed', 'last_error' => 'Oblivion couldn’t store what the hub read from UniFi Access.'])->save();
        }
    }

    /** @param array<string, mixed> $payload */
    private function store(AccessConsole $console, array $payload, CarbonImmutable $syncedAt): void
    {
        DB::transaction(function () use ($console, $payload, $syncedAt): void {
            $complete = ($payload['complete'] ?? false) === true;
            $doors = $this->doors($console, $payload['doors'] ?? [], $syncedAt);
            $people = $this->people($console, $payload['people'] ?? [], $syncedAt);
            $this->visitors($console, $payload['visitors'] ?? [], $syncedAt);
            $this->policies($console, $payload['policies'] ?? [], $syncedAt);
            $this->events($console, $payload['events'] ?? [], $doors, $people);
            if ($complete) {
                // Gone from UniFi: gone here (events keep their history).
                foreach ([AccessDoor::class, AccessPerson::class, AccessVisitor::class, AccessPolicy::class] as $model) {
                    $model::query()->where('console_id', $console->id)->where('seen_at', '<', $syncedAt)->delete();
                }
            }
            $until = null;
            try {
                $until = is_string($payload['events_until'] ?? null) ? CarbonImmutable::parse($payload['events_until'])->utc() : null;
            } catch (Throwable) {
                $until = null;
            }
            $console->forceFill([
                'state' => 'ok', 'last_error' => null, 'last_synced_at' => $syncedAt,
                'events_until' => $until !== null && ($console->events_until === null || $until->gt($console->events_until)) ? $until : $console->events_until,
            ])->save();
        });
    }

    /** Clear the names on door events older than 90 days (D14). */
    public function forgetOldNames(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now('UTC');

        return AccessEvent::query()->whereNotNull('actor_name')->where('occurred_at', '<', $now->subDays(90))->update(['actor_name' => null]);
    }

    public static function reference(int $siteId): ?CredentialReference
    {
        return CredentialReference::query()
            ->where('site_id', $siteId)
            ->where('provider', 'unifi_access')
            ->whereIn('purpose', ['device_management', 'monitoring'])
            ->where('status', CredentialReferenceStatus::Active->value)
            ->whereNotIn('rotation_status', [CredentialRotationStatus::Overdue->value, CredentialRotationStatus::Failed->value])
            ->orderByDesc('id')
            ->first();
    }

    /** @return array<string, int> external id => door id */
    private function doors(AccessConsole $console, mixed $rows, CarbonImmutable $at): array
    {
        $ids = [];
        foreach (array_slice(is_array($rows) ? $rows : [], 0, 500) as $row) {
            $external = self::id($row['id'] ?? null);
            if ($external === null) {
                continue;
            }
            $door = AccessDoor::query()->updateOrCreate(['console_id' => $console->id, 'external_id' => $external], [
                'name' => self::text($row['name'] ?? null, 191) ?? 'Door',
                'lock_state' => in_array($row['lock'] ?? null, ['locked', 'unlocked'], true) ? $row['lock'] : null,
                'door_state' => in_array($row['position'] ?? null, ['open', 'closed'], true) ? $row['position'] : null,
                'hub_bound' => ($row['hub_bound'] ?? false) === true,
                'seen_at' => $at,
            ]);
            $ids[$external] = (int) $door->id;
        }

        return $ids;
    }

    /** @return array<string, int> external id => person id */
    private function people(AccessConsole $console, mixed $rows, CarbonImmutable $at): array
    {
        $ids = [];
        foreach (array_slice(is_array($rows) ? $rows : [], 0, 5000) as $row) {
            $external = self::id($row['id'] ?? null);
            if ($external === null) {
                continue;
            }
            $employee = self::text($row['employee_number'] ?? null, 64);
            $person = AccessPerson::query()->firstOrNew(['console_id' => $console->id, 'external_id' => $external]);
            $person->fill([
                'first_name' => self::text($row['first_name'] ?? null, 120),
                'last_name' => self::text($row['last_name'] ?? null, 120),
                'employee_number' => $employee,
                'status' => in_array($row['status'] ?? null, ['active', 'pending', 'deactivated'], true) ? $row['status'] : 'active',
                'policy_ids' => array_values(array_filter(array_map([self::class, 'id'], is_array($row['policy_ids'] ?? null) ? $row['policy_ids'] : []))),
                'seen_at' => $at,
            ]);
            // A suggestion only: a manager confirms the match (pop-up 90) before anything is sent to UniFi.
            if ($person->match_state !== 'matched' && $employee !== null) {
                $userId = $this->staffByEmployeeNumber($employee, (int) $console->site_id);
                $person->fill($userId !== null
                    ? ['user_id' => $userId, 'match_state' => 'suggested', 'kind' => 'staff']
                    : ['user_id' => null, 'match_state' => 'unmatched']);
            }
            $person->save();
            $ids[$external] = (int) $person->id;
            $this->cards($person, $row['cards'] ?? [], $at);
        }

        return $ids;
    }

    private function cards(AccessPerson $person, mixed $rows, CarbonImmutable $at): void
    {
        $seen = [];
        foreach (array_slice(is_array($rows) ? $rows : [], 0, 20) as $row) {
            $hash = is_string($row['token_hash'] ?? null) && preg_match('/\A[0-9a-f]{64}\z/', $row['token_hash']) === 1 ? $row['token_hash'] : null;
            if ($hash === null) {
                continue;
            }
            AccessCard::query()->updateOrCreate(['person_id' => $person->id, 'token_hash' => $hash], [
                'display_id' => self::text($row['display_id'] ?? null, 32),
                'status' => 'active',
                'seen_at' => $at,
            ]);
            $seen[] = $hash;
        }
        AccessCard::query()->where('person_id', $person->id)->whereNotIn('token_hash', $seen === [] ? [''] : $seen)->delete();
    }

    private function visitors(AccessConsole $console, mixed $rows, CarbonImmutable $at): void
    {
        foreach (array_slice(is_array($rows) ? $rows : [], 0, 1000) as $row) {
            $external = self::id($row['id'] ?? null);
            if ($external === null) {
                continue;
            }
            AccessVisitor::query()->updateOrCreate(['console_id' => $console->id, 'external_id' => $external], [
                'name' => self::text($row['name'] ?? null, 191) ?? 'Visitor',
                'status' => self::text($row['status'] ?? null, 24),
                'starts_at' => self::time($row['starts_at'] ?? null),
                'ends_at' => self::time($row['ends_at'] ?? null),
                'seen_at' => $at,
            ]);
        }
    }

    private function policies(AccessConsole $console, mixed $rows, CarbonImmutable $at): void
    {
        foreach (array_slice(is_array($rows) ? $rows : [], 0, 500) as $row) {
            $external = self::id($row['id'] ?? null);
            if ($external === null) {
                continue;
            }
            AccessPolicy::query()->updateOrCreate(['console_id' => $console->id, 'external_id' => $external], [
                'name' => self::text($row['name'] ?? null, 191) ?? 'Policy',
                'door_ids' => array_values(array_filter(array_map([self::class, 'id'], is_array($row['door_ids'] ?? null) ? $row['door_ids'] : []))),
                'schedule' => is_array($row['schedule'] ?? null) ? array_slice($row['schedule'], 0, 50) : null,
                'seen_at' => $at,
            ]);
        }
    }

    /**
     * @param  array<string, int>  $doors
     * @param  array<string, int>  $people
     */
    private function events(AccessConsole $console, mixed $rows, array $doors, array $people): void
    {
        foreach (array_slice(is_array($rows) ? $rows : [], 0, 2000) as $row) {
            $external = self::id($row['id'] ?? null);
            $at = self::time($row['occurred_at'] ?? null);
            if ($external === null || $at === null) {
                continue;
            }
            $doorExternal = self::id($row['door_id'] ?? null);
            $personExternal = self::id($row['person_id'] ?? null);
            AccessEvent::query()->insertOrIgnore([
                'console_id' => $console->id,
                'event_key' => hash('sha256', $external),
                'door_id' => $doorExternal !== null ? ($doors[$doorExternal] ?? AccessDoor::query()->where('console_id', $console->id)->where('external_id', $doorExternal)->value('id')) : null,
                'person_id' => $personExternal !== null ? ($people[$personExternal] ?? AccessPerson::query()->where('console_id', $console->id)->where('external_id', $personExternal)->value('id')) : null,
                'kind' => in_array($row['kind'] ?? null, ['opened', 'denied', 'forced', 'held_open', 'unlocked'], true) ? $row['kind'] : 'other',
                'result' => self::text($row['result'] ?? null, 24),
                'method' => in_array($row['method'] ?? null, ['card', 'pin', 'app', 'face', 'fingerprint', 'button', 'remote'], true) ? $row['method'] : 'other',
                'actor_name' => self::text($row['actor_name'] ?? null, 191),
                'occurred_at' => $at->format('Y-m-d H:i:s'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function staffByEmployeeNumber(string $employeeNumber, int $siteId): ?int
    {
        $profile = HrEmployeeProfile::query()
            ->where('employee_number', $employeeNumber)
            ->where('is_active', true)
            ->where(fn ($query) => $query->where('primary_site_id', $siteId)->orWhereJsonContains('secondary_site_ids', $siteId))
            ->first(['user_id']);

        return $profile?->user_id !== null ? (int) $profile->user_id : null;
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

    private static function time(mixed $value): ?CarbonImmutable
    {
        try {
            return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->utc() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
