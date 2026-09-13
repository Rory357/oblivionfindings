<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\ConcealsInaccessibleItWork;
use App\Models\ItWorkTask;
use Illuminate\Foundation\Http\FormRequest;

class ItWorkTaskCommandIdentityRequest extends FormRequest
{
    use ConcealsInaccessibleItWork;

    public function authorize(): bool
    {
        $ticket = $this->workableTicketOrNotFound();
        if ($this->isMethod('get') && $this->has('actor_user_id')
            && filter_var($this->input('actor_user_id'), FILTER_VALIDATE_INT) !== false) {
            abort_unless($this->integer('actor_user_id') === (int) $this->user()?->id, 403);
        }
        if (filter_var($this->input('task_id'), FILTER_VALIDATE_INT) !== false && $this->integer('task_id') > 0) {
            abort_unless(ItWorkTask::query()->whereKey($this->integer('task_id'))->where('ticket_id', $ticket->id)->exists(), 404);
        }

        return (bool) $this->user()?->canDo('it.manage');
    }

    public function rules(): array
    {
        $requiresTask = ! in_array($this->route('operation'), ['create', 'reorder'], true);

        return [
            'actor_user_id' => [$this->isMethod('post') ? 'required' : 'sometimes', 'integer', 'min:1'],
            'task_id' => $requiresTask ? ['required', 'integer', 'min:1'] : ['prohibited'],
        ];
    }
}
