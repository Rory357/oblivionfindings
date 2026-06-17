<?php

namespace App\Services\Safeguarding;

use App\Models\SafeguardingConcern;

/**
 * Safeguarding redesign — Step 2.
 *
 * The single source of truth for the enforced concern lifecycle
 * (SAFEGUARDING_LIFECYCLE_PLAN.md §4). Used by the controller to gate generic
 * status changes and by the index/detail payloads to tell the UI which actions
 * are available + why an action is disabled.
 *
 * Closing goes through SafeguardingConcernController@close (never updateStatus),
 * and leaving `reported` goes through @triage — so neither `closed` nor the
 * exits from `reported` appear in the generic transition map below.
 */
class SafeguardingLifecycle
{
    /**
     * Legal targets for a *generic* status change (updateStatus), keyed by the
     * current status. Gates (investigation / report existence) are applied on
     * top of this map in {@see guardTransition()}.
     */
    public const TRANSITIONS = [
        'reported' => [],                                                  // leave only via triage
        'triaged' => ['investigating', 'referred_external'],
        'investigating' => ['action_plan', 'monitoring', 'referred_external'],
        'referred_external' => ['investigating', 'action_plan', 'monitoring'],
        'action_plan' => ['monitoring', 'investigating'],
        'monitoring' => ['action_plan'],                                   // close via @close
        'closed' => [],
        'no_action_required' => [],
    ];

    public const SUBSTANTIATIONS = ['substantiated', 'needs_enquiry', 'not_substantiated'];

    public const TRIAGE_PATHS = ['investigate', 'refer', 'no_action'];

    public const STATUS_LABELS = [
        'reported' => 'Awaiting triage',
        'triaged' => 'Triaged',
        'investigating' => 'Under investigation',
        'action_plan' => 'Action plan',
        'monitoring' => 'Monitoring',
        'referred_external' => 'Referred external',
        'closed' => 'Closed',
        'no_action_required' => 'No further action',
    ];

    public function label(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    /**
     * Whether a generic status change is permitted, and if not, a one-line
     * reason the UI can surface on the disabled action.
     *
     * @return array{allowed: bool, reason: ?string}
     */
    public function guardTransition(SafeguardingConcern $concern, string $to): array
    {
        $from = $concern->status;

        if ($from === $to) {
            return $this->deny('The concern is already at this stage.');
        }

        if ($from === 'reported') {
            return $this->deny('Triage the concern first.');
        }

        if ($to === 'closed') {
            return $this->deny('Use the Close action to close a concern.');
        }

        if ($to === 'no_action_required') {
            return $this->deny('"No further action" is set during triage.');
        }

        if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            return $this->deny(sprintf(
                "Can't move from %s to %s.",
                $this->label($from),
                $this->label($to),
            ));
        }

        if ($to === 'investigating' && ! $this->hasOpenInvestigation($concern)) {
            return $this->deny('Start an investigation to enter this stage.');
        }

        if ($to === 'referred_external' && $this->externalReportCount($concern) === 0) {
            return $this->deny('Log an external report before referring.');
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * Open (non-abandoned) investigations satisfy the W3 gate.
     */
    public function hasOpenInvestigation(SafeguardingConcern $concern): bool
    {
        return $concern->investigations()
            ->where('status', '!=', 'abandoned')
            ->exists();
    }

    public function externalReportCount(SafeguardingConcern $concern): int
    {
        return $concern->externalReports()->count();
    }

    /**
     * Non-cancelled / non-abandoned investigations that aren't yet completed.
     */
    public function openInvestigationCount(SafeguardingConcern $concern): int
    {
        return $concern->investigations()
            ->whereNotIn('status', ['completed', 'abandoned'])
            ->count();
    }

    /**
     * Action-plan items still requiring work (not completed / cancelled).
     */
    public function openActionCount(SafeguardingConcern $concern): int
    {
        return $concern->actionPlans()
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->count();
    }

    public function hasOpenWork(SafeguardingConcern $concern): bool
    {
        return $this->openInvestigationCount($concern) > 0
            || $this->openActionCount($concern) > 0;
    }

    /**
     * @return array{allowed: false, reason: string}
     */
    private function deny(string $reason): array
    {
        return ['allowed' => false, 'reason' => $reason];
    }
}
