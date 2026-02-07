<?php

namespace App\Domain\Governance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Domain\Governance\Models\GovernanceMeeting::class);
    }

    public function rules(): array
    {
        return [
            'meeting_type' => 'required|in:full_board,audit_risk,people,finance,special_general,executive_session',
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
}
