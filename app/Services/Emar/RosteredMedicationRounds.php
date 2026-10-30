<?php

namespace App\Services\Emar;

use App\Models\Client;
use App\Models\MedicationRound;
use App\Models\Shift;
use App\Models\User;
use App\Services\MarScheduleService;
use App\Services\Medication\DoseSlots\DoseSlotReaderScope;
use App\Services\Medication\DoseSlots\ScheduledDoseStates;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;

/** Current approved-Site and person read scope; no administration authority. */
final class RosteredMedicationRounds
{
    public function context(User $user, Carbon $from, Carbon $to): array
    {
        if (! $user->canDo('medications.view') && ! $user->canDo('medications.administer.record')) {
            return ['site_ids' => [], 'people' => [], 'shifts' => collect()];
        }
        $sites = app(UserSiteAccessService::class)->accessibleSiteIds($user, ['clinical.accessAllSites', 'sites.viewAll']);
        $candidates = Client::query()->whereIn('site_id', $sites)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $people = DoseSlotReaderScope::forViewerClients($user, $candidates)->clientIds ?? [];
        $shifts = Shift::query()->visibleToFrontline()->where('user_id', $user->id)
            ->where('status', '!=', 'cancelled')->whereIn('site_id', $sites)
            ->where('starts_at', '<=', $to->copy()->utc())->where('ends_at', '>=', $from->copy()->utc())->get();

        return ['site_ids' => $sites, 'people' => $people, 'shifts' => $shifts];
    }

    /** Counts include concealed controlled doses of these same readable people. */
    public function progress(MedicationRound $round, array $people): array
    {
        $people = Client::query()->whereIn('id', $people)->where('site_id', $round->site_id)
            ->when($round->service_context_id, fn ($q) => $q->where('service_context_id', $round->service_context_id))
            ->pluck('id')->all();
        $at = $round->scheduledAt();
        $from = $at->copy()->subMinutes($round->windowMinutes());
        $to = $at->copy()->addMinutes($round->windowMinutes());
        $orders = ScheduledDoseStates::listedOrders($people)->get();
        $schedule = app(MarScheduleService::class);
        $records = $schedule->administrationsForWindow($people, $from, $to);
        $doses = app(ScheduledDoseStates::class)->dosesOn($orders, $at, now());
        $counts = ['total' => 0, 'completed' => 0, 'pending' => 0, 'waiting' => 0, 'late' => 0];
        foreach ($orders as $order) {
            foreach ($doses[(int) $order->id] ?? [] as $dose) {
                if (! $dose['due_at']->betweenIncluded($from, $to) || in_array($dose['state'], ['away', 'self_managed'], true)) {
                    continue;
                }
                $record = $records->get($schedule->slotKey((int) $order->client_id, (int) $order->id, $dose['due_at']));
                $recorded = in_array($record?->status ?? $dose['outcome'], ScheduledDoseStates::RECORDED_STATUSES, true);
                if ($dose['state'] === 'pending_check' && ! $recorded) {
                    $counts['waiting']++;

                    continue;
                }
                $counts['total']++;
                $counts[$recorded ? 'completed' : 'pending']++;
                $counts['late'] += ! $recorded && in_array($dose['state'], ['late', 'not_recorded'], true) ? 1 : 0;
            }
        }

        return $counts;
    }
}
