<?php

declare(strict_types=1);

namespace App\Domain\Shifts\Handovers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEvent;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionRolledBack;

/** @internal Physical-root continuity only; not schema, authority or evidence certification. */
final class OperationsHandoverRoot
{
    public const RETAINED_MARKERS = 32;

    private static ?\WeakMap $live = null;
    private string $state = 'opening';
    private bool $checking = false;
    private bool $committing = false;
    private ?\Closure $commitVerifier = null;
    private readonly string $prefix;
    private array $markers = [];
    private int $serial = 0;
    private readonly OperationsHandoverRootEvents $events;

    private function __construct(private readonly Connection $connection, private readonly \PDO $pdo, private readonly Dispatcher $original)
    {
        $this->prefix = 'of_ops_ho_root_'.bin2hex(random_bytes(16));
        $this->events = new OperationsHandoverRootEvents($original, $this->observe(...), $this->afterObserve(...));
    }

    public static function open(Connection $connection): self
    {
        $pdo = $connection->getPdo();
        self::$live ??= new \WeakMap;
        if (isset(self::$live[$connection]) || $connection->transactionLevel() !== 0 || $pdo->inTransaction()
            || $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql' || $connection->getEventDispatcher() === null) {
            throw new \LogicException('A fresh supported handover root is required. No foreign transaction was changed.');
        }
        $root = new self($connection, $pdo, $connection->getEventDispatcher());
        self::$live[$connection] = $root;
        $connection->setEventDispatcher($root->events);
        try {
            // Installed beginTransaction increments level before dispatch. The
            // scoped interceptor stamps the root BEFORE ordinary begin listeners.
            $connection->beginTransaction();
            $root->assertCurrent(1);
            return $root;
        } catch (\Throwable $error) {
            // Only a still-proven root may be rolled back. An unknown replaced
            // root is never silently cleaned up or described as not_saved.
            try { $root->closeWithoutCommit(); } catch (\Throwable) { /* Ownership remains unknown; original error escapes. */ }
            throw $error;
        }
    }

    public function connection(): Connection { return $this->connection; }
    public function pdo(): \PDO { return $this->pdo; }

    public function assertCurrent(int $level): void
    {
        if ($this->checking || $this->state !== 'active' || ! $this->frame($level)) {
            $this->state = 'lost';
            throw new OperationsHandoverRootUnavailable('Handover root generation was lost.');
        }
        $this->checking = true;
        try {
            // All available markers predate the current nested savepoint.
            // RELEASE consumes one without rows/ROLLBACK TO. Never recreate a
            // root marker inside trans2: lawful rollback would delete it.
            // MySQL keeps savepoints as a stack: RELEASE also drops every
            // savepoint set after the named one. Consume the newest marker so
            // the older retained markers survive the check.
            if ($this->markers === []) {
                throw new OperationsHandoverRootUnavailable('Finite root continuity markers were exhausted.');
            }
            $marker = array_pop($this->markers);
            $this->statement('RELEASE SAVEPOINT '.$marker);
            if ($level === 1) { $this->refill(); }
            if (! $this->frame($level)) {
                throw new OperationsHandoverRootUnavailable('Handover root changed during continuity check.');
            }
        } catch (\Throwable $error) {
            $this->state = 'lost';
            throw new OperationsHandoverRootUnavailable('Handover root continuity is unavailable.', 0, $error);
        } finally {
            $this->checking = false;
        }
    }

    /** Commit only this retained physical root; no row/AFTER certificate is implied. */
    public function commit(?\Closure $verifyDomain = null): void
    {
        $this->assertCurrent(1); $this->committing = true; $this->commitVerifier = $verifyDomain;
        try {
            $this->connection->commit();
            if ($this->state !== 'committed' || $this->connection->getPdo() !== $this->pdo
                || $this->connection->transactionLevel() !== 0 || $this->pdo->inTransaction()) {
                throw new OperationsHandoverRootUnavailable('Handover commit or closure is unknown.');
            }
        } finally {
            $this->committing = false; $this->commitVerifier = null;
            if ($this->state === 'committed') { $this->release(); }
        }
    }

    /** Roll back only this still-proven root; never adopt a replacement transaction. */
    public function closeWithoutCommit(): void
    {
        try {
            if ($this->state !== 'expired') {
                $this->assertCurrent($this->connection->transactionLevel());
                $this->state = 'expired'; // Permanent BEFORE rollback callbacks.
                $this->connection->rollBack(0);
                if ($this->connection->getPdo() !== $this->pdo || $this->connection->transactionLevel() !== 0 || $this->pdo->inTransaction()) {
                    throw new \LogicException('Handover admission root closure is unknown.');
                }
            }
        } finally {
            $this->state = 'expired';
            if ($this->connection->getEventDispatcher() === $this->events) {
                $this->connection->setEventDispatcher($this->original);
            }
            if (isset(self::$live[$this->connection]) && self::$live[$this->connection] === $this) {
                unset(self::$live[$this->connection]);
            }
        }
    }

    private function frame(int $level): bool
    {
        return $level > 0 && self::$live[$this->connection] === $this && $this->connection->getPdo() === $this->pdo
            && $this->connection->getEventDispatcher() === $this->events
            && $this->connection->transactionLevel() === $level && $this->pdo->inTransaction();
    }

    private function observe(mixed $event): void
    {
        if (! $event instanceof ConnectionEvent || $event->connection !== $this->connection) { return; }
        if ($event instanceof TransactionBeginning && $this->state === 'opening') {
            if (! $this->frame(1)) {
                $this->state = 'lost';
                throw new OperationsHandoverRootUnavailable('The initial handover root is not owned.');
            }
            $this->refill();
            $this->state = 'active';
        } elseif ($event instanceof TransactionCommitting && $this->connection->transactionLevel() === 1) {
            if (!$this->committing) { $this->state = 'lost'; }
        } elseif (($event instanceof TransactionCommitted || $event instanceof TransactionRolledBack) && $this->connection->transactionLevel() === 0) {
            if ($event instanceof TransactionCommitted && $this->committing && $this->state === 'active') { $this->state = 'committed'; }
            elseif ($this->state !== 'expired') { $this->state = 'lost'; }
        } elseif ($event instanceof TransactionBeginning && $this->connection->transactionLevel() === 1) {
            $this->state = 'lost';
        }
    }

    private function afterObserve(mixed $event): void
    {
        if ($event instanceof TransactionCommitting && $event->connection === $this->connection && $this->committing) {
            // Ordinary committing listeners ran. Prove the original markers before the actual PDO commit.
            $this->assertCurrent(1);
            if ($this->commitVerifier !== null) { ($this->commitVerifier)(); $this->assertCurrent(1); }
        }
    }

    private function release(): void
    {
        $this->state = 'expired';
        if ($this->connection->getEventDispatcher() === $this->events) { $this->connection->setEventDispatcher($this->original); }
        if (isset(self::$live[$this->connection]) && self::$live[$this->connection] === $this) { unset(self::$live[$this->connection]); }
    }

    /** Only initial/proven root level1 may create continuity markers. */
    private function refill(): void
    {
        if (! $this->frame(1)) {
            throw new OperationsHandoverRootUnavailable('Root markers cannot be created inside a writer frame.');
        }
        while (count($this->markers) < self::RETAINED_MARKERS) {
            if ($this->serial === PHP_INT_MAX) {
                throw new OperationsHandoverRootUnavailable('Root marker generation was exhausted.');
            }
            $name = $this->prefix.'_'.($this->serial++);
            $this->statement('SAVEPOINT '.$name);
            $this->markers[] = $name;
        }
    }

    private function statement(string $sql): void
    {
        if ($this->pdo->exec($sql) === false) {
            throw new OperationsHandoverRootUnavailable('Handover root savepoint is unavailable.');
        }
    }

    public function __debugInfo(): array { return ['kind' => 'private_root_generation', 'state' => $this->state, 'source_proof' => false]; }
    public function __serialize(): array { throw new \LogicException('Root generation cannot escape its owner.'); }
}
