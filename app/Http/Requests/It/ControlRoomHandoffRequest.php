<?php

namespace App\Http\Requests\It;

use Illuminate\Foundation\Http\FormRequest;

/** Command fields are validated by the canonical coordinator after its source lock. */
final class ControlRoomHandoffRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor !== null && $actor->approved_at !== null
            && $actor->canDo('it.view') && $actor->canDo('it.manage')
            && $actor->canDo('controlRoom.alerts.manage')
            && filter_var($this->input('viewer_user_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false
            && $this->integer('viewer_user_id') === (int) $actor->id;
    }

    public function rules(): array
    {
        return ['viewer_user_id' => ['required', 'integer', 'min:1'],
            ...($this->routeIs('it.control-room.handoff.preview')
                ? ['search' => ['nullable', 'string', 'max:120']]
                : ($this->route('requestUuid') === null ? ['request_uuid' => ['required', 'uuid']] : []))];
    }
}
