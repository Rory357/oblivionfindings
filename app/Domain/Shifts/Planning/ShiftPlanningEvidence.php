<?php

namespace App\Domain\Shifts\Planning;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\AppSetting;
use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftTask;
use App\Models\Site;
use App\Models\SiteCoverageRequirement;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\ShiftStateGuardService;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Command-local translation of existing planning authority, never generic read scope. */
final class ShiftPlanningEvidence
{
    private const KEYS = ['shifts.create', 'shifts.update', 'shifts.manageAny', 'shifts.overrideEligibility', 'reports.viewAny'];

    public function __construct(private readonly AuthorizationEvidenceLockService $authorization, private readonly UserSiteAccessService $sites) {}

    /** Application mutex is already held by the command; complete participants precede every decision. */
    public function lock(User $actor, array $data, ?Shift $bound, bool $forMutation = true): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Planning source evidence requires a transaction.');
        }
        $sourceHint = $bound ? ShiftPlanningIntent::source($bound) : null;
        $requestedClient = isset($data['client_id']) ? (int) $data['client_id'] : null;
        // Existing resolver priorities are preserved: explicit active, Client active,
        // configured active default, first active, null. No new context Site policy.
        $default = CurrentAuthorizationReads::within(fn ($reads) => $reads->query(AppSetting::query()->where('key', 'service_context.default_id'))->first());
        $contexts = CurrentAuthorizationReads::within(fn ($reads) => $reads->query(ServiceContext::query()->orderBy('id'))->get()->keyBy('id'));
        $ids = collect([$requestedClient, $bound?->client_id])->filter()->map(fn ($id) => (int) $id)->unique()->sort()->values();
        $clients = Client::query()->withTrashed()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        abort_unless($clients->count() === $ids->count() && $clients->every(fn (Client $client) => ! $client->trashed()), 404);
        $client = $clients->get($requestedClient);
        $locked = $bound ? Shift::query()->whereKey($bound->id)->lockForUpdate()->firstOrFail() : null;

        $resolvedUserId = array_key_exists('user_id', $data) ? $data['user_id'] : $locked?->user_id;
        $userIds = collect([$actor->id, $locked?->user_id, $resolvedUserId])->filter()->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
        $users = CurrentAuthorizationReads::within(function ($reads) use ($userIds): Collection {
            abort_unless($reads->query(User::query()->whereIn('id', $userIds)->orderBy('id'))->get()->count() === count($userIds), 404);

            return collect($userIds)->mapWithKeys(fn ($id) => [$id => $this->authorization->lockForUserWithoutWaiting($id, self::KEYS)]);
        });
        $profiles = CurrentAuthorizationReads::within(fn ($reads) => $reads->query(HrEmployeeProfile::query()->withTrashed()->whereIn('user_id', $userIds)->orderBy('user_id'))->get()->keyBy('user_id'));
        foreach ($users as $user) {
            $user->setRelation('hrEmployeeProfile', $profiles->get($user->id));
        }
        $currentActor = $users->get((int) $actor->id);
        abort_unless($currentActor?->isApproved() && $currentActor->canDo($locked ? 'shifts.update' : 'shifts.create'), 403);
        abort_unless(! $locked || $currentActor->canDo('shifts.manageAny') || (int) $locked->user_id === (int) $currentActor->id, 403);
        abort_unless(! $locked || ShiftPlanningIntent::source($locked) === $sourceHint, 409, 'This Shift changed while the edit was waiting. Refresh its current record before saving again.');
        $sourceSite = $locked ? $this->canonicalSourceSite($locked, $clients) : null;
        $siteId = (int) ($client?->site_id ?? ($forMutation ? 0 : $sourceSite));
        abort_unless($siteId > 0, 403, 'You are not authorized to create or update shifts for this site.');
        CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($currentActor, $siteId, $sourceSite, $locked, $resolvedUserId, $forMutation): void {
            $accessible = $this->sites->accessibleSiteIds($currentActor, ['reports.viewAny'], $reads);
            abort_unless(in_array($siteId, $accessible, true) && ($sourceSite === null || in_array($sourceSite, $accessible, true)), 403, 'You are not authorized to access shifts for this site.');
            if ($resolvedUserId) {
                // Existing picker/assignment authority is checked before the
                // exact target-Site membership validation, preserving its 403.
                $staff = app(HrCurrentStaffService::class)->currentUsersQuery()->whereKey((int) $resolvedUserId);
                if (! $currentActor->canDo('reports.viewAny')) {
                    $staff->whereHas('hrEmployeeProfile', function ($profile) use ($accessible): void {
                        $profile->where(function ($membership) use ($accessible): void {
                            $membership->whereIn('primary_site_id', $accessible);
                            foreach ($accessible as $id) {
                                $membership->orWhereJsonContains('secondary_site_ids', $id);
                            }
                        });
                    });
                }
                abort_unless($reads->query($staff)->exists(), 403, 'You are not authorized to assign that staff member to this shift.');
            }
            // Explicit current recipient tree, not applyShiftIntegrityScope's raw
            // correlated SQL whose query blocks cannot inherit an outer lock.
            foreach (collect([[$locked?->user_id, $sourceSite], [$resolvedUserId, $siteId]])->filter(fn ($pair) => $pair[0])->values() as [$userId, $recipientSite]) {
                $query = $this->sites->applyFleetRecipientEligibility(User::query()->whereKey((int) $userId), (int) $recipientSite);
                if (! $reads->query($query)->exists()) {
                    if ($locked && (int) $userId === (int) $locked->user_id && (int) $recipientSite === (int) $sourceSite) {
                        abort(403, 'You are not authorized to access shifts for this site.');
                    }
                    throw ValidationException::withMessages(['user_id' => 'This staff member is not currently assigned to the shift site.']);
                }
            }
            if ($locked && $forMutation) {
                // Keep the existing approved-Timesheet planning lock, now current.
                $approved = $reads->query($locked->approvedTimesheets()->getQuery())->get();
                if ($approved->isNotEmpty()) {
                    throw ValidationException::withMessages(['shift' => 'This shift has an approved timesheet and can no longer be edited from planning.']);
                }
                // The model's identical binary guard consumes this current empty
                // result, instead of an ordinary RR EXISTS read on update.
                $locked->setRelation('timesheets', $approved);
            }
        });
        if ($locked && $forMutation) {
            $guard = app(ShiftStateGuardService::class);
            if (! in_array($locked->status, $guard->planningStatuses(), true)) {
                throw ValidationException::withMessages(['status' => 'This shift is already in a live or locked lifecycle state. Use the dedicated lifecycle actions instead of a planning edit.']);
            }
            if (isset($data['status']) && $data['status'] !== $locked->status && ! in_array($data['status'], $guard->planningStatuses(), true)) {
                throw ValidationException::withMessages(['status' => 'Shift status must be changed using the dedicated lifecycle actions. Planning edits cannot directly start, complete, or cancel a shift.']);
            }
        }
        $contextIds = [$data['service_context_id'] ?? null, $client?->service_context_id, is_numeric($default?->value) ? (int) $default->value : null];
        $context = collect($contextIds)->filter()->map(fn ($id) => $contexts->get((int) $id))->first(fn ($context) => $context?->is_active);
        $context ??= $contexts->first(fn ($context) => $context->is_active);
        if (! $forMutation) {
            $context = $locked->service_context_id ? $contexts->get((int) $locked->service_context_id) : null;
        }
        $site = CurrentAuthorizationReads::within(fn ($reads) => $reads->query(Site::query()->whereKey($siteId))->firstOrFail());
        if (! empty($data['coverage_rule_id'])) {
            $requirement = CurrentAuthorizationReads::within(fn ($reads) => $reads->query(SiteCoverageRequirement::query()->whereKey((int) $data['coverage_rule_id']))->firstOrFail());
            abort_unless(! $requirement->site_id || (int) $requirement->site_id === $siteId, 422, 'The selected planning client no longer matches the linked site coverage rule.');
        }
        $tasks = $locked ? ShiftTask::query()->where('shift_id', $locked->id)->orderBy('id')->lockForUpdate()->get() : collect();
        foreach ($data['tasks'] ?? [] as $task) {
            abort_unless(empty($task['id']) || $tasks->contains(fn (ShiftTask $row) => (int) $row->id === (int) $task['id']), 404);
        }
        $protectedIds = $tasks->filter(fn (ShiftTask $task) => $task->creation_key || $task->source_handover_id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($locked && array_key_exists('tasks', $data)) {
            $data['tasks'] = array_values(array_filter($data['tasks'] ?? [], fn (array $task) => empty($task['id']) || ! in_array((int) $task['id'], $protectedIds, true)));
        }
        $intent = $forMutation ? ShiftPlanningIntent::normalize($data, $locked) : [];
        $attributes = array_intersect_key($intent, array_flip(['client_id', 'user_id', 'starts_at', 'ends_at', 'location', 'notes', 'status', 'shift_type', 'is_sleepover', 'is_on_call', 'is_lone_worker', 'expected_break_minutes', 'coverage_roles', 'required_licence_class', 'required_licence_endorsements']));
        $attributes['site_id'] = $siteId;
        $attributes['service_context_id'] = $context?->id;
        // Missing optional fields mean retention on update, not an implicit clear.
        foreach (['user_id', 'location', 'notes', 'coverage_roles', 'required_licence_class', 'required_licence_endorsements'] as $field) {
            if (! array_key_exists($field, $data)) {
                unset($attributes[$field]);
            }
        }

        return ['actor' => $currentActor, 'users' => $users, 'shift' => $locked, 'client' => $client,
            'attributes' => $attributes, 'intent' => $intent, 'source' => $sourceHint, 'tasks' => $tasks, 'data' => $data, 'site' => $site, 'context' => $context];
    }

    private function canonicalSourceSite(Shift $shift, Collection $clients): int
    {
        $client = $shift->client_id === null ? null : $clients->get((int) $shift->client_id);
        abort_unless($shift->client_id === null || $client, 404);
        $ids = collect([$shift->site_id, $client?->site_id])->filter(fn ($id) => is_numeric($id) && (int) $id > 0)->map(fn ($id) => (int) $id)->unique()->values();
        abort_unless($ids->count() === 1, 404);

        return (int) $ids->first();
    }
}
