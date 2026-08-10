<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\SecurityDevices\Management\Enums\CommandAttemptStatus;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandAttempt;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class CollectorCommandRecoveryService
{
    public function __construct(private readonly DeviceCommandAuditService $audit) {}

    /** @return array{expired_before_delivery: int, uncertain_after_delivery: int} */
    public function recover(?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now('UTC')->startOfSecond();
        $counts = ['expired_before_delivery' => 0, 'uncertain_after_delivery' => 0];
        $ids = DeviceCommandAttempt::query()
            ->where('runtime', 'collector')
            ->whereIn('status', [
                CommandAttemptStatus::Dispatching->value,
                CommandAttemptStatus::Accepted->value,
                CommandAttemptStatus::Running->value,
            ])
            ->whereHas('request', fn ($query) => $query
                ->where('expires_at', '<=', $at)
                ->whereIn('status', [
                    CommandStatus::Dispatching->value,
                    CommandStatus::Accepted->value,
                    CommandStatus::Running->value,
                ]))
            ->orderBy('id')
            ->limit(1000)
            ->pluck('id');

        foreach ($ids as $id) {
            $outcome = DB::transaction(function () use ($id, $at): ?string {
                $attempt = DeviceCommandAttempt::query()->lockForUpdate()->find($id);
                if (! $attempt || $attempt->status->isTerminal()) {
                    return null;
                }
                $request = DeviceCommandRequest::query()->lockForUpdate()->find($attempt->device_command_request_id);
                if (! $request
                    || $request->expires_at->greaterThan($at)
                    || ! in_array($request->status, [
                        CommandStatus::Dispatching,
                        CommandStatus::Accepted,
                        CommandStatus::Running,
                    ], true)) {
                    return null;
                }

                $issued = is_string($attempt->provider_request_reference)
                    && str_starts_with($attempt->provider_request_reference, 'collector:');
                $attempt->status = $issued
                    ? CommandAttemptStatus::Uncertain
                    : CommandAttemptStatus::Expired;
                $attempt->safe_failure_reason = $issued
                    ? 'A signed collector configuration was issued, but no final ordered result arrived before the command expired. The action was not repeated.'
                    : 'The command expired before any collector configuration was issued.';
                $attempt->completed_at = $at;
                $attempt->save();

                $request->status = $issued ? CommandStatus::Uncertain : CommandStatus::Expired;
                $request->safe_failure_reason = $attempt->safe_failure_reason;
                $request->execution_completed_at = $at;
                $request->save();
                $this->audit->append(
                    $request,
                    null,
                    $issued ? 'collector_result_timeout_uncertain' : 'collector_delivery_expired',
                    [
                        'attempt_number' => (int) $attempt->attempt_number,
                        'status' => $request->status->value,
                        'configuration_issued' => $issued,
                    ],
                );

                return $issued ? 'uncertain_after_delivery' : 'expired_before_delivery';
            }, 3);
            if ($outcome !== null) {
                $counts[$outcome]++;
            }
        }

        return $counts;
    }
}
