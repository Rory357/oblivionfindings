<?php

namespace App\Services\HealthSafety;

use App\Services\CurrentAuthorizationReads;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/** Callback-local final barrier for exact emitted source rows and scalar operands. */
final class HealthSafetyReadMaterialisationProof
{
    private static ?\WeakMap $active = null;
    private array $receipts = [];
    private bool $verifying = false;
    private \WeakMap $registered;

    public function __construct(private readonly HealthSafetyReadContext $context)
    {
        $context->assertActive();
        $this->registered = new \WeakMap;
    }

    public static function current(HealthSafetyReadContext $context): ?self
    {
        $context->assertActive();
        return (self::$active ??= new \WeakMap)[$context] ?? null;
    }

    /** Nested services contribute to the outermost final response barrier. */
    public static function within(HealthSafetyReadContext $context, callable $operation): mixed
    {
        $existing = self::current($context);
        if ($existing !== null) { return $operation($existing); }
        $proof = new self($context);
        self::$active[$context] = $proof;
        try {
            $result = $operation($proof);
            $proof->assertUnchanged();
            return $result;
        } finally {
            unset(self::$active[$context]);
        }
    }

    public function protect(Builder $query): Builder
    {
        $this->context->assertActive();
        $base = $query->getQuery();
        if (isset($this->registered[$base])) { return $query; }
        $this->registered[$base] = true;
        $base->beforeQuery(function (QueryBuilder $actual): void {
            if ($this->verifying) { return; }
            // Aggregate/get clones have their actual final columns here. A subquery
            // compiled without execution creates no receipt.
            $original = clone $actual;
            $actual->afterQuery(function ($rows) use ($original) {
                if (! $this->verifying) {
                    $this->receipts[] = ['query' => $original, 'facts' => $this->facts($rows)];
                }
                return $rows;
            });
        });
        return $query;
    }

    public function assertUnchanged(): void
    {
        $this->context->assertActive();
        $this->verifying = true;
        try {
            $this->context->consumeSourceViewer([], function ($actor, CurrentAuthorizationReads $reads): void {
                foreach ($this->receipts as $receipt) {
                    // Preserve original expressions, bindings and read locks. Never
                    // replay detached SQL or recapture a fresh source admission.
                    $query = clone $receipt['query'];
                    $rows = $reads->query($query)->get();
                    abort_unless($this->facts($rows) === $receipt['facts'], 404, 'Source evidence changed during report materialisation.');
                }
            });
        } finally {
            $this->verifying = false;
        }
    }

    private function facts(mixed $rows): array
    {
        $values = $rows instanceof Collection ? $rows->all() : (array) $rows;
        $facts = array_map(function ($row): string {
            $values = (array) $row;
            ksort($values);
            return serialize($values);
        }, $values);
        // SQL without a total ordering may legitimately reorder equal sets.
        sort($facts, SORT_STRING);
        return $facts;
    }
}
