<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\ShiftOpenPosition;
use App\Models\User;
use App\Services\CoverageReservationService;
use App\Services\Eligibility\EligibilityResult;
use App\Services\ShiftReplacementService;
use App\Services\ShiftStaffEligibilityService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class JobBoardController extends Controller
{
    protected const RECENT_WEEKS_LOOKBACK = 26;

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

        $canApprove = $this->canApprovePositions($auth);

        $search = trim((string) ($filters['q'] ?? ''));
        $scope = $filters['scope'] ?? 'for-you';
        $skill = trim((string) ($filters['skill'] ?? ''));
        $fit = $filters['fit'] ?? null;

        $weekFilterRequested = ! empty($filters['week']);
        $weekAnchor = $weekFilterRequested
            ? Carbon::createFromFormat('Y-m-d', $filters['week'])->startOfDay()
            : Carbon::now()->startOfWeek(Carbon::MONDAY);
        $weekStart = $weekAnchor->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);

        $positions = ShiftOpenPosition::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with([
                'shift:id,client_id,starts_at,ends_at,location,status,user_id,coverage_roles',
                'shift.client:id,first_name,last_name,site_id,suburb,city',
                'shift.client.site:id,name,suburb,city',
                'shift.tasks:id,shift_id,label,sort_order',
                'claimer:id,name',
                'approver:id,name',
                'replacementRequest:id,shift_id,requested_by,current_staff_id,replacement_user_id,status,reason,requested_at,claimed_at,approved_at,cancelled_at',
                'replacementRequest.requester:id,name',
                'replacementRequest.currentStaff:id,name',
                'replacementRequest.replacementStaff:id,name',
            ])
            ->where(function ($query) {
                $query->where('status', '!=', 'open')
                    ->orWhereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->when($scope === 'mine', function ($query) use ($auth) {
                $query->where('claimed_by', $auth->id)
                    ->where(function ($nested) {
                        $nested->where('status', 'claimed')
                            ->orWhere(function ($filled) {
                                $filled->where('status', 'filled')
                                    ->where('approved_at', '>=', now()->subDays(14));
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
            ->when($scope === 'all' || $scope === 'for-you', function ($query) {
                $query->where('status', 'open')
                    ->where(function ($nested) {
                        $nested->whereNull('expires_at')
                            ->orWhere('expires_at', '>', now());
                    });
            })
            ->when($weekFilterRequested && $scope !== 'mine', function ($query) use ($weekStart, $weekEnd) {
                $query->whereHas('shift', fn ($shiftQuery) => $shiftQuery
                    ->whereBetween('starts_at', [$weekStart, $weekEnd]));
            })
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($nested) use ($search) {
                    $nested->where('notes', 'like', '%'.$search.'%')
                        ->orWhereHas('replacementRequest', fn ($replacementQuery) => $replacementQuery->where('reason', 'like', '%'.$search.'%'))
                        ->orWhereHas('shift', fn ($shiftQuery) => $shiftQuery->where('location', 'like', '%'.$search.'%'))
                        ->orWhereHas('shift.client', fn ($clientQuery) => $clientQuery
                            ->where('first_name', 'like', '%'.$search.'%')
                            ->orWhere('last_name', 'like', '%'.$search.'%'));
                });
            })
            ->when(! empty($filters['date_range']), fn ($q) => $this->applyDateRangeFilter($q, $filters['date_range']))
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
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id));
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
            ->whereBetween('expires_at', [now(), now()->addHour()])
            ->count();

        $myClaimsCount = ShiftOpenPosition::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->where('claimed_by', $auth->id)
            ->where(function ($nested) {
                $nested->where('status', 'claimed')
                    ->orWhere(function ($filled) {
                        $filled->where('status', 'filled')
                            ->where('approved_at', '>=', now()->subDays(14));
                    });
            })
            ->count();

        $replacementsCount = (clone $statsQuery)
            ->whereNotNull('replacement_request_id')
            ->where(function ($query) {
                $query->where('status', '!=', 'open')
                    ->orWhereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->count();

        $pendingApprovalCount = $canApprove
            ? (clone $statsQuery)
                ->where('status', 'claimed')
                ->where('claimed_by', '!=', $auth->id)
                ->count()
            : 0;

        $sitesCount = ShiftOpenPosition::query()
            ->when($auth->organization_id, fn ($q) => $q->where('shift_open_positions.organization_id', $auth->organization_id))
            ->where('shift_open_positions.status', 'open')
            ->where(function ($query) {
                $query->whereNull('shift_open_positions.expires_at')
                    ->orWhere('shift_open_positions.expires_at', '>', now());
            })
            ->join('shifts', 'shifts.id', '=', 'shift_open_positions.shift_id')
            ->join('clients', 'clients.id', '=', 'shifts.client_id')
            ->whereNotNull('clients.site_id')
            ->distinct('clients.site_id')
            ->count('clients.site_id');

        $sitesWorkedThisWeek = Shift::query()
            ->where('shifts.user_id', $auth->id)
            ->where('shifts.starts_at', '>=', $weekStart)
            ->where('shifts.starts_at', '<=', $weekEnd)
            ->join('clients', 'clients.id', '=', 'shifts.client_id')
            ->whereNotNull('clients.site_id')
            ->distinct('clients.site_id')
            ->count('clients.site_id');

        return inertia('operations/job-board/Index', [
            'jobs' => $formattedJobs,
            'filters' => array_merge($filters, [
                'scope' => $scope,
                'week' => $weekStart->toDateString(),
            ]),
            'available_skills' => $availableSkills,
            'week' => [
                'start' => $weekStart->toDateString(),
                'end' => $weekEnd->toDateString(),
                'start_label' => $weekStart->format('j M'),
                'end_label' => $weekEnd->format('j M'),
                'prev' => $weekStart->copy()->subWeek()->toDateString(),
                'next' => $weekStart->copy()->addWeek()->toDateString(),
                'is_current' => $weekStart->equalTo(Carbon::now()->startOfWeek(Carbon::MONDAY)),
            ],
            'viewer' => [
                'first_name' => $this->viewerFirstName($auth),
                'can_approve' => $canApprove,
                'can_post_position' => $this->canCreatePositions($auth),
                'alerts_enabled' => (bool) ($auth->job_board_alerts_enabled ?? false),
            ],
            'stats' => [
                'open' => (clone $statsQuery)
                    ->where('status', 'open')
                    ->where(function ($query) {
                        $query->whereNull('expires_at')
                            ->orWhere('expires_at', '>', now());
                    })
                    ->count(),
                'claimed' => (clone $statsQuery)->where('status', 'claimed')->count(),
                'filled_today' => (clone $statsQuery)
                    ->where('status', 'filled')
                    ->whereDate('approved_at', now()->toDateString())
                    ->count(),
                'eligible_for_you' => $eligibleForYou,
                'expiring_soon' => $expiringSoon,
                'mine' => $myClaimsCount,
                'replacements' => $replacementsCount,
                'pending_approval' => $pendingApprovalCount,
                'sites' => $sitesCount,
                'sites_worked_this_week' => $sitesWorkedThisWeek,
            ],
        ]);
    }

    public function createPosition(Request $request)
    {
        $auth = $request->user();
        abort_unless($this->canCreatePositions($auth), 403);

        $data = $request->validate([
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'required_skills' => ['nullable', 'array'],
            'required_skills.*' => ['string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $shift = Shift::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($data['shift_id']);

        $existingActivePosition = ShiftOpenPosition::query()
            ->where('shift_id', $shift->id)
            ->whereIn('status', ['open', 'claimed'])
            ->exists();

        if ($existingActivePosition) {
            return redirect()->back()->withErrors([
                'shift_id' => 'This shift already has an active open position.',
            ]);
        }

        try {
            DB::transaction(function () use ($auth, $data, $shift) {
                $lockedShift = Shift::query()->lockForUpdate()->findOrFail($shift->id);
                $existingActivePosition = ShiftOpenPosition::query()
                    ->where('shift_id', $lockedShift->id)
                    ->whereIn('status', ['open', 'claimed'])
                    ->exists();

                if ($existingActivePosition) {
                    throw ValidationException::withMessages([
                        'shift_id' => 'This shift already has an active open position.',
                    ]);
                }

                ShiftOpenPosition::create([
                    'organization_id' => $auth->organization_id,
                    'shift_id' => $lockedShift->id,
                    'replacement_request_id' => app(ShiftReplacementService::class)->activeForShift($lockedShift)?->id,
                    'status' => 'open',
                    'required_skills' => array_values($data['required_skills'] ?? []),
                    'coverage_roles' => $lockedShift->coverage_roles ?? [],
                    'notes' => $data['notes'] ?? null,
                    'expires_at' => $data['expires_at'] ?? null,
                ]);
            });
        } catch (QueryException $exception) {
            report($exception);

            return redirect()->back()->withErrors([
                'shift_id' => 'This shift already has an active open position.',
            ]);
        }

        // Surface the new position's skills in the filter chips immediately
        // rather than waiting out the short cache TTL.
        Cache::forget($this->availableSkillsCacheKey($auth->organization_id));

        return redirect()->back()->with('success', 'Open position published.');
    }

    /**
     * Toggle the viewer's "Alert me" subscription. Returns to the job board.
     */
    public function toggleAlerts(Request $request)
    {
        $auth = $request->user();
        abort_unless($this->canViewJobBoard($auth), 403);

        $enabled = ! ($auth->job_board_alerts_enabled ?? false);
        $auth->forceFill(['job_board_alerts_enabled' => $enabled])->save();

        return redirect()->back()->with(
            'success',
            $enabled
                ? 'Alerts enabled — we\'ll notify you when matching shifts open.'
                : 'Alerts disabled.'
        );
    }

    public function claim(Request $request, $position)
    {
        $auth = $request->user();
        abort_unless($this->canClaimPositions($auth), 403);

        $position = ShiftOpenPosition::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with(['shift', 'replacementRequest', 'claimer'])
            ->findOrFail($position);

        if ($position->status !== 'open') {
            return redirect()->back()->withErrors([
                'claim' => $position->status === 'claimed'
                    ? 'This position was just claimed by another worker.'
                    : 'This position is no longer open for claims.',
            ]);
        }

        if ($position->expires_at && $position->expires_at->lte(now())) {
            return redirect()->back()->withErrors([
                'claim' => 'This position has expired and can no longer be claimed.',
            ]);
        }

        if ($position->shift && (int) $position->shift->user_id === (int) $auth->id) {
            return redirect()->back()->withErrors([
                'claim' => 'You are already assigned to this shift.',
            ]);
        }

        if (! $position->shift || in_array($position->shift->status, ['completed', 'cancelled'], true)) {
            return redirect()->back()->withErrors([
                'claim' => 'This shift can no longer be claimed from the job board.',
            ]);
        }

        $eligibility = app(ShiftStaffEligibilityService::class)->evaluate($position->shift, $auth);
        if ($eligibility->hasBlocks()) {
            return redirect()->back()->withErrors([
                'claim' => $eligibility->blocking_reasons[0] ?? 'You cannot claim this shift.',
            ]);
        }

        if ($eligibility->hasWarnings()) {
            session()->flash('job_board_claim_warnings', $eligibility->warnings);
        }

        $reservation = app(CoverageReservationService::class)->reserveForAssignment($position->shift, $auth, 'job_board_claim');

        try {
            DB::transaction(function () use ($position, $auth, $reservation) {
                $position = ShiftOpenPosition::query()->lockForUpdate()->findOrFail($position->id);
                if ($position->status !== 'open') {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'claim' => 'This position was just claimed by another worker.',
                    ]);
                }

                $position->update([
                    'claimed_by' => $auth->id,
                    'claimed_at' => now(),
                    'status' => 'claimed',
                ]);

                if ($reservation) {
                    $reservation->update([
                        'shift_open_position_id' => $position->id,
                    ]);
                }
            });
        } catch (\Throwable $e) {
            app(CoverageReservationService::class)->release($reservation);
            throw $e;
        }

        app(ShiftReplacementService::class)->syncClaimFromOpenPosition($position->fresh(['replacementRequest', 'claimer']));

        return redirect()->back()->with('success', 'Position claimed.');
    }

    public function approve(Request $request, $position)
    {
        $auth = $request->user();
        abort_unless($this->canApprovePositions($auth), 403);

        $position = ShiftOpenPosition::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with(['shift', 'replacementRequest'])
            ->where('status', 'claimed')
            ->findOrFail($position);

        if (! $position->shift || ! $position->claimed_by) {
            return redirect()->back()->withErrors([
                'position' => 'This claimed position is missing shift or claimant information.',
            ]);
        }

        $assignee = User::staff()->find($position->claimed_by);
        if (! $assignee) {
            return redirect()->back()->withErrors([
                'position' => 'The claimed worker is no longer available for assignment.',
            ]);
        }

        try {
            $eligibility = app(ShiftStaffEligibilityService::class)->evaluate($position->shift, $assignee);
            if ($eligibility->hasBlocks()) {
                return redirect()->back()->withErrors([
                    'position' => $eligibility->blocking_reasons[0] ?? 'Cannot approve this claim for the selected worker.',
                ])->with('compliance_warnings', $eligibility->toArray()['compliance_warnings'] ?? []);
            }

            if ($eligibility->hasWarnings()) {
                session()->flash('compliance_warnings', $eligibility->warnings);
            }
        } catch (\Throwable $e) {
            Log::warning('Eligibility check failed during job board approval', ['error' => $e->getMessage()]);
        }

        if (in_array($position->shift->status, ['completed', 'cancelled'], true)) {
            return redirect()->back()->withErrors([
                'position' => 'This shift can no longer be assigned from the job board.',
            ]);
        }

        $reservation = \App\Models\CoverageReservation::query()
            ->where('shift_open_position_id', $position->id)
            ->where('status', CoverageReservationService::STATUS_ACTIVE)
            ->latest('id')
            ->first();

        if (! $reservation) {
            $reservation = app(CoverageReservationService::class)->reserveForAssignment($position->shift, $auth, 'job_board_approve');
        }

        try {
            DB::transaction(function () use ($position, $auth, $reservation) {
                $position = ShiftOpenPosition::query()
                    ->with('shift')
                    ->lockForUpdate()
                    ->findOrFail($position->id);
                if ($position->status !== 'claimed') {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'position' => 'This claim is no longer active.',
                    ]);
                }

                $position->update([
                    'approved_by' => $auth->id,
                    'approved_at' => now(),
                    'status' => 'filled',
                ]);

                $position->shift->update([
                    'user_id' => $position->claimed_by,
                    'status' => $position->shift->status === 'draft' ? 'scheduled' : $position->shift->status,
                ]);

                ShiftOpenPosition::query()
                    ->where('shift_id', $position->shift_id)
                    ->where('id', '!=', $position->id)
                    ->whereIn('status', ['open', 'claimed'])
                    ->update(['status' => 'cancelled']);

                app(CoverageReservationService::class)->fulfill($reservation, $position->shift, $position);
            });
        } catch (\Throwable $e) {
            app(CoverageReservationService::class)->release($reservation);
            throw $e;
        }

        app(ShiftReplacementService::class)->approveFromOpenPosition(
            $position->fresh(['replacementRequest', 'shift.client', 'claimer']),
            $auth,
        );

        return redirect()->back()->with('success', 'Claim approved and shift assigned.');
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
    ): array
    {
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
            'date' => optional($position->shift?->starts_at)->toDateString(),
            'start_time' => optional($position->shift?->starts_at)->format('H:i'),
            'end_time' => optional($position->shift?->ends_at)->format('H:i'),
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
                'reason' => $canViewSensitiveDetails ? $position->replacementRequest->reason : null,
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

    protected function applyDateRangeFilter($query, string $dateRange): void
    {
        [$startsAt, $endsAt] = match ($dateRange) {
            'this_weekend' => $this->weekendRange(),
            'tonight' => [
                Carbon::now()->setTime(18, 0),
                Carbon::tomorrow()->setTime(6, 0),
            ],
            default => [now()->startOfDay(), now()->addDays(7)->endOfDay()],
        };

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
        // position on each load. Cache the derived list per organization for a
        // short TTL so the decode does not run on every request; the brief
        // staleness window avoids needing explicit cross-writer invalidation.
        return Cache::remember($this->availableSkillsCacheKey($viewer->organization_id), 60, function () use ($viewer) {
            return ShiftOpenPosition::query()
                ->when($viewer->organization_id, fn ($q) => $q->where('organization_id', $viewer->organization_id))
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

    protected function availableSkillsCacheKey(?int $organizationId): string
    {
        return sprintf('job_board:available_skills:org:%s', $organizationId ?? 'none');
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
                    blocking_reasons: ['This shift can no longer be claimed from the job board.'],
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
                blocking_reasons: ['This shift can no longer be claimed from the job board.'],
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
}
