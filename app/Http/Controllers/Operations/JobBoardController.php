<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\ShiftOpenPosition;
use App\Models\User;
use App\Services\CoverageReservationService;
use App\Services\ShiftReplacementService;
use App\Services\ShiftStaffEligibilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class JobBoardController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($this->canViewJobBoard($auth), 403);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:open,claimed,filled,cancelled'],
        ]);

        $search = trim((string) ($filters['q'] ?? ''));

        $positions = ShiftOpenPosition::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with([
                'shift:id,client_id,starts_at,ends_at,location,status,user_id',
                'shift.client:id,first_name,last_name,site_id',
                'shift.client.site:id,name',
                'claimer:id,name',
                'approver:id,name',
                'replacementRequest:id,shift_id,requested_by,current_staff_id,replacement_user_id,status,reason,requested_at,claimed_at,approved_at,cancelled_at',
                'replacementRequest.requester:id,name',
                'replacementRequest.currentStaff:id,name',
                'replacementRequest.replacementStaff:id,name',
            ])
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
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $statsQuery = ShiftOpenPosition::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id));

        return inertia('operations/job-board/Index', [
            'jobs' => $positions->through(fn (ShiftOpenPosition $position) => [
                'id' => $position->id,
                'title' => $this->formatPositionTitle($position),
                'status' => $position->status,
                'date' => optional($position->shift?->starts_at)->toDateString(),
                'start_time' => optional($position->shift?->starts_at)->format('H:i'),
                'end_time' => optional($position->shift?->ends_at)->format('H:i'),
                'location' => $position->shift?->location ?? $position->shift?->client?->site?->name,
                'required_skills' => $position->required_skills ?? [],
                'coverage_roles' => $position->coverage_roles ?? [],
                'client' => $position->shift?->client ? [
                    'id' => $position->shift->client->id,
                    'first_name' => $position->shift->client->first_name,
                    'last_name' => $position->shift->client->last_name,
                ] : null,
                'replacement' => $position->replacementRequest ? [
                    'id' => $position->replacementRequest->id,
                    'status' => $position->replacementRequest->status,
                    'reason' => $position->replacementRequest->reason,
                    'requested_at' => optional($position->replacementRequest->requested_at)->toISOString(),
                    'current_staff' => $position->replacementRequest->currentStaff
                        ? ['id' => $position->replacementRequest->currentStaff->id, 'name' => $position->replacementRequest->currentStaff->name]
                        : null,
                    'requested_by' => $position->replacementRequest->requester
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
            ]),
            'filters' => $filters,
            'stats' => [
                'open' => (clone $statsQuery)->where('status', 'open')->count(),
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

        $shift = Shift::query()->findOrFail($data['shift_id']);

        $existingActivePosition = ShiftOpenPosition::query()
            ->where('shift_id', $shift->id)
            ->whereIn('status', ['open', 'claimed'])
            ->exists();

        if ($existingActivePosition) {
            return redirect()->back()->withErrors([
                'shift_id' => 'This shift already has an active open position.',
            ]);
        }

        ShiftOpenPosition::create([
            'organization_id' => $auth->organization_id,
            'shift_id' => $shift->id,
            'replacement_request_id' => app(ShiftReplacementService::class)->activeForShift($shift)?->id,
            'status' => 'open',
            'required_skills' => array_values($data['required_skills'] ?? []),
            'coverage_roles' => $shift->coverage_roles ?? [],
            'notes' => $data['notes'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        return redirect()->back()->with('success', 'Open position published.');
    }

    public function claim(Request $request, $position)
    {
        $auth = $request->user();
        abort_unless($this->canClaimPositions($auth), 403);

        $position = ShiftOpenPosition::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with(['shift', 'replacementRequest', 'claimer'])
            ->where('status', 'open')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->findOrFail($position);

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
        if (! $eligibility['is_eligible']) {
            return redirect()->back()->withErrors([
                'claim' => $eligibility['blocked_reasons'][0] ?? 'You cannot claim this shift.',
            ]);
        }

        if (! empty($eligibility['warning_reasons'])) {
            session()->flash('job_board_claim_warnings', $eligibility['warning_reasons']);
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
            if (! $eligibility['is_eligible']) {
                return redirect()->back()->withErrors([
                    'position' => $eligibility['blocked_reasons'][0] ?? 'Cannot approve this claim for the selected worker.',
                ])->with('compliance_warnings', $eligibility['compliance_warnings'] ?? []);
            }

            if (! empty($eligibility['warning_reasons'])) {
                session()->flash('compliance_warnings', $eligibility['warning_reasons']);
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

    protected function formatPositionTitle(ShiftOpenPosition $position): string
    {
        $clientName = trim((string) ($position->shift?->client?->first_name.' '.$position->shift?->client?->last_name));

        if ($clientName !== '') {
            return 'Support shift for '.$clientName;
        }

        if ($position->shift?->location) {
            return 'Open shift at '.$position->shift->location;
        }

        return 'Open support shift';
    }
}
