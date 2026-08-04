<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEapReferral;
use App\Domain\Hr\Models\HrEngagementActionPlan;
use App\Domain\Hr\Models\HrEngagementActionPlanNote;
use App\Domain\Hr\Models\HrEngagementSurvey;
use App\Domain\Hr\Models\HrWellbeingCheckin;
use App\Domain\Hr\Models\HrWellbeingFlagAction;
use App\Domain\Hr\Models\HrWellbeingIndicator;
use App\Domain\Hr\Notifications\EngagementActionPlanAssignedNotification;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Duty-of-care write operations for the Wellbeing hub: flag triage actions,
 * wellbeing check-ins, EAP referrals, standalone action plans, and the action
 * plan notes timeline. Survey + indicator stores stay owned by EngagementService
 * and WellbeingIndicatorService respectively.
 */
class WellbeingCareService
{
    // ---------------------------------------------------------------------
    // Flag triage
    // ---------------------------------------------------------------------

    public function recordFlagAction(
        User $actor,
        int $staffUserId,
        string $action,
        ?string $reason = null,
        ?string $snoozeUntil = null,
    ): HrWellbeingFlagAction {
        $indicatorId = HrWellbeingIndicator::query()
            ->where('user_id', $staffUserId)
            ->orderByDesc('id')
            ->value('id');

        return HrWellbeingFlagAction::create([
            'indicator_id' => $indicatorId,
            'staff_user_id' => $staffUserId,
            'action' => $action,
            'reason' => $reason,
            'snooze_until' => $action === 'snooze' ? $snoozeUntil : null,
            'actor_user_id' => $actor->id,
        ]);
    }

    /**
     * Soft undo: remove the most recent action this actor logged for the staff
     * member (used by the toast "Undo" affordance).
     */
    public function undoLastFlagAction(User $actor, int $staffUserId): bool
    {
        return DB::transaction(function () use ($actor, $staffUserId): bool {
            $latest = HrWellbeingFlagAction::query()
                ->where('staff_user_id', $staffUserId)
                ->where('actor_user_id', $actor->id)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (! $latest) {
                return false;
            }

            $latest->delete();

            return true;
        }, attempts: 1);
    }

    // ---------------------------------------------------------------------
    // Check-ins
    // ---------------------------------------------------------------------

    public function createCheckin(User $actor, array $data): HrWellbeingCheckin
    {
        return HrWellbeingCheckin::create([
            'staff_user_id' => (int) $data['staff_user_id'],
            'manager_user_id' => $actor->id,
            'type' => $data['type'] ?? 'welfare',
            'notes' => $data['notes'] ?? null,
            'mood' => $data['mood'] ?? null,
            'follow_up_date' => $data['follow_up_date'] ?? null,
            'is_private' => (bool) ($data['is_private'] ?? true),
        ]);
    }

    public function updateCheckin(HrWellbeingCheckin $checkin, array $data): HrWellbeingCheckin
    {
        return DB::transaction(function () use ($checkin, $data): HrWellbeingCheckin {
            $locked = HrWellbeingCheckin::query()->lockForUpdate()->findOrFail($checkin->getKey());
            $payload = [];
            foreach (['type', 'notes', 'mood', 'follow_up_date'] as $field) {
                if (array_key_exists($field, $data)) {
                    $payload[$field] = $data[$field];
                }
            }
            if (array_key_exists('is_private', $data)) {
                $payload['is_private'] = (bool) $data['is_private'];
            }
            $locked->update($payload);

            return $locked->fresh();
        }, attempts: 1);
    }

    public function acknowledgeCheckin(HrWellbeingCheckin $checkin, int $staffUserId): HrWellbeingCheckin
    {
        return DB::transaction(function () use ($checkin, $staffUserId): HrWellbeingCheckin {
            $locked = HrWellbeingCheckin::query()
                ->where('staff_user_id', $staffUserId)
                ->where('is_private', false)
                ->lockForUpdate()
                ->findOrFail($checkin->getKey());
            if (! $locked->acknowledged_at) {
                $locked->update(['acknowledged_at' => now()]);
            }

            return $locked->fresh();
        }, attempts: 1);
    }

    // ---------------------------------------------------------------------
    // EAP referrals
    // ---------------------------------------------------------------------

    public function createEapReferral(User $actor, array $data, bool $isSelfReferral = false): HrEapReferral
    {
        return HrEapReferral::create([
            'staff_user_id' => $isSelfReferral ? $actor->id : (int) $data['staff_user_id'],
            'referred_by' => $actor->id,
            'reason_category' => $data['reason_category'] ?? 'wellbeing',
            'provider' => $data['provider'] ?? null,
            'status' => 'submitted',
            'consent_given' => $isSelfReferral ? true : (bool) ($data['consent_given'] ?? false),
            'is_self_referral' => $isSelfReferral,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    // ---------------------------------------------------------------------
    // Action plans (standalone + lifecycle + notes)
    // ---------------------------------------------------------------------

    public function createStandalonePlan(User $actor, array $data): HrEngagementActionPlan
    {
        $sourceType = $data['source_type'] ?? 'manual';
        $staffUserId = isset($data['staff_user_id']) ? (int) $data['staff_user_id'] : null;

        return DB::transaction(function () use ($actor, $data, $sourceType, $staffUserId): HrEngagementActionPlan {
            $plan = HrEngagementActionPlan::create([
                'survey_id' => $data['survey_id'] ?? null,
                'owner_user_id' => (int) $data['owner_user_id'],
                'staff_user_id' => $staffUserId,
                'source_type' => $sourceType,
                'source_id' => $data['source_id'] ?? $staffUserId,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'priority' => $data['priority'] ?? 'medium',
                'status' => $data['status'] ?? 'open',
                'progress_percent' => (int) ($data['progress_percent'] ?? 0),
                'due_date' => $data['due_date'] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->addPlanNote($plan, $actor, $this->sourceCreationLabel($sourceType), 'system');

            return $plan;
        }, attempts: 1);
    }

    public function createSurveyPlan(User $actor, HrEngagementSurvey $survey, array $data): HrEngagementActionPlan
    {
        return DB::transaction(function () use ($actor, $survey, $data): HrEngagementActionPlan {
            $lockedSurvey = HrEngagementSurvey::query()->lockForUpdate()->findOrFail($survey->getKey());
            $plan = HrEngagementActionPlan::query()->create([
                'survey_id' => $lockedSurvey->id,
                'owner_user_id' => (int) $data['owner_user_id'],
                'source_type' => 'survey',
                'source_id' => $lockedSurvey->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'priority' => $data['priority'],
                'status' => $data['status'] ?? 'open',
                'progress_percent' => (int) ($data['progress_percent'] ?? 0),
                'due_date' => $data['due_date'] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $this->addPlanNote($plan, $actor, 'Plan created from survey: '.$lockedSurvey->title.'.', 'system');

            return $plan;
        }, attempts: 1);
    }

    /**
     * Tell an action plan's owner they've been made responsible for it — unless
     * they created it themselves. Best-effort: a notification failure must never
     * roll back the plan. Closes the duty-of-care loop that previously stayed
     * silent until the plan's due date approached.
     */
    public function notifyOwnerAssigned(HrEngagementActionPlan $plan, User $actor): void
    {
        if (! $plan->owner_user_id || $plan->owner_user_id === $actor->id) {
            return;
        }

        $owner = User::find($plan->owner_user_id);
        if (! $owner) {
            return;
        }

        try {
            $owner->notify(new EngagementActionPlanAssignedNotification($plan, $actor->name));
        } catch (\Throwable $e) {
            Log::warning('Failed to send action-plan-assigned notification', [
                'plan_id' => $plan->id,
                'owner_id' => $plan->owner_user_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function sourceCreationLabel(string $sourceType): string
    {
        return match ($sourceType) {
            'flag' => 'Plan created from a wellbeing flag.',
            'survey' => 'Plan created from a survey.',
            default => 'Plan created.',
        };
    }

    public function addPlanNote(HrEngagementActionPlan $plan, ?User $actor, string $body, string $kind = 'note'): HrEngagementActionPlanNote
    {
        return DB::transaction(function () use ($plan, $actor, $body, $kind): HrEngagementActionPlanNote {
            $locked = HrEngagementActionPlan::query()->lockForUpdate()->findOrFail($plan->getKey());

            return $locked->notes()->create([
                'author_user_id' => $actor?->id,
                'kind' => $kind,
                'body' => $body,
            ]);
        }, attempts: 1);
    }

    public function updatePlan(HrEngagementActionPlan $plan, User $actor, array $data): HrEngagementActionPlan
    {
        return DB::transaction(function () use ($plan, $actor, $data): HrEngagementActionPlan {
            $locked = HrEngagementActionPlan::query()->lockForUpdate()->findOrFail($plan->getKey());
            if (in_array($locked->status, ['completed', 'cancelled'], true)) {
                throw ValidationException::withMessages([
                    'plan' => 'This plan is closed. Reopen it before making changes.',
                ]);
            }
            $note = $data['note'] ?? null;
            unset($data['note']);
            $previousStatus = $locked->status;
            $payload = [...$data, 'updated_by' => $actor->id];
            if (($payload['status'] ?? null) === 'completed') {
                $payload['completed_at'] = now()->toDateString();
                $payload['progress_percent'] = 100;
            } elseif (array_key_exists('status', $payload) && $payload['status'] !== 'completed') {
                $payload['completed_at'] = null;
            }
            $locked->update($payload);
            if (array_key_exists('status', $data) && $data['status'] !== $previousStatus) {
                $this->addPlanNote($locked, $actor, 'Status changed to '.str_replace('_', ' ', $data['status']).'.', 'system');
            }
            if (is_string($note) && trim($note) !== '') {
                $this->addPlanNote($locked, $actor, trim($note), 'note');
            }

            return $locked->fresh();
        }, attempts: 1);
    }

    public function reopenPlan(HrEngagementActionPlan $plan, User $actor): HrEngagementActionPlan
    {
        return DB::transaction(function () use ($plan, $actor): HrEngagementActionPlan {
            $locked = HrEngagementActionPlan::query()->lockForUpdate()->findOrFail($plan->getKey());
            if (! in_array($locked->status, ['completed', 'cancelled'], true)) {
                throw ValidationException::withMessages(['plan' => 'Only a closed plan can be reopened.']);
            }
            $locked->update([
                'status' => 'open',
                'completed_at' => null,
                'updated_by' => $actor->id,
            ]);
            $this->addPlanNote($locked, $actor, 'Plan reopened.', 'system');

            return $locked->fresh();
        }, attempts: 1);
    }

    public function cancelPlan(HrEngagementActionPlan $plan, User $actor, ?string $reason = null): HrEngagementActionPlan
    {
        return DB::transaction(function () use ($plan, $actor, $reason): HrEngagementActionPlan {
            $locked = HrEngagementActionPlan::query()->lockForUpdate()->findOrFail($plan->getKey());
            if (! in_array($locked->status, ['open', 'in_progress'], true)) {
                throw ValidationException::withMessages(['plan' => 'This plan is already closed.']);
            }
            $locked->update([
                'status' => 'cancelled',
                'updated_by' => $actor->id,
            ]);
            $this->addPlanNote($locked, $actor, $reason ? ('Plan cancelled: '.$reason) : 'Plan cancelled.', 'system');

            return $locked->fresh();
        }, attempts: 1);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function planNotes(HrEngagementActionPlan $plan): array
    {
        return $plan->notes()->with('author:id,name')->get()->map(fn (HrEngagementActionPlanNote $note) => [
            'id' => $note->id,
            'author' => $note->author?->name ?? ($note->kind === 'system' ? 'System' : 'Unknown'),
            'kind' => $note->kind,
            'body' => $note->body,
            'created_at' => $note->created_at?->toIso8601String(),
            'created_human' => $note->created_at ? Carbon::parse($note->created_at)->diffForHumans() : null,
        ])->all();
    }
}
