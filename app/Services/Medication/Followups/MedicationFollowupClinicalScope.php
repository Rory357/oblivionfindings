<?php

namespace App\Services\Medication\Followups;

use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Exceptions\MedicationEmergencyAccessEnded;
use App\Models\Client;
use App\Models\ClientBreakGlassAccess;
use App\Models\ClientMedication;
use App\Models\Shift;
use App\Models\User;
use App\Services\MarScheduleService;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Follow-up authority never accepts a historical or expired offline grant. */
final class MedicationFollowupClinicalScope
{
    public function __construct(
        private readonly UserSiteAccessService $sites,
        private readonly HrCurrentStaffService $staff,
        private readonly MarScheduleService $schedule,
    ) {}

    /** Caller holds Client/medicine/source/Shift locks, before taking User locks. */
    public function lockLatestGrant(User $actor, Client $client): ?ClientBreakGlassAccess
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Resolve emergency access inside the clinical transaction.');
        }

        return ClientBreakGlassAccess::withTrashed()->where('client_id', $client->id)
            ->where('user_id', $actor->id)->where('created_at', '<=', now())
            ->orderByDesc('created_at')->orderByDesc('id')->lockForUpdate()->first();
    }

    /** Current actor and co-signer authorization must already be locked together. */
    public function assertGrant(User $actor, Client $client, ?ClientBreakGlassAccess $grant, Collection $users): ClientBreakGlassAccess
    {
        abort_unless($grant && $this->canonicalGrant($actor, $client, $grant, $users->get((int) $grant->co_signed_by)), 404);
        if (! $grant->isRunning()) {
            throw new MedicationEmergencyAccessEnded;
        }

        return $grant;
    }

    /** Read-only action hint; transition resolves and rechecks under canonical locks. */
    public function hasCurrentGrant(User $actor, Client $client): bool
    {
        $grant = ClientBreakGlassAccess::query()->where('client_id', $client->id)
            ->where('user_id', $actor->id)->where('created_at', '<=', now())
            ->where('expires_at', '>', now())->whereNull('ended_at')
            ->orderByDesc('created_at')->orderByDesc('id')->first();

        return $grant !== null && $this->canonicalGrant($actor, $client, $grant,
            $grant->co_signed_by ? User::query()->find($grant->co_signed_by) : null);
    }

    public function assertMedicationActive(ClientMedication $medication): void
    {
        // Same current-order checks as the established PRN source recorder.
        $date = now()->timezone($this->schedule->workerTimezone())->toDateString();
        if ($medication->state !== 'active' || ! (bool) $medication->active
            || $medication->superseded_by !== null || $medication->deleted_at !== null
            || ($medication->start_date && $date < $medication->start_date->toDateString())
            || ($medication->end_date && $date > $medication->end_date->toDateString())) {
            throw ValidationException::withMessages(['medication' => 'The requested medication record was not found.']);
        }
    }

    /** Distinguish ordinary person access from ClientPolicy's legacy grant fallback. */
    public function hasOrdinaryPersonAccess(User $actor, Client $client, ?Shift $shift): bool
    {
        if (collect(['clients.viewAny', 'medications.stock.update', 'medications.audit.view',
            'medications.reports.export', 'medications.reports.view'])->contains(fn ($key) => $actor->canDo($key))) {
            return true;
        }
        if ($client->supportWorkers()->whereKey($actor->id)->exists()) {
            return true;
        }
        if (! $shift || ! $shift->actual_starts_at || $shift->actual_starts_at->isFuture()
            || $shift->status !== 'in_progress' || $shift->actual_ends_at !== null) {
            return false;
        }

        return (int) $shift->client_id === (int) $client->id
            || (Schema::hasTable('shift_clients') && DB::table('shift_clients')->where('shift_id', $shift->id)->where('client_id', $client->id)->exists());
    }

    private function canonicalGrant(User $actor, Client $client, ClientBreakGlassAccess $grant, ?User $cosigner): bool
    {
        if ((int) $grant->client_id !== (int) $client->id || (int) $grant->user_id !== (int) $actor->id
            || ! $actor->approved_at || ! $actor->canDo('medications.breakglass') || ! $this->staff->isCurrent($actor)
            || ! in_array((int) $client->site_id, $this->sites->accessibleSiteIds($actor), true)
            || ! $grant->created_at || ! $grant->expires_at || $grant->created_at->isFuture()
            || ! in_array($grant->authorization_mode, ['self', 'co_sign'], true)
            || ! $grant->acknowledged_min_necessary || ! $grant->acknowledged_incident_report) {
            return false;
        }
        $policy = $grant->effectivePolicy();
        if (($policy['reason_required'] && blank($grant->reason))
            || ($policy['second_person'] === 'required' && $grant->authorization_mode !== 'co_sign')
            || ($policy['second_person'] === 'off' && $grant->authorization_mode === 'co_sign')) {
            return false;
        }
        if ($grant->authorization_mode === 'co_sign' && (! $cosigner
            || (int) $cosigner->id === (int) $actor->id || ! $cosigner->approved_at
            || ! $this->staff->isCurrent($cosigner)
            || (! $cosigner->canDo('medications.breakglass') && ! $cosigner->canDo('medications.audit.view'))
            || ! in_array((int) $client->site_id, $this->sites->accessibleSiteIds($cosigner), true))) {
            return false;
        }
        $duration = $grant->created_at->diffInMinutes($grant->expires_at, false);

        return $duration >= 5 && $duration <= (int) $policy['max_minutes'];
    }
}
