<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\WorkforceEligibilityRecheck;
use App\Services\Eligibility\WorkforceEligibilityRefresh;
use App\Services\Eligibility\WorkforceEligibilityRefreshAccess;
use App\Services\Eligibility\WorkforceEligibilityRefreshPresenter;
use App\Services\Operations\WorkforceMutationGuard;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class WorkforceEligibilityRefreshController extends Controller
{
    public function index(Request $request, WorkforceEligibilityRefreshAccess $access, WorkforceEligibilityRefreshPresenter $presenter): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor?->isApproved() && collect(['rostering.viewAny', 'shifts.viewAny', 'shifts.viewAssigned'])
            ->contains(fn (string $permission): bool => $actor->canDo($permission)), 403);
        $data = $request->validate([
            'shift_ids' => ['sometimes', 'array', 'max:200'], 'shift_ids.*' => ['integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = $access->shifts(app(WorkforceEligibilityRefresh::class)->duties(), $actor)->with('client:id,site_id');
        if (array_key_exists('shift_ids', $data)) {
            $query->whereIn('shifts.id', $data['shift_ids']);
        }
        $page = $query->orderBy('shifts.id')->paginate(50);
        $page->through(fn (Shift $shift): array => $presenter->present($shift, $actor));

        return response()->json($page);
    }

    public function retry(Request $request, Shift $shift, WorkforceEligibilityRefreshAccess $access, WorkforceMutationGuard $guard, WorkforceEligibilityRefresh $refresh): JsonResponse
    {
        $rootEntry = $this->isPhysicalRoot();
        try {
            DB::transaction(function () use ($request, $shift, $access, $guard, $refresh): void {
                $guard->lock();
                $actor = $guard->actor($request->user(), 'shifts.update', $shift->user_id ? [(int) $shift->user_id] : []);
                $current = $refresh->duties()->employeeDuties()->whereKey($shift->id)->lockForUpdate()->first();
                abort_unless($current, 403);
                // The route-bound identity selected our participant locks.
                // Never retarget a request to another owner or source after waiting.
                foreach (['user_id', 'client_id', 'site_id'] as $field) {
                    abort_unless($current->getAttribute($field) === $shift->getAttribute($field), 403);
                }
                $access->assertCurrentRetryAccess($current, $actor);
                $intent = $refresh->requestShift($current);
                $staged = WorkforceEligibilityRecheck::query()->whereKey($intent->id)->lockForUpdate()->first();
                if (! $staged || $staged->source_type !== 'manual_shift'
                    || (int) $staged->source_id !== (int) $current->id
                    || (int) $staged->source_version < 1
                    || (int) $staged->source_version !== (int) $intent->source_version
                    || $staged->source_fingerprint !== $intent->source_fingerprint
                    || ! in_array(''.$current->id, array_map('strval', $staged->shift_ids ?? []), true)
                    || ! in_array($staged->status, ['pending', 'processing'], true)) {
                    throw ValidationException::withMessages([
                        'eligibility_refresh' => 'The recheck request could not be confirmed. Refresh the duty status before trying again.',
                    ]);
                }
            }, 3);
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 3572) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'eligibility_refresh' => 'Duty access is being updated. Refresh the duty status before trying again.',
            ]);
        }

        return $this->confirmedResponse($rootEntry, (int) $shift->id);
    }

    private function isPhysicalRoot(): bool
    {
        return DB::connection()->transactionLevel() === 0 && ! DB::connection()->getPdo()->inTransaction();
    }

    private function confirmedResponse(bool $rootEntry, int $shiftId): JsonResponse
    {
        $unknown = ['shift_id' => $shiftId, 'queued' => null, 'status' => 'unconfirmed'];
        if (! $rootEntry) {
            return response()->json($unknown, 409);
        }
        try {
            if (! $this->isPhysicalRoot()) {
                return response()->json($unknown, 409);
            }

            // This acknowledges committed intent, not broker delivery or a result.
            return response()->json(['shift_id' => $shiftId, 'queued' => true], 202);
        } catch (Throwable $exception) {
            try {
                Log::warning('Committed duty recheck confirmation is unavailable', [
                    'shift_id' => $shiftId, 'exception_class' => $exception::class,
                ]);
            } catch (Throwable) {
                // Receipt metadata cannot reverse a committed request.
            }

            return response()->json($unknown, 503);
        }
    }
}
