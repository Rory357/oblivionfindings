<?php

namespace App\Services\Medication\Reporting;

use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\ControlledDrugLossReport;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationError;
use App\Models\MedicationOrderRevision;
use App\Models\MedicationReview;
use App\Models\MedicationRound;
use App\Models\MedicationSyringeDriver;
use App\Models\User;
use App\Services\Medication\DoseSlots\DoseSlotProjection;
use App\Services\Medication\DoseSlots\DoseSlotReaderScope;
use App\Services\Medication\MarLinkService;
use App\Services\Medication\MedicationAdministratorCompetencyPolicy;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\StaffEligibilityRegister;
use App\Support\Medication\MedicationStockQuantity as Quantity;
use App\Support\Medication\StockLotRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/** Shared factual datasets for the P09 hub and the existing report builder. */
final class MedicationReportDataset
{
    public const REPORTS = ['doses' => 'Doses', 'rounds' => 'Rounds', 'prn' => 'As needed', 'syringe_drivers' => 'Syringe drivers', 'controlled' => 'Controlled medicines', 'errors' => 'Medication errors', 'reviews' => 'Reviews', 'stock' => 'Stock', 'competency' => 'Competency'];

    public const SOURCES = ['dose_slots' => 'doses', 'medication_rounds' => 'rounds', 'prn_doses' => 'prn', 'medication_errors' => 'errors', 'medication_stock' => 'stock', 'controlled_register' => 'controlled'];

    public const MAX_ROWS = 100000;

    public function __construct(
        private readonly MedicationReportAccess $access,
        private readonly DoseSlotProjection $projection,
        private readonly MedicationGovernanceScopeService $scope,
        private readonly StaffEligibilityRegister $staffEligibility,
        private readonly MedicationAdministratorCompetencyPolicy $competency,
    ) {}

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
            $rows = $clients->map(function ($client) use ($byPerson, $empty, $onlyPrn, $period, $actor) {
                $numbers = $byPerson[$client->id] ?? $empty;
                $reason = $numbers['given_rate'] !== null ? null : (($onlyPrn[$client->id] ?? false) ? 'Only as-needed medicines' : ($numbers['away'] > 0 && $numbers['due'] === 0 ? 'All scheduled doses were Away' : 'No scheduled doses due in this period'));

                return $this->person($client, $period, $actor) + $numbers + ['reason' => $reason];
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
            $rows = $records->groupBy('client_id')->map(fn ($items, $id) => $this->person($clients->get($id), $period, $actor) + ['given' => $items->count(), 'effect_recorded' => $items->filter(fn ($a) => $a->prnEffectiveness !== null && (int) $a->prnEffectiveness->client_id === (int) $a->client_id && (int) $a->prnEffectiveness->client_medication_id === (int) $a->client_medication_id)->count(), 'last_given_at' => $items->max('administered_at')?->toIso8601String()])->values();
            $totals = ['given' => $records->count(), 'effect_recorded' => $rows->sum('effect_recorded'), 'effect_pct' => $records->count() ? round($rows->sum('effect_recorded') * 100 / $records->count(), 1) : null];
        } elseif ($report === 'syringe_drivers') {
            $query = MedicationSyringeDriver::query()->whereIn('client_id', $clientIds)
                ->where(fn ($q) => $q->whereNull('site_id')->orWhereIn('site_id', $siteIds))
                ->whereBetween('commenced_at', $period->bounds())->orderBy('commenced_at')->orderBy('id');
            $this->limit((clone $query)->count());
            $rows = $query->get()->map(function ($driver) use ($clients, $period, $actor) {
                $client = $clients->get($driver->client_id);
                $contents = $this->scope->visibleSyringeDriverContents($client, $driver->contents ?? [], $actor->canDo('medications.controlled.view'));
                if ($contents === null) {
                    return null;
                }

                return $this->person($client, $period, $actor) + [
                    'reference' => 'driver:'.$driver->id, 'status' => $driver->status,
                    'commenced_at' => $driver->commenced_at?->toIso8601String(),
                    'completed_at' => $driver->completed_at?->toIso8601String(),
                    'rate' => $driver->rate, 'rate_unit' => $driver->rate_unit,
                    'duration_hours' => $driver->duration_hours, 'contents' => $contents,
                ];
            })->filter()->values();
            $totals = ['drivers' => $rows->count(), 'running' => $rows->where('status', 'running')->count(), 'completed' => $rows->where('status', 'completed')->count()];
        } elseif ($report === 'controlled') {
            abort_unless($actor->canDo('medications.controlled.view'), 403);
            $entries = $this->canonical(ClientControlledDrugEntry::query(), $siteIds, $clientIds)->whereBetween('recorded_at', $period->bounds())->with('medication')->orderBy('recorded_at')->get();
            $rows = $entries->map(function ($e) use ($clients, $period, $actor) {
                $day = $e->recorded_at->timezone('Pacific/Auckland')->toDateString();
                $person = $this->person($clients->get($e->client_id), $period, $actor);
                $href = $person['href'] !== null ? '/emar/controlled?'.http_build_query([
                    'client_id' => $e->client_id, 'client_medication_id' => $e->client_medication_id, 'date' => $day, 'entry_id' => $e->id,
                ]) : null;

                return array_merge($person, ['href' => $href, 'reference' => 'register:'.$e->id, 'date' => $day,
                    'medicine' => $e->medication?->historicalDisplayName(), 'movement' => $e->entry_type, 'quantity' => (float) $e->quantity,
                    'balance' => $e->on_hand_after === null ? null : (float) $e->on_hand_after, 'witnessed' => $e->witnessed_by !== null ? 1 : 0]);
            })->values();
            // P07's real witnessed ledger entry is the count evidence. A task
            // marked complete is not a substitute for a recorded count.
            $counts = $entries->where('entry_type', 'balance_check')->count();
            $discrepancies = $this->canonical(ClientControlledDrugDiscrepancy::query(), $siteIds, $clientIds)->whereBetween('reported_at', $period->bounds())->count();
            $losses = $this->canonical(ControlledDrugLossReport::query(), $siteIds, $clientIds)->whereBetween('discovered_at', $period->bounds())->count();
            $given = $this->canonical(ClientMedicationAdministration::query()->effectiveClinicalEvidence(), $siteIds, $clientIds)->whereHas('medication', fn ($q) => $q->where('controlled_drug', true))->where('status', 'given')->whereBetween('administered_at', $period->bounds())->count();
            $totals = ['movements' => $rows->count(), 'witnessed' => $rows->sum('witnessed'), 'receipts' => $rows->where('movement', 'receipt')->count(), 'disposals' => $rows->whereIn('movement', ['disposal', 'destruction'])->count(), 'counts' => $counts, 'discrepancies' => $discrepancies, 'losses' => $losses, 'given' => $given];
        } elseif ($report === 'errors') {
            $bounds = $period->bounds();
            $records = $this->errorQuery($actor, $siteIds, $clientIds)->whereRaw('COALESCE(occurred_at, reported_at) BETWEEN ? AND ?', $bounds)->orderByRaw('COALESCE(occurred_at, reported_at)')->orderBy('id')->get();
            // Only recorded facts. Free-text accounts may name a controlled
            // medicine, so they never enter this report or the builder domain.
            $rows = $records->map(fn ($e) => array_replace($this->person($clients->get($e->client_id), $period, $actor), ['reference' => $e->reference_number, 'date' => ($e->occurred_at ?? $e->reported_at)?->timezone('Pacific/Auckland')->toDateString(), 'occurred_at' => ($e->occurred_at ?? $e->reported_at)?->toIso8601String(), 'reported_at' => $e->reported_at?->toIso8601String(), 'error_type' => $e->error_type, 'reached' => $e->reached_client ?? 'unknown', 'harm' => $e->harm_level ?? 'unknown', 'status' => $e->stage(), 'confirmed_sac' => $e->confirmed_sac, 'href' => '/emar/errors?error='.$e->id, 'in_error' => $e->status === 'in_error' ? 1 : 0]))->values();
            $effective = $rows->where('in_error', 0);
            $totals = ['reached' => $effective->where('reached', 'yes')->count(), 'near_misses' => $effective->where('reached', 'no')->count(), 'with_harm' => $effective->whereIn('harm', ['minor', 'moderate', 'severe', 'severe_permanent', 'death'])->count(), 'open' => $effective->where('status', '!=', 'closed')->count(), 'reach_unknown' => $effective->whereNotIn('reached', ['yes', 'no'])->count()];
        } elseif ($report === 'reviews') {
            $rows = MedicationReview::query()->whereIn('client_id', $clientIds)->where(fn ($q) => $q->whereBetween('scheduled_date', [$period->from, $period->to])->orWhereBetween('happened_at', $period->bounds())->orWhere(fn ($q) => $q->whereNull('happened_at')->whereBetween('completed_date', [$period->from, $period->to])))->orderBy('scheduled_date')->orderBy('id')->get()->map(fn ($r) => array_replace($this->person($clients->get($r->client_id), $period, $actor), ['reference' => 'review:'.$r->id, 'date' => $r->scheduled_date?->toDateString(), 'status' => $r->status, 'happened_at' => $r->happened_at?->toIso8601String(), 'completed_date' => $r->happened_at?->timezone('Pacific/Auckland')->toDateString() ?? $r->completed_date?->toDateString(), 'next_review_date' => $r->next_review_date?->toDateString(), 'href' => '/emar/reviews?review='.$r->id]))->values();
            $totals = ['due' => $rows->whereBetween('date', [$period->from, $period->to])->count(), 'done' => $rows->where('status', 'completed')->whereBetween('completed_date', [$period->from, $period->to])->count(), 'overdue' => MedicationReview::query()->whereIn('client_id', $clientIds)->whereIn('status', ['scheduled', 'overdue', 'in_progress'])->whereDate('scheduled_date', '<', $now->timezone('Pacific/Auckland')->toDateString())->count()];
        } elseif ($report === 'stock') {
            $query = ClientMedicationStock::query()->whereHas('medication.client', fn ($q) => $q->whereIn('site_id', $siteIds))->with(['medication.client.site:id,name', 'lots']);
            if (! $finance) {
                $query->whereHas('medication', fn ($q) => $q->whereIn('client_id', $clientIds));
            }
            if (! $actor->canDo('medications.controlled.view')) {
                $query->whereHas('medication', fn ($q) => $q->where('controlled_drug', false));
            }
            $today = $now->timezone('Pacific/Auckland')->toDateString();
            $this->limit((clone $query)->count());
            $rows = $query->get()->map(function ($s) use ($today) {
                $usable = $s->lots->map(fn ($lot) => ['id' => $lot->id, 'state' => $lot->state, 'quantity_remaining' => $lot->quantity_remaining, 'expiry_date' => $lot->expiry_date?->toDateString()])->filter(fn ($lot) => StockLotRules::usable($lot, $today))->values();
                // P06: legacy unknown remains unknown; controlled physical stock
                // follows its real register balance rather than pack allocation.
                $onHand = $s->lots_started_at !== null && ! $s->medication?->controlled_drug ? Quantity::toFloat(StockLotRules::onHand($usable->all(), $today)) : ($s->on_hand === null ? null : (float) $s->on_hand);
                $expiry = $s->lots_started_at !== null ? $usable->pluck('expiry_date')->filter()->sort()->first() : $s->expiry_date?->toDateString();

                return ['reference' => 'stock:'.$s->id, 'medicine' => $s->medication?->historicalDisplayName(), 'site' => $s->medication?->client?->site?->name, 'unit' => $s->unit, 'on_hand' => $onHand, 'reorder_level' => $s->reorder_level === null ? null : (float) $s->reorder_level, 'expiry_date' => $expiry, 'value_on_hand' => null, 'low' => $onHand !== null && $s->reorder_level !== null && $onHand <= (float) $s->reorder_level ? 1 : 0];
            })->values();
            if ($finance) {
                // Collapse person-owned stock into medicine/house/unit lines.
                // No person names, person IDs, links or individual balances.
                $rows = $rows->groupBy(fn ($r) => json_encode([$r['medicine'], $r['site'], $r['unit']]))->map(fn ($group) => ['medicine' => $group[0]['medicine'], 'site' => $group[0]['site'], 'unit' => $group[0]['unit'], 'on_hand' => $group->contains(fn ($r) => $r['on_hand'] === null) ? null : $group->sum('on_hand'), 'value_on_hand' => null])->values();
            }
            $totals = ['lines' => $rows->count(), 'low' => $finance ? null : $rows->sum('low'), 'expiring' => $finance ? null : $rows->filter(fn ($r) => $r['expiry_date'] !== null && $r['expiry_date'] >= $now->timezone('Pacific/Auckland')->toDateString() && $r['expiry_date'] <= $now->timezone('Pacific/Auckland')->addDays(30)->toDateString())->count()];
            $notice = 'Stock is as at now. Value on hand is Not configured until stock has a recorded unit cost.';
        } else {
            $users = $this->staffEligibility->users($siteIds);
            $rows = $users->map(function (User $user) use ($now, $actor): array {
                // A temporary site exemption permits work but is not a current assessment.
                $decision = $this->competency->evaluate($user, null, $now);
                $assessment = $decision['assessment_id'] !== null
                    ? $user->medicationCompetencyAssessments->firstWhere('id', $decision['assessment_id'])
                    : $user->medicationCompetencyAssessments
                        ->sortByDesc(fn (MedicationCompetencyAssessment $a): array => [$a->assessment_date?->toDateString() ?? '', $a->id])
                        ->first();

                return [
                    'reference' => $assessment ? 'assessment:'.$assessment->id : 'staff:'.$user->id,
                    'staff' => $user->name,
                    'status' => $decision['state'],
                    'assessment_status' => $assessment?->status,
                    'date' => $assessment?->assessment_date?->toDateString(),
                    'expiry_date' => $assessment?->expiry_date?->toDateString(),
                    'current' => $decision['state'] === 'valid' ? 1 : 0,
                    'href' => $actor->canDo('medications.view') ? '/emar/safety/eligibility' : null,
                ];
            })->values();
            $totals = [
                'staff' => $rows->count(),
                'assessed' => $users->filter(fn (User $user): bool => $user->medicationCompetencyAssessments->isNotEmpty())->count(),
                'current' => $rows->sum('current'),
                'current_pct' => $rows->count() ? round($rows->sum('current') * 100 / $rows->count(), 1) : null,
            ];
            $notice = 'Competency is as at now. Current assessments require an independent assessor declaration and staff acknowledgement. Temporary house exemptions are not current assessments.';
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

            return $this->person($clients->get($a->client_id), $period, $actor) + ['reference' => $concealed ? null : 'administration:'.$a->id, 'date' => $a->administered_at->timezone('Pacific/Auckland')->toDateString(), 'medicine' => $concealed ? 'Controlled medicine' : $a->medication?->historicalDisplayName(), 'recorded_at' => $a->administered_at->toIso8601String(), 'status' => $a->status, 'effect_recorded' => $effect ? 1 : 0];
        })->all();
    }

    /** Source-level scheduled doses for the builder and omission audit. */
    public function doseRows(User $actor, MedicationReportPeriod $period, array $siteIds, ?int $clientId = null, ?int $roundId = null): array
    {
        $ids = $this->access->clientIds($actor, $siteIds);
        if ($clientId !== null) {
            abort_unless(in_array($clientId, $ids, true), 404);
            $ids = [$clientId];
        }
        $rows = $this->projection->rows(DoseSlotReaderScope::forAuthorisedClients($actor, $ids), $period->from, $period->to, CarbonImmutable::now('UTC'));
        $clients = Client::query()->whereIn('id', $ids)->with('site:id,name')->get()->keyBy('id');
        if ($roundId !== null) {
            $round = MedicationRound::query()->whereIn('site_id', $siteIds)->findOrFail($roundId);
            abort_unless($period->from === $period->to && $round->round_date->toDateString() === $period->from, 404);
            $at = $round->scheduledAt();
            abort_unless($at !== null, 422, 'This round has no scheduled time.');
            $rows = $rows->filter(fn ($slot) => (int) $clients->get($slot['client_id'])?->site_id === (int) $round->site_id
                && (! $round->service_context_id || (int) $clients->get($slot['client_id'])?->service_context_id === (int) $round->service_context_id)
                && CarbonImmutable::parse($slot['due_at'])->betweenIncluded($at->copy()->subMinutes($round->windowMinutes()), $round->windowEndsAt()))->values();
        }
        $this->limit($rows->count());
        $medicines = ClientMedication::withTrashed()->whereIn('id', $rows->pluck('client_medication_id')->filter()->unique())->get()->keyBy('id');
        $checked = MedicationOrderRevision::query()->whereIn('client_medication_id', $medicines->keys())->whereIn('client_id', $ids)->whereNotNull('checked_at')->whereIn('status', ['checked', 'checked_alone'])->with('version')->orderByDesc('checked_at')->orderByDesc('id')->get()->groupBy('client_medication_id');

        return $rows->map(function ($slot) use ($actor, $clients, $period, $medicines, $checked) {
            $medicine = $medicines->get($slot['client_medication_id']);
            $revision = ($checked[$slot['client_medication_id']] ?? collect())->first(fn ($r) => (int) $r->client_id === (int) $slot['client_id'] && $r->checked_at->lte(CarbonImmutable::parse($slot['due_at'])) && (int) $r->version?->client_medication_id === (int) $slot['client_medication_id'] && (int) $r->version?->client_id === (int) $slot['client_id']);
            $version = $revision?->version;
            $concealed = $slot['concealed'] || ($version?->controlled_drug && ! $actor->canDo('medications.controlled.view'));

            return $this->person($clients->get($slot['client_id']), $period, $actor) + ['reference' => 'slot:'.$slot['id'], 'order_reference' => $concealed ? null : 'order:'.$slot['client_medication_id'], 'version_reference' => $concealed || ! $version ? null : 'order-version:'.$version->id, 'date' => $slot['nz_date'], 'medicine' => $concealed ? 'Controlled medicine' : ($version?->name ?? $medicine?->historicalDisplayName()), 'dose' => $concealed ? 'Concealed' : ($version?->formatted_dose ?? 'Not recorded'), 'route' => $concealed ? 'Concealed' : ($version?->route ?? 'Not recorded'), 'status' => $slot['state'], 'due_at' => $slot['due_at'], 'window_ends_at' => $slot['window_ends_at'], 'recorded_at' => $slot['outcome_at'], 'late' => $slot['recorded_late'] ? 1 : 0, 'away' => $slot['state'] === 'away' ? 1 : 0];
        })->all();
    }

    /** Retained as-needed outcomes in the same column contract as scheduled doses. */
    public function prnDoseRows(User $actor, MedicationReportPeriod $period, array $sites, ?int $clientId = null): array
    {
        $ids = $this->access->clientIds($actor, $sites);
        if ($clientId !== null) {
            abort_unless(in_array($clientId, $ids, true), 404);
            $ids = [$clientId];
        }
        $clients = Client::query()->whereIn('id', $ids)->with('site:id,name')->get()->keyBy('id');
        $query = $this->canonical(ClientMedicationAdministration::query()->effectiveClinicalEvidence(), $sites, $ids)
            ->whereHas('medication', fn ($q) => $q->where('is_prn', true))
            ->whereBetween('administered_at', $period->bounds())->with('medication')->orderBy('administered_at')->orderBy('id');
        if (! $actor->canDo('medications.controlled.view')) {
            $this->scope->scopeWithoutControlledMedicationRows($query);
        }
        $this->limit((clone $query)->count());

        return $query->get()->map(fn ($a) => $this->person($clients->get($a->client_id), $period, $actor) + [
            'reference' => 'administration:'.$a->id, 'date' => $a->administered_at->timezone('Pacific/Auckland')->toDateString(),
            'medicine' => $a->medication?->historicalDisplayName(), 'dose' => $a->dose_given, 'route' => $a->medication?->route,
            'status' => $a->status, 'due_at' => null, 'window_ends_at' => null, 'recorded_at' => $a->administered_at->toIso8601String(), 'late' => 0, 'away' => 0,
        ])->all();
    }

    /** P08b's reporter/account axis with current per-person reads on every export recheck. */
    public function errorQuery(User $actor, array $sites, ?array $clients = null): Builder
    {
        $query = $this->canonical(MedicationError::query(), $sites, $clients ?? $this->access->clientIds($actor, $sites), true);
        if (! $actor->canDo('medications.controlled.view')) {
            $this->scope->scopeWithoutControlledMedicationRows($query);
        }
        if (! $actor->canDo('medications.errors.manage') && ! $actor->canDo('medications.audit.view')) {
            $query->where(fn ($q) => $q->where('reported_by', $actor->id)
                ->orWhereHas('entries', fn ($entries) => $entries->where('kind', 'account')->where('actor_id', $actor->id)));
        }

        return $query;
    }

    private function canonical(Builder $query, array $sites, array $clients, bool $nullMedicine = false): Builder
    {
        return $this->scope->scopeCanonicalClientMedicationRows($query, $sites, $nullMedicine)->whereIn($query->qualifyColumn('client_id'), $clients);
    }

    private function person(?Client $client, MedicationReportPeriod $period, User $actor): array
    {
        $canOpen = $client && $actor->canDo('medications.view') && app(MarLinkService::class)->canOpen($actor, $client->id);

        return ['client_id' => $client?->id, 'person' => $client ? trim($client->first_name.' '.$client->last_name) : '', 'site' => $client?->site?->name, 'href' => $canOpen ? '/emar/mar?'.http_build_query(['client_id' => $client->id, 'date' => $period->from]) : null];
    }

    private function limit(int $count): void
    {
        abort_if($count > self::MAX_ROWS, 422, 'This period exceeds 100,000 source rows. Choose a shorter period; no partial result was created.');
    }
}
