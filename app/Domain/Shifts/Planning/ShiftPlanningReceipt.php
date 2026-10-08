<?php

namespace App\Domain\Shifts\Planning;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Only confirms this request's physically committed single planning mutation. */
class ShiftPlanningReceipt
{
    public function begin(Request $request): bool
    {
        $request->session()->forget('shift_result');
        try {
            return $this->isPhysicalRoot();
        } catch (Throwable) {
            return false;
        }
    }

    protected function isPhysicalRoot(): bool
    {
        return DB::transactionLevel() === 0 && ! DB::connection()->getPdo()->inTransaction();
    }

    public function committed(bool $rootEntry, string $action, ShiftPlanningResult $result): ?array
    {
        if (! $rootEntry) {
            return null;
        }
        try {
            if (! $this->isPhysicalRoot() || ! in_array($action, ['create', 'update'], true) || $result->actorId <= 0) {
                return null;
            }
            if ($result->validationErrors !== []) {
                return null;
            }
            $shift = $result->shift;
            if ($action === 'create' && $result->rejectionReason !== null) {
                if ($shift->exists || $shift->id !== null || $result->source !== null || $result->changed
                    || ! in_array($result->rejectionReason, ['eligibility_warning', 'override_reason_required'], true)) {
                    return null;
                }

                return ['action' => 'create', 'actor_id' => $result->actorId, 'shift_id' => null,
                    'scope' => 'single', 'source' => null, 'outcome' => 'not_saved', 'changed' => false,
                    'reason' => $result->rejectionReason, 'values_hash' => ShiftPlanningIntent::hash($result->intent)];
            }
            if (! $shift->exists || ! $shift->id || ! in_array($shift->status, ['draft', 'scheduled'], true)) {
                return null;
            }

            if ($result->rejectionReason !== null) {
                if ($action !== 'update' || ! in_array($result->rejectionReason, ['eligibility_warning', 'override_reason_required'], true) || $result->changed || $result->source === null) {
                    return null;
                }

                return ['action' => 'update', 'actor_id' => $result->actorId, 'shift_id' => (int) $shift->id,
                    'scope' => 'single', 'source' => $result->source, 'outcome' => 'not_saved', 'changed' => false,
                    'reason' => $result->rejectionReason, 'values_hash' => ShiftPlanningIntent::hash($result->intent)];
            }

            $warnings = [];
            if ($action === 'create') {
                $warnings = array_key_exists('warning_reasons', $result->eligibility)
                    ? $result->eligibility['warning_reasons'] : ($result->eligibility === [] ? [] : null);
                if (! is_array($warnings) || ! array_is_list($warnings) || array_filter($warnings, static fn ($warning) => ! is_string($warning)) !== []) {
                    return null;
                }
            }

            $receipt = ['action' => $action, 'actor_id' => $result->actorId, 'shift_id' => (int) $shift->id,
                'scope' => 'single', 'source' => $result->source, 'client_id' => (int) $shift->client_id,
                'site_id' => (int) $shift->site_id, 'user_id' => $shift->user_id === null ? null : (int) $shift->user_id,
                'service_context_id' => $shift->service_context_id === null ? null : (int) $shift->service_context_id,
                'status' => $shift->status, 'changed' => $result->changed, 'outcome' => $result->changed ? 'saved' : 'unchanged',
                'starts_at' => ShiftPlanningIntent::instant($shift->starts_at), 'ends_at' => ShiftPlanningIntent::instant($shift->ends_at),
                'values_hash' => ShiftPlanningIntent::hash($result->intent)];
            if ($action === 'create') {
                $receipt['assignment_warnings'] = $warnings;
            }

            return $receipt;
        } catch (Throwable $exception) {
            try {
                Log::warning('Committed Shift planning result could not be presented', ['action' => $action, 'exception_class' => $exception::class]);
            } catch (Throwable) {
            }

            return null;
        }
    }
}
