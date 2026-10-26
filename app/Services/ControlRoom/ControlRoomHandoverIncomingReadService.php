<?php

namespace App\Services\ControlRoom;

use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\HealthSafety\HealthSafetyReadContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Validation\ValidationException;

/** Retains incoming-lead Alert visibility through the outgoing preparation's final query. */
final class ControlRoomHandoverIncomingReadService
{
    private static ?\WeakMap $owners = null;

    public function within(User $incoming, callable $prepare): array
    {
        return HealthSafetyReadContext::within($incoming, ControlRoomGovernanceReadService::PERMISSIONS,
            function (HealthSafetyReadContext $context) use ($incoming, $prepare): array {
                self::$owners ??= new \WeakMap;
                $owner = (object) ['frames' => []];
                $context->retainControlRoomOriginalReadOwner($owner);
                self::$owners[$context] = \WeakReference::create($owner);
                app(ControlRoomGovernanceReadService::class)->withCurrentViewer($incoming,
                    function (User $current, HealthSafetyReadContext $same, CurrentAuthorizationReads $reads) use ($context, $owner): array {
                        if ($same !== $context) { throw new \LogicException('Keep the original incoming context.'); }
                        if (! $current->canDo('controlRoom.alerts.manage')) {
                            throw ValidationException::withMessages(['incoming_lead_user_id' => 'Choose a Control Room lead who can review and accept this handover.']);
                        }
                        $owner->frames['incoming'] = ['viewer' => $current, 'reads' => $reads, 'context' => $same,
                            'actor' => $this->plainClone($context->originalSourceAuthorityQuery(ControlRoomGovernanceReadService::PERMISSIONS, [])),
                            'state' => 'pending', 'ids' => [], 'roots' => []];
                        return [];
                    }, $context);
                // The incoming Governance actor tail has finished. The bare context has no SQL tail.
                $result = $prepare($owner->frames['incoming']['viewer'], $context);
                if (! is_array($result)) { throw new \LogicException('Preparation contexts return detached arrays.'); }
                \App\Services\Incidents\IncidentRoutingSettings::detached($result);
                $context->assertActive();
                return $result;
            });
    }

    /** Canonical metadata get and original READ callbacks mint the private witnesses, never supplied IDs alone. */
    public function capture(User $incoming, array $candidateIds, HealthSafetyReadContext $context): void
    {
        [$owner, $frame] = $this->frame($incoming, $context);
        foreach ($candidateIds as $id) {
            if (! is_int($id) || $id < 1) { throw new \LogicException('Incoming candidates must be exact positive IDs.'); }
        }
        $ids = array_values(array_unique($candidateIds)); sort($ids);
        if ($frame['state'] !== 'pending') { throw new \LogicException('The first incoming candidate set cannot be recaptured.'); }
        $owner->frames['incoming']['state'] = 'capturing';
        try {
            $governance = app(ControlRoomGovernanceReadService::class);
            $governance->withCurrentViewer($incoming,
                function (User $current, HealthSafetyReadContext $same, CurrentAuthorizationReads $reads) use ($owner, $frame, $ids, $context, $governance): array {
                    if ($current !== $frame['viewer'] || $reads !== $frame['reads'] || $same !== $context) {
                        throw new \LogicException('Capture the same incoming actor and read handles.');
                    }
                    // This fixed path has no search or workflow filters. Never widen it to arbitrary filters.
                    $query = app(AlertWorklistQuery::class)->forUser($current, ['lens' => 'all_records'], $same)
                        ->whereKey($ids)->setEagerLoads([]);
                    $originalList = $this->plainClone($query);
                    // Keep the complete safe metadata projection so Governance records original ownership.
                    $visible = $query->get()->modelKeys();
                    $visible = array_values(array_unique(array_map('intval', $visible))); sort($visible);
                    if ($visible !== $ids) {
                        throw ValidationException::withMessages(['incoming_lead_user_id' => 'Choose an incoming lead who can access every alert included in this handover.']);
                    }
                    $roots = [];
                    foreach ($ids as $id) {
                        $result = $governance->consumeOperationalAlertDetails($current, $id, $same,
                            function (Builder $original, CurrentAuthorizationReads $sameReads, User $sameViewer, HealthSafetyReadContext $sameContext)
                                use ($current, $reads, $same, $id, &$roots): array {
                                if ($sameReads !== $reads || $sameViewer !== $current || $sameContext !== $same) {
                                    throw new \LogicException('Retain the actual original incoming Alert witness.');
                                }
                                $roots[$id] = $this->plainClone($original);
                                return [];
                            });
                        abort_unless($result['available'] && isset($roots[$id]), 404, 'The incoming Alert access changed.');
                    }
                    $owner->frames['incoming']['ids'] = $ids;
                    $owner->frames['incoming']['list'] = $originalList;
                    $owner->frames['incoming']['roots'] = $roots;
                    return [];
                }, $context);
            // Mark ready only after ALL canonical callback and actor tails succeed.
            $owner->frames['incoming']['state'] = 'ready';
        } catch (\Throwable $exception) {
            $owner->frames['incoming']['state'] = 'denied';
            throw $exception;
        }
    }

    /** Narrow the actual outgoing target with every FIRST incoming witness; no incoming SQL tail follows it. */
    public function constrainOriginalRead(User $incoming, HealthSafetyReadContext $context, Builder $target): void
    {
        [$owner, $frame] = $this->frame($incoming, $context);
        if ($frame['state'] !== 'ready' || array_keys($frame['roots']) !== $frame['ids']) {
            throw new \LogicException('A complete original incoming admission is required.');
        }
        $this->assertPlain($target);
        $add = function (Builder $query) use ($target): void {
            $query = $this->plainClone($query)->setEagerLoads([])->reorder()->select([])->selectRaw('1');
            $query->toSql(); $target->whereExists($query->toBase());
        };
        $add($frame['actor']);
        foreach ($frame['ids'] as $id) {
            $add($frame['roots'][$id]);
            $add($this->plainClone($frame['list'])->whereKey($id));
        }
        // This is an in-memory lifetime check evaluated on every final composite compilation.
        $target->whereRaw(new class($owner, $incoming, $context, $frame['reads']) extends \Illuminate\Database\Query\Expression {
            public function __construct(private readonly \stdClass $owner, private readonly User $viewer,
                private readonly HealthSafetyReadContext $context, private readonly CurrentAuthorizationReads $reads) { parent::__construct('1 = 1'); }
            public function getValue(\Illuminate\Database\Grammar $grammar)
            {
                $this->context->assertViewer($this->viewer); $this->reads->assertActive();
                $frame = $this->owner->frames['incoming'] ?? null;
                if ($frame === null || $frame['viewer'] !== $this->viewer || $frame['context'] !== $this->context
                    || $frame['reads'] !== $this->reads || $frame['state'] !== 'ready') {
                    throw new \LogicException('The incoming preparation admission has ended.');
                }
                return parent::getValue($grammar);
            }
        });
    }

    private function frame(User $incoming, HealthSafetyReadContext $context): array
    {
        $owner = (self::$owners[$context] ?? null)?->get();
        $frame = $owner?->frames['incoming'] ?? null;
        if ($frame === null || $frame['viewer'] !== $incoming || $frame['context'] !== $context) {
            throw new \LogicException('Use the exact privately retained incoming actor and context.');
        }
        $context->assertViewer($incoming); $frame['reads']->assertActive();
        $context->consumeSourceViewer(ControlRoomGovernanceReadService::PERMISSIONS,
            function (User $same, CurrentAuthorizationReads $reads) use ($incoming, $frame): void {
                if ($same !== $incoming || $reads !== $frame['reads']) { throw new \LogicException('Incoming read handles changed.'); }
            });
        return [$owner, $frame];
    }

    private function assertPlain(mixed $query): void
    {
        if ($query instanceof Builder) { $query = $query->getQuery(); }
        if ($query instanceof QueryBuilder) {
            if (! in_array(get_class($query), [QueryBuilder::class, JoinClause::class], true)) {
                throw new \LogicException('Retain canonical plain witnesses; never strip an execution guard.');
            }
            foreach (['wheres', 'joins', 'unions'] as $property) { $this->assertPlain($query->{$property}); }
        } elseif (is_array($query)) { foreach ($query as $part) { $this->assertPlain($part); } }
    }

    private function plainClone(Builder $query): Builder
    {
        $this->assertPlain($query);
        $copy = function (mixed $part) use (&$copy): mixed {
            if ($part instanceof QueryBuilder) {
                $part = clone $part;
                foreach (['wheres', 'joins', 'unions'] as $property) { $part->{$property} = $copy($part->{$property}); }
                return $part;
            }
            return is_array($part) ? array_map($copy, $part) : $part;
        };
        $cloned = clone $query;
        return $cloned->setQuery($copy($query->getQuery()));
    }
}
