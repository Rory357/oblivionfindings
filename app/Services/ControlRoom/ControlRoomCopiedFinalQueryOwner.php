<?php

namespace App\Services\ControlRoom;

use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Support\Incidents\LinkedOperationalEvidencePresenter;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/** Each exact CR consumer has its own private tickets. This trait supplies no public query factory. */
trait ControlRoomCopiedFinalQueryOwner
{
    private static ?\WeakMap $copiedFinalQueryTickets = null;

    /** Apply scopes and fixed projection before any actual-query owner attaches its canonical guards. */
    private static function prepareRegisteredCopiedFinal(Builder $plain, string $mode): Builder
    {
        if (! in_array($mode, ['exists', 'first_row'], true)) { throw new LogicException('Unsupported canonical final execution mode.'); }
        $actual = $plain->applyScopes()->withoutGlobalScopes()->setEagerLoads([]);
        if ($mode === 'exists') { $actual->select([])->selectRaw('1'); }
        else {
            if ($actual->getQuery()->columns === null) { throw new LogicException('Canonical body selection must be fixed before registration.'); }
            $actual->limit(1);
        }
        return $actual;
    }

    /** Source decorator lookup only. A ticket cannot be created, imported or rebound through this API. */
    public static function registeredCopiedNoteFinalQuery(object $ticket, User $viewer,
        CurrentAuthorizationReads $reads, HealthSafetyReadContext $context): Builder
    {
        $entry = self::$copiedFinalQueryTickets[$ticket] ?? null;
        if ($entry === null) { throw new LogicException('Use an active canonical final-query ticket.'); }
        self::assertRegisteredCopiedNoteFinalQueryIdentity($ticket, $viewer, $reads, $context, $entry->actual);
        self::assertRegisteredCopiedFinalShape($entry, $reads);
        return $entry->actual;
    }

    /** Source hook attestation may check liveness without recursing through shape validation. */
    public static function assertRegisteredCopiedNoteFinalQueryIdentity(object $ticket, User $viewer,
        CurrentAuthorizationReads $reads, HealthSafetyReadContext $context, Builder $actual): void
    {
        $context->assertViewer($viewer); $reads->assertActive();
        $entry = self::$copiedFinalQueryTickets[$ticket] ?? null;
        if ($entry === null || ! $entry->active || $entry->owner !== self::class
            || $entry->tuple !== [$viewer, $reads, $context] || $entry->actual !== $actual) {
            throw new LogicException('Use the original active query ticket from its exact canonical execution owner.');
        }
        $context->consumeSourceViewer([], static function (User $same, CurrentAuthorizationReads $sameReads) use ($viewer, $reads): void {
            if ($same !== $viewer || $sameReads !== $reads) { throw new LogicException('Use the original final-query context and reads.'); }
        });
        if ($actual->getModel() !== $entry->model || $actual->getConnection() !== $entry->connection
            || ($entry->sealed && $actual->getQuery() !== $entry->base)) {
            throw new LogicException('The canonical final execution model, connection or base was replaced.');
        }
    }

    /** Called only from this owner's canonical fixed sites, inside an already-open complete final scope. */
    private static function executeRegisteredCopiedFinal(Builder $actual, string $mode, User $viewer,
        CurrentAuthorizationReads $reads, HealthSafetyReadContext $context): array
    {
        if (! in_array($mode, ['exists', 'first_row'], true) || $actual->getQuery()->columns === null
            || ($mode === 'first_row' && $actual->getQuery()->limit !== 1)) {
            throw new LogicException('Prepare the canonical fixed execution shape before registration.');
        }
        $context->assertViewer($viewer); $reads->assertActive();
        $context->consumeSourceViewer([], static function (User $same, CurrentAuthorizationReads $sameReads) use ($viewer, $reads): void {
            if ($same !== $viewer || $sameReads !== $reads) { throw new LogicException('Register the exact original final-query tuple.'); }
        });
        $ticket = new \stdClass; self::$copiedFinalQueryTickets ??= new \WeakMap;
        $entry = (object) ['ticket' => $ticket, 'owner' => self::class, 'tuple' => [$viewer, $reads, $context], 'actual' => $actual,
            'model' => $actual->getModel(), 'connection' => $actual->getConnection(), 'base' => null,
            'mode' => $mode, 'active' => true, 'sealed' => false, 'hooks' => new \SplObjectStorage,
            'where_count' => count($actual->getQuery()->wheres ?? []),
            'where_bindings' => count($actual->getQuery()->getRawBindings()['where'] ?? [])];
        $entry->originalRootHooks = array_values(array_filter($actual->getQuery()->beforeQueryCallbacks,
            static fn ($callback) => ! self::isCopiedFinalReadHook($callback, $reads)));
        $entry->originalShape = self::copiedFinalShape($actual->getQuery(), $reads, $entry->hooks, true, null, $entry);
        self::$copiedFinalQueryTickets[$ticket] = $entry;
        try {
            // Install our lifetime proof BEFORE Linked's complete graph seal. Nothing decorates after Linked.
            ControlRoomOriginalReadOuterQuery::attach($actual,
                static function () use ($ticket, $viewer, $reads, $context, $actual): void {
                    if (self::registeredCopiedNoteFinalQuery($ticket, $viewer, $reads, $context) !== $actual) {
                        throw new LogicException('The canonical final execution escaped its original owner.');
                    }
                }, $reads);
            app(LinkedOperationalEvidencePresenter::class)->decorateRegisteredCopiedNoteFinalQuery(
                $ticket, self::class, $viewer, $reads, $context);
            self::assertRegisteredCopiedFinalShape($entry, $reads);
            $entry->base = $actual->getQuery();
            $entry->finalShape = self::copiedFinalShape($entry->base, $reads, $entry->hooks, true, null, $entry);
            $entry->sealed = true;
            self::registeredCopiedNoteFinalQuery($ticket, $viewer, $reads, $context);
            if (! $entry->base instanceof ControlRoomOriginalReadOuterQuery) {
                throw new LogicException('Execute only the complete guarded canonical final query.');
            }
            // Scopes, projection and first-row limit were materialized before registration.
            // Execute this exact base directly: no later Eloquent clone, scope or eager-body query.
            if ($entry->mode === 'exists') { $result = ['exists' => $entry->base->exists()]; }
            else {
                $row = $entry->base->first();
                $result = ['row' => $row === null ? null : (array) $row];
            }
            self::registeredCopiedNoteFinalQuery($ticket, $viewer, $reads, $context);
            \App\Services\Incidents\IncidentRoutingSettings::detached($result);
            return $result;
        } finally {
            try {
                app(LinkedOperationalEvidencePresenter::class)->retireRegisteredCopiedFinalHookReplacements(
                    $ticket, self::class, $viewer, $reads, $context);
            } finally {
                $entry->active = false;
                unset(self::$copiedFinalQueryTickets[$ticket]);
            }
        }
    }

    private static function assertRegisteredCopiedFinalShape(object $entry, CurrentAuthorizationReads $reads): void
    {
        if ($entry->actual->getModel() !== $entry->model || $entry->actual->getConnection() !== $entry->connection) {
            throw new LogicException('The original canonical final target changed during decoration.');
        }
        $base = $entry->actual->getQuery();
        if ($entry->sealed) {
            if (self::copiedFinalShape($base, $reads, $entry->hooks, false, null, $entry) !== $entry->finalShape) {
                throw new LogicException('The sealed canonical final predicates, selection or hooks changed.');
            }
            return;
        }
        // Linked may append only conjunctive source constraints. It cannot replace or rebaseline
        // any original CR predicate, nested graph, selection, target or existing binding.
        foreach (array_slice($base->wheres ?? [], $entry->where_count) as $where) {
            if (($where['boolean'] ?? null) !== 'and') { throw new LogicException('Source decoration must narrow the original canonical target.'); }
        }
        if (self::copiedFinalShape($base, $reads, $entry->hooks, false,
            [$entry->where_count, $entry->where_bindings], $entry) !== $entry->originalShape) {
            throw new LogicException('The original canonical final graph changed before its source seal.');
        }
    }

    private static function isCopiedFinalReadHook(mixed $callback, CurrentAuthorizationReads $reads): bool
    {
        if (! $callback instanceof \Closure) { return false; }
        $reflection = new \ReflectionFunction($callback);
        $method = new \ReflectionMethod(CurrentAuthorizationReads::class, 'query');
        return $reflection->getClosureThis() === $reads
            && $reflection->getClosureScopeClass()?->getName() === CurrentAuthorizationReads::class
            && $reflection->getFileName() === $method->getFileName()
            && $reflection->getStartLine() >= $method->getStartLine()
            && $reflection->getEndLine() <= $method->getEndLine();
    }

    /** Memory-only graph snapshot. Never compile SQL or invoke query/model/connection callbacks here. */
    private static function copiedFinalShape(\Illuminate\Database\Query\Builder $root, CurrentAuthorizationReads $reads,
        \SplObjectStorage $hooks, bool $collect = false, ?array $originalPrefix = null, ?object $entry = null): array
    {
        $visiting = new \SplObjectStorage;
        $walk = function ($value, $grammar, bool $isRoot = false) use (&$walk, $visiting, $reads, $hooks, $collect, $originalPrefix, $entry) {
            if ($value instanceof \Illuminate\Database\Query\Builder) {
                if ($visiting->contains($value)) { throw new LogicException('A canonical final query cannot contain a cyclic graph.'); }
                $visiting->attach($value); $fields = get_object_vars($value);
                $originalCallbacks = [];
                foreach ($fields['beforeQueryCallbacks'] ?? [] as $callback) {
                    if (! $callback instanceof \Closure) { throw new LogicException('Canonical query callbacks must retain their original identity.'); }
                    if (self::isCopiedFinalReadHook($callback, $reads)) { continue; }
                    if ($collect && (! $isRoot || $callback === ($entry->originalRootHooks[count($originalCallbacks)] ?? null))) { $hooks->attach($callback); }
                    elseif (! $hooks->contains($callback)) {
                        $original = $isRoot ? ($entry->originalRootHooks[count($originalCallbacks)] ?? null) : null;
                        if (! $original instanceof \Closure) {
                            throw new LogicException('An unregistered execution hook was added to the canonical final query.');
                        }
                        [$viewer, $sameReads, $context] = $entry->tuple;
                        app(LinkedOperationalEvidencePresenter::class)->assertRegisteredCopiedFinalHookReplacement(
                            $entry->ticket, self::class, $viewer, $sameReads, $context, $original, $callback);
                        $callback = $original;
                    }
                    $originalCallbacks[] = $callback;
                }
                if ($isRoot) {
                    // Only the root executes hooks. Linked additionally proves witnessed consumption;
                    // its private attestation permits exact wrappers, never new/reordered callbacks.
                    if ($originalCallbacks !== $entry->originalRootHooks
                        && ! ($entry->sealed && $originalCallbacks === [])) {
                        throw new LogicException('Original canonical final hooks were removed or reordered.');
                    }
                    unset($fields['beforeQueryCallbacks']);
                } else {
                    $fields['beforeQueryCallbacks'] = $originalCallbacks;
                }
                if (! in_array($fields['lock'] ?? null, [null, 'for share nowait'], true)) {
                    throw new LogicException('The canonical current-read lock changed.');
                }
                unset($fields['lock']);
                // CurrentAuthorizationReads lock() sets this flag while annotating the same tree.
                unset($fields['useWritePdo']);
                if ($isRoot && $originalPrefix !== null) {
                    $fields['wheres'] = array_slice($fields['wheres'] ?? [], 0, $originalPrefix[0]);
                    $fields['bindings']['where'] = array_slice($fields['bindings']['where'] ?? [], 0, $originalPrefix[1]);
                }
                $result = [$isRoot ? 'canonical_root' : get_class($value), $value->getConnection(),
                    $value->getGrammar(), $value->getProcessor(), $walk($fields, $value->getGrammar())];
                $visiting->detach($value);
                return $result;
            }
            if ($value instanceof \Illuminate\Contracts\Database\Query\Expression) {
                if ($visiting->contains($value)) { throw new LogicException('A canonical expression cannot contain a cyclic graph.'); }
                $visiting->attach($value); $properties = [];
                $reflection = new \ReflectionObject($value);
                foreach ($reflection->getProperties() as $property) {
                    if ($property->isStatic() || ! $property->isInitialized($value)) { continue; }
                    $properties[$property->getDeclaringClass()->getName().'::'.$property->getName()] =
                        $walk($property->getValue($value), $grammar);
                }
                $visiting->detach($value);
                return ['expression', get_class($value), $value, $properties];
            }
            if (is_array($value)) {
                $result = [];
                foreach ($value as $key => $part) { $result[$key] = $walk($part, $grammar); }
                return $result;
            }
            if ($value instanceof \DateTimeInterface) {
                return ['date', get_class($value), $value->format('Y-m-d H:i:s.uP'), $value->getTimezone()->getName()];
            }
            if (is_object($value)) { return ['identity', $value]; }
            if (is_resource($value)) { throw new LogicException('A canonical query graph cannot contain resource values.'); }
            return $value;
        };
        return $walk($root, $root->getGrammar(), true);
    }
}
