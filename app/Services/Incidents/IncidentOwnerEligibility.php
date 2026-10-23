<?php

namespace App\Services\Incidents;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ClientIncident;
use App\Models\SafeguardingConcern;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Support\Facades\DB;

final class IncidentOwnerEligibility
{
    public const PERMISSIONS = [
        'incidents.owner.assign', 'incidents.owner.hold', 'incidents.owner.holdRestricted',
        'incidents.owner.request', 'incidents.viewAny', 'incidents.viewAssigned',
        'healthSafety.viewAllSites', 'hr.cases.view',
    ];

    public function __construct(private readonly IncidentAccessService $access) {}

    public function canAssign(User $actor, ClientIncident $incident, ?CurrentAuthorizationReads $reads = null): bool
    {
        return $incident->status !== 'closed'
            && $this->canManage($actor, $incident, $reads);
    }

    public function canManage(User $actor, ClientIncident $incident, ?CurrentAuthorizationReads $reads = null): bool
    {
        return $actor->canDo('incidents.owner.assign')
            && $this->activeInternal($actor, $reads)
            && $this->access->canView($actor, $incident, $reads)
            && ! $this->hasConflict($actor, $incident, $reads);
    }

    public function canOwn(User $candidate, ClientIncident $incident, ?CurrentAuthorizationReads $reads = null): bool
    {
        return $candidate->canDo('incidents.owner.hold')
            && $this->activeInternal($candidate, $reads)
            && $this->access->canView($candidate, $incident, $reads)
            && ! $this->hasConflict($candidate, $incident, $reads)
            && (! $this->isRestricted($incident, $reads) || $candidate->canDo('incidents.owner.holdRestricted'));
    }

    public function activeInternal(User $user, ?CurrentAuthorizationReads $reads = null): bool
    {
        $account = User::query()->staff()->whereKey($user->id)->whereNotNull('approved_at');
        if (! ($reads ? $reads->query($account) : $account)->exists()) {
            return false;
        }
        $today = now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();
        $profile = HrEmployeeProfile::query()->where('user_id', $user->id)->where('is_active', true)
            ->where(fn ($dates) => $dates->whereNull('start_date')->orWhereDate('start_date', '<=', $today))
            ->where(fn ($dates) => $dates->whereNull('end_date')->orWhereDate('end_date', '>=', $today));

        return ($reads ? $reads->query($profile) : $profile)->exists();
    }

    public function isRestricted(ClientIncident $incident, ?CurrentAuthorizationReads $reads = null): bool
    {
        if ($incident->type === 'safeguarding' || $incident->is_hr_confidential) {
            return true;
        }
        // Include retained concerns: closing/soft-deleting a concern is not a
        // decision that removes an ownership conflict or specialist requirement.
        $concern = SafeguardingConcern::withTrashed()->where('related_incident_id', $incident->id);
        $exclusion = DB::table('incident_owner_exclusions')->where('incident_id', $incident->id);

        return ($reads ? $reads->query($concern) : $concern)->exists()
            || ($reads ? $reads->query($exclusion) : $exclusion)->exists();
    }

    private function hasConflict(User $user, ClientIncident $incident, ?CurrentAuthorizationReads $reads): bool
    {
        $exclusion = DB::table('incident_owner_exclusions')->where('incident_id', $incident->id)->where('user_id', $user->id);
        $concern = SafeguardingConcern::withTrashed()->where('related_incident_id', $incident->id)
            ->whereIn('alleged_perpetrator_type', [User::class, $user->getMorphClass(), 'staff'])
            ->where('alleged_perpetrator_id', $user->id);

        return ($reads ? $reads->query($exclusion) : $exclusion)->exists()
            || ($reads ? $reads->query($concern) : $concern)->exists();
    }
}
