<?php

namespace App\Services\HealthSafety;

use App\Jobs\DispatchRestraintDelivery;
use App\Models\AuditLog;
use App\Models\ClientIncident;
use App\Models\HsEvent;
use App\Models\RestraintEvent;
use App\Models\RestraintEventAttachment;
use App\Models\RestraintEventReview;
use App\Models\RestraintFollowup;
use App\Models\RestraintSourceDelivery;
use App\Models\Role;
use App\Models\RoleNotificationPreference;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Notifications\AppEventNotification;
use App\Services\AuditLogger;
use App\Services\ControlRoom\ComprehensiveAlertBridgeService;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** Durable source/assignment delivery; confirmation is never human contact or clinical safety. */
class RestraintSourceDeliveryService
{
    public function __construct(private readonly RestraintAccessService $access, private readonly HsEventService $events, private readonly ComprehensiveAlertBridgeService $bridge) {}

    public function tuple(RestraintEvent $row): array
    {
        return ['event_id' => (int) $row->id, 'client_id' => $row->client_id, 'site_id' => $row->site_id, 'stay_id' => $row->stay_id, 'source_schema_version' => $row->source_schema_version, 'recorded_client_id' => $row->recorded_client_id, 'recorded_site_id' => $row->recorded_site_id, 'recorded_stay_id' => $row->recorded_stay_id, 'creator_id' => $row->created_by];
    }

    public function stageSource(RestraintEvent $row, ?User $actor, string $operation = 'review'): void
    {
        $tuple = [...$this->tuple($row), 'operation' => $operation];
        $this->stage('source_review:'.$row->id, ['purpose' => 'source_review', 'restraint_event_id' => $row->id, 'initiated_by_user_id' => $actor?->id, 'membership' => [$tuple]]);
        $this->stage('source_reconcile:'.$row->id.':'.$row->lock_version, ['purpose' => 'source_reconcile', 'restraint_event_id' => $row->id, 'initiated_by_user_id' => $actor?->id, 'membership' => [$tuple]]);
        if ($row->injury_occurred || $row->within_support_plan === false) {
            $tag = $row->injury_occurred ? 'injury' : 'out_of_plan';
            $this->stage('operational_bridge:'.$row->id.':'.$row->source_revision.':'.$tag, ['purpose' => 'operational_bridge', 'restraint_event_id' => $row->id, 'initiated_by_user_id' => $actor?->id, 'membership' => [[...$tuple, 'accepted_source_revision' => (int) $row->source_revision]]]);
        }
    }

    public function stageReviewAssignment(RestraintEvent $row, User $actor): void
    {
        if (! $row->review_owner_id || ! $row->needsReview()) {
            return;
        }
        $this->stage('review_assignment:'.$row->id.':'.$row->review_assignment_revision, ['purpose' => 'review_assignment', 'restraint_event_id' => $row->id, 'recipient_user_id' => $row->review_owner_id, 'assignment_revision' => $row->review_assignment_revision, 'initiated_by_user_id' => $actor?->id, 'membership' => [[...$this->tuple($row), 'kind' => 'review', 'assignment_revision' => (int) $row->review_assignment_revision]]]);
    }

    public function stageFollowupAssignment(RestraintEvent $row, RestraintFollowup $child, User $actor): void
    {
        if (! $child->assigned_to_user_id || $child->completed_at || $child->cancelled_at) {
            return;
        }
        $this->stage('followup_assignment:'.$child->id.':'.$child->assignment_revision, ['purpose' => 'followup_assignment', 'restraint_event_id' => $row->id, 'followup_id' => $child->id, 'recipient_user_id' => $child->assigned_to_user_id, 'assignment_revision' => $child->assignment_revision, 'initiated_by_user_id' => $actor?->id, 'membership' => [[...$this->tuple($row), 'kind' => 'followup', 'followup_id' => (int) $child->id, 'assignment_revision' => (int) $child->assignment_revision]]]);
    }

    public function stageEvidenceRemoval(RestraintEvent $row, RestraintEventAttachment $attachment, User $actor): void
    {
        $this->stage('evidence_removal:'.$attachment->id.':'.$attachment->removal_command_key, ['purpose' => 'evidence_removal', 'restraint_event_id' => $row->id, 'attachment_id' => $attachment->id, 'initiated_by_user_id' => $actor?->id, 'membership' => [[...$this->tuple($row), 'attachment_id' => (int) $attachment->id, 'removal_command_key' => $attachment->removal_command_key, 'disk' => $attachment->disk, 'path' => $attachment->path]]]);
    }

    public function stageDueReminders(int $windowDays = 0): int
    {
        $cutoff = now()->addDays(max(0, $windowDays));
        $day = now('Pacific/Auckland')->toDateString();
        $ids = RestraintFollowup::whereNull('completed_at')->whereNull('cancelled_at')->whereNotNull('assigned_to_user_id')->where('due_at', '<=', $cutoff)->pluck('assigned_to_user_id')->merge(RestraintEvent::whereNotNull('review_owner_id')->where('review_due_at', '<=', $cutoff)->where(fn ($q) => $q->whereNull('reviewed_source_revision')->orWhereColumn('reviewed_source_revision', '<', 'source_revision'))->pluck('review_owner_id'))->unique()->sort();
        $count = 0;
        foreach ($ids as $id) {
            $recipient = User::find($id);
            if (! $recipient) {
                continue;
            }
            try {
                $count += (int) $this->access->withCurrentViewer($recipient, function ($current, $reads, $sites) use ($cutoff, $day): bool {
                    $members = [];
                    $rows = RestraintEvent::where('review_owner_id', $current->id)->where('review_due_at', '<=', $cutoff)->orderBy('id')->get();
                    foreach ($rows as $row) {
                        if ($row->needsReview() && $this->authorized($row, $current, $reads, $sites, 'review')) {
                            $members[] = [...$this->tuple($row), 'kind' => 'review', 'assignment_revision' => (int) $row->review_assignment_revision];
                        }
                    }
                    $children = RestraintFollowup::where('assigned_to_user_id', $current->id)->whereNull('completed_at')->whereNull('cancelled_at')->where('due_at', '<=', $cutoff)->orderBy('id')->get();
                    foreach ($children as $child) {
                        $row = RestraintEvent::find($child->restraint_event_id);
                        if ($row && $this->authorized($row, $current, $reads, $sites, 'complete_followup')) {
                            $members[] = [...$this->tuple($row), 'kind' => 'followup', 'followup_id' => (int) $child->id, 'assignment_revision' => (int) $child->assignment_revision];
                        }
                    }
                    if (! $members) {
                        return false;
                    }

                    return $this->stage('due_reminder:'.$current->id.':'.$day, ['purpose' => 'due_reminder', 'recipient_user_id' => $current->id, 'reminder_day' => $day, 'due_cutoff_at' => $cutoff, 'membership' => $members])->wasRecentlyCreated;
                });
            } catch (HttpException $error) {
                if (! in_array($error->getStatusCode(), [403, 404], true)) {
                    throw $error;
                }
            }
        }

        return $count;
    }

    private function stage(string $key, array $values): RestraintSourceDelivery
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Stage delivery inside its source transaction.');
        }
        $row = RestraintSourceDelivery::firstOrCreate(['dedupe_key' => hash('sha256', $key)], ['id' => (string) Str::uuid(), 'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now(), ...$values]);
        if (! $row->id || ! RestraintSourceDelivery::whereKey($row->id)->exists()) {
            throw new RuntimeException('Source delivery intent was not durably confirmed.');
        }
        if ($row->wasRecentlyCreated) {
            $deliveryId = (string) $row->id;
            DB::afterCommit(fn () => $this->queue($deliveryId));
        }

        return $row;
    }

    public function queue(string $id): void
    {
        try {
            DispatchRestraintDelivery::dispatch($id);
        } catch (Throwable $error) {
            Log::warning('Restraint queue unavailable', ['delivery_id' => $id, 'exception_type' => $error::class]);
            try {
                RestraintSourceDelivery::whereKey($id)->whereIn('status', ['pending', 'failed'])->update(['status' => 'failed', 'failure_code' => 'queue_unavailable', 'next_attempt_at' => now()->addMinute()]);
            } catch (Throwable $stateError) {
                // The committed pending intent remains recoverable; do not turn an accepted source command into a false failure.
                Log::error('Restraint queue failure state unavailable', ['delivery_id' => $id, 'exception_type' => $stateError::class]);
                report($stateError);
            }
        }
    }

    /** Legacy observer entry, after commit; factual capture commands stage atomically and do not emit observers. */
    public function stageObserved(RestraintEvent $event, array $changes = [], ?string $handoffKey = null): void
    {
        DB::transaction(function () use ($event, $changes, $handoffKey): void {
            $row = RestraintEvent::whereKey($event->id)->lockForUpdate()->first();
            if (! $row) {
                return;
            }
            $actor = User::find($row->updated_by ?: $row->created_by);
            if (! $actor) {
                throw new RuntimeException('Legacy source handoff has no attributable current actor.');
            }
            $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($row, $changes, $handoffKey): void {
                $operation = $changes === [] ? ($current->canDo('restraints.create') || $current->canDo('restraints.manage') || $current->canDo('restraints.report') ? 'capture' : 'respite_capture') : 'review';
                $this->access->assertEvent($row, $current, $reads, $sites, $operation);
                if ($changes !== []) {
                    if (! $handoffKey || ! Str::isUuid($handoffKey)) {
                        throw new LogicException('A changed legacy source requires an exact handoff UUID.');
                    }
                    if (RestraintEventReview::where('restraint_event_id', $row->id)->where('command_key', $handoffKey)->exists()) {
                        return;
                    }
                    foreach ($changes as $field => $observed) {
                        if (! in_array($field, RestraintEvent::LEGACY_FACTUAL_FIELDS, true)) {
                            throw new LogicException('Legacy handoff contains a non-factual field.');
                        }
                        if (RestraintCommandValues::digest($row->getAttribute($field)) !== RestraintCommandValues::digest($observed)) {
                            return; // A newer writer superseded this callback; never label newer facts as this write.
                        }
                    }
                    $row->source_revision++;
                    $row->lock_version++;
                    $row->saveQuietly();
                    if ((int) $row->fresh()?->source_revision !== (int) $row->source_revision) {
                        throw new RuntimeException('Legacy source revision was not durably confirmed.');
                    }
                    $request = clone request();
                    $request->setUserResolver(fn () => $current);
                    AuditLogger::logOrFail('restraints.event.legacy_source_change', $row, ['handoff_key' => $handoffKey, 'source_revision' => $row->source_revision, 'observed_values' => $changes, 'prior_values' => 'not_retained'], $request);
                    if (AuditLog::where('user_id', $current->id)->where('auditable_type', $row->getMorphClass())->where('auditable_id', $row->id)->where('action', 'restraints.event.legacy_source_change')->where('meta->handoff_key', $handoffKey)->count() !== 1) {
                        throw new RuntimeException('Legacy source handoff requires its exact attributable audit.');
                    }
                    RestraintEventReview::create(['restraint_event_id' => $row->id, 'source_revision' => $row->source_revision, 'recorder_id' => $current->id, 'actor_snapshot' => ['id' => (int) $current->id, 'name' => $current->name], 'actual_actor_id' => null, 'actual_actor_snapshot' => null, 'occurred_at' => null, 'facts' => ['kind' => 'legacy_source_change', 'observed_values' => $changes, 'prior_values' => 'not_retained'], 'evidence_ids' => [], 'command_key' => $handoffKey])->persisted();
                }
                $this->stageSource($row, $current, $operation);
            });
        });
    }

    public function deliver(string $id): void
    {
        try {
            DB::transaction(function () use ($id): void {
                $original = RestraintSourceDelivery::find($id);
                if (! $original) {
                    return;
                }
                // Same stable parent -> child -> receipt order as source commands.
                $members = $original->membership ?? [];
                $parents = RestraintEvent::withTrashed()->whereIn('id', array_column($members, 'event_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $children = RestraintFollowup::whereIn('id', array_filter(array_column($members, 'followup_id')))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $intent = RestraintSourceDelivery::whereKey($id)->lockForUpdate()->first();
                if (! $intent || ! in_array($intent->status, ['pending', 'failed'], true) || $intent->next_attempt_at?->isFuture()) {
                    return;
                }
                $intent->attempts++;
                if ($intent->purpose === 'evidence_removal') {
                    $this->removeBytes($intent, $parents);
                } elseif (in_array($intent->purpose, ['source_review', 'source_reconcile', 'operational_bridge'], true)) {
                    $this->deliverSource($intent, $parents);
                } else {
                    $this->deliverNotification($intent, $parents, $children);
                }
            });
        } catch (Throwable $error) {
            DB::transaction(function () use ($id): void {
                $row = RestraintSourceDelivery::whereKey($id)->lockForUpdate()->first();
                if (! $row || ! in_array($row->status, ['pending', 'failed'], true)) {
                    return;
                }
                $attempts = $row->attempts + 1;
                $row->forceFill(['status' => 'failed', 'attempts' => $attempts, 'failure_code' => 'delivery_failed', 'next_attempt_at' => now()->addSeconds(min(900, 30 * (2 ** min(5, $attempts - 1))))])->save();
            });
            throw $error;
        }
    }

    private function deliverSource(RestraintSourceDelivery $intent, $parents): void
    {
        $row = $parents->get($intent->restraint_event_id);
        $member = $intent->membership[0] ?? [];
        if (! $row || $row->trashed() || ! $this->sameSource($row, $member)) {
            $this->finish($intent, 'skipped', 'source_changed');

            return;
        }
        $actor = User::find($intent->initiated_by_user_id);
        if (! $actor) {
            $this->finish($intent, 'suppressed', 'authority_changed');

            return;
        }
        try {
            $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($row, $intent, $member): void {
                $this->access->assertEvent($row, $current, $reads, $sites, $member['operation'] ?? 'review');
                $hs = $this->events->recordEvent(['source' => $row, 'event_category' => HsEvent::CATEGORY_RESTRAINT, 'severity' => $row->injury_occurred ? 'high' : 'medium', 'occurred_at' => $row->started_at, 'reported_at' => $row->created_at, 'site_id' => $row->site_id, 'client_id' => $row->client_id, 'staff_id' => $row->created_by, 'created_by' => $row->created_by]);
                if (! $hs || ! in_array($hs->source_type, [RestraintEvent::class, $row->getMorphClass()], true) || (int) $hs->source_id !== (int) $row->id || $hs->event_category !== HsEvent::CATEGORY_RESTRAINT || $hs->site_id != $row->site_id || $hs->client_id != $row->client_id || $hs->staff_id != $row->created_by) {
                    throw new RuntimeException('Canonical restraint source review was not confirmed.');
                }
                if ($row->injury_occurred) {
                    $this->events->syncSeverity($hs, 'high');
                }
                $intent->hs_event_id = $hs->id;
                if ($intent->purpose === 'operational_bridge') {
                    if ((int) ($member['accepted_source_revision'] ?? 0) !== (int) $row->source_revision) {
                        $this->finish($intent, 'skipped', 'source_superseded');

                        return;
                    }
                    if (! $row->injury_occurred && $row->within_support_plan !== false) {
                        $this->finish($intent, 'skipped', 'not_required');

                        return;
                    }
                    $alert = $this->bridge->bridgeOperationalAlert('restraint_event', $row->injury_occurred ? 'high' : 'medium', ['restraint_event_id' => (int) $row->id, 'source_revision' => (int) $row->source_revision, 'delivery_id' => $intent->id, 'client_id' => $row->client_id, 'site_id' => $row->site_id, 'injury_occurred' => (bool) $row->injury_occurred, 'within_support_plan' => (bool) $row->within_support_plan, 'severity_escalation' => (bool) $row->injury_occurred]);
                    if (! $alert || (['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4][$alert->severity] ?? 0) < ($row->injury_occurred ? 3 : 2) || (int) ($alert->context['restraint_event_id'] ?? 0) !== (int) $row->id || (int) ($alert->context['source_revision'] ?? 0) !== (int) $row->source_revision || $alert->client_id != $row->client_id || $alert->site_id != $row->site_id || ! collect($alert->context['source_deliveries'] ?? [])->contains(fn ($receipt) => (int) ($receipt['source_revision'] ?? 0) === (int) $row->source_revision && ($receipt['delivery_id'] ?? null) === $intent->id && ($receipt['severity'] ?? null) === ($row->injury_occurred ? 'high' : 'medium'))) {
                        throw new RuntimeException('Canonical source-specific operational alert was not confirmed.');
                    }
                    $this->events->linkControlRoomAlert($hs, (int) $alert->id);
                    $intent->control_room_alert_id = $alert->id;
                }
                // Reference reconciliation is identity-only and never closes a destination.
                if ($intent->purpose === 'source_reconcile' && $row->related_incident_id) {
                    $incident = $reads->query(ClientIncident::query())->find($row->related_incident_id);
                    if (! $incident || $incident->client_id != $row->client_id || $incident->site_id != $row->site_id || ($incident->respite_stay_id === null ? null : (int) $incident->respite_stay_id) !== ($row->stay_id === null ? null : (int) $row->stay_id)) {
                        throw new RuntimeException('Source incident reconciliation is not canonical.');
                    }
                    $intent->linked_incident_id = $incident->id;
                }
                $this->finish($intent, 'delivered');
            });
        } catch (HttpException $error) {
            if (! in_array($error->getStatusCode(), [403, 404], true)) {
                throw $error;
            } $this->finish($intent, 'suppressed', 'authority_changed');
        }
    }

    private function deliverNotification(RestraintSourceDelivery $intent, $parents, $children): void
    {
        $recipient = User::find($intent->recipient_user_id);
        if (! $recipient) {
            $this->finish($intent, 'suppressed', 'authority_changed');

            return;
        }
        try {
            $this->access->withCurrentViewer($recipient, function ($current, $reads, $sites) use ($intent, $parents, $children): void {
                $eligible = [];
                foreach ($intent->membership ?? [] as $member) {
                    $row = $parents->get($member['event_id'] ?? 0);
                    if (! $row || $row->trashed() || ! $this->sameSource($row, $member)) {
                        continue;
                    }
                    if (($member['kind'] ?? null) === 'review') {
                        if ((int) $row->review_owner_id !== (int) $current->id || (int) $row->review_assignment_revision !== (int) ($member['assignment_revision'] ?? 0) || ! $row->needsReview() || ! $this->authorized($row, $current, $reads, $sites, 'review') || ($intent->purpose === 'due_reminder' && (! $row->review_due_at || $row->review_due_at->gt($intent->due_cutoff_at)))) {
                            continue;
                        }
                    } else {
                        $child = $children->get($member['followup_id'] ?? 0);
                        if (! $child || (int) $child->restraint_event_id !== (int) $row->id || $child->completed_at || $child->cancelled_at || (int) $child->assigned_to_user_id !== (int) $current->id || (int) $child->assignment_revision !== (int) ($member['assignment_revision'] ?? 0) || ! $this->authorized($row, $current, $reads, $sites, 'complete_followup') || ($intent->purpose === 'due_reminder' && (! $child->due_at || $child->due_at->gt($intent->due_cutoff_at)))) {
                            continue;
                        }
                    }
                    $eligible[] = $member;
                }
                if (! $eligible) {
                    $this->finish($intent, 'suppressed', 'assignment_inactive');

                    return;
                }
                $key = $intent->purpose === 'due_reminder' ? 'followups.overdue_reminder' : 'tasks.assigned';
                if (! $this->inAppEnabled($current, $reads, $key)) {
                    $this->finish($intent, 'suppressed', 'preference_suppressed');

                    return;
                }
                $url = count($eligible) === 1 ? '/health-safety/restraints/events/'.$eligible[0]['event_id'].'?section='.($eligible[0]['kind'] === 'review' ? 'review' : 'followups') : '/tasks?domain=health_safety';
                $notification = new AppEventNotification(['kind' => $intent->purpose, 'event_key' => $key, 'title' => $intent->purpose === 'due_reminder' ? 'Restraint follow-up work needs attention' : 'Restraint follow-up work assigned', 'body' => 'Open your current authorised work.', 'url' => $url, 'work_due' => count($eligible)]);
                $notification->id = $intent->id;
                if (! $reads->query(DB::table('notifications'))->where('id', $intent->id)->exists()) {
                    $current->notify($notification);
                }
                if (! $reads->query(DB::table('notifications'))->where('id', $intent->id)->where('notifiable_id', $current->id)->exists()) {
                    throw new RuntimeException('Current-audience inbox delivery was not confirmed.');
                }
                $this->finish($intent, 'delivered');
            });
        } catch (HttpException $error) {
            if (! in_array($error->getStatusCode(), [403, 404], true)) {
                throw $error;
            } $this->finish($intent, 'suppressed', 'authority_changed');
        }
    }

    private function removeBytes(RestraintSourceDelivery $intent, $parents): void
    {
        $row = $parents->get($intent->restraint_event_id);
        $member = $intent->membership[0] ?? [];
        $attachment = RestraintEventAttachment::where('restraint_event_id', $row?->id)->whereKey($intent->attachment_id)->lockForUpdate()->first();
        if (! $row || ! $this->sameSource($row, $member) || ! $attachment?->removed_at || $attachment->removal_command_key !== ($member['removal_command_key'] ?? null) || $attachment->path !== ($member['path'] ?? null) || $attachment->disk !== ($member['disk'] ?? null)) {
            $this->finish($intent, 'skipped', 'source_changed');

            return;
        }
        // Removal is the already accepted source decision; later loss of recorder grants does not resurrect bytes.
        if ($attachment->disk !== 'private') {
            $this->finish($intent, 'skipped', 'legacy_storage_unavailable');

            return;
        }
        if (! str_starts_with($attachment->path, 'restraint_attachments/') || str_contains($attachment->path, '..')) {
            throw new RuntimeException('Unsafe retained private evidence path.');
        }
        if (Storage::disk('private')->exists($attachment->path) && ! Storage::disk('private')->delete($attachment->path)) {
            throw new RuntimeException('Private evidence deletion was not confirmed.');
        }
        if (Storage::disk('private')->exists($attachment->path)) {
            throw new RuntimeException('Private evidence remains present after removal.');
        }
        $this->finish($intent, 'delivered');
    }

    private function sameSource(RestraintEvent $row, array $member): bool
    {
        foreach ($this->tuple($row) as $key => $value) {
            if (! array_key_exists($key, $member) || $member[$key] != $value) {
                return false;
            }
        }

        return true;
    }

    private function authorized(RestraintEvent $row, User $current, CurrentAuthorizationReads $reads, array $sites, string $operation): bool
    {
        try {
            $this->access->eligibleStaff((int) $current->id, (int) $row->site_id, $reads);
            $this->access->assertEvent($row, $current, $reads, $sites, $operation);

            return true;
        } catch (HttpException $error) {
            if (in_array($error->getStatusCode(), [403, 404], true)) {
                return false;
            } throw $error;
        }
    }

    private function inAppEnabled(User $current, CurrentAuthorizationReads $reads, string $key): bool
    {
        $override = $reads->query(UserNotificationPreference::query())->where('user_id', $current->id)->where('key', $key)->first();
        if ($override) {
            return (bool) $override->enabled && (bool) $override->channel_inapp;
        }
        $roles = $current->roles->pluck('id');
        if ($roles->isEmpty() && filled($current->role)) {
            $roles = $reads->query(Role::query())->where('name', $current->role)->pluck('id');
        }
        $defaults = $reads->query(RoleNotificationPreference::query())->whereIn('role_id', $roles)->where('key', $key)->get();

        return $defaults->isEmpty() || $defaults->contains(fn ($p) => (bool) $p->enabled && (bool) $p->channel_inapp);
    }

    private function finish(RestraintSourceDelivery $intent, string $status, ?string $code = null): void
    {
        $intent->forceFill(['status' => $status, 'failure_code' => $code, 'next_attempt_at' => null, 'delivered_at' => $status === 'delivered' ? now()->utc() : null])->save();
        if (! $intent->fresh() || $intent->fresh()->status !== $status) {
            throw new RuntimeException('Delivery state was not durably confirmed.');
        }
    }

    public function recoverPending(int $limit = 30): int
    {
        $ids = RestraintSourceDelivery::whereIn('status', ['pending', 'failed'])->where('next_attempt_at', '<=', now())->orderBy('next_attempt_at')->limit(max(1, min(100, $limit)))->pluck('id');
        foreach ($ids as $id) {
            try {
                $this->deliver($id);
            } catch (Throwable $error) {
                report($error);
            }
        }

        return $ids->count();
    }
}
