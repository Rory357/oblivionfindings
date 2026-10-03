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
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationRecordAccess;
use App\Support\Medication\MedicationStockQuantity as Qty;
use App\Support\Medication\PharmacySupplyRules;
use App\Support\Medication\StockLotRules;
use Illuminate\Http\Request;

final class StockReadPayload
{
    public function __construct(private readonly MedicationGovernanceScopeService $scope, private readonly MedicationRecordAccess $access, private readonly MedicationStockService $stock) {}

    public function page(Request $request): array
    {
        $actor = $request->user();
        $site = $request->filled('site_id') ? $request->integer('site_id') : null;
        $siteIds = $this->scope->readerSiteIds($actor, 'medications.view', $site);
        $allSites = $siteIds;
        if ($site) {
            $siteIds = [$site];
        }
        $clientIds = $this->access->readableClientIds($actor, Client::whereIn('site_id', $siteIds)->pluck('id'));
        $medications = ClientMedication::query()->current()->whereIn('client_id', $clientIds)
            ->when(! $actor->canDo('medications.controlled.view'), fn ($query) => $query->where('controlled_drug', false));
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
        $itemsQuery = (clone $medications)
            ->when($view === 'expiring', fn ($q) => $q->whereHas('stock.lots', fn ($l) => $l->where('quantity_remaining', '>', 0)
                ->whereNotNull('expiry_date')->where('expiry_date', '<=', \Carbon\CarbonImmutable::parse($this->stock->today())->addDays(30)->toDateString())))
            ->when($view === 'stock' && $show === 'out', fn ($q) => $q->whereHas('stock', fn ($s) => $s->whereNotNull('on_hand')->where('on_hand', '<=', 0)))
            ->when($view === 'stock' && $show === 'low', fn ($q) => $q->whereHas('stock', fn ($s) => $s->whereNotNull('reorder_level')->whereColumn('on_hand', '<=', 'reorder_level')));
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
        $countsPage->through(fn ($c) => [...$c->only(['id', 'state', 'lines', 'reason', 'counted_at', 'review_reason']), 'medication_id' => $c->stock->client_medication_id,
            'medication_name' => $c->stock->medication->name, 'client_name' => $c->stock->medication->client->full_name, 'counted_by_name' => $c->countedBy?->name]);
        $movements = MedicationStockMovement::query()->when($search !== '', fn ($q) => $q->whereHas('lot.stock.medication', fn ($m) => $m->where('name', 'like', '%'.$search.'%')))->whereIn('medication_stock_lot_id', (clone $lots)->select('id'))->with(['lot.stock.medication.client', 'recordedBy'])
            ->when($view === 'removals', fn ($q) => $q->whereIn('kind', ['returned_pharmacy', 'removed_expired', 'damaged', 'quarantined']))
            ->latest('id')->paginate(25, ['*'], 'moves_page')->withQueryString();
        $movements->through(fn ($m) => [...$m->only(['id', 'kind', 'quantity', 'balance_before', 'balance_after', 'reason', 'notes', 'recorded_at']),
            'medication_id' => $m->lot->stock->client_medication_id, 'medication_name' => $m->lot->stock->medication->name,
            'client_name' => $m->lot->stock->medication->client->full_name, 'recorded_by_name' => $m->recordedBy?->name]);

        return [
            'items' => $items, 'orders' => $ordersPage, 'counts' => $countsPage, 'movements' => $movements,
            'sites' => $this->scope->sitePicker($allSites)->map->only(['id', 'name']),
            'pharmacies' => (clone $orders)->distinct()->orderBy('pharmacy_name')->pluck('pharmacy_name')->filter()->values(),
            'filters' => ['view' => $view, 'search' => $search, 'site_id' => $site, 'show' => $show],
            'metrics' => [
                'tracked' => (clone $stocks)->count(), 'out' => (clone $stocks)->whereNotNull('on_hand')->where('on_hand', '<=', 0)->count(),
                'expiring' => (clone $lots)->where('quantity_remaining', '>', 0)->whereNotNull('expiry_date')->where('expiry_date', '<=', \Carbon\CarbonImmutable::parse($this->stock->today())->addDays(30)->toDateString())->count(),
                'orders' => (clone $orders)->whereNotIn('status', PharmacySupplyRules::CLOSED)->count(),
                'counts' => (clone $counts)->where('state', 'needs_review')->count(),
            ],
            // Permission scope stays unchanged pending the exact receive-role grant.
            'can' => ['receive' => config('medications.stock_lots_enabled', false) && $actor->canDo('medications.stock.update'), 'manage' => config('medications.stock_lots_enabled', false) && $actor->canDo('medications.stock.update'), 'controlled' => $actor->canDo('medications.controlled.view')],
            'lots_enabled' => (bool) config('medications.stock_lots_enabled', false),
        ];
    }

    public function detail(User $actor, int $medicationId): array
    {
        $med = $this->access->medication($actor, $medicationId);
        abort_if($med->controlled_drug && ! $actor->canDo('medications.controlled.view'), 404);
        $med->load(['client.site', 'stock.lots.photos.takenBy']);
        $item = $this->item($med);
        $item['packs'] = $med->stock?->lots->map(fn ($lot) => [
            ...$lot->only(['id', 'batch_number', 'batch_not_printed', 'expiry_date', 'expiry_not_printed', 'quantity_received', 'quantity_remaining', 'state', 'source', 'source_reference', 'received_at', 'short_expiry_reason', 'revision', 'notes']),
            'photos' => $lot->photos->map(fn ($photo) => [
                ...$photo->only(['id', 'original_name', 'mime', 'bytes', 'taken_at', 'description']),
                'taken_by_name' => $photo->takenBy?->name, 'preview_url' => route('emar.stock.photos.view', $photo->id), 'download_url' => route('emar.stock.photos.download', $photo->id),
            ])->all(),
        ])->all() ?? [];
        $item['outward'] = $med->stock ? MedicationStockMovement::where('kind', 'going_out')
            ->whereHas('lot', fn ($q) => $q->where('client_medication_stock_id', $med->stock->id))->whereDoesntHave('returns')
            ->get(['id', 'quantity', 'medication_stock_lot_id', 'recorded_at', 'reason'])->toArray() : [];

        return $item;
    }

    private function item(ClientMedication $med): array
    {
        $stock = $med->stock;
        $lots = $stock?->lots ?? collect();
        $started = $stock !== null && $stock->lots_started_at !== null;
        $available = $started ? StockLotRules::onHand($lots->toArray(), $this->stock->today()) : $stock?->on_hand;
        $onHand = $med->controlled_drug ? $stock?->on_hand : $available;
        $expired = $lots->filter(fn ($l) => Qty::greaterThan($l->quantity_remaining, 0) && $l->expiry_date && $l->expiry_date->toDateString() < $this->stock->today())->count();
        $next = $lots->filter(fn ($l) => StockLotRules::usable($l->toArray(), $this->stock->today()))->sortBy(fn ($l) => $l->expiry_date?->toDateString() ?? '9999-12-31')->first();
        $state = $onHand === null ? 'unknown' : (Qty::equals($onHand, 0) ? 'out' : ($expired ? 'expired' : ($stock?->reorder_level !== null && Qty::lessThanOrEqual($onHand, $stock->reorder_level) ? 'low' : 'ok')));
        return [
            'id' => $med->id, 'name' => $med->name, 'client_name' => $med->client->full_name, 'site_name' => $med->client->site?->name,
            'controlled' => (bool) $med->controlled_drug, 'active' => (bool) $med->active && $med->state === 'active',
            'stock_id' => $stock?->id, 'on_hand' => $onHand === null ? null : Qty::toFloat($onHand), 'unit' => $stock?->unit,
            'reorder_level' => $stock?->reorder_level, 'last_counted_at' => $stock?->last_counted_at, 'lots_started' => $started,
            'pack_count' => $lots->where('quantity_remaining', '>', 0)->count(), 'next_batch' => $next?->batch_number,
            'next_expiry' => $next?->expiry_date?->toDateString(), 'expired_packs' => $expired, 'state' => $state,
            'days_supply' => null, // Requires P01's explicit same-unit daily stock-use contract; never guess mg -> tablets.
        ];
    }
}

