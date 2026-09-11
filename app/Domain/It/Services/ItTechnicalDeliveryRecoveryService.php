<?php

namespace App\Domain\It\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** A reviewed retry grants one attempt on the canonical delivery, never a fresh intake. */
final class ItTechnicalDeliveryRecoveryService
{
    public function __construct(private readonly ItTechnicalDeliveryOperationsPresenter $operations) {}

    public function review(User $actor, int $originalActorId, string $source, int $id): array
    {
        $actor = $this->actor($actor, $originalActorId);
        $row = $this->operations->authorizedRecord($actor, $source, $id);

        return $this->result($actor, $source, $row);
    }

    public function retry(User $actor, int $originalActorId, string $source, int $id, string $version): array
    {
        // Authorize before dispatch selection; unknown sources never reach a service.
        $this->review($actor, $originalActorId, $source, $id);
        $service = $source === 'device' ? app(ItMonitoringDeliveryService::class) : app(ItFleetDeliveryService::class);
        $service->retry($id, function (Model $locked) use ($actor, $originalActorId, $source, $id, $version): void {
            $currentActor = $this->actor($actor, $originalActorId, true);
            $this->operations->authorizedRecord($currentActor, $source, $id);
            abort_unless(hash_equals($this->version($locked), $version), 409, 'Delivery changed. Review its current outcome before requesting another retry.');
            abort_unless($this->retryable($locked), 409, 'This delivery does not currently permit a retry.');
        });

        // A committed allowance is not proof of delivery. Queue dispatch may have
        // failed, or a synchronous worker may already have recorded an outcome.
        return ['retry_requested' => true, ...$this->review($actor, $originalActorId, $source, $id)];
    }

    private function actor(User $actor, int $originalActorId, bool $lock = false): User
    {
        abort_unless((int) $actor->id === $originalActorId, 403);
        $current = User::query()->whereKey($actor->id)->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
        abort_unless($current->approved_at !== null, 403);

        return $current;
    }

    private function result(User $actor, string $source, Model $row): array
    {
        return [
            ...$this->operations->describe($row),
            'viewer_user_id' => (int) $actor->id, 'source' => $source, 'id' => (int) $row->id,
            'version' => $this->version($row), 'can_retry' => $this->retryable($row),
        ];
    }

    private function retryable(Model $row): bool
    {
        return $row->status === 'sent' && in_array($row->it_status, ['failed', 'dead_letter', 'unroutable'], true);
    }

    private function version(Model $row): string
    {
        return hash('sha256', json_encode([
            $row->getTable(), (int) $row->id, $row->status, $row->it_status,
            $row->it_attempts, $row->it_attempt_limit, $row->it_scope,
            $row->it_signal_id, $row->it_outcome_code, $row->it_ticket_ids,
            $row->it_last_attempt_at?->toIso8601String(), $row->it_completed_at?->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
    }
}
