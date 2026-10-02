<?php

namespace App\Services\Medication;

use App\Models\Client;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoomAlert;
use App\Models\MedicationDashboardAlert;
use App\Services\ControlRoom\ControlRoomAlertLifecycleService;
use App\Services\Medication\DoseSlots\OverdueDoses;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The Control Room overdue-dose alert and its dashboard copy, from the doses
 * OverdueDoses finds (P01 foundation C6f) — the same overdue doses Meds
 * today, My Day and the badge show.
 *
 * Each overdue spell of a dose is one signal, keyed by the dose and the
 * spell, so a dose raises one alert per spell however often the job runs; a
 * person's doses that fall overdue together (a round) group into one alert.
 * An alert resolves itself once none of its doses is overdue any more — the
 * dose was recorded, its order changed so it's no longer owed, or alerts
 * were suppressed for the person — straight after the change commits, and
 * on every run of the overdue job. A dose whose alert has resolved and that
 * is no longer overdue is released: if it falls overdue again (its record is
 * deleted or corrected away), that is a new spell and one new alert.
 */
final class OverdueDoseAlerts
{
    public const RESOLUTION_CODE = 'medication_workflow';

    public const RESOLUTION_SOURCE = 'dose_slot_projection';

    public function __construct(
        private readonly OverdueDoses $overdue,
        private readonly MedicationSignalService $signals,
        private readonly ControlRoomAlertLifecycleService $lifecycle,
    ) {}

    /**
     * The overdue job's pass over everyone: raise what's newly overdue, and
     * resolve what no longer is.
     *
     * @return Collection<int, array<string, mixed>> the overdue doses, each with its spell's key (for the job's own notifications)
     */
    public function sweep(Carbon $now): Collection
    {
        $overdue = $this->overdue->at($now);
        $raised = collect();
        foreach ($overdue->groupBy('client_id') as $clientId => $doses) {
            $raised = $raised->concat($this->raise((int) $clientId, $doses)['doses']);
        }
        $this->reconcile($overdue, null, $now);

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
        $dashboard = $overdue->isEmpty() ? null : $this->raise((int) $client->id, $overdue)['dashboard'];
        $this->reconcile($overdue, [(int) $client->id], $now);

        return $dashboard?->toArray();
    }

    /**
     * After a dose's record or its order changes (once committed): resolve
     * the person's overdue alerts whose doses are no longer overdue, release
     * those doses, and refresh their dashboard row. Cheap when they have no
     * unreleased overdue dose; the job reconciles anything else.
     */
    public function afterDoseChange(int $clientId): void
    {
        try {
            if (! $this->unreleasedSignals([$clientId])->exists() && $this->openAlerts([$clientId])->isEmpty()) {
                return;
            }
            $this->reconcile($this->overdue->at(now(), [$clientId]), [$clientId], now());
        } catch (\Throwable $e) {
            // Never let alert housekeeping fail a recording; the job retries.
            report($e);
        }
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $doses  one person's overdue doses
     * @return array{dashboard: MedicationDashboardAlert, doses: Collection<int, array<string, mixed>>}
     */
    private function raise(int $clientId, Collection $doses): array
    {
        $dashboard = MedicationDashboardAlert::createOrUpdateAlert($clientId, 'overdue', 'critical', $this->dashboardMessage($doses), null);
        $spells = $this->currentSpells($clientId, $doses->pluck('key')->all());

        $raised = $doses->map(function (array $dose) use ($clientId, $spells): array {
            $spell = $spells[$dose['key']] ?? 1;
            $spellKey = $dose['key'].'~'.$spell;
            // A controlled medicine is never named here (EM-12).
            $name = $dose['order']->unrestrictedName();
            $this->signals->emit(
                MedicationSignalService::TYPE_OVERDUE,
                $clientId,
                'high',
                sprintf('Overdue dose: %s, due %s', $name, $dose['due_at']->format('H:i')),
                [
                    'site_id' => $dose['site_id'],
                    'client_medication_id' => $dose['order']->id,
                    'dose_key' => $dose['key'],
                    'dose_spell' => $spell,
                    'dose_spell_key' => $spellKey,
                    'due_at' => $dose['due_at']->toIso8601String(),
                    'window_ends_at' => $dose['window_ends_at']->toIso8601String(),
                    'overdue_count' => 1,
                    'medication_names' => [$name],
                ],
            );

            return [...$dose, 'spell_key' => $spellKey];
        });

        return ['dashboard' => $dashboard, 'doses' => $raised];
    }

    /**
     * Each dose's current overdue spell: its latest spell, or the next one
     * once that has been released.
     *
     * @param  list<string>  $keys
     * @return array<string, int>
     */
    private function currentSpells(int $clientId, array $keys): array
    {
        return $this->overdueSignals([$clientId], $keys)
            ->groupBy(fn (Signal $signal): string => (string) data_get($signal->normalized_data, 'dose_key'))
            ->map(function (Collection $signals): int {
                $latest = $signals->sortByDesc(fn (Signal $signal): int => (int) data_get($signal->normalized_data, 'dose_spell', 1))->first();
                $spell = (int) data_get($latest->normalized_data, 'dose_spell', 1);

                return data_get($latest->normalized_data, 'dose_released_at') === null ? $spell : $spell + 1;
            })
            ->all();
    }

    /**
     * Resolve the Control Room overdue alerts whose doses are no longer
     * overdue, release those doses, and bring the dashboard rows up to date.
     *
     * @param  Collection<int, array<string, mixed>>  $overdue  the doses overdue now (the lookback), for $clientIds
     * @param  list<int>|null  $clientIds  null: everyone
     */
    private function reconcile(Collection $overdue, ?array $clientIds, Carbon $now): void
    {
        $alerts = $this->openAlerts($clientIds);
        $unreleased = $this->unreleasedSignals($clientIds)->get();
        $alertDoses = $alerts->mapWithKeys(fn (ControlRoomAlert $alert): array => [$alert->id => $this->doseKeysOf($alert)]);

        // Overdue now: the lookback's doses, and any older dose an open alert
        // or an unreleased spell still stands for.
        $lookback = $overdue->pluck('key')->all();
        $older = collect($alertDoses->flatten()->all())
            ->concat($unreleased->map(fn (Signal $signal) => data_get($signal->normalized_data, 'dose_key')))
            ->filter()
            ->unique()
            ->diff($lookback)
            ->values()
            ->all();
        $stillOverdue = collect([...$lookback, ...$this->overdue->stillOverdue($older, $now)])->flip();
        $peopleOverdue = $overdue->pluck('client_id')->flip();

        foreach ($alerts as $alert) {
            $doseKeys = $alertDoses[$alert->id];
            // An alert from before C6f names no dose: it stands for the
            // person's overdue doses as a whole.
            $open = $doseKeys === []
                ? $peopleOverdue->has((int) $alert->client_id)
                : collect($doseKeys)->contains(fn (string $key): bool => $stillOverdue->has($key));
            if (! $open) {
                $this->resolve($alert, $doseKeys);
            }
        }

        $this->release($unreleased, $stillOverdue);

        // The dashboard copy: resolved for people with nothing overdue, the
        // count kept current for the rest.
        MedicationDashboardAlert::query()
            ->where('alert_type', 'overdue')
            ->where('status', 'active')
            ->when($clientIds !== null, fn ($rows) => $rows->whereIn('client_id', $clientIds))
            ->when($peopleOverdue->isNotEmpty(), fn ($rows) => $rows->whereNotIn('client_id', $peopleOverdue->keys()->all()))
            ->update(['status' => 'resolved', 'resolved_at' => now()]);
        foreach ($overdue->groupBy('client_id') as $clientId => $doses) {
            MedicationDashboardAlert::query()
                ->where('client_id', $clientId)
                ->where('alert_type', 'overdue')
                ->where('status', 'active')
                ->whereNull('client_medication_id')
                ->update(['message' => $this->dashboardMessage($doses)]);
        }
    }

    /**
     * @param  list<string>  $doseKeys
     */
    private function resolve(ControlRoomAlert $alert, array $doseKeys): void
    {
        try {
            $this->lifecycle->resolveAutomatically(
                $alert,
                'The overdue dose has been recorded, or is no longer owed.',
                self::RESOLUTION_CODE,
                self::RESOLUTION_SOURCE,
                ['dose_keys' => $doseKeys],
            );
        } catch (\InvalidArgumentException $e) {
            // An operator's open task keeps it open (the resolve gate).
            Log::warning('OverdueDoseAlerts: alert resolution was gated', [
                'alert_id' => $alert->id,
                'reason' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Release the spells that have ended: the dose is no longer overdue and
     * its alert is no longer open (resolved here, or by a person). A spell
     * whose alert is still open — another of its doses is overdue — stays,
     * so the dose falling overdue again rejoins that alert.
     *
     * @param  Collection<int, Signal>  $unreleased
     * @param  Collection<string, int>  $stillOverdue
     */
    private function release(Collection $unreleased, Collection $stillOverdue): void
    {
        $alertIds = $unreleased->map(fn (Signal $signal) => $signal->alert_id ?? $signal->correlated_alert_id)->filter()->unique()->values();
        $openAlertIds = ControlRoomAlert::query()
            ->whereKey($alertIds->all())
            ->unresolved()
            ->pluck('id')
            ->flip();

        foreach ($unreleased as $signal) {
            $alertId = $signal->alert_id ?? $signal->correlated_alert_id;
            if ($alertId === null
                || $openAlertIds->has($alertId)
                || $stillOverdue->has((string) data_get($signal->normalized_data, 'dose_key'))) {
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
     * @return Collection<int, ControlRoomAlert>
     */
    private function openAlerts(?array $clientIds): Collection
    {
        return ControlRoomAlert::query()
            ->unresolved()
            ->where('source', 'medication')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(context, '$.signal_type_code')) = ?", [MedicationSignalService::TYPE_OVERDUE])
            ->when($clientIds !== null, fn ($alerts) => $alerts->whereIn('client_id', $clientIds))
            ->get();
    }

    /**
     * Overdue spells not yet released.
     *
     * @param  list<int>|null  $clientIds
     * @return \Illuminate\Database\Eloquent\Builder<Signal>
     */
    private function unreleasedSignals(?array $clientIds)
    {
        return Signal::query()
            ->where('signal_type_code', MedicationSignalService::TYPE_OVERDUE)
            ->when($clientIds !== null, fn ($signals) => $signals->whereIn('client_id', $clientIds))
            ->whereRaw("JSON_EXTRACT(normalized_data, '$.dose_key') IS NOT NULL")
            ->whereRaw("JSON_EXTRACT(normalized_data, '$.dose_released_at') IS NULL");
    }

    /**
     * @param  list<int>  $clientIds
     * @param  list<string>  $keys
     * @return Collection<int, Signal>
     */
    private function overdueSignals(array $clientIds, array $keys): Collection
    {
        if ($keys === []) {
            return collect();
        }

        return Signal::query()
            ->where('signal_type_code', MedicationSignalService::TYPE_OVERDUE)
            ->whereIn('client_id', $clientIds)
            ->whereIn(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(normalized_data, '$.dose_key'))"), $keys)
            ->get();
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
