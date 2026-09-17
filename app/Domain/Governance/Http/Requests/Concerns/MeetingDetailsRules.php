<?php

namespace App\Domain\Governance\Http\Requests\Concerns;

use App\Domain\Governance\Models\BoardCommittee;
use App\Domain\Governance\Support\GovernanceLabels;
use App\Support\WorkerClock;
use Illuminate\Validation\Validator;

/**
 * Shared by StoreMeetingRequest and UpdateMeetingRequest: NZ wall-time start
 * times, the committee that belongs to each meeting type, and plain
 * validation messages (vocabulary.md — no internal field names).
 */
trait MeetingDetailsRules
{
    /** Meeting types the scheduling wizard offers, in display order. */
    public const MEETING_TYPES = ['full_board', 'audit_risk', 'people', 'finance', 'special_general', 'executive_session'];

    /** A committee meeting's type is the committee's own type. */
    public const COMMITTEE_MEETING_TYPES = ['audit_risk', 'people', 'finance'];

    /** Meetings of the whole board never belong to a committee. */
    public const WHOLE_BOARD_MEETING_TYPES = ['full_board', 'special_general'];

    /**
     * The wizard sends the start time as a UTC instant; anything without an
     * offset (a datetime-local value) is NZ wall time. Convert before
     * validation so `after:now` compares real instants.
     */
    protected function prepareForValidation(): void
    {
        $start = $this->input('scheduled_at');

        if (! is_string($start) || trim($start) === '') {
            return;
        }

        try {
            $utc = WorkerClock::toUtc(trim($start));
        } catch (\Throwable) {
            return; // the `date` rule reports an unreadable value
        }

        if ($utc !== null) {
            $this->merge(['scheduled_at' => $utc->toIso8601String()]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'meeting_type.required' => 'Choose the type of meeting.',
            'meeting_type.in' => 'Choose one of the meeting types listed.',
            'board_committee_id.exists' => "The committee you chose doesn't exist any more.",
            'title.required' => 'Give the meeting a title.',
            'title.max' => 'Keep the title under 255 characters.',
            'scheduled_at.required' => 'Choose the date and start time.',
            'scheduled_at.date' => 'Enter the start as a date and time.',
            'scheduled_at.after' => 'New meetings must be scheduled in the future.',
            'duration_minutes.required' => 'Say how long the meeting runs, between 30 minutes and 8 hours.',
            'duration_minutes.integer' => 'Enter the length of the meeting in whole minutes.',
            'duration_minutes.min' => 'A meeting must be at least 30 minutes long.',
            'duration_minutes.max' => 'A meeting can be at most 8 hours (480 minutes) long.',
            'location.max' => 'Keep the location under 255 characters.',
            'virtual_link.url' => 'Enter the full video link, starting with https://',
            'virtual_link.max' => 'The video link is too long.',
            'chair_id.exists' => 'Choose the chair from the list of board members.',
            'secretary_id.exists' => 'Choose the secretary from the list of board members.',
            'quorum_required.integer' => 'Enter the quorum as a whole percentage.',
            'quorum_required.min' => 'The quorum must be at least 25% of members.',
            'quorum_required.max' => 'The quorum can be at most 100% of members.',
            'status.in' => 'Editing a meeting can only keep it scheduled or cancel it. The other stages follow from the board pack and the minutes.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'meeting_type' => 'type of meeting',
            'board_committee_id' => 'committee',
            'scheduled_at' => 'start time',
            'duration_minutes' => 'length of the meeting',
            'virtual_link' => 'video link',
            'chair_id' => 'chair',
            'secretary_id' => 'secretary',
            'quorum_required' => 'quorum',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['meeting_type', 'board_committee_id'])) {
                return;
            }

            [$type, $committeeId, $checks] = $this->meetingTypeAndCommittee();
            if (! $checks || ! is_string($type)) {
                return;
            }

            $message = self::committeeMismatch($type, $committeeId);
            if ($message !== null) {
                $validator->errors()->add('board_committee_id', $message);
            }
        }];
    }

    /**
     * The meeting type and committee this request would leave the meeting
     * with, and whether they need checking.
     *
     * @return array{0: mixed, 1: mixed, 2: bool}
     */
    abstract protected function meetingTypeAndCommittee(): array;

    /**
     * Why a committee doesn't fit a type of meeting, in plain words — or null
     * when it fits. Whole-board meetings have no committee; committee
     * meetings need a committee of their own type; board-only sessions may
     * belong to any committee (its members can then see the session).
     */
    public static function committeeMismatch(string $type, mixed $committeeId): ?string
    {
        $hasCommittee = $committeeId !== null && $committeeId !== '';

        if (in_array($type, self::WHOLE_BOARD_MEETING_TYPES, true)) {
            return $hasCommittee
                ? 'This type of meeting is for the whole board, so it can\'t belong to a committee. Remove the committee, or choose the committee\'s own meeting type.'
                : null;
        }

        if (! in_array($type, self::COMMITTEE_MEETING_TYPES, true)) {
            return null;
        }

        $committeeLabel = mb_strtolower(GovernanceLabels::label('meeting_type', $type));

        if (! $hasCommittee) {
            return BoardCommittee::query()->where('committee_type', $type)->exists()
                ? "Choose which {$committeeLabel} this meeting is for."
                : "There's no {$committeeLabel} set up yet, so this meeting can't be scheduled for it. Ask an administrator to add the committee, or choose another type of meeting.";
        }

        $committeeType = BoardCommittee::query()->whereKey($committeeId)->value('committee_type');

        return $committeeType === $type
            ? null
            : "The committee you chose isn't the {$committeeLabel}. Choose the {$committeeLabel}, or change the type of meeting.";
    }
}
