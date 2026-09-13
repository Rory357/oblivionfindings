<?php

namespace App\Domain\It\Services;

use App\Domain\It\Data\ItAttachmentStorageReservation;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/** Carries file reservations to the transaction which owns the final commit. */
final class ItAttachmentWriteContext
{
    /** @var array<int, ItAttachmentStorageReservation> */
    private array $reservations = [];

    private ?Connection $connection = null;

    private bool $used = false;

    private bool $active = false;

    private int $level = 0;

    public function transaction(Closure $action): mixed
    {
        if ($this->used) {
            throw new LogicException('An attachment write context belongs to one transaction.');
        }
        $this->used = true;
        $connection = $this->connection = DB::connection();
        $pdo = $connection->getPdo();
        $this->level = $connection->transactionLevel();
        $prepared = false;
        $this->active = true;
        try {
            return $connection->transaction(function () use ($action, &$prepared): mixed {
                $result = $action($this);
                $prepared = true;

                return $result;
            });
        } catch (Throwable $failure) {
            // A completed callback may have committed despite an acknowledgement
            // error. Only a proven rollback on the original outer connection
            // authorizes cleanup; a surviving savepoint/unknown commit does not.
            try {
                if (! $prepared && $this->level === 0 && $connection->transactionLevel() === 0
                    && $connection->getPdo() === $pdo && ! $pdo->inTransaction()) {
                    app(ItAttachmentStorageIntentService::class)->requestRollbackCleanup(array_values($this->reservations));
                }
            } catch (Throwable) {
                // Preserve the original failure and durable intent on DB outage.
            }
            throw $failure;
        } finally {
            $this->active = false;
        }
    }

    public function assertActive(): void
    {
        if (! $this->active || DB::connection() !== $this->connection
            || $this->connection->transactionLevel() <= $this->level) {
            throw new LogicException('Attachment reservations require their active transaction owner.');
        }
    }

    /** @param array<int, ItAttachmentStorageReservation> $reservations */
    public function remember(array $reservations): void
    {
        $this->assertActive();
        foreach ($reservations as $reservation) {
            $this->reservations[$reservation->id] = $reservation;
        }
    }
}
