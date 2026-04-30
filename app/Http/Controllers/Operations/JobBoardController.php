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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class JobBoardController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($this->canViewJobBoard($auth), 403);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:open,claimed,filled,cancelled'],
            'scope' => ['nullable', 'string', 'in:mine'],
            'date_range' => ['nullable', 'string', 'in:next_7_days,this_weekend'],
            'skill' => ['nullable', 'string', 'max:100'],
        ]);

        $search = trim((string) ($filters['q'] ?? ''));
        $scope = $filters['scope'] ?? null;
        $skill = trim((string) ($filters['skill'] ?? ''));

        $positions = ShiftOpenPosition::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with([
                'shift:id,client_id,starts_at,ends_at,location,status,user_id',
                'shift.client:id,first_name,last_name,site_id,suburb,city',
                'shift.client.site:id,name,suburb,city',
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

        return inertia('operations/job-board/Index', [
            'jobs' => $positions->through(fn (ShiftOpenPosition $position) => $this->formatPositionForViewer($position, $auth)),
            'filters' => $filters,
            'available_skills' => $availableSkills,
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

        return redirect()->back()->with('success', 'Open position published.');
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

    protected function formatPositionForViewer(ShiftOpenPosition $position, User $viewer): array
    {
        $canViewSensitiveDetails = $this->canViewSensitivePositionDetails($position, $viewer);

        return [
            'id' => $position->id,
            'title' => $this->formatPositionTitle($position, $canViewSensitiveDetails),
            'status' => $position->status,
            'date' => optional($position->shift?->starts_at)->toDateString(),
            'start_time' => optional($position->shift?->starts_at)->format('H:i'),
            'end_time' => optional($position->shift?->ends_at)->format('H:i'),
            'location' => $this->formatPositionLocation($position, $canViewSensitiveDetails),
            'required_skills' => $position->required_skills ?? [],
            'coverage_roles' => $position->coverage_roles ?? [],
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
            'eligibility' => $this->getPositionEligibility($position),
            'viewer_eligibility' => $this->getViewerEligibility($position, $viewer),
        ];
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

    protected function canViewSensitivePositionDetails(ShiftOpenPosition $position, User $viewer): bool
    {
        if (
            $viewer->canDo('job_board.approve')
            || $viewer->canDo('shifts.manageAny')
            || $viewer->canDo('shifts.viewAny')
        ) {
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
    }

    /**
     * Lightweight eligibility check for a claimed position.
     * Only runs when a claimer exists to avoid unnecessary work.
     */
    protected function getPositionEligibility($position): ?array
    {
        if (! $position->claimer || ! $position->shift) {
            return null;
        }

        try {
            $result = app(ShiftStaffEligibilityService::class)
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

    protected function getViewerEligibility(ShiftOpenPosition $position, User $viewer): ?array
    {
        if (! $position->shift) {
            return null;
        }

        if ($position->status !== 'open') {
            return null;
        }

        if ((int) $position->shift->user_id === (int) $viewer->id) {
            return $this->formatEligibilityResult(new EligibilityResult(
                is_allowed: false,
                blocking_reasons: ['You are already assigned to this shift.'],
                warnings: [],
                checked_rules: [],
                overrideable_warnings: [],
            ));
        }

        if (in_array($position->shift->status, ['completed', 'cancelled'], true)) {
            return $this->formatEligibilityResult(new EligibilityResult(
                is_allowed: false,
                blocking_reasons: ['This shift can no longer be claimed from the job board.'],
                warnings: [],
                checked_rules: [],
                overrideable_warnings: [],
            ));
        }

        try {
            return $this->formatEligibilityResult(
                app(ShiftStaffEligibilityService::class)->evaluate($position->shift, $viewer)
            );
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
