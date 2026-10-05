<?php

namespace App\Services\Medication\Stock;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\MedicationPharmacyOrder;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\MarLinkService;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\MedicationScanVerificationService;
use App\Support\Medication\MedicationStockQuantity;
use App\Support\Medication\PharmacySupplyRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/** Query-bounded readers for the retained stock, pharmacy and CD workspace. */
final class StockManagementReadPayload
{
    public function __construct(
        private readonly MedicationGovernanceScopeService $scope,
        private readonly MarLinkService $marLinks,
        private readonly MedicationScanVerificationService $scanVerification,
    ) {}

    public function page(Request $request): array
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'view' => ['nullable', Rule::in(['all', 'low', 'expiring', 'expired', 'controlled', 'orders'])],
            'chip' => ['nullable', Rule::in(['all', 'controlled', 'cold_chain'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $context = $this->readerContext($request);
        $view = $data['view'] ?? 'all';
        $search = trim($data['q'] ?? '');
        $chip = $data['chip'] ?? 'all';
        if (! $context['can_view_controlled']) {
            $view = $view === 'controlled' ? 'all' : $view;
            $chip = $chip === 'controlled' ? 'all' : $chip;
        }
        $perPage = (int) ($data['per_page'] ?? 10);
        $page = (int) ($data['page'] ?? 1);
        $filters = ['q' => $search, 'view' => $view, 'chip' => $chip, 'per_page' => $perPage,
            'page' => $page, 'site_id' => $context['site_id'], 'client_id' => $context['client_id']];
        $stocks = $context['stocks'];
        $orders = $context['orders'];
        $controlled = (clone $stocks)->whereHas('medication', fn (Builder $q) => $q->where('controlled_drug', true));
        $summary = [
            'total_stock' => (clone $stocks)->count(),
            'low_stock' => (clone $stocks)->lowStock()->count(),
            'expiring' => (clone $stocks)->expiringSoon(30)->count(),
            'expired' => (clone $stocks)->expired()->count(),
            'controlled' => (clone $controlled)->count(),
            'controlled_discrepancies' => (clone $controlled)->whereIn('client_medication_id',
                $this->discrepancies($context)->where('difference', '<>', 0)->select('client_medication_id'))->count(),
            'total_orders' => (clone $orders)->count(),
            'open_orders' => (clone $orders)->whereNotIn('status', PharmacySupplyRules::CLOSED)->count(),
            'overdue_orders' => (clone $orders)->whereNotIn('status', PharmacySupplyRules::CLOSED)
                ->where('created_at', '<', now()->subDays(7))->count(),
        ];

        $inventory = clone $stocks;
        if ($view === 'low') {
            $inventory->lowStock();
        } elseif ($view === 'expiring') {
            $inventory->expiringSoon(30);
        } elseif ($view === 'expired') {
            $inventory->expired();
        }
        if ($chip === 'controlled') {
            $inventory->whereHas('medication', fn (Builder $q) => $q->where('controlled_drug', true));
        } elseif ($chip === 'cold_chain') {
            $inventory->whereIn('storage_condition', ['fridge', 'controlled_room']);
        }
        $inventory = $this->searchStocks($inventory, $search);
        $stockPage = $this->paginate($this->stockRelations($this->orderedStocks($inventory)), $perPage,
            in_array($view, ['orders', 'controlled'], true) ? 1 : $page, $request);
        $controlledPage = $this->paginate($this->stockRelations($this->orderedStocks($this->searchStocks(clone $controlled, $search, true))),
            $perPage, $view === 'controlled' ? $page : 1, $request);
        $orderPage = $this->paginate($this->searchOrders(clone $orders, $search)->with(['client:id,first_name,last_name', 'medication:id,name,controlled_drug'])
            ->orderByDesc('created_at')->orderByDesc('id'), $perPage, $view === 'orders' ? $page : 1, $request);
        $filters['page'] = ($view === 'orders' ? $orderPage : ($view === 'controlled' ? $controlledPage : $stockPage))->currentPage();

        // Actions can cross list pages: fetch only the stock belonging to these
        // three bounded pages, rather than making one list act as a picker.
        $medicationIds = $stockPage->getCollection()->pluck('client_medication_id')
            ->merge($controlledPage->getCollection()->pluck('client_medication_id'))
            ->merge($orderPage->getCollection()->pluck('client_medication_id'))->filter()->unique()->values();
        $stockModels = $stockPage->getCollection()->merge($controlledPage->getCollection())->unique('id')->values();
        $missingIds = $medicationIds->diff($stockModels->pluck('client_medication_id'));
        if ($missingIds->isNotEmpty()) {
            $stockModels = $stockModels->merge($this->stockRelations((clone $stocks)->whereIn('client_medication_id', $missingIds))->get())->unique('id')->values();
        }
        $stockContext = $this->stockRows($stockModels, $context['actor']);
        $stockById = $stockContext->keyBy('id');
        $openOrders = $this->latestOpenOrders(clone $orders, $medicationIds);
        $pagers = [
            'stock' => $this->pager($stockPage, $request, $filters, in_array($view, ['orders', 'controlled'], true) ? 'all' : $view),
            'orders' => $this->pager($orderPage, $request, $filters, 'orders'),
            'controlled' => $this->pager($controlledPage, $request, $filters, 'controlled'),
        ];
        $sites = $this->scope->sitePicker($context['accessible_sites']);
        $activeSite = $context['site_id'] ? $sites->firstWhere('id', $context['site_id']) : null;

        return [
            'can_record_controlled' => $context['actor']->canDo('medications.controlled.record'),
            'can_view_controlled' => $context['can_view_controlled'],
            'stockItems' => $stockPage->getCollection()->map(fn ($s) => $stockById->get($s->id))->values(),
            'controlledRegister' => $this->controlledRows($controlledPage->getCollection(), $context),
            'pharmacyOrders' => $orderPage->getCollection()->map(fn ($o) => $this->orderRow($o))->values(),
            'stockContext' => $stockContext,
            'openOrdersByMedication' => (object) $openOrders->mapWithKeys(fn ($o) => [(int) $o->client_medication_id => $this->orderRow($o)])->all(),
            'summary' => $summary, 'filters' => $filters, 'pagers' => $pagers,
            'pagination' => $pagers[$view === 'orders' ? 'orders' : ($view === 'controlled' ? 'controlled' : 'stock')],
            'lowStockCount' => $summary['low_stock'], 'expiringCount' => $summary['expiring'], 'expiredCount' => $summary['expired'],
            'clients' => $this->scope->clientPicker($context['accessible_sites']),
            // A complete, thin picker remains available. It never hydrates pack
            // history; selecting an off-page medicine reads its context endpoint.
            'activeMedications' => (clone $context['medications'])->with(['client:id,first_name,last_name', 'stock:id,client_medication_id,lots_started_at'])
                ->orderBy('name')->orderBy('id')->get(['id', 'name', 'client_id', 'dosage', 'barcode', 'nzulm_code', 'controlled_drug'])
                ->map(fn ($medication) => $this->medicationRow($medication))->values(),
            'witnesses' => $this->scope->controlledWitnessPicker($context['accessible_sites'], $context['actor']->id),
            'sites' => $sites->map(fn (Site $site) => $site->only(['id', 'name']))->values(),
            'active_site' => $activeSite ? ['id' => $activeSite->id, 'name' => $activeSite->name] : null,
            'site_brand_colour' => $activeSite?->brand_colour, 'client_id' => $context['client_id'],
        ];
    }

    public function medicationContext(Request $request, int $medicationId): array
    {
        $context = $this->readerContext($request);
        $medication = (clone $context['medications'])->with(['client:id,first_name,last_name', 'stock'])->findOrFail($medicationId);
        $stocks = $this->stockRelations((clone $context['stocks'])->where('client_medication_id', $medication->id))->get();
        $openOrder = $this->latestOpenOrders(clone $context['orders'], collect([$medication->id]))->first();

        return [
            'stock_item' => $this->stockRows($stocks, $context['actor'])->first(),
            'medication' => $this->medicationRow($medication),
            'open_order' => $openOrder ? $this->orderRow($openOrder) : null,
            'checked_at' => now()->toIso8601String(),
            'context' => ['medication_id' => (int) $medication->id, 'site_id' => $context['site_id'], 'client_id' => $context['client_id']],
        ];
    }

    private function readerContext(Request $request): array
    {
        $actor = $request->user();
        abort_unless($actor, 403);
        $siteId = $this->requestedId($request, 'site_id');
        $clientId = $this->requestedId($request, 'client_id');
        $sites = $this->scope->readerSiteIds($actor, MedicationGovernanceScopeService::STOCK_CAPABILITY, $siteId, $clientId);
        $readerSites = $siteId !== null ? [$siteId] : $sites;
        $clientIds = $this->marLinks->openableClientIds($actor, Client::whereIn('site_id', $readerSites)
            ->when($clientId, fn (Builder $q) => $q->whereKey($clientId))->pluck('id'));
        abort_if($clientId !== null && ! in_array($clientId, $clientIds, true), 404);
        $canViewControlled = $actor->canDo('medications.controlled.view');
        $medications = ClientMedication::active()->whereIn('client_id', $clientIds)
            ->when(! $canViewControlled, fn (Builder $q) => $q->where('controlled_drug', false));
        $stocks = ClientMedicationStock::query()->whereIn('client_medication_id', (clone $medications)->select('id'));
        $orders = $this->scope->scopeCanonicalClientMedicationRows(MedicationPharmacyOrder::query(), $readerSites, false)
            ->whereIn('client_id', $clientIds)
            ->when(! $canViewControlled, fn (Builder $q) => $this->scope->scopeWithoutControlledMedicationRows($q));

        return ['actor' => $actor, 'site_id' => $siteId, 'client_id' => $clientId, 'accessible_sites' => $sites,
            'reader_sites' => $readerSites, 'client_ids' => $clientIds, 'can_view_controlled' => $canViewControlled,
            'medications' => $medications, 'stocks' => $stocks, 'orders' => $orders];
    }

    private function requestedId(Request $request, string $key): ?int
    {
        if (! $request->filled($key)) {
            return null;
        }
        $id = filter_var($request->query($key), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_unless(is_int($id), 404);

        return $id;
    }

    private function orderedStocks(Builder $query): Builder
    {
        return $query->orderByRaw("(SELECT CONCAT_WS(' ', stock_person.first_name, stock_person.last_name) FROM clients AS stock_person JOIN client_medications AS stock_order ON stock_order.client_id = stock_person.id WHERE stock_order.id = client_medication_stocks.client_medication_id)")
            ->orderBy(ClientMedication::query()->select('name')->whereColumn('id', 'client_medication_stocks.client_medication_id'))
            ->orderBy('client_medication_stocks.id');
    }

    private function stockRelations(Builder $query): Builder
    {
        return $query->with([
            'lots' => fn ($lots) => $lots->where('state', 'open')->where('quantity_remaining', '>', 0),
            'medication' => fn ($med) => $med->with(['client:id,first_name,last_name,site_id,room_id', 'client.site:id,name', 'client.room:id,name']),
        ]);
    }

    private function searchStocks(Builder $query, string $search, bool $controlled = false): Builder
    {
        if ($search === '') {
            return $query;
        }
        $name = '(SELECT stock_med.name FROM client_medications AS stock_med WHERE stock_med.id = client_medication_stocks.client_medication_id)';
        $person = '(SELECT CONCAT_WS(\' \', stock_client.first_name, stock_client.last_name) FROM clients AS stock_client JOIN client_medications AS stock_med ON stock_med.client_id = stock_client.id WHERE stock_med.id = client_medication_stocks.client_medication_id)';
        if ($controlled) {
            return $query->whereRaw("LOCATE(?, LOWER(CONCAT_WS(' ', {$name}, {$person}))) > 0", [mb_strtolower($search)]);
        }
        $house = '(SELECT stock_site.name FROM sites AS stock_site JOIN clients AS stock_client ON stock_client.site_id = stock_site.id JOIN client_medications AS stock_med ON stock_med.client_id = stock_client.id WHERE stock_med.id = client_medication_stocks.client_medication_id)';
        $batch = "CASE WHEN client_medication_stocks.lots_started_at IS NULL THEN client_medication_stocks.batch_number ELSE (SELECT search_pack.batch_number FROM medication_stock_lots AS search_pack WHERE search_pack.client_medication_stock_id = client_medication_stocks.id AND search_pack.state = 'open' AND search_pack.quantity_remaining > 0 AND (search_pack.expiry_date IS NULL OR search_pack.expiry_date >= ?) ORDER BY search_pack.expiry_date IS NULL, search_pack.expiry_date, search_pack.received_at, search_pack.id LIMIT 1) END";

        return $query->whereRaw("LOCATE(?, LOWER(CONCAT_WS(' ', {$name}, {$person}, {$house}, {$batch}))) > 0", [mb_strtolower($search), StockAvailability::today()]);
    }

    private function searchOrders(Builder $query, string $search): Builder
    {
        if ($search === '') {
            return $query;
        }
        $name = '(SELECT order_med.name FROM client_medications AS order_med WHERE order_med.id = medication_pharmacy_orders.client_medication_id)';
        $person = '(SELECT CONCAT_WS(\' \', order_client.first_name, order_client.last_name) FROM clients AS order_client WHERE order_client.id = medication_pharmacy_orders.client_id)';

        return $query->whereRaw("LOCATE(?, LOWER(CONCAT_WS(' ', {$name}, {$person}, pharmacy_name, batch_number))) > 0", [mb_strtolower($search)]);
    }

    private function latestOpenOrders(Builder $query, Collection $medicationIds): Collection
    {
        return $query->whereIn('client_medication_id', $medicationIds)->whereNotIn('status', PharmacySupplyRules::CLOSED)
            ->whereNotExists(fn ($newer) => $newer->selectRaw('1')->from('medication_pharmacy_orders as newer_order')
                ->whereColumn('newer_order.client_medication_id', 'medication_pharmacy_orders.client_medication_id')
                ->whereColumn('newer_order.client_id', 'medication_pharmacy_orders.client_id')
                ->whereNotIn('newer_order.status', PharmacySupplyRules::CLOSED)
                ->where(fn ($later) => $later->whereColumn('newer_order.created_at', '>', 'medication_pharmacy_orders.created_at')
                    ->orWhere(fn ($tie) => $tie->whereColumn('newer_order.created_at', 'medication_pharmacy_orders.created_at')->whereColumn('newer_order.id', '>', 'medication_pharmacy_orders.id'))))
            ->with(['client:id,first_name,last_name', 'medication:id,name,controlled_drug'])
            ->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    private function discrepancies(array $context): Builder
    {
        return $this->scope->scopeCanonicalClientMedicationRows(ClientControlledDrugDiscrepancy::query(), $context['reader_sites'], false)
            ->whereIn('client_id', $context['client_ids'])->whereIn('status', ['open', 'under_review']);
    }

    private function controlledRows(Collection $stocks, array $context): Collection
    {
        $ids = $stocks->pluck('client_medication_id');
        $checks = $this->scope->scopeCanonicalClientMedicationRows(ClientControlledDrugEntry::query(), $context['reader_sites'], false)
            ->whereIn('client_medication_id', $ids)->where('entry_type', 'balance_check')
            ->whereNotExists(fn ($newer) => $newer->selectRaw('1')->from('client_controlled_drug_entries as newer_check')
                ->whereColumn('newer_check.client_medication_id', 'client_controlled_drug_entries.client_medication_id')
                ->whereColumn('newer_check.client_id', 'client_controlled_drug_entries.client_id')->where('newer_check.entry_type', 'balance_check')
                ->where(fn ($later) => $later->whereColumn('newer_check.recorded_at', '>', 'client_controlled_drug_entries.recorded_at')
                    ->orWhere(fn ($tie) => $tie->whereColumn('newer_check.recorded_at', 'client_controlled_drug_entries.recorded_at')->whereColumn('newer_check.id', '>', 'client_controlled_drug_entries.id'))))
            ->with('witnessedBy:id,name')->get()->keyBy('client_medication_id');
        $discrepancies = $this->discrepancies($context)->whereIn('client_medication_id', $ids)
            ->whereNotExists(fn ($older) => $older->selectRaw('1')->from('client_controlled_drug_discrepancies as older_discrepancy')
                ->whereColumn('older_discrepancy.client_medication_id', 'client_controlled_drug_discrepancies.client_medication_id')
                ->whereColumn('older_discrepancy.client_id', 'client_controlled_drug_discrepancies.client_id')
                ->whereIn('older_discrepancy.status', ['open', 'under_review'])->whereColumn('older_discrepancy.id', '<', 'client_controlled_drug_discrepancies.id'))
            ->get()->keyBy('client_medication_id');

        return $stocks->map(function (ClientMedicationStock $stock) use ($checks, $discrepancies): array {
            $check = $checks->get($stock->client_medication_id);
            $discrepancy = $discrepancies->get($stock->client_medication_id);
            $balance = $check?->on_hand_after;

            return ['id' => $stock->id, 'medication_id' => $stock->client_medication_id, 'medication_name' => $stock->medication?->name,
                'client_id' => $stock->medication?->client_id, 'client_name' => $stock->medication?->client?->full_name ?? '',
                'cd_class' => $stock->medication?->controlled_drug_class,
                'register_balance' => $balance !== null ? MedicationStockQuantity::toFloat($balance) : null,
                'on_hand' => $stock->on_hand !== null ? MedicationStockQuantity::toFloat($stock->on_hand) : null,
                'unit' => $stock->unit, 'last_check_at' => $check?->recorded_at?->toIso8601String(),
                'last_check_witness' => $check?->witnessedBy?->name, 'discrepancy' => $discrepancy ? (float) $discrepancy->difference : null];
        })->values();
    }

    private function medicationRow(ClientMedication $medication): array
    {
        return ['id' => $medication->id, 'name' => $medication->name, 'client_id' => $medication->client_id,
            'controlled' => (bool) $medication->controlled_drug,
            'pack_workflow_url' => ! $medication->controlled_drug ? $medication->stock?->pack_workflow_url : null,
            'client' => $medication->client ? $medication->client->only(['first_name', 'last_name']) : null,
            'scan_verification' => $medication->client ? $this->scanPayload($medication->client, $medication) : null];
    }

    private function scanPayload(Client $client, ClientMedication $medication): array
    {
        return [...$this->scanVerification->payload($client, $medication),
            'svg_url' => route('api.medications.scan_code.svg', ['client' => $client->id, 'medication' => $medication->id])];
    }

    private function orderRow(MedicationPharmacyOrder $order): array
    {
        return ['id' => $order->id, 'medication_id' => $order->client_medication_id,
            'client_name' => $order->client ? trim($order->client->first_name.' '.$order->client->last_name) : 'Unknown',
            'medication_name' => $order->medication?->name, 'controlled' => (bool) $order->medication?->controlled_drug,
            'pharmacy_name' => $order->pharmacy_name, 'order_type' => $order->order_type, 'status' => $order->status,
            'quantity_ordered' => $order->quantity_ordered, 'quantity_received' => $order->quantity_received,
            'ordered_at' => $order->created_at?->toIso8601String(), 'submitted_at' => $order->submitted_at?->toIso8601String(),
            'confirmed_at' => $order->confirmed_at?->toIso8601String(), 'dispensed_at' => $order->dispensed_at?->toIso8601String(),
            'delivered_at' => $order->delivered_at?->toIso8601String(), 'batch_number' => $order->batch_number,
            'batch_expiry' => $order->batch_expiry?->toDateString()];
    }

    /** Clamp before fetching rows, so a changed result set never leaves a stale page number. */
    private function paginate(Builder $query, int $perPage, int $requestedPage, Request $request): LengthAwarePaginator
    {
        $total = (clone $query)->toBase()->getCountForPagination();
        $page = min($requestedPage, max(1, (int) ceil($total / $perPage)));
        $rows = $total > 0 ? $query->forPage($page, $perPage)->get() : $query->getModel()->newCollection();

        return new LengthAwarePaginator($rows, $total, $perPage, $page, ['path' => $request->url(), 'pageName' => 'page']);
    }

    private function pager(LengthAwarePaginator $page, Request $request, array $filters, string $view): array
    {
        $page->appends([...$request->query(), ...$filters, 'view' => $view]);

        return ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage(),
            'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem(),
            'prev_page_url' => $page->previousPageUrl(), 'next_page_url' => $page->nextPageUrl()];
    }

    private function stockRows(Collection $stocks, User $actor): Collection
    {
        $ranked = AuditLog::query()->where('auditable_type', (new ClientMedicationStock)->getMorphClass())
            ->whereIn('auditable_id', $stocks->pluck('id'))
            ->whereIn('action', ['clientmedicationstock.create', 'clientmedicationstock.update'])
            ->select('audit_logs.*')->selectRaw('ROW_NUMBER() OVER (PARTITION BY auditable_id ORDER BY created_at DESC, id DESC) AS stock_rank');
        $movements = AuditLog::query()->fromSub($ranked, 'audit_logs')->where('stock_rank', '<=', 6)
            ->with('user:id,name')->orderByDesc('created_at')->orderByDesc('id')->get()->groupBy('auditable_id');

        return $stocks->map(function (ClientMedicationStock $stock) use ($actor, $movements): array {
            $quantity = $stock->availableQuantity();

            return ['id' => $stock->id, 'medication_id' => $stock->client_medication_id, 'medication_name' => $stock->medication?->name,
                'medication_dose' => $stock->medication?->dosage, 'client_name' => $stock->medication?->client?->full_name ?? '',
                'client_id' => $stock->medication?->client_id, 'client_room' => $stock->medication?->client?->room?->name,
                'mar_url' => $this->marLinks->urlFor($actor, $stock->medication?->client_id),
                'site_id' => $stock->medication?->client?->site_id, 'site_name' => $stock->medication?->client?->site?->name,
                'on_hand' => $quantity !== null ? MedicationStockQuantity::toFloat($quantity) : null,
                'unit' => $stock->unit, 'reorder_level' => $stock->reorder_level,
                'last_counted_at' => $stock->last_counted_at?->toIso8601String(), 'is_low' => $stock->isLowStock(),
                'controlled' => (bool) $stock->medication?->controlled_drug, 'storage_condition' => $stock->storage_condition ?? 'ambient',
                'requires_cold_chain' => $stock->requiresColdChain(), 'expiry_date' => $stock->currentExpiryDate()?->toDateString(),
                'batch_number' => $stock->currentBatchNumber(), 'pack_workflow_url' => ! $stock->medication?->controlled_drug ? $stock->pack_workflow_url : null,
                'supplier_name' => $stock->supplier_name, 'reorder_quantity' => $stock->reorder_quantity,
                'is_expired' => $stock->isExpired(), 'is_expiring_soon' => $stock->isExpiringSoon(30), 'is_expiring_90' => $stock->isExpiringSoon(90),
                'scan_verification' => $stock->medication?->client ? $this->scanPayload($stock->medication->client, $stock->medication) : null,
                'movements' => ($movements->get($stock->id) ?? collect())->map(fn (AuditLog $log) => $this->formatStockMovement($log, $stock->unit))->values()];
        })->values();
    }

    /**
     * Format one ClientMedicationStock audit-log entry into a movement row for
     * the stock detail modal. This is an honest change-ledger derivation from the
     * recorded before/after snapshot — no invented quantities or movement table.
     *
     * @return array<string, mixed>
     */
    private function formatStockMovement(AuditLog $log, ?string $unit): array
    {
        $meta = $log->meta ?? [];
        $after = $meta['after'] ?? [];
        $before = $meta['before'] ?? [];
        $fields = $meta['fields'] ?? array_keys($after);
        $isCreate = str_ends_with($log->action, '.create');

        $delta = null;
        if (array_key_exists('on_hand', $after) && is_numeric($after['on_hand'])
            && ($isCreate || (array_key_exists('on_hand', $before) && is_numeric($before['on_hand'])))) {
            $to = MedicationStockQuantity::normalize($after['on_hand']);
            $from = MedicationStockQuantity::normalize($isCreate ? 0 : $before['on_hand']);
            $delta = MedicationStockQuantity::toFloat(MedicationStockQuantity::subtract($to, $from));
        }

        $notes = $after['notes'] ?? null;
        $reason = is_string($notes) ? preg_replace('/^Stock adjustment:\s*/', '', $notes) : null;

        if ($isCreate) {
            $type = 'created';
            $summary = 'Stock record created';
        } elseif (in_array('on_hand', $fields, true)) {
            // An on_hand change with a logged reason came through the adjust/count
            // path; one without a reason is a receipt increment.
            if ($delta === null) {
                $type = 'updated';
                $summary = 'Stock balance updated';
            } elseif (in_array('notes', $fields, true) && is_string($notes) && str_starts_with($notes, 'Stock adjustment')) {
                $isCount = $reason !== null && stripos($reason, 'count') !== false;
                $type = $isCount ? 'counted' : 'adjusted';
                $summary = $reason !== '' && $reason !== null ? $reason : ($isCount ? 'Stock counted' : 'Stock adjusted');
            } else {
                $type = $delta >= 0 ? 'received' : 'removed';
                $summary = $type === 'received' ? 'Stock received' : 'Stock removed';
            }
        } else {
            $type = 'updated';
            $labelMap = [
                'reorder_level' => 'reorder level',
                'reorder_quantity' => 'reorder qty',
                'expiry_date' => 'expiry',
                'batch_number' => 'batch',
                'supplier_name' => 'supplier',
                'storage_condition' => 'storage',
            ];
            $labels = array_values(array_intersect_key($labelMap, array_flip($fields)));
            $summary = $labels ? 'Updated '.implode(', ', $labels) : 'Stock details updated';
        }

        return [
            'id' => $log->id,
            'at' => $log->created_at?->toIso8601String(),
            'actor' => $log->user?->name,
            'type' => $type,
            'summary' => $summary,
            'delta' => $delta,
            'unit' => $unit,
        ];
    }
}
