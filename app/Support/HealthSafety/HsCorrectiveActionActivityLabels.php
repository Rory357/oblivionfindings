<?php

namespace App\Support\HealthSafety;

use App\Models\AuditLog;
use App\Models\HsCorrectiveAction;
use Illuminate\Support\Collection;

class HsCorrectiveActionActivityLabels
{
    /**
     * @param  Collection<int, AuditLog>  $logs
     * @return array<int, array{label: string, actor: string|null, occurred_at: string|null, detail: string|null}>
     */
    public function history(
        Collection $logs,
        ?int $ownerId,
    ): array {
        $returnedForRework = false;
        $history = [];

        foreach ($logs->sortBy('id') as $log) {
            $label = null;
            $detail = null;

            if ($log->action === 'hscorrectiveaction.create') {
                $label = 'Action created';
            } elseif ($log->action === 'hscorrectiveaction.update') {
                $before = data_get($log->meta, 'before.status');
                $after = data_get($log->meta, 'after.status');

                if ($before === HsCorrectiveAction::STATUS_OPEN
                    && $after === HsCorrectiveAction::STATUS_IN_PROGRESS) {
                    $label = $this->isOwnerActor($log, $ownerId)
                        ? 'Owner started action'
                        : 'Action started';
                } elseif ($before === HsCorrectiveAction::STATUS_IN_PROGRESS
                    && $after === HsCorrectiveAction::STATUS_COMPLETED) {
                    $ownerActor = $this->isOwnerActor($log, $ownerId);
                    $label = match (true) {
                        $returnedForRework && $ownerActor => 'Owner resubmitted evidence',
                        $returnedForRework => 'Evidence resubmitted',
                        $ownerActor => 'Owner submitted evidence',
                        default => 'Evidence submitted',
                    };
                } elseif ($before === HsCorrectiveAction::STATUS_COMPLETED
                    && $after === HsCorrectiveAction::STATUS_IN_PROGRESS) {
                    $label = 'Action returned for rework';
                    $detail = $this->stringOrNull(
                        data_get($log->meta, 'after.verification_notes'),
                    );
                    $returnedForRework = true;
                } elseif ($before === HsCorrectiveAction::STATUS_COMPLETED
                    && $after === HsCorrectiveAction::STATUS_VERIFIED) {
                    $label = 'Action independently verified';
                } elseif ($before === HsCorrectiveAction::STATUS_VERIFIED
                    && $after === HsCorrectiveAction::STATUS_CLOSED) {
                    $label = 'Action closed';
                }
            }

            if ($label === null) {
                continue;
            }

            $history[] = [
                'label' => $label,
                'actor' => $log->user?->name,
                'occurred_at' => $log->created_at?->toIso8601String(),
                'detail' => $detail,
            ];
        }

        return array_reverse($history);
    }

    /**
     * @param  Collection<int, AuditLog>  $logs
     */
    public function latestReturnReason(Collection $logs): ?string
    {
        foreach ($logs->sortByDesc('id') as $log) {
            if ($log->action !== 'hscorrectiveaction.update'
                || data_get($log->meta, 'before.status') !== HsCorrectiveAction::STATUS_COMPLETED
                || data_get($log->meta, 'after.status') !== HsCorrectiveAction::STATUS_IN_PROGRESS) {
                continue;
            }

            return $this->stringOrNull(
                data_get($log->meta, 'after.verification_notes'),
            );
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && filled($value) ? $value : null;
    }

    private function isOwnerActor(AuditLog $log, ?int $ownerId): bool
    {
        return $ownerId !== null && (int) $log->user_id === $ownerId;
    }
}
