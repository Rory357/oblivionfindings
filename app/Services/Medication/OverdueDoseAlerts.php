<?php

namespace App\Services\Medication;

use App\Models\Client;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoomAlert;
use App\Models\MedicationDashboardAlert;
use App\Services\ControlRoom\ControlRoomAlertLifecycleService;
use App\Services\ControlRoom\SignalProcessingService;
use App\Services\Medication\DoseSlots\OverdueDoses;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The Control Room overdue-dose alert and its dashboard copy, from the doses
 * OverdueDoses finds (P01 foundation C6f) — the same overdue doses Meds
 * today, My Day and the badge show.
 *
 * Spells. Each overdue spell of a dose is one signal, keyed by the dose and
 * the spell, so a dose raises one alert per spell however often the job
 * runs; a person's doses that fall overdue together (a round) group into one
 * alert. A spell ends — is released — once the dose is settled (recorded,
 * no longer owed) and its alert is no longer open; if the dose then falls
 * overdue again (its record deleted or corrected away), that is a new spell
 * and one new alert. A spell whose signal was suppressed (a maintenance
 * window) or failed doesn't block the dose: once the window has ended, the
 * next run starts a new spell.
 *
 * Resolving. An alert resolves itself only on positive evidence that every
 * one of its doses is settled (OverdueDoses::settled) — never because a dose
 * has aged out of the lookback. The alert is locked and its doses re-read
 * first, so a dose grouped into it meanwhile keeps it open. This runs
 * straight after a dose's record or order changes commit (once per person
 * per transaction), and on every run of the overdue job.
 */
final class OverdueDoseAlerts
{
    public const RESOLUTION_CODE = 'medication_workflow';

    public const RESOLUTION_SOURCE = 'dose_slot_projection';

    /** The dashboard row's resolution note when no dose is overdue any more. */
    public const AUTO_RESOLVED_NOTE = 'No dose is overdue any more (resolved automatically).';

    /**
     * People with a reconcile queued, per root transaction. Weak, so the
     * entry goes with the transaction whether it commits or rolls back.
     *
     * @var \WeakMap<object, array<int, true>>|null
     */
    private static ?\WeakMap $queued = null;

    public function __construct(
        private readonly OverdueDoses $overdue,
        private readonly MedicationSignalService $signals,
        private readonly ControlRoomAlertLifecycleService $lifecycle,
        private readonly SignalProcessingService $processor,
    ) {}

    /**
     * Once the current transaction commits (now, outside one): reconcile the
     * person's overdue alerts — once per person however many of their doses
     * the transaction touched.
     */
    public static function queueAfterCommit(int $clientId): void
    {
        if ($clientId <= 0) {
            return;
        }

        $root = app('db.transactions')->callbackApplicableTransactions()->first();
        if ($root === null) {
            app(self::class)->afterDoseChange($clientId);

            return;
        }

        self::$queued ??= new \WeakMap;
        $queued = self::$queued[$root] ?? [];
        if (isset($queued[$clientId])) {
            return;
        }
        $queued[$clientId] = true;
        self::$queued[$root] = $queued;

        // Discarded with the transaction if it rolls back.
        DB::afterCommit(static fn () => app(self::class)->afterDoseChange($clientId));
    }

    /**
     * The overdue job's pass over everyone: raise what's newly overdue
     * (re-checked per person just before raising), and resolve what is
     * settled.
     *
     * @return Collection<int, array<string, mixed>> the doses raised, each with its spell's key (for the job's own notifications)
     */
    public function sweep(Carbon $now): Collection
    {
        $overdue = collect();
        $raised = collect();
        foreach ($this->overdue->at($now)->pluck('client_id')->unique() as $clientId) {
            $doses = $this->overdue->at($now, [(int) $clientId]);
            $overdue = $overdue->concat($doses);
            if ($doses->isNotEmpty()) {
                $raised = $raised->concat($this->raise((int) $clientId, $doses, $now)['doses']);
            }
        }
        $this->reconcile(null, $now, $overdue);

        return $raised->values();
    }

    /**
     * One person's pass (their alerts refresh, a recording on the profile or
     * the API): the dashboard row for their overdue doses, or null.
     *
     * @return array<string, mixed>|null
     */
    public function syncClient(Client $client, Carbon $now): ?array
    {
        $overdue = $this->overdue->at($now, [(int) $client->id]);
        $dashboard = $overdue->isEmpty() ? null : $this->raise((int) $client->id, $overdue, $now)['dashboard'];
        $this->reconcile([(int) $client->id], $now, $overdue);

        return $dashboard?->toArray();
    }

    /**
     * After a dose's record or its order changes (once committed): resolve
     * the person's settled alerts, release ended spells, and bring their
     * dashboard row up to date. Cheap when they have no open spell or alert
     * (a dashboard row of theirs comes with one; the job reconciles others).
     */
    public function afterDoseChange(int $clientId): void
    {
        try {
            if (! $this->unreleasedSignals([$clientId])->exists() && $this->openAlerts([$clientId])->isEmpty()) {
                return;
            }
            $this->reconcile([$clientId], now());
        } catch (\Throwable $e) {
            // Never let alert housekeeping fail a recording; the job retries.
            report($e);
        }
    }

    /**
     * "08:00", "08:00 yesterday" or "08:00 Thu 1 Oct": the due time, with
     * its day when it isn't today (NZ).
     */
    public static function dueLabel(Carbon $dueAt, Carbon $now): string
    {
        $timezone = (string) config('app.worker_timezone', 'Pacific/Auckland');
        $due = $dueAt->copy()->timezone($timezone);
        $today = $now->copy()->timezone($timezone)->startOfDay();

        return match (true) {
            $due->isSameDay($today) => $due->format('H:i'),
            $due->isSameDay($today->copy()->subDay()) => $due->format('H:i').' yesterday',
            default => $due->format('H:i D j M'),
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $doses  one person's overdue doses
     * @return array{dashboard: MedicationDashboardAlert|null, doses: Collection<int, array<string, mixed>>}
     */
    private function raise(int $clientId, Collection $doses, Carbon $now): array
    {
        $spells = $this->currentSpells($clientId, $doses->pluck('key')->all());

        $raised = $doses->map(function (array $dose) use ($clientId, $spells, $now): array {
            $spell = $spells[$dose['key']] ?? ['spell' => 1, 'emit' => true, 'since' => null];
            $spellKey = $dose['key'].'~'.$spell['spell'];
            if ($spell['emit']) {
                // A controlled medicine is never named here (EM-12); the
                // marker lets readers without controlled access see the
                // ordinary ones (sticky once an alert holds a controlled one).
                $name = $dose['order']->unrestrictedName();
                $this->signals->emit(
                    MedicationSignalService::TYPE_OVERDUE,
                    $clientId,
                    'high',
                    sprintf('Overdue dose: %s, due %s', $name, self::dueLabel($dose['due_at'], $now)),
                    [
                        'site_id' => $dose['site_id'],
                        'client_medication_id' => $dose['order']->id,
                        'controlled_drug' => (bool) $dose['order']->controlled_drug,
                        'dose_key' => $dose['key'],
                        'dose_spell' => $spell['spell'],
                        'dose_spell_key' => $spellKey,
                        'due_at' => $dose['due_at']->toIso8601String(),
                        'window_ends_at' => $dose['window_ends_at']->toIso8601String(),
                        'overdue_count' => 1,
                        'medication_names' => [$name],
                    ],
                );
            }

            return [...$dose, 'spell_key' => $spellKey, 'spell_since' => $spell['since']];
        });

        return ['dashboard' => $this->dashboardRow($clientId, $raised), 'doses' => $raised];
    }

    /**
     * Each dose's current overdue spell, whether it still needs a signal,
     * and since when it has had one.
     *
     * @param  list<string>  $keys
     * @return array<string, array{spell: int, emit: bool, since: Carbon|null}>
     */
    private function currentSpells(int $clientId, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        return Signal::query()
            ->where('signal_type_code', MedicationSignalService::TYPE_OVERDUE)
            ->where('client_id', $clientId)
            ->whereIn(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(normalized_data, '$.dose_key'))"), $keys)
            ->get()
            ->groupBy(fn (Signal $signal): string => (string) data_get($signal->normalized_data, 'dose_key'))
            ->map(function (Collection $signals): array {
                /** @var Signal $latest */
                $latest = $signals->sortByDesc(fn (Signal $signal): int => (int) data_get($signal->normalized_data, 'dose_spell', 1))->first();
                $spell = (int) data_get($latest->normalized_data, 'dose_spell', 1);
                $next = ['spell' => $spell + 1, 'emit' => true, 'since' => null];

                if (data_get($latest->normalized_data, 'dose_released_at') !== null) {
                    return $next;
                }

                return match (true) {
                    // Not yet processed: emitting again retries it.
                    $latest->status === 'pending' => ['spell' => $spell, 'emit' => true, 'since' => $latest->created_at],
                    // Suppressed: wait out the window, then a new spell.
                    $latest->status === 'suppressed' => $this->processor->isInMaintenanceWindow($latest)
                        ? ['spell' => $spell, 'emit' => false, 'since' => $latest->created_at]
                        : $next,
                    // Alerted (an alert of its own, or grouped into one).
                    ($latest->alert_id ?? $latest->correlated_alert_id) !== null => ['spell' => $spell, 'emit' => false, 'since' => $latest->created_at],
                    // Failed, or processed without an alert.
                    default => $next,
                };
            })
            ->all();
    }

    /**
     * The person's dashboard row: kept current while active; not brought
     * back while a person has acknowledged, dismissed or resolved it and no
     * dose has fallen overdue since; else a new row.
     *
     * @param  Collection<int, array<string, mixed>>  $doses
     */
    private function dashboardRow(int $clientId, Collection $doses): ?MedicationDashboardAlert
    {
        $message = $this->dashboardMessage($doses);
        $latest = MedicationDashboardAlert::query()
            ->where('client_id', $clientId)
            ->where('alert_type', 'overdue')
            ->whereNull('client_medication_id')
            ->latest('id')
            ->first();

        if ($latest?->status === 'active') {
            $latest->forceFill(['severity' => 'critical', 'message' => $message])->save();

            return $latest;
        }

        $handledAt = match (true) {
            $latest === null => null,
            $latest->status === 'acknowledged' => $latest->acknowledged_at ?? $latest->updated_at,
            $latest->status === 'resolved' && $latest->resolution_notes === self::AUTO_RESOLVED_NOTE => null,
            default => $latest->resolved_at ?? $latest->updated_at,
        };
        $nothingNew = $handledAt !== null && $doses->every(
            fn (array $dose): bool => $dose['spell_since'] !== null && $dose['spell_since']->lessThanOrEqualTo($handledAt),
        );
        if ($nothingNew) {
            if ($latest->status === 'acknowledged') {
                $latest->forceFill(['message' => $message])->save();
            }

            return $latest;
        }

        return MedicationDashboardAlert::query()->create([
            'client_id' => $clientId,
            'client_medication_id' => null,
            'alert_type' => 'overdue',
            'severity' => 'critical',
            'message' => $message,
            'status' => 'active',
        ]);
    }

    /**
     * Resolve the Control Room overdue alerts whose doses are all settled,
     * release ended spells, and bring the dashboard rows up to date.
     *
     * @param  list<int>|null  $clientIds  null: everyone
     * @param  Collection<int, array<string, mixed>>|null  $overdue  the lookback's overdue doses for $clientIds, when already known
     */
    private function reconcile(?array $clientIds, Carbon $now, ?Collection $overdue = null): void
    {
        $overdue ??= $this->overdue->at($now, $clientIds);
        $peopleOverdue = $overdue->pluck('client_id')->flip();

        foreach ($this->openAlerts($clientIds) as $alert) {
            $this->resolveIfSettled((int) $alert->id, $peopleOverdue, $now);
        }

        $this->releaseEndedSpells($clientIds, $now);

        // The dashboard copy (the lookback's overdue doses): resolved for
        // people with none, the count kept current for the rest.
        $this->openDashboardRows($clientIds)
            ->when($peopleOverdue->isNotEmpty(), fn ($rows) => $rows->whereNotIn('client_id', $peopleOverdue->keys()->all()))
            ->update(['status' => 'resolved', 'resolved_at' => now(), 'resolution_notes' => self::AUTO_RESOLVED_NOTE]);
        foreach ($overdue->groupBy('client_id') as $clientId => $doses) {
            $this->openDashboardRows([(int) $clientId])->update(['message' => $this->dashboardMessage($doses)]);
        }
    }

    /**
     * Lock the alert, re-read its doses and resolve it only if every one is
     * settled — a dose grouped into it meanwhile keeps it open.
     *
     * @param  Collection<int, int>  $peopleOverdue  people with a dose overdue in the lookback (for alerts from before C6f, which name no dose)
     */
    private function resolveIfSettled(int $alertId, Collection $peopleOverdue, Carbon $now): void
    {
        try {
            DB::transaction(function () use ($alertId, $peopleOverdue, $now): void {
                $alert = ControlRoomAlert::query()->whereKey($alertId)->lockForUpdate()->first();
                if ($alert === null || ! $alert->isActionable()) {
                    return;
                }

                $doseKeys = $this->doseKeysOf($alert);
                $settled = $doseKeys === []
                    ? ! $peopleOverdue->has((int) $alert->client_id)
                    : count($this->overdue->settled($doseKeys, $now)) === count($doseKeys);
                if (! $settled) {
                    return;
                }

                $this->lifecycle->resolveAutomatically(
                    $alert,
                    'The overdue dose has been recorded, or is no longer owed.',
                    self::RESOLUTION_CODE,
                    self::RESOLUTION_SOURCE,
                    ['dose_keys' => $doseKeys],
                );
            });
        } catch (\InvalidArgumentException $e) {
            // An operator's open task keeps it open (the resolve gate).
            Log::warning('OverdueDoseAlerts: alert resolution was gated', [
                'alert_id' => $alertId,
                'reason' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Release the spells that have ended: the dose is settled and its alert
     * is no longer open (resolved here, or by a person). A spell whose alert
     * is still open — another of its doses is overdue — stays, so the dose
     * falling overdue again rejoins that alert.
     *
     * @param  list<int>|null  $clientIds
     */
    private function releaseEndedSpells(?array $clientIds, Carbon $now): void
    {
        $unreleased = $this->unreleasedSignals($clientIds)->get();
        if ($unreleased->isEmpty()) {
            return;
        }

        $settled = collect($this->overdue->settled(
            $unreleased->map(fn (Signal $signal): string => (string) data_get($signal->normalized_data, 'dose_key'))->unique()->values()->all(),
            $now,
        ))->flip();
        $openAlertIds = ControlRoomAlert::query()
            ->whereKey($unreleased->map(fn (Signal $signal) => $signal->alert_id ?? $signal->correlated_alert_id)->filter()->unique()->values()->all())
            ->unresolved()
            ->pluck('id')
            ->flip();

        foreach ($unreleased as $signal) {
            $alertId = $signal->alert_id ?? $signal->correlated_alert_id;
            if ($alertId === null
                || $openAlertIds->has($alertId)
                || ! $settled->has((string) data_get($signal->normalized_data, 'dose_key'))) {
                continue;
            }

            $signal->forceFill([
                'normalized_data' => array_replace((array) $signal->normalized_data, ['dose_released_at' => now()->toIso8601String()]),
            ])->saveQuietly();
        }
    }

    /** @param Collection<int, array<string, mixed>> $doses */
    private function dashboardMessage(Collection $doses): string
    {
        // A controlled medicine is never named here (EM-12).
        $names = $doses->map(fn (array $dose): string => $dose['order']->unrestrictedName())->unique()->values();

        return $doses->count().' overdue dose(s): '.$names->implode(', ');
    }

    /**
     * @param  list<int>|null  $clientIds
     * @return Builder<MedicationDashboardAlert>
     */
    private function openDashboardRows(?array $clientIds): Builder
    {
        return MedicationDashboardAlert::query()
            ->where('alert_type', 'overdue')
            ->whereNull('client_medication_id')
            ->whereIn('status', ['active', 'acknowledged'])
            ->when($clientIds !== null, fn ($rows) => $rows->whereIn('client_id', $clientIds));
    }

    /**
     * @param  list<int>|null  $clientIds
     * @return Collection<int, ControlRoomAlert>
     */
    private function openAlerts(?array $clientIds): Collection
    {
        return ControlRoomAlert::query()
            ->unresolved()
            ->where('source', 'medication')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(context, '$.signal_type_code')) = ?", [MedicationSignalService::TYPE_OVERDUE])
            ->when($clientIds !== null, fn ($alerts) => $alerts->whereIn('client_id', $clientIds))
            ->get(['id', 'client_id']);
    }

    /**
     * Overdue spells not yet released.
     *
     * @param  list<int>|null  $clientIds
     * @return Builder<Signal>
     */
    private function unreleasedSignals(?array $clientIds): Builder
    {
        return Signal::query()
            ->where('signal_type_code', MedicationSignalService::TYPE_OVERDUE)
            ->when($clientIds !== null, fn ($signals) => $signals->whereIn('client_id', $clientIds))
            ->whereRaw("JSON_EXTRACT(normalized_data, '$.dose_key') IS NOT NULL")
            ->whereRaw("JSON_EXTRACT(normalized_data, '$.dose_released_at') IS NULL");
    }

    /**
     * The doses an alert stands for: every overdue signal grouped into it.
     *
     * @return list<string>
     */
    private function doseKeysOf(ControlRoomAlert $alert): array
    {
        return Signal::query()
            ->where('signal_type_code', MedicationSignalService::TYPE_OVERDUE)
            ->where(fn ($signals) => $signals->where('alert_id', $alert->id)->orWhere('correlated_alert_id', $alert->id))
            ->get(['normalized_data'])
            ->map(fn (Signal $signal): ?string => data_get($signal->normalized_data, 'dose_key'))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
