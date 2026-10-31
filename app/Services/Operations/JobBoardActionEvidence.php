<?php

namespace App\Services\Operations;

use App\Models\CoverageReservation;
use App\Models\TimelineEvent;
use App\Services\Timeline\TimelineEmitter;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PDO;

/** Command-local intended-row proof, never a durable replay ledger. */
final class JobBoardActionEvidence
{
    private array $proofs = [];

    public function __construct(private readonly Connection $connection, private readonly PDO $pdo) {}

    public function remember(Model $model, ?array $fields = null): void
    {
        $fields ??= ['id', ...$model->getFillable()];
        $this->proofs[$model::class.':'.$model->getKey()] = [$model::class, $model->getKey(), $fields, JobBoardActionSource::values($model, $fields)];
    }

    public function update(Model $model, array $values, ?array $fields = null): bool
    {
        $this->inside();
        $identity = $model->getKey();
        $fields ??= ['id', ...$model->getFillable()];
        $intent = clone $model;
        $intent->forceFill($values);
        $intent->setRawAttributes($intent->getAttributes()); // Storage date precision, before any observer.
        $expected = JobBoardActionSource::values($intent, $fields);
        $model->forceFill($values);
        abort_unless($model->save() === true && $model->getKey() === $identity, 409, 'The requested change was not saved.');
        $stored = $model->newQuery()->whereKey($identity)->lockForUpdate()->first();
        abort_unless($stored && JobBoardActionSource::values($stored, $fields) === $expected,
            409, 'The saved change did not match the requested action.');
        $model->setRawAttributes($stored->getAttributes(), true);
        // Retain the pre-observer intent, never learn a changed row as the new proof.
        $this->proofs[$model::class.':'.$identity] = [$model::class, $identity, $fields, $expected];

        return true;
    }

    public function hold(array $values): CoverageReservation
    {
        return $this->create(new CoverageReservation($values), array_merge(['id'], (new CoverageReservation)->getFillable()));
    }

    public function timeline(array $values): TimelineEvent
    {
        $this->inside();
        $intent = new TimelineEvent($values);
        $intent->setRawAttributes($intent->getAttributes());
        $fields = array_keys($values);
        $expected = JobBoardActionSource::values($intent, $fields);
        $event = app(TimelineEmitter::class)->record($values);
        $stored = $event->id ? TimelineEvent::query()->whereKey($event->id)->lockForUpdate()->first() : null;
        abort_unless($stored && JobBoardActionSource::values($stored, $fields) === $expected,
            409, 'Replacement history was not saved as requested.');
        $this->proofs[TimelineEvent::class.':'.$event->id] = [TimelineEvent::class, $event->id, $fields, $expected];

        return $stored;
    }

    private function create(Model $model, array $fields): Model
    {
        $this->inside();
        $intent = clone $model;
        $intent->setRawAttributes($intent->getAttributes());
        $nonIdentity = array_values(array_diff($fields, ['id']));
        $expected = JobBoardActionSource::values($intent, $nonIdentity);
        abort_unless($model->save() === true && $model->exists && $model->getKey(), 409, 'The coverage hold was not saved.');
        $stored = $model->newQuery()->whereKey($model->getKey())->lockForUpdate()->first();
        abort_unless($stored && JobBoardActionSource::values($stored, $nonIdentity) === $expected,
            409, 'The saved coverage hold did not match its intent.');
        $model->setRawAttributes($stored->getAttributes(), true);
        $this->remember($model, $fields);

        return $model;
    }

    public function finish(): void
    {
        $this->inside();
        abort_unless($this->matches(), 409, 'A governing row changed before the action completed.');
    }

    /** Called in a new bounded read transaction after an actual root commit. */
    public function matches(): bool
    {
        $this->inside();
        foreach ($this->proofs as [$class, $id, $fields, $expected]) {
            $stored = $class::query()->whereKey($id)->lockForUpdate()->first();
            if (! $stored || JobBoardActionSource::values($stored, $fields) !== $expected) {
                return false;
            }
        }

        return true;
    }

    private function inside(): void
    {
        if (DB::connection() !== $this->connection || $this->connection->getPdo() !== $this->pdo
            || $this->connection->transactionLevel() < 1 || ! $this->pdo->inTransaction()) {
            throw new \LogicException('Job Board action proof left its governing transaction.');
        }
    }
}
