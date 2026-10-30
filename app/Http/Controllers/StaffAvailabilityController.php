<?php

namespace App\Http\Controllers;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\StaffAvailability;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\Operations\WorkforceMutationGuard;
use App\Services\UserSiteAccessService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class StaffAvailabilityController extends Controller
{
    public function __construct(private UserSiteAccessService $sites) {}

    public function workspace(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor, 403);
        $data = $request->validate(['staff_id' => ['nullable', 'integer', 'exists:users,id'], 'week' => ['nullable', 'date']]);
        if ($actor->canDo('rostering.viewAny')) {
            return redirect()->route('operations.rostering.index', array_filter([
                'tab' => 'availability', 'staff_id' => $data['staff_id'] ?? null, 'week' => $data['week'] ?? null,
            ], fn ($value) => $value !== null));
        }

        return $this->index($request, User::query()->findOrFail($data['staff_id'] ?? $actor->id));
    }

    public function index(Request $request, User $user)
    {
        $actor = $request->user();
        $this->authorizeSubject($actor, $user, false);
        $data = $request->validate(['week' => ['nullable', 'date']]);
        if ($actor->canDo('rostering.viewAny')) {
            return redirect()->route('operations.rostering.index', array_filter([
                'tab' => 'availability', 'staff_id' => $user->id, 'week' => $data['week'] ?? null,
            ], fn ($value) => $value !== null));
        }

        return inertia('staff/availability', [
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'availability' => $user->staffAvailability()->orderBy('day_of_week')->orderBy('starts_at')
                ->get(['id', 'day_of_week', 'starts_at', 'ends_at', 'ends_next_day'])
                ->map(fn (StaffAvailability $slot) => [
                    'id' => (int) $slot->id, 'day_of_week' => (int) $slot->day_of_week,
                    'starts_at' => substr((string) $slot->starts_at, 0, 5),
                    'ends_at' => substr((string) $slot->ends_at, 0, 5),
                    'ends_next_day' => (bool) $slot->ends_next_day,
                ]),
            'canManage' => $this->canManage($actor, $user),
            'workerTimezone' => (string) config('app.worker_timezone', 'Pacific/Auckland'),
            'week' => $data['week'] ?? null,
            'staffOptions' => $this->canViewAny($actor)
                ? $this->sites->applyStaffScope(User::query(), $actor, ['reports.viewAny'])
                    ->orderBy('name')->get(['id', 'name', 'email'])
                : [],
        ]);
    }

    public function store(Request $request, User $user)
    {
        $request->session()->forget('staff_availability_result');
        $actor = $request->user();
        $this->authorizeSubject($actor, $user, true);
        $data = $request->validate([
            'day_of_week' => ['required', 'integer', 'min:0', 'max:6'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i'],
            'ends_next_day' => ['sometimes', 'boolean'],
        ]);
        $nextDay = (bool) ($data['ends_next_day'] ?? false);
        if ((! $nextDay && $data['ends_at'] <= $data['starts_at']) || ($nextDay && $data['ends_at'] > $data['starts_at'])) {
            throw ValidationException::withMessages([
                'ends_at' => $nextDay ? 'Next-day availability cannot exceed 24 hours.' : 'The end must be after the start, or select ends next day.',
            ]);
        }
        $rootEntry = $this->isPhysicalRoot();
        $availability = $this->command(function () use ($actor, $user, $data, $nextDay): StaffAvailability {
            [$actor, $user] = $this->lockSubjects($actor, $user);
            CurrentAuthorizationReads::within(fn (CurrentAuthorizationReads $reads) => $this->authorizeSubject($actor, $user, true, $reads));

            return $user->staffAvailability()->create([
                'day_of_week' => (int) $data['day_of_week'], 'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'], 'ends_next_day' => $nextDay,
            ]);
        });

        return $this->committedResult(back()->with('success', 'Availability added.'), $rootEntry, 'create', $availability);
    }

    public function destroy(Request $request, User $user, StaffAvailability $availability)
    {
        $request->session()->forget('staff_availability_result');
        $actor = $request->user();
        $this->authorizeSubject($actor, $user, true);
        abort_unless((int) $availability->user_id === (int) $user->id, 404);
        $rootEntry = $this->isPhysicalRoot();
        $deleted = $this->command(function () use ($actor, $user, $availability): StaffAvailability {
            [$actor, $user] = $this->lockSubjects($actor, $user);
            CurrentAuthorizationReads::within(fn (CurrentAuthorizationReads $reads) => $this->authorizeSubject($actor, $user, true, $reads));
            $availability = StaffAvailability::query()->lockForUpdate()->findOrFail($availability->id);
            abort_unless((int) $availability->user_id === (int) $user->id, 404);
            $availability->delete();

            return $availability;
        });

        return $this->committedResult(back()->with('success', 'Availability removed.'), $rootEntry, 'delete', $deleted);
    }

    private function canViewAny(User $actor): bool
    {
        return $actor->canDo('staff.viewAny') || $actor->canDo('staff.availability.updateAny');
    }

    private function canManage(User $actor, User $subject): bool
    {
        return $actor->canDo('staff.availability.updateAny')
            || ((int) $actor->id === (int) $subject->id && $actor->canDo('staff.availability.updateSelf'));
    }

    private function authorizeSubject(?User $actor, User $subject, bool $write, ?CurrentAuthorizationReads $reads = null): void
    {
        abort_unless($actor && $actor->isApproved() && ($write ? $this->canManage($actor, $subject)
            : $this->canViewAny($actor) || ((int) $actor->id === (int) $subject->id && $actor->canDo('staff.availability.updateSelf'))), 403);
        if (! $reads) {
            $query = $this->sites->applyStaffScope(User::query(), $actor, ['reports.viewAny']);
        } else {
            // Same canonical staff/profile predicate as applyStaffScope, with
            // each source read current after the command's User/Profile waits.
            $query = app(HrCurrentStaffService::class)->currentUsersQuery();
            if (! $actor->canDo('reports.viewAny')) {
                $siteIds = $this->sites->accessibleSiteIds($actor, ['reports.viewAny'], $reads);
                $query->whereHas('hrEmployeeProfile', function ($profile) use ($siteIds): void {
                    $profile->where(function ($sites) use ($siteIds): void {
                        $sites->whereIn('primary_site_id', $siteIds);
                        foreach ($siteIds as $siteId) {
                            $sites->orWhereJsonContains('secondary_site_ids', $siteId);
                        }
                    });
                });
            }
            $query = $reads->query($query);
        }
        abort_unless($query->whereKey($subject->id)->exists(), 403, 'You are not authorized to access this staff member’s availability.');
    }

    private function lockSubjects(User $actor, User $subject): array
    {
        app(WorkforceMutationGuard::class)->lock();
        $ids = collect([$actor->id, $subject->id])->unique()->sort()->values()->all();
        $users = app(AuthorizationEvidenceLockService::class)->lockForUsers($ids, [
            'staff.viewAny', 'staff.availability.updateAny', 'staff.availability.updateSelf', 'reports.viewAny',
        ]);
        $profiles = HrEmployeeProfile::query()->whereIn('user_id', $ids)->orderBy('user_id')->lockForUpdate()->get()->keyBy('user_id');
        foreach ($users as $user) {
            $user->setRelation('hrEmployeeProfile', $profiles->get($user->id));
        }

        return [$users->get($actor->id), $users->get($subject->id)];
    }

    private function command(callable $command): StaffAvailability
    {
        try {
            return DB::transaction($command);
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 3572) {
                throw $exception;
            }

            throw ValidationException::withMessages(['availability' => 'Availability access is being updated. Please try again.']);
        }
    }

    private function isPhysicalRoot(): bool
    {
        return DB::connection()->transactionLevel() === 0 && ! DB::connection()->getPdo()->inTransaction();
    }

    private function committedResult(RedirectResponse $response, bool $rootEntry, string $action, StaffAvailability $availability): RedirectResponse
    {
        if (! $rootEntry) {
            return $response;
        }
        try {
            if (! $this->isPhysicalRoot() || (int) $availability->id <= 0
                || ($action === 'create' && ! $availability->exists)
                || ($action === 'delete' && $availability->exists)) {
                return $response;
            }

            return $response->with('staff_availability_result', [
                'action' => $action, 'staff_id' => (int) $availability->user_id,
                'availability_id' => (int) $availability->id, 'day_of_week' => (int) $availability->day_of_week,
                'starts_at' => substr((string) $availability->starts_at, 0, 5),
                'ends_at' => substr((string) $availability->ends_at, 0, 5),
                'ends_next_day' => (bool) $availability->ends_next_day,
            ]);
        } catch (Throwable $exception) {
            try {
                Log::warning('Committed availability result could not be presented', [
                    'action' => $action, 'availability_id' => (int) $availability->id, 'exception_class' => $exception::class,
                ]);
            } catch (Throwable) {
                // A receipt failure cannot reverse the committed command.
            }

            return $response;
        }
    }
}
