<?php

namespace App\Domain\Governance\Http\Requests\Concerns;

use App\Support\WorkerClock;

/**
 * Shared by StoreResolutionRequest and UpdateResolutionRequest: NZ wall-time
 * deadlines, the voting rules the outcome engine supports, and plain
 * validation messages (vocabulary.md — no internal field names).
 */
trait ResolutionAuthoringRules
{
    /** Voting rules Resolution::determineOutcome() actually implements. */
    public const VOTING_THRESHOLDS = ['simple_majority', 'two_thirds', 'unanimous'];

    /**
     * The wizard's `<input type="datetime-local">` sends NZ wall time
     * ("2026-09-20T17:00"). Convert it to a UTC instant before validation, so
     * `after:now` compares real instants and the stored deadline isn't
     * 12 hours out. Values that already carry an offset keep it.
     */
    protected function prepareForValidation(): void
    {
        $deadline = $this->input('voting_deadline');

        if (! is_string($deadline) || trim($deadline) === '') {
            return;
        }

        try {
            $utc = WorkerClock::toUtc(trim($deadline));
        } catch (\Throwable) {
            return; // the `date` rule reports an unreadable value
        }

        if ($utc !== null) {
            $this->merge(['voting_deadline' => $utc->toIso8601String()]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Give the resolution a title.',
            'title.max' => 'Keep the title under 255 characters.',
            'purpose.in' => 'Choose whether this paper is for decision, for discussion or for information.',
            'type.in' => 'Choose how the resolution passes: more For than Against, at least two-thirds For, or everyone entitled votes For.',
            'voting_threshold.in' => 'Choose how the resolution passes: more For than Against, at least two-thirds For, or everyone entitled votes For.',
            'meeting_id.exists' => "The meeting you chose doesn't exist any more.",
            'governance_meeting_id.exists' => "The meeting you chose doesn't exist any more.",
            'board_committee_id.exists' => "The committee you chose doesn't exist any more.",
            'voting_deadline.date' => 'Enter the voting deadline as a date and time.',
            'voting_deadline.after' => 'Pick a voting deadline in the future.',
            'options.*.label.max' => 'Keep each option title under 255 characters.',
            'follow_up_actions.*.title.max' => 'Keep each follow-up action under 255 characters.',
            'follow_up_actions.*.assigned_to.exists' => "The person responsible for a follow-up action doesn't have an account any more. Choose someone else.",
            'follow_up_actions.*.due_date.date' => 'Enter a due date for each follow-up action.',
            'authority_binding.subject_type.in' => 'Choose a budget, budget change, strategic plan, performance review or voting rules for this resolution to approve.',
            'authority_binding.subject_type.required_with' => 'Choose what this resolution will approve.',
            'authority_binding.subject_id.required_with' => 'Choose what this resolution will approve.',
            'expected_version.integer' => 'Reload the page and try again.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => 'title',
            'exact_motion' => 'resolution wording',
            'context' => 'background',
            'description' => 'background',
            'purpose' => 'purpose',
            'type' => 'voting rule',
            'voting_threshold' => 'voting rule',
            'voting_deadline' => 'voting deadline',
            'meeting_id' => 'meeting',
            'governance_meeting_id' => 'meeting',
            'board_committee_id' => 'committee',
            'recommendation' => "management's recommendation",
            'single_option_reason' => "reason there's only one option",
            'service_user_implications' => 'effect on the people we support',
            'risk_equity_implications' => 'risk and fairness',
            'follow_up_actions' => 'follow-up actions',
            'authority_binding' => 'what this resolution approves',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function sharedResolutionRules(): array
    {
        return [
            'exact_motion' => 'nullable|string',
            'purpose' => 'nullable|string|in:decision,discussion,information',
            'decision_type' => 'nullable|string|max:100',
            'type' => 'nullable|string|in:ordinary,special,unanimous',
            'context' => 'nullable|string',
            'description' => 'nullable|string',
            'options' => 'nullable|array',
            'options.*.label' => 'nullable|string|max:255',
            'options.*.description' => 'nullable|string',
            'options.*.benefits' => 'nullable|string',
            'options.*.drawbacks' => 'nullable|string',
            'single_option_reason' => 'nullable|string',
            'recommendation' => 'nullable|string',
            'cost_impact' => 'nullable|array',
            'risk_impact' => 'nullable|array',
            'service_user_implications' => 'nullable|string',
            'risk_equity_implications' => 'nullable|string',
            'attachments' => 'nullable|array',
            'follow_up_actions' => 'nullable|array',
            'follow_up_actions.*.title' => 'nullable|string|max:255',
            'follow_up_actions.*.assigned_to' => 'nullable|integer|exists:users,id',
            'follow_up_actions.*.due_date' => 'nullable|date',
            'meeting_id' => 'nullable|exists:governance_meetings,id',
            'governance_meeting_id' => 'nullable|exists:governance_meetings,id',
            'board_committee_id' => 'nullable|exists:board_committees,id',
            'voting_deadline' => 'nullable|date|after:now',
            'voting_threshold' => 'nullable|string|in:'.implode(',', self::VOTING_THRESHOLDS),
            'quorum_required' => 'nullable|boolean',
            'publish_now' => 'nullable|boolean',
            // Explicit decision authority: the exact record this paper approves.
            // Send null to remove it. The revision fingerprint is derived
            // server-side, never accepted.
            'authority_binding' => 'nullable|array',
            'authority_binding.subject_type' => 'nullable|required_with:authority_binding.subject_id|string|in:voting_profile,strategic_plan,budget_adjustment,budget,performance_review',
            'authority_binding.subject_id' => 'nullable|required_with:authority_binding.subject_type|integer|min:1',
        ];
    }
}
