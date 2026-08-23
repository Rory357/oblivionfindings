<?php

namespace App\Domain\Clinical\Services;

use App\Domain\Clinical\Enums\ProtocolFrequency;
use App\Domain\Clinical\Models\ClinicalObservation;
use App\Domain\Clinical\Models\ClinicalProtocol;
use App\Domain\Clinical\Models\ClinicalProtocolSchedule;
use App\Domain\Clinical\Models\ClinicalProtocolScheduleMaterialization;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Shift;
use App\Models\ShiftTask;
use App\Models\Site;
use App\Models\User;
use BackedEnum;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use RuntimeException;

class ClinicalProtocolService
{
    public const AUTOMATIC_MATERIALIZATION_DAYS = 30;

    public const MAX_OCCURRENCES_PER_COMMAND = 5000;

    public function __construct(
        private readonly ClinicalSiteAccessService $siteAccess,
    ) {}

    /** @param array<string, mixed> $data */
    public function createProtocol(
        User $actor,
        array $data,
        string $idempotencyKey,
        ?CarbonInterface $effectiveAt = null,
        ?string $timezone = null,
    ): ClinicalProtocol {
        Gate::forUser($actor)->authorize('create', ClinicalProtocol::class);
        $this->assertIdempotencyKey($idempotencyKey);
        $timezone = $this->timezone($timezone);
        $clientId = (int) ($data['client_id'] ?? 0);
        $snapshot = $this->clientSnapshot($actor, $clientId);
        $fingerprint = $this->fingerprint([
            'action' => 'create',
            'actor_id' => (int) $actor->id,
            'data' => $data,
            'effective_at' => $this->explicitInstant($effectiveAt),
            'timezone' => $timezone,
        ]);

        return DB::transaction(function () use ($actor, $data, $idempotencyKey, $effectiveAt, $timezone, $snapshot, $fingerprint): ClinicalProtocol {
            $lockedActor = $this->lockActor($actor);
            Gate::forUser($lockedActor)->authorize('create', ClinicalProtocol::class);
            $client = $this->lockClientGraph($lockedActor, $snapshot);
            $command = $this->claimCommand(
                $lockedActor,
                'create',
                $idempotencyKey,
                $fingerprint,
            );

            if ($command->completed_at) {
                $protocol = ClinicalProtocol::query()
                    ->whereKey($command->clinical_protocol_id)
                    ->where('client_id', $client->id)
                    ->firstOrFail();
                Gate::forUser($lockedActor)->authorize('update', $protocol);

                return $protocol->fresh();
            }

            $protocol = ClinicalProtocol::create([
                ...$data,
                'client_id' => $client->id,
                'created_by' => $lockedActor->id,
                'schedule_version' => 1,
                'schedule_anchor_at' => null,
            ]);
            [$from, $to] = $this->automaticWindow($effectiveAt);
            $occurrences = $this->materializeLocked($protocol, $from, $to, $timezone);
            $this->completeCommand($command, $protocol, $from, $to, $timezone, $occurrences);

            return $protocol->fresh();
        }, attempts: 3);
    }

    /** @param array<string, mixed> $data */
    public function updateProtocol(
        User $actor,
        int $protocolId,
        array $data,
        string $idempotencyKey,
        ?CarbonInterface $effectiveAt = null,
        ?string $timezone = null,
    ): ClinicalProtocol {
        Gate::forUser($actor)->authorize('create', ClinicalProtocol::class);
        $this->assertIdempotencyKey($idempotencyKey);
        $timezone = $this->timezone($timezone);
        $snapshot = $this->protocolSnapshot($actor, $protocolId);
        $fingerprint = $this->fingerprint([
            'action' => 'update',
            'actor_id' => (int) $actor->id,
            'protocol_id' => $protocolId,
            'data' => $data,
            'effective_at' => $this->explicitInstant($effectiveAt),
            'timezone' => $timezone,
        ]);

        return DB::transaction(function () use ($actor, $protocolId, $data, $idempotencyKey, $effectiveAt, $timezone, $snapshot, $fingerprint): ClinicalProtocol {
            [$lockedActor, $protocol] = $this->lockProtocolGraph($actor, $protocolId, $snapshot);
            $command = $this->claimCommand(
                $lockedActor,
                'update',
                $idempotencyKey,
                $fingerprint,
                $protocol->id,
            );
            if ($command->completed_at) {
                return $protocol->fresh();
            }

            [$from, $to] = $this->automaticWindow($effectiveAt);
            $this->assertStructureCanChange($protocol, $data);
            unset($data['client_id'], $data['created_by'], $data['schedule_anchor_at'], $data['schedule_version']);
            $wasActive = (bool) $protocol->is_active;
            $protocol->fill($data);

            if (! $wasActive && $protocol->is_active) {
                $this->retirePendingOccurrences(
                    $protocol,
                    $from,
                    'Protocol schedule superseded by reactivation.',
                );
                $protocol->schedule_version = ((int) $protocol->schedule_version) + 1;
                $protocol->schedule_anchor_at = null;
            }

            $protocol->save();
            if ($wasActive && ! $protocol->is_active) {
                $this->retirePendingOccurrences($protocol, $from, 'Protocol deactivated.');
            } else {
                $this->retirePendingOutsideProtocolWindow($protocol, $from, $timezone);
            }

            $occurrences = $this->materializeLocked($protocol, $from, $to, $timezone);
            $this->completeCommand($command, $protocol, $from, $to, $timezone, $occurrences);

            return $protocol->fresh();
        }, attempts: 3);
    }

    public function setActive(
        User $actor,
        int $protocolId,
        bool $active,
        string $idempotencyKey,
        ?CarbonInterface $effectiveAt = null,
        ?string $timezone = null,
    ): ClinicalProtocol {
        Gate::forUser($actor)->authorize('create', ClinicalProtocol::class);
        $this->assertIdempotencyKey($idempotencyKey);
        $timezone = $this->timezone($timezone);
        $snapshot = $this->protocolSnapshot($actor, $protocolId);
        $fingerprint = $this->fingerprint([
            'action' => 'set_active',
            'actor_id' => (int) $actor->id,
            'protocol_id' => $protocolId,
            'is_active' => $active,
            'effective_at' => $this->explicitInstant($effectiveAt),
            'timezone' => $timezone,
        ]);

        return DB::transaction(function () use ($actor, $protocolId, $active, $idempotencyKey, $effectiveAt, $timezone, $snapshot, $fingerprint): ClinicalProtocol {
            [$lockedActor, $protocol] = $this->lockProtocolGraph($actor, $protocolId, $snapshot);
            $command = $this->claimCommand(
                $lockedActor,
                'set_active',
                $idempotencyKey,
                $fingerprint,
                $protocol->id,
            );
            if ($command->completed_at) {
                return $protocol->fresh();
            }

            [$from, $to] = $this->automaticWindow($effectiveAt);
            $wasActive = (bool) $protocol->is_active;
            if ($wasActive !== $active) {
                $protocol->is_active = $active;
                if ($active) {
                    $this->retirePendingOccurrences(
                        $protocol,
                        $from,
                        'Protocol schedule superseded by reactivation.',
                    );
                    $protocol->schedule_version = ((int) $protocol->schedule_version) + 1;
                    $protocol->schedule_anchor_at = null;
                }
                $protocol->save();
            }

            if (! $active) {
                $this->retirePendingOccurrences($protocol, $from, 'Protocol deactivated.');
            }

            $occurrences = $this->materializeLocked($protocol, $from, $to, $timezone);
            $this->completeCommand($command, $protocol, $from, $to, $timezone, $occurrences);

            return $protocol->fresh();
        }, attempts: 3);
    }

    /**
     * Explicitly reconcile one exact bounded window against the protocol's
     * canonical cadence anchor. Returned occurrences converge on replay.
     *
     * @return Collection<int, ClinicalProtocolSchedule>
     */
    public function reconcileSchedule(
        User $actor,
        int $protocolId,
        CarbonInterface $from,
        CarbonInterface $to,
        string $idempotencyKey,
        string $timezone = 'UTC',
    ): Collection {
        Gate::forUser($actor)->authorize('create', ClinicalProtocol::class);
        $this->assertIdempotencyKey($idempotencyKey);
        $timezone = $this->timezone($timezone);
        [$fromUtc, $toUtc] = $this->normaliseRequestedWindow($from, $to);
        $snapshot = $this->protocolSnapshot($actor, $protocolId);
        $fingerprint = $this->fingerprint([
            'action' => 'reconcile',
            'actor_id' => (int) $actor->id,
            'protocol_id' => $protocolId,
            'from' => $fromUtc->format('Y-m-d\TH:i:s\Z'),
            'to' => $toUtc->format('Y-m-d\TH:i:s\Z'),
            'timezone' => $timezone,
        ]);

        return DB::transaction(function () use ($actor, $protocolId, $fromUtc, $toUtc, $idempotencyKey, $timezone, $snapshot, $fingerprint): Collection {
            [$lockedActor, $protocol] = $this->lockProtocolGraph($actor, $protocolId, $snapshot);
            $command = $this->claimCommand(
                $lockedActor,
                'reconcile',
                $idempotencyKey,
                $fingerprint,
                $protocol->id,
            );
            if ($command->completed_at) {
                return $this->occurrencesForCommand($command);
            }

            $occurrences = $this->materializeLocked($protocol, $fromUtc, $toUtc, $timezone);
            $this->completeCommand($command, $protocol, $fromUtc, $toUtc, $timezone, $occurrences);

            return $occurrences;
        }, attempts: 3);
    }

    /**
     * Extend every active time-based protocol through one deterministic rolling
     * horizon. Each protocol is its own recoverable command so a failed sweep
     * can safely resume without repeating completed materialization work.
     *
     * @return array{protocol_count:int,occurrence_count:int}
     */
    public function reconcileScheduledHorizon(
        ?CarbonInterface $effectiveAt = null,
        ?string $timezone = null,
    ): array {
        $timezone = $this->timezone($timezone);
        $localDay = ($effectiveAt
            ? CarbonImmutable::instance($effectiveAt)
            : CarbonImmutable::now('UTC'))
            ->setTimezone($timezone)
            ->startOfDay();
        $windowStart = $localDay->utc()->startOfSecond();
        $windowEnd = $localDay
            ->addDays(self::AUTOMATIC_MATERIALIZATION_DAYS)
            ->endOfDay()
            ->utc()
            ->startOfSecond();

        $protocolIds = ClinicalProtocol::query()
            ->where('is_active', true)
            ->where('frequency', '!=', ProtocolFrequency::EveryShift->value)
            ->where(function ($query) use ($localDay): void {
                $query->whereNull('ends_at')
                    ->orWhereDate('ends_at', '>=', $localDay->toDateString());
            })
            ->where(function ($query) use ($localDay): void {
                $query->whereNull('starts_at')
                    ->orWhereDate(
                        'starts_at',
                        '<=',
                        $localDay->addDays(self::AUTOMATIC_MATERIALIZATION_DAYS)->toDateString(),
                    );
            })
            ->orderBy('id')
            ->pluck('id');

        $protocolCount = 0;
        $occurrenceCount = 0;
        foreach ($protocolIds as $protocolId) {
            $occurrences = DB::transaction(function () use ($protocolId, $windowStart, $windowEnd, $timezone): Collection {
                // This system path never locks user or Site rows. Its lock order
                // remains protocol -> command -> schedules, the shared suffix of
                // every actor-driven protocol mutation.
                $protocol = ClinicalProtocol::query()
                    ->whereKey((int) $protocolId)
                    ->lockForUpdate()
                    ->first();
                if (! $protocol
                    || ! $protocol->is_active
                    || $protocol->frequency === ProtocolFrequency::EveryShift) {
                    return collect();
                }

                $fingerprint = $this->fingerprint([
                    'action' => 'scheduled_reconcile',
                    'protocol_id' => (int) $protocol->id,
                    'schedule_version' => (int) $protocol->schedule_version,
                    'from' => $windowStart,
                    'to' => $windowEnd,
                    'timezone' => $timezone,
                ]);
                $idempotencyKey = Uuid::uuid5(Uuid::NAMESPACE_URL, $fingerprint)->toString();
                $command = $this->claimCommand(
                    null,
                    'scheduled_reconcile',
                    $idempotencyKey,
                    $fingerprint,
                    (int) $protocol->id,
                );
                if ($command->completed_at) {
                    return $this->occurrencesForCommand($command);
                }

                $occurrences = $this->materializeLocked(
                    $protocol,
                    $windowStart,
                    $windowEnd,
                    $timezone,
                );
                $this->completeCommand(
                    $command,
                    $protocol,
                    $windowStart,
                    $windowEnd,
                    $timezone,
                    $occurrences,
                );

                return $occurrences;
            }, attempts: 3);

            $protocolCount++;
            $occurrenceCount += $occurrences->count();
        }

        return [
            'protocol_count' => $protocolCount,
            'occurrence_count' => $occurrenceCount,
        ];
    }

    /**
     * Get observations due for a client (pending schedule items, including overdue).
     *
     * @return Collection<int, ClinicalProtocolSchedule>
     */
    public function getDueForClient(Client $client): Collection
    {
        return ClinicalProtocolSchedule::query()
            ->pending()
            ->whereHas('protocol', function ($q) use ($client) {
                $q->where('client_id', $client->id)->active();
            })
            ->with('protocol')
            ->orderBy('due_at')
            ->get();
    }

    /**
     * Get observations due for a specific shift.
     *
     * Includes:
     * - EveryShift protocols for the shift's client (always due)
     * - Time-based protocols with pending items due within the shift window
     *
     * @return Collection<int, array{protocol: ClinicalProtocol, schedule: ?ClinicalProtocolSchedule}>
     */
    public function getDueForShift(Shift $shift): Collection
    {
        if (! $shift->client_id) {
            return collect();
        }

        $protocols = ClinicalProtocol::query()
            ->forClient($shift->client_id)
            ->active()
            ->get();

        $due = collect();

        foreach ($protocols as $protocol) {
            if (! $protocol->isCurrentlyApplicable()) {
                continue;
            }

            if ($protocol->frequency === ProtocolFrequency::EveryShift) {
                // Check if already completed for this shift
                $alreadyDone = ClinicalObservation::query()
                    ->forClient($shift->client_id)
                    ->forShift($shift->id)
                    ->ofType($protocol->observation_type)
                    ->exists();

                if (! $alreadyDone) {
                    $due->push([
                        'protocol' => $protocol,
                        'schedule' => null, // EveryShift items don't use pre-generated schedules
                    ]);
                }

                continue;
            }

            // Time-based: find pending schedule items within shift window
            if ($shift->starts_at && $shift->ends_at) {
                $pendingItems = ClinicalProtocolSchedule::query()
                    ->where('clinical_protocol_id', $protocol->id)
                    ->pending()
                    ->whereBetween('due_at', [$shift->starts_at, $shift->ends_at])
                    ->get();

                foreach ($pendingItems as $item) {
                    $due->push([
                        'protocol' => $protocol,
                        'schedule' => $item,
                    ]);
                }
            }
        }

        return $due;
    }

    /**
     * Create ShiftTask records for protocol items due on a shift.
     *
     * Follows the FamilyNoteController dedup pattern:
     * - Check label existence before creating
     * - Use max(sort_order) + 1 for ordering
     *
     * @return Collection<int, ShiftTask>
     */
    public function generateShiftTasks(Shift $shift): Collection
    {
        $dueItems = $this->getDueForShift($shift);
        $created = collect();

        foreach ($dueItems as $item) {
            /** @var ClinicalProtocol $protocol */
            $protocol = $item['protocol'];
            /** @var ?ClinicalProtocolSchedule $schedule */
            $schedule = $item['schedule'];

            // Skip if schedule already has a linked task
            if ($schedule && $schedule->shift_task_id) {
                continue;
            }

            $label = $this->buildTaskLabel($protocol);

            // Dedup: don't create if identical task already exists on this shift
            if (ShiftTask::where('shift_id', $shift->id)->where('label', $label)->exists()) {
                continue;
            }

            $sortOrder = ((int) ShiftTask::where('shift_id', $shift->id)->max('sort_order')) + 1;

            $task = ShiftTask::create([
                'shift_id' => $shift->id,
                'label' => $label,
                'is_completed' => false,
                'sort_order' => $sortOrder,
            ]);

            // Link schedule item to the created task
            if ($schedule) {
                $schedule->updateQuietly(['shift_task_id' => $task->id]);
            }

            $created->push($task);
        }

        return $created;
    }

    /**
     * Get overdue schedule items for a client.
     *
     * @return Collection<int, ClinicalProtocolSchedule>
     */
    public function getOverdue(Client $client): Collection
    {
        return ClinicalProtocolSchedule::query()
            ->overdue()
            ->whereHas('protocol', function ($q) use ($client) {
                $q->where('client_id', $client->id)->active();
            })
            ->with('protocol')
            ->orderBy('due_at')
            ->get();
    }

    /**
     * Calculate compliance rate for a client's protocols.
     *
     * Returns percentage of schedule items completed vs total (completed + missed + pending-overdue).
     */
    public function getComplianceRate(Client $client, ?Carbon $from = null, ?Carbon $to = null): float
    {
        $from = $from ?? now()->subDays(30);
        $to = $to ?? now();

        $query = ClinicalProtocolSchedule::query()
            ->whereHas('protocol', function ($q) use ($client) {
                $q->where('client_id', $client->id);
            })
            ->whereIn('status', ['completed', 'missed', 'pending'])
            ->whereBetween('due_at', [$from, $to]);

        $total = (clone $query)->count();

        if ($total === 0) {
            return 0.0;
        }

        $completed = (clone $query)->where('status', 'completed')->count();

        return round(($completed / $total) * 100, 1);
    }

    /** @return array{client_id:int,site_id:int} */
    private function clientSnapshot(User $actor, int $clientId): array
    {
        $client = $this->siteAccess->applyClientScope(
            Client::query()->whereKey($clientId),
            $actor,
        )->firstOrFail(['id', 'site_id']);

        return [
            'client_id' => (int) $client->id,
            'site_id' => (int) $client->site_id,
        ];
    }

    /** @return array{client_id:int,site_id:int} */
    private function protocolSnapshot(User $actor, int $protocolId): array
    {
        $protocol = $this->siteAccess->applyProtocolScope(
            ClinicalProtocol::query()->whereKey($protocolId),
            $actor,
        )->with('client:id,site_id')->firstOrFail();
        Gate::forUser($actor)->authorize('update', $protocol);

        return [
            'client_id' => (int) $protocol->client_id,
            'site_id' => (int) $protocol->client->site_id,
        ];
    }

    private function lockActor(User $actor): User
    {
        $locked = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();

        // Access is derived from the current employment profile unless an
        // explicit global Site permission applies. Lock the profile before
        // Sites so revocation cannot race a clinical schedule mutation.
        HrEmployeeProfile::query()
            ->where('user_id', $locked->id)
            ->lockForUpdate()
            ->first();
        $locked->unsetRelation('hrEmployeeProfile');
        $locked->unsetRelation('roles');
        $locked->unsetRelation('permissionOverrides');

        return $locked;
    }

    /** @param array{client_id:int,site_id:int} $snapshot */
    private function lockClientGraph(User $actor, array $snapshot): Client
    {
        $this->siteAccess->applySiteScope(
            Site::query()->whereKey($snapshot['site_id']),
            $actor,
        )->lockForUpdate()->firstOrFail();

        return $this->siteAccess->applyClientScope(
            Client::query()
                ->whereKey($snapshot['client_id'])
                ->where('site_id', $snapshot['site_id']),
            $actor,
        )->lockForUpdate()->firstOrFail();
    }

    /**
     * Lock order for every existing aggregate mutation is:
     * User -> HR profile -> Site -> Client -> protocol -> command -> schedules.
     *
     * @param array{client_id:int,site_id:int} $snapshot
     * @return array{0:User,1:ClinicalProtocol}
     */
    private function lockProtocolGraph(
        User $actor,
        int $protocolId,
        array $snapshot,
    ): array {
        $lockedActor = $this->lockActor($actor);
        Gate::forUser($lockedActor)->authorize('create', ClinicalProtocol::class);
        $client = $this->lockClientGraph($lockedActor, $snapshot);
        $protocol = ClinicalProtocol::query()
            ->whereKey($protocolId)
            ->where('client_id', $client->id)
            ->lockForUpdate()
            ->firstOrFail();
        Gate::forUser($lockedActor)->authorize('update', $protocol);

        return [$lockedActor, $protocol];
    }

    private function claimCommand(
        ?User $actor,
        string $action,
        string $idempotencyKey,
        string $fingerprint,
        ?int $protocolId = null,
    ): ClinicalProtocolScheduleMaterialization {
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        $requesterId = $actor ? (int) $actor->id : null;
        ClinicalProtocolScheduleMaterialization::query()->insertOrIgnore([
            'idempotency_key' => $idempotencyKey,
            'action' => $action,
            'request_fingerprint' => $fingerprint,
            'requested_by' => $requesterId,
            'clinical_protocol_id' => $protocolId,
            'materialization_timezone' => 'UTC',
            'occurrence_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $command = ClinicalProtocolScheduleMaterialization::query()
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->firstOrFail();

        $protocolMismatch = $protocolId !== null
            && (int) $command->clinical_protocol_id !== $protocolId;
        $commandRequesterId = $command->requested_by === null
            ? null
            : (int) $command->requested_by;
        if ($commandRequesterId !== $requesterId
            || $command->action !== $action
            || ! hash_equals((string) $command->request_fingerprint, $fingerprint)
            || $protocolMismatch) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'That scheduling request key is already bound to a different command.',
            ]);
        }

        return $command;
    }

    /** @param Collection<int, ClinicalProtocolSchedule> $occurrences */
    private function completeCommand(
        ClinicalProtocolScheduleMaterialization $command,
        ClinicalProtocol $protocol,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $timezone,
        Collection $occurrences,
    ): void {
        $keys = $occurrences
            ->pluck('occurrence_key')
            ->map(fn ($key): string => (string) $key)
            ->values()
            ->all();

        $command->forceFill([
            'clinical_protocol_id' => $protocol->id,
            'schedule_version' => (int) $protocol->schedule_version,
            'window_start_at' => $from,
            'window_end_at' => $to,
            'materialization_timezone' => $timezone,
            'occurrence_keys' => $keys,
            'occurrence_count' => count($keys),
            'completed_at' => CarbonImmutable::now('UTC')->startOfSecond(),
        ])->save();
    }

    /** @return Collection<int, ClinicalProtocolSchedule> */
    private function occurrencesForCommand(
        ClinicalProtocolScheduleMaterialization $command,
    ): Collection {
        $keys = is_array($command->occurrence_keys) ? $command->occurrence_keys : [];
        if ($keys === []) {
            return collect();
        }

        return ClinicalProtocolSchedule::query()
            ->where('clinical_protocol_id', $command->clinical_protocol_id)
            ->whereIn('occurrence_key', $keys)
            ->orderBy('due_at')
            ->get();
    }

    /** @return Collection<int, ClinicalProtocolSchedule> */
    private function materializeLocked(
        ClinicalProtocol $protocol,
        CarbonImmutable $requestedFrom,
        CarbonImmutable $requestedTo,
        string $timezone,
    ): Collection {
        if (! $protocol->is_active || $protocol->frequency === ProtocolFrequency::EveryShift) {
            return collect();
        }

        $intervalHours = $protocol->effectiveIntervalHours();
        if (! $intervalHours || $intervalHours <= 0) {
            return collect();
        }

        $anchor = $this->ensureAnchor($protocol, $requestedFrom, $timezone);
        $window = $this->clipToProtocolWindow($protocol, $requestedFrom, $requestedTo, $timezone);
        if ($window === null) {
            return collect();
        }
        [$from, $to] = $window;
        $intervalSeconds = $intervalHours * 3600;
        $secondsFromAnchor = $from->getTimestamp() - $anchor->getTimestamp();
        $steps = $secondsFromAnchor <= 0
            ? 0
            : (int) ceil($secondsFromAnchor / $intervalSeconds);
        $cursor = $anchor->addSeconds($steps * $intervalSeconds);
        if ($cursor->lt($from)) {
            $cursor = $cursor->addSeconds($intervalSeconds);
        }
        if ($cursor->gt($to)) {
            return collect();
        }

        $count = intdiv($to->getTimestamp() - $cursor->getTimestamp(), $intervalSeconds) + 1;
        if ($count > self::MAX_OCCURRENCES_PER_COMMAND) {
            throw ValidationException::withMessages([
                'window' => 'The requested scheduling window is too large for this protocol frequency.',
            ]);
        }

        $occurrences = collect();
        while ($cursor->lte($to)) {
            $occurrences->push($this->persistOccurrence($protocol, $cursor));
            $cursor = $cursor->addSeconds($intervalSeconds);
        }

        return $occurrences;
    }

    protected function persistOccurrence(
        ClinicalProtocol $protocol,
        CarbonImmutable $dueAt,
    ): ClinicalProtocolSchedule {
        $dueAt = $dueAt->utc()->startOfSecond();
        $key = ClinicalProtocolSchedule::buildOccurrenceKey(
            (int) $protocol->id,
            (int) $protocol->schedule_version,
            $dueAt,
        );
        $occurrence = ClinicalProtocolSchedule::query()->firstOrCreate(
            ['occurrence_key' => $key],
            [
                'clinical_protocol_id' => $protocol->id,
                'schedule_version' => $protocol->schedule_version,
                'due_at' => $dueAt,
                'status' => 'pending',
            ],
        );

        if ((int) $occurrence->clinical_protocol_id !== (int) $protocol->id
            || (int) $occurrence->schedule_version !== (int) $protocol->schedule_version
            || ! $occurrence->due_at->utc()->startOfSecond()->equalTo($dueAt)) {
            throw new RuntimeException('Clinical protocol occurrence identity resolved to inconsistent schedule data.');
        }

        return $occurrence;
    }

    private function ensureAnchor(
        ClinicalProtocol $protocol,
        CarbonImmutable $requestedFrom,
        string $timezone,
    ): CarbonImmutable {
        if ($protocol->schedule_anchor_at) {
            return CarbonImmutable::instance($protocol->schedule_anchor_at)->utc()->startOfSecond();
        }

        $firstPersistedOccurrence = ClinicalProtocolSchedule::query()
            ->where('clinical_protocol_id', $protocol->id)
            ->min('due_at');
        $anchor = $firstPersistedOccurrence
            ? CarbonImmutable::parse((string) $firstPersistedOccurrence, 'UTC')->utc()->startOfSecond()
            : $requestedFrom->utc()->startOfSecond();
        if (! $firstPersistedOccurrence && $protocol->starts_at) {
            $start = CarbonImmutable::parse($protocol->starts_at->toDateString(), $timezone)
                ->startOfDay()
                ->utc();
            if ($start->gt($anchor)) {
                $anchor = $start;
            }
        }

        $protocol->forceFill(['schedule_anchor_at' => $anchor])->save();

        return $anchor;
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable}|null */
    private function clipToProtocolWindow(
        ClinicalProtocol $protocol,
        CarbonImmutable $requestedFrom,
        CarbonImmutable $requestedTo,
        string $timezone,
    ): ?array {
        $from = $requestedFrom->utc()->startOfSecond();
        $to = $requestedTo->utc()->startOfSecond();

        if ($protocol->starts_at) {
            $startsAt = CarbonImmutable::parse($protocol->starts_at->toDateString(), $timezone)
                ->startOfDay()
                ->utc();
            if ($startsAt->gt($from)) {
                $from = $startsAt;
            }
        }
        if ($protocol->ends_at) {
            $endsAt = CarbonImmutable::parse($protocol->ends_at->toDateString(), $timezone)
                ->endOfDay()
                ->utc()
                ->startOfSecond();
            if ($endsAt->lt($to)) {
                $to = $endsAt;
            }
        }

        return $from->gt($to) ? null : [$from, $to];
    }

    private function retirePendingOccurrences(
        ClinicalProtocol $protocol,
        CarbonImmutable $effectiveFrom,
        string $reason,
    ): void {
        ClinicalProtocolSchedule::query()
            ->where('clinical_protocol_id', $protocol->id)
            ->where('status', 'pending')
            ->where('due_at', '>=', $effectiveFrom)
            ->update([
                'status' => 'skipped',
                'skip_reason' => $reason,
                'updated_at' => CarbonImmutable::now('UTC'),
            ]);
    }

    private function retirePendingOutsideProtocolWindow(
        ClinicalProtocol $protocol,
        CarbonImmutable $effectiveFrom,
        string $timezone,
    ): void {
        if (! $protocol->starts_at && ! $protocol->ends_at) {
            return;
        }

        $startsAt = $protocol->starts_at
            ? CarbonImmutable::parse($protocol->starts_at->toDateString(), $timezone)->startOfDay()->utc()
            : null;
        $endsAt = $protocol->ends_at
            ? CarbonImmutable::parse($protocol->ends_at->toDateString(), $timezone)->endOfDay()->utc()->startOfSecond()
            : null;

        ClinicalProtocolSchedule::query()
            ->where('clinical_protocol_id', $protocol->id)
            ->where('status', 'pending')
            ->where('due_at', '>=', $effectiveFrom)
            ->where(function ($outside) use ($startsAt, $endsAt): void {
                if ($startsAt) {
                    $outside->where('due_at', '<', $startsAt);
                }
                if ($endsAt) {
                    $method = $startsAt ? 'orWhere' : 'where';
                    $outside->{$method}('due_at', '>', $endsAt);
                }
            })
            ->update([
                'status' => 'skipped',
                'skip_reason' => 'Protocol schedule window changed.',
                'updated_at' => CarbonImmutable::now('UTC'),
            ]);
    }

    /** @param array<string, mixed> $data */
    private function assertStructureCanChange(ClinicalProtocol $protocol, array $data): void
    {
        if (! $protocol->schedules()->exists()) {
            return;
        }

        $errors = [];
        foreach (['observation_type', 'frequency', 'custom_frequency_hours'] as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $current = $protocol->{$field};
            $current = $current instanceof BackedEnum ? $current->value : $current;
            $next = $data[$field] instanceof BackedEnum ? $data[$field]->value : $data[$field];
            if ((string) $current !== (string) $next) {
                $errors[$field] = 'Protocol scheduling structure cannot change after schedule history exists.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} */
    private function automaticWindow(?CarbonInterface $effectiveAt): array
    {
        $from = $effectiveAt
            ? CarbonImmutable::instance($effectiveAt)->utc()->startOfSecond()
            : CarbonImmutable::now('UTC')->startOfSecond();

        return [$from, $from->addDays(self::AUTOMATIC_MATERIALIZATION_DAYS)];
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} */
    private function normaliseRequestedWindow(
        CarbonInterface $from,
        CarbonInterface $to,
    ): array {
        $fromUtc = CarbonImmutable::instance($from)->utc()->startOfSecond();
        $toUtc = CarbonImmutable::instance($to)->utc()->startOfSecond();
        if ($fromUtc->gt($toUtc)) {
            throw ValidationException::withMessages([
                'window' => 'The schedule window end must be on or after its start.',
            ]);
        }

        return [$fromUtc, $toUtc];
    }

    private function timezone(?string $timezone): string
    {
        $timezone = trim((string) ($timezone ?: config('app.timezone', 'UTC')));
        try {
            return (new DateTimeZone($timezone))->getName();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'timezone' => 'A valid scheduling time zone is required.',
            ]);
        }
    }

    private function assertIdempotencyKey(string $idempotencyKey): void
    {
        if (! Str::isUuid($idempotencyKey)) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'A valid scheduling request key is required.',
            ]);
        }
    }

    private function explicitInstant(?CarbonInterface $instant): ?string
    {
        return $instant
            ? CarbonImmutable::instance($instant)->utc()->startOfSecond()->format('Y-m-d\TH:i:s\Z')
            : null;
    }

    /** @param array<string, mixed> $payload */
    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc()->startOfSecond()->format('Y-m-d\TH:i:s\Z');
        }
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }

    /**
     * Build task label for a protocol-generated shift task.
     */
    protected function buildTaskLabel(ClinicalProtocol $protocol): string
    {
        return '📋 ' . $protocol->observation_type->label() . ': ' . $protocol->name;
    }
}
