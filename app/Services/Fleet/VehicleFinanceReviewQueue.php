<?php

namespace App\Services\Fleet;

use App\Domain\Finance\Models\FinBill;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\AssetDocument;
use App\Models\FleetFinanceReviewRequest;
use App\Models\FleetFinanceReviewRequestEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Finance › Vehicle reviews: the review requests Fleet sent to Finance, for
 * vehicles at the Sites Finance handles (VehicleFinanceService::
 * financeSiteIds). Finance reads and decides them here without Fleet access;
 * the requester sees the decision on the vehicle's Finance view.
 */
class VehicleFinanceReviewQueue
{
    public const PER_PAGE = 25;

    public const HISTORY_PAGE_SIZE = 10;

    public const STATUSES = ['open', 'ready', 'mine', 'overdue', 'preparing', 'changes_requested', 'decided', 'all'];

    /** Files still being stored or checked for viruses. */
    private const WAITING_STATES = ['scan_unavailable', 'publication_failed', 'stored', 'reserved'];

    public function __construct(
        private readonly VehicleFinanceService $finance,
        private readonly SecurityDevicesAccessService $vehicles,
    ) {}

    public function canView(User $viewer): bool
    {
        return $viewer->canDo('finance.assets.view') || $viewer->canDo('finance.ap.view');
    }

    /** Current work and its linked bills, with the same Finance scope and DTO as the queue. */
    public function forWork(User $viewer, int $assetId, int $workId, array $billIds): array
    {
        if (! $this->canView($viewer)) {
            return [];
        }

        return $this->rows($viewer, $this->scoped($viewer)->where('asset_id', $assetId)
            ->where(fn ($query) => $query->where(fn ($work) => $work->where('source_type', 'work_order')->where('source_id', $workId))
                ->orWhere(fn ($bills) => $bills->where('source_type', 'bill')->whereIn('source_id', $billIds)))
            ->latest('id')->limit(25)->get());
    }

    /** Requests for vehicles at the Sites this person handles for Finance. */
    public function scoped(User $viewer): Builder
    {
        $sites = $this->finance->financeSiteIds($viewer);

        return FleetFinanceReviewRequest::query()->whereHas('asset', fn (Builder $asset): Builder => $asset
            ->whereNotNull('site_id')->whereIn('site_id', $sites));
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function present(User $viewer, array $filters, ?int $focusId = null): array
    {
        $status = in_array($filters['status'] ?? null, self::STATUSES, true) ? (string) $filters['status'] : 'open';
        $search = trim((string) ($filters['search'] ?? ''));
        $sort = in_array($filters['sort'] ?? null, ['oldest', 'newest', 'due', 'amount'], true) ? $filters['sort'] : 'oldest';
        $query = $this->scoped($viewer)
            ->when($status === 'open', fn (Builder $open): Builder => $open->whereIn('status', ['preparing', 'submitted', 'changes_requested']))
            ->when($status === 'ready', fn (Builder $ready): Builder => $ready->where('status', 'submitted'))
            ->when(in_array($status, ['preparing', 'changes_requested'], true), fn (Builder $query): Builder => $query->where('status', $status))
            ->when($status === 'mine', fn (Builder $mine): Builder => $mine->where('assigned_to_user_id', $viewer->id)->whereIn('status', ['preparing', 'submitted', 'changes_requested']))
            ->when($status === 'overdue', fn (Builder $due): Builder => $due->where('status', 'submitted')->whereDate('due_on', '<', today('Pacific/Auckland')))
            ->when($status === 'decided', fn (Builder $decided): Builder => $decided->whereIn('status', ['resolved', 'declined']))
            ->when($search !== '', function (Builder $matching) use ($search): void {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $matching->where(fn (Builder $any): Builder => $any->where('reference_number', 'like', $like)
                    ->orWhere('note', 'like', $like)->orWhere('source_label', 'like', $like)
                    ->orWhereHas('asset', fn (Builder $asset): Builder => $asset->where('name', 'like', $like)
                        ->orWhere('registration_number', 'like', $like)));
            });
        $total = (clone $query)->count();
        $page = min(max(1, (int) ($filters['page'] ?? 1)), max(1, (int) ceil($total / self::PER_PAGE)));
        // Open requests first, oldest first; then decisions, newest first.
        match ($sort) {
            'newest' => $query->orderByDesc('created_at'),
            'due' => $query->orderByRaw('due_on IS NULL')->orderBy('due_on'),
            'amount' => $query->orderByDesc('amount'),
            default => $query->orderByRaw("CASE WHEN status = 'submitted' THEN 0 ELSE 1 END")->orderBy('created_at'),
        };
        $rows = $this->rows($viewer, $query->orderByDesc('id')->forPage($page, self::PER_PAGE)->get());
        $pagination = new LengthAwarePaginator($rows, $total, self::PER_PAGE, $page, [
            'path' => route('finance.vehicle-reviews.index'),
            'query' => array_filter(['status' => $status, 'search' => $search, 'sort' => $sort], fn ($value) => $value !== ''),
        ]);
        // A request opened from All Tasks or a notification, whatever the filter.
        $focus = $focusId === null ? null
            : (collect($rows)->firstWhere('id', $focusId)
                ?? ($this->rows($viewer, $this->scoped($viewer)->whereKey($focusId)->get())[0] ?? null));
        $open = $this->scoped($viewer)->whereIn('status', ['preparing', 'submitted', 'changes_requested']);
        $oldest = (clone $open)->min('created_at');

        return [
            'requests' => $rows,
            'focus' => $focus,
            'total' => $total,
            'pagination' => [
                'current_page' => $pagination->currentPage(),
                'last_page' => $pagination->lastPage(),
                'from' => $pagination->firstItem(),
                'to' => $pagination->lastItem(),
                'links' => $pagination->onEachSide(1)->linkCollection()->all(),
            ],
            'filters' => ['status' => $status, 'search' => $search, 'sort' => $sort],
            'summary' => [
                'open' => (clone $open)->count(),
                'decided_30' => $this->scoped($viewer)->whereIn('status', ['resolved', 'declined'])
                    ->where('decided_at', '>=', now()->subDays(30))->count(),
                'oldest_open_at' => $oldest === null ? null : CarbonImmutable::parse((string) $oldest, 'UTC')->toIso8601String(),
            ],
            'can' => ['decide' => $this->finance->canDecide($viewer)],
        ];
    }

    /**
     * @param  Collection<int, FleetFinanceReviewRequest>  $requests
     * @return list<array<string,mixed>>
     */
    private function rows(User $viewer, Collection $requests): array
    {
        if ($requests->isEmpty()) {
            return [];
        }
        $requests->load(['asset:id,name,registration_number,site_id', 'asset.site:id,name', 'requestedBy:id,name', 'decidedBy:id,name', 'assignedTo:id,name',
            'events' => fn ($events) => $events->with('actor:id,name')->orderByDesc('id')->limit(self::HISTORY_PAGE_SIZE + 1)]);
        $files = $this->files($requests);
        $decide = $this->finance->canDecide($viewer);
        $spend = $viewer->canDo('finance.ap.view');
        // The vehicle's own page is linked only for people who can open it.
        $profileUrls = [];
        foreach ($requests->pluck('asset_id')->unique() as $id) {
            if ($this->vehicles->fleetVehicle($viewer, (int) $id) !== null) {
                $profileUrls[$id] = "/fleet-assets/vehicles/{$id}?view=finance";
            } elseif (($asset = $this->vehicles->assignableAsset($viewer, (int) $id)) && Gate::forUser($viewer)->allows('view', $asset)) {
                $profileUrls[$id] = "/fleet-assets/assets/{$id}#view=overview&section=finance";
            }
        }

        return $requests->map(function (FleetFinanceReviewRequest $request) use ($viewer, $files, $decide, $spend, $profileUrls): array {
            [$label, $tone] = match ($request->status) {
                'resolved' => ['Resolved', 'success'],
                'declined' => ['Declined', 'neutral'],
                'preparing' => ['Preparing evidence', 'warning'],
                'changes_requested' => ['Changes requested', 'warning'],
                default => ['Pending Finance review', 'warning'],
            };
            $own = (int) $request->requested_by_user_id === (int) $viewer->id;
            $asset = $request->asset;
            $restrictedSource = in_array($request->source_type, ['purchase_order', 'bill'], true) && ! $spend;
            $bill = $request->source_type === 'bill' ? FinBill::find($request->source_id) : null;
            $canOpenBill = $bill && $viewer->can('view', $bill);
            if ($request->source_type === 'bill' && ! $canOpenBill) {
                $restrictedSource = true;
            }

            return [
                'id' => (int) $request->id,
                'reference' => $request->reference_number,
                'type_label' => $request->typeLabel(),
                'status' => $request->status,
                'status_label' => $label,
                'tone' => $tone,
                'vehicle' => [
                    'id' => (int) $request->asset_id,
                    'name' => $asset?->name ?? 'Vehicle',
                    'registration' => $asset?->registration_number,
                    'site' => $asset?->site?->name,
                    'url' => $profileUrls[$request->asset_id] ?? null,
                ],
                'amount' => $request->amount === null ? null : (float) $request->amount,
                'note' => $request->note,
                'source' => $restrictedSource
                    ? (VehicleFinanceService::KINDS[$request->source_type] ?? 'Finance record').' · accounts payable access needed'
                    : $request->source_label,
                'requested_by' => $request->requestedBy?->name,
                'requested_at' => $request->created_at?->toIso8601String(),
                'decided_by' => $request->decidedBy?->name,
                'decided_at' => $request->decided_at?->toIso8601String(),
                'decision_note' => $request->decision_note,
                'lock_version' => (int) $request->lock_version,
                'evidence_token' => app(FinanceReviewEvidence::class)->token($request),
                'decision_evidence' => $request->decision_evidence,
                'assigned_to_user_id' => $request->assigned_to_user_id,
                'assigned_to' => $request->assignedTo?->name,
                'due_on' => $request->due_on?->toDateString(),
                'response_note' => $request->response_note,
                'can_assign' => $decide && ! in_array($request->status, ['resolved', 'declined'], true),
                'bill_url' => $canOpenBill ? '/finance/bills/'.$bill->id : null,
                'files' => $files[$request->id] ?? [],
                ...$this->historyPage($request->events),
                'own_request' => $own,
                // Someone other than the person who asked decides (VehicleFinanceService::decide).
                'can_decide' => $decide && $request->isOpen() && ! $own,
            ];
        })->values()->all();
    }

    /** Older events are fetched only when a reviewer asks for them. */
    public function history(User $viewer, int $requestId, ?int $before): array
    {
        abort_unless($this->canView($viewer), 403);
        $record = $this->scoped($viewer)->findOrFail($requestId);
        $events = $record->events()->with('actor:id,name')
            ->when($before !== null, fn ($query) => $query->where('id', '<', $before))
            ->orderByDesc('id')->limit(self::HISTORY_PAGE_SIZE + 1)->get();

        return $this->historyPage($events);
    }

    private function historyPage(Collection $events): array
    {
        $visible = $events->take(self::HISTORY_PAGE_SIZE)->sortBy('id')->values();

        return [
            'history' => $visible->map(fn (FleetFinanceReviewRequestEvent $event): array => [
                'id' => (int) $event->id,
                'label' => match ($event->action) {
                    'submitted' => 'Submitted to Finance',
                    'resolved' => 'Resolved by Finance',
                    'declined' => 'Declined by Finance',
                    'preparing' => 'Preparing evidence', 'changes_requested' => 'Returned for correction',
                    'resubmitted' => 'Response submitted', 'assigned' => 'Reviewer and due date updated',
                    default => 'Updated',
                },
                'actor' => $event->actor?->name,
                'note' => $event->note,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
            ])->all(),
            'history_next_before' => $events->count() > self::HISTORY_PAGE_SIZE ? (int) $visible->first()->id : null,
        ];
    }

    /**
     * Files kept with each request, and the vehicle document it pointed to,
     * opened through Finance › Vehicle reviews.
     *
     * @param  Collection<int, FleetFinanceReviewRequest>  $requests
     * @return array<int, list<array<string,mixed>>>
     */
    private function files(Collection $requests): array
    {
        $map = [];
        $file = fn (FleetFinanceReviewRequest $request, AssetDocument $document): array => [
            'id' => (int) $document->id,
            'name' => $document->original_name ?: $document->title,
            'mime' => $document->detected_mime ?: $document->mime_type,
            'url' => $document->isOpenable()
                ? route('finance.vehicle-reviews.file', ['reviewRequest' => $request->id, 'document' => $document->id])
                : null,
            'waiting' => in_array($document->state, self::WAITING_STATES, true),
        ];
        $byId = $requests->keyBy('id');
        AssetDocument::query()->where('source_type', 'finance_review_request')->whereIn('source_id', $byId->keys())
            ->whereNull('archived_at')->orderBy('id')->get()
            ->each(function (AssetDocument $document) use (&$map, $byId, $file): void {
                $request = $byId->get((int) $document->source_id);
                if ($request !== null && (int) $document->asset_id === (int) $request->asset_id) {
                    $map[(int) $request->id][] = $file($request, $document);
                }
            });
        $referenced = $requests->pluck('existing_document_id')->filter()->unique()->values();
        if ($referenced->isNotEmpty()) {
            $documents = AssetDocument::query()->whereIn('id', $referenced)->get()->keyBy('id');
            foreach ($requests as $request) {
                $document = $request->existing_document_id ? $documents->get($request->existing_document_id) : null;
                if ($document !== null && (int) $document->asset_id === (int) $request->asset_id) {
                    $map[(int) $request->id][] = $file($request, $document);
                }
            }
        }

        return $map;
    }
}
