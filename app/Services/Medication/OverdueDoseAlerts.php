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
use Illuminate\Support\Facades\Log;

/**
 * The Control Room overdue-dose alert and its dashboard copy, from the doses
 * OverdueDoses finds (P01 foundation C6f) — the same overdue doses Meds
 * today, My Day and the badge show.
 *
 * Each overdue dose is one signal, keyed by the dose, so a dose raises an
 * alert once however often the job runs; a person's doses that fall overdue
 * together (a round) group into one alert. An alert resolves itself once
 * none of its doses is overdue any more — the dose was recorded, its order
 * changed so it's no longer owed, or alerts were suppressed for the person —
 * straight after the change commits, and on every run of the overdue job.
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
     * @return Collection<int, array<string, mixed>> the overdue doses (for the job's own notifications)
     */
    public function sweep(Carbon $now): Collection
    {
        $overdue = $this->overdue->at($now);
        foreach ($overdue->groupBy('client_id') as $clientId => $doses) {
            $this->raise((int) $clientId, $doses);
        }
        $this->resolveNoLongerOverdue($overdue, null);

        return $overdue;
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
        $alert = $overdue->isEmpty() ? null : $this->raise((int) $client->id, $overdue);
        $this->resolveNoLongerOverdue($overdue, [(int) $client->id]);

        return $alert?->toArray();
    }

    /**
     * After a dose's record or its order changes (once committed): resolve
     * the person's overdue alerts whose doses are no longer overdue, and
     * their dashboard row with them. Cheap when they have no open Control
     * Room overdue alert; the job reconciles anything else.
     */
    public function afterDoseChange(int $clientId): void
    {
        try {
            if ($this->openAlerts([$clientId])->isEmpty()) {
                return;
            }
            $this->resolveNoLongerOverdue($this->overdue->at(now(), [$clientId]), [$clientId]);
        } catch (\Throwable $e) {
            // Never let alert housekeeping fail a recording; the job retries.
            report($e);
        }
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $doses  one person's overdue doses
     */
    private function raise(int $clientId, Collection $doses): MedicationDashboardAlert
    {
        // A controlled medicine is never named here (EM-12).
        $names = $doses->map(fn (array $dose): string => $dose['order']->unrestrictedName())->unique()->values();
        $dashboard = MedicationDashboardAlert::createOrUpdateAlert(
            $clientId,
            'overdue',
            'critical',
            $doses->count().' overdue dose(s): '.$names->implode(', '),
            null,
        );

        foreach ($doses as $dose) {
            $this->signals->emit(
                MedicationSignalService::TYPE_OVERDUE,
                $clientId,
                'high',
                sprintf('Overdue dose: %s, due %s', $dose['order']->unrestrictedName(), $dose['due_at']->format('H:i')),
                [
                    'site_id' => $dose['site_id'],
                    'client_medication_id' => $dose['order']->id,
                    'dose_key' => $dose['key'],
                    'due_at' => $dose['due_at']->toIso8601String(),
                    'window_ends_at' => $dose['window_ends_at']->toIso8601String(),
                    'overdue_count' => 1,
                    'medication_names' => [$dose['order']->unrestrictedName()],
                ],
            );
        }

        return $dashboard;
    }

    /**
     * Resolve the Control Room overdue alerts and dashboard rows whose doses
     * are no longer overdue.
     *
     * @param  Collection<int, array<string, mixed>>  $overdue  the doses overdue now, for $clientIds
     * @param  list<int>|null  $clientIds  null: everyone
     */
    private function resolveNoLongerOverdue(Collection $overdue, ?array $clientIds): void
    {
        $stillOverdue = $overdue->pluck('key')->flip();
        $peopleOverdue = $overdue->pluck('client_id')->flip();

        MedicationDashboardAlert::query()
            ->where('alert_type', 'overdue')
            ->where('status', 'active')
            ->when($clientIds !== null, fn ($rows) => $rows->whereIn('client_id', $clientIds))
            ->when($peopleOverdue->isNotEmpty(), fn ($rows) => $rows->whereNotIn('client_id', $peopleOverdue->keys()->all()))
            ->update(['status' => 'resolved', 'resolved_at' => now()]);

        $alerts = $this->openAlerts($clientIds);
        foreach ($alerts as $alert) {
            $doseKeys = $this->doseKeysOf($alert);
            // An alert from before C6f names no dose: it stands for the
            // person's overdue doses as a whole.
            $open = $doseKeys === []
                ? $peopleOverdue->has((int) $alert->client_id)
                : collect($doseKeys)->contains(fn (string $key): bool => $stillOverdue->has($key));
            if ($open) {
                continue;
            }

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
