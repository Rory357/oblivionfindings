<?php

namespace App\Support\HealthSafety;

use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;

/**
 * "The safety review" card on a restraint or injury record (boards G4-5 and G4-8, approved by Stephan 1 Nov 2026): its
 * reference, status, owner and the facts its five steps are drawn from. Scalars only.
 *
 * Decision 6: the caller builds this ONLY after it has proved the viewer can open the safety review. A viewer who
 * can't gets no card, no "hidden" line and no closer: the record says nothing about it.
 */
final class LinkedSafetyReviewSummary
{
    private const DONE = [HsCorrectiveAction::STATUS_COMPLETED, HsCorrectiveAction::STATUS_VERIFIED, HsCorrectiveAction::STATUS_CLOSED];

    public static function make(HsEvent $event, CurrentAuthorizationReads $reads): array
    {
        $owner = $event->owner_user_id ? $reads->query(User::query())->whereKey($event->owner_user_id)->value('name') : null;
        $investigation = $reads->query(HsInvestigation::query())->where('hs_event_id', $event->id)->orderByDesc('id')
            ->first(['id', 'status', 'completed_at', 'reviewed_at']);
        $actions = $reads->query(HsCorrectiveAction::query())->where('hs_event_id', $event->id)
            ->get(['id', 'status', 'verified_at', 'effectiveness_confirmed', 'completed_at']);
        $investigationDone = $investigation && in_array($investigation->status, ['reviewed', 'completed'], true);

        return [
            'id' => (int) $event->id,
            'reference' => $event->reference_number,
            'status' => $event->status,
            'severity' => $event->severity,
            'url' => '/health-safety/events/'.$event->id,
            'owner' => is_string($owner) ? $owner : null,
            'assigned_at' => $event->accepted_at?->toIso8601String(),
            'investigation' => [
                'required' => (bool) $event->investigation_required,
                'started' => $investigation !== null,
                'done_at' => $investigationDone ? ($investigation->reviewed_at ?? $investigation->completed_at)?->toIso8601String() : null,
                'done' => $investigationDone,
            ],
            'actions' => [
                'total' => $actions->count(),
                'done' => $actions->filter(fn ($a) => in_array($a->status, self::DONE, true))->count(),
                'last_done_at' => $actions->max('completed_at')?->toIso8601String(),
                'checked' => $actions->filter(fn ($a) => $a->verified_at !== null)->count(),
                'failed' => $actions->filter(fn ($a) => $a->verified_at !== null && $a->effectiveness_confirmed === false)->count(),
            ],
            'worksafe' => [
                'decided' => $event->hasSignedWorksafeDecision(),
                'notifiable' => $event->worksafe_notifiable === null ? null : (bool) $event->worksafe_notifiable,
                'decided_at' => $event->worksafe_decided_at?->toIso8601String(),
            ],
            'closed_at' => $event->closed_at?->toIso8601String(),
        ];
    }
}
