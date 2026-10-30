<?php

namespace App\Services\Incidents;

use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\Client;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\UserSiteAccessService;

/** Current intake context only. It never grants saved incident, HR or ownership access. */
final class IncidentIndependenceCaptureAccess
{
    public const PERMISSIONS = ['incidents.create', 'incidents.viewAny', 'incidents.viewAssigned', 'healthSafety.viewAllSites', 'reports.viewAny',
        'clients.viewAny', 'clients.viewAssigned', 'clinical.accessAllSites', 'sites.viewAll', 'hr.cases.view'];
    public const SITE_BYPASS = ['healthSafety.viewAllSites', 'reports.viewAny'];

    public function __construct(private readonly AuthorizationEvidenceLockService $authorization, private readonly UserSiteAccessService $sites,
        private readonly IncidentOwnerEligibility $eligibility, private readonly HrCurrentStaffService $staff) {}

    public function current(User $actor, CurrentAuthorizationReads $reads): User
    {
        $reads->assertActive();
        $current = $this->authorization->lockForUserWithoutWaiting($actor, self::PERMISSIONS);
        abort_unless($current->canDo('incidents.create') && $this->eligibility->activeInternal($current, $reads), 403);
        return $current;
    }

    public function assertContext(User $current, array $context, CurrentAuthorizationReads $reads): void
    {
        $reads->assertActive();
        $siteIds = $this->sites->accessibleSiteIds($current, self::SITE_BYPASS, $reads);
        abort_unless(in_array($context['site_id'], $siteIds, true), 404);
        $reads->query(Site::query()->whereKey($context['site_id'])->where('is_active', true))->firstOrFail(['id']);
        $client = $reads->query(Client::query()->whereKey($context['client_id']))->firstOrFail(['id', 'site_id']);
        abort_unless((int) $client->site_id === $context['site_id'], 404);
        abort_unless(app(ClientPolicy::class)->viewFromCurrentEvidence($current, $client, $reads) || $current->canDo('healthSafety.viewAllSites'), 403);
        if ($context['shift_id'] !== null) {
            $shift = $reads->query(Shift::query()->whereKey($context['shift_id'])->where('client_id', $client->id)->where('user_id', $current->id))->firstOrFail(['id', 'client_id', 'user_id', 'site_id']);
            abort_unless(($shift->site_id === null ? (int) $client->site_id : (int) $shift->site_id) === $context['site_id'], 404);
        }
    }

    /** Exact permitted roster IDs first; names are loaded only by the caller after this admission. */
    public function rosterIds(User $current, array $context, CurrentAuthorizationReads $reads, string $search = '', ?int $selectedId = null): array
    {
        $this->assertContext($current, $context, $reads);
        abort_unless(mb_strlen($search) <= 100 && ($selectedId === null || ($selectedId > 0 && $selectedId <= 2147483647)), 422);
        $references = app(IncidentRoutingSettings::class)->ordinaryCaptureReferenceIds($current, $context['site_id'], $reads);
        $query = $this->staff->currentUsersQuery()->where(function ($roster) use ($context, $references): void {
            $roster->whereHas('hrEmployeeProfile', fn ($profile) => $profile->where(fn ($site) =>
                $site->where('primary_site_id', $context['site_id'])->orWhereJsonContains('secondary_site_ids', $context['site_id'])));
            if ($references !== []) { $roster->orWhereIn('users.id', $references); }
        });
        if ($selectedId !== null) { $query->whereKey($selectedId); }
        if ($search !== '') { $query->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'); }
        return $reads->query($query->orderBy('id')->limit(30))->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function assertNamed(User $current, array $context, array $ids, CurrentAuthorizationReads $reads): void
    {
        foreach ($ids as $id) {
            if ($this->rosterIds($current, $context, $reads, '', $id) !== [$id]) {
                IncidentIndependenceValues::fail('independent_handling.involved_user_ids', 'A selected person is no longer available in this report Site. Preserve the draft and review the selection, or record unknown.');
            }
        }
    }
}
