<?php

namespace App\Services\HealthSafety;

use App\Jobs\DispatchFirstAidDelivery;
use App\Models\FirstAidFollowup;
use App\Models\FirstAidRecord;
use App\Models\FirstAidSourceDelivery;
use App\Models\HsEvent;
use App\Models\Role;
use App\Models\RoleNotificationPreference;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Notifications\AppEventNotification;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** Durable local review/inbox delivery; neither receipt establishes human acceptance or safety. */
class FirstAidDeliveryService
{
    public const FAILURE_CODES = ['queue_unavailable', 'delivery_failed', 'source_inactive', 'source_changed', 'authority_changed', 'assignment_inactive', 'preference_suppressed'];

    public function __construct(private readonly FirstAidAccessService $access, private readonly HsEventService $events) {}

    public function stageReview(FirstAidRecord $row, User $actor): ?FirstAidSourceDelivery
    {
        $this->insideTransaction();
        if ($row->trashed() || $row->related_incident_id || (! $row->ambulance_called && $row->treatment_outcome !== 'sent_to_hospital')) {
            return null;
        }

        $delivered = FirstAidSourceDelivery::where('first_aid_record_id', $row->id)->where('purpose', 'source_review')->where('status', 'delivered')->lock('for share nowait')->first();
        if ($delivered) {
            return $delivered;
        }

        return $this->stage('source_review:'.$row->id.':'.$row->lock_version, [
            'purpose' => 'source_review', 'first_aid_record_id' => $row->id, 'initiated_by_user_id' => $actor->id,
            'membership' => [['record_id' => (int) $row->id, 'site_id' => (int) $row->site_id, 'client_id' => $row->client_id, 'treated_person_id' => $row->treated_person_id, 'treated_person_type' => $row->treated_person_type]],
        ]);
    }

    public function stageAssignment(FirstAidRecord $row, FirstAidFollowup $child, User $actor): ?FirstAidSourceDelivery
    {
        $this->insideTransaction();
        if (! $child->assigned_to_user_id || $child->completed_at) {
            return null;
        }

        return $this->stage('followup_assignment:'.$child->id.':'.$child->assignment_revision, [
            'purpose' => 'followup_assignment', 'first_aid_record_id' => $row->id, 'followup_id' => $child->id,
            'recipient_user_id' => $child->assigned_to_user_id, 'assignment_revision' => $child->assignment_revision,
            'initiated_by_user_id' => $actor->id, 'membership' => [[...$this->sourceTuple($row), 'followup_id' => (int) $child->id, 'assignment_revision' => (int) $child->assignment_revision]],
        ]);
    }

    /** Existing daily digest, one exact recipient/NZ calendar-day receipt. No private hidden counts. */
    public function stageDueReminders(int $windowDays = 0): int
    {
        $cutoff = now()->addDays(max(0, $windowDays));
        $day = now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();
        $recipientIds = FirstAidFollowup::query()->whereNull('completed_at')->whereNotNull('assigned_to_user_id')->where('due_at', '<=', $cutoff)->whereHas('record')->distinct()->pluck('assigned_to_user_id');
        $staged = 0;
        foreach ($recipientIds as $id) {
            $recipient = User::find($id);
            if (! $recipient) {
                continue;
            }
            try {
                $staged += (int) $this->access->withCurrentViewer($recipient, function ($current, $reads, $sites) use ($cutoff, $day) {
                    if (! $current->canDo('hazards.view')) {
                        return false;
                    }
                    $parentIds = FirstAidFollowup::query()->where('assigned_to_user_id', $current->id)->whereNull('completed_at')->where('due_at', '<=', $cutoff)->pluck('first_aid_record_id');
                    $parents = $reads->query(FirstAidRecord::query())->whereIn('id', $parentIds)->orderBy('id')->get()->keyBy('id');
                    $children = $reads->query(FirstAidFollowup::query())->whereIn('first_aid_record_id', $parents->keys())->where('assigned_to_user_id', $current->id)->whereNull('completed_at')->where('due_at', '<=', $cutoff)->orderBy('id')->get();
                    $membership = [];
                    foreach ($children as $child) {
                        $row = $parents->get($child->first_aid_record_id);
                        if ($row && $this->sourceAuthorized($row, $current, $reads, $sites)) {
                            $membership[] = [...$this->sourceTuple($row), 'followup_id' => (int) $child->id, 'assignment_revision' => (int) $child->assignment_revision];
                        }
                    }
                    if ($membership === []) {
                        return false;
                    }
                    $intent = $this->stage('followup_reminder:'.$current->id.':'.$day, ['purpose' => 'followup_reminder', 'recipient_user_id' => $current->id, 'reminder_day' => $day, 'due_cutoff_at' => $cutoff, 'membership' => $membership]);

                    return $intent->wasRecentlyCreated;
                });
            } catch (HttpException $error) {
                if (! in_array($error->getStatusCode(), [403, 404], true)) {
                    throw $error;
                }
            }
        }

        return $staged;
    }

    private function stage(string $key, array $values): FirstAidSourceDelivery
    {
        $this->insideTransaction();
        $intent = FirstAidSourceDelivery::firstOrCreate(['dedupe_key' => hash('sha256', $key)], ['id' => (string) Str::uuid(), 'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now(), ...$values]);
        if ($intent->wasRecentlyCreated) {
            DB::afterCommit(fn () => $this->queue($intent->id));
        }

        return $intent;
    }

    public function queue(string $id): void
    {
        try {
            DispatchFirstAidDelivery::dispatch($id);
        } catch (Throwable $error) {
            Log::warning('First aid delivery queue unavailable', ['delivery_id' => $id, 'exception_type' => $error::class]);
            try {
                FirstAidSourceDelivery::whereKey($id)->whereIn('status', ['pending', 'failed'])->update(['status' => 'failed', 'failure_code' => 'queue_unavailable', 'next_attempt_at' => now()->addMinute(), 'updated_at' => now()]);
            } catch (Throwable $receiptError) {
                Log::warning('First aid queue failure receipt unavailable', ['delivery_id' => $id, 'exception_type' => $receiptError::class]);
            }
        }
    }

    public function deliver(string $id): void
    {
        try {
            DB::transaction(function () use ($id) {
                $original = FirstAidSourceDelivery::find($id);
                if (! $original) {
                    return;
                }
                // Same parent -> child -> receipt order as source commands, also for multi-record digests.
                $members = $original->membership ?? [];
                $parents = FirstAidRecord::withTrashed()->whereIn('id', array_column($members, 'record_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $children = FirstAidFollowup::whereIn('id', array_filter(array_column($members, 'followup_id')))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $intent = FirstAidSourceDelivery::whereKey($id)->lockForUpdate()->first();
                if (! $intent || ! in_array($intent->status, ['pending', 'failed'], true) || $intent->next_attempt_at?->isFuture()) {
                    return;
                }
                $intent->attempts = (int) $intent->attempts + 1;
                if ($intent->purpose === 'source_review') {
                    $this->deliverReview($intent, $parents);
                } else {
                    $this->deliverNotification($intent, $parents, $children);
                }
            }, 3);
        } catch (Throwable $error) {
            DB::transaction(function () use ($id) {
                $intent = FirstAidSourceDelivery::whereKey($id)->lockForUpdate()->first();
                if (! $intent || ! in_array($intent->status, ['pending', 'failed'], true)) {
                    return;
                }
                $attempts = (int) $intent->attempts + 1;
                $intent->forceFill(['status' => 'failed', 'attempts' => $attempts, 'failure_code' => 'delivery_failed', 'next_attempt_at' => now()->addSeconds(min(900, 30 * (2 ** min(5, $attempts - 1))))])->save();
            }, 3);
            throw $error;
        }
    }

    private function deliverReview(FirstAidSourceDelivery $intent, $parents): void
    {
        $row = $parents->get($intent->first_aid_record_id);
        if (! $row || $row->trashed() || $row->related_incident_id || (! $row->ambulance_called && $row->treatment_outcome !== 'sent_to_hospital')) {
            $this->finish($intent, 'skipped', 'source_inactive');

            return;
        }
        $tuple = $intent->membership[0] ?? [];
        foreach (['site_id', 'client_id', 'treated_person_id', 'treated_person_type'] as $field) {
            if (! array_key_exists($field, $tuple) || $tuple[$field] != $row->{$field}) {
                $this->finish($intent, 'skipped', 'source_changed');

                return;
            }
        }
        $actor = User::find($intent->initiated_by_user_id);
        if (! $actor) {
            $this->finish($intent, 'skipped', 'authority_changed');

            return;
        }
        try {
            $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($row, $intent) {
                $this->access->assertCommand($row, $current, $reads, $sites, 'create');
                $event = $this->events->recordEvent(['source' => $row, 'event_category' => HsEvent::CATEGORY_INJURY, 'severity' => 'high',
                    'occurred_at' => $row->treatment_date, 'reported_at' => $intent->created_at, 'site_id' => $row->site_id,
                    'client_id' => $row->treated_person_type === 'client' ? $row->client_id : null,
                    'staff_id' => $row->treated_person_type === 'staff' ? $row->treated_person_id : null, 'created_by' => $current->id]);
                if (! $event || ! in_array($event->source_type, array_unique([FirstAidRecord::class, $row->getMorphClass()]), true)
                    || (int) $event->source_id !== (int) $row->id || (int) $event->site_id !== (int) $row->site_id
                    || $event->event_category !== HsEvent::CATEGORY_INJURY || $event->client_id != ($row->treated_person_type === 'client' ? $row->client_id : null)
                    || $event->staff_id != ($row->treated_person_type === 'staff' ? $row->treated_person_id : null)) {
                    throw new RuntimeException('Canonical first-aid source review was not confirmed.');
                }
                $intent->hs_event_id = $event->id;
                $this->finish($intent, 'delivered');
            });
        } catch (HttpException $error) {
            if (! in_array($error->getStatusCode(), [403, 404], true)) {
                throw $error;
            }
            $this->finish($intent, 'skipped', 'authority_changed');
        }
    }

    private function deliverNotification(FirstAidSourceDelivery $intent, $parents, $children): void
    {
        $recipient = User::find($intent->recipient_user_id);
        if (! $recipient) {
            $this->finish($intent, 'skipped', 'authority_changed');

            return;
        }
        try {
            $this->access->withCurrentViewer($recipient, function ($current, $reads, $sites) use ($intent, $parents, $children) {
                if (! $current->canDo('hazards.view')) {
                    $this->finish($intent, 'skipped', 'authority_changed');

                    return;
                }
                $eligible = [];
                foreach ($intent->membership ?? [] as $member) {
                    $row = $parents->get($member['record_id'] ?? null);
                    $child = $children->get($member['followup_id'] ?? null);
                    if (! $row || $row->trashed() || ! $child || (int) $child->first_aid_record_id !== (int) $row->id
                        || $child->completed_at || (int) $child->assigned_to_user_id !== (int) $current->id
                        || (int) $child->assignment_revision !== (int) ($member['assignment_revision'] ?? 0)
                        || ! $this->sameSource($row, $member)
                        || ! $this->sourceAuthorized($row, $current, $reads, $sites)
                        || ($intent->purpose === 'followup_reminder' && (! $child->due_at || $child->due_at->gt($intent->due_cutoff_at ?? now())))) {
                        continue;
                    }
                    $eligible[] = [$row, $child];
                }
                if ($eligible === []) {
                    $this->finish($intent, 'skipped', 'assignment_inactive');

                    return;
                }
                $eventKey = $intent->purpose === 'followup_assignment' ? 'tasks.assigned' : 'followups.overdue_reminder';
                if (! $this->inAppEnabled($current, $reads, $eventKey)) {
                    $this->finish($intent, 'suppressed', 'preference_suppressed');

                    return;
                }
                $url = count($eligible) === 1 ? '/health-safety/first-aid/'.$eligible[0][0]->id.'?section=followups&followup='.$eligible[0][1]->id : '/health-safety/first-aid?tab=followup&period=all';
                $payload = ['kind' => $intent->purpose, 'event_key' => $eventKey, 'title' => $intent->purpose === 'followup_assignment' ? 'First aid follow-up assigned' : 'First aid follow-ups need attention',
                    'body' => 'Open your current first-aid follow-up work.', 'url' => $url];
                if ($intent->purpose === 'followup_reminder') {
                    $payload['followups_due'] = count($eligible);
                    $payload['overdue'] = count(array_filter($eligible, fn ($pair) => $pair[1]->due_at->lt(now())));
                }
                $notification = new AppEventNotification($payload);
                $notification->id = $intent->id;
                if (! $reads->query(DB::table('notifications'))->where('id', $intent->id)->exists()) {
                    $current->notify($notification);
                }
                $this->finish($intent, 'delivered');
            });
        } catch (HttpException $error) {
            if (! in_array($error->getStatusCode(), [403, 404], true)) {
                throw $error;
            }
            $this->finish($intent, 'skipped', 'authority_changed');
        }
    }

    private function sourceTuple(FirstAidRecord $row): array
    {
        return ['record_id' => (int) $row->id, 'site_id' => (int) $row->site_id, 'client_id' => $row->client_id, 'treated_person_id' => $row->treated_person_id, 'treated_person_type' => $row->treated_person_type];
    }

    private function sameSource(FirstAidRecord $row, array $member): bool
    {
        foreach ($this->sourceTuple($row) as $field => $value) {
            if (! array_key_exists($field, $member) || $member[$field] != $value) {
                return false;
            }
        }

        return true;
    }

    private function sourceAuthorized(FirstAidRecord $row, User $current, CurrentAuthorizationReads $reads, array $sites): bool
    {
        try {
            $this->access->eligibleStaff((int) $current->id, (int) $row->site_id, $reads, followupAssignee: true);
            $this->access->assertSource($row, $current, $reads, $sites);

            return true;
        } catch (HttpException $error) {
            if (! in_array($error->getStatusCode(), [403, 404], true)) {
                throw $error;
            }

            return false;
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

        return $defaults->isEmpty() || $defaults->contains(fn ($preference) => (bool) $preference->enabled && (bool) $preference->channel_inapp);
    }

    private function finish(FirstAidSourceDelivery $intent, string $status, ?string $code = null): void
    {
        $intent->forceFill(['status' => $status, 'failure_code' => $code, 'next_attempt_at' => null, 'delivered_at' => $status === 'delivered' ? now() : null])->save();
    }

    private function insideTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('First-aid delivery must be staged in the source transaction.');
        }
    }

    public function recoverPending(int $limit = 30): int
    {
        $ids = FirstAidSourceDelivery::whereIn('status', ['pending', 'failed'])->where('next_attempt_at', '<=', now())->orderBy('next_attempt_at')->limit(max(1, min(100, $limit)))->pluck('id');
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
