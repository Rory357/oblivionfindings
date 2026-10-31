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

    /** @var \WeakMap<CurrentAuthorizationReads, array<string, bool>>|null answers per read lifetime (speed, 30 Oct) */
    private static ?\WeakMap $answers = null;

    /** Within one page-wide locked read (All Tasks, the Overview) the same question has the same answer: canAssign and
     * the owner summary both asked it. Every other read decides afresh at each call. */
    private function remember(?CurrentAuthorizationReads $reads, string $key, callable $decide): bool
    {
        if (! IncidentHsEventSourceReadService::isPageWide($reads)) { return $decide(); }
        $reads->assertActive();
        self::$answers ??= new \WeakMap;
        $known = self::$answers[$reads] ?? [];
        if (! array_key_exists($key, $known)) {
            $answer = (bool) $decide();
            // Read the answers again: a nested question (canManage asks activeInternal) stored its own meanwhile,
            // and writing back the copy from before would drop it (31 Oct: activeInternal was decided per incident).
            $known = self::$answers[$reads] ?? [];
            $known[$key] = $answer;
            self::$answers[$reads] = $known;
        }

        return $known[$key];
    }

    public function canManage(User $actor, ClientIncident $incident, ?CurrentAuthorizationReads $reads = null): bool
    {
        return $this->remember($reads, 'manage|'.$actor->id.'|'.$incident->id, fn (): bool => $this->decideCanManage($actor, $incident, $reads));
    }

    private function decideCanManage(User $actor, ClientIncident $incident, ?CurrentAuthorizationReads $reads): bool
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
        return $this->remember($reads, 'active|'.$user->id, fn (): bool => $this->decideActiveInternal($user, $reads));
    }

    private function decideActiveInternal(User $user, ?CurrentAuthorizationReads $reads): bool
    {
        $account = User::query()->staff()->whereKey($user->id)->whereNotNull('approved_at');
        if (! ($reads ? $reads->query($account) : $account)->exists()) {
            return false;
        }
        $today = now(\App\Support\Incidents\IncidentOccurrencePeriod::timezone())->toDateString();
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
