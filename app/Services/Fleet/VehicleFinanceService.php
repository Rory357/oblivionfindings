<?php

namespace App\Services\Fleet;

use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinCostCentre;
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
use App\Notifications\AppEventNotification;
use App\Services\AuditLogger;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The vehicle's connection to Finance: links to existing Finance records and
 * review requests that Finance decides. Nothing here approves spend, pays an
 * invoice, posts a journal or edits a Finance record.
 *
 * Every command re-resolves the vehicle inside the actor's site scope and
 * checks the Finance permission the record type needs, the same gates as the
 * record's own Finance page: fixed assets need finance.assets.view; purchase
 * orders and supplier invoices need finance.ap.view.
 */
class VehicleFinanceService
{
    public const SEARCH_LIMIT = 20;

    /** Central Finance oversight: these open every Site's review requests to Finance. */
    public const FINANCE_SITE_BYPASS = ['sites.viewAll', 'finance.insights.viewAllSites', 'finance.payments.viewAllSites'];

    public const KINDS = [
        'fixed_asset' => 'Fixed asset',
        'purchase_order' => 'Purchase order',
        'bill' => 'Supplier invoice',
    ];

    /** Finance status => [label, tone]. Approval and payment stay Finance-owned. */
    public const STATUS = [
        'fixed_asset' => [
            'active' => ['Active', 'info'],
            'fully_depreciated' => ['Fully depreciated', 'info'],
            'disposed' => ['Disposed', 'neutral'],
        ],
        'purchase_order' => [
            'draft' => ['Draft', 'info'],
            'approved' => ['Approved', 'success'],
            'sent' => ['Sent to supplier', 'info'],
            'partially_received' => ['Part received', 'info'],
            'received' => ['Received', 'success'],
            'cancelled' => ['Cancelled', 'neutral'],
        ],
        'bill' => [
            'draft' => ['Draft', 'info'],
            'awaiting_approval' => ['Awaiting approval', 'info'],
            'approved' => ['Awaiting payment', 'info'],
            'partially_paid' => ['Part paid', 'info'],
            'paid' => ['Paid', 'success'],
            'cancelled' => ['Cancelled', 'neutral'],
        ],
    ];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function canView(User $actor): bool
    {
        return $actor->canDo('finance.assets.view');
    }

    public function canLinkType(User $actor, string $type): bool
    {
        return array_key_exists($type, self::KINDS)
            && $actor->canDo('fleet.manage')
            && $actor->canDo(self::viewPermission($type));
    }

    public function canRequest(User $actor): bool
    {
        return $actor->canDo('fleet.manage');
    }

    public function canDecide(User $actor): bool
    {
        return $actor->canDo('finance.assets.manage') || $actor->canDo('finance.ap.manage');
    }

    /** The permission that opens a record type's own Finance page. */
    public static function viewPermission(string $type): string
    {
        return $type === 'fixed_asset' ? 'finance.assets.view' : 'finance.ap.view';
    }

    /** The Finance cost centre for the vehicle's site, if Finance has set one up. */
    public function siteCostCentre(Asset $asset): ?FinCostCentre
    {
        if (! $asset->site_id) {
            return null;
        }

        return FinCostCentre::query()->where('type', 'site')->where('site_id', $asset->site_id)
            ->orderByDesc('is_active')->orderBy('id')->first();
    }

    /**
     * Link existing Finance records to the vehicle. A present `fixed_asset_id`
     * sets the vehicle's own fixed-asset link (null removes it, another id
     * replaces it); `record_type` + `record_id` add a purchase order or
     * supplier invoice. One reason covers the whole change.
     *
     * @param  array<string,mixed>  $data
     * @return list<FleetVehicleFinanceLink>
     */
    public function link(User $actor, int $assetId, array $data, string $requestKey): array
    {
        self::assertKey($requestKey);
        $change = $this->validLinkChange($data);
        $fingerprint = MaintenanceFingerprint::of(['actor' => (int) $actor->id, 'asset' => $assetId, 'change' => $change]);

        return $this->guardDuplicate(fn (): array => DB::transaction(function () use ($actor, $assetId, $change, $requestKey, $fingerprint): array {
            [$current, $asset] = $this->resolve($actor, $assetId, 'fleet.manage');
            $prior = FleetVehicleFinanceLink::query()->where('asset_id', $asset->id)
                ->where(fn (Builder $query): Builder => $query->where('request_key', $requestKey)->orWhere('unlink_request_key', $requestKey))
                ->lockForUpdate()->get();
            if ($prior->isNotEmpty()) {
                $same = $prior->every(fn (FleetVehicleFinanceLink $link): bool => hash_equals(
                    (string) ($link->request_key === $requestKey ? $link->request_fingerprint : $link->unlink_request_fingerprint),
                    $fingerprint,
                ));
                abort_unless($same, 409, 'This request was already used for a different Finance link.');

                return $prior->all();
            }

            $linked = [];
            $removed = [];
            if ($change['fixed_asset']) {
                abort_unless($current->canDo('finance.assets.view'), 403);
                [$linked, $removed] = $this->setFixedAsset($current, $asset, $change['fixed_asset_id'], $change['reason'], $requestKey, $fingerprint);
            }
            if ($change['record_type'] !== null) {
                abort_unless($current->canDo('finance.ap.view'), 403);
                $linked[] = $this->addSpendRecord($current, $asset, $change['record_type'], (int) $change['record_id'], $change['reason'], $requestKey, $fingerprint);
            }
            AuditLogger::logOrFail('fleet.vehicle.finance.link', $asset, [
                'asset_id' => $asset->id,
                'reason' => $change['reason'],
                'linked' => array_map(fn (FleetVehicleFinanceLink $link): array => ['type' => $link->record_type, 'id' => $link->record_id], $linked),
                'removed' => array_map(fn (FleetVehicleFinanceLink $link): array => ['type' => $link->record_type, 'id' => $link->record_id], $removed),
                'actor_id' => $current->id,
            ]);

            return [...$linked, ...$removed];
        }, 3), 'This record was linked while you were working. Reload to see the current Finance records.');
    }

    /** Remove a link made from the vehicle. The Finance record and the link history stay. */
    public function unlink(User $actor, int $assetId, int $linkId, string $reason, string $requestKey): FleetVehicleFinanceLink
    {
        self::assertKey($requestKey);
        $reason = self::requiredText($reason, 'reason', 'Record why this link is removed.');
        $fingerprint = MaintenanceFingerprint::of(['actor' => (int) $actor->id, 'link' => $linkId, 'reason' => $reason]);

        return DB::transaction(function () use ($actor, $assetId, $linkId, $reason, $requestKey, $fingerprint): FleetVehicleFinanceLink {
            [$current, $asset] = $this->resolve($actor, $assetId, 'fleet.manage');
            $link = FleetVehicleFinanceLink::query()->whereKey($linkId)->where('asset_id', $asset->id)->lockForUpdate()->first() ?? abort(404);
            abort_unless($this->canLinkType($current, $link->record_type), 403);
            if ($link->unlinked_at !== null) {
                abort_unless($link->unlink_request_key === $requestKey, 409, 'This link was already removed. Reload to see the current Finance records.');
                abort_unless(hash_equals((string) $link->unlink_request_fingerprint, $fingerprint), 409, 'This request was already used for a different change.');

                return $link;
            }
            $this->close($link, $current, $reason, $requestKey, $fingerprint);
            AuditLogger::logOrFail('fleet.vehicle.finance.unlink', $asset, [
                'asset_id' => $asset->id,
                'reason' => $reason,
                'removed' => [['type' => $link->record_type, 'id' => $link->record_id]],
                'actor_id' => $current->id,
            ]);

            return $link;
        }, 3);
    }

    /**
     * Finance records that could be linked, newest first and bounded. Supplier
     * invoices must be recorded for the vehicle's site and not against another
     * asset; purchase orders default to the site cost centre and are otherwise
     * found by search.
     *
     * @return array{results: list<array<string,mixed>>, next_before: ?int, scope: string}
     */
    public function linkable(User $actor, int $assetId, string $type, string $search, ?int $before): array
    {
        abort_unless(array_key_exists($type, self::KINDS), 404);
        $current = User::query()->findOrFail($actor->id);
        abort_unless($this->canLinkType($current, $type), 403);
        $asset = $this->access->assignableVehicle($current, $assetId) ?? abort(404);
        $search = mb_substr(trim($search), 0, 80);
        $like = '%'.addcslashes($search, '%_\\').'%';
        $linked = FleetVehicleFinanceLink::query()->where('asset_id', $asset->id)->where('record_type', $type)
            ->whereNull('unlinked_at')->pluck('record_id')->map(fn (mixed $id): int => (int) $id)->all();
        $scope = $search !== '' ? 'search' : 'recent';

        $query = match ($type) {
            'fixed_asset' => FinFixedAsset::query()
                // Finance-linked fixed assets are already shown; others belong elsewhere.
                ->whereNull('linked_asset_id')
                ->whereNotIn('id', FleetVehicleFinanceLink::query()->select('record_id')
                    ->where('record_type', 'fixed_asset')->whereNull('unlinked_at'))
                ->when($search === '', fn (Builder $query): Builder => $query->where('category', 'vehicle'))
                ->when($search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $name): Builder => $name
                    ->where('asset_name', 'like', $like)->orWhere('asset_tag', 'like', $like))),
            'purchase_order' => FinPurchaseOrder::query()->with('vendor:id,name')
                ->where('status', '!=', 'cancelled')->whereNotIn('id', $linked)
                ->when($search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $match) => $match
                    ->where('po_number', 'like', $like)->orWhere('notes', 'like', $like)
                    ->orWhereHas('vendor', fn (Builder $vendor): Builder => $vendor->where('name', 'like', $like)))),
            'bill' => FinBill::query()->with('vendor:id,name')
                // Cast so a vehicle without a site never matches invoices without one.
                ->where('site_id', (int) $asset->site_id)->whereNull('asset_id')
                ->where('status', '!=', 'cancelled')->whereNotIn('id', $linked)
                ->when($search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $match) => $match
                    ->where('bill_number', 'like', $like)->orWhere('vendor_reference', 'like', $like)
                    ->orWhereHas('vendor', fn (Builder $vendor): Builder => $vendor->where('name', 'like', $like)))),
        };
        if ($type === 'purchase_order' && $search === '') {
            $centre = $this->siteCostCentre($asset);
            $scope = $centre ? 'site_cost_centre' : 'none';
            $centre ? $query->where('cost_centre_id', $centre->id) : $query->whereRaw('1 = 0');
        }

        $rows = $query->when($before !== null, fn (Builder $query): Builder => $query->where('id', '<', $before))
            ->orderByDesc('id')->limit(self::SEARCH_LIMIT + 1)->get();
        $page = $rows->take(self::SEARCH_LIMIT);

        return [
            'results' => $page->map(fn (Model $record): array => $this->option($type, $record))->values()->all(),
            'next_before' => $rows->count() > self::SEARCH_LIMIT ? (int) $page->last()->getKey() : null,
            'scope' => $scope,
        ];
    }

    /**
     * Route evidence to Finance for a decision. Supporting files are uploaded
     * next, as private vehicle documents owned by the request.
     *
     * @param  array<string,mixed>  $data
     */
    public function createRequest(User $actor, int $assetId, array $data, string $requestKey): FleetFinanceReviewRequest
    {
        self::assertKey($requestKey);
        $values = $this->validRequest($data);
        $fingerprint = MaintenanceFingerprint::of(['actor' => (int) $actor->id, 'asset' => $assetId, 'request' => $values]);

        $request = $this->guardDuplicate(fn (): FleetFinanceReviewRequest => DB::transaction(function () use ($actor, $assetId, $values, $requestKey, $fingerprint): FleetFinanceReviewRequest {
            [$current, $asset] = $this->resolve($actor, $assetId, 'fleet.manage');
            $prior = FleetFinanceReviewRequest::query()->where('asset_id', $asset->id)->where('request_key', $requestKey)->lockForUpdate()->first();
            if ($prior) {
                abort_unless(hash_equals((string) $prior->request_fingerprint, $fingerprint), 409, 'This request was already used for a different Finance review request.');

                return $prior;
            }
            [$sourceType, $sourceId, $sourceLabel] = $this->source($current, $asset, $values['source']);
            $documentId = $values['existing_document_id'] === null
                ? null
                : $this->existingDocument($current, $asset, $values['existing_document_id']);
            $open = FleetFinanceReviewRequest::query()->where('asset_id', $asset->id)->where('status', 'submitted')
                ->where('request_type', $values['request_type'])->where('source_type', $sourceType)
                ->when($sourceId === null, fn (Builder $query): Builder => $query->whereNull('source_id'), fn (Builder $query): Builder => $query->where('source_id', $sourceId))
                ->lockForUpdate()->first(['id']);
            if ($open) {
                throw ValidationException::withMessages(['request_type' => 'An open request already exists for this source and request type. Open the existing request in Finance.']);
            }
            $request = FleetFinanceReviewRequest::query()->create([
                'asset_id' => $asset->id,
                'request_type' => $values['request_type'],
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_label' => mb_substr($sourceLabel, 0, 255),
                'amount' => $values['amount'],
                'note' => $values['note'],
                'existing_document_id' => $documentId,
                'status' => 'submitted',
                'lock_version' => 1,
                'requested_by_user_id' => $current->id,
                'request_key' => $requestKey,
                'request_fingerprint' => $fingerprint,
            ]);
            $this->event($request, $current, 'submitted', null, $requestKey, $fingerprint);
            AuditLogger::logOrFail('fleet.vehicle.finance.review_request', $asset, [
                'asset_id' => $asset->id,
                'review_request_id' => $request->id,
                'request_type' => $request->request_type,
                'reason' => $request->note,
                'actor_id' => $current->id,
            ]);

            return $request;
        }, 3), 'This request was saved while you were working. Reload to check it.');

        // A new request (not a retry) tells Finance where to decide it.
        if ($request->wasRecentlyCreated) {
            $this->notifyFinance($request, $actor);
        }

        return $request;
    }

    /**
     * The Sites whose vehicles this person handles for Finance: their own
     * Sites, or every Site with central Finance oversight. Finance decides
     * review requests under this rule, not the Fleet one.
     *
     * @return list<int>
     */
    public function financeSiteIds(User $actor): array
    {
        return $this->siteAccess->accessibleSiteIds($actor, self::FINANCE_SITE_BYPASS);
    }

    /** In-app notice to the Finance people who can decide the request, except the requester. */
    private function notifyFinance(FleetFinanceReviewRequest $request, User $requester): void
    {
        $asset = Asset::query()->find($request->asset_id, ['id', 'name', 'registration_number', 'site_id']);
        if (! $asset) {
            return;
        }
        $keys = ['finance.assets.manage', 'finance.ap.manage'];
        $recipients = User::query()->whereNotNull('approved_at')->whereKeyNot($requester->id)
            ->where(fn (Builder $query): Builder => $query
                ->whereHas('roles.permissions', fn (Builder $permission): Builder => $permission->whereIn('key', $keys))
                ->orWhereHas('permissionOverrides', fn (Builder $permission): Builder => $permission->whereIn('permissions.key', $keys)))
            ->limit(200)->get()
            ->filter(fn (User $user): bool => $this->canDecide($user)
                && ($user->canDo('finance.assets.view') || $user->canDo('finance.ap.view'))
                && in_array((int) $asset->site_id, $this->financeSiteIds($user), true));
        $vehicle = trim($asset->name.($asset->registration_number ? ' · '.$asset->registration_number : ''));
        foreach ($recipients as $recipient) {
            try {
                $recipient->notify(new AppEventNotification([
                    'kind' => 'fleet_finance_review_request',
                    'event_key' => 'fleet.vehicle.finance.review_request',
                    'action' => 'review',
                    'entity' => 'Vehicle review request',
                    'entity_id' => $request->id,
                    'title' => $request->typeLabel().' requested · '.$vehicle,
                    'body' => 'Resolve or decline it in Finance › Vehicle reviews. It changes no Finance record by itself.',
                    'url' => '/finance/vehicle-reviews?request='.$request->id,
                    'actor' => ['id' => $requester->id, 'name' => $requester->name],
                ]));
            } catch (Throwable $exception) {
                // The request stands; it is also listed in All Tasks.
                report($exception);
            }
        }
    }

    /**
     * Finance resolves or declines a request with a note, from Finance ›
     * Vehicle reviews or the vehicle's Finance view. The vehicle is resolved
     * under Finance's Site rule (financeSiteIds), so Finance needs no Fleet
     * access to decide.
     */
    public function decide(User $actor, int $assetId, int $requestId, string $decision, string $note, int $expectedVersion, string $requestKey): FleetFinanceReviewRequest
    {
        self::assertKey($requestKey);
        if (! in_array($decision, FleetFinanceReviewRequest::DECISIONS, true)) {
            throw ValidationException::withMessages(['decision' => 'Choose whether to resolve or decline the request.']);
        }
        $note = self::requiredText($note, 'note', 'Record the Finance decision and any next step.');
        $fingerprint = MaintenanceFingerprint::of(['actor' => (int) $actor->id, 'request' => $requestId, 'decision' => $decision, 'note' => $note]);

        return DB::transaction(function () use ($actor, $assetId, $requestId, $decision, $note, $expectedVersion, $requestKey, $fingerprint): FleetFinanceReviewRequest {
            $current = User::query()->findOrFail($actor->id);
            abort_unless($this->canDecide($current), 403);
            $asset = Asset::query()->whereKey($assetId)->whereNotNull('site_id')
                ->whereIn('site_id', $this->financeSiteIds($current))->lockForUpdate()->first() ?? abort(404);
            $request = FleetFinanceReviewRequest::query()->whereKey($requestId)->where('asset_id', $asset->id)->lockForUpdate()->first() ?? abort(404);
            $prior = FleetFinanceReviewRequestEvent::query()->where('review_request_id', $request->id)->where('request_key', $requestKey)->lockForUpdate()->first();
            if ($prior) {
                abort_unless(hash_equals((string) $prior->request_fingerprint, $fingerprint), 409, 'This request was already used for a different decision.');

                return $request;
            }
            abort_unless($request->lock_version === $expectedVersion, 409, 'This review request changed while you were deciding. Reload before saving.');
            abort_unless($request->isOpen(), 409, 'Finance has already decided this review request.');
            // Separation of duties: the person who asked for the review can't decide it.
            if ((int) $request->requested_by_user_id === (int) $current->id) {
                throw ValidationException::withMessages(['decision' => 'Someone other than the person who asked for this review must decide it.']);
            }
            $request->forceFill([
                'status' => $decision,
                'decided_by_user_id' => $current->id,
                'decided_at' => now(),
                'decision_note' => $note,
                'lock_version' => $request->lock_version + 1,
            ])->save();
            $this->event($request, $current, $decision, $note, $requestKey, $fingerprint);
            AuditLogger::logOrFail('fleet.vehicle.finance.review_decision', $asset, [
                'asset_id' => $asset->id,
                'review_request_id' => $request->id,
                'decision' => $decision,
                'reason' => $note,
                'actor_id' => $current->id,
            ]);

            return $request;
        }, 3);
    }

    /** Whether a Finance record is connected to the vehicle by Finance or by an active link. */
    public function isConnected(Asset $asset, string $type, int $id): bool
    {
        $explicit = FleetVehicleFinanceLink::query()->where('asset_id', $asset->id)->where('record_type', $type)
            ->where('record_id', $id)->whereNull('unlinked_at')->exists();

        return $explicit || match ($type) {
            'fixed_asset' => FinFixedAsset::query()->whereKey($id)->where('linked_asset_id', $asset->id)->exists(),
            'bill' => FinBill::query()->whereKey($id)->where('asset_id', $asset->id)->exists(),
            default => false,
        };
    }

    /** @return array{label: string, tone: string} */
    public static function statusFor(string $type, ?string $status): array
    {
        $known = self::STATUS[$type][(string) $status] ?? null;

        return $known
            ? ['label' => $known[0], 'tone' => $known[1]]
            : ['label' => $status ? ucfirst(str_replace('_', ' ', $status)) : 'Not recorded', 'tone' => 'neutral'];
    }

    public static function money(float $amount): string
    {
        return ($amount < 0 ? '-$' : '$').number_format(abs($amount), 2);
    }

    public static function vehicleLabel(Asset $asset): string
    {
        return ($asset->registration_number ?: $asset->name).' · Vehicle record';
    }

    public static function workOrderLabel(FleetWorkOrder $order): string
    {
        return implode(' · ', array_filter([$order->reference_number, $order->title])) ?: 'Maintenance work';
    }

    /** "Reference · name" for a Finance record, as shown in pickers and request sources. */
    public static function recordLabel(string $type, Model $record): string
    {
        return implode(' · ', array_filter(match ($type) {
            'fixed_asset' => [$record->asset_tag, $record->asset_name],
            'purchase_order' => [$record->po_number, $record->vendor?->name],
            default => [$record->bill_number, $record->vendor?->name],
        })) ?: self::KINDS[$type];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array{reason: string, fixed_asset: bool, fixed_asset_id: ?int, record_type: ?string, record_id: ?int}
     */
    private function validLinkChange(array $data): array
    {
        Validator::make($data, [
            'reason' => ['required', 'string', 'max:2000'],
            'fixed_asset_id' => ['nullable', 'integer', 'min:1'],
            'record_type' => ['nullable', 'string', 'in:purchase_order,bill', 'required_with:record_id'],
            'record_id' => ['nullable', 'integer', 'min:1', 'required_with:record_type'],
        ], [
            'reason.required' => 'Record the reason for this change.',
            'record_type.in' => 'Choose a purchase order or supplier invoice.',
        ], [
            'reason' => 'reason', 'fixed_asset_id' => 'fixed asset',
            'record_type' => 'record type', 'record_id' => 'purchase order or supplier invoice',
        ])->validate();
        $reason = self::requiredText((string) $data['reason'], 'reason', 'Record the reason for this change.');
        $fixedAsset = array_key_exists('fixed_asset_id', $data);
        $recordType = ($data['record_type'] ?? null) ?: null;
        if (! $fixedAsset && $recordType === null) {
            throw ValidationException::withMessages(['record_id' => 'Choose a record to link, or change the fixed asset.']);
        }

        return [
            'reason' => $reason,
            'fixed_asset' => $fixedAsset,
            'fixed_asset_id' => $fixedAsset && $data['fixed_asset_id'] !== null ? (int) $data['fixed_asset_id'] : null,
            'record_type' => $recordType,
            'record_id' => $recordType !== null ? (int) $data['record_id'] : null,
        ];
    }

    /** @return array{0: list<FleetVehicleFinanceLink>, 1: list<FleetVehicleFinanceLink>} */
    private function setFixedAsset(User $actor, Asset $asset, ?int $fixedAssetId, string $reason, string $key, string $fingerprint): array
    {
        if (FinFixedAsset::query()->where('linked_asset_id', $asset->id)->exists()) {
            throw ValidationException::withMessages(['fixed_asset_id' => 'Finance has already linked a fixed asset to this vehicle. Ask Finance to change it.']);
        }
        $existing = FleetVehicleFinanceLink::query()->where('asset_id', $asset->id)->where('record_type', 'fixed_asset')
            ->whereNull('unlinked_at')->lockForUpdate()->get();
        if ($fixedAssetId === null) {
            if ($existing->isEmpty()) {
                throw ValidationException::withMessages(['fixed_asset_id' => 'No fixed asset is linked to this vehicle.']);
            }
            $existing->each(fn (FleetVehicleFinanceLink $link) => $this->close($link, $actor, $reason, $key, $fingerprint));

            return [[], $existing->all()];
        }
        if ($existing->contains('record_id', $fixedAssetId)) {
            throw ValidationException::withMessages(['fixed_asset_id' => 'This fixed asset is already linked to the vehicle.']);
        }
        $fixed = FinFixedAsset::query()->whereKey($fixedAssetId)->lockForUpdate()->first();
        if (! $fixed) {
            throw ValidationException::withMessages(['fixed_asset_id' => 'Choose a fixed asset from the Finance register.']);
        }
        if ($fixed->linked_asset_id !== null && (int) $fixed->linked_asset_id !== (int) $asset->id) {
            throw ValidationException::withMessages(['fixed_asset_id' => 'Finance has linked this fixed asset to another vehicle or asset.']);
        }
        // A locking read sees links committed by concurrent saves for other vehicles.
        $elsewhere = FleetVehicleFinanceLink::query()->where('record_type', 'fixed_asset')->where('record_id', $fixed->id)
            ->whereNull('unlinked_at')->where('asset_id', '!=', $asset->id)->lockForUpdate()->first(['id']);
        if ($elsewhere) {
            throw ValidationException::withMessages(['fixed_asset_id' => 'This fixed asset is already linked to another vehicle.']);
        }
        $existing->each(fn (FleetVehicleFinanceLink $link) => $this->close($link, $actor, $reason, $key, $fingerprint));

        return [[$this->open($asset, $actor, 'fixed_asset', (int) $fixed->id, $reason, $key, $fingerprint)], $existing->all()];
    }

    private function addSpendRecord(User $actor, Asset $asset, string $type, int $id, string $reason, string $key, string $fingerprint): FleetVehicleFinanceLink
    {
        $record = $type === 'bill'
            ? FinBill::query()->whereKey($id)->lockForUpdate()->first()
            : FinPurchaseOrder::query()->whereKey($id)->lockForUpdate()->first();
        if (! $record) {
            throw ValidationException::withMessages(['record_id' => $type === 'bill' ? 'Choose a supplier invoice from Finance.' : 'Choose a purchase order from Finance.']);
        }
        $problem = $record instanceof FinBill ? $this->billProblem($asset, $record) : $this->purchaseOrderProblem($record);
        if ($problem !== null) {
            throw ValidationException::withMessages(['record_id' => $problem]);
        }
        $existing = FleetVehicleFinanceLink::query()->where('asset_id', $asset->id)->where('record_type', $type)
            ->where('record_id', $id)->whereNull('unlinked_at')->lockForUpdate()->first(['id']);
        if ($existing) {
            throw ValidationException::withMessages(['record_id' => 'This record is already linked to the vehicle.']);
        }

        return $this->open($asset, $actor, $type, $id, $reason, $key, $fingerprint);
    }

    private function billProblem(Asset $asset, FinBill $bill): ?string
    {
        if ((int) $bill->asset_id === (int) $asset->id) {
            return 'Finance already records this invoice against the vehicle.';
        }
        if ($bill->asset_id !== null) {
            return 'Finance records this invoice against another vehicle or asset.';
        }
        if ($bill->site_id === null || (int) $bill->site_id !== (int) $asset->site_id) {
            return 'Choose a supplier invoice recorded for this vehicle’s site.';
        }

        return $bill->status === 'cancelled' ? 'This supplier invoice is cancelled in Finance.' : null;
    }

    private function purchaseOrderProblem(FinPurchaseOrder $order): ?string
    {
        return $order->status === 'cancelled' ? 'This purchase order is cancelled in Finance.' : null;
    }

    private function open(Asset $asset, User $actor, string $type, int $id, string $reason, string $key, string $fingerprint): FleetVehicleFinanceLink
    {
        return FleetVehicleFinanceLink::query()->create([
            'asset_id' => $asset->id, 'record_type' => $type, 'record_id' => $id, 'active_slot' => 1,
            'reason' => $reason, 'linked_by_user_id' => $actor->id,
            'request_key' => $key, 'request_fingerprint' => $fingerprint,
        ]);
    }

    private function close(FleetVehicleFinanceLink $link, User $actor, string $reason, string $key, string $fingerprint): void
    {
        $link->forceFill([
            'unlinked_at' => now(), 'unlinked_by_user_id' => $actor->id, 'unlink_reason' => $reason,
            'active_slot' => null, 'unlink_request_key' => $key, 'unlink_request_fingerprint' => $fingerprint,
        ])->save();
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array{request_type: string, source: string, amount: ?string, note: string, existing_document_id: ?int}
     */
    private function validRequest(array $data): array
    {
        Validator::make($data, [
            'request_type' => ['required', 'string', 'in:'.implode(',', array_keys(FleetFinanceReviewRequest::TYPES))],
            'source' => ['required', 'string', 'max:60'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'note' => ['required', 'string', 'max:2000'],
            'existing_document_id' => ['nullable', 'integer', 'min:1'],
        ], [
            'request_type.required' => 'Choose the Finance request type.',
            'request_type.in' => 'Choose the Finance request type.',
            'source.required' => 'Choose the source record.',
            'note.required' => 'Record what Finance needs to review.',
            'amount.decimal' => 'Enter the amount in dollars and cents.',
            'amount.min' => 'The amount can’t be negative.',
        ], ['amount' => 'amount', 'note' => 'what Finance needs to review'])->validate();
        $amount = $data['amount'] ?? null;

        return [
            'request_type' => (string) $data['request_type'],
            'source' => trim((string) $data['source']),
            'amount' => $amount === null || $amount === '' ? null : number_format((float) $amount, 2, '.', ''),
            'note' => self::requiredText((string) $data['note'], 'note', 'Record what Finance needs to review.'),
            'existing_document_id' => isset($data['existing_document_id']) ? (int) $data['existing_document_id'] : null,
        ];
    }

    /**
     * The request's source: the vehicle, one of its work orders, or a Finance
     * record connected to it that the actor may open.
     *
     * @return array{0: string, 1: ?int, 2: string}
     */
    private function source(User $actor, Asset $asset, string $value): array
    {
        if ($value === 'vehicle') {
            return ['vehicle', null, self::vehicleLabel($asset)];
        }
        [$type, $id] = array_pad(explode(':', $value, 2), 2, '');
        $id = ctype_digit($id) ? (int) $id : 0;
        $invalid = ValidationException::withMessages(['source' => 'Choose a source record that belongs to this vehicle.']);
        if ($id < 1) {
            throw $invalid;
        }
        if ($type === 'work_order') {
            $order = FleetWorkOrder::query()->whereKey($id)->where('asset_id', $asset->id)->first(['id', 'reference_number', 'title']);
            if (! $order) {
                throw $invalid;
            }

            return ['work_order', (int) $order->id, self::workOrderLabel($order)];
        }
        if (! array_key_exists($type, self::KINDS) || ! $actor->canDo(self::viewPermission($type)) || ! $this->isConnected($asset, $type, $id)) {
            throw $invalid;
        }
        $record = match ($type) {
            'fixed_asset' => FinFixedAsset::query()->find($id),
            'purchase_order' => FinPurchaseOrder::query()->with('vendor:id,name')->find($id),
            default => FinBill::query()->with('vendor:id,name')->find($id),
        };
        if (! $record) {
            throw $invalid;
        }

        return [$type, $id, self::recordLabel($type, $record)];
    }

    /** A current general document of the vehicle that the actor may open. */
    private function existingDocument(User $actor, Asset $asset, int $documentId): int
    {
        $valid = Gate::forUser($actor)->allows('view', $asset)
            && AssetDocument::query()->whereKey($documentId)->where('asset_id', $asset->id)->whereNull('archived_at')
                ->whereHas('documentSet', fn (Builder $set): Builder => $set->whereNull('source_type')->whereNull('archived_at')
                    ->whereColumn('asset_document_sets.current_revision', 'asset_documents.revision'))
                ->exists();
        if (! $valid) {
            throw ValidationException::withMessages(['existing_document_id' => 'Choose a current document from this vehicle.']);
        }

        return $documentId;
    }

    /** @return array<string,mixed> */
    private function option(string $type, Model $record): array
    {
        $status = self::statusFor($type, $record->status);
        [$name, $reference, $amount, $date] = match ($type) {
            'fixed_asset' => [$record->asset_name, $record->asset_tag, (float) $record->purchase_cost, $record->purchase_date],
            'purchase_order' => [$record->vendor?->name ?? self::KINDS[$type], $record->po_number, (float) $record->total_amount, $record->order_date],
            default => [$record->vendor?->name ?? self::KINDS[$type], $record->bill_number, (float) $record->total_amount, $record->bill_date],
        };

        return [
            'type' => $type,
            'id' => (int) $record->getKey(),
            'name' => (string) $name,
            'reference' => $reference,
            'status_label' => $status['label'],
            'detail' => implode(' · ', array_filter([
                self::KINDS[$type], $status['label'], self::money($amount), $date?->format('j M Y'),
            ])),
        ];
    }

    /** @return array{0: User, 1: Asset} */
    private function resolve(User $actor, int $assetId, string $permission): array
    {
        $current = User::query()->findOrFail($actor->id);
        abort_unless($current->canDo($permission), 403);
        $asset = $this->access->assignableVehicle($current, $assetId, true) ?? abort(404);

        return [$current, $asset];
    }

    private function event(FleetFinanceReviewRequest $request, User $actor, string $action, ?string $note, ?string $key, ?string $fingerprint): void
    {
        FleetFinanceReviewRequestEvent::query()->create([
            'review_request_id' => $request->id, 'action' => $action, 'actor_user_id' => $actor->id, 'note' => $note,
            'request_key' => $key, 'request_fingerprint' => $fingerprint, 'occurred_at' => now(),
        ]);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function guardDuplicate(callable $callback, string $message): mixed
    {
        try {
            return $callback();
        } catch (QueryException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                abort(409, $message);
            }
            throw $error;
        }
    }

    private static function requiredText(string $value, string $field, string $message): string
    {
        $value = trim($value);
        if ($value === '') {
            throw ValidationException::withMessages([$field => $message]);
        }
        if (mb_strlen($value) > 2000) {
            throw ValidationException::withMessages([$field => 'Keep this to 2,000 characters.']);
        }

        return $value;
    }

    private static function assertKey(string $requestKey): void
    {
        if (trim($requestKey) === '' || mb_strlen($requestKey) > 100) {
            throw ValidationException::withMessages(['request_key' => 'Provide an idempotency key of at most 100 characters.']);
        }
    }
}
