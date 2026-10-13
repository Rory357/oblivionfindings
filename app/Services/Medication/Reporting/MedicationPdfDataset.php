<?php

namespace App\Services\Medication\Reporting;

use App\Models\Client;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationRound;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\ClientAllergyRecordService;
use App\Services\Medication\MedicationGovernanceScopeService;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

/** Buffered PDF evidence, shared with its fresh release check. No live record writes. */
final class MedicationPdfDataset
{
    public function read(User $actor, string $type, MedicationReportPeriod $period, array $sites, ?int $clientId, ?int $medicineId, bool $includePrn = true, ?int $roundId = null): array
    {
        $access = app(MedicationReportAccess::class);
        abort_unless($access->canExport($actor, $type), 403);
        $approved = $access->siteIds($actor, null, $clientId, $type === 'cd_register' ? 'controlled' : 'doses');
        abort_if(array_diff($sites, $approved) !== [], 404);
        $start = CarbonImmutable::parse($period->from, 'Pacific/Auckland');
        abort_if($start->diffInDays(CarbonImmutable::parse($period->to, 'Pacific/Auckland')) >= 31, 422, 'PDF records support up to 31 NZ calendar days. Choose a shorter period.');
        $clientIds = $access->clientIds($actor, $sites);
        if ($clientId !== null) {
            abort_unless(in_array($clientId, $clientIds, true), 404);
            $clientIds = [$clientId];
        }
        $result = ['title' => match ($type) {
            'mar' => 'Medication Administration Record', 'cd_register' => 'Controlled Drug Register', default => 'Round Sheet'
        }, 'scope' => Site::query()->whereIn('id', $sites)->orderBy('id')->pluck('name')->implode(', '), 'period' => [$period->from, $period->to], 'chart' => [], 'dates' => [], 'orders' => [], 'allergies' => [], 'columns' => [], 'rows' => [], 'notes' => []];
        if ($type === 'mar') {
            abort_unless($clientId, 422, 'Choose one person for a MAR chart.');
            $client = Client::findOrFail($clientId);
            $result['scope'] = trim($client->first_name.' '.$client->last_name).' · '.$result['scope'];
            $allergies = app(ClientAllergyRecordService::class)->summary($client);
            $result['allergies'] = array_map(fn ($a) => $a['allergen'].($a['severity'] ? ' ('.$a['severity'].')' : '').($a['reaction'] ? ' · '.$a['reaction'] : ''), $allergies['entries']);
            $result['allergies'][] = $allergies['reviewed'] ? ($allergies['status'] === 'no_known' ? 'No known allergies — reviewed' : 'Allergy record reviewed') : 'Allergy record not reviewed';
            $slots = app(MedicationReportDataset::class)->doseRows($actor, $period, $sites, $clientId);
            $orders = ClientMedication::withTrashed()->where('client_id', $clientId)
                ->where(fn ($q) => $q->whereNull('start_date')->orWhere('start_date', '<=', $period->to))
                ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $period->from))
                ->where(fn ($q) => $q->whereNull('ceased_at')->orWhere('ceased_at', '>=', $period->bounds()[0]))
                ->where(fn ($q) => $q->whereNull('superseded_at')->orWhere('superseded_at', '>=', $period->bounds()[0]))
                ->where(fn ($q) => $q->whereNull('deleted_at')->orWhere('deleted_at', '>=', $period->bounds()[0]))
                ->when(! $actor->canDo('medications.controlled.view'), fn ($q) => $q->where('controlled_drug', false))
                ->when(! $includePrn, fn ($q) => $q->where('is_prn', false))
                ->orderBy('id')->get();
            // Ceased/superseded orders stay in the period; active-only is never
            // an acceptable historical MAR filter. The slots supply each day.
            $result['orders'] = $orders->map(fn ($m) => ['medicine' => $m->historicalDisplayName(), 'dose' => $m->dosage, 'route' => $m->route, 'as_needed' => (bool) $m->is_prn, 'start' => $m->start_date?->toDateString(), 'end' => $m->end_date?->toDateString(), 'ceased_at' => $m->ceased_at?->toIso8601String(), 'superseded_at' => $m->superseded_at?->toIso8601String()])->all();
            $result['dates'] = collect(CarbonPeriod::create($period->from, $period->to))->map(fn ($d) => $d->toDateString())->all();
            $result['chart'] = collect($slots)->groupBy(fn ($r) => ($r['order_reference'] ?? $r['reference']).'|'.($r['version_reference'] ?? 'unknown').'|'.CarbonImmutable::parse($r['due_at'])->timezone('Pacific/Auckland')->format('H:i'))->map(fn ($group) => ['medicine' => $group[0]['medicine'], 'dose' => $group[0]['dose'], 'route' => $group[0]['route'], 'time' => CarbonImmutable::parse($group[0]['due_at'])->timezone('Pacific/Auckland')->format('H:i'), 'days' => $group->groupBy('date')->map(fn ($day) => $day->pluck('status')->all())->all()])->values()->all();
            $prn = app(MedicationGovernanceScopeService::class)->scopeCanonicalClientMedicationRows(ClientMedicationAdministration::query()->effectiveClinicalEvidence(), $sites, false)
                ->where('client_id', $clientId)->whereHas('medication', fn ($q) => $q->where('is_prn', true))
                ->whereBetween('administered_at', $period->bounds())->with('medication')->orderBy('administered_at')->orderBy('id')
                ->when(! $actor->canDo('medications.controlled.view'), fn ($q) => app(MedicationGovernanceScopeService::class)->scopeWithoutControlledMedicationRows($q))
                ->when(! $includePrn, fn ($q) => $q->whereRaw('1 = 0'))->get();
            $result['columns'] = ['When (NZDT/NZST)', 'As-needed medicine', 'Recorded result'];
            $result['rows'] = $prn->map(fn ($a) => [$a->administered_at->timezone('Pacific/Auckland')->format('j M Y H:i T'), $a->medication?->historicalDisplayName(), $a->status])->all();
            $result['notes'] = ['The chart shows recorded scheduled slots, including Away and Not recorded. A blank cell means no slot is available, not that a dose was given.', 'Dose and route use the immutable version checked by the due time. Not recorded means no such version evidence is available; no historical instruction has been guessed.', 'Ceased and superseded orders in this period are listed below. Current order instructions are shown separately from each day’s dose evidence.'];
        } elseif ($type === 'cd_register') {
            abort_unless($medicineId && $clientId, 422, 'Choose one person and controlled medicine.');
            $medicine = ClientMedication::withTrashed()->where('client_id', $clientId)->where('controlled_drug', true)->findOrFail($medicineId);
            $result['scope'] .= ' · '.trim($medicine->client->first_name.' '.$medicine->client->last_name).' · '.$medicine->historicalDisplayName();
            $query = app(MedicationGovernanceScopeService::class)->scopeCanonicalClientMedicationRows(ClientControlledDrugEntry::query(), $sites, false)->where('client_id', $clientId)->where('client_medication_id', $medicineId);
            $opening = (clone $query)->where('recorded_at', '<', $period->bounds()[0])->orderByDesc('recorded_at')->orderByDesc('id')->first();
            $entries = (clone $query)->whereBetween('recorded_at', $period->bounds())->with(['recordedBy', 'witnessedBy'])->orderBy('recorded_at')->orderBy('id')->get();
            $result['columns'] = ['When (NZDT/NZST)', 'Movement', 'Quantity', 'Unit', 'Balance', 'Recorded by', 'Witness'];
            $result['rows'] = $entries->map(fn ($e) => [$e->recorded_at->timezone('Pacific/Auckland')->format('j M Y H:i T'), $e->entry_type, $e->quantity, $e->unit, $e->on_hand_after, $e->recordedBy?->name, $e->witnessedBy?->name])->all();
            $result['notes'] = ['Opening balance: '.($opening?->on_hand_after ?? $entries->first()?->on_hand_before ?? 'Not recorded'), 'Closing balance: '.($entries->last()?->on_hand_after ?? $opening?->on_hand_after ?? 'Not recorded')];
        } else {
            abort_unless(count($sites) === 1 && $period->from === $period->to, 422, 'A round sheet needs one house and one NZ day.');
            $round = $roundId !== null ? MedicationRound::query()->whereIn('site_id', $sites)->findOrFail($roundId) : null;
            if ($round !== null) {
                abort_unless($round->round_date->toDateString() === $period->from, 404);
                $result['scope'] .= ' · '.$round->name;
                $result['round'] = ['id' => $round->id, 'site_id' => $round->site_id, 'service_context_id' => $round->service_context_id,
                    'scheduled_time' => $round->scheduled_time, 'window_minutes' => $round->windowMinutes()];
            }
            $rows = app(MedicationReportDataset::class)->doseRows($actor, $period, $sites, $clientId, $roundId);
            $result['columns'] = ['Person', 'Due (NZDT/NZST)', 'Medicine', 'Dose / route', 'Recorded result', 'Recorded (NZDT/NZST)'];
            $result['rows'] = array_map(fn ($r) => [$r['person'], CarbonImmutable::parse($r['due_at'])->timezone('Pacific/Auckland')->format('H:i T'), $r['medicine'], $r['dose'].' / '.$r['route'], $r['status'], $r['recorded_at'] ? CarbonImmutable::parse($r['recorded_at'])->timezone('Pacific/Auckland')->format('H:i T') : '—'], $rows);
            $result['notes'] = [($round !== null ? 'Selected round and its scheduled window on one NZ day.' : 'One house and one selected NZ day.').' Rows come from the same scheduled-dose projection as the medication record.', 'This printout is a record snapshot. Record care in eMAR; do not use a paper signature to alter the electronic record.'];
        }
        abort_if(count($result['rows']) > 2000 || count($result['chart']) > 1000, 422, 'This PDF exceeds the supported page size. Choose a shorter period or make a CSV.');

        return $result;
    }
}
