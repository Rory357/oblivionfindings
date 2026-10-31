<?php

namespace App\Services\Incidents;

use App\Models\ClientIncident;
use App\Models\HsEvent;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Grammar;
use Illuminate\Support\Facades\DB;
use LogicException;
use App\Support\HealthSafety\QueryPaging;

/** Original incident/HSE association, intersected with existing source authority before copied bodies. */
final class IncidentHsEventSourceReadService
{
    public const PERMISSIONS = IncidentSavedCopyService::PERMISSIONS;
    private const EVENT_META = ['id', 'source_type', 'source_id', 'event_category', 'idempotency_key', 'site_id', 'client_id', 'control_room_alert_id'];
    private const ROOT_META = ['id', 'type', 'hs_event_id', 'control_room_alert_id', ...IncidentIndependenceIntakeService::ROOT_COLUMNS];
    private \WeakMap $evidence;
    private \WeakMap $eventParents;
    private \WeakMap $rootParents;
    private \WeakMap $discovery;

    public function __construct()
    {
        $this->evidence = new \WeakMap;
        $this->eventParents = new \WeakMap;
        $this->rootParents = new \WeakMap;
        $this->discovery = new \WeakMap;
    }

    /** Classification only: suspicious spellings/direct links must not escape through the unrelated-source arm. */
    public function incidentRelated(Builder $events): Builder
    {
        return $events->where(function (Builder $family): void {
            $family->whereRaw('LOWER(TRIM(LEADING ? FROM TRIM(REPLACE(REPLACE(REPLACE(`hs_events`.`source_type`, CHAR(9), ?), CHAR(10), ?), CHAR(13), ?)))) = LOWER(?)', ['\\', '', '', '', ClientIncident::class])
                ->orWhereExists(DB::table('client_incidents as hs_incident_family')->selectRaw('1')
                    ->whereColumn('hs_incident_family.hs_event_id', 'hs_events.id'));
        });
    }

    /** No callback means no source evidence, never an inferred permission or a detached ID admission list.
     * One approved exception (Stephan, 30 Oct 2026, speed option B): within ONE request's locked read, the ids that
     * the full unchanged proof admits may be proven once and reused by that same request's later counts and lists.
     * Never across requests, stored, or for another user. See HsEventAccessService::provenReadableEventIds() and
     * IncidentRegisterReadService (proven register ids). */
    public function withoutIncidentSources(Builder $events): Builder
    {
        return $events->where(function (Builder $ordinary): void {
            $ordinary->where(fn (Builder $typed) => $typed->whereNull('hs_events.source_type')
                ->orWhereRaw('LOWER(TRIM(LEADING ? FROM TRIM(REPLACE(REPLACE(REPLACE(`hs_events`.`source_type`, CHAR(9), ?), CHAR(10), ?), CHAR(13), ?)))) <> LOWER(?)', ['\\', '', '', '', ClientIncident::class]))
                ->whereNotExists(DB::table('client_incidents as hs_incident_family')->selectRaw('1')
                    ->whereColumn('hs_incident_family.hs_event_id', 'hs_events.id'));
        });
    }

    public function applyEventScopeInReads(Builder $events, User $current, CurrentAuthorizationReads $reads, ?Builder $candidates = null): Builder
    {
        $reads->assertActive();
        $candidates = (clone ($candidates ?? $events))->setEagerLoads([])->reorder()->when(true, QueryPaging::clear(...));
        $this->assertEvents($candidates);
        // Exactly the identity columns, whatever the caller selected (a register adds count columns that aren't on the table).
        $identities = $reads->query($this->incidentRelated($candidates->select(array_map(fn (string $column): string => 'hs_events.'.$column, self::EVENT_META))))->get();
        $sourceIds = $identities->pluck('source_id')->filter()->all();
        $eventIds = $identities->pluck('id')->all();
        if ($eventIds !== []) {
            $roots = $reads->query(ClientIncident::query()->where(fn (Builder $root) => $root
                ->whereIn('id', $sourceIds)->orWhereIn('hs_event_id', $eventIds)))->get(array_values(array_unique(self::ROOT_META)));
            // One batched evidence check for every root, instead of a full independence proof per root (speed, 30 Oct).
            app(IncidentIndependenceIntakeService::class)->prefetchEvidenceInReads($roots->pluck('id'), $reads);
            // Every linked event's parent is the restricted root variant (see the loop below).
            $this->prefetchRootIdentities($roots->pluck('id'), $current, $reads, true);
            $discovery = $this->discovery[$reads] ?? [];
            foreach ($identities as $event) {
                $discovery[$event->id] ??= $roots->filter(fn ($root) => (int) $root->id === (int) $event->source_id || (int) $root->hs_event_id === (int) $event->id)->values();
            }
            $this->discovery[$reads] = $discovery;
        }
        $relatedIds = $identities->pluck('id')->all();
        $bindings = [];
        foreach ($identities as $event) {
            // Parents without the viewer-wide authority facts: those are the same in every block and are added once below.
            // Restricted parents (Stephan, 25 Oct, "event yes, incident no"): an incident-linked H&S event is visible to
            // H&S viewers at its Site without incident read, as on main. The incident's Site and independence rules and
            // its exact original facts still apply; its content (HR-confidential rule included) stays behind
            // incidentQueryForEventInReads().
            $parent = $this->eventParent($event, $current, $reads, false, true);
            $bindings[] = ['event' => $event, 'parent' => $parent];
        }
        $events->where(function (Builder $scope) use ($bindings, $relatedIds, $current, $reads): void {
            $scope->where(fn (Builder $ordinary) => $this->withoutIncidentSources($ordinary)->whereNotIn('hs_events.id', $relatedIds));
            if ($bindings === []) { return; }
            // (X1 AND A) OR (X2 AND A) ... is A AND (X1 OR X2 ...). A only compares the viewer's own uncorrelated rows,
            // so this is the same test with A compiled once rather than once per linked event. Without a snapshot no
            // parent reached authority (each is "1 = 0"), so every linked block is false either way.
            $scope->orWhere(function (Builder $linked) use ($bindings, $current, $reads): void {
                if ($this->hasAuthoritySnapshot($current, $reads)) { $this->authorityCommon($linked, $current, $reads); }
                $linked->where(function (Builder $any) use ($bindings): void {
                    foreach ($bindings as $binding) {
                        $any->orWhere(function (Builder $exact) use ($binding): void {
                            $this->exact($exact, $binding['event']->getAttributes(), 'hs_events');
                            $exact->whereExists((clone $binding['parent'])->reorder()->selectRaw('1')->toBase());
                        });
                    }
                });
            });
        });
        return $this->protect($events, $reads);
    }

    /** Active, protected parent Builder. Metadata models only locate the literal original; they are not read receipts. */
    public function incidentQueryForEventInReads(HsEvent $original, User $current, CurrentAuthorizationReads $reads): Builder
    {
        return $this->eventParent($original, $current, $reads, true);
    }

    /** $common false leaves out the viewer-wide authority facts; only the list scopes, which add them once, use that. */
    private function eventParent(HsEvent $original, User $current, CurrentAuthorizationReads $reads, bool $common, bool $restricted = false): Builder
    {
        $reads->assertActive();
        $empty = fn (): Builder => $this->protect(ClientIncident::query()->whereRaw('1 = 0'), $reads);
        $meta = array_intersect_key($original->getRawOriginal(), array_flip(self::EVENT_META));
        if (count($meta) !== count(self::EVENT_META) || ! $original->id) { return $empty(); }
        // Callers load these columns in different orders (list identities vs a full model); compare by key, values strict.
        ksort($meta);
        $type = $original->getRawOriginal('source_type');
        // Existing Journey read contract allows leading slash legacy spelling, never case/trailing-byte rewrites.
        if (! is_string($type) || ltrim($type, '\\') !== ClientIncident::class || ! $original->source_id) { return $empty(); }
        $eventParents = $this->eventParents[$reads] ?? [];
        $key = ($common ? (string) $current->id : $current->id.'/site-client').($restricted ? '/restricted' : '');
        // Both variants pin the earliest event facts seen in this lifetime; a changed event is never a new original.
        $pinned = $eventParents[$current->id.'/meta'][$original->id] ?? null;
        if ($pinned !== null && $pinned !== $meta) { return $empty(); }
        if (isset($eventParents[$key][$original->id])) {
            return $eventParents[$key][$original->id]['meta'] === $meta ? clone $eventParents[$key][$original->id]['query'] : $empty();
        }
        $roots = ($this->discovery[$reads] ?? [])[$original->id] ?? $reads->query(ClientIncident::query()->where(fn (Builder $root) => $root
            ->whereKey($original->source_id)->orWhere('hs_event_id', $original->id)))->get(array_values(array_unique(self::ROOT_META)));
        if ($roots->count() !== 1 || (int) $roots->sole()->id !== (int) $original->source_id) { return $empty(); }
        $root = $roots->sole();
        if (($root->hs_event_id !== null && (int) $root->hs_event_id !== (int) $original->id)
            || ! app(IncidentJourneyService::class)->hsEventIsCanonicalForIncident($root, $original)) { return $empty(); }
        $query = $this->originalRootParent($root, $current, $reads, $common, $restricted);
        $base = $this->rootBase($root, $current, $reads, $restricted);
        $event = HsEvent::query()->whereKey($original->id);
        $this->exact($event, $meta, 'hs_events');
        $event->whereNotExists(DB::table('client_incidents as hs_other_incident')->selectRaw('1')
            ->where('hs_other_incident.hs_event_id', $original->id)->where('hs_other_incident.id', '!=', $root->id));
        $event->whereNotExists(DB::table('hs_events as hs_other_source')->selectRaw('1')
            ->where('hs_other_source.id', '!=', $original->id)->where('hs_other_source.source_id', $root->id)
            ->whereRaw('LOWER(TRIM(LEADING ? FROM TRIM(REPLACE(REPLACE(REPLACE(`hs_other_source`.`source_type`, CHAR(9), ?), CHAR(10), ?), CHAR(13), ?)))) = LOWER(?)', ['\\', '', '', '', ClientIncident::class]));
        $query->whereExists($event->selectRaw('1')->toBase());
        // originalRootParent() already holds the authority conditions for the original root's Site and Client.
        // The same viewer, Site and Client give the same conditions, so they are added again only when the event's differ.
        $siteId = $original->site_id === null ? null : (int) $original->site_id;
        $clientId = $original->client_id === null ? null : (int) $original->client_id;
        if ($siteId !== $base['site_id'] || $clientId !== $base['client_id']) {
            $query = $common ? $this->authorityUnchanged($query, $current, $reads, $siteId, $clientId)
                : $this->authorityScoped($query, $current, $reads, $siteId, $clientId);
        }
        $query = $this->protect($query, $reads);
        $eventParents[$key][$original->id] = ['meta' => $meta, 'query' => clone $query];
        $eventParents[$current->id.'/meta'][$original->id] = $meta;
        $this->eventParents[$reads] = $eventParents;
        return $query;
    }

    /** @var \WeakMap<CurrentAuthorizationReads, array<int, list<int>>>|null */
    private static ?\WeakMap $provenIncidentIds = null;

    /** @var \WeakMap<CurrentAuthorizationReads, true>|null reads a page-wide H&S read switched on (HealthSafetyReadContext::provePageWide) */
    private static ?\WeakMap $pageWideReads = null;

    /** Only a page-wide read uses the proven incident set; every other read keeps the embedded scope. */
    public static function provePageWide(CurrentAuthorizationReads $reads): void
    {
        $reads->assertActive();
        self::$pageWideReads ??= new \WeakMap;
        self::$pageWideReads[$reads] = true;
    }

    public static function isPageWide(?CurrentAuthorizationReads $reads): bool
    {
        return $reads !== null && isset(self::$pageWideReads[$reads]);
    }

    /** @var \WeakMap<CurrentAuthorizationReads, array<int, User>>|null */
    private static ?\WeakMap $provenIncidentViewers = null;

    /** @return list<int> */
    private function proveIncidentIds(User $current, CurrentAuthorizationReads $reads): array
    {
        return $reads->query($this->applyIncidentScopeInReadsEmbedded(ClientIncident::query(), $current, $reads))
            ->reorder()->pluck('client_incidents.id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
    }

    /**
     * Response barrier for the proven incident sets: the embedded scope runs once more before a page-wide read returns;
     * a set that no longer matches (a moved incident, a withdrawn permission) withholds the whole response (404).
     */
    public function recheckProvenIncidentIds(CurrentAuthorizationReads $reads): void
    {
        foreach (self::$provenIncidentViewers[$reads] ?? [] as $id => $current) {
            $proven = self::$provenIncidentIds[$reads][$id] ?? null;
            if ($proven !== null && $this->proveIncidentIds($current, $reads) !== $proven) {
                throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
            }
        }
    }

    /**
     * Original incident scopes for admitted metrics/reports. No HSE association is invented for an independent incident.
     * Approved exception (Stephan, 30 Oct 2026, speed option B; see the rule note above withoutIncidentSources()): the
     * incident ids the embedded scope admits are proven ONCE per locked read and viewer, over all incidents, and each
     * metric in the same read is "its own filters AND id IN that set". The embedded scope decides each incident on its
     * own facts, so the rows are the same; parity tests compare the two for every role.
     */
    public function applyIncidentScopeInReads(Builder $incidents, User $current, CurrentAuthorizationReads $reads): Builder
    {
        $reads->assertActive();
        if (! $incidents->getModel() instanceof ClientIncident || $incidents->getQuery()->from !== 'client_incidents') {
            throw new LogicException('Incident source scope requires the canonical incident table.');
        }
        if (! isset(self::$pageWideReads[$reads])) {
            return $this->applyIncidentScopeInReadsEmbedded($incidents, $current, $reads);
        }
        self::$provenIncidentIds ??= new \WeakMap;
        $known = self::$provenIncidentIds[$reads] ?? [];
        if (! array_key_exists((int) $current->id, $known)) {
            $known[(int) $current->id] = $this->proveIncidentIds($current, $reads);
            self::$provenIncidentIds[$reads] = $known;
            self::$provenIncidentViewers ??= new \WeakMap;
            self::$provenIncidentViewers[$reads] = [...(self::$provenIncidentViewers[$reads] ?? []), (int) $current->id => $current];
        }

        $original = \App\Support\HealthSafety\ProofParity::$enabled ? clone $incidents : null;
        $scoped = $this->protect($incidents->whereIn('client_incidents.id', $known[(int) $current->id]), $reads);
        if ($original !== null) {
            \App\Support\HealthSafety\ProofParity::assertSameRows($scoped, $this->applyIncidentScopeInReadsEmbedded($original, $current, $reads),
                'client_incidents.id', 'Incident source scope');
        }

        return $scoped;
    }

    /** The embedded scope: every admitted incident's full guard in the query (also what proves the per-read set). */
    public function applyIncidentScopeInReadsEmbedded(Builder $incidents, User $current, CurrentAuthorizationReads $reads): Builder
    {
        $reads->assertActive();
        if (! $incidents->getModel() instanceof ClientIncident || $incidents->getQuery()->from !== 'client_incidents') {
            throw new LogicException('Incident source scope requires the canonical incident table.');
        }
        $discover = app(IncidentAccessService::class)->apply((clone $incidents)->setEagerLoads([])->reorder()->when(true, QueryPaging::clear(...)), $current, $reads);
        $roots = $reads->query($discover->select(array_values(array_unique(self::ROOT_META))))->get();
        app(IncidentIndependenceIntakeService::class)->prefetchEvidenceInReads($roots->pluck('id'), $reads);
        $this->prefetchRootIdentities($roots->pluck('id'), $current, $reads, false);
        if ($roots->isEmpty()) { return $this->protect($incidents->whereRaw('1 = 0'), $reads); }
        $original = \App\Support\HealthSafety\ProofParity::$enabled ? clone $incidents : null;
        $groups = $this->groupedRootGuards($roots, $current, $reads);
        // Speed R3b. Per root, the old guard was G_i = "id = i AND S AND Admitted_i AND Exact_i AND Site/Client_i", where S
        // (canonical access + Site scope) is the same row test for every root and Admitted_i (the independence block)
        // also requires id = i. Each chunk's admitted query is "S AND (Admitted_i OR Admitted_j ...)" over its roots, so
        // for a row with id = i it is exactly "S AND Admitted_i". Hence
        //   OR_i (id IN G_i)  =  OR_chunk (id IN Chunk AND OR_{i in chunk} id IN (id = i AND Exact_i AND Site/Client_i))
        // row by row: the same facts, with S and the evidence-free independence checks compiled once per chunk instead of
        // once per root (the per-root form made a ~650 KB statement on the Overview). PROOF_PARITY compares both forms.
        $incidents->where(function (Builder $allowed) use ($groups, $current, $reads): void {
            $this->authorityCommon($allowed, $current, $reads);
            $allowed->where(function (Builder $any) use ($groups): void {
                foreach ($groups as [$chunk, $exacts]) {
                    $any->orWhere(function (Builder $group) use ($chunk, $exacts): void {
                        $group->whereIn('client_incidents.id', (clone $chunk)->reorder()->select('client_incidents.id'));
                        $group->where(function (Builder $own) use ($exacts): void {
                            foreach ($exacts as $exact) { $own->orWhereIn('client_incidents.id', (clone $exact)->reorder()->select('client_incidents.id')); }
                        });
                    });
                }
            });
        });
        $scoped = $this->protect($incidents, $reads);
        if ($original !== null) {
            \App\Support\HealthSafety\ProofParity::assertSameRows($scoped, $this->applyIncidentScopeInReadsPerRoot($original, $current, $reads),
                'client_incidents.id', 'Incident embedded scope (grouped vs per root)');
        }

        return $scoped;
    }

    /** @var \WeakMap<CurrentAuthorizationReads, array<string, array{chunks: list<array{ids: list<int>, query: Builder}>, exact: array<int, Builder>}>>|null */
    private ?\WeakMap $groupedGuards = null;

    /**
     * The grouped guards for these roots (see applyIncidentScopeInReadsEmbedded()), built once per reads and viewer like
     * the per-root guards: a root's chunk and exact guard come from the first call that saw it, and a later call only adds
     * a chunk for roots not seen yet. Each chunk's admitted query is the canonical access query for `id IN (chunk)` through
     * the independence scope, from the rows read once by prefetchRootIdentities().
     *
     * @return list<array{0: Builder, 1: list<Builder>}> per chunk: its admitted query and the exact guards of these roots
     */
    private function groupedRootGuards(\Illuminate\Support\Collection $roots, User $current, CurrentAuthorizationReads $reads): array
    {
        $this->groupedGuards ??= new \WeakMap;
        $known = $this->groupedGuards[$reads] ?? [];
        $entry = $known[(string) $current->id] ?? ['chunks' => [], 'exact' => []];
        $seen = [];
        foreach ($entry['chunks'] as $index => $chunk) { foreach ($chunk['ids'] as $id) { $seen[$id] = $index; } }
        $new = $roots->filter(fn (ClientIncident $root) => ! isset($seen[(int) $root->id]))->values();
        foreach ($new->chunk(500) as $part) {
            $ids = $part->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $query = $this->rootAccessQuery(ClientIncident::query()->whereIn('client_incidents.id', $ids), $current, $reads, false);
            $query = app(IncidentIndependenceIntakeService::class)->applySourceScopeInReads($query, $current, $reads, 'read',
                prefetched: [$this->identityKey($current, false), $ids]);
            $entry['chunks'][] = ['ids' => $ids, 'query' => $query];
            foreach ($ids as $id) { $seen[$id] = array_key_last($entry['chunks']); }
            foreach ($part as $root) {
                $exact = ClientIncident::query()->whereKey($root->id);
                $this->exact($exact, array_intersect_key($root->getRawOriginal(), array_flip(self::ROOT_META)), 'client_incidents');
                $exact = $this->authorityScoped($exact, $current, $reads,
                    $root->site_id === null ? null : (int) $root->site_id, $root->client_id === null ? null : (int) $root->client_id);
                $entry['exact'][(int) $root->id] = $this->protect($exact, $reads);
            }
        }
        $known[(string) $current->id] = $entry;
        $this->groupedGuards[$reads] = $known;
        $groups = [];
        foreach ($roots as $root) {
            $index = $seen[(int) $root->id];
            $groups[$index] ??= [$entry['chunks'][$index]['query'], []];
            $groups[$index][1][] = $entry['exact'][(int) $root->id];
        }

        return array_values($groups);
    }

    /** The per-root form of the embedded scope (one full guard per root). Kept for PROOF_PARITY. */
    private function applyIncidentScopeInReadsPerRoot(Builder $incidents, User $current, CurrentAuthorizationReads $reads): Builder
    {
        $discover = app(IncidentAccessService::class)->apply((clone $incidents)->setEagerLoads([])->reorder()->when(true, QueryPaging::clear(...)), $current, $reads);
        $roots = $reads->query($discover->select(array_values(array_unique(self::ROOT_META))))->get();
        $bindings = [];
        foreach ($roots as $root) { $bindings[] = $this->originalRootParent($root, $current, $reads, false); }
        if ($bindings === []) { return $this->protect($incidents->whereRaw('1 = 0'), $reads); }
        // Every guard carried the same viewer-wide authority facts A (uncorrelated), so
        // "id IN (G1 AND A) OR id IN (G2 AND A) ..." is "A AND (id IN G1 OR id IN G2 ...)", with A compiled once.
        $incidents->where(function (Builder $allowed) use ($bindings, $current, $reads): void {
            $this->authorityCommon($allowed, $current, $reads);
            $allowed->where(function (Builder $any) use ($bindings): void {
                foreach ($bindings as $guard) {
                    $any->orWhereIn('client_incidents.id', (clone $guard)->reorder()->select('client_incidents.id'));
                }
            });
        });
        return $this->protect($incidents, $reads);
    }

    /** Reuse the earliest original source proof within this exact reads/actor lifetime; never recapture a changed root as acceptance.
     * $common false leaves out the viewer-wide authority facts, for list scopes that add them once around all parents. */
    private function originalRootParent(ClientIncident $root, User $current, CurrentAuthorizationReads $reads, bool $common = true, bool $restricted = false): Builder
    {
        $key = ($common ? (string) $current->id : $current->id.'/site-client').($restricted ? '/restricted' : '');
        if (isset(($this->rootParents[$reads] ?? [])[$key][$root->id])) { return clone $this->rootParents[$reads][$key][$root->id]; }
        // Both variants derive from the same earliest root base, so neither recaptures a changed root.
        $base = $this->rootBase($root, $current, $reads, $restricted);
        $query = clone $base['query'];
        $query = $common ? $this->authorityUnchanged($query, $current, $reads, $base['site_id'], $base['client_id'])
            : $this->authorityScoped($query, $current, $reads, $base['site_id'], $base['client_id']);
        $query = $this->protect($query, $reads);
        $parents = $this->rootParents[$reads] ?? [];
        $parents[$key][$root->id] = clone $query;
        $this->rootParents[$reads] = $parents;
        return $query;
    }

    /** Canonical access, independence and the exact original root facts, captured once per reads/actor/root. */
    private function rootBase(ClientIncident $root, User $current, CurrentAuthorizationReads $reads, bool $restricted = false): array
    {
        $baseKey = $current->id.'/base'.($restricted ? '/restricted' : '');
        if (isset(($this->rootParents[$reads] ?? [])[$baseKey][$root->id])) { return $this->rootParents[$reads][$baseKey][$root->id]; }
        $query = $this->rootAccessQuery(ClientIncident::query()->whereKey($root->id), $current, $reads, $restricted);
        $query = app(IncidentIndependenceIntakeService::class)->applySourceScopeInReads($query, $current, $reads, 'read', siteBoundaryOnly: $restricted,
            prefetched: [$this->identityKey($current, $restricted), (int) $root->id]);
        $this->exact($query, array_intersect_key($root->getRawOriginal(), array_flip(self::ROOT_META)), 'client_incidents');
        $base = ['query' => $query, 'site_id' => $root->site_id === null ? null : (int) $root->site_id,
            'client_id' => $root->client_id === null ? null : (int) $root->client_id];
        $parents = $this->rootParents[$reads] ?? [];
        $parents[$baseKey][$root->id] = $base;
        $this->rootParents[$reads] = $parents;
        return $base;
    }

    /**
     * The canonical access query rootBase() starts from, for one root (`whereKey`) or a batch (`whereIn`).
     * Restricted (H&S event visibility only): the incident's Site boundary, without the incident read permission or
     * the HR-confidential content rule. As on main, H&S staff see the H&S event of a confidential incident (its
     * WorkSafe work can't go unseen); the incident's content is the full variant, the canonical incident read.
     */
    private function rootAccessQuery(Builder $incidents, User $current, CurrentAuthorizationReads $reads, bool $restricted): Builder
    {
        $access = app(IncidentAccessService::class);

        return $restricted
            ? $reads->query($access->applySiteBoundary($incidents, $current, $reads))
            : $access->apply($incidents, $current, $reads);
    }

    private function identityKey(User $current, bool $restricted): string
    {
        return 'incident-root/'.$current->id.($restricted ? '/restricted' : '');
    }

    /** Speed R3b: read the identity rows of every root a list scope is about to bind in one query, not one per root
     * (see IncidentIndependenceIntakeService::prefetchSourceIdentitiesInReads()). */
    private function prefetchRootIdentities(iterable $rootIds, User $current, CurrentAuthorizationReads $reads, bool $restricted): void
    {
        $ids = collect($rootIds)->map(fn ($id) => (int) $id)->filter(fn (int $id) => $id > 0)->unique()->values()->all();
        if ($ids === []) { return; }
        app(IncidentIndependenceIntakeService::class)->prefetchSourceIdentitiesInReads(
            $this->rootAccessQuery(ClientIncident::query(), $current, $reads, $restricted), $ids, $current, $reads,
            $this->identityKey($current, $restricted), siteBoundaryOnly: $restricted);
    }

    /** Every child SELECT embeds the SAME original parent/HSE/independence/authority predicate. */
    public function childQueryInReads(Builder $children, string $parentColumn, Builder $parent, CurrentAuthorizationReads $reads): Builder
    {
        $reads->assertActive();
        $children->whereRaw(new class(clone $parent) extends Expression {
            public function __construct(private readonly Builder $original)
            {
                parent::__construct('1 = 1');
            }
            public function getValue(Grammar $grammar)
            {
                (clone $this->original)->toSql();
                return parent::getValue($grammar);
            }
        });
        $children->whereIn($parentColumn, (clone $parent)->reorder()->select('client_incidents.id'));
        return $this->protect($children, $reads);
    }

    /** Nested SQL compilation remains bound even when an outer unrelated Builder tries to reuse the predicate. */
    private function protect(Builder $query, CurrentAuthorizationReads $reads): Builder
    {
        $timezone = (string) (config('app.worker_timezone') ?: config('app.timezone') ?: 'UTC');
        $day = now($timezone)->toDateString();
        $query->whereRaw(new class($reads, $timezone, $day) extends Expression {
            public function __construct(private readonly CurrentAuthorizationReads $reads, private readonly string $timezone, private readonly string $day)
            {
                parent::__construct('1 = 1');
            }
            public function getValue(Grammar $grammar)
            {
                $this->reads->assertActive();
                if ((string) (config('app.worker_timezone') ?: config('app.timezone') ?: 'UTC') !== $this->timezone || now($this->timezone)->toDateString() !== $this->day) {
                    throw new LogicException('Incident/HSE source date evidence changed during materialisation.');
                }
                return parent::getValue($grammar);
            }
        });
        return $reads->query($query);
    }

    private function assertEvents(Builder $query): void
    {
        if (! $query->getModel() instanceof HsEvent || $query->getQuery()->from !== 'hs_events'
            || $query->getQuery()->groups || $query->getQuery()->havings || $query->getQuery()->unions) {
            throw new LogicException('Incident HSE scope requires independent canonical event candidates.');
        }
    }

    private function exact(Builder|QueryBuilder $query, array $values, ?string $table = null): Builder|QueryBuilder
    {
        foreach ($values as $column => $value) {
            $column = $table === null ? $column : $table.'.'.$column;
            if ($value === null) { $query->whereNull($column); }
            elseif (is_string($value)) { $query->whereRaw('BINARY '.$query->getGrammar()->wrap($column).' = BINARY ?', [$value]); }
            else { $query->where($column, $value); }
        }
        return $query;
    }

    /** Equality only, after canonical Access/Intake admission. Reuses original evidence; it never evaluates/confers permission. */
    private function authorityUnchanged(Builder $source, User $current, CurrentAuthorizationReads $reads, ?int $siteId, ?int $clientId): Builder
    {
        return $this->authorityScoped($this->authorityCommon($source, $current, $reads), $current, $reads, $siteId, $clientId);
    }

    private function hasAuthoritySnapshot(User $current, CurrentAuthorizationReads $reads): bool
    {
        return isset(($this->evidence[$reads] ?? [])[$current->id]);
    }

    /** The viewer's own role, permission, override and HR profile rows: the same for every Site and Client. */
    private function authorityCommon(Builder $source, User $current, CurrentAuthorizationReads $reads): Builder
    {
        $snapshot = $this->authoritySnapshot($current, $reads);
        $roleIds = array_column($snapshot['roles'], 'id');
        $permissionIds = array_values(array_unique([...array_column($snapshot['definitions'], 'id'), ...array_keys($snapshot['used_definitions'])]));
        foreach ($snapshot['used_definitions'] as $definition) {
            $source->whereExists($this->exact(DB::table('permissions')->where('id', $definition['id']), $definition)->selectRaw('1'));
        }
        foreach ([
            [DB::table('users')->where('id', $current->id), $snapshot['users'], ['id']],
            [DB::table('permissions')->whereIn('key', self::PERMISSIONS), $snapshot['definitions'], ['id']],
            [DB::table('role_user')->where('user_id', $current->id), $snapshot['memberships'], ['role_id', 'user_id']],
            [DB::table('roles')->whereIn('id', $roleIds), $snapshot['roles'], ['id']],
            [DB::table('role_permission')->whereIn('role_id', $roleIds)->whereIn('permission_id', $permissionIds), $snapshot['grants'], ['role_id', 'permission_id']],
            [DB::table('permission_user')->where('user_id', $current->id)->whereIn('permission_id', $permissionIds), $snapshot['overrides'], ['permission_id', 'user_id']],
            [DB::table('hr_employee_profiles')->where('user_id', $current->id)->whereNull('deleted_at'), $snapshot['profiles'], ['id']],
        ] as [$selector, $rows, $identity]) { $this->expectedSet($source, $selector, $rows, $identity); }
        return $source;
    }

    /** The bounded Site and Client rows for one source. */
    private function authorityScoped(Builder $source, User $current, CurrentAuthorizationReads $reads, ?int $siteId, ?int $clientId): Builder
    {
        $snapshot = $this->authoritySnapshot($current, $reads);
        foreach ([
            [DB::table('sites')->where('id', $siteId), array_values(array_filter($snapshot['sites'], fn ($row) => (int) $row['id'] === $siteId)), ['id']],
            [DB::table('client_user')->where('user_id', $current->id)->where('client_id', $clientId), array_values(array_filter($snapshot['clients'], fn ($row) => (int) $row['client_id'] === $clientId)), ['id']],
        ] as [$selector, $rows, $identity]) { $this->expectedSet($source, $selector, $rows, $identity); }
        return $source;
    }

    private function authoritySnapshot(User $current, CurrentAuthorizationReads $reads): array
    {
        $actors = $this->evidence[$reads] ?? [];
        if (! isset($actors[$current->id])) {
            if (! $current->relationLoaded('roles') || ! $current->relationLoaded('permissionOverrides')
                || ! $current->roles->every(fn ($role) => $role->relationLoaded('permissions'))) {
                throw new LogicException('Incident/HSE source requires prepared canonical authorization evidence.');
            }
            $snapshot = ['users' => [array_intersect_key($current->getRawOriginal(), array_flip(['id', 'role', 'approved_at', 'external_clinical_account']))],
                'definitions' => $reads->query(DB::table('permissions')->whereIn('key', self::PERMISSIONS))->get(['id', 'key'])->map(fn ($row) => (array) $row)->all(),
                'roles' => [], 'memberships' => [], 'grants' => [], 'overrides' => [], 'used_definitions' => []];
            foreach ($current->roles as $role) {
                $snapshot['roles'][] = ['id' => (int) $role->id, 'name' => $role->getRawOriginal('name')];
                $snapshot['memberships'][] = ['role_id' => (int) $role->id, 'user_id' => (int) $current->id];
                foreach ($role->permissions as $permission) {
                    if (in_array($permission->key, self::PERMISSIONS, true)) {
                        $snapshot['grants'][] = ['role_id' => (int) $role->id, 'permission_id' => (int) $permission->id];
                        $snapshot['used_definitions'][$permission->id] = ['id' => (int) $permission->id, 'key' => $permission->key];
                    }
                }
            }
            foreach ($current->permissionOverrides as $permission) {
                if (in_array($permission->key, self::PERMISSIONS, true)) {
                    $snapshot['overrides'][] = ['permission_id' => (int) $permission->id, 'user_id' => (int) $current->id, 'allowed' => (int) (bool) $permission->pivot->allowed];
                    $snapshot['used_definitions'][$permission->id] = ['id' => (int) $permission->id, 'key' => $permission->key];
                }
            }
            $snapshot['profiles'] = $reads->query(DB::table('hr_employee_profiles')->where('user_id', $current->id)->whereNull('deleted_at'))
                ->get(['id', 'user_id', 'primary_site_id', 'secondary_site_ids', 'is_active', 'start_date', 'end_date', 'deleted_at'])->map(fn ($row) => (array) $row)->all();
            // Identity/status facts only. These original sets cover later bounded Sites/Clients without rebaselining changed evidence.
            $snapshot['sites'] = $reads->query(DB::table('sites'))->get(['id', 'is_active', 'archived', 'archived_at', 'deleted_at'])->map(fn ($row) => (array) $row)->all();
            $snapshot['clients'] = $reads->query(DB::table('client_user')->where('user_id', $current->id))->get(['id', 'client_id', 'user_id'])->map(fn ($row) => (array) $row)->all();
            $actors[$current->id] = $snapshot;
            $this->evidence[$reads] = $actors;
        }
        return $actors[$current->id];
    }

    private function expectedSet(Builder $guard, QueryBuilder $selector, array $rows, array $identity): void
    {
        foreach ($rows as $row) { $guard->whereExists($this->exact(clone $selector, $row)->selectRaw('1')); }
        $extra = clone $selector;
        if ($rows !== []) {
            $extra->whereNot(function (QueryBuilder $known) use ($rows, $identity): void {
                foreach ($rows as $row) {
                    $known->orWhere(fn (QueryBuilder $entry) => $this->exact($entry, array_intersect_key($row, array_flip($identity))));
                }
            });
        }
        $guard->whereNotExists($extra->selectRaw('1'));
    }
}
