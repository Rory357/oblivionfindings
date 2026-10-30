<?php

namespace App\Services\Overview;

use App\Domain\It\Services\ItWorkAccessService;
use App\Models\ItTicket;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;

final class OverviewAccess
{
    public const ROLES = ['worker', 'lead', 'manager', 'hr', 'it', 'governance', 'office'];

    public function roles(User $user): array
    {
        $user->loadMissing(['roles.permissions', 'permissionOverrides']);
        if ($user->hasRole('client', 'next_of_kin', 'external_clinician') || $user->isExternalClinicianAccount()) {
            return [];
        }
        $roles = ['worker'];
        if ($user->hasRole('admin', 'team_lead', 'coordinator', 'clinical_lead')
            || $user->canDo('shifts.manageAny')) {
            $roles[] = 'lead';
        }
        if ($user->hasRole('admin', 'provider_manager', 'ceo', 'coo')
            || ($user->canDo('operations.dashboard.view') && ! $user->hasRole('team_lead'))) {
            $roles[] = 'manager';
        }
        // HR staff always get the HR overview (each block still checks its own
        // permission); others need the workforce analytics permission.
        if ($user->hasRole('hr') || $user->canDo('hr.analytics.view')) {
            $roles[] = 'hr';
        }
        if ($user->canDo('it.view') || $user->canDo('it.manage')) {
            $roles[] = 'it';
        }
        if ($user->canDo('governance.view')) {
            $roles[] = 'governance';
        }
        // Business support: back-office roles, and anyone who reads finance,
        // compliance, audit or risk records. A frontline fleet or hazard
        // permission alone (support workers, team leads) does not add it.
        if ($user->hasRole(...OverviewOfficeBlocks::ROLES) || $user->canDo('finance.dashboard') || $user->canDo('compliance.view')
            || $user->canDo('audit.viewAny') || $user->canDo('governance.risks.view')) {
            $roles[] = 'office';
        }

        return $roles;
    }

    public function defaultRole(User $user): string
    {
        $available = $this->roles($user);
        $preferred = $user->uiPreferences()->where('key', 'overview.default-role')->value('value');
        if (is_array($preferred) && in_array($preferred['role'] ?? null, $available, true)) {
            return $preferred['role'];
        }
        $role = match (true) {
            // A CEO without the operations dashboard would open on an overview of
            // "Permission required" cards; their board workspace is the useful one.
            $user->hasRole('ceo') && ! $user->hasRole('admin', 'provider_manager') && ! $user->canDo('operations.dashboard.view')
                && $user->canDo('governance.view') => 'governance',
            $user->hasRole('admin', 'provider_manager', 'ceo', 'coo') => 'manager',
            $user->hasRole('hr') => 'hr',
            $user->hasRole('it_manager') => 'it',
            $user->hasRole('board_chair', 'board_member', 'board_secretary', 'board_observer', 'board_trustee') => 'governance',
            $user->hasRole('team_lead', 'coordinator', 'clinical_lead') => 'lead',
            $user->hasRole(...OverviewOfficeBlocks::ROLES) => 'office',
            default => 'worker',
        };

        return in_array($role, $available, true) ? $role : ($available[0] ?? 'worker');
    }

    public function authorize(User $user, string $role): void
    {
        abort_unless(in_array($role, $this->roles($user), true), 403);
    }

    public function bypass(string $role): array
    {
        return match ($role) {
            'lead', 'manager' => ['operations.dashboard.viewAllSites'],
            'hr' => UserSiteAccessService::HR_EMPLOYEE_SITE_BYPASS_PERMISSIONS,
            // Each Business support block also applies its own module's Site rule.
            'office' => ['sites.viewAll', 'healthSafety.viewAllSites'],
            default => [],
        };
    }

    /**
     * The blocks a role opens with before the person saves a layout. Business
     * support picks from the areas the person can see; the others use the
     * first six blocks of their catalog.
     *
     * @return list<string>
     */
    public function defaultBlockIds(User $user, string $role): array
    {
        return $role === 'office'
            ? app(OverviewOfficeBlocks::class)->defaultBlockIds($user)
            : array_column(array_slice($this->catalog($role)['blocks'], 0, 6), 'id');
    }

    public function siteIds(User $user, string $role): array
    {
        if ($role === 'it') {
            $access = app(ItWorkAccessService::class);

            return collect($access->approvedSiteIds($user))
                ->merge($access->applyViewScope(ItTicket::query(), $user)->whereNotNull('site_id')->distinct()->pluck('site_id'))
                ->unique()->map(fn ($id) => (int) $id)->values()->all();
        }

        return app(UserSiteAccessService::class)->accessibleSiteIds($user, $this->bypass($role));
    }

    public function sites(User $user, string $role): array
    {
        return Site::query()->whereIn('id', $this->siteIds($user, $role))->active()->notArchived()
            ->whereNull('archived_at')->orderBy('name')->get(['id', 'name'])->toArray();
    }

    /** @var array<string, array>|null Parsed once per request; it is read for every block. */
    private static ?array $catalog = null;

    public function catalog(string $role): array
    {
        self::$catalog ??= json_decode(file_get_contents(resource_path('js/features/overview/catalog.json')), true, flags: JSON_THROW_ON_ERROR);

        return self::$catalog[$role];
    }

    public function preference(User $user, string $role): array
    {
        $raw = $user->uiPreferences()->where('key', 'overview.workspace.'.$role)->value('value');
        // The generic preference endpoint is also user writable. Never trust it as authorization.
        $value = is_array($raw) ? $raw : [];
        $ids = array_column($this->catalog($role)['blocks'], 'id');
        $sites = ['page', 'all', ...array_map('strval', $this->siteIds($user, $role))];
        $cleanBlocks = static function ($blocks) use ($ids, $sites): array {
            $result = [];
            foreach (is_array($blocks) ? $blocks : [] as $block) {
                if (! is_array($block) || ! in_array($block['id'] ?? null, $ids, true) || isset($result[$block['id']])) {
                    continue;
                }
                $result[$block['id']] = [
                    'id' => $block['id'], 'span' => in_array($block['span'] ?? null, [4, 6, 8, 12], true) ? $block['span'] : 6,
                    'height' => max(0, min(720, (int) ($block['height'] ?? 0))),
                    'site' => in_array($block['site'] ?? null, $sites, true) ? $block['site'] : 'page',
                ];
            }

            return array_values($result);
        };
        $copies = [];
        foreach (array_slice(is_array($value['copies'] ?? null) ? $value['copies'] : [], 0, 3) as $copy) {
            if (! is_array($copy) || ! is_string($copy['name'] ?? null)) {
                continue;
            }
            $copies[] = ['name' => mb_substr($copy['name'], 0, 32), 'blocks' => $cleanBlocks($copy['blocks'] ?? []), 'density' => ($copy['density'] ?? '') === 'compact' ? 'compact' : 'balanced'];
        }

        return [
            'value' => [
                'blocks' => array_key_exists('blocks', $value) ? $cleanBlocks($value['blocks']) : null,
                'favorites' => array_values(array_intersect($ids, array_filter(is_array($value['favorites'] ?? null) ? $value['favorites'] : [], 'is_string'))),
                'copies' => $copies,
                'density' => ($value['density'] ?? '') === 'compact' ? 'compact' : 'balanced',
                'range' => in_array($value['range'] ?? null, ['today', 'week', 'month', 'quarter'], true) ? $value['range'] : $this->catalog($role)['range'],
            ],
            'revision' => $raw === null ? null : hash('sha256', json_encode($raw)),
        ];
    }
}
