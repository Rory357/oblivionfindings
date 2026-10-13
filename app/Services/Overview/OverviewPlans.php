<?php

namespace App\Services\Overview;

use App\Domain\It\Services\ItWorkAccessService;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkPlanningRecord;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class OverviewPlans
{
    public function __construct(private readonly OverviewAccess $overview, private readonly UserSiteAccessService $sites) {}

    public function definition(string $kind): array
    {
        $definition = OverviewPlanDefinitions::all()[$kind] ?? null;
        abort_unless($definition, 404);
        $meta = collect($this->overview->catalog($definition['role'])['blocks'])->firstWhere('id', $kind);

        return $definition + ['kind' => $kind, 'title' => $meta['title']];
    }

    public function canRead(User $user, array $definition): bool
    {
        return in_array($definition['role'], $this->overview->roles($user), true)
            && ($definition['read'] === null || $user->canDo($definition['read'])
                || ($definition['role'] === 'it' && $user->canDo('it.manage')));
    }

    public function canWrite(User $user, array $definition): bool
    {
        return $this->canRead($user, $definition)
            && ($definition['write'] === null || $user->canDo($definition['write']));
    }

    public function sites(User $user, array $definition): array
    {
        if ($definition['scope'] !== 'site') {
            return [];
        }
        // Ticket visibility can be personal; it must never grant access to a site's IT plans.
        $ids = $definition['role'] === 'it'
            ? app(ItWorkAccessService::class)->approvedSiteIds($user)
            : $this->overview->siteIds($user, $definition['role']);

        return Site::query()->whereIn('id', $ids)->active()->notArchived()->whereNull('archived_at')
            ->orderBy('name')->get(['id', 'name'])->toArray();
    }

    public function query(User $user, string $kind): Builder
    {
        $definition = $this->definition($kind);
        abort_unless($this->canRead($user, $definition), 403);
        $query = WorkPlanningRecord::query()->where('kind', $kind);

        return match ($definition['scope']) {
            'personal' => $query->where('owner_id', $user->id)->whereNull('site_id'),
            'organisation' => $query->whereNull('site_id'),
            default => $query->whereIn('site_id', array_column($this->sites($user, $definition), 'id')),
        };
    }

    public function owners(User $user, array $definition): array
    {
        if ($definition['scope'] === 'personal') {
            return [['id' => $user->id, 'name' => $user->name, 'site_ids' => []]];
        }
        $query = User::query()->whereNotNull('approved_at');
        if ($definition['scope'] === 'site') {
            $this->sites->applyStaffScope($query, $user, $this->overview->bypass($definition['role']));
        } else {
            $query->whereHas('boardMember', fn ($member) => $member->where('is_active', true));
        }
        // Include self, even if no employee/board profile is linked. Eligibility is still checked below.
        $people = $query->with(['roles.permissions', 'permissionOverrides'])->orderBy('name')->get(['id', 'name', 'role'])->push($user)->unique('id');
        $visibleSiteIds = array_column($this->sites($user, $definition), 'id');

        return $people->filter(fn ($person) => $this->canRead($person, $definition))->map(fn ($person) => [
            'id' => $person->id, 'name' => $person->name,
            'site_ids' => array_values(array_intersect($visibleSiteIds, array_column($this->sites($person, $definition), 'id'))),
        ])->values()->all();
    }

    public function needingAttention(Builder $query): Builder
    {
        $today = now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();

        return $query->whereNotIn('status', ['completed', 'cancelled'])
            ->where(fn ($q) => $q->whereDate('due_date', '<', $today)->orWhere('status', 'blocked'));
    }

    public function attention(User $user, string $kind, array $siteIds): ?array
    {
        $definition = $this->definition($kind);
        if (! $this->canRead($user, $definition)) {
            return null;
        }
        $query = $this->query($user, $kind)->when($definition['scope'] === 'site', fn ($q) => $q->whereIn('site_id', $siteIds));
        $count = $this->needingAttention($query)->count();
        if ($count === 0) {
            return null;
        }
        $filters = ['status' => 'attention'];
        if ($definition['scope'] === 'site' && count($siteIds) === 1) {
            $filters['site'] = $siteIds[0];
        }

        return ['label' => $definition['title'], 'value' => $count,
            'href' => '/work-plans/'.$kind.'?'.http_build_query($filters),
            'detail' => 'Overdue or blocked plans · review the next step'];
    }

    public function summary(User $user, string $kind, array $siteIds): array
    {
        $definition = $this->definition($kind);
        if (! $this->canRead($user, $definition)) {
            return ['state' => 'restricted', 'subtitle' => 'Permission required', 'message' => 'Your permissions do not include these plans.', 'rows' => [], 'series' => [], 'total' => null];
        }
        $query = $this->query($user, $kind)->when($definition['scope'] === 'site', fn ($q) => $q->whereIn('site_id', $siteIds));
        $open = (clone $query)->whereNotIn('status', ['completed', 'cancelled']);
        $href = '/work-plans/'.$kind.($definition['scope'] === 'site' && count($siteIds) === 1 ? '?site='.$siteIds[0] : '');
        $today = now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();
        $rows = (clone $open)->orderBy('due_date')->limit(6)->get()->map(fn ($plan) => [
            'label' => $plan->title,
            'detail' => 'Recorded by your team', 'date' => $plan->due_date->toDateString(),
            'value' => $plan->progress, 'target' => 100,
            'status' => $plan->due_date->toDateString() < $today ? 'Overdue' : Str::headline($plan->status),
            'tone' => $plan->status === 'blocked' || $plan->due_date->toDateString() < $today ? 'warning' : 'neutral',
            'href' => '/work-plans/'.$kind.'?record='.$plan->id,
        ])->all();

        $meta = collect($this->overview->catalog($definition['role'])['blocks'])->firstWhere('id', $kind);
        if ($meta['kind'] === 'donut') {
            $rows = (clone $open)->select('status')->selectRaw('COUNT(*) as total')->groupBy('status')->get()->map(fn ($group) => [
                'label' => Str::headline($group->status), 'value' => (int) $group->total, 'href' => $href,
            ])->all();
        }
        if ($kind === 'it-licences') {
            $allocations = (clone $query)->where('status', '!=', 'cancelled');
            $rows = (clone $allocations)->orderBy('due_date')->limit(6)->get()->map(fn ($plan) => [
                'label' => $plan->details['product'] ?? $plan->title,
                'value' => (int) ($plan->details['assigned'] ?? 0), 'target' => (int) ($plan->details['entitled'] ?? 0),
                'status' => (int) ($plan->details['assigned'] ?? 0) > (int) ($plan->details['entitled'] ?? 0) ? 'Over allocation' : 'Recorded allocation',
                'tone' => (int) ($plan->details['assigned'] ?? 0) > (int) ($plan->details['entitled'] ?? 0) ? 'warning' : 'neutral',
                'detail' => 'Assigned seats / seats allocated to this site', 'href' => '/work-plans/'.$kind.'?record='.$plan->id,
            ])->all();

            return ['state' => 'ready', 'scopeMode' => 'site', 'subtitle' => 'Recorded allocation reviews · selected sites', 'presentation' => 'progress-rows',
                'href' => $href.(str_contains($href, '?') ? '&' : '?').'status=all', 'actionLabel' => 'Review allocations', 'rows' => $rows, 'series' => [], 'total' => $allocations->count(),
                'footnote' => 'Staff-recorded seats per review · cancelled reviews excluded · not automatic software discovery'];
        }

        return ['state' => 'ready', 'scopeMode' => $definition['scope'], 'subtitle' => 'Recorded plans · open work, including overdue items',
            'presentation' => in_array($meta['kind'], ['bars', 'progress'], true) ? 'progress-rows' : null,
            'href' => $href, 'actionLabel' => $this->canWrite($user, $definition) ? 'Manage plans' : 'View plans',
            'empty' => 'No open plans. Open this workspace to see completed work or add a plan.',
            'rows' => $rows, 'series' => [], 'total' => $open->count(),
            'footnote' => 'Staff-maintained records · completion is not an automated readiness check'];
    }
}
