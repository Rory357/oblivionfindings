<?php

namespace App\Domain\Governance\Http\Requests;

use App\Domain\Governance\Http\Requests\Concerns\MeetingDetailsRules;
use App\Domain\Governance\Models\GovernanceMeeting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMeetingRequest extends FormRequest
{
    use MeetingDetailsRules;

    /**
     * Editing a meeting schedules or cancels it. Every other stage comes from
     * its own step — preparing the board pack, writing, approving and signing
     * the minutes — so an edit can't skip those controls.
     */
    public const EDITABLE_STATUSES = ['scheduled', 'cancelled'];

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('meeting'));
    }

    public function rules(): array
    {
        return [
            'meeting_type' => ['sometimes', Rule::in(self::MEETING_TYPES)],
            'board_committee_id' => 'nullable|exists:board_committees,id',
            'title' => 'sometimes|string|max:255',
            'scheduled_at' => 'sometimes|date',
            'duration_minutes' => 'sometimes|integer|min:30|max:480',
            'location' => 'nullable|string|max:255',
            'virtual_link' => 'nullable|url|max:500',
            'notes' => 'nullable|string',
            'status' => ['sometimes', Rule::in($this->allowedStatuses())],
            'chair_id' => 'nullable|exists:board_members,id',
            'secretary_id' => 'nullable|exists:board_members,id',
            'quorum_required' => 'sometimes|integer|min:25|max:100',
        ];
    }

    /**
     * Scheduled or cancelled — plus the meeting's current status, so saving
     * other details never fails just because the meeting has moved on.
     *
     * @return array<int, string>
     */
    protected function allowedStatuses(): array
    {
        $meeting = $this->route('meeting');
        $current = $meeting instanceof GovernanceMeeting ? $meeting->status : null;

        return array_values(array_unique(array_filter([...self::EDITABLE_STATUSES, $current])));
    }

    /**
     * Only checked when the edit touches the type or the committee, against
     * the values the meeting would be left with.
     */
    protected function meetingTypeAndCommittee(): array
    {
        $meeting = $this->route('meeting');
        $checks = $this->has('meeting_type') || $this->has('board_committee_id');

        $type = $this->has('meeting_type')
            ? $this->input('meeting_type')
            : ($meeting instanceof GovernanceMeeting ? $meeting->meeting_type : null);
        $committeeId = $this->has('board_committee_id')
            ? $this->input('board_committee_id')
            : ($meeting instanceof GovernanceMeeting ? $meeting->board_committee_id : null);

        return [$type, $committeeId, $checks];
    }
}
