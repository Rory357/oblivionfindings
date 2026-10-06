<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Services\Emar\MedsBoardPayloadService;
use App\Services\MarScheduleService;
use App\Services\Medication\DoseSlots\DoseSlotCoverage;
use App\Services\Medication\Followups\MedicationFollowupService;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationRecordAccess;
use App\Services\Medication\MedicationRecordDayService;
use App\Services\Medication\MedicationScopeDecisionService;
use App\Services\Medication\SecondPersonConfirmationPayload;
use App\Services\Medication\Support\MedicationSupport;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;

/** Medication work only; every list follows current person access and canonical ownership. */
final class MedicationRecordHubController extends Controller
{
    public function show(Request $request, string $view)
    {
        $actor = $request->user();
        abort_unless($actor && in_array($view, ['charts', 'medicines', 'asneeded'], true), 403);
        $now = Carbon::now(app(MarScheduleService::class)->workerTimezone());
        $data = $request->validate(['site_id' => ['nullable', 'integer', 'min:1'], 'client_id' => ['nullable', 'integer', 'min:1'],
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$now->toDateString()], 'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:current,stopped'], 'range' => ['nullable', 'integer', 'in:7,30,90'], 'page' => ['nullable', 'integer', 'min:1']]);
        $scope = app(MedicationGovernanceScopeService::class);
        $access = app(MedicationRecordAccess::class);
        $siteId = $request->integer('site_id') ?: null;
        $clientId = $request->integer('client_id') ?: null;
        $sites = $scope->readerSiteIds($actor, 'medications.view', $siteId, $clientId);
        if ($clientId) {
            $access->client($actor, $clientId);
        }
        $ids = $access->readableClientIds($actor, Client::query()->whereIn('site_id', $siteId ? [$siteId] : $sites)->when($clientId, fn ($q) => $q->whereKey($clientId))->pluck('id'));
        $people = Client::whereIn('id', $ids)->with('site:id,name')->get()->keyBy('id');
        $controlled = $actor->canDo('medications.controlled.view');
        $orders = ClientMedication::withTrashed()->whereIn('client_id', $ids)->when(! $controlled, fn ($q) => $q->where('controlled_drug', false))->orderBy('name')->get();
        $current = $orders->reject(fn ($order) => $this->stopped($order));
        $date = $data['date'] ?? $now->toDateString();
        $day = Carbon::parse($date, $now->timezone)->startOfDay();
        $board = app(MedsBoardPayloadService::class);
        $scheduleRows = function (Carbon $forDay) use ($board, $ids, $controlled, $now) {
            if (! app(DoseSlotCoverage::class)->forPeriod($forDay->toDateString(), CarbonImmutable::now())['complete']) {
                return collect();
            }
            $bySlot = $board->slotIndex($board->administrationsForDay($ids, $forDay, $controlled));
            $hidden = [];

            return collect($forDay->toDateString() < $now->toDateString()
                ? app(MedicationRecordDayService::class)->scheduled($ids, $forDay, $now, $bySlot, $controlled, $hidden)
                : $board->scheduleForDate($ids, $forDay, $now, $bySlot, $controlled, $hidden));
        };
        $todayRows = $scheduleRows($now->copy()->startOfDay());
        $query = mb_strtolower(trim($data['q'] ?? ''));
        if ($view === 'charts') {
            $scheduled = $date === $now->toDateString() ? $todayRows : $scheduleRows($day);
            $prn = $date === $now->toDateString() ? collect($board->prnMedications($ids, $now, $controlled)) : collect(app(MedicationRecordDayService::class)->prn($ids, $day))->when(! $controlled, fn ($items) => $items->where('is_controlled', false));
            $workIds = $scheduled->pluck('client_id')->merge($prn->pluck('client_id'));
            if ($date === $now->toDateString()) {
                $workIds = $workIds->merge($current->pluck('client_id'));
            }
            $authority = $actor->canDo('medications.administer.record') ? app(MedicationScopeDecisionService::class)->clientIdsWithCurrentAuthority($actor, $ids, $now) : [];
            $rows = $people->only($workIds->unique()->all())->map(function ($person) use ($scheduled, $date, $now, $authority) {
                $doses = $scheduled->where('client_id', $person->id);
                $staff = $doses->reject(fn ($row) => in_array($row['state'] ?? '', ['self_managed', 'away', 'pending_check', 'not_due', 'upcoming'], true));
                $next = $doses->filter(fn ($row) => in_array($row['state'] ?? '', ['not_due', 'upcoming'], true))->sortBy('scheduled_for')->first();

                return $this->identity($person) + ['key' => 'person'.$person->id, 'id' => $person->id, 'href' => '/emar/mar?client_id='.$person->id.'&date='.$date,
                    'due' => $doses->filter(fn ($row) => ($row['state'] ?? '') === 'due' && $row['recorded'] === null)->count(), 'overdue' => $doses->where('status', 'overdue')->count(),
                    'recorded' => $staff->filter(fn ($row) => $row['recorded'] !== null)->count(), 'so_far' => $staff->count(), 'waiting' => $doses->where('status', 'pending_check')->count(), 'next' => $next['time'] ?? null,
                    'can_record' => $date === $now->toDateString() && in_array((int) $person->id, $authority, true)];
            })->filter(fn ($row) => ! $query || str_contains(mb_strtolower($row['person']), $query))->sortBy('person')->values();
        } elseif ($view === 'medicines') {
            $policy = app(MedicationSupport::class);
            $words = ['self_managed' => 'Self-managed', 'prompted' => 'Prompt', 'assisted' => 'Assist', 'staff_given' => 'Administer'];
            $rows = $orders->filter(fn ($order) => $this->stopped($order) === (($data['status'] ?? 'current') === 'stopped'))->map(function ($order) use ($people, $policy, $words) {
                $person = $people->get($order->client_id);

                return $this->identity($person) + ['key' => 'medicine'.$order->id, 'id' => $order->id, 'name' => $order->historicalDisplayName(), 'dosage' => $order->dosage, 'route' => $order->route,
                    'when' => $order->is_prn ? 'As needed' : collect($order->dose_times ?? [])->join(' · '), 'support' => $words[$policy->mode($order)] ?? 'Administer',
                    'state' => $this->stopped($order) ? 'Stopped / replaced' : (! $order->isVerifiedForAdministration() ? 'awaiting' : ($order->state === 'paused' || ! $order->active ? 'Paused' : 'Active')),
                    'href' => '/emar/mar?client_id='.$person->id.'&tab=medicines&'.($this->stopped($order) ? 'view=stopped&' : '').'medication_id='.$order->id];
            })->filter(fn ($row) => ! $query || str_contains(mb_strtolower($row['person'].' '.$row['name']), $query))->values();
        } else {
            $from = $day->copy()->subDays(($data['range'] ?? 30) - 1)->utc();
            $rows = $scope->scopeCanonicalClientMedicationRows(ClientMedicationAdministration::query()->effectiveClinicalEvidence()->whereIn('client_id', $ids), $siteId ? [$siteId] : $sites, false)
                ->where('status', 'given')->whereHas('medication', fn ($q) => $q->where('is_prn', true))
                ->when(! $controlled, fn ($q) => $scope->scopeWithoutControlledMedicationRows($q))->whereBetween('administered_at', [$from, $day->copy()->endOfDay()->utc()])
                ->when($query, fn ($q) => $q->where(fn ($q) => $q->whereHas('medication', fn ($med) => $med->where('name', 'like', '%'.$query.'%'))->orWhereHas('client', fn ($person) => $person->where('first_name', 'like', '%'.$query.'%')->orWhere('last_name', 'like', '%'.$query.'%'))))
                ->with(['medication', 'administeredBy:id,name', 'prnEffectiveness', SecondPersonConfirmationPayload::RELATION])->latest('administered_at')->latest('id')->paginate(25)->withQueryString();
            $followups = app(MedicationFollowupService::class)->visibleQuery($actor)->where('type', 'effect')->whereIn('administration_id', $rows->pluck('id'))->get()->keyBy('administration_id');
            $rows->setCollection($rows->getCollection()->map(function ($dose) use ($people, $followups, $actor) {
                $effect = $dose->prnEffectiveness;
                if ($effect && ((int) $effect->client_id !== (int) $dose->client_id || (int) $effect->client_medication_id !== (int) $dose->client_medication_id)) {
                    $effect = null;
                }
                $followup = $followups->get($dose->id);

                return $this->identity($people->get($dose->client_id)) + ['key' => 'prn'.$dose->id, 'id' => $dose->id, 'name' => $dose->medication->historicalDisplayName(),
                    'at' => $dose->administered_at->toIso8601String(), 'by' => $dose->administeredBy?->name, 'dose' => $dose->dose_given, 'reason' => $dose->reason,
                    ...app(SecondPersonConfirmationPayload::class)->forAdministration($dose),
                    'effect_status' => $effect ? 'recorded' : 'pending', 'effect' => $effect ? $effect->effectiveness_label.' · '.($effect->observations ?? '') : ($followup?->due_at ? 'Due '.$followup->due_at->timezone('Pacific/Auckland')->format('j M, g:i a') : 'Time not set'),
                    'can_record' => ! $effect && $actor->canDo('medications.administer.record'), 'href' => '/emar/mar?client_id='.$dose->client_id.'&tab=history&dose_id='.$dose->id];
            }));
        }
        $pageNumber = $data['page'] ?? 1;
        $page = $rows instanceof LengthAwarePaginator ? $rows : new LengthAwarePaginator($rows->forPage($pageNumber, 25)->values(), $rows->count(), 25, $pageNumber, ['path' => $request->url(), 'query' => $request->query()]);

        return Inertia::render('emar/record/hub', ['view' => $view, 'filters' => ['site_id' => $siteId, 'client_id' => $clientId, 'date' => $date, 'q' => $data['q'] ?? '', 'status' => $data['status'] ?? 'current', 'range' => $data['range'] ?? 30],
            'page' => $page, 'sites' => $scope->sitePicker($sites)->map->only(['id', 'name'])->values(), 'today' => $now->toDateString(), 'as_at' => $now->toIso8601String(), 'controlled_left_out' => ! $controlled,
            'can_report' => $actor->canDo('medications.reports.view') && $actor->canDo('medications.reports.export'),
            'coverage' => app(DoseSlotCoverage::class)->forPeriod($date, CarbonImmutable::now()), 'meters' => ['people' => $current->pluck('client_id')->unique()->count(), 'medicines' => $current->count(),
                'due' => $todayRows->filter(fn ($row) => ($row['state'] ?? '') === 'due' && $row['recorded'] === null)->count(), 'overdue' => $todayRows->where('status', 'overdue')->count()]]);
    }

    private function identity(Client $person): array
    {
        return ['client_id' => (int) $person->id, 'person' => trim($person->first_name.' '.$person->last_name), 'house' => $person->site?->name];
    }

    private function stopped(ClientMedication $order): bool
    {
        return $order->trashed() || $order->superseded_by !== null || $order->ceased_at !== null || $order->state === 'ceased';
    }
}
