<?php

namespace App\Services\HealthSafety;

use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\HsInvestigationCorrection;
use App\Models\User;
use App\Notifications\AppEventNotification;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Answer 20 (Stephan, 1 Nov 2026): correcting a done investigation's findings. A correction is proposed with a reason,
 * then reviewed and approved by independent H&S staff, as the original findings were: the reviewer is not the proposer
 * or anyone who investigated or submitted the work; the approver is none of those and not the reviewer. The original
 * stands until approval and is never overwritten. Every step is audited (kept 10+ years) and the review owner is told.
 *
 * Callers pass an event the actor may read (the controller resolves it through the event read) and an actor who holds
 * hazards.manage (route middleware).
 */
final class HsInvestigationCorrectionService
{
    public function propose(HsEvent $event, HsInvestigation $investigation, User $actor, string $after, string $reason): HsInvestigationCorrection
    {
        return DB::transaction(function () use ($event, $investigation, $actor, $after, $reason): HsInvestigationCorrection {
            $investigation = $this->lockedInvestigation($event, $investigation);
            if (! $event->isOpen()) {
                throw ValidationException::withMessages(['after_value' => 'This safety review is closed, so its findings can\'t be corrected here.']);
            }
            if (! $investigation->isCompleted()) {
                throw ValidationException::withMessages(['after_value' => 'Only agreed findings are corrected. Change them in the investigation while it is open.']);
            }
            $after = trim($after);
            $reason = trim($reason);
            $current = (string) $investigation->effectiveFindingsSummary();
            if ($after === '' || $after === trim($current)) {
                throw ValidationException::withMessages(['after_value' => 'Write the corrected findings.']);
            }
            if (mb_strlen($reason) < 10) {
                throw ValidationException::withMessages(['reason' => 'Say why the findings need correcting (at least 10 characters).']);
            }
            if ($investigation->corrections()->whereIn('status', [HsInvestigationCorrection::STATUS_PROPOSED, HsInvestigationCorrection::STATUS_REVIEWED])->exists()) {
                throw ValidationException::withMessages(['after_value' => 'A correction to these findings is already waiting for review.']);
            }

            $correction = HsInvestigationCorrection::create([
                'hs_investigation_id' => $investigation->id,
                'hs_event_id' => $event->id,
                'field' => HsInvestigationCorrection::FIELD_FINDINGS,
                'before_value' => $current !== '' ? $current : null,
                'after_value' => $after,
                'reason' => $reason,
                'status' => HsInvestigationCorrection::STATUS_PROPOSED,
                'proposed_by_id' => $actor->id,
                'proposed_by_name' => (string) $actor->name,
                'proposed_at' => now(),
            ]);
            AuditLogger::logOrFail('healthSafety.investigation.correctionProposed', $investigation, [
                'actor_id' => $actor->id, 'hs_event_id' => $event->id, 'correction_id' => $correction->id, 'reason' => $reason,
            ]);
            $this->tellOwner($event, $actor, 'A correction to the findings of '.$event->reference_number.' is waiting for review.', $correction);

            return $correction;
        }, 3);
    }

    public function review(HsEvent $event, HsInvestigationCorrection $correction, User $actor): HsInvestigationCorrection
    {
        return $this->decide($event, $correction, $actor, 'review');
    }

    public function approve(HsEvent $event, HsInvestigationCorrection $correction, User $actor): HsInvestigationCorrection
    {
        return $this->decide($event, $correction, $actor, 'approval');
    }

    public function reject(HsEvent $event, HsInvestigationCorrection $correction, User $actor, string $why): HsInvestigationCorrection
    {
        return $this->decide($event, $correction, $actor, 'rejection', trim($why));
    }

    private function decide(HsEvent $event, HsInvestigationCorrection $correction, User $actor, string $decision, ?string $why = null): HsInvestigationCorrection
    {
        return DB::transaction(function () use ($event, $correction, $actor, $decision, $why): HsInvestigationCorrection {
            $correction = HsInvestigationCorrection::query()->whereKey($correction->id)->where('hs_event_id', $event->id)->lockForUpdate()->firstOrFail();
            $investigation = $this->lockedInvestigation($event, $correction->investigation()->firstOrFail());
            if (! $event->isOpen()) {
                throw ValidationException::withMessages(['correction' => 'This safety review is closed.']);
            }
            $expected = match ($decision) {
                'review' => [HsInvestigationCorrection::STATUS_PROPOSED],
                'approval' => [HsInvestigationCorrection::STATUS_REVIEWED],
                default => [HsInvestigationCorrection::STATUS_PROPOSED, HsInvestigationCorrection::STATUS_REVIEWED],
            };
            if (! in_array($correction->status, $expected, true)) {
                throw ValidationException::withMessages(['correction' => 'This correction has already moved on. Reload the safety review.']);
            }
            $this->assertIndependent($investigation, $correction, $actor, $decision);
            if ($decision === 'rejection' && mb_strlen((string) $why) < 10) {
                throw ValidationException::withMessages(['reason' => 'Say why the correction isn\'t accepted (at least 10 characters).']);
            }

            $now = now();
            $correction->update(match ($decision) {
                'review' => ['status' => HsInvestigationCorrection::STATUS_REVIEWED, 'reviewed_by_id' => $actor->id, 'reviewed_at' => $now],
                'approval' => ['status' => HsInvestigationCorrection::STATUS_APPROVED, 'approved_by_id' => $actor->id, 'approved_at' => $now],
                default => ['status' => HsInvestigationCorrection::STATUS_REJECTED, 'rejected_by_id' => $actor->id, 'rejected_at' => $now, 'rejection_reason' => $why],
            });
            AuditLogger::logOrFail('healthSafety.investigation.correction'.match ($decision) {
                'review' => 'Reviewed', 'approval' => 'Approved', default => 'Rejected',
            }, $investigation, [
                'actor_id' => $actor->id, 'hs_event_id' => $event->id, 'correction_id' => $correction->id,
                'reason' => $why,
            ]);
            if ($decision !== 'review') {
                $this->tellOwner($event, $actor, $decision === 'approval'
                    ? 'The correction to the findings of '.$event->reference_number.' was approved.'
                    : 'The correction to the findings of '.$event->reference_number.' wasn\'t accepted.', $correction);
            }

            return $correction->fresh();
        }, 3);
    }

    /** Independent assurance, as for the original findings (HsInvestigationService::assertIndependentAssuranceActor). */
    private function assertIndependent(HsInvestigation $investigation, HsInvestigationCorrection $correction, User $actor, string $decision): void
    {
        $excluded = collect([
            $investigation->lead_investigator_id,
            $investigation->submitted_by_id,
            ...($investigation->team_member_ids ?? []),
            $correction->proposed_by_id,
        ])->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)->map(fn (mixed $id): int => (int) $id);
        if ($decision === 'approval' && $correction->reviewed_by_id) {
            $excluded->push((int) $correction->reviewed_by_id);
        }
        if ($excluded->contains((int) $actor->id)) {
            throw ValidationException::withMessages(['correction' => 'Someone who didn\'t propose this correction, investigate or submit the work'
                .($decision === 'approval' ? ', or review the correction,' : '').' must do this.']);
        }
    }

    private function lockedInvestigation(HsEvent $event, HsInvestigation $investigation): HsInvestigation
    {
        return HsInvestigation::query()->whereKey($investigation->id)->where('hs_event_id', $event->id)->lockForUpdate()->firstOrFail();
    }

    private function tellOwner(HsEvent $event, User $actor, string $message, HsInvestigationCorrection $correction): void
    {
        $owner = $event->owner_user_id ? User::query()->find($event->owner_user_id) : null;
        if ($owner === null || (int) $owner->id === (int) $actor->id) {
            return;
        }
        $owner->notify(new AppEventNotification([
            'title' => 'Findings correction',
            'message' => $message,
            'type' => 'health_safety_findings_correction',
            'hs_event_id' => $event->id,
            'correction_id' => $correction->id,
            'url' => '/health-safety/events/'.$event->id,
        ]));
    }
}
