<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Http\Requests\JobBoardPositionRequest;
use App\Http\Requests\JobBoardPostingShiftRequest;
use App\Models\Client;
use App\Models\Shift;
use App\Models\ShiftOpenPosition;
use App\Models\ShiftReplacementRequest;
use App\Models\Site;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\Eligibility\EligibilityResult;
use App\Services\Operations\JobBoardActionCommand;
use App\Services\Operations\WorkforceMutationGuard;
use App\Services\ShiftStaffEligibilityService;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class JobBoardController extends Controller
{
    protected const RECENT_WEEKS_LOOKBACK = 26;

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($this->canViewJobBoard($auth), 403);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:open,claimed,filled,cancelled'],
            'scope' => ['nullable', 'string', 'in:for-you,all,mine,replacements,approvals'],
            'date_range' => ['nullable', 'string', 'in:next_7_days,this_weekend,tonight'],
            'skill' => ['nullable', 'string', 'max:100'],
            'fit' => ['nullable', 'string', 'in:all,eligible,no-conflict,site'],
            'week' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $readAt = Carbon::now();
        $canApprove = $this->canApprovePositions($auth);

        $search = trim((string) ($filters['q'] ?? ''));
        // Same viewer-wide rule as canViewSensitivePositionDetails(): only these
        // viewers see full client names and exact locations on every card.
        $searchSensitive = $auth->canDo('job_board.approve')
            || $auth->canDo('shifts.manageAny')
            || $auth->canDo('shifts.viewAny');
        $scope = $filters['scope'] ?? 'for-you';
        $skill = trim((string) ($filters['skill'] ?? ''));
        $fit = $filters['fit'] ?? null;

        $weekFilterRequested = ! empty($filters['week']);
        $weekAnchor = $weekFilterRequested
            ? Carbon::createFromFormat('Y-m-d', $filters['week'])->startOfDay()
            : $readAt->copy()->startOfWeek(Carbon::MONDAY);
        $weekStart = $weekAnchor->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);
        $dateRangeBounds = ! empty($filters['date_range'])
            ? $this->jobBoardDateRangeBounds($filters['date_range'], $readAt) : null;

        $positions = ShiftOpenPosition::query()
            ->tap(fn ($query) => $this->siteAccess->applyShiftOpenPositionScope($query, $auth, ['reports.viewAny']))
            ->with([
                'shift:id,client_id,starts_at,ends_at,location,status,user_id,coverage_roles',
                'shift.client:id,first_name,last_name,site_id,suburb,city',
                'shift.client.site:id,name,suburb,city',
                'shift.tasks:id,shift_id,label,sort_order',
                'claimer:id,name',
                'approver:id,name',
                'replacementRequest:id,shift_id,requested_by,current_staff_id,replacement_user_id,status,requested_at,claimed_at,approved_at,cancelled_at',
                'replacementRequest.requester:id,name',
                'replacementRequest.currentStaff:id,name',
                'replacementRequest.replacementStaff:id,name',
            ])
            ->where(function ($query) use ($readAt) {
                $query->where('status', '!=', 'open')
                    ->orWhereNull('expires_at')
                    ->orWhere('expires_at', '>', $readAt->copy());
            })
            ->when($scope === 'mine', function ($query) use ($auth, $readAt) {
                $query->where('claimed_by', $auth->id)
                    ->where(function ($nested) use ($readAt) {
                        $nested->where('status', 'claimed')
                            ->orWhere(function ($filled) use ($readAt) {
                                $filled->where('status', 'filled')
                                    ->where('approved_at', '>=', $readAt->copy()->subDays(14));
                            });
                    });
            })
            ->when($scope === 'replacements', function ($query) {
                $query->whereNotNull('replacement_request_id');
            })
            ->when($scope === 'approvals', function ($query) use ($auth) {
                // Positions waiting on a coordinator to approve a worker's claim.
                // Exclude the viewer's own claims — they should approve via "My claims" only if they can self-approve.
                $query->where('status', 'claimed')
                    ->where('claimed_by', '!=', $auth->id);
            })
            ->when($scope === 'all' || $scope === 'for-you', function ($query) use ($readAt) {
                $query->where('status', 'open')
                    ->where(function ($nested) use ($readAt) {
                        $nested->whereNull('expires_at')
                            ->orWhere('expires_at', '>', $readAt->copy());
                    });
            })
            ->when($weekFilterRequested && $scope !== 'mine', function ($query) use ($weekStart, $weekEnd) {
                $query->whereHas('shift', fn ($shiftQuery) => $shiftQuery
                    ->whereBetween('starts_at', [$weekStart, $weekEnd]));
            })
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when($search !== '', function ($query) use ($search, $searchSensitive) {
                $query->where(function ($nested) use ($search, $searchSensitive) {
                    // Posting notes are searchable, except the notes older replacement
                    // postings generated ("Replacement needed … Reason: …"): they copied
                    // the absent worker's reason, and a search must not reveal it.
                    $nested->where(fn ($notes) => $notes->where('notes', 'like', '%'.$search.'%')
                        ->where('notes', 'not like', 'Replacement needed%Reason: %'))
                        // Everyone can search the public place label a card shows.
                        ->orWhereHas('shift.client', function ($clientQuery) use ($search, $searchSensitive) {
                            $clientQuery->where(function ($labels) use ($search, $searchSensitive) {
                                $labels->where('suburb', 'like', '%'.$search.'%')
                                    ->orWhere('city', 'like', '%'.$search.'%')
                                    ->orWhereHas('site', fn ($site) => $site->where('name', 'like', '%'.$search.'%')
                                        ->orWhere('suburb', 'like', '%'.$search.'%')
                                        ->orWhere('city', 'like', '%'.$search.'%'));
                                // Names only for viewers who see full names; staff who
                                // see initials must not learn who a shift is for by searching.
                                if ($searchSensitive) {
                                    $labels->orWhere('first_name', 'like', '%'.$search.'%')
                                        ->orWhere('last_name', 'like', '%'.$search.'%');
                                }
                            });
                        });
                    if ($searchSensitive) {
                        $nested->orWhereHas('shift', fn ($shiftQuery) => $shiftQuery->where('location', 'like', '%'.$search.'%'));
                    }
                });
            })
            ->when($dateRangeBounds !== null, fn ($q) => $this->applyDateRangeFilter($q, $filters['date_range'], $dateRangeBounds))
            ->when($skill !== '', fn ($q) => $q->whereJsonContains('required_skills', $skill))
            ->orderBy(
                Shift::query()
                    ->select('starts_at')
                    ->whereColumn('shifts.id', 'shift_open_positions.shift_id')
                    ->limit(1)
            )
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $statsQuery = ShiftOpenPosition::query()
            ->tap(fn ($query) => $this->siteAccess->applyShiftOpenPositionScope($query, $auth, ['reports.viewAny']));
        $availableSkills = $this->availableSkills($auth);

        $positionItems = $positions->getCollection();
        $viewerEligibilityResults = $this->batchViewerEligibility($positionItems, $auth);
        $positionEligibilityResults = $this->batchClaimedPositionEligibility($positionItems);

        // Sensitive-detail visibility depends on three viewer capabilities that
        // are identical across every card. Resolve them once instead of firing
        // permissionOverrides queries per card.
        $viewerCanSeeSensitive = $auth->canDo('job_board.approve')
            || $auth->canDo('shifts.manageAny')
            || $auth->canDo('shifts.viewAny');

        // One grouped COUNT for "past shifts here" across the whole page instead
        // of one query per rendered card.
        $pastShiftCounts = $this->batchPastShiftsHere($positionItems, $auth);

        $formattedJobs = $positions->through(
            fn (ShiftOpenPosition $position) => $this->formatPositionForViewer(
                $position,
                $auth,
                $viewerEligibilityResults[$position->id] ?? null,
                $positionEligibilityResults[$position->id] ?? null,
                $viewerCanSeeSensitive,
                $pastShiftCounts[$position->shift?->client_id] ?? 0,
            ),
        );

        $eligibleForYou = collect($formattedJobs->items())
            ->filter(fn ($job) => $job['status'] === 'open' && ($job['viewer_eligibility']['is_eligible'] ?? false))
            ->count();

        $expiringSoon = (clone $statsQuery)
            ->where('status', 'open')
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [$readAt->copy(), $readAt->copy()->addHour()])
            ->count();

        $myClaimsCount = ShiftOpenPosition::query()
            ->tap(fn ($query) => $this->siteAccess->applyShiftOpenPositionScope($query, $auth, ['reports.viewAny']))
            ->where('claimed_by', $auth->id)
            ->where(function ($nested) use ($readAt) {
                $nested->where('status', 'claimed')
                    ->orWhere(function ($filled) use ($readAt) {
                        $filled->where('status', 'filled')
                            ->where('approved_at', '>=', $readAt->copy()->subDays(14));
                    });
            })
            ->count();

        $replacementsCount = (clone $statsQuery)
            ->whereNotNull('replacement_request_id')
            ->where(function ($query) use ($readAt) {
                $query->where('status', '!=', 'open')
                    ->orWhereNull('expires_at')
                    ->orWhere('expires_at', '>', $readAt->copy());
            })
            ->count();

        $pendingApprovalCount = $canApprove
            ? (clone $statsQuery)
                ->where('status', 'claimed')
                ->where('claimed_by', '!=', $auth->id)
                ->count()
            : 0;

        $sitesCount = ShiftOpenPosition::query()
            ->tap(fn ($query) => $this->siteAccess->applyShiftOpenPositionScope($query, $auth, ['reports.viewAny']))
            ->where('shift_open_positions.status', 'open')
            ->where(function ($query) use ($readAt) {
                $query->whereNull('shift_open_positions.expires_at')
                    ->orWhere('shift_open_positions.expires_at', '>', $readAt->copy());
            })
            ->join('shifts', 'shifts.id', '=', 'shift_open_positions.shift_id')
            ->join('clients', 'clients.id', '=', 'shifts.client_id')
            ->whereNotNull('clients.site_id')
            ->distinct('clients.site_id')
            ->count('clients.site_id');

        $sitesWorkedThisWeek = Shift::query()
            ->tap(fn ($query) => $this->siteAccess->applyShiftScope($query, $auth))
            ->where('shifts.user_id', $auth->id)
            ->where('shifts.starts_at', '>=', $weekStart)
            ->where('shifts.starts_at', '<=', $weekEnd)
            ->join('clients', 'clients.id', '=', 'shifts.client_id')
            ->whereNotNull('clients.site_id')
            ->distinct('clients.site_id')
            ->count('clients.site_id');

        $stats = [
            'open' => (clone $statsQuery)
                ->where('status', 'open')
                ->where(function ($query) use ($readAt) {
                    $query->whereNull('expires_at')
                        ->orWhere('expires_at', '>', $readAt->copy());
                })
                ->count(),
            'claimed' => (clone $statsQuery)->where('status', 'claimed')->count(),
            'filled_today' => (clone $statsQuery)
                ->where('status', 'filled')
                ->whereDate('approved_at', $readAt->copy()->toDateString())
                ->count(),
            'eligible_for_you' => $eligibleForYou,
            'expiring_soon' => $expiringSoon,
            'mine' => $myClaimsCount,
            'replacements' => $replacementsCount,
            'pending_approval' => $pendingApprovalCount,
            'sites' => $sitesCount,
            'sites_worked_this_week' => $sitesWorkedThisWeek,
        ];

        return inertia('operations/job-board/Index', [
            'jobs' => $formattedJobs,
            'filters' => array_merge($filters, [
                'scope' => $scope,
                'week' => $weekFilterRequested ? $weekStart->toDateString() : null,
            ]),
            'available_skills' => $availableSkills,
            'week' => [
                'start' => $weekStart->toDateString(),
                'end' => $weekEnd->toDateString(),
                'start_label' => $weekStart->format('j M'),
                'end_label' => $weekEnd->format('j M'),
                'prev' => $weekStart->copy()->subWeek()->toDateString(),
                'next' => $weekStart->copy()->addWeek()->toDateString(),
                'is_current' => $weekStart->equalTo($readAt->copy()->startOfWeek(Carbon::MONDAY)),
            ],
            'viewer' => [
                'first_name' => $this->viewerFirstName($auth),
                'can_claim' => $this->canClaimPositions($auth),
                'can_approve' => $canApprove,
                'can_post_position' => $this->canCreatePositions($auth),
                'alerts_enabled' => (bool) ($auth->job_board_alerts_enabled ?? false),
            ],
            'provenance' => $this->jobBoardProvenance($auth, $filters, $scope, $weekFilterRequested,
                $weekStart, $weekEnd, $dateRangeBounds, $readAt, $formattedJobs, $stats, $canApprove),
            'stats' => $stats,
        ]);
    }

    public function postingShifts(JobBoardPostingShiftRequest $request)
    {
        $data = $request->validated();
        $page = (int) ($data['page'] ?? 1);
        $perPage = (int) ($data['per_page'] ?? 20);
        $timezone = (string) (config('app.worker_timezone') ?: config('app.timezone', 'UTC'));
        $result = DB::transaction(function () use ($request, $data, $page, $perPage, $timezone): array {
            app(WorkforceMutationGuard::class)->lock();
            $actor = $this->postingActor($request->user());

            return CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($actor, $data, $page, $perPage, $timezone): array {
                $siteIds = $this->siteAccess->accessibleSiteIds($actor, ['reports.viewAny'], $reads);
                $sensitive = $actor->canDo('job_board.approve') || $actor->canDo('shifts.manageAny') || $actor->canDo('shifts.viewAny');
                $query = Shift::query()->where(function (Builder $sites) use ($siteIds): void {
                    $sites->whereIn('site_id', $siteIds)->orWhere(function (Builder $fallback) use ($siteIds): void {
                        $fallback->whereNull('site_id')->whereHas('client', fn (Builder $client) => $client->whereIn('site_id', $siteIds));
                    });
                });
                $this->siteAccess->applyShiftIntegrityScope($query);
                if (! empty($data['week'])) {
                    $start = Carbon::createFromFormat('!Y-m-d', $data['week'], $timezone)->startOfWeek(Carbon::MONDAY);
                    $query->whereBetween('starts_at', [$start->copy()->utc(), $start->copy()->endOfWeek(Carbon::SUNDAY)->utc()]);
                }
                $search = trim((string) ($data['q'] ?? ''));
                if ($search !== '') {
                    $query->where(function (Builder $matching) use ($search, $sensitive): void {
                        if (ctype_digit($search)) {
                            $matching->whereKey((int) $search);
                        } else {
                            $matching->whereHas('client', function (Builder $client) use ($search, $sensitive): void {
                                $client->where(function (Builder $labels) use ($search, $sensitive): void {
                                    $labels->where('suburb', 'like', '%'.$search.'%')->orWhere('city', 'like', '%'.$search.'%')
                                        ->orWhereHas('site', fn (Builder $site) => $site->where('name', 'like', '%'.$search.'%')
                                            ->orWhere('suburb', 'like', '%'.$search.'%')->orWhere('city', 'like', '%'.$search.'%'));
                                    if ($sensitive) {
                                        $labels->orWhere('first_name', 'like', '%'.$search.'%')->orWhere('last_name', 'like', '%'.$search.'%');
                                    }
                                });
                            });
                            if ($sensitive) {
                                $matching->orWhere('location', 'like', '%'.$search.'%');
                            }
                        }
                    });
                }
                $candidates = $this->postingPage($query, $siteIds, $reads, $page, $perPage);
                $options = [];
                foreach (array_slice($candidates, 0, $perPage) as $shift) {
                    [$active, $replacement] = $this->postingLinks($shift, $reads);
                    $reason = $this->postingRefusal($shift, $active, $replacement);
                    $display = (new ShiftOpenPosition)->setRelation('shift', $shift);
                    $options[] = ['id' => (int) $shift->id, 'label' => $this->formatPositionTitle($display, $sensitive),
                        'location' => $this->formatPositionLocation($display, $sensitive),
                        'starts_at' => $shift->starts_at?->copy()->utc()->toISOString(), 'ends_at' => $shift->ends_at?->copy()->utc()->toISOString(),
                        'status' => $shift->status, 'assigned' => $shift->user_id !== null,
                        'source_revision' => $this->postingSourceRevision($shift, $active, $replacement),
                        'post_url' => route('operations.job_board.create', ['shift' => $shift->id], false),
                        'active_position' => $active ? ['id' => (int) $active->id, 'status' => $active->status,
                            'expired' => $active->expires_at?->lte(now()) ?? false] : null,
                        'can_post' => $reason === null, 'reason' => $reason];
                }

                return ['data' => $options, 'page' => $page, 'per_page' => $perPage, 'has_more' => count($candidates) > $perPage];
            });
        });

        return response()->json([...$result, 'workerTimezone' => $timezone]);
    }

    /** Page only current canonical records; hidden rows never consume a public slot. */
    private function postingPage(Builder $query, array $siteIds, CurrentAuthorizationReads $reads, int $page, int $perPage): array
    {
        $skip = ($page - 1) * $perPage;
        $visible = [];
        $cursorStart = null;
        $cursorId = null;
        while (count($visible) < $perPage + 1) {
            $batchQuery = clone $query;
            if ($cursorId !== null) {
                $batchQuery->where(function (Builder $after) use ($cursorStart, $cursorId): void {
                    $after->where('starts_at', '<', $cursorStart)
                        ->orWhere(function (Builder $sameStart) use ($cursorStart, $cursorId): void {
                            $sameStart->where('starts_at', $cursorStart)->where('id', '<', $cursorId);
                        });
                });
            }
            $batch = $reads->query($batchQuery->orderByDesc('starts_at')->orderByDesc('id')->limit(100))->get();
            if ($batch->isEmpty()) {
                break;
            }
            foreach ($batch as $shift) {
                $cursorStart = $shift->getRawOriginal('starts_at');
                $cursorId = (int) $shift->id;
                if (! $this->postingShiftEvidence($shift, $siteIds, $reads)) {
                    continue;
                }
                if ($skip > 0) {
                    $skip--;

                    continue;
                }
                $visible[] = $shift;
                if (count($visible) === $perPage + 1) {
                    break;
                }
            }
            if ($batch->count() < 100) {
                break;
            }
        }

        return $visible;
    }

    public function createPosition(JobBoardPositionRequest $request, string $shift)
    {
        $data = $request->validated();
        if ((int) $shift !== (int) $data['shift_id']) {
            throw ValidationException::withMessages(['shift_id' => 'The selected shift does not match this posting. Choose the shift again.']);
        }
        $externalTransaction = DB::transactionLevel() === 0 && DB::connection()->getPdo()->inTransaction();
        abort_if($externalTransaction, 409, 'Posting cannot run inside an externally owned transaction.');
        $rootEntry = DB::transactionLevel() === 0;
        $saved = DB::transaction(function () use ($request, $data, $shift): array {
            app(WorkforceMutationGuard::class)->lock();
            $actor = $this->postingActor($request->user());
            $lockedShift = Shift::query()->whereKey((int) $shift)->lockForUpdate()->firstOrFail();

            return CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($lockedShift, $actor, $data): array {
                $siteIds = $this->siteAccess->accessibleSiteIds($actor, ['reports.viewAny'], $reads);
                abort_unless($this->postingShiftEvidence($lockedShift, $siteIds, $reads), 404);
                [$active, $replacement] = $this->postingLinks($lockedShift, $reads);
                $sourceRevision = $this->postingSourceRevision($lockedShift, $active, $replacement);
                abort_if(isset($data['source_revision']) && ! hash_equals($sourceRevision, $data['source_revision']),
                    409, 'The selected posting source changed. Reload the available shifts before posting.');
                $reason = $this->postingRefusal($lockedShift, $active, $replacement);
                if ($reason !== null) {
                    throw ValidationException::withMessages(['shift_id' => match ($reason) {
                        'duplicate' => 'This shift already has an active open position.',
                        'terminal_shift' => 'Completed or cancelled shifts cannot be posted for cover.',
                        default => 'This shift is assigned. Request replacement cover from the shift first.',
                    }]);
                }
                $expires = empty($data['expires_at']) ? null : Carbon::parse($data['expires_at'])->utc()->startOfSecond();
                if ($expires?->lte(now())) {
                    throw ValidationException::withMessages(['expires_at' => 'The expiry must still be in the future.']);
                }
                $shiftBefore = $lockedShift->getRawOriginal();
                $replacementBefore = $replacement?->getRawOriginal();
                $attributes = ['shift_id' => (int) $lockedShift->id, 'replacement_request_id' => $replacement?->id,
                    'status' => 'open', 'required_skills' => array_values($data['required_skills'] ?? []),
                    'coverage_roles' => $lockedShift->coverage_roles ?? [], 'notes' => $data['notes'] ?? null, 'expires_at' => $expires,
                    'claimed_by' => null, 'claimed_at' => null, 'approved_by' => null, 'approved_at' => null];
                $position = new ShiftOpenPosition($attributes);
                if (! $position->save()) {
                    throw ValidationException::withMessages(['shift_id' => 'The open position could not be saved. No position was posted.']);
                }
                $stored = ShiftOpenPosition::query()->whereKey($position->id)->lockForUpdate()->first();
                $actualShift = Shift::query()->whereKey($lockedShift->id)->lockForUpdate()->first();
                $valid = $stored && $actualShift?->getRawOriginal() === $shiftBefore;
                foreach (['shift_id', 'replacement_request_id', 'status', 'required_skills', 'coverage_roles', 'notes', 'claimed_by', 'claimed_at', 'approved_by', 'approved_at'] as $field) {
                    $actual = $stored?->{$field};
                    if (in_array($field, ['shift_id', 'replacement_request_id'], true)) {
                        $actual = $actual === null ? null : (int) $actual;
                    }
                    $valid = $valid && $actual === $attributes[$field];
                }
                $valid = $valid && ($expires === null ? $stored?->expires_at === null : $stored?->expires_at?->equalTo($expires));
                if ($replacement) {
                    $valid = $valid && $reads->query(ShiftReplacementRequest::query()->whereKey($replacement->id))->first()?->getRawOriginal() === $replacementBefore;
                }
                if (! $valid) {
                    throw ValidationException::withMessages(['shift_id' => 'The saved open position did not match this request. No position was posted.']);
                }

                return ['actor_id' => (int) $actor->id, 'source_revision' => $sourceRevision,
                    'position' => ['id' => (int) $stored->id,
                        ...array_diff_key($attributes, ['expires_at' => true]), 'expires_at' => $stored->expires_at?->copy()->utc()->toISOString()],
                    'shift' => ['id' => (int) $lockedShift->id, 'site_id' => $this->siteAccess->shiftSiteId($lockedShift),
                        'assigned' => $lockedShift->user_id !== null,
                        'status' => $lockedShift->status, 'starts_at' => $lockedShift->starts_at?->copy()->utc()->toISOString(),
                        'ends_at' => $lockedShift->ends_at?->copy()->utc()->toISOString()]];
            });
        });
        Cache::forget($this->availableSkillsCacheKey($request->user()));
        $result = $this->postingResult($rootEntry, $request, $saved);
        if ($request->expectsJson() && ! empty($data['request_id'])) {
            return response()->json(['result' => $result], $result ? 201 : 202);
        }

        return $result ? redirect()->back()->with('success', 'Open position published.') : redirect()->back();
    }

    private function postingActor(User $actor): User
    {
        $current = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($actor, [
            'job_board.create', 'shifts.manageAny', 'reports.viewAny', 'job_board.approve', 'shifts.viewAny',
        ]);
        abort_unless($current->isApproved() && $this->canCreatePositions($current), 403);

        return $current;
    }

    private function postingShiftEvidence(Shift $shift, array $siteIds, CurrentAuthorizationReads $reads): bool
    {
        $client = $shift->client_id === null ? null : $reads->query(Client::query()->whereKey($shift->client_id))->first();
        $siteId = $shift->site_id ?: $client?->site_id;
        if (! $siteId || ! in_array((int) $siteId, $siteIds, true)
            || ($shift->client_id !== null && (! $client || ! $client->site_id || ($shift->site_id !== null && (int) $shift->site_id !== (int) $client->site_id)))) {
            return false;
        }
        $site = $reads->query(Site::query()->whereKey($siteId))->first();
        if (! $site) {
            return false;
        }
        $client?->setRelation('site', $site);
        $shift->setRelation('client', $client);
        $shift->setRelation('site', $site);
        if ($shift->user_id !== null) {
            if (! $reads->query(User::query()->whereKey($shift->user_id))->first()) {
                return false;
            }
            $worker = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting((int) $shift->user_id, []);
            // Preserve the existing Shift query's exact worker predicate.
            if (! $worker->isApproved() || in_array($worker->role, ['client', 'next_of_kin'], true)
                || $worker->roles->contains(fn ($role) => in_array($role->name, ['client', 'next_of_kin'], true))
                || ! in_array((int) $siteId, $this->siteAccess->accessibleSiteIds($worker, [], $reads), true)) {
                return false;
            }
        }

        return true;
    }

    private function postingLinks(Shift $shift, CurrentAuthorizationReads $reads): array
    {
        $replacement = $reads->query(ShiftReplacementRequest::query()->where('shift_id', $shift->id)->active()
            ->orderByDesc('requested_at')->orderByDesc('id'))->get()->first(fn ($row) => $this->postingReplacementIsCanonical($row, $shift, $reads)
                && ($shift->user_id === null || (int) $row->current_staff_id === (int) $shift->user_id));
        $active = $reads->query(ShiftOpenPosition::query()->where('shift_id', $shift->id)->whereIn('status', ['open', 'claimed'])->orderBy('id'))
            ->get()->first(function (ShiftOpenPosition $position) use ($shift, $reads): bool {
                if (! $this->postingUsersExist([$position->claimed_by, $position->approved_by], $reads)) {
                    return false;
                }
                if ($position->replacement_request_id === null) {
                    return true;
                }
                $row = $reads->query(ShiftReplacementRequest::query()->whereKey($position->replacement_request_id))->first();

                return $row && $this->postingReplacementIsCanonical($row, $shift, $reads);
            });

        return [$active, $replacement];
    }

    private function postingReplacementIsCanonical(ShiftReplacementRequest $row, Shift $shift, CurrentAuthorizationReads $reads): bool
    {
        return (int) $row->shift_id === (int) $shift->id && $row->current_staff_id !== null
            && $this->postingUsersExist([$row->current_staff_id, $row->requested_by, $row->replacement_user_id, $row->approved_by, $row->cancelled_by], $reads);
    }

    private function postingUsersExist(array $ids, CurrentAuthorizationReads $reads): bool
    {
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => $id !== null)));

        return $ids === [] || $reads->query(User::query()->whereIn('id', $ids)->orderBy('id'))->get(['id'])->count() === count($ids);
    }

    private function postingSourceRevision(Shift $shift, ?ShiftOpenPosition $active, ?ShiftReplacementRequest $replacement): string
    {
        $nullableId = static fn ($id): ?int => $id === null ? null : (int) $id;

        return hash('sha256', json_encode([
            'shift' => [
                'id' => (int) $shift->id,
                'client_id' => $nullableId($shift->client_id),
                'site_id' => $nullableId($shift->site_id),
                'effective_site_id' => $this->siteAccess->shiftSiteId($shift),
                'service_context_id' => $nullableId($shift->service_context_id),
                'shift_series_id' => $nullableId($shift->shift_series_id),
                'respite_booking_id' => $nullableId($shift->respite_booking_id),
                'user_id' => $nullableId($shift->user_id),
                'status' => $shift->status,
                'starts_at' => $shift->getRawOriginal('starts_at'),
                'ends_at' => $shift->getRawOriginal('ends_at'),
                'coverage_roles' => $shift->coverage_roles ?? [],
                'location' => $shift->location,
            ],
            'active_position' => $active ? [
                'id' => (int) $active->id,
                'status' => $active->status,
                'expires_at' => $active->getRawOriginal('expires_at'),
            ] : null,
            'replacement' => $replacement ? [
                'id' => (int) $replacement->id,
                'shift_id' => (int) $replacement->shift_id,
                'current_staff_id' => $nullableId($replacement->current_staff_id),
                'replacement_user_id' => $nullableId($replacement->replacement_user_id),
                'status' => $replacement->status,
                'requested_at' => $replacement->getRawOriginal('requested_at'),
            ] : null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function postingRefusal(Shift $shift, ?ShiftOpenPosition $active, ?ShiftReplacementRequest $replacement): ?string
    {
        if ($active) {
            return 'duplicate';
        }
        if (in_array($shift->status, ['completed', 'cancelled'], true)) {
            return 'terminal_shift';
        }

        return $shift->user_id !== null && ! $replacement ? 'request_cover_required' : null;
    }

    protected function postingResult(bool $rootEntry, Request $request, array $saved): ?array
    {
        try {
            if (! $rootEntry || DB::transactionLevel() !== 0 || DB::connection()->getPdo()->inTransaction()
                || $saved['actor_id'] !== $request->user()?->id) {
                return null;
            }

            return ['version' => 1, 'action' => 'post_position', 'outcome' => 'posted', 'changed' => true,
                'request_id' => $request->input('request_id'), ...$saved,
                'committed_at' => now('UTC')->startOfSecond()->toISOString()];
        } catch (\Throwable $exception) {
            try {
                Log::warning('Committed job board result could not be presented', ['exception_class' => $exception::class]);
            } catch (\Throwable) {
            }

            return null;
        }
    }

    /**
     * Toggle the viewer's "Alert me" subscription. Returns to the job board.
     */
    public function toggleAlerts(Request $request)
    {
        return $this->jobBoardActionResponse($request, 'set_alert_preference');
    }

    public function claim(Request $request, $position)
    {
        return $this->jobBoardActionResponse($request, 'claim', (int) $position);
    }

    public function approve(Request $request, $position)
    {
        return $this->jobBoardActionResponse($request, 'approve', (int) $position);
    }

    protected function canViewJobBoard(?User $auth): bool
    {
        return (bool) $auth && (
            $auth->canDo('job_board.viewAny')
            || $auth->canDo('job_board.claim')
            || $auth->canDo('shifts.viewAny')
            || $auth->canDo('shifts.viewAssigned')
        );
    }

    protected function canCreatePositions(?User $auth): bool
    {
        return (bool) $auth && (
            $auth->canDo('job_board.create')
            || $auth->canDo('shifts.manageAny')
        );
    }

    protected function canClaimPositions(?User $auth): bool
    {
        return (bool) $auth && (
            $auth->canDo('job_board.claim')
            || $auth->canDo('shifts.viewAssigned')
            || $auth->canDo('shifts.manageAny')
        );
    }

    protected function canApprovePositions(?User $auth): bool
    {
        return (bool) $auth && (
            $auth->canDo('job_board.approve')
            || $auth->canDo('shifts.manageAny')
        );
    }

    protected function formatPositionForViewer(
        ShiftOpenPosition $position,
        User $viewer,
        ?EligibilityResult $viewerEligibilityResult = null,
        ?EligibilityResult $positionEligibilityResult = null,
        ?bool $viewerCanSeeSensitive = null,
        ?int $pastShiftsHere = null,
    ): array {
        $canViewSensitiveDetails = $this->canViewSensitivePositionDetails($position, $viewer, $viewerCanSeeSensitive);
        $viewerEligibilityResult ??= $this->evaluateViewerEligibility($position, $viewer);
        $viewerEligibility = $viewerEligibilityResult
            ? $this->formatEligibilityResult($viewerEligibilityResult)
            : null;
        $requiredSkills = $position->required_skills ?? [];
        $coverageRoles = $position->coverage_roles ?? [];
        $tasksTotal = $position->shift?->tasks?->count() ?? 0;
        $taskList = $this->buildTaskList($position, $canViewSensitiveDetails);
        $pastShiftsHere ??= $this->countPastShiftsHere($position, $viewer);

        return [
            'id' => $position->id,
            'title' => $this->formatPositionTitle($position, $canViewSensitiveDetails),
            'status' => $position->status,
            // Shift times are stored in UTC; cards show the worker's wall clock,
            // the same zone the approve and claim dialogs use.
            'date' => $position->shift?->starts_at?->copy()->setTimezone($this->workerTimezone())->toDateString(),
            'start_time' => $position->shift?->starts_at?->copy()->setTimezone($this->workerTimezone())->format('H:i'),
            'end_time' => $position->shift?->ends_at?->copy()->setTimezone($this->workerTimezone())->format('H:i'),
            'location' => $this->formatPositionLocation($position, $canViewSensitiveDetails),
            'required_skills' => $requiredSkills,
            'your_skills' => $this->resolveViewerSkills($requiredSkills, $viewerEligibilityResult),
            'coverage_roles' => $coverageRoles,
            'coverage' => $this->buildCoverageLabel($coverageRoles, $position->shift),
            'privacy' => [
                'can_view_sensitive_details' => $canViewSensitiveDetails,
            ],
            'client' => $this->formatClientForViewer($position, $canViewSensitiveDetails),
            'replacement' => $position->replacementRequest ? [
                'id' => $position->replacementRequest->id,
                'status' => $position->replacementRequest->status,
                'requested_at' => optional($position->replacementRequest->requested_at)->toISOString(),
                'current_staff' => $canViewSensitiveDetails && $position->replacementRequest->currentStaff
                    ? ['id' => $position->replacementRequest->currentStaff->id, 'name' => $position->replacementRequest->currentStaff->name]
                    : null,
                'requested_by' => $canViewSensitiveDetails && $position->replacementRequest->requester
                    ? ['id' => $position->replacementRequest->requester->id, 'name' => $position->replacementRequest->requester->name]
                    : null,
                'replacement_staff' => $position->replacementRequest->replacementStaff
                    ? ['id' => $position->replacementRequest->replacementStaff->id, 'name' => $position->replacementRequest->replacementStaff->name]
                    : null,
            ] : null,
            'claimed_by' => $position->claimer ? [
                'id' => $position->claimer->id,
                'name' => $position->claimer->name,
            ] : null,
            'eligibility' => $this->getPositionEligibility($position, $positionEligibilityResult),
            'viewer_eligibility' => $viewerEligibility,
            'your_schedule' => $this->buildYourSchedule($position, $viewerEligibilityResult),
            'tasks' => $taskList,
            'tasks_total' => $tasksTotal,
            'past_shifts_here' => $pastShiftsHere,
            'site_familiar' => $pastShiftsHere > 0,
        ];
    }

    protected function buildCoverageLabel(array $coverageRoles, ?Shift $shift): ?string
    {
        if (! empty($coverageRoles)) {
            $role = str_replace('_', ' ', (string) $coverageRoles[0]);

            return '1:1 '.$role;
        }

        return $shift?->is_sleepover ? '1:1 sleepover' : null;
    }

    protected function resolveViewerSkills(array $requiredSkills, ?EligibilityResult $eligibility): array
    {
        if (empty($requiredSkills)) {
            return [];
        }

        if (! $eligibility) {
            return [];
        }

        // Compliance rule failures expose which requirements the viewer is missing.
        // Anything not flagged as a compliance block/warning is treated as held.
        $missingRequirementNames = $this->extractMissingRequirementNames($eligibility);

        if (empty($missingRequirementNames)) {
            return array_values($requiredSkills);
        }

        return array_values(array_filter(
            $requiredSkills,
            function (string $skill) use ($missingRequirementNames): bool {
                $needle = strtolower($skill);
                foreach ($missingRequirementNames as $missing) {
                    if (str_contains(strtolower($missing), $needle) || str_contains($needle, strtolower($missing))) {
                        return false;
                    }
                }

                return true;
            }
        ));
    }

    protected function extractMissingRequirementNames(EligibilityResult $eligibility): array
    {
        $names = [];

        foreach ($eligibility->checked_rules as $rule) {
            if (($rule['rule'] ?? null) !== 'compliance') {
                continue;
            }

            foreach (($rule['compliance_warnings'] ?? []) as $warning) {
                if (! empty($warning['requirement'])) {
                    $names[] = $warning['requirement'];
                }
            }
        }

        return $names;
    }

    protected function buildYourSchedule(ShiftOpenPosition $position, ?EligibilityResult $eligibility): ?array
    {
        if (! $position->shift || $position->status !== 'open') {
            return null;
        }

        if (! $eligibility) {
            return null;
        }

        $conflict = null;
        $timeOff = null;
        $fatigue = null;

        foreach ($eligibility->checked_rules as $rule) {
            if (($rule['passed'] ?? true) === true) {
                continue;
            }

            $ruleName = $rule['rule'] ?? '';
            $severity = $rule['severity'] ?? 'block';
            $message = $rule['message'] ?? null;

            if ($ruleName === 'conflict' && $conflict === null) {
                $conflict = [
                    'type' => 'shift',
                    'label' => $message,
                    'severity' => $severity,
                ];
            } elseif ($ruleName === 'time_off' && $timeOff === null) {
                $timeOff = ['label' => $message];
            } elseif (str_starts_with($ruleName, 'fatigue') && $fatigue === null) {
                $fatigue = [
                    'label' => $message,
                    'severity' => $severity,
                ];
            }
        }

        return [
            'conflict' => $conflict,
            'time_off' => $timeOff,
            'fatigue' => $fatigue,
            'free' => $conflict === null && $timeOff === null && $fatigue === null,
        ];
    }

    protected function buildTaskList(ShiftOpenPosition $position, bool $canViewSensitiveDetails): array
    {
        if (! $position->shift || ! $position->shift->relationLoaded('tasks')) {
            return [];
        }

        $tasks = $position->shift->tasks->take(5);

        return $tasks->map(function ($task) use ($canViewSensitiveDetails) {
            $label = (string) ($task->label ?? '');

            return [
                'label' => $canViewSensitiveDetails
                    ? $label
                    : 'Task details visible after claim approval',
                'kind' => $this->categoriseTaskKind($label),
            ];
        })->all();
    }

    protected function categoriseTaskKind(string $label): string
    {
        $needle = strtolower($label);

        if (preg_match('/\b(med|medication|peg|catheter|injection|dosage|vital)\b/', $needle)) {
            return 'med';
        }

        if (preg_match('/\b(meal|breakfast|lunch|dinner|snack|cook|food|grocer|brunch)\b/', $needle)) {
            return 'meal';
        }

        if (preg_match('/\b(community|outing|access|library|park|cafe|drive|transport|appointment|garden)\b/', $needle)) {
            return 'access';
        }

        return 'care';
    }

    protected function countPastShiftsHere(ShiftOpenPosition $position, User $viewer): int
    {
        $clientId = $position->shift?->client_id;
        if (! $clientId) {
            return 0;
        }

        return Shift::query()
            ->where('user_id', $viewer->id)
            ->where('client_id', $clientId)
            ->where('status', 'completed')
            ->where('starts_at', '>=', now()->subWeeks(self::RECENT_WEEKS_LOOKBACK))
            ->count();
    }

    /**
     * Count the viewer's recent completed shifts per client for the rendered
     * page in ONE grouped query, keyed by client_id. Mirrors the predicate of
     * countPastShiftsHere(); clients with no history are simply absent (the
     * caller defaults to 0).
     *
     * @return array<int, int>
     */
    protected function batchPastShiftsHere(iterable $positions, User $viewer): array
    {
        $clientIds = collect($positions)
            ->map(fn ($position) => $position instanceof ShiftOpenPosition ? $position->shift?->client_id : null)
            ->filter()
            ->unique()
            ->values();

        if ($clientIds->isEmpty()) {
            return [];
        }

        return Shift::query()
            ->where('user_id', $viewer->id)
            ->whereIn('client_id', $clientIds->all())
            ->where('status', 'completed')
            ->where('starts_at', '>=', now()->subWeeks(self::RECENT_WEEKS_LOOKBACK))
            ->groupBy('client_id')
            ->selectRaw('client_id, count(*) as aggregate')
            ->pluck('aggregate', 'client_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    protected function viewerFirstName(User $viewer): string
    {
        $name = trim((string) $viewer->name);
        if ($name === '') {
            return 'there';
        }

        return strtok($name, ' ') ?: $name;
    }

    protected function formatPositionTitle(ShiftOpenPosition $position, bool $canViewSensitiveDetails = true): string
    {
        if (! $canViewSensitiveDetails) {
            $location = $this->publicLocationLabel($position);

            return $location
                ? 'Support shift near '.$location
                : 'Open support shift';
        }

        $clientName = trim((string) ($position->shift?->client?->first_name.' '.$position->shift?->client?->last_name));

        if ($clientName !== '') {
            return 'Support shift for '.$clientName;
        }

        if ($position->shift?->location) {
            return 'Open shift at '.$position->shift->location;
        }

        return 'Open support shift';
    }

    protected function formatPositionLocation(ShiftOpenPosition $position, bool $canViewSensitiveDetails): ?string
    {
        if ($canViewSensitiveDetails) {
            return $position->shift?->location ?? $position->shift?->client?->site?->name;
        }

        return $this->publicLocationLabel($position) ?: 'Location confirmed after approval';
    }

    protected function formatClientForViewer(ShiftOpenPosition $position, bool $canViewSensitiveDetails): ?array
    {
        $client = $position->shift?->client;
        if (! $client) {
            return null;
        }

        $displayName = $canViewSensitiveDetails
            ? trim($client->first_name.' '.$client->last_name)
            : trim($this->initial($client->first_name).' '.$this->initial($client->last_name));

        return [
            'id' => $client->id,
            'first_name' => $canViewSensitiveDetails ? $client->first_name : $this->initial($client->first_name),
            'last_name' => $canViewSensitiveDetails ? $client->last_name : $this->initial($client->last_name),
            'display_name' => $displayName !== '' ? $displayName : 'Client',
            'suburb' => $client->suburb ?: $client->city ?: $client->site?->suburb ?: $client->site?->city,
            'is_redacted' => ! $canViewSensitiveDetails,
        ];
    }

    protected function canViewSensitivePositionDetails(ShiftOpenPosition $position, User $viewer, ?bool $viewerCanSeeSensitive = null): bool
    {
        // The three viewer-wide capability checks are identical for every card,
        // so the caller may resolve them once and pass the result in. Fall back
        // to evaluating them here when not supplied.
        $viewerCanSeeSensitive ??= $viewer->canDo('job_board.approve')
            || $viewer->canDo('shifts.manageAny')
            || $viewer->canDo('shifts.viewAny');

        if ($viewerCanSeeSensitive) {
            return true;
        }

        if ((int) $position->shift?->user_id === (int) $viewer->id) {
            return true;
        }

        return $position->status === 'filled'
            && (int) $position->claimed_by === (int) $viewer->id;
    }

    protected function publicLocationLabel(ShiftOpenPosition $position): ?string
    {
        return $position->shift?->client?->suburb
            ?: $position->shift?->client?->city
            ?: $position->shift?->client?->site?->suburb
            ?: $position->shift?->client?->site?->city
            ?: $position->shift?->client?->site?->name;
    }

    protected function initial(?string $value): string
    {
        $value = trim((string) $value);

        return $value === '' ? '' : mb_strtoupper(mb_substr($value, 0, 1)).'.';
    }

    protected function applyDateRangeFilter($query, string $dateRange, ?array $bounds = null): void
    {
        [$startsAt, $endsAt] = $bounds ?? $this->jobBoardDateRangeBounds($dateRange, Carbon::now());

        $query->whereHas('shift', fn ($shiftQuery) => $shiftQuery
            ->whereBetween('starts_at', [$startsAt, $endsAt]));
    }

    protected function weekendRange(): array
    {
        $startsAt = Carbon::now()->startOfWeek(Carbon::MONDAY)->addDays(5)->startOfDay();
        $endsAt = $startsAt->copy()->addDay()->endOfDay();

        if (Carbon::now()->greaterThan($endsAt)) {
            $startsAt->addWeek();
            $endsAt->addWeek();
        }

        return [$startsAt, $endsAt];
    }

    protected function availableSkills(User $viewer): array
    {
        // The chip list plucks + JSON-decodes required_skills for every open
        // position on each load. Cache the derived list per accessible Site set for a
        // short TTL so the decode does not run on every request; the brief
        // staleness window avoids needing explicit cross-writer invalidation.
        return Cache::remember($this->availableSkillsCacheKey($viewer), 60, function () use ($viewer) {
            return ShiftOpenPosition::query()
                ->tap(fn ($query) => $this->siteAccess->applyShiftOpenPositionScope($query, $viewer, ['reports.viewAny']))
                ->where('status', 'open')
                ->where(function ($query) {
                    $query->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                })
                ->pluck('required_skills')
                ->flatten()
                ->filter(fn ($skill) => is_string($skill) && trim($skill) !== '')
                ->map(fn ($skill) => trim($skill))
                ->unique()
                ->sort()
                ->values()
                ->all();
        });
    }

    protected function availableSkillsCacheKey(User $viewer): string
    {
        $siteIds = $this->siteAccess->accessibleSiteIds($viewer, ['reports.viewAny']);

        return 'job_board:available_skills:sites:'.sha1(json_encode($siteIds, JSON_THROW_ON_ERROR));
    }

    /**
     * Lightweight eligibility check for a claimed position.
     * Only runs when a claimer exists to avoid unnecessary work.
     */
    protected function batchViewerEligibility(iterable $positions, User $viewer): array
    {
        $pending = collect();
        $results = [];

        foreach ($positions as $position) {
            if (! $position instanceof ShiftOpenPosition || ! $position->shift) {
                continue;
            }

            if ($position->status !== 'open') {
                continue;
            }

            if ((int) $position->shift->user_id === (int) $viewer->id) {
                $results[$position->id] = new EligibilityResult(
                    is_allowed: false,
                    blocking_reasons: ['You are already assigned to this shift.'],
                    warnings: [],
                    checked_rules: [],
                    overrideable_warnings: [],
                );

                continue;
            }

            if (in_array($position->shift->status, ['completed', 'cancelled'], true)) {
                $results[$position->id] = new EligibilityResult(
                    is_allowed: false,
                    blocking_reasons: ['This shift can no longer be claimed.'],
                    warnings: [],
                    checked_rules: [],
                    overrideable_warnings: [],
                );

                continue;
            }

            $pending->push($position);
        }

        if ($pending->isEmpty()) {
            return $results;
        }

        $batch = app(ShiftStaffEligibilityService::class)
            ->evaluateMany($pending->pluck('shift'), [$viewer]);

        foreach ($pending as $position) {
            $result = $batch[$position->shift->id][$viewer->id] ?? null;
            if ($result) {
                $results[$position->id] = $result;
            }
        }

        return $results;
    }

    protected function batchClaimedPositionEligibility(iterable $positions): array
    {
        $pending = collect($positions)
            ->filter(fn ($position) => $position instanceof ShiftOpenPosition
                && $position->shift
                && $position->claimer)
            ->values();

        if ($pending->isEmpty()) {
            return [];
        }

        $batch = app(ShiftStaffEligibilityService::class)
            ->evaluateMany(
                $pending->pluck('shift'),
                $pending->pluck('claimer')->unique('id')->values(),
            );

        $results = [];
        foreach ($pending as $position) {
            $result = $batch[$position->shift->id][$position->claimer->id] ?? null;
            if ($result) {
                $results[$position->id] = $result;
            }
        }

        return $results;
    }

    protected function getPositionEligibility($position, ?EligibilityResult $result = null): ?array
    {
        if (! $position->claimer || ! $position->shift) {
            return null;
        }

        try {
            $result ??= app(ShiftStaffEligibilityService::class)
                ->evaluate($position->shift, $position->claimer);

            return [
                'is_eligible' => $result->is_allowed,
                'blocked_reasons' => $result->blocking_reasons,
                'warning_count' => count($result->warnings),
                'first_warning' => $result->warnings[0] ?? null,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function evaluateViewerEligibility(ShiftOpenPosition $position, User $viewer): ?EligibilityResult
    {
        if (! $position->shift) {
            return null;
        }

        if ($position->status !== 'open') {
            return null;
        }

        if ((int) $position->shift->user_id === (int) $viewer->id) {
            return new EligibilityResult(
                is_allowed: false,
                blocking_reasons: ['You are already assigned to this shift.'],
                warnings: [],
                checked_rules: [],
                overrideable_warnings: [],
            );
        }

        if (in_array($position->shift->status, ['completed', 'cancelled'], true)) {
            return new EligibilityResult(
                is_allowed: false,
                blocking_reasons: ['This shift can no longer be claimed.'],
                warnings: [],
                checked_rules: [],
                overrideable_warnings: [],
            );
        }

        try {
            return app(ShiftStaffEligibilityService::class)->evaluate($position->shift, $viewer);
        } catch (\Throwable $e) {
            Log::warning('Viewer eligibility check failed for job board position', [
                'position_id' => $position->id,
                'user_id' => $viewer->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function formatEligibilityResult(EligibilityResult $result): array
    {
        return [
            'is_eligible' => $result->is_allowed,
            'blocked_reasons' => $result->blocking_reasons,
            'warning_count' => count($result->warnings),
            'first_warning' => $result->warnings[0] ?? null,
        ];
    }

    public function actionSource(Request $request, $position)
    {
        return response()->json(app(JobBoardActionCommand::class)->source($request, (int) $position));
    }

    public function alertPreference(Request $request)
    {
        return response()->json(app(JobBoardActionCommand::class)->source($request));
    }

    private function jobBoardActionResponse(Request $request, string $action, ?int $position = null)
    {
        $outcome = app(JobBoardActionCommand::class)->execute($request, $action, $position);
        if (JobBoardActionCommand::modern($request)) {
            return response()->json(['result' => $outcome['confirmed'] ? $outcome['saved'] : null], $outcome['confirmed'] ? 200 : 202);
        }
        if (! $outcome['confirmed']) {
            return redirect()->back(); // A nested or changed commit has no confirmed success.
        }
        $result = $outcome['saved'];
        $warnings = $outcome['legacy_warnings'];
        if ($warnings !== []) {
            session()->flash($action === 'claim' ? 'job_board_claim_warnings' : 'compliance_warnings', $warnings);
        }
        $message = match ($action) {
            'claim' => 'Position claimed.',
            'approve' => 'Claim approved and shift assigned.',
            default => $result['preference']['enabled']
                ? 'Alerts enabled — we\'ll notify you when matching shifts open.' : 'Alerts disabled.',
        };

        return redirect()->back()->with('success', $message);
    }

    private function jobBoardDateRangeBounds(string $dateRange, Carbon $readAt): array
    {
        if ($dateRange === 'this_weekend') {
            $start = $readAt->copy()->startOfWeek(Carbon::MONDAY)->addDays(5)->startOfDay();
            $end = $start->copy()->addDay()->endOfDay();
            if ($readAt->greaterThan($end)) {
                $start->addWeek();
                $end->addWeek();
            }

            return [$start, $end];
        }

        return $dateRange === 'tonight'
            ? [$readAt->copy()->setTime(18, 0), $readAt->copy()->addDay()->setTime(6, 0)]
            : [$readAt->copy()->startOfDay(), $readAt->copy()->addDays(7)->endOfDay()];
    }

    private function jobBoardProvenance(User $actor, array $filters, string $scope, bool $weekRequested,
        Carbon $weekStart, Carbon $weekEnd, ?array $dateBounds, Carbon $readAt,
        $jobs, array $stats, bool $canApprove): array
    {
        $weekApplied = $weekRequested && $scope !== 'mine';
        $applied = ['scope'];
        foreach (['q', 'status', 'skill', 'date_range'] as $key) {
            $used = in_array($key, ['q', 'skill'], true)
                ? trim((string) ($filters[$key] ?? '')) !== '' : ! empty($filters[$key]);
            if ($used) {
                $applied[] = $key;
            }
        }
        if ($weekApplied) {
            $applied[] = 'week';
        }
        $ignored = [];
        if ($weekRequested && ! $weekApplied) {
            $ignored[] = 'week';
        }
        // Existing fit is validated/echoed, but is not an installed SQL or eligibility filter.
        if (! empty($filters['fit'])) {
            $ignored[] = 'fit';
        }
        $open = collect($jobs->items())->where('status', 'open');
        $assessed = $open->filter(fn ($job) => is_array($job['viewer_eligibility'] ?? null)
            && is_bool($job['viewer_eligibility']['is_eligible'] ?? null));
        $eligible = $assessed->filter(fn ($job) => $job['viewer_eligibility']['is_eligible'])->count();
        $unassessed = $open->count() - $assessed->count();
        $status = $open->isEmpty() ? 'empty' : ($unassessed > 0 ? 'partial' : 'complete');
        $weekWindow = $this->jobBoardSqlWindow([$weekStart, $weekEnd]);
        $criteria = $scope === 'mine'
            ? ['claimed_by_actor', 'active_claims_or_filled_approved_since']
            : match ($scope) {
                'replacements' => ['replacement_linked', 'open_unexpired_or_non_open'],
                'approvals' => ['claimed', 'claimed_by_other'],
                default => ['open_unexpired'],
            };
        $allBasis = ['basis' => 'accessible_all_weeks', 'list_filters_applied' => false];
        $metrics = [];
        foreach (['open', 'claimed', 'filled_today', 'expiring_soon', 'replacements', 'sites'] as $key) {
            $metrics[$key] = $allBasis + ['value' => $stats[$key], 'assessment_status' => 'counted'];
        }
        $metrics['filled_today'] += ['date_field' => 'approved_at', 'date_value' => $readAt->toDateString(), 'storage_timezone' => 'UTC'];
        $metrics['expiring_soon'] += ['date_field' => 'expires_at', 'window' => $this->jobBoardSqlWindow([$readAt, $readAt->copy()->addHour()])];
        $metrics['mine'] = ['basis' => 'actor_claims_active_or_recent_filled', 'list_filters_applied' => false,
            'value' => $stats['mine'], 'assessment_status' => 'counted',
            'filled_approved_since_utc' => $this->jobBoardSqlWindow([$readAt->copy()->subDays(14), $readAt])['starts_at_utc']];
        $metrics['pending_approval'] = ['basis' => 'accessible_other_claims_all_weeks', 'list_filters_applied' => false,
            'value' => $canApprove ? $stats['pending_approval'] : null,
            'assessment_status' => $canApprove ? 'counted' : 'not_authorized'];
        $metrics['sites_worked_this_week'] = ['basis' => 'actor_assigned_shift_starts_in_anchor_week', 'list_filters_applied' => false,
            'value' => $stats['sites_worked_this_week'], 'assessment_status' => 'counted', 'window' => $weekWindow];
        $metrics['eligible_for_you'] = ['basis' => 'returned_open_positions', 'list_filters_applied' => true,
            'value' => $unassessed === 0 ? $eligible : null, 'assessed_eligible' => $eligible, 'assessment_status' => $status];

        return [
            'version' => 1, 'actor_id' => (int) $actor->id,
            'read_at_utc' => $readAt->copy()->utc()->toISOString(),
            'consistency' => 'request_read_not_atomic_snapshot',
            'worker_timezone' => (string) (config('app.worker_timezone') ?: config('app.timezone', 'UTC')),
            'list' => [
                'scope' => $scope, 'range_kind' => $scope === 'mine' ? 'my_claims' : ($weekApplied ? 'selected_week' : 'all_weeks'),
                'requested_week' => $weekRequested ? $weekStart->toDateString() : null,
                'week_applied' => $weekApplied, 'shift_start_window' => $weekApplied ? $weekWindow : null,
                'date_range' => $dateBounds === null ? null : ['key' => $filters['date_range'], 'window' => $this->jobBoardSqlWindow($dateBounds)],
                'criteria' => $criteria,
                'filled_approved_since_utc' => $scope === 'mine' ? $metrics['mine']['filled_approved_since_utc'] : null,
                'applied_filters' => $applied, 'ignored_filters' => $ignored,
                'eligibility_filtered' => false,
            ],
            'pagination' => ['current_page' => $jobs->currentPage(), 'per_page' => $jobs->perPage(),
                'returned' => count($jobs->items()), 'total' => $jobs->total(), 'last_page' => $jobs->lastPage(),
                'has_more' => $jobs->hasMorePages()],
            'eligibility' => [
                'basis' => 'returned_open_positions', 'assessment_status' => $status,
                'open_positions' => $open->count(), 'assessed' => $assessed->count(), 'unassessed' => $unassessed,
                'assessed_eligible' => $eligible, 'assessed_not_eligible' => $assessed->count() - $eligible,
                'page_eligible_count' => $unassessed === 0 ? $eligible : null,
                'filtered_list_eligible_count' => null, 'all_weeks_eligible_count' => null,
            ],
            'metrics' => $metrics,
            'available_skills_basis' => 'accessible_open_positions_all_weeks_cache_60_seconds',
        ];
    }

    private function workerTimezone(): string
    {
        return (string) (config('app.worker_timezone') ?: config('app.timezone', 'UTC'));
    }

    private function jobBoardSqlWindow(array $bounds): array
    {
        $format = DB::connection()->getQueryGrammar()->getDateFormat();

        return [
            'starts_at_utc' => Carbon::parse($bounds[0]->format($format), 'UTC')->toISOString(),
            'ends_at_utc' => Carbon::parse($bounds[1]->format($format), 'UTC')->toISOString(),
            'end_inclusive' => true,
            'anchor_timezone' => $bounds[0]->getTimezone()->getName(),
            'storage_timezone' => 'UTC',
        ];
    }
}
