<?php

namespace App\Services\Medication\EmergencyAccess;

use App\Models\BreakGlassFlagDismissal;
use App\Models\BreakGlassPolicy;
use App\Models\Client;
use App\Models\ClientBreakGlassAccess;
use App\Models\MedicationEmergencyAccessReview;
use App\Models\User;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\WitnessPinService;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmergencyAccessService
{
    public function __construct(
        private readonly UserSiteAccessService $sites,
        private readonly WitnessPinService $pins,
        private readonly EmergencyAccessEvents $events,
        private readonly EmergencyAccessNotifications $notifications,
        private readonly MedicationGovernanceScopeService $governance,
    ) {}

    public function start(User $actor, Client $client, array $data): ClientBreakGlassAccess
    {
        return DB::transaction(function () use ($actor, $client, $data): ClientBreakGlassAccess {
            // Same Client mutex as recording: concurrent starts cannot create a duplicate live window.
            $client = Client::query()->lockForUpdate()->findOrFail($client->id);
            abort_unless($actor->approved_at !== null && $actor->canDo('medications.breakglass')
                && in_array((int) $client->site_id, $this->sites->accessibleSiteIds($actor), true), 404);
            if (ClientBreakGlassAccess::where('client_id', $client->id)->where('user_id', $actor->id)
                ->where('expires_at', '>', now())->whereNull('ended_at')->exists()) {
                throw ValidationException::withMessages(['client_id' => 'Your emergency access for this person is already running. Open that grant.']);
            }
            if (! ($data['acknowledged_min_necessary'] ?? false) || ! ($data['acknowledged_incident_report'] ?? false)) {
                throw ValidationException::withMessages(['acknowledgements' => 'Both acknowledgements are required before emergency access starts.']);
            }
            $policy = BreakGlassPolicy::current();
            $snapshot = $policy->snapshot();
            // Recheck the exact policy retained on the grant, including a concurrent policy edit.
            $data = Validator::make($data, [
                'reason' => [$snapshot['reason_required'] ? 'required' : 'nullable', 'string', 'min:5', 'max:255'],
                'reason_category' => ['required', 'string', 'max:100'],
                'minutes' => ['nullable', 'integer', 'min:5', 'max:'.$snapshot['max_minutes']],
                'authorization_mode' => ['required', Rule::in(['self', 'co_sign'])],
                'co_signed_by' => ['nullable', 'integer', 'required_if:authorization_mode,co_sign'],
                'co_signer_pin' => ['nullable', 'string', 'required_if:authorization_mode,co_sign'],
                'acknowledged_min_necessary' => ['accepted'],
                'acknowledged_incident_report' => ['accepted'],
            ])->validate();
            $mode = $data['authorization_mode'] ?? 'self';
            if ($snapshot['second_person'] === 'required' && $mode !== 'co_sign') {
                throw ValidationException::withMessages(['co_signed_by' => 'A second person must confirm before emergency access can start.']);
            }
            if ($snapshot['second_person'] === 'off' && $mode === 'co_sign') {
                throw ValidationException::withMessages(['authorization_mode' => 'The current policy does not ask for a second person.']);
            }
            $ids = [$actor->id];
            if ($mode === 'co_sign') {
                $candidate = User::query()->whereNotNull('approved_at')->find($data['co_signed_by'] ?? 0);
                if (! $candidate || (int) $candidate->id === (int) $actor->id) {
                    throw ValidationException::withMessages(['co_signed_by' => 'Choose a different approved colleague who can confirm at this house.']);
                }
                $ids[] = $candidate->id;
            }
            $locked = $this->governance->lockControlledWitnessUsers($ids);
            $profiles = $this->governance->lockCurrentStaffProfiles($locked, $ids);
            $locked->each(fn (User $user) => $user->setRelation('hrEmployeeProfile', $profiles->get($user->id)));
            $actor = $locked->get($actor->id);
            $this->governance->lockCurrentMedicationSite((int) $client->site_id);
            abort_unless($actor->canDo('medications.breakglass') && in_array((int) $client->site_id, $this->sites->accessibleSiteIds($actor), true), 404);
            $cosigner = null;
            if ($mode === 'co_sign') {
                $cosigner = $locked->get((int) ($data['co_signed_by'] ?? 0));
                if (! $cosigner || (int) $cosigner->id === (int) $actor->id
                    || (! $cosigner->canDo('medications.breakglass') && ! $cosigner->canDo('medications.audit.view'))
                    || ! in_array((int) $client->site_id, $this->sites->accessibleSiteIds($cosigner), true)) {
                    throw ValidationException::withMessages(['co_signed_by' => 'Choose a different approved colleague who can confirm at this house.']);
                }
                $this->pins->verify($cosigner, $data['co_signer_pin'] ?? null, 'co_signer_pin', [
                    'actor_id' => (int) $actor->id, 'site_id' => (int) $client->site_id, 'purpose' => 'emergency_access',
                ]);
            }
            $minutes = (int) ($data['minutes'] ?? $snapshot['default_minutes']);
            if ($minutes < 5 || $minutes > $snapshot['max_minutes']) {
                throw ValidationException::withMessages(['minutes' => 'Choose a duration within the current policy.']);
            }
            $grant = ClientBreakGlassAccess::create([
                'client_id' => $client->id, 'user_id' => $actor->id,
                'reason' => trim((string) ($data['reason'] ?? '')), 'reason_category' => $data['reason_category'] ?? null,
                'authorization_mode' => $mode, 'co_signed_by' => $cosigner?->id,
                'confirmed_at' => $cosigner ? now() : null,
                'acknowledged_min_necessary' => true, 'acknowledged_incident_report' => true,
                'policy_snapshot' => $snapshot, 'expires_at' => now()->addMinutes($minutes),
            ]);
            $grant->setRelation('client', $client);
            $grant->setRelation('user', $actor);
            $repeatCount = ClientBreakGlassAccess::withTrashed()->where('user_id', $actor->id)
                ->whereHas('client', fn ($q) => $q->where('site_id', $client->site_id))
                ->where('created_at', '>=', now()->subDays($snapshot['repeat_window_days']))->count();
            $events = [['action' => 'opened']];
            if ($repeatCount >= $snapshot['repeat_threshold_count']) {
                $events[] = ['action' => 'repeat_flagged', 'detail' => ['count' => $repeatCount, 'window_days' => $snapshot['repeat_window_days']]];
            }
            $this->events->recordMany($grant, $events, $actor);
            DB::afterCommit(fn () => $this->notifications->opened($grant));

            return $grant;
        }, 5);
    }

    public function extend(User $actor, ClientBreakGlassAccess $grant, string $reason): void
    {
        DB::transaction(function () use ($actor, $grant, $reason): void {
            $grant = $this->lockGrant($grant);
            $locked = $this->governance->lockControlledWitnessUsers([$actor->id]);
            $profiles = $this->governance->lockCurrentStaffProfiles($locked, [$actor->id]);
            $actor = $locked->get($actor->id);
            $actor->setRelation('hrEmployeeProfile', $profiles->get($actor->id));
            $this->governance->lockCurrentMedicationSite((int) $grant->client->site_id);
            abort_unless((int) $grant->user_id === (int) $actor->id && $actor->canDo('medications.breakglass'), 403);
            if (! $grant->isRunning()) {
                throw ValidationException::withMessages(['access' => 'This grant has ended. Start emergency access again; keep your dose entry open.']);
            }
            if ($grant->expires_at->gt(now()->addMinutes(10))) {
                throw ValidationException::withMessages(['access' => 'Extend is available in the final ten minutes of this grant.']);
            }
            if (mb_strlen(trim($reason)) < 5) {
                throw ValidationException::withMessages(['reason' => 'Say why you need longer.']);
            }
            abort_unless(in_array((int) $grant->client->site_id, $this->sites->accessibleSiteIds($actor), true), 404);
            $policy = $grant->effectivePolicy();
            $end = $grant->expires_at->copy()->addMinutes($policy['extend_minutes']);
            $cap = $grant->created_at->copy()->addMinutes($policy['max_minutes']);
            if ($end->gt($cap)) {
                $end = $cap;
            }
            if ($end->lte($grant->expires_at)) {
                throw ValidationException::withMessages(['access' => 'This grant is already at its longest duration.']);
            }
            $grant->extensions()->create([
                'user_id' => $actor->id, 'previous_expires_at' => $grant->expires_at,
                'expires_at' => $end, 'reason' => $reason,
            ]);
            $grant->forceFill(['expires_at' => $end])->save();
            $this->events->record($grant, 'extended', $actor, ['reason' => $reason, 'expires_at' => $end->toIso8601String()]);
        }, 5);
    }

    public function end(?User $actor, ClientBreakGlassAccess $grant, ?string $reason = null): void
    {
        DB::transaction(function () use ($actor, $grant, $reason): void {
            $grant = $this->lockGrant($grant);
            if ($actor) {
                $actor = $this->governance->lockControlledWitnessUsers([$actor->id])->get($actor->id);
                abort_unless($actor->approved_at !== null, 403);
            }
            if ($grant->ended_at !== null) {
                return;
            }
            $expired = $grant->expires_at !== null && $grant->expires_at->lte(now());
            $owner = $actor !== null && (int) $actor->id === (int) $grant->user_id;
            if (! $expired) {
                $this->governance->lockCurrentMedicationSite((int) $grant->client->site_id);
                abort_unless($actor && in_array((int) $grant->client->site_id,
                    $this->sites->accessibleSiteIds($actor, $owner ? [] : ['medications.breakglass.end']), true), 404);
                if (! $owner && mb_strlen(trim((string) $reason)) < 10) {
                    throw ValidationException::withMessages(['reason' => 'Say why their emergency access is being ended.']);
                }
                abort_unless($actor && ($owner ? $actor->canDo('medications.breakglass') : $actor->canDo('medications.breakglass.end')), 403);
            }
            $at = $expired ? $grant->expires_at : now();
            $how = $expired ? 'expired' : ($owner ? 'done' : 'ended_by');
            $grant->forceFill([
                'ended_at' => $at, 'ended_how' => $how, 'ended_by' => $expired ? null : $actor?->id,
                'end_reason' => $how === 'ended_by' ? $reason : null,
                'review_due_at' => $at->copy()->addDays($grant->effectivePolicy()['review_days']),
                'revoked_by' => $expired ? null : $actor?->id,
            ])->save();
            if (! $expired) {
                $grant->delete(); // Compatibility with all existing readers.
            }
            $this->events->record($grant, 'closed', $expired ? null : $actor, ['ended_how' => $how, 'reason' => $grant->end_reason], $at);
            if ($how === 'ended_by') {
                DB::afterCommit(fn () => $this->notifications->endedBySomeoneElse($grant));
            }
        }, 5);
    }

    public function review(User $actor, ClientBreakGlassAccess $grant, array $data): void
    {
        DB::transaction(function () use ($actor, $grant, $data): void {
            $grant = $this->lockGrant($grant);
            $actor = $this->governance->lockControlledWitnessUsers([$actor->id])->get($actor->id);
            $this->governance->lockCurrentMedicationSite((int) $grant->client->site_id);
            abort_unless($actor->approved_at !== null && $actor->canDo('medications.audit.view')
                && ! in_array((int) $actor->id, [(int) $grant->user_id, (int) $grant->co_signed_by], true), 403);
            abort_unless(in_array((int) $grant->client->site_id, $this->sites->accessibleSiteIds($actor, ['medications.audit.view']), true), 404);
            $data = Validator::make($data, [
                'review_outcome' => ['required', Rule::in(['justified', 'not_justified'])],
                'review_notes' => ['required_if:review_outcome,not_justified', 'nullable', 'string', 'min:10', 'max:2000'],
                'correction_reason' => ['nullable', 'string', 'min:10', 'max:1000'],
                'corrects_review_id' => ['nullable', 'integer'],
                'incident_report_id' => ['nullable', 'integer', Rule::exists('client_incidents', 'id')->where('client_id', $grant->client_id)],
                'medication_error_id' => ['nullable', 'integer', Rule::exists('medication_errors', 'id')->where('client_id', $grant->client_id)->whereNull('deleted_at')],
            ])->validate();
            if ($grant->isRunning()) {
                throw ValidationException::withMessages(['access' => 'Review this grant after it ends.']);
            }
            if ($grant->endedTime() === null) {
                throw ValidationException::withMessages(['access' => 'This grant has no recorded end time. It cannot be reviewed yet.']);
            }
            $previous = $grant->reviews()->latest('id')->first();
            if ($previous && blank($data['correction_reason'] ?? null)) {
                throw ValidationException::withMessages(['correction_reason' => 'Say why the previous review is being corrected. It stays visible.']);
            }
            if (! $previous && filled($data['correction_reason'] ?? null)) {
                throw ValidationException::withMessages(['correction_reason' => 'There is no earlier review to correct. Reload this grant.']);
            }
            if ($previous && (int) ($data['corrects_review_id'] ?? 0) !== (int) $previous->id) {
                throw ValidationException::withMessages(['access' => 'Another review was saved. Reload it before adding a correction.']);
            }
            MedicationEmergencyAccessReview::create([
                'access_id' => $grant->id, 'user_id' => $actor->id, 'outcome' => $data['review_outcome'],
                'notes' => $data['review_notes'] ?? null, 'incident_report_id' => $data['incident_report_id'] ?? null,
                'medication_error_id' => $data['medication_error_id'] ?? null,
                'corrects_review_id' => $previous?->id, 'correction_reason' => $previous ? $data['correction_reason'] : null,
            ]);
            // Compatibility projection only; authoritative history is never overwritten.
            $grant->forceFill([
                'reviewed_at' => now(), 'reviewed_by' => $actor->id, 'review_outcome' => $data['review_outcome'],
                'review_notes' => $data['review_notes'] ?? null, 'incident_report_id' => $data['incident_report_id'] ?? null,
                'incident_report_linked' => filled($data['incident_report_id'] ?? null),
            ])->save();
            $this->events->record($grant, $previous ? 'review_corrected' : 'reviewed', $actor, [
                'outcome' => $data['review_outcome'], 'corrects_review_id' => $previous?->id,
            ]);
        }, 5);
    }

    public function acknowledgeRepeat(User $actor, int $houseId, int $staffId, string $reason): void
    {
        abort_unless($actor->approved_at !== null && $actor->canDo('medications.audit.view'), 403);
        abort_if($staffId === (int) $actor->id, 403);
        abort_unless(in_array($houseId, $this->sites->accessibleSiteIds($actor, ['medications.audit.view']), true), 404);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:10', 'max:1000']])->validate();
        DB::transaction(function () use ($actor, $houseId, $staffId, $reason): void {
            $policy = BreakGlassPolicy::current();
            $candidates = ClientBreakGlassAccess::withTrashed()
                ->whereHas('client', fn ($q) => $q->where('site_id', $houseId))
                ->where('user_id', $staffId)
                ->where('created_at', '>=', now()->subDays($policy->repeat_window_days))
                ->get(['id', 'client_id']);
            // Preserve the shared Client -> grant -> authorization -> Site order.
            $clients = Client::query()->whereIn('id', $candidates->pluck('client_id'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $grants = ClientBreakGlassAccess::withTrashed()->whereIn('id', $candidates->pluck('id'))
                ->where('user_id', $staffId)->orderBy('id')->lockForUpdate()->get()
                ->filter(fn ($grant) => (int) $clients->get($grant->client_id)?->site_id === $houseId);
            $actor = $this->governance->lockControlledWitnessUsers([$actor->id])->get($actor->id);
            $this->governance->lockCurrentMedicationSite($houseId);
            abort_unless($actor->approved_at !== null && $actor->canDo('medications.audit.view'), 403);
            abort_if($staffId === (int) $actor->id, 403);
            abort_unless(in_array($houseId, $this->sites->accessibleSiteIds($actor, ['medications.audit.view']), true), 404);
            abort_unless($grants->count() >= $policy->repeat_threshold_count, 404);
            BreakGlassFlagDismissal::updateOrCreate(
                ['signal_type' => 'repeat', 'signal_key' => $houseId.':'.$staffId],
                ['dismissed_by' => $actor->id, 'reason' => $reason,
                    'dismissed_through' => $grants->max('created_at'), 'dismissed_through_access_id' => $grants->max('id')],
            );
            $grant = $grants->last();
            $grant->setRelation('client', $clients->get($grant->client_id));
            $this->events->record($grant, 'repeat_acknowledged', $actor);
        }, 5);
    }

    private function lockGrant(ClientBreakGlassAccess $snapshot): ClientBreakGlassAccess
    {
        // Same Client -> grant mutex order as medication recording.
        $client = Client::query()->whereKey($snapshot->client_id)->lockForUpdate()->firstOrFail();
        $grant = ClientBreakGlassAccess::withTrashed()->where('client_id', $client->id)->whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
        $grant->setRelation('client', $client);

        return $grant;
    }
}
