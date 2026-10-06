<?php

namespace App\Services\Medication\Stock;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\MedicationPharmacyOrder;
use App\Models\MedicationStockCountRecord;
use App\Models\MedicationStockLot;
use App\Models\MedicationStockMovement;
use App\Models\User;
use App\Services\Medication\ControlledMedicationTransportWitnessService;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationRecordAccess;
use App\Services\Medication\WitnessPinService;
use App\Support\Medication\MedicationStockQuantity as Qty;
use App\Support\Medication\PharmacySupplyRules;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final class StockReadPayload
{
    public function __construct(private readonly MedicationGovernanceScopeService $scope, private readonly MedicationRecordAccess $access, private readonly MedicationStockService $stock) {}

    public function page(Request $request): array
    {
        $actor = $request->user();
        $site = $request->filled('site_id') ? $request->integer('site_id') : null;
        $clientId = null;
        if ($request->query->has('client_id')) {
            $clientId = filter_var($request->query('client_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            abort_unless(is_int($clientId), 404, 'The requested medication record was not found.');
        }
        $siteIds = $this->scope->readerSiteIds($actor, 'medications.view', $site, $clientId);
        $allSites = $siteIds;
        if ($site) {
            $siteIds = [$site];
        }
        $clientIds = $this->access->readableClientIds($actor, Client::whereIn('site_id', $siteIds)->pluck('id'));
        $selectedClient = null;
        if ($clientId !== null) {
            abort_unless(in_array($clientId, $clientIds, true), 404, 'The requested medication record was not found.');
            $selectedClient = $this->access->client($actor, $clientId);
            $clientIds = [$clientId];
        }
        $medications = ClientMedication::query()->current()->whereIn('client_id', $clientIds)
            ->when(! $actor->canDo('medications.controlled.view'), fn ($query) => $query->where('controlled_drug', false));
        $medicineId = $request->filled('medication_id') ? $request->integer('medication_id') : null;
        if ($medicineId !== null) {
            $selectedMedicine = $this->access->medication($actor, $medicineId);
            abort_if($clientId !== null && (int) $selectedMedicine->client_id !== $clientId, 404, 'The requested medication record was not found.');
            $medications->whereKey($medicineId);
        }
        $medicationIds = (clone $medications)->select('id');
        $stocks = ClientMedicationStock::whereIn('client_medication_id', $medicationIds);
        $lots = MedicationStockLot::whereIn('client_medication_stock_id', (clone $stocks)->select('id'));
        $search = mb_substr(trim($request->string('search')->toString()), 0, 100);
        $view = $request->string('view', 'stock')->toString();
        if (! in_array($view, ['stock', 'orders', 'counts', 'expiring', 'removals', 'movements'], true)) {
            $view = 'stock';
        }
        $show = $request->string('show', 'all')->toString();
        if (! in_array($show, ['all', 'out', 'low'], true)) {
            $show = 'all';
        }
        $quantitySql = StockAvailability::usableQuantitySql();
        $today = $this->stock->today();
        $itemsQuery = (clone $medications)
            ->when($view === 'expiring', fn ($q) => $q->whereHas('stock.lots', fn ($l) => $l->where('quantity_remaining', '>', 0)
                ->where('state', 'open')->whereNotNull('expiry_date')->where('expiry_date', '<=', CarbonImmutable::parse($this->stock->today())->addDays(30)->toDateString())))
            ->when($view === 'stock' && $show === 'out', fn ($q) => $q->whereHas('stock', fn ($s) => $s->whereRaw('('.$quantitySql.') <= 0', [$today])))
            ->when($view === 'stock' && $show === 'low', fn ($q) => $q->whereHas('stock', fn ($s) => $s->whereNotNull('reorder_level')->whereRaw('('.$quantitySql.') <= client_medication_stocks.reorder_level', [$today])));
        $items = $itemsQuery->with(['client.site', 'stock.lots'])
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')
                ->orWhereHas('client', fn ($c) => $c->where('first_name', 'like', '%'.$search.'%')->orWhere('last_name', 'like', '%'.$search.'%'))
                ->orWhereHas('stock.lots', fn ($l) => $l->where('batch_number', 'like', '%'.$search.'%'))))
            ->orderBy('name')->orderBy('id')->paginate(25, ['*'], 'items_page')->withQueryString();
        $items->through(fn ($med) => $this->item($med));
        $orders = MedicationPharmacyOrder::whereIn('client_id', $clientIds)->whereIn('client_medication_id', $medicationIds)
            ->whereHas('medication', fn ($q) => $q->whereColumn('client_medications.client_id', 'medication_pharmacy_orders.client_id'));
        $ordersPage = (clone $orders)->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('pharmacy_name', 'like', '%'.$search.'%')->orWhereHas('medication', fn ($m) => $m->where('name', 'like', '%'.$search.'%'))))->with(['client', 'medication'])->latest('id')->paginate(25, ['*'], 'orders_page')->withQueryString();
        $ordersPage->through(fn ($o) => [
            ...$o->only(['id', 'client_medication_id', 'pharmacy_name', 'status', 'quantity_ordered', 'quantity_received', 'quantity_dispensed', 'order_notes',
                'needed_by', 'expected_delivery', 'communication_method', 'communication_reference', 'communication_recorded_at', 'closure_reason', 'batch_number', 'batch_expiry']),
            'medication_name' => $o->medication->name, 'client_name' => $o->client->full_name, 'controlled' => (bool) $o->medication->controlled_drug,
        ]);
        $counts = MedicationStockCountRecord::whereIn('client_medication_stock_id', (clone $stocks)->select('id'));
        $countsPage = (clone $counts)->when($search !== '', fn ($q) => $q->whereHas('stock.medication', fn ($m) => $m->where('name', 'like', '%'.$search.'%')))->with(['stock.medication.client', 'countedBy'])->latest('id')->paginate(25, ['*'], 'counts_page')->withQueryString();
        $countsPage->through(fn ($c) => $this->countPresentation($c));
        $focusedCount = $request->filled('count_id') ? $this->countPresentation((clone $counts)
            ->with(['stock.medication.client', 'countedBy'])->findOrFail($request->integer('count_id'))) : null;
        $movements = MedicationStockMovement::query()->when($search !== '', fn ($q) => $q->whereHas('lot.stock.medication', fn ($m) => $m->where('name', 'like', '%'.$search.'%')))->whereIn('medication_stock_lot_id', (clone $lots)->select('id'))->with(['lot.stock.medication.client', 'recordedBy'])
            ->when($view === 'removals', fn ($q) => $q->whereIn('kind', ['returned_pharmacy', 'removed_expired', 'damaged', 'quarantined']))
            ->latest('id')->paginate(25, ['*'], 'moves_page')->withQueryString();
        $movements->through(fn ($m) => [...$m->only(['id', 'kind', 'quantity', 'balance_before', 'balance_after', 'reason', 'notes', 'recorded_at', 'administration_id', 'controlled_entry_id', 'destruction_id', 'transit_log_id', 'stock_count_record_id', 'reverses_movement_id', 'return_of_id']),
            'medication_id' => $m->lot->stock->client_medication_id, 'medication_name' => $m->lot->stock->medication->name,
            'client_name' => $m->lot->stock->medication->client->full_name, 'recorded_by_name' => $m->recordedBy?->name]);

        return [
            'items' => $items, 'orders' => $ordersPage, 'counts' => $countsPage, 'movements' => $movements, 'focused_count' => $focusedCount,
            'sites' => $this->scope->sitePicker($allSites)->map->only(['id', 'name']),
            'pharmacies' => (clone $orders)->distinct()->orderBy('pharmacy_name')->pluck('pharmacy_name')->filter()->values(),
            'filters' => ['view' => $view, 'search' => $search, 'site_id' => $site, 'client_id' => $clientId, 'show' => $show, 'medication_id' => $medicineId],
            'selected_client' => $selectedClient ? ['id' => (int) $selectedClient->id, 'name' => $selectedClient->full_name] : null,
            'metrics' => [
                'tracked' => (clone $stocks)->count(), 'out' => (clone $stocks)->whereRaw('('.$quantitySql.') <= 0', [$today])->count(),
                'expiring' => (clone $lots)->where('quantity_remaining', '>', 0)->where('state', 'open')->whereNotNull('expiry_date')->where('expiry_date', '<=', CarbonImmutable::parse($this->stock->today())->addDays(30)->toDateString())->count(),
                'orders' => (clone $orders)->whereNotIn('status', PharmacySupplyRules::CLOSED)->count(),
                'counts' => (clone $counts)->where('state', 'needs_review')->count(),
            ],
            // Permission scope stays unchanged pending the exact receive-role grant.
            'can' => ['receive' => config('medications.stock_lots_enabled', false) && $actor->canDo('medications.stock.update'), 'manage' => config('medications.stock_lots_enabled', false) && $actor->canDo('medications.stock.update'), 'receive_controlled' => config('medications.stock_lots_enabled', false) && $actor->canDo('medications.stock.update') && $actor->canDo('medications.controlled.view') && $actor->canDo('medications.controlled.record') && $siteIds !== [], 'controlled' => $actor->canDo('medications.controlled.view')],
            'lots_enabled' => (bool) config('medications.stock_lots_enabled', false),
        ];
    }

    public function detail(User $actor, int $medicationId): array
    {
        $med = $this->access->medication($actor, $medicationId);
        abort_if($med->controlled_drug && ! $actor->canDo('medications.controlled.view'), 404);
        $med->load(['client.site', 'stock.lots.photos.takenBy']);
        $item = $this->item($med);
        $item['current_user_id'] = (int) $actor->id;
        $item['witnesses'] = [];
        if ($med->controlled_drug) {
            $eligible = app(ControlledMedicationTransportWitnessService::class)
                ->eligibleWitnessesForSite((int) $med->client->site_id, now(), (int) $actor->id);
            $statuses = app(WitnessPinService::class)->statuses($eligible->pluck('id'));
            $item['witnesses'] = $eligible->map(fn ($user) => [
                'id' => $user->id, 'name' => $user->name,
                'eligible' => ($statuses[$user->id] ?? '') === WitnessPinService::STATUS_SET,
                'reason' => ($statuses[$user->id] ?? '') === WitnessPinService::STATUS_SET ? null : 'A usable witness PIN is needed',
                'pin_status' => $statuses[$user->id] ?? WitnessPinService::STATUS_NOT_SET,
            ])->values()->all();
        }
        $item['packs'] = $med->stock?->lots->map(fn ($lot) => [
            ...$lot->only(['id', 'batch_number', 'batch_not_printed', 'expiry_date', 'expiry_not_printed', 'quantity_received', 'quantity_remaining', 'controlled_entry_id', 'state', 'source', 'source_reference', 'received_at', 'short_expiry_reason', 'revision', 'notes']),
            'photos' => $lot->photos->map(fn ($photo) => [
                ...$photo->only(['id', 'original_name', 'mime', 'bytes', 'taken_at', 'description']),
                'taken_by_name' => $photo->takenBy?->name, 'preview_url' => route('emar.stock.photos.view', $photo->id), 'download_url' => route('emar.stock.photos.download', $photo->id),
            ])->all(),
        ])->all() ?? [];
        $item['outward'] = $med->stock ? MedicationStockMovement::where('kind', 'going_out')
            ->whereHas('lot', fn ($q) => $q->where('client_medication_stock_id', $med->stock->id))->whereDoesntHave('returns')
            ->get(['id', 'quantity', 'medication_stock_lot_id', 'recorded_at', 'reason'])->toArray() : [];

        $recentMoves = $med->stock ? MedicationStockMovement::whereHas('lot', fn ($q) => $q->where('client_medication_stock_id', $med->stock->id))
            ->with('recordedBy')->latest('id')->limit(51)->get() : collect();
        $item['history_truncated'] = $recentMoves->count() > 50;
        $item['movements'] = $recentMoves->take(50)->map(fn ($move) => [
            ...$move->only(['id', 'kind', 'quantity', 'balance_before', 'balance_after', 'reason', 'notes', 'recorded_at', 'administration_id', 'controlled_entry_id', 'destruction_id', 'transit_log_id', 'stock_count_record_id', 'reverses_movement_id', 'return_of_id']),
            'medication_id' => $med->id, 'medication_name' => $med->name, 'client_name' => $med->client->full_name,
            'recorded_by_name' => $move->recordedBy?->name,
        ])->values()->all();
        $item['orders'] = MedicationPharmacyOrder::where('client_id', $med->client_id)->where('client_medication_id', $med->id)
            ->latest('id')->limit(50)->get()->map(fn ($supply) => [
                ...$supply->only(['id', 'client_medication_id', 'pharmacy_name', 'status', 'quantity_ordered', 'quantity_received',
                    'quantity_dispensed', 'order_notes', 'needed_by', 'expected_delivery', 'communication_method',
                    'communication_reference', 'communication_recorded_at', 'closure_reason', 'batch_number', 'batch_expiry']),
                'medication_name' => $med->name, 'client_name' => $med->client->full_name, 'controlled' => (bool) $med->controlled_drug,
            ])->all();

        return $item;
    }

    private function countPresentation(MedicationStockCountRecord $count): array
    {
        return [...$count->only(['id', 'state', 'lines', 'reason', 'counted_at', 'review_reason']),
            'medication_id' => $count->stock->client_medication_id, 'medication_name' => $count->stock->medication->name,
            'client_name' => $count->stock->medication->client->full_name, 'counted_by_name' => $count->countedBy?->name];
    }

    private function item(ClientMedication $med): array
    {
        $stock = $med->stock;
        $lots = $stock?->lots ?? collect();
        $started = $stock !== null && $stock->lots_started_at !== null;
        $onHand = $stock?->availableQuantity();
        $expired = $stock?->packExpiries(true)->count() ?? 0;
        $next = $stock && $started ? app(StockAvailability::class)->nextUsablePack($stock) : null;
        $state = $onHand === null ? 'unknown' : (Qty::equals($onHand, 0) ? 'out' : ($expired ? 'expired' : ($stock?->reorder_level !== null && Qty::lessThanOrEqual($onHand, $stock->reorder_level) ? 'low' : 'ok')));

        return [
            'id' => $med->id, 'name' => $med->name, 'client_name' => $med->client->full_name, 'site_name' => $med->client->site?->name,
            'controlled' => (bool) $med->controlled_drug, 'active' => (bool) $med->active && $med->state === 'active',
            'stock_id' => $stock?->id, 'physical_on_hand' => $stock?->on_hand, 'usable_on_hand' => $stock ? app(StockAvailability::class)->usableQuantity($stock) : null, 'on_hand' => $onHand === null ? null : Qty::toFloat($onHand), 'unit' => $stock?->unit,
            'reorder_level' => $stock?->reorder_level, 'last_counted_at' => $stock?->last_counted_at, 'lots_started' => $started,
            'pack_count' => $lots->where('quantity_remaining', '>', 0)->count(), 'next_batch' => $stock?->currentBatchNumber(),
            'next_expiry' => $stock?->currentExpiryDate()?->toDateString(), 'expired_packs' => $expired, 'state' => $state,
            'days_supply' => null, // Requires P01's explicit same-unit daily stock-use contract; never guess mg -> tablets.
        ];
    }
}
