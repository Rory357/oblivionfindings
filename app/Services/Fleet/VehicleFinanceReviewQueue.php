<?php

namespace App\Services\Fleet;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\AssetDocument;
use App\Models\FleetFinanceReviewRequest;
use App\Models\FleetFinanceReviewRequestEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Finance › Vehicle reviews: the review requests Fleet sent to Finance, for
 * vehicles at the Sites Finance handles (VehicleFinanceService::
 * financeSiteIds). Finance reads and decides them here without Fleet access;
 * the requester sees the decision on the vehicle's Finance view.
 */
class VehicleFinanceReviewQueue
{
    public const LIMIT = 100;

    public const STATUSES = ['open', 'decided', 'all'];

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
        $query = $this->scoped($viewer)
            ->when($status === 'open', fn (Builder $open): Builder => $open->where('status', 'submitted'))
            ->when($status === 'decided', fn (Builder $decided): Builder => $decided->whereIn('status', FleetFinanceReviewRequest::DECISIONS))
            ->when($search !== '', function (Builder $matching) use ($search): void {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $matching->where(fn (Builder $any): Builder => $any->where('reference_number', 'like', $like)
                    ->orWhere('note', 'like', $like)->orWhere('source_label', 'like', $like)
                    ->orWhereHas('asset', fn (Builder $asset): Builder => $asset->where('name', 'like', $like)
                        ->orWhere('registration_number', 'like', $like)));
            });
        $total = (clone $query)->count();
        // Open requests first, oldest first; then decisions, newest first.
        $rows = $this->rows($viewer, $query->orderByRaw("CASE WHEN status = 'submitted' THEN 0 ELSE 1 END")
            ->orderByRaw("CASE WHEN status = 'submitted' THEN created_at END ASC")
            ->orderByDesc('decided_at')->orderByDesc('id')->limit(self::LIMIT)->get());
        // A request opened from All Tasks or a notification, whatever the filter.
        $focus = $focusId === null ? null
            : (collect($rows)->firstWhere('id', $focusId)
                ?? ($this->rows($viewer, $this->scoped($viewer)->whereKey($focusId)->get())[0] ?? null));
        $open = $this->scoped($viewer)->where('status', 'submitted');
        $oldest = (clone $open)->min('created_at');

        return [
            'requests' => $rows,
            'focus' => $focus,
            'total' => $total,
            'filters' => ['status' => $status, 'search' => $search],
            'summary' => [
                'open' => (clone $open)->count(),
                'decided_30' => $this->scoped($viewer)->whereIn('status', FleetFinanceReviewRequest::DECISIONS)
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
        $requests->load(['asset:id,name,registration_number,site_id', 'asset.site:id,name', 'requestedBy:id,name', 'decidedBy:id,name',
            'events' => fn ($events) => $events->with('actor:id,name')->orderBy('id')]);
        $files = $this->files($requests);
        $decide = $this->finance->canDecide($viewer);
        $spend = $viewer->canDo('finance.ap.view');
        // The vehicle's own page is linked only for people who can open it.
        $openable = $requests->pluck('asset_id')->unique()
            ->filter(fn (mixed $id): bool => $this->vehicles->fleetVehicle($viewer, (int) $id) !== null)
            ->map(fn (mixed $id): int => (int) $id)->values()->all();

        return $requests->map(function (FleetFinanceReviewRequest $request) use ($viewer, $files, $decide, $spend, $openable): array {
            [$label, $tone] = match ($request->status) {
                'resolved' => ['Resolved', 'success'],
                'declined' => ['Declined', 'neutral'],
                default => ['Pending Finance review', 'warning'],
            };
            $own = (int) $request->requested_by_user_id === (int) $viewer->id;
            $asset = $request->asset;
            $restrictedSource = in_array($request->source_type, ['purchase_order', 'bill'], true) && ! $spend;

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
                    'url' => in_array((int) $request->asset_id, $openable, true)
                        ? "/fleet-assets/vehicles/{$request->asset_id}?view=finance" : null,
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
                'files' => $files[$request->id] ?? [],
                'history' => $request->events->map(fn (FleetFinanceReviewRequestEvent $event): array => [
                    'id' => (int) $event->id,
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
                'own_request' => $own,
                // Someone other than the person who asked decides (VehicleFinanceService::decide).
                'can_decide' => $decide && $request->isOpen() && ! $own,
            ];
        })->values()->all();
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
