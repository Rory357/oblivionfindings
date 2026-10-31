<?php

namespace App\Services\ControlRoom;

use App\Services\CurrentAuthorizationReads;
use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use LogicException;

/** Persistent full original-source proof on the supplied actual read query only. */
final class ControlRoomOriginalReadOuterQuery extends Builder
{
    private array $originalReadProofs = [];

    private bool $predicateOnly = false;

    private ?CurrentAuthorizationReads $originalReads = null;

    private mixed $originalConnection = null;

    private mixed $originalGrammar = null;

    private mixed $originalProcessor = null;

    public static function attach(EloquentBuilder $actual, callable $verify, CurrentAuthorizationReads $reads): void
    {
        $actual->setQuery(self::fromActual($actual->getQuery(), $verify, $reads));
    }

    public static function fromActual(Builder $actual, callable $verify, CurrentAuthorizationReads $reads): self
    {
        if ($actual instanceof self) {
            $actual->verifyOriginalRead();
            if ($actual->originalReads !== $reads) { throw new LogicException('Use the same original current-read evidence.'); }
        }
        elseif (get_class($actual) !== Builder::class) { throw new LogicException('Actual resolution outer query uses an unsupported builder.'); }
        $guarded = new self($actual->getConnection(), $actual->getGrammar(), $actual->getProcessor());
        // Retain the ACTUAL predicate/binding/aggregate/join/projection/callback state.
        // No independent query or reconstruction of its authorization is substituted.
        foreach (get_object_vars($actual) as $property => $value) {
            if (! in_array($property, ['originalReadProofs', 'predicateOnly', 'originalReads', 'originalConnection', 'originalGrammar', 'originalProcessor'], true)) {
                $guarded->{$property} = $value;
            }
        }
        $guarded->originalReadProofs = $actual instanceof self ? $actual->originalReadProofs : [];
        $guarded->originalReadProofs[] = Closure::fromCallable($verify);
        $guarded->originalReads = $reads;
        $guarded->originalConnection = $actual->getConnection();
        $guarded->originalGrammar = $actual->getGrammar();
        $guarded->originalProcessor = $actual->getProcessor();
        $guarded->verifyOriginalRead();

        return $guarded;
    }

    public function applyBeforeQueryCallbacks()
    {
        $this->verifyOriginalRead();
        $this->originalReads->query($this);
        parent::applyBeforeQueryCallbacks();
        $this->verifyOriginalRead();
        // Reapply to blocks added by existing callbacks, using the SAME reads.
        // The last tree annotation does no database reads or policy reconstruction.
        $this->originalReads->query($this);
        parent::applyBeforeQueryCallbacks();
    }

    public function applyAfterQueryCallbacks($result)
    {
        $this->verifyOriginalRead();
        $result = parent::applyAfterQueryCallbacks($result);
        $this->verifyOriginalRead();

        return $result;
    }

    public function exists()
    {
        $result = parent::exists();
        $this->verifyOriginalRead();

        return $result;
    }

    public function newQuery()
    {
        // Current caller pages have ungrouped outer queries. Grouped paginator
        // wrappers/unscoped forks require a separately explicit bounded contract.
        throw new LogicException('Retain or clone the actual bounded read query; do not fork an unscoped query.');
    }

    public function forNestedWhere()
    {
        $query = new self($this->originalConnection, $this->originalGrammar, $this->originalProcessor);
        $query->from = $this->from;
        $query->originalReadProofs = $this->originalReadProofs;
        $query->originalReads = $this->originalReads;
        $query->originalConnection = $this->originalConnection;
        $query->originalGrammar = $this->originalGrammar;
        $query->originalProcessor = $this->originalProcessor;
        $query->predicateOnly = true;

        return $query;
    }

    private function verifyOriginalRead(): void
    {
        if ($this->predicateOnly) { throw new LogicException('Nested read predicates cannot execute independently.'); }
        if ($this->originalReadProofs === [] || $this->originalReads === null || $this->getConnection() !== $this->originalConnection
            || $this->getGrammar() !== $this->originalGrammar || $this->getProcessor() !== $this->originalProcessor) {
            throw new LogicException('Actual original-read query changed its admitted execution identity.');
        }
        $this->originalReads->assertActive();
        foreach ($this->originalReadProofs as $proof) { $proof(); }
    }
}
