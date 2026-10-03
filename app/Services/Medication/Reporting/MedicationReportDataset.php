<?php

namespace App\Services\Medication\Reporting;

use App\Models\Client;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationError;
use App\Models\MedicationReview;
use App\Models\MedicationRound;
use App\Models\User;
use App\Services\Medication\DoseSlots\DoseSlotProjection;
use App\Services\Medication\DoseSlots\DoseSlotReaderScope;
use App\Services\Medication\MedicationGovernanceScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Shared factual datasets for the P09 hub and the existing report builder. */
final class MedicationReportDataset
{
    public const REPORTS = ['doses' => 'Doses', 'rounds' => 'Rounds', 'prn' => 'As needed', 'controlled' => 'Controlled medicines', 'errors' => 'Medication errors', 'reviews' => 'Reviews', 'stock' => 'Stock', 'competency' => 'Competency'];
    public const SOURCES = ['dose_slots' => 'doses', 'medication_rounds' => 'rounds', 'prn_doses' => 'prn', 'medication_errors' => 'errors', 'medication_stock' => 'stock', 'controlled_register' => 'controlled'];
    public const MAX_ROWS = 100000;

    public function __construct(private readonly MedicationReportAccess $access, private readonly DoseSlotProjection $projection, private readonly MedicationGovernanceScopeService $scope) {}

    public function read(User $actor, string $report, MedicationReportPeriod $period, array $siteIds, ?int $clientId = null): array
    {
        abort_unless(isset(self::REPORTS[$report]), 422);
        $finance = $this->access->financeOnly($actor);
        abort_if($finance && $report !== 'stock', 403);
        $clientIds = $this->access->clientIds($actor, $siteIds);
        if ($clientId !== null) {
            abort_unless(in_array($clientId, $clientIds, true), 404);
            $clientIds = [$clientId];
        }
        $clients = Client::query()->whereIn('id', $clientIds)->with('site:id,name')->get()->keyBy('id');
        $now = CarbonImmutable::now('UTC');
        $doseScope = DoseSlotReaderScope::forAuthorisedClients($actor, $clientIds);
        $notice = null;
        $weeks = [];
        $totals = [];
        $rows = collect();
        if ($report === 'doses') {
            $totals = $this->projection->totals($doseScope, $period->from, $period->to, $now);
            $byPerson = $this->projection->totalsBy('client_id', $doseScope, $period->from, $period->to, $now);
            $empty = array_fill_keys(array_keys($totals), 0);
            $empty['given_rate'] = null;
            $onlyPrn = ClientMedication::query()->whereIn('client_id', $clientIds)->where('active', true)->get(['client_id', 'is_prn'])->groupBy('client_id')->map(fn ($orders) => $orders->every(fn ($m) => $m->is_prn));
            $rows = $clients->map(function ($client) use ($byPerson, $empty, $onlyPrn, $period) {
                $numbers = $byPerson[$client->id] ?? $empty;
                $reason = $numbers['given_rate'] !== null ? null : (($onlyPrn[$client->id] ?? false) ? 'Only as-needed medicines' : ($numbers['away'] > 0 && $numbers['due'] === 0 ? 'All scheduled doses were Away' : 'No scheduled doses due in this period'));

                return $this->person($client, $period) + $numbers + ['reason' => $reason];
            })->values();
            $weeks = collect($this->projection->totalsBy('nz_date', $doseScope, $period->from, $period->to, $now))->map(fn ($numbers, $day) => ['week' => CarbonImmutable::parse($day, 'Pacific/Auckland')->startOfWeek()->toDateString()] + $numbers)->groupBy('week')->map(function ($days, $week) {
                $numbers = ['week' => $week];
                foreach (['due', 'given', 'refused', 'withheld', 'missed', 'not_recorded', 'away'] as $key) {
                    $numbers[$key] = $days->sum($key);
                }
                $numbers['given_rate'] = $numbers['due'] ? round($numbers['given'] * 100 / $numbers['due'], 1) : null;

                return $numbers;
            })->values()->all();
            $notice = $this->projection->coverage($period->from, $now)['notice'];
        } elseif ($report === 'rounds') {
            $slots = $this->projection->rows($doseScope, $period->from, $period->to, $now);
            $this->limit($slots->count());
            $rounds = MedicationRound::query()->whereIn('site_id', $siteIds)->whereBetween('round_date', [$period->from, $period->to])->orderBy('round_date')->orderBy('scheduled_time')->get();
            $rows = $rounds->map(function ($round) use ($slots, $clients, $now) {
                $at = $round->scheduledAt();
                $end = $round->windowEndsAt();
                $eligible = $slots->filter(fn ($slot) => $at && $slot['nz_date'] === $round->round_date->toDateString()
                    && (int) $clients->get($slot['client_id'])?->site_id === (int) $round->site_id
                    && (! $round->service_context_id || (int) $clients->get($slot['client_id'])?->service_context_id === (int) $round->service_context_id)
                    && CarbonImmutable::parse($slot['due_at'])->betweenIncluded($at->copy()->subMinutes($round->windowMinutes()), $end));
                // Away, self-managed and waiting-for-check slots aren't owed.
                $owed = $eligible->reject(fn ($slot) => in_array($slot['state'], ['away', 'self_managed', 'pending_check'], true));
                $recorded = $owed->filter(fn ($slot) => $slot['outcome'] !== null);
                $status = $owed->isEmpty() ? 'not_applicable' : (! $end || $now->lte($end) ? 'in_window'
                    : ($recorded->isEmpty() ? 'not_started' : ($recorded->count() < $owed->count() ? 'not_completed'
                        : ($recorded->contains(fn ($slot) => $slot['recorded_late'] || CarbonImmutable::parse($slot['outcome_at'])->gt($end)) ? 'late' : 'on_time'))));

                return ['reference' => 'round:'.$round->id, 'date' => $round->round_date->toDateString(), 'site' => $clients->firstWhere('site_id', $round->site_id)?->site?->name ?? '', 'round' => $round->name, 'status' => $status, 'due' => $owed->count(), 'recorded' => $recorded->count(), 'away' => $eligible->where('state', 'away')->count(), 'window_ends_at' => $end?->toIso8601String(), 'href' => '/emar/rounds?date='.$round->round_date->toDateString().'&site_id='.$round->site_id];
            })->filter(fn ($row) => $row['due'] > 0 || $row['away'] > 0)->values();
            $counts = $rows->countBy('status');
            $ended = array_sum(array_map(fn ($key) => $counts[$key] ?? 0, ['on_time', 'late', 'not_completed', 'not_started']));
            $totals = ['ended' => $ended, 'on_time' => $counts['on_time'] ?? 0, 'late' => $counts['late'] ?? 0, 'not_completed' => $counts['not_completed'] ?? 0, 'not_started' => $counts['not_started'] ?? 0, 'away' => $rows->sum('away'), 'on_time_pct' => $ended ? round(($counts['on_time'] ?? 0) * 100 / $ended, 1) : null];
            $notice = $this->projection->coverage($period->from, $now)['notice'];
        } elseif ($report === 'prn') {
            $records = $this->canonical(ClientMedicationAdministration::query()->effectiveClinicalEvidence(), $siteIds, $clientIds)->whereHas('medication', fn ($q) => $q->where('is_prn', true))->where('status', 'given')->whereBetween('administered_at', $period->bounds())->with('prnEffectiveness')->get();
            $rows = $records->groupBy('client_id')->map(fn ($items, $id) => $this->person($clients->get($id), $period) + ['given' => $items->count(), 'effect_recorded' => $items->filter(fn ($a) => $a->prnEffectiveness !== null && (int) $a->prnEffectiveness->client_id === (int) $a->client_id && (int) $a->prnEffectiveness->client_medication_id === (int) $a->client_medication_id)->count(), 'last_given_at' => $items->max('administered_at')?->toIso8601String()])->values();
            $totals = ['given' => $records->count(), 'effect_recorded' => $rows->sum('effect_recorded'), 'effect_pct' => $records->count() ? round($rows->sum('effect_recorded') * 100 / $records->count(), 1) : null];
        } elseif ($report === 'controlled') {
            abort_unless($actor->canDo('medications.controlled.view'), 403);
            $entries = $this->canonical(ClientControlledDrugEntry::query(), $siteIds, $clientIds)->whereBetween('recorded_at', $period->bounds())->with('medication')->orderBy('recorded_at')->get();
            $rows = $entries->map(fn ($e) => $this->person($clients->get($e->client_id), $period) + ['reference' => 'register:'.$e->id, 'date' => $e->recorded_at->timezone('Pacific/Auckland')->toDateString(), 'medicine' => $e->medication?->historicalDisplayName(), 'movement' => $e->entry_type, 'quantity' => (float) $e->quantity, 'balance' => $e->on_hand_after === null ? null : (float) $e->on_hand_after, 'witnessed' => $e->witnessed_by !== null ? 1 : 0])->values();
            $counts = $this->canonical(\App\Models\MedicationScheduledStockCount::query(), $siteIds, $clientIds)->whereHas('medication', fn ($q) => $q->where('controlled_drug', true))->whereBetween('scheduled_date', [$period->from, $period->to])->where('status', 'completed')->count();
            $discrepancies = $this->canonical(\App\Models\ClientControlledDrugDiscrepancy::query(), $siteIds, $clientIds)->whereBetween('reported_at', $period->bounds())->count();
            $losses = $this->canonical(\App\Models\ControlledDrugLossReport::query(), $siteIds, $clientIds)->whereBetween('discovered_at', $period->bounds())->count();
            $given = $this->canonical(ClientMedicationAdministration::query()->effectiveClinicalEvidence(), $siteIds, $clientIds)->whereHas('medication', fn ($q) => $q->where('controlled_drug', true))->where('status', 'given')->whereBetween('administered_at', $period->bounds())->count();
            $totals = ['movements' => $rows->count(), 'witnessed' => $rows->sum('witnessed'), 'receipts' => $rows->where('movement', 'receipt')->count(), 'disposals' => $rows->whereIn('movement', ['disposal', 'destruction'])->count(), 'counts' => $counts, 'discrepancies' => $discrepancies, 'losses' => $losses, 'given' => $given];
        } elseif ($report === 'errors') {
            $records = $this->canonical(MedicationError::query(), $siteIds, $clientIds, true)->whereBetween('reported_at', $period->bounds())->orderBy('reported_at')->get();
            // Only recorded facts. Free-text accounts may name a controlled
            // medicine, so they never enter this report or the builder domain.
            $rows = $records->map(fn ($e) => $this->person($clients->get($e->client_id), $period) + ['reference' => $e->reference_number, 'date' => $e->reported_at?->timezone('Pacific/Auckland')->toDateString(), 'error_type' => $e->error_type, 'reached' => $e->reached_client ?? 'unknown', 'harm' => $e->harm_level ?? 'unknown', 'status' => $e->status, 'confirmed_sac' => $e->confirmed_sac, 'href' => '/emar/errors?error_id='.$e->id, 'in_error' => $e->status === 'in_error' ? 1 : 0])->values();
            $effective = $rows->where('in_error', 0);
            $totals = ['reached' => $effective->where('reached', 'yes')->count(), 'near_misses' => $effective->where('reached', 'no')->count(), 'with_harm' => $effective->whereIn('harm', ['minor', 'moderate', 'severe', 'severe_permanent', 'death'])->count(), 'open' => $effective->whereNotIn('status', ['closed', 'resolved'])->count(), 'reach_unknown' => $effective->whereNotIn('reached', ['yes', 'no'])->count()];
        } elseif ($report === 'reviews') {
            $rows = MedicationReview::query()->whereIn('client_id', $clientIds)->where(fn ($q) => $q->whereBetween('scheduled_date', [$period->from, $period->to])->orWhereBetween('completed_date', [$period->from, $period->to]))->get()->map(fn ($r) => $this->person($clients->get($r->client_id), $period) + ['reference' => 'review:'.$r->id, 'date' => $r->scheduled_date?->toDateString(), 'status' => $r->status, 'completed_date' => $r->completed_date?->toDateString(), 'next_review_date' => $r->next_review_date?->toDateString(), 'href' => '/emar/reviews?client_id='.$r->client_id])->values();
            $totals = ['due' => $rows->whereBetween('date', [$period->from, $period->to])->count(), 'done' => $rows->whereBetween('completed_date', [$period->from, $period->to])->count(), 'overdue' => MedicationReview::query()->whereIn('client_id', $clientIds)->overdue()->count()];
        } elseif ($report === 'stock') {
            $query = ClientMedicationStock::query()->whereHas('medication.client', fn ($q) => $q->whereIn('site_id', $siteIds))->with('medication.client.site:id,name');
            if (! $finance) {
                $query->whereHas('medication', fn ($q) => $q->whereIn('client_id', $clientIds));
            }
            if (! $actor->canDo('medications.controlled.view')) {
                $query->whereHas('medication', fn ($q) => $q->where('controlled_drug', false));
            }
            $rows = $query->get()->map(fn ($s) => ['reference' => 'stock:'.$s->id, 'medicine' => $s->medication?->historicalDisplayName(), 'site' => $s->medication?->client?->site?->name, 'unit' => $s->unit, 'on_hand' => $s->on_hand === null ? null : (float) $s->on_hand, 'reorder_level' => $s->reorder_level === null ? null : (float) $s->reorder_level, 'expiry_date' => $s->expiry_date?->toDateString(), 'value_on_hand' => null, 'low' => $s->on_hand !== null && $s->reorder_level !== null && (float) $s->on_hand <= (float) $s->reorder_level ? 1 : 0])->values();
            if ($finance) {
                // Collapse person-owned stock into medicine/house/unit lines.
                // No person names, person IDs, links or individual balances.
                $rows = $rows->groupBy(fn ($r) => json_encode([$r['medicine'], $r['site'], $r['unit']]))->map(fn ($group) => ['medicine' => $group[0]['medicine'], 'site' => $group[0]['site'], 'unit' => $group[0]['unit'], 'on_hand' => $group->contains(fn ($r) => $r['on_hand'] === null) ? null : $group->sum('on_hand'), 'value_on_hand' => null])->values();
            }
            $totals = ['lines' => $rows->count(), 'low' => $finance ? null : $rows->sum('low'), 'expiring' => $finance ? null : $rows->filter(fn ($r) => $r['expiry_date'] !== null && $r['expiry_date'] >= $now->timezone('Pacific/Auckland')->toDateString() && $r['expiry_date'] <= $now->timezone('Pacific/Auckland')->addDays(30)->toDateString())->count()];
            $notice = 'Stock is as at now. Value on hand is Not configured until stock has a recorded unit cost.';
        } else {
            $records = MedicationCompetencyAssessment::query()->whereHas('user.hrEmployeeProfile', fn ($q) => $q->whereIn('primary_site_id', $siteIds)->where('is_active', true))->with('user:id,name')->orderByDesc('assessment_date')->orderByDesc('id')->get()->unique('user_id');
            $rows = $records->map(fn ($a) => ['reference' => 'assessment:'.$a->id, 'staff' => $a->user?->name, 'status' => $a->status, 'date' => $a->assessment_date?->toDateString(), 'expiry_date' => $a->expiry_date?->toDateString(), 'current' => $a->status === 'passed' && $a->expiry_date?->gte($now->timezone('Pacific/Auckland')->startOfDay()) ? 1 : 0, 'href' => '/emar/settings#staff/competency'])->values();
            $totals = ['assessed' => $rows->count(), 'current' => $rows->sum('current'), 'current_pct' => $rows->count() ? round($rows->sum('current') * 100 / $rows->count(), 1) : null];
        }
        $this->limit($rows->count());

        return ['rows' => $rows->all(), 'totals' => $totals, 'notice' => $notice, 'weeks' => $weeks];
    }

    /** Actual as-needed administrations for the builder, not person aggregates. */
    public function prnRows(User $actor, MedicationReportPeriod $period, array $sites, ?int $clientId = null): array
    {
        $ids = $this->access->clientIds($actor, $sites);
        if ($clientId !== null) {
            abort_unless(in_array($clientId, $ids, true), 404);
            $ids = [$clientId];
        }
        $clients = Client::query()->whereIn('id', $ids)->with('site:id,name')->get()->keyBy('id');
        $query = $this->canonical(ClientMedicationAdministration::query()->effectiveClinicalEvidence(), $sites, $ids)->whereHas('medication', fn ($q) => $q->where('is_prn', true))->where('status', 'given')->whereBetween('administered_at', $period->bounds())->with(['medication', 'prnEffectiveness'])->orderBy('administered_at')->orderBy('id');
        $this->limit((clone $query)->count());

        return $query->get()->map(function ($a) use ($actor, $clients, $period) {
            $concealed = $a->medication?->controlled_drug && ! $actor->canDo('medications.controlled.view');
            $effect = $a->prnEffectiveness && (int) $a->prnEffectiveness->client_id === (int) $a->client_id && (int) $a->prnEffectiveness->client_medication_id === (int) $a->client_medication_id;

            return $this->person($clients->get($a->client_id), $period) + ['reference' => $concealed ? null : 'administration:'.$a->id, 'date' => $a->administered_at->timezone('Pacific/Auckland')->toDateString(), 'medicine' => $concealed ? 'Controlled medicine' : $a->medication?->historicalDisplayName(), 'recorded_at' => $a->administered_at->toIso8601String(), 'status' => $a->status, 'effect_recorded' => $effect ? 1 : 0];
        })->all();
    }

    /** Source-level scheduled doses for the builder and omission audit. */
    public function doseRows(User $actor, MedicationReportPeriod $period, array $siteIds, ?int $clientId = null): array
    {
        $ids = $this->access->clientIds($actor, $siteIds);
        if ($clientId !== null) {
            abort_unless(in_array($clientId, $ids, true), 404);
            $ids = [$clientId];
        }
        $rows = $this->projection->rows(DoseSlotReaderScope::forAuthorisedClients($actor, $ids), $period->from, $period->to, CarbonImmutable::now('UTC'));
        $this->limit($rows->count());
        $clients = Client::query()->whereIn('id', $ids)->with('site:id,name')->get()->keyBy('id');
        $medicines = ClientMedication::withTrashed()->whereIn('id', $rows->pluck('client_medication_id')->filter()->unique())->get()->keyBy('id');

        return $rows->map(fn ($slot) => $this->person($clients->get($slot['client_id']), $period) + ['reference' => 'slot:'.$slot['id'], 'date' => $slot['nz_date'], 'medicine' => $slot['concealed'] ? 'Controlled medicine' : $medicines->get($slot['client_medication_id'])?->historicalDisplayName(), 'dose' => $slot['concealed'] ? 'Concealed' : $medicines->get($slot['client_medication_id'])?->dosage, 'route' => $slot['concealed'] ? 'Concealed' : $medicines->get($slot['client_medication_id'])?->route, 'status' => $slot['state'], 'due_at' => $slot['due_at'], 'window_ends_at' => $slot['window_ends_at'], 'recorded_at' => $slot['outcome_at'], 'late' => $slot['recorded_late'] ? 1 : 0, 'away' => $slot['state'] === 'away' ? 1 : 0])->all();
    }

    private function canonical(Builder $query, array $sites, array $clients, bool $nullMedicine = false): Builder
    {
        return $this->scope->scopeCanonicalClientMedicationRows($query, $sites, $nullMedicine)->whereIn($query->qualifyColumn('client_id'), $clients);
    }

    private function person(?Client $client, MedicationReportPeriod $period): array
    {
        return ['client_id' => $client?->id, 'person' => $client ? trim($client->first_name.' '.$client->last_name) : '', 'site' => $client?->site?->name, 'href' => $client ? '/emar/clients/'.$client->id.'/mar?date_from='.$period->from.'&date_to='.$period->to : null];
    }

    private function limit(int $count): void
    {
        abort_if($count > self::MAX_ROWS, 422, 'This period exceeds 100,000 source rows. Choose a shorter period; no partial result was created.');
    }
}
