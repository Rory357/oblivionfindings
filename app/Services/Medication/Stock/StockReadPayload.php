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
use App\Services\Medication\PharmacyConnect\PharmacyDispatchPresentation;
use App\Services\Medication\PharmacyConnect\PharmacyPartnerRegistry;
use App\Services\Medication\Support\MedicationSupport;
use App\Services\Medication\WitnessPinService;
use App\Support\Medication\MedicationStockQuantity as Qty;
use App\Support\Medication\PharmacySupplyRules;
use App\Support\Medication\StockLotRules;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final class StockReadPayload
{
    public function __construct(private readonly MedicationGovernanceScopeService $scope, private readonly MedicationRecordAccess $access, private readonly MedicationStockService $stock) {}

    public function page(Request $request): array
    {
        $request->validate(['lot_id' => ['nullable', 'integer', 'min:1']]);
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
        $lotId = $request->filled('lot_id') ? $request->integer('lot_id') : null;
        if ($lotId !== null) {
            // An exact pack entry intersects every requested boundary before
            // deriving its owner. A stale or contradictory link cannot widen scope.
            $selectedLot = MedicationStockLot::query()
                ->whereHas('stock', fn ($query) => $query->whereIn('client_medication_id', (clone $medications)->select('id')))
                ->with('stock.medication')->findOrFail($lotId);
            $medicineId = (int) $selectedLot->stock->client_medication_id;
            $medications->whereKey($medicineId);
            $clientId = (int) $selectedLot->stock->medication->client_id;
            $selectedClient ??= $this->access->client($actor, $clientId);
            $clientIds = [$clientId];
            $site = (int) $selectedClient->site_id;
            $siteIds = [$site];
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
        if (! in_array($show, ['all', 'out', 'low', 'attention', 'self'], true)) {
            $show = 'all';
        }
        $quantitySql = StockAvailability::usableQuantitySql();
        $today = $this->stock->today();
        // Approved P06 "Running low": at or below the reorder level, or out.
        $runningLow = fn ($stock) => $stock->where(fn ($s) => $s->whereRaw('('.$quantitySql.') <= 0', [$today])
            ->orWhere(fn ($r) => $r->whereNotNull('reorder_level')->whereRaw('('.$quantitySql.') <= client_medication_stocks.reorder_level', [$today])));
        $itemsQuery = (clone $medications)
            ->when($view === 'expiring', fn ($q) => $q->whereHas('stock.lots', fn ($l) => $l->where('quantity_remaining', '>', 0)
                ->where('state', 'open')->whereNotNull('expiry_date')->where('expiry_date', '<=', CarbonImmutable::parse($this->stock->today())->addDays(30)->toDateString())))
            ->when($view === 'stock' && $show === 'out', fn ($q) => $q->whereHas('stock', fn ($s) => $s->whereRaw('('.$quantitySql.') <= 0', [$today])))
            ->when($view === 'stock' && $show === 'low', fn ($q) => $q->whereHas('stock', $runningLow))
            // Approved P06 Show › Needs attention (Main, 21 Oct): out or low, a pack expiring
            // within 30 days, a count waiting for sign-off, or an overdue order.
            ->when($view === 'stock' && $show === 'attention', fn ($q) => $q->where(fn ($w) => $w->whereHas('stock', $runningLow)
                ->orWhereHas('stock.lots', fn ($l) => $l->where('quantity_remaining', '>', 0)->where('state', 'open')->whereNotNull('expiry_date')
                    ->where('expiry_date', '<=', CarbonImmutable::parse($today)->addDays(30)->toDateString()))
                ->orWhereHas('stock', fn ($s) => $s->whereIn('client_medication_stocks.id', MedicationStockCountRecord::query()->where('state', 'needs_review')->select('client_medication_stock_id')))
                ->orWhereIn('client_medications.id', $this->overdueOrders($today)->select('client_medication_id'))))
            // Approved P06 Show › Self-managed: the person manages it (P03 support).
            ->when($view === 'stock' && $show === 'self', fn ($q) => $q->whereIn('client_medications.id', $this->selfManagedIds(clone $medications)));
        $itemsQuery->when($search !== '', fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')
            ->orWhereHas('client', fn ($c) => $c->where('first_name', 'like', '%'.$search.'%')->orWhere('last_name', 'like', '%'.$search.'%'))
            ->orWhereHas('stock.lots', fn ($l) => $l->where('batch_number', 'like', '%'.$search.'%'))));
        // Approved P06 caption "n shown · n people": the people across the whole list.
        $itemPeople = (clone $itemsQuery)->distinct()->count('client_id');
        $items = $itemsQuery->with(['client.site', 'stock.lots'])->orderBy('name')->orderBy('id')->paginate(25, ['*'], 'items_page')->withQueryString();
        [$openOrders, $toSignOff, $support] = $this->rowWork($items->getCollection());
        $items->through(fn ($med) => [...$this->item($med),
            'open_order' => isset($openOrders[$med->id]) ? $this->orderRow($openOrders[$med->id]) : null,
            'count_to_sign_off' => ($count = $toSignOff[$med->stock?->id ?? 0] ?? null) ? ['id' => (int) $count->id, 'counted_at' => $count->counted_at?->toIso8601String()] : null,
            'self_managed' => ($support[(int) $med->id] ?? 'staff_given') === 'self_managed',
        ]);
        $orders = MedicationPharmacyOrder::whereIn('client_id', $clientIds)->whereIn('client_medication_id', $medicationIds)
            ->whereHas('medication', fn ($q) => $q->whereColumn('client_medications.client_id', 'medication_pharmacy_orders.client_id'));
        $ordersPage = (clone $orders)->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('pharmacy_name', 'like', '%'.$search.'%')->orWhereHas('medication', fn ($m) => $m->where('name', 'like', '%'.$search.'%'))))->with(['client', 'medication.stock'])
            // Approved P06 sections (1440-12): to receive, waiting on the pharmacy, drafts, closed.
            ->orderByRaw("CASE WHEN status IN ('dispensed', 'part_received') THEN 0 WHEN status IN ('submitted', 'confirmed') THEN 1 WHEN status = 'draft' THEN 2 ELSE 3 END")
            ->latest('id')->paginate(25, ['*'], 'orders_page')->withQueryString();
        // EA-138 (Lane C, 20 Oct): where each connected pharmacy send stands,
        // on the row, while the pharmacy connection runs (as the Stock overview).
        $dispatches = app(PharmacyPartnerRegistry::class)->enabled()
            ? app(PharmacyDispatchPresentation::class)->rowStates($ordersPage->getCollection()->pluck('id')->map(fn ($id): int => (int) $id)->all())
            : [];
        $ordersPage->through(fn ($o) => [...$this->orderRow($o), 'dispatch' => $dispatches[(int) $o->id] ?? null]);
        $counts = MedicationStockCountRecord::whereIn('client_medication_stock_id', (clone $stocks)->select('id'));
        $countsPage = (clone $counts)->when($search !== '', fn ($q) => $q->whereHas('stock.medication', fn ($m) => $m->where('name', 'like', '%'.$search.'%')))->with(['stock.medication.client', 'countedBy'])->latest('id')->paginate(25, ['*'], 'counts_page')->withQueryString();
        $countsPage->through(fn ($c) => $this->countPresentation($c));
        $focusedCount = $request->filled('count_id') ? $this->countPresentation((clone $counts)
            ->with(['stock.medication.client', 'countedBy'])->findOrFail($request->integer('count_id'))) : null;
        $movements = MedicationStockMovement::query()->when($search !== '', fn ($q) => $q->whereHas('lot.stock.medication', fn ($m) => $m->where('name', 'like', '%'.$search.'%')))->whereIn('medication_stock_lot_id', (clone $lots)->select('id'))->with(['lot.stock.medication.client', 'recordedBy'])
            ->when($view === 'removals', fn ($q) => $q->whereIn('kind', ['returned_pharmacy', 'removed_expired', 'damaged', 'quarantined']))
            ->latest('id')->paginate(25, ['*'], 'moves_page')->withQueryString();
        $movements->through(fn ($m) => [...$m->only(['id', 'kind', 'quantity', 'balance_before', 'balance_after', 'reason', 'notes', 'recorded_at', 'administration_id', 'controlled_entry_id', 'destruction_id', 'transit_log_id', 'stock_count_record_id', 'reverses_movement_id', 'return_of_id']),
            'quantity_display' => Qty::display($m->quantity),
            'medication_id' => $m->lot->stock->client_medication_id, 'medication_name' => $m->lot->stock->medication->name,
            'client_name' => $m->lot->stock->medication->client->full_name, 'recorded_by_name' => $m->recordedBy?->name]);

        // Approved P06 meters (Main, 20 Oct): every figure reads the list's own
        // scope — houses, the person rule and controlled concealment — so a
        // reader without controlled view counts only visible medicines.
        $controlledView = $actor->canDo('medications.controlled.view');
        $lowStocks = $runningLow(clone $stocks);
        $openDated = (clone $lots)->where('quantity_remaining', '>', 0)->where('state', 'open')->whereNotNull('expiry_date');
        $toReceive = (clone $orders)->whereIn('status', ['dispensed', 'part_received']);
        $meters = [
            'running_low' => (clone $lowStocks)->count(),
            'running_low_first' => ClientMedication::query()->whereIn('id', (clone $lowStocks)->select('client_medication_id'))->orderBy('name')->orderBy('id')->value('name'),
            'expired' => (clone $openDated)->where('expiry_date', '<', $today)->count(),
            'to_receive' => (clone $toReceive)->count(),
            'to_receive_due' => (clone $toReceive)->whereNotNull('expected_delivery')->where('expected_delivery', '<=', $today)->count(),
            'to_receive_part' => (clone $toReceive)->where('status', 'part_received')->count(),
            'pharmacy_orders' => (clone $orders)->whereIn('status', ['draft', 'submitted', 'confirmed'])->count(),
            'drafts' => (clone $orders)->where('status', 'draft')->count(),
            'people' => ClientMedication::query()->whereIn('id', (clone $stocks)->select('client_medication_id'))->distinct()->count('client_id'),
            'controlled_hidden' => $controlledView ? 0 : ClientMedication::query()->current()->whereIn('client_id', $clientIds)->where('controlled_drug', true)->count(),
        ];

        return [
            'items' => $items, 'orders' => $ordersPage, 'counts' => $countsPage, 'movements' => $movements, 'focused_count' => $focusedCount,
            'focused_lot_id' => $lotId, 'item_people' => $itemPeople,
            'sites' => $this->scope->sitePicker($allSites)->map->only(['id', 'name']),
            'pharmacies' => (clone $orders)->distinct()->orderBy('pharmacy_name')->pluck('pharmacy_name')->filter()->values(),
            'filters' => ['view' => $view, 'search' => $search, 'site_id' => $site, 'client_id' => $clientId, 'show' => $show, 'medication_id' => $medicineId, 'lot_id' => $lotId],
            'selected_client' => $selectedClient ? ['id' => (int) $selectedClient->id, 'name' => $selectedClient->full_name] : null,
            'metrics' => [
                'tracked' => (clone $stocks)->count(), 'out' => (clone $stocks)->whereRaw('('.$quantitySql.') <= 0', [$today])->count(),
                'expiring' => (clone $lots)->where('quantity_remaining', '>', 0)->where('state', 'open')->whereNotNull('expiry_date')->where('expiry_date', '<=', CarbonImmutable::parse($this->stock->today())->addDays(30)->toDateString())->count(),
                'orders' => (clone $orders)->whereNotIn('status', PharmacySupplyRules::CLOSED)->count(),
                'counts' => (clone $counts)->where('state', 'needs_review')->count(),
                ...$meters,
            ],
            'controlled_left_out' => ! $controlledView,
            // Approved P06 Q5: receive-level actions with either key; the rest stay stock.update.
            'can' => ['photograph' => app(PackPhotoReceiptSettings::class)->canPhotograph($actor), 'receive' => config('medications.stock_lots_enabled', false) && ($actor->canDo('medications.stock.update') || $actor->canDo('medications.stock.receive')), 'manage' => config('medications.stock_lots_enabled', false) && $actor->canDo('medications.stock.update'), 'receive_controlled' => config('medications.stock_lots_enabled', false) && $actor->canDo('medications.stock.update') && $actor->canDo('medications.controlled.view') && $actor->canDo('medications.controlled.record') && $siteIds !== [], 'controlled' => $actor->canDo('medications.controlled.view')],
            'lots_enabled' => (bool) config('medications.stock_lots_enabled', false),
        ];
    }

    public function detail(User $actor, int $medicationId): array
    {
        $med = $this->access->medication($actor, $medicationId);
        abort_if($med->controlled_drug && ! $actor->canDo('medications.controlled.view'), 404);
        $med->load(['client.site', 'stock.lots.photos.takenBy', 'stock.lots.pharmacyOrder']);
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
            'label' => PackPhotoReader::packLabel($lot),
            'supplier' => PackPhotoReader::supplierLabel($lot),
            'photos' => $lot->photos->map(fn ($photo) => [
                ...$photo->only(['id', 'original_name', 'mime', 'bytes', 'taken_at', 'description', 'pack_changed']),
                'taken_by_name' => $photo->takenBy?->name, 'preview_url' => route('emar.stock.photos.view', $photo->id), 'download_url' => route('emar.stock.photos.download', $photo->id),
            ])->all(),
        ])->all() ?? [];
        // C11 (approved P06 Q6 + P11 Medicine photos): whether the receive
        // dialog asks for a photo, whether a controlled receipt needs one,
        // and the photo staff saw last.
        $settings = app(PackPhotoReceiptSettings::class);
        $hasPhoto = $med->stock?->lots->contains(fn ($lot) => $lot->photos->isNotEmpty()) ?? false;
        $item['photo_policy'] = [
            'can_photograph' => $settings->canPhotograph($actor),
            'mode' => $settings->prompt(),
            'prompt' => $settings->promptFor($hasPhoto, (bool) $med->controlled_drug),
            'required' => (bool) $med->controlled_drug && $settings->controlledPhotoRequired(),
            'has_photo' => $hasPhoto,
        ];
        $item['latest_photo'] = app(PackPhotoReader::class)->forOrder($actor, $med);
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

    /**
     * Approved P06 header "Receive a delivery" (Main, 20 Oct): the medicines a
     * delivery can be received for — deliveries due from the pharmacy first
     * (dated, then undated), then every other receivable medicine at the
     * house. The list's scope: houses, the person rule and controlled
     * concealment; controlled medicines only for people who receive them with
     * a witness. The choice opens the existing receive dialog and command.
     */
    public function receiveChoices(Request $request): array
    {
        $actor = $request->user();
        $lotsEnabled = (bool) config('medications.stock_lots_enabled', false);
        abort_unless($lotsEnabled && ($actor->canDo('medications.stock.update') || $actor->canDo('medications.stock.receive')), 403);
        $controlled = $actor->canDo('medications.stock.update') && $actor->canDo('medications.controlled.view') && $actor->canDo('medications.controlled.record');
        $site = $request->filled('site_id') ? $request->integer('site_id') : null;
        $siteIds = $this->scope->readerSiteIds($actor, 'medications.view', $site);
        if ($site) {
            $siteIds = [$site];
        }
        $clientIds = $this->access->readableClientIds($actor, Client::whereIn('site_id', $siteIds)->pluck('id'));
        $search = mb_substr(trim($request->string('search')->toString()), 0, 100);
        $medications = ClientMedication::query()->current()->whereIn('client_id', $clientIds)
            ->when(! $controlled, fn ($query) => $query->where('controlled_drug', false))
            // MedicationStockService::receivable(): active, or held (EA-073).
            ->where(fn ($query) => $query->where(fn ($q) => $q->where('active', true)->where('state', 'active'))->orWhere('state', 'paused'))
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')
                ->orWhereHas('client', fn ($c) => $c->where('first_name', 'like', '%'.$search.'%')->orWhere('last_name', 'like', '%'.$search.'%'))));
        $orders = MedicationPharmacyOrder::query()->whereIn('client_id', $clientIds)->whereIn('client_medication_id', (clone $medications)->select('id'))
            ->whereHas('medication', fn ($q) => $q->whereColumn('client_medications.client_id', 'medication_pharmacy_orders.client_id'))
            ->whereIn('status', ['dispensed', 'part_received'])->with(['client', 'medication.stock'])
            ->orderByRaw('expected_delivery IS NULL')->orderBy('expected_delivery')->orderBy('id')->limit(50)->get();
        $medicines = (clone $medications)->whereNotIn('id', $orders->pluck('client_medication_id')->all())
            ->with(['client.site', 'stock.lots'])->orderBy('name')->orderBy('id')->limit(101)->get();

        return [
            'orders' => $orders->map(fn ($order) => $this->orderRow($order))->values()->all(),
            'medicines' => $medicines->take(100)->map(fn ($med) => $this->item($med))->values()->all(),
            'more' => $medicines->count() > 100,
        ];
    }

    /**
     * Approved P06 stock rows (Main, 20 Oct): for one page of medicines, the
     * open order each row's state line and button follow — a due delivery
     * before a sent order before a draft, never an order filed under another
     * person — the latest count waiting for sign-off, and each medicine's P03
     * support mode.
     *
     * @return array{0: array<int, MedicationPharmacyOrder>, 1: array<int, MedicationStockCountRecord>, 2: array<int, string>}
     */
    private function rowWork($medicines): array
    {
        $rank = ['dispensed' => 0, 'part_received' => 1, 'confirmed' => 2, 'submitted' => 3, 'draft' => 4];
        $orders = MedicationPharmacyOrder::query()->whereIn('client_medication_id', $medicines->pluck('id')->all())
            ->whereIn('status', array_keys($rank))
            ->whereHas('medication', fn ($q) => $q->whereColumn('client_medications.client_id', 'medication_pharmacy_orders.client_id'))
            ->with(['client', 'medication.stock'])->orderByDesc('id')->get()
            ->sortBy(fn ($order) => $rank[$order->status], SORT_REGULAR)
            ->groupBy('client_medication_id')->map->first()->all();
        $stockIds = $medicines->map(fn ($med) => $med->stock?->id)->filter()->values()->all();
        $counts = MedicationStockCountRecord::query()->whereIn('client_medication_stock_id', $stockIds)->where('state', 'needs_review')
            ->orderByDesc('id')->get(['id', 'client_medication_stock_id', 'counted_at'])
            ->unique('client_medication_stock_id')->keyBy('client_medication_stock_id')->all();

        return [$orders, $counts, app(MedicationSupport::class)->modes($medicines)];
    }

    /** Approved P06 "overdue": sent or dispensed, and past its expected delivery — or, undated, past the date it was needed by. */
    private function overdueOrders(string $today)
    {
        return MedicationPharmacyOrder::query()->whereIn('status', ['submitted', 'confirmed', 'dispensed', 'part_received'])
            ->where(fn ($o) => $o->where('expected_delivery', '<', $today)->orWhere(fn ($n) => $n->whereNull('expected_delivery')->where('needed_by', '<', $today)))
            ->whereHas('medication', fn ($q) => $q->whereColumn('client_medications.client_id', 'medication_pharmacy_orders.client_id'));
    }

    /** @return list<int> the medicines in this scope that their person manages (P03). */
    private function selfManagedIds($medications): array
    {
        $modes = app(MedicationSupport::class)->modes($medications->get(['id', 'client_id']));

        return array_keys(array_filter($modes, fn (string $mode): bool => $mode === 'self_managed'));
    }

    private function orderRow(MedicationPharmacyOrder $order): array
    {
        return [
            ...$order->only(['id', 'client_medication_id', 'pharmacy_name', 'status', 'quantity_ordered', 'quantity_received', 'quantity_dispensed', 'order_notes',
                'needed_by', 'expected_delivery', 'communication_method', 'communication_reference', 'communication_recorded_at', 'closure_reason', 'batch_number', 'batch_expiry',
                'created_at', 'submitted_at', 'dispensed_at', 'delivered_at', 'closed_at']),
            // Approved P06 rows: the unit and whole-number quantities (MedicationStockQuantity::display).
            'unit' => $order->medication->stock?->unit,
            'received_display' => Qty::display($order->quantity_received ?? 0),
            'dispensed_display' => $order->quantity_dispensed === null ? null : Qty::display($order->quantity_dispensed),
            'medication_name' => $order->medication->name, 'client_name' => $order->client->full_name, 'controlled' => (bool) $order->medication->controlled_drug,
            'overdue' => in_array($order->status, ['submitted', 'confirmed', 'dispensed', 'part_received'], true)
                && ($order->expected_delivery ? $order->expected_delivery->toDateString() < StockAvailability::today()
                    : ($order->needed_by !== null && $order->needed_by->toDateString() < StockAvailability::today())),
            'still_to_come' => $order->status === 'part_received'
                ? Qty::display(Qty::maxZero(Qty::subtract($order->quantity_dispensed ?? $order->quantity_ordered, $order->quantity_received ?? 0))) : null,
        ];
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
            'id' => $med->id, 'name' => $med->name, 'client_id' => (int) $med->client_id, 'site_id' => (int) $med->client->site_id,
            'client_name' => $med->client->full_name, 'site_name' => $med->client->site?->name,
            'controlled' => (bool) $med->controlled_drug, 'active' => (bool) $med->active && $med->state === 'active',
            // EA-073: a held medicine can still be received and reordered.
            'receivable' => MedicationStockService::receivable($med), 'held' => $med->state === 'paused',
            'stock_id' => $stock?->id, 'physical_on_hand' => $stock?->on_hand, 'usable_on_hand' => $stock ? app(StockAvailability::class)->usableQuantity($stock) : null, 'on_hand' => $onHand === null ? null : Qty::toFloat($onHand), 'unit' => $stock?->unit,
            'reorder_level' => $stock?->reorder_level, 'last_counted_at' => $stock?->last_counted_at, 'lots_started' => $started,
            'pack_count' => $lots->where('quantity_remaining', '>', 0)->count(), 'next_batch' => $stock?->currentBatchNumber(),
            'next_expiry' => $stock?->currentExpiryDate()?->toDateString(), 'expired_packs' => $expired, 'state' => $state,
            'days_supply' => null, // Requires P01's explicit same-unit daily stock-use contract; never guess mg -> tablets.
            ...$this->rowQuantities($stock, $started, $next),
        ];
    }

    /**
     * Approved P06 stock row (Main, 20 Oct): quantities as staff read them —
     * whole when whole ("68"), the decimal only when real ("2.5"), and "0"
     * a real balance — from MedicationStockQuantity::display; the expired
     * packs to take out of use; and the next pack, for "use first" lines.
     */
    private function rowQuantities(?ClientMedicationStock $stock, bool $started, ?MedicationStockLot $next): array
    {
        $today = StockAvailability::today();
        $usable = $stock ? app(StockAvailability::class)->usableQuantity($stock) : null;
        $lots = $stock && $started ? app(StockAvailability::class)->packs($stock) : collect();
        $expired = $lots->filter(fn ($lot) => $lot->state === 'open' && Qty::greaterThan($lot->quantity_remaining, 0)
            && $lot->expiry_date !== null && $lot->expiry_date->toDateString() < $today)->sortBy(fn ($lot) => [$lot->expiry_date->toDateString(), $lot->id]);

        return [
            'usable_display' => $usable === null ? null : Qty::display($usable),
            'physical_display' => $stock?->on_hand === null ? null : Qty::display($stock->on_hand),
            'reorder_display' => $stock?->reorder_level === null ? null : Qty::display($stock->reorder_level),
            'expired_lots' => $expired->map(fn ($lot) => ['batch_number' => $lot->batch_number, 'expiry_date' => $lot->expiry_date->toDateString(),
                'quantity' => Qty::display($lot->quantity_remaining)])->values()->all(),
            'usable_packs' => $lots->filter(fn ($lot) => StockLotRules::usable($lot->toArray(), $today))->count(),
            'next_quantity' => $next ? Qty::display($next->quantity_remaining) : null,
            'next_expiry_in_days' => $next?->expiry_date
                ? (int) CarbonImmutable::parse($today)->diffInDays(CarbonImmutable::parse($next->expiry_date->toDateString()), false) : null,
        ];
    }
}
