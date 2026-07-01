<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEapReferral;
use App\Domain\Hr\Models\HrEngagementActionPlan;
use App\Domain\Hr\Models\HrEngagementActionPlanNote;
use App\Domain\Hr\Models\HrWellbeingCheckin;
use App\Domain\Hr\Models\HrWellbeingFlagAction;
use App\Domain\Hr\Models\HrWellbeingIndicator;
use App\Models\User;
use Illuminate\Support\Carbon;

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
        ?int $tenantId,
        int $staffUserId,
        string $action,
        ?string $reason = null,
        ?string $snoozeUntil = null,
    ): HrWellbeingFlagAction {
        $indicatorId = HrWellbeingIndicator::query()
            ->where('user_id', $staffUserId)
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->orderByDesc('id')
            ->value('id');

        return HrWellbeingFlagAction::create([
            'tenant_id' => $tenantId,
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
        $latest = HrWellbeingFlagAction::query()
            ->where('staff_user_id', $staffUserId)
            ->where('actor_user_id', $actor->id)
            ->orderByDesc('id')
            ->first();

        if (! $latest) {
            return false;
        }

        $latest->delete();

        return true;
    }

    // ---------------------------------------------------------------------
    // Check-ins
    // ---------------------------------------------------------------------

    public function createCheckin(User $actor, ?int $tenantId, array $data): HrWellbeingCheckin
    {
        return HrWellbeingCheckin::create([
            'tenant_id' => $tenantId,
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
        $checkin->update(array_filter([
            'type' => $data['type'] ?? null,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : null,
            'mood' => $data['mood'] ?? null,
            'follow_up_date' => $data['follow_up_date'] ?? null,
            'is_private' => array_key_exists('is_private', $data) ? (bool) $data['is_private'] : null,
        ], fn ($value) => $value !== null));

        return $checkin->fresh();
    }

    public function acknowledgeCheckin(HrWellbeingCheckin $checkin): HrWellbeingCheckin
    {
        if (! $checkin->acknowledged_at) {
            $checkin->update(['acknowledged_at' => now()]);
        }

        return $checkin->fresh();
    }

    // ---------------------------------------------------------------------
    // EAP referrals
    // ---------------------------------------------------------------------

    public function createEapReferral(User $actor, ?int $tenantId, array $data, bool $isSelfReferral = false): HrEapReferral
    {
        return HrEapReferral::create([
            'tenant_id' => $tenantId,
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

    public function createStandalonePlan(User $actor, ?int $tenantId, array $data): HrEngagementActionPlan
    {
        $sourceType = $data['source_type'] ?? 'manual';
        $staffUserId = isset($data['staff_user_id']) ? (int) $data['staff_user_id'] : null;

        $plan = HrEngagementActionPlan::create([
            'survey_id' => $data['survey_id'] ?? null,
            'tenant_id' => $tenantId,
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
        return $plan->notes()->create([
            'author_user_id' => $actor?->id,
            'kind' => $kind,
            'body' => $body,
        ]);
    }

    public function reopenPlan(HrEngagementActionPlan $plan, User $actor): HrEngagementActionPlan
    {
        $plan->update([
            'status' => 'open',
            'completed_at' => null,
            'updated_by' => $actor->id,
        ]);

        $this->addPlanNote($plan, $actor, 'Plan reopened.', 'system');

        return $plan->fresh();
    }

    public function cancelPlan(HrEngagementActionPlan $plan, User $actor, ?string $reason = null): HrEngagementActionPlan
    {
        $plan->update([
            'status' => 'cancelled',
            'updated_by' => $actor->id,
        ]);

        $this->addPlanNote($plan, $actor, $reason ? ('Plan cancelled: ' . $reason) : 'Plan cancelled.', 'system');

        return $plan->fresh();
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
