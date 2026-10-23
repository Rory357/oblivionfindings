<?php

namespace App\Services\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Services\CurrentAuthorizationReads;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use LogicException;
use WeakMap;

/** Canonical Client decisions precede identifiable Overview options, in one original read. */
final class DashboardClientOptionsReadService
{
    public const PERMISSIONS = ['clients.viewAny', 'clients.viewAssigned', 'clinical.accessAllSites', 'sites.viewAll', 'healthSafety.viewAllSites'];

    private WeakMap $prepared;

    public function __construct(private readonly UserSiteAccessService $sites, private readonly ClientPolicy $policy)
    {
        $this->prepared = new WeakMap;
    }

    /** The controller supplies its existing canonical Site tuple; this owner does not choose one. */
    public function prepare(HealthSafetyReadContext $context, ?int $selectedSite, array $siteIds): void
    {
        $context->assertActive();
        $proof = HealthSafetyReadMaterialisationProof::current($context);
        if ($proof === null) { throw new LogicException('Client options require the original Overview final proof.'); }
        $siteIds = array_values(array_unique(array_map('intval', $siteIds)));
        sort($siteIds);
        if (isset($this->prepared[$context])) {
            $original = $this->prepared[$context];
            if ($original['proof'] !== $proof || $original['selected'] !== $selectedSite || $original['sites'] !== $siteIds) {
                throw new LogicException('Client options cannot replace their original canonical Site tuple.');
            }
            return;
        }
        if ($selectedSite !== null && $siteIds !== [$selectedSite]) {
            throw new LogicException('Client options require the controller canonical selected Site tuple.');
        }
        $context->consumeSourceViewer(self::PERMISSIONS, function (User $current, CurrentAuthorizationReads $reads) use ($context, $selectedSite, $siteIds, $proof): void {
            abort_unless($current->isApproved(), 403);
            $authority = $context->originalSourceAuthorityQuery(self::PERMISSIONS, $siteIds);
            abort_unless($proof->protect(clone $authority)->get(['users.id'])->count() === 1, 404);
            // Keep the prepared canonical actor. Site/Client policy may reload its HR relation,
            // but cannot replace this callback's original role and permission evidence.
            $actor = clone $current;
            $actor->setRelation('roles', $current->roles->map(function ($role) {
                $copy = clone $role;
                return $copy->setRelation('permissions', $role->permissions->map(fn ($permission) => clone $permission));
            }));
            $actor->setRelation('permissionOverrides', $current->permissionOverrides->map(function ($permission) {
                $copy = clone $permission;
                return $copy->setRelation('pivot', clone $permission->pivot);
            }));
            // This is only the historical-existence operand used by User's canonical
            // portal arm. It grants nothing and imposes no current-employment rule.
            if ($actor->exists && ! $actor->isExternalClinicianAccount() && $actor->hasRole('client', 'next_of_kin')) {
                $present = $proof->protect($reads->query(HrEmployeeProfile::withTrashed()->where('user_id', $actor->id)))->exists();
                $historical = DB::table('hr_employee_profiles')->where('user_id', $actor->id)->selectRaw('1');
                $present ? $authority->whereExists($historical) : $authority->whereNotExists($historical);
            }
            $audience = $this->sites->accessibleSiteIds($actor, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS, $reads);
            abort_unless($siteIds !== [] && array_diff($siteIds, $audience) === [], 403);
            $siteRows = $proof->protect($reads->query(Site::query()->active()->notArchived()->whereNull('archived_at')->whereIn('id', $siteIds)))
                ->get(['id', 'is_active', 'archived', 'archived_at', 'deleted_at']);
            abort_unless($siteRows->count() === count($siteIds), 404);
            foreach ($siteRows as $site) {
                $authority->whereExists($this->exact(DB::table('sites'), $site->getRawOriginal())->selectRaw('1'));
            }
            // Register the complete witness, including historical absence and consumed Sites.
            abort_unless($proof->protect(clone $authority)->get(['users.id'])->count() === 1, 404);
            $this->prepared[$context] = ['proof' => $proof, 'selected' => $selectedSite, 'sites' => $siteIds, 'actor' => $actor, 'authority' => $authority];
        });
    }

    /** Arrays discover subjects; the retained original SQL proves admission on the actual name SELECT. */
    public function options(HealthSafetyReadContext $context, ?int $selectedSite): array
    {
        $context->assertActive();
        $original = $this->prepared[$context] ?? null;
        if ($original === null || $original['selected'] !== $selectedSite) {
            throw new LogicException('Client options require their original prepared Site tuple.');
        }
        $proof = HealthSafetyReadMaterialisationProof::current($context);
        if ($proof === null || $original['proof'] !== $proof) { throw new LogicException('Client options left their original final proof.'); }
        return $context->consumeSourceViewer(self::PERMISSIONS, function ($current, CurrentAuthorizationReads $reads) use ($original, $context, $proof): array {
            $context->assertViewer($original['actor']);
            $authority = clone $original['authority'];
            abort_unless((clone $authority)->exists(), 404);
            $discovery = $reads->query(Client::query()->whereIn('site_id', $original['sites'])
                ->whereExists((clone $authority)->selectRaw('1')->toBase())->orderBy('id'));
            $identities = $proof->protect($discovery)->get(['id', 'site_id']);
            $admitted = $identities->filter(fn (Client $client): bool => $this->policy->viewFromCurrentEvidence($original['actor'], $client, $reads));
            abort_unless((clone $authority)->exists(), 404);
            if ($admitted->isEmpty()) { return []; }
            $names = $reads->query(Client::query()->whereExists((clone $authority)->selectRaw('1')->toBase())
                ->where(function (Builder $allowed) use ($admitted): void {
                    foreach ($admitted as $client) {
                        $allowed->orWhere(fn (Builder $tuple) => $tuple->where('clients.id', $client->id)->where('clients.site_id', $client->site_id));
                    }
                })->whereHas('site', fn (Builder $site) => $site->active()->notArchived()->whereNull('archived_at'))
                ->orderBy('first_name')->orderBy('id'));
            $rows = $proof->protect($names)->get(['id', 'first_name', 'last_name', 'site_id']);
            abort_unless($rows->count() === $admitted->count(), 404);
            return $rows->map(fn (Client $client): array => ['id' => (int) $client->id, 'first_name' => $client->first_name,
                'last_name' => $client->last_name, 'site_id' => (int) $client->site_id])->values()->all();
        });
    }

    private function exact(QueryBuilder $query, array $values): QueryBuilder
    {
        foreach ($values as $column => $value) {
            if ($value === null) { $query->whereNull($column); }
            elseif (is_string($value)) { $query->whereRaw('BINARY '.$query->getGrammar()->wrap($column).' = BINARY ?', [$value]); }
            else { $query->where($column, $value); }
        }
        return $query;
    }
}
