<?php

namespace App\Domain\Governance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('meeting'));
    }

    public function rules(): array
    {
        return [
            'title' => 'sometimes|string|max:255',
            'scheduled_at' => 'sometimes|date',
            'duration_minutes' => 'sometimes|integer|min:30|max:480',
            'location' => 'nullable|string|max:255',
            'virtual_link' => 'nullable|url|max:500',
            'notes' => 'nullable|string',
            'status' => 'sometimes|in:scheduled,agenda_draft,agenda_final,in_progress,minutes_draft,minutes_review,minutes_approved,minutes_signed,archived',
            'chair_id' => 'nullable|exists:board_members,id',
            'secretary_id' => 'nullable|exists:board_members,id',
        ];
    }
}
