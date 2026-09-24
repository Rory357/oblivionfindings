<?php

namespace App\Services\Fleet;

use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinFixedAsset;
use App\Domain\Finance\Models\FinPurchaseOrder;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\FleetFinanceReviewRequest;
use App\Models\FleetFinanceReviewRequestEvent;
use App\Models\FleetVehicleFinanceLink;
use App\Models\FleetWorkOrder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Read model for the vehicle profile's Overview › Finance view. Bounded and
 * permission-shaped: the view needs finance.assets.view; purchase order and
 * supplier invoice identity and amounts need finance.ap.view; a link to a
 * Finance page is only given when the viewer may open that page. Purchase
 * orders and invoices are different stages of the same spend, so nothing
 * here adds amounts together. Nothing here writes.
 */
class VehicleFinancePresenter
{
    private const BILL_LIMIT = 100;

    private const REQUEST_LIMIT = 100;

    private const WORK_LIMIT = 50;

    private const DOCUMENT_LIMIT = 100;

    /** Record type => [owner shown on the record, Finance workspace that holds it]. */
    private const OWNERS = [
        'fixed_asset' => ['Finance assets', 'Fixed assets'],
        'purchase_order' => ['Finance purchasing', 'Purchasing'],
        'bill' => ['Accounts payable', 'Accounts payable'],
    ];

    private const WAITING_STATES = ['scan_unavailable', 'publication_failed', 'stored', 'reserved'];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly VehicleFinanceService $finance,
    ) {}

    /**
     * The caller resolves the vehicle for the viewer; it is re-resolved here
     * so the view can never outlive the viewer's site access.
     *
     * @return array<string,mixed>
     */
    public function present(User $viewer, Asset $asset): array
    {
        // Central fleet oversight opens the vehicle, not its Finance records:
        // those keep the vehicle's Site rule and read as not available.
        $vehicle = $this->access->fleetVehicle($viewer, (int) $asset->getKey()) ?? abort(404);
        $siteRestricted = ! $this->access->vehicleAtAccessibleSite($viewer, $vehicle);
        $can = $siteRestricted
            ? array_map(fn (): bool => false, $this->permissions($viewer, $vehicle))
            : $this->permissions($viewer, $vehicle);
        $view = [
            'can' => $can,
            'site_restricted' => $siteRestricted,
            'fixed_asset' => null,
            'cost_centre' => null,
            'pending_requests' => 0,
            'records' => [],
            'records_total' => 0,
            'requests' => [],
            'request_types' => [],
            'sources' => [],
            'documents' => [],
            'link_state' => ['finance_fixed_asset' => null, 'vehicle_fixed_asset' => null],
            'as_of' => now()->toIso8601String(),
        ];
        if (! $can['view']) {
            return $view;
        }

        $requests = FleetFinanceReviewRequest::query()->where('asset_id', $vehicle->id)
            ->with(['requestedBy:id,name', 'decidedBy:id,name', 'events' => fn ($events) => $events->with('actor:id,name')->orderBy('id')])
            ->orderByDesc('id')->limit(self::REQUEST_LIMIT)->get();
        $files = $this->requestFiles($vehicle, $requests, $can['open_documents']);
        [$records, $total] = $this->records($vehicle, $can, $requests, $files);
        $fixed = array_values(array_filter($records, fn (array $record): bool => $record['type'] === 'fixed_asset' && $record['available']));
        $centre = $this->finance->siteCostCentre($vehicle);

        return [
            ...$view,
            'fixed_asset' => $fixed === [] ? null : [
                'id' => $fixed[0]['id'],
                'label' => $fixed[0]['reference'] ?: $fixed[0]['name'],
                'count' => count($fixed),
            ],
            'cost_centre' => $centre ? [
                'id' => $centre->id, 'code' => $centre->code, 'name' => $centre->name, 'active' => (bool) $centre->is_active,
            ] : null,
            'pending_requests' => FleetFinanceReviewRequest::query()->where('asset_id', $vehicle->id)->where('status', 'submitted')->count(),
            'records' => $records,
            'records_total' => $total,
            'requests' => $requests->map(fn (FleetFinanceReviewRequest $request): array => $this->request($request, $can, $files, (int) $viewer->id))->values()->all(),
            'request_types' => collect(FleetFinanceReviewRequest::TYPES)
                ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all(),
            'sources' => $can['request_review'] ? $this->sources($vehicle, $records) : [],
            'documents' => $can['request_review'] && $can['open_documents'] ? $this->documents($vehicle) : [],
            'link_state' => $this->linkState($records),
        ];
    }

    /** @return array<string,bool> */
    private function permissions(User $viewer, Asset $vehicle): array
    {
        $view = $this->finance->canView($viewer);
        $fleet = $viewer->canDo('fleet.manage');
        $spend = $view && $viewer->canDo('finance.ap.view');

        return [
            'view' => $view,
            // Purchase order and supplier invoice identity and amounts.
            'view_spend' => $spend,
            'link' => $view && $fleet,
            'link_fixed_asset' => $view && $fleet,
            'link_spend' => $spend && $fleet,
            'request_review' => $view && $fleet,
            'decide' => $view && $this->finance->canDecide($viewer),
            'attach_files' => $view && $fleet && Gate::forUser($viewer)->allows('manageDocuments', $vehicle),
            'open_documents' => Gate::forUser($viewer)->allows('view', $vehicle),
        ];
    }

    /**
     * Finance records connected to the vehicle: fixed assets Finance linked to
     * it, supplier invoices Finance recorded against it, and records linked
     * from the vehicle. Each record appears once.
     *
     * @param  array<string,bool>  $can
     * @param  Collection<int, FleetFinanceReviewRequest>  $requests
     * @param  array<int, list<array<string,mixed>>>  $files
     * @return array{0: list<array<string,mixed>>, 1: int}
     */
    private function records(Asset $vehicle, array $can, Collection $requests, array $files): array
    {
        $links = FleetVehicleFinanceLink::query()->where('asset_id', $vehicle->id)->whereNull('unlinked_at')
            ->with('linkedBy:id,name')->orderBy('id')->get()
            ->keyBy(fn (FleetVehicleFinanceLink $link): string => $link->record_type.':'.$link->record_id);
        $ids = fn (string $type): array => $links->where('record_type', $type)->pluck('record_id')->map(fn (mixed $id): int => (int) $id)->values()->all();

        $fixed = FinFixedAsset::query()
            ->where(fn ($query) => $query->where('linked_asset_id', $vehicle->id)->orWhereIn('id', $ids('fixed_asset')))
            ->orderByDesc('id')->get();
        $orders = FinPurchaseOrder::query()->with('vendor:id,name')->whereIn('id', $ids('purchase_order'))
            ->orderByDesc('order_date')->orderByDesc('id')->get();
        $billRelations = ['vendor:id,name', 'purchaseOrder:id,po_number'];
        $canonicalBills = FinBill::query()->with($billRelations)->where('asset_id', $vehicle->id)
            ->orderByDesc('bill_date')->orderByDesc('id')->limit(self::BILL_LIMIT)->get();
        $bills = $canonicalBills->concat(FinBill::query()->with($billRelations)->whereIn('id', $ids('bill'))->get())
            ->unique('id')
            ->sortByDesc(fn (FinBill $bill): string => ($bill->bill_date?->format('Y-m-d') ?? '0000-00-00').str_pad((string) $bill->id, 12, '0', STR_PAD_LEFT))
            ->values();
        $hiddenBills = max(0, FinBill::query()->where('asset_id', $vehicle->id)->count() - $canonicalBills->count());

        $rows = [];
        foreach ([['fixed_asset', $fixed], ['purchase_order', $orders], ['bill', $bills]] as [$type, $models]) {
            foreach ($models as $model) {
                $rows[] = $this->row($type, $model, $vehicle, $links->get($type.':'.$model->getKey()), $can, $requests, $files);
            }
        }
        $found = array_flip(array_map(fn (array $row): string => $row['type'].':'.$row['id'], $rows));
        foreach ($links as $key => $link) {
            if (! isset($found[$key])) {
                $rows[] = $this->unavailableRow($link, $can);
            }
        }

        return [$rows, count($rows) + $hiddenBills];
    }

    /**
     * @param  array<string,bool>  $can
     * @param  Collection<int, FleetFinanceReviewRequest>  $requests
     * @param  array<int, list<array<string,mixed>>>  $files
     * @return array<string,mixed>
     */
    private function row(string $type, Model $record, Asset $vehicle, ?FleetVehicleFinanceLink $link, array $can, Collection $requests, array $files): array
    {
        $canonical = match ($type) {
            'fixed_asset' => (int) $record->linked_asset_id === (int) $vehicle->id,
            'bill' => (int) $record->asset_id === (int) $vehicle->id,
            default => false,
        };
        $basis = $canonical ? ($link ? 'both' : 'finance') : 'vehicle';
        $status = VehicleFinanceService::statusFor($type, $record->status);
        [$owner, $workspace] = self::OWNERS[$type];
        $kind = VehicleFinanceService::KINDS[$type];
        $row = [
            'key' => $type.'-'.$record->getKey(),
            'type' => $type,
            'id' => (int) $record->getKey(),
            'kind' => $kind,
            'status' => $record->status,
            'status_label' => $status['label'],
            'tone' => $status['tone'],
            'owner' => $owner,
            'workspace' => $workspace,
            'basis' => $basis,
            'available' => true,
            'link_id' => $link?->id,
            'can_unlink' => $basis === 'vehicle' && $link !== null && $this->canLink($type, $can),
        ];
        $visible = $type === 'fixed_asset' ? $can['view'] : $can['view_spend'];
        if (! $visible) {
            return [
                ...$row, 'name' => $kind, 'reference' => null, 'amount' => null, 'date' => null,
                'detail' => null, 'basis_note' => null, 'href' => null, 'restricted' => true, 'files' => [],
            ];
        }

        [$name, $reference, $amount, $date, $detail, $href] = match ($type) {
            'fixed_asset' => [
                (string) $record->asset_name,
                $record->asset_tag,
                (float) $record->purchase_cost,
                $record->purchase_date,
                $this->fixedAssetDetail($record),
                '/finance/fixed-assets/'.$record->getKey(),
            ],
            'purchase_order' => [
                implode(' · ', array_filter([
                    $record->vendor?->name,
                    Str::limit((string) Str::of((string) $record->notes)->before("\n")->trim(), 60, '…'),
                ])) ?: $kind,
                $record->po_number,
                (float) $record->total_amount,
                $record->order_date,
                'Order total including GST ('.VehicleFinanceService::money((float) $record->subtotal).' net + '
                    .VehicleFinanceService::money((float) $record->gst_amount).' GST). Approval does not establish payment.',
                '/finance/purchase-orders/'.$record->getKey(),
            ],
            default => [
                implode(' · ', array_filter([$record->vendor?->name, $record->vendor_reference])) ?: $kind,
                $record->bill_number,
                (float) $record->total_amount,
                $record->bill_date,
                $this->billDetail($record),
                '/finance/bills/'.$record->getKey(),
            ],
        };

        return [
            ...$row,
            'name' => $name,
            'reference' => $reference,
            'amount' => $amount,
            'date' => $date?->toDateString(),
            'detail' => $detail,
            'basis_note' => $this->basisNote($type, $basis, $link),
            'href' => $href,
            'restricted' => false,
            'files' => $this->recordFiles($type, (int) $record->getKey(), $requests, $files),
        ];
    }

    /** A vehicle link whose Finance record is no longer in Finance. @param array<string,bool> $can @return array<string,mixed> */
    private function unavailableRow(FleetVehicleFinanceLink $link, array $can): array
    {
        $type = $link->record_type;
        [$owner, $workspace] = self::OWNERS[$type] ?? ['Finance', 'Finance'];
        $kind = VehicleFinanceService::KINDS[$type] ?? 'Finance record';
        $visible = $type === 'fixed_asset' ? $can['view'] : $can['view_spend'];

        return [
            'key' => $type.'-'.$link->record_id,
            'type' => $type,
            'id' => (int) $link->record_id,
            'kind' => $kind,
            'status' => null,
            'status_label' => 'Unavailable',
            'tone' => 'neutral',
            'owner' => $owner,
            'workspace' => $workspace,
            'basis' => 'vehicle',
            'available' => false,
            'link_id' => $link->id,
            'can_unlink' => $this->canLink($type, $can),
            'name' => $kind.' no longer in Finance',
            'reference' => null,
            'amount' => null,
            'date' => null,
            'detail' => null,
            'basis_note' => $visible ? $this->basisNote($type, 'vehicle', $link) : null,
            'href' => null,
            'restricted' => ! $visible,
            'files' => [],
        ];
    }

    /** @param array<string,bool> $can */
    private function canLink(string $type, array $can): bool
    {
        return $type === 'fixed_asset' ? $can['link_fixed_asset'] : $can['link_spend'];
    }

    private function fixedAssetDetail(FinFixedAsset $asset): string
    {
        $parts = ['Acquisition value. Book value '.VehicleFinanceService::money($asset->getBookValue()).' after '
            .VehicleFinanceService::money((float) $asset->accumulated_depreciation).' depreciation.'];
        if ($asset->disposed_date) {
            $parts[] = 'Disposed on '.$asset->disposed_date->format('j M Y').'.';
        }
        $parts[] = 'Capitalisation, depreciation and disposal stay with Finance.';

        return implode(' ', $parts);
    }

    private function billDetail(FinBill $bill): string
    {
        $parts = [VehicleFinanceService::money((float) $bill->subtotal).' net + '.VehicleFinanceService::money((float) $bill->gst_amount).' GST.'];
        if ((float) $bill->amount_paid > 0 && $bill->status !== 'paid') {
            $parts[] = VehicleFinanceService::money((float) $bill->amount_paid).' paid so far.';
        }
        if ($bill->purchaseOrder?->po_number) {
            $parts[] = 'Raised from purchase order '.$bill->purchaseOrder->po_number.'.';
        }
        $parts[] = 'Invoice approval and payment remain Finance-owned.';

        return implode(' ', $parts);
    }

    private function basisNote(string $type, string $basis, ?FleetVehicleFinanceLink $link): ?string
    {
        if ($basis !== 'vehicle') {
            return $type === 'fixed_asset'
                ? 'Finance links this fixed asset to the vehicle.'
                : 'Finance recorded this invoice against the vehicle.';
        }
        if (! $link) {
            return null;
        }
        $when = $link->created_at
            ? ' on '.CarbonImmutable::instance($link->created_at)->setTimezone((string) config('app.worker_timezone', 'Pacific/Auckland'))->format('j M Y')
            : '';

        return 'Linked from this vehicle by '.($link->linkedBy?->name ?? 'a former user').$when.': '.$link->reason;
    }

    /**
     * Files submitted to Finance about a record, from the requests whose
     * source is that record.
     *
     * @param  Collection<int, FleetFinanceReviewRequest>  $requests
     * @param  array<int, list<array<string,mixed>>>  $files
     * @return list<array<string,mixed>>
     */
    private function recordFiles(string $type, int $id, Collection $requests, array $files): array
    {
        return collect($requests)
            ->filter(fn (FleetFinanceReviewRequest $request): bool => $request->source_type === $type && (int) $request->source_id === $id)
            ->flatMap(fn (FleetFinanceReviewRequest $request): array => $files[$request->id] ?? [])
            ->unique('id')->values()->all();
    }

    /**
     * @param  array<string,bool>  $can
     * @param  array<int, list<array<string,mixed>>>  $files
     * @return array<string,mixed>
     */
    private function request(FleetFinanceReviewRequest $request, array $can, array $files, int $viewerId): array
    {
        [$label, $tone] = match ($request->status) {
            'resolved' => ['Resolved', 'success'],
            'declined' => ['Declined', 'neutral'],
            default => ['Pending Finance review', 'warning'],
        };
        $restrictedSource = in_array($request->source_type, ['purchase_order', 'bill'], true) && ! $can['view_spend'];

        return [
            'id' => $request->id,
            'reference' => $request->reference_number,
            'type' => $request->request_type,
            'type_label' => $request->typeLabel(),
            'source' => [
                'type' => $request->source_type,
                'id' => $request->source_id,
                'label' => $restrictedSource
                    ? VehicleFinanceService::KINDS[$request->source_type].' · accounts payable access needed'
                    : $request->source_label,
            ],
            'amount' => $request->amount === null ? null : (float) $request->amount,
            'note' => $request->note,
            'status' => $request->status,
            'status_label' => $label,
            'tone' => $tone,
            'requested_by' => $request->requestedBy?->name,
            'requested_at' => $request->created_at?->toIso8601String(),
            'decided_by' => $request->decidedBy?->name,
            'decided_at' => $request->decided_at?->toIso8601String(),
            'decision_note' => $request->decision_note,
            'lock_version' => $request->lock_version,
            'history' => $request->events->map(fn (FleetFinanceReviewRequestEvent $event): array => [
                'id' => $event->id,
                'action' => $event->action,
                'label' => match ($event->action) {
                    'submitted' => 'Submitted to Finance',
                    'resolved' => 'Resolved by Finance',
                    'declined' => 'Declined by Finance',
                    default => 'Updated',
                },
                'actor' => $event->actor?->name,
                'note' => $event->note,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
            ])->values()->all(),
            'files' => $files[$request->id] ?? [],
            // Someone other than the person who asked decides (VehicleFinanceService::decide).
            'can_decide' => $can['decide'] && $request->isOpen()
                && (int) $request->requested_by_user_id !== $viewerId,
            'can_add_files' => $can['attach_files'] && $request->isOpen(),
        ];
    }

    /**
     * Supporting files per request: files uploaded with the request (private,
     * virus-checked vehicle documents it owns) and the existing vehicle
     * document it referenced.
     *
     * @param  Collection<int, FleetFinanceReviewRequest>  $requests
     * @return array<int, list<array<string,mixed>>>
     */
    private function requestFiles(Asset $vehicle, Collection $requests, bool $canOpen): array
    {
        if ($requests->isEmpty()) {
            return [];
        }
        $map = [];
        AssetDocument::query()->where('asset_id', $vehicle->id)->where('source_type', 'finance_review_request')
            ->whereIn('source_id', $requests->pluck('id'))->whereNull('archived_at')->orderBy('id')->get()
            ->each(function (AssetDocument $file) use (&$map, $vehicle, $canOpen): void {
                $map[(int) $file->source_id][] = $this->file($vehicle, $file, $canOpen);
            });
        $referenced = $requests->pluck('existing_document_id')->filter()->unique()->values();
        if ($referenced->isNotEmpty()) {
            $existing = AssetDocument::query()->where('asset_id', $vehicle->id)->whereIn('id', $referenced)->get()->keyBy('id');
            foreach ($requests as $request) {
                $document = $request->existing_document_id ? $existing->get($request->existing_document_id) : null;
                if ($document) {
                    $map[$request->id][] = $this->file($vehicle, $document, $canOpen);
                }
            }
        }

        return $map;
    }

    /** @return array<string,mixed> */
    private function file(Asset $vehicle, AssetDocument $file, bool $canOpen): array
    {
        return [
            'id' => $file->id,
            'name' => $file->original_name ?: $file->title,
            'state' => $file->state,
            'url' => $canOpen && $file->isOpenable()
                ? route('fleet-assets.vehicles.documents.file', ['asset' => $vehicle->id, 'document' => $file->id])
                : null,
            'waiting' => in_array($file->state, self::WAITING_STATES, true),
        ];
    }

    /**
     * Where a review request can come from: the vehicle, its maintenance work
     * or a Finance record connected to it that the viewer may open.
     *
     * @param  list<array<string,mixed>>  $records
     * @return list<array{value: string, label: string, detail: string}>
     */
    private function sources(Asset $vehicle, array $records): array
    {
        $sources = [[
            'value' => 'vehicle',
            'label' => VehicleFinanceService::vehicleLabel($vehicle),
            'detail' => (string) ($vehicle->asset_tag ?: $vehicle->name),
        ]];
        $orders = FleetWorkOrder::query()->where('asset_id', $vehicle->id)->orderByDesc('id')->limit(self::WORK_LIMIT)
            ->get(['id', 'reference_number', 'title', 'status']);
        foreach ($orders as $order) {
            $sources[] = [
                'value' => 'work_order:'.$order->id,
                'label' => VehicleFinanceService::workOrderLabel($order),
                'detail' => 'Maintenance work · '.ucfirst(str_replace('_', ' ', (string) $order->status)),
            ];
        }
        foreach ($records as $record) {
            if ($record['available'] && ! $record['restricted']) {
                $sources[] = [
                    'value' => $record['type'].':'.$record['id'],
                    'label' => implode(' · ', array_filter([$record['reference'], $record['name']])),
                    'detail' => $record['kind'].' · '.$record['status_label'],
                ];
            }
        }

        return $sources;
    }

    /**
     * Current general documents of the vehicle that a request can refer to.
     *
     * @return list<array{id: int, name: string, detail: string}>
     */
    private function documents(Asset $vehicle): array
    {
        return AssetDocument::query()
            ->join('asset_document_sets as document_set', 'document_set.id', '=', 'asset_documents.document_set_id')
            ->where('asset_documents.asset_id', $vehicle->id)->whereNull('asset_documents.archived_at')
            ->whereNull('document_set.source_type')->whereNull('document_set.archived_at')
            ->whereColumn('document_set.current_revision', 'asset_documents.revision')
            ->orderByDesc('asset_documents.id')->limit(self::DOCUMENT_LIMIT)
            ->get([
                'asset_documents.id', 'asset_documents.original_name', 'asset_documents.title',
                'document_set.category as set_category', 'document_set.reference as set_reference',
                'document_set.document_date as set_document_date',
            ])
            ->map(fn (AssetDocument $row): array => [
                'id' => (int) $row->id,
                'name' => (string) ($row->original_name ?: $row->title),
                'detail' => implode(' · ', array_filter([
                    $row->set_category ?: 'Evidence',
                    $row->set_reference ?: ($row->set_document_date
                        ? 'dated '.CarbonImmutable::parse((string) $row->set_document_date)->format('j M Y')
                        : null),
                ])),
            ])->values()->all();
    }

    /**
     * The fixed-asset field of the link wizard: Finance's own link is locked;
     * a link made from the vehicle can be replaced or removed.
     *
     * @param  list<array<string,mixed>>  $records
     * @return array{finance_fixed_asset: ?array<string,mixed>, vehicle_fixed_asset: ?array<string,mixed>}
     */
    private function linkState(array $records): array
    {
        $summary = fn (array $record): array => [
            'id' => $record['id'],
            'label' => implode(' · ', array_filter([$record['reference'], $record['name']])),
            'link_id' => $record['link_id'],
        ];
        $fixed = array_values(array_filter($records, fn (array $record): bool => $record['type'] === 'fixed_asset'));
        $finance = array_values(array_filter($fixed, fn (array $record): bool => $record['basis'] !== 'vehicle'));
        $vehicle = array_values(array_filter($fixed, fn (array $record): bool => $record['basis'] === 'vehicle'));

        return [
            'finance_fixed_asset' => $finance === [] ? null : $summary($finance[0]),
            'vehicle_fixed_asset' => $vehicle === [] ? null : $summary($vehicle[0]),
        ];
    }
}
