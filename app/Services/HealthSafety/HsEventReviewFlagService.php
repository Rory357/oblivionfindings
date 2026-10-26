<?php

namespace App\Services\HealthSafety;

use App\Models\HsEvent;
use App\Models\HsEventReviewFlag;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stephan's job 3 rules (26 Oct): a source change after a signed WorkSafe decision flags the event for re-review, and an
 * escalation of a closed event flags it. Neither ever changes the signed decision or reopens the event; a person decides.
 */
final class HsEventReviewFlagService
{
    private const RANK = [HsEvent::SEVERITY_LOW => 0, HsEvent::SEVERITY_MEDIUM => 1, HsEvent::SEVERITY_HIGH => 2, HsEvent::SEVERITY_CRITICAL => 3];

    /**
     * Flags for one source change landing on an event. $before is the event as it stood before the change was applied.
     *
     * @param  bool  $correctedAfterDecision  the source's facts were corrected after the signed WorkSafe decision
     */
    public function afterSourceChange(HsEvent $before, Model $source, ?int $revision, string $severityAfter,
        bool $correctedAfterDecision): void
    {
        if ($correctedAfterDecision && $before->hasSignedWorksafeDecision()) {
            $this->raise($before, HsEventReviewFlag::REASON_WORKSAFE_REVIEW, $source, $revision, $before->severity, $severityAfter);
        }
        if ($before->status === HsEvent::STATUS_CLOSED && $this->escalates($before, $severityAfter)) {
            $this->raise($before, HsEventReviewFlag::REASON_ESCALATED_AFTER_CLOSE, $source, $revision, $before->severity, $severityAfter);
        }
    }

    public function escalates(HsEvent $before, string $severityAfter): bool
    {
        return (self::RANK[$severityAfter] ?? 0) > (self::RANK[$before->severity] ?? 0);
    }

    public function raise(HsEvent $event, string $reason, Model $source, ?int $revision, ?string $from, ?string $to): HsEventReviewFlag
    {
        $identity = ['hs_event_id' => (int) $event->id, 'reason' => $reason, 'source_type' => $source->getMorphClass(),
            'source_id' => (int) $source->getKey(), 'source_revision' => $revision];
        // One flag per reason and source revision: a repeat delivery of the same change never adds another. A source
        // with no revisions (an old-style record) gets a new flag for each change once the last one was reviewed.
        $existing = HsEventReviewFlag::query()->where($identity)->when($revision === null, fn ($q) => $q->open())->first();
        if ($existing !== null) {
            return $existing;
        }
        $flag = HsEventReviewFlag::query()->create([...$identity, 'from_severity' => $from, 'to_severity' => $to,
            'raised_at' => now()->utc()]);
        AuditLogger::logOrFail('hs_event.review_flag.raised', $event,
            ['flag_id' => (int) $flag->id, 'reason' => $reason, 'source_revision' => $revision], null, true);

        return $flag;
    }

    /** The caller has admitted the event under the H&S event access rule and hazards.manage (the event write routes). */
    public function resolve(HsEventReviewFlag $flag, User $actor, string $note): HsEventReviewFlag
    {
        $note = trim($note);
        if (mb_strlen($note) < 10 || mb_strlen($note) > 2000) {
            throw ValidationException::withMessages(['note' => 'Say what you checked and decided, in 10 to 2,000 characters.']);
        }

        return DB::transaction(function () use ($flag, $actor, $note): HsEventReviewFlag {
            $locked = HsEventReviewFlag::query()->lockForUpdate()->findOrFail($flag->id);
            if ($locked->resolved_at !== null) {
                throw ValidationException::withMessages(['note' => 'Someone has already marked this reviewed.']);
            }
            $locked->forceFill(['resolved_at' => now()->utc(), 'resolved_by' => (int) $actor->id, 'resolution_note' => $note])->save();
            AuditLogger::logOrFail('hs_event.review_flag.resolved', $locked->event()->withTrashed()->firstOrFail(),
                ['flag_id' => (int) $locked->id, 'reason' => $locked->reason]);

            return $locked;
        });
    }

    /** Open flags for the event page (whose reader is already admitted): reason and severity step only. */
    public function present(HsEvent $event, bool $canResolve): array
    {
        return HsEventReviewFlag::query()->where('hs_event_id', $event->id)->open()->orderBy('raised_at')->get()
            ->map(fn (HsEventReviewFlag $flag) => [
                'id' => (int) $flag->id,
                'reason' => $flag->reason,
                'source_revision' => $flag->source_revision,
                'from_severity' => $flag->from_severity,
                'to_severity' => $flag->to_severity,
                'raised_at' => $flag->raised_at?->toIso8601String(),
                'can_resolve' => $canResolve,
            ])->values()->all();
    }
}
