<?php

namespace App\Domain\Governance\Http\Requests;

use App\Domain\Governance\Http\Requests\Concerns\MeetingDetailsRules;
use App\Domain\Governance\Models\GovernanceMeeting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMeetingRequest extends FormRequest
{
    use MeetingDetailsRules;

    public function authorize(): bool
    {
        return $this->user()->can('create', GovernanceMeeting::class);
    }

    public function rules(): array
    {
        return [
            'meeting_type' => ['required', Rule::in(self::MEETING_TYPES)],
            'board_committee_id' => 'nullable|exists:board_committees,id',
            'title' => 'required|string|max:255',
            'scheduled_at' => 'required|date|after:now',
            'duration_minutes' => 'required|integer|min:30|max:480',
            'location' => 'nullable|string|max:255',
            'virtual_link' => 'nullable|url|max:500',
            'notes' => 'nullable|string',
            'chair_id' => 'nullable|exists:board_members,id',
            'secretary_id' => 'nullable|exists:board_members,id',
            'quorum_required' => 'integer|min:25|max:100',
        ];
    }

    /** A new meeting always has its type and committee checked together. */
    protected function meetingTypeAndCommittee(): array
    {
        return [$this->input('meeting_type'), $this->input('board_committee_id'), true];
    }
}
