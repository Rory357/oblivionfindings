<?php

namespace App\Services\Safeguarding;

use App\Models\Client;
use App\Models\SafeguardingConcern;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Unmounted reader proposal matching SafeguardingConcernPolicy, without a new sensitive count gate. */
final class SafeguardingConcernReadScope
{
    public const PERMISSIONS = ['safeguarding.viewAny', 'reports.viewAny'];
    public const DISPLAY_PERMISSIONS = ['safeguarding.viewSensitive', 'safeguarding.declassification.approve',
        'safeguarding.create', 'safeguarding.update', 'safeguarding.investigate', 'safeguarding.report.external',
        'incidents.viewAny', 'incidents.viewAssigned'];
    public const FILTER_KEYS = ['q', 'tab', 'severity', 'category', 'site_id', 'subject_id', 'from', 'to'];

    private ?CurrentAuthorizationReads $activeReads = null;
    private ?object $invocation = null;
    private ?int $actorObject = null;
    private array $sites = [];
    private bool $globalSites = false;
    private bool $registerRead = false;

    public function __construct(private readonly UserSiteAccessService $siteAccess) {}

    public function withCurrentViewer(User $viewer, callable $operation, array $displayPermissionKeys = []): mixed
    {
        if (array_diff($displayPermissionKeys, self::DISPLAY_PERMISSIONS) !== []) {
            throw new LogicException('Only existing Safeguarding display capabilities may be prepared.');
        }
        return DB::transaction(fn () => CurrentAuthorizationReads::within(function ($reads) use ($viewer, $operation, $displayPermissionKeys) {
            $current = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($viewer,
                array_values(array_unique([...self::PERMISSIONS, ...$displayPermissionKeys])));
            return $this->consume($current, $reads, $operation);
        }));
    }

    public function withReadContext(User $viewer, HealthSafetyReadContext $context, callable $operation): mixed
    {
        $context->assertViewer($viewer);
        return $context->consumeSourceViewer(self::PERMISSIONS, fn ($current, $reads) => $this->consume($current, $reads, $operation));
    }

    private function consume(User $current, CurrentAuthorizationReads $reads, callable $operation): mixed
    {
        $reads->assertActive();
        if ($this->activeReads !== null) { throw new LogicException('Safeguarding read consumers cannot nest.'); }
        abort_unless($current->approved_at !== null && ! $current->isExternalClinicianAccount(), 403);
        $this->activeReads = $reads; $this->invocation = new \stdClass; $this->actorObject = spl_object_id($current);
        try {
            $this->globalSites = $current->canDo('reports.viewAny');
            $this->registerRead = $current->canDo('safeguarding.viewAny');
            $this->sites = $this->siteAccess->accessibleSiteIds($current, [], $reads);
            $result = $operation($current, $reads); $reads->assertActive(); $this->detached($result);
            return $result;
        } finally {
            $this->activeReads = null; $this->invocation = null; $this->actorObject = null; $this->sites = []; $this->globalSites = $this->registerRead = false;
        }
    }

    /** Exact current counterpart of policy.applyVisibleScope; organisation-null Site remains visible. */
    private function siteScope(Builder $query): Builder
    {
        if (! $this->globalSites) {
            $query->where(function (Builder $scope) {
                $scope->whereNull('site_id');
                if ($this->sites !== []) { $scope->orWhereIn('site_id', $this->sites); }
            });
        }
        return $query;
    }

    /** Register authority is independent of direct reporter/assignee admission. */
    public function queryForCurrentReads(User $current, CurrentAuthorizationReads $reads, ?array $selectedSites = null): Builder
    {
        $this->lease($reads, $current); abort_unless($current->canDo('safeguarding.viewAny'), 403);
        $query = $this->siteScope($reads->query(SafeguardingConcern::query()));
        if ($selectedSites !== null) { $query->whereIn('site_id', $this->selectedSites($selectedSites)); }
        return $this->bind($query, $reads);
    }

    /** A canonical model is internal to the active projection; it cannot be returned by the consumer. */
    public function concernForCurrentReads(int $id, User $current, CurrentAuthorizationReads $reads): SafeguardingConcern
    {
        $this->lease($reads, $current);
        $query = $this->siteScope($reads->query(SafeguardingConcern::query()))->whereKey($id);
        if (! $current->canDo('safeguarding.viewAny')) {
            $query->where(fn (Builder $own) => $own->where('reported_by_user_id', $current->id)->orWhere('assigned_to_user_id', $current->id));
        }
        return $this->bind($query, $reads)->firstOrFail();
    }

    /** Same footer filter meanings, including inclusive civil reported_at dates and private search masking behavior. */
    public function applyFiltersForCurrentReads(Builder $query, array $filters, CurrentAuthorizationReads $reads): Builder
    {
        $this->lease($reads);
        abort_unless($this->registerRead, 403);
        if (! ($query->getModel() instanceof SafeguardingConcern)) { throw new LogicException('Safeguarding filters require the canonical concern source.'); }
        if (array_diff(array_keys($filters), self::FILTER_KEYS) !== []) { $this->invalid('filters'); }
        foreach ($filters as $key => $value) { if (is_array($value) || is_object($value)) { $this->invalid($key); } }
        $query = $this->siteScope($reads->query($query));
        foreach (['severity' => 'severity', 'category' => 'abuse_category', 'site_id' => 'site_id'] as $key => $column) {
            if (! empty($filters[$key])) { $query->where($column, $filters[$key]); }
        }
        if (! empty($filters['subject_id'])) { $query->where('subject_type', Client::class)->where('subject_id', $filters['subject_id']); }
        if (! empty($filters['from'])) { $query->whereDate('reported_at', '>=', $filters['from']); }
        if (! empty($filters['to'])) { $query->whereDate('reported_at', '<=', $filters['to']); }
        if (! empty($filters['q'])) {
            $term = $filters['q'];
            $query->where(fn (Builder $search) => $search->where('reference_number', 'like', "%{$term}%")
                ->orWhere('description', 'like', "%{$term}%")->orWhere('subject_name', 'like', "%{$term}%"));
        }
        return $this->bind($query, $reads);
    }

    public function openCount(User $viewer, ?array $selectedSites = null): array
    {
        return $this->restricted(fn () => $this->withCurrentViewer($viewer,
            fn ($current, $reads) => $this->countInReads($current, $reads, $selectedSites)));
    }

    public function openCountInContext(User $viewer, HealthSafetyReadContext $context, ?array $selectedSites = null): array
    {
        return $this->restricted(fn () => $this->withReadContext($viewer, $context,
            fn ($current, $reads) => $this->countInReads($current, $reads, $selectedSites)));
    }

    private function countInReads(User $current, CurrentAuthorizationReads $reads, ?array $selectedSites): array
    {
        $this->lease($reads, $current); abort_unless($current->canDo('safeguarding.viewAny'), 403);
        $selected = $selectedSites === null ? null : $this->selectedSites($selectedSites);
        if ($selected === []) { return ['access' => 'restricted', 'count' => null, 'href' => null]; }
        $count = $this->queryForCurrentReads($current, $reads, $selected)->open()->count();
        // Existing register has only one Site filter. Never pretend a broader register equals a selected set.
        $href = $selected === null ? '/safeguarding?tab=open'
            : (count($selected) === 1 ? '/safeguarding?tab=open&site_id='.$selected[0] : null);
        return ['access' => 'readable', 'count' => $count, 'href' => $href];
    }

    private function selectedSites(array $selected): array
    {
        foreach ($selected as $id) { if (! is_int($id) || $id < 1) { $this->invalid('site_id'); } }
        $selected = array_values(array_unique($selected));
        return $this->globalSites ? $selected : array_values(array_intersect($this->sites, $selected));
    }

    private function restricted(callable $operation): array
    {
        try { return $operation(); }
        catch (HttpExceptionInterface $error) {
            if (! in_array($error->getStatusCode(), [403, 404], true)) { throw $error; }
            return ['access' => 'restricted', 'count' => null, 'href' => null];
        }
    }

    private function lease(CurrentAuthorizationReads $reads, ?User $current = null): void
    {
        $reads->assertActive();
        if ($reads !== $this->activeReads || ($current !== null && spl_object_id($current) !== $this->actorObject)) {
            throw new LogicException('Safeguarding source reads require their exact active current-reader invocation.');
        }
    }

    private function bind(Builder $query, CurrentAuthorizationReads $reads): Builder
    {
        $invocation = $this->invocation;
        $query->getQuery()->beforeQuery(function () use ($reads, $invocation) {
            $this->lease($reads);
            if ($invocation === null || $invocation !== $this->invocation) { throw new LogicException('Safeguarding source query escaped its projection.'); }
        });
        return $query;
    }

    private function invalid(string $field): never { throw ValidationException::withMessages([$field => 'Use one supported Safeguarding filter value or positive integer Site identity.']); }
    private function detached(mixed $value): void
    {
        if (is_array($value)) { foreach ($value as $item) { $this->detached($item); } return; }
        if ($value !== null && ! is_scalar($value)) { throw new LogicException('Safeguarding read consumers must return detached scalar data only.'); }
    }
}
