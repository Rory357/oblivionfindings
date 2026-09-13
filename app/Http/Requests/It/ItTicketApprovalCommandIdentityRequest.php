<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\ConcealsInaccessibleItWork;
use App\Models\ItTicketApproval;
use Illuminate\Foundation\Http\FormRequest;

class ItTicketApprovalCommandIdentityRequest extends FormRequest
{
    use ConcealsInaccessibleItWork;

    public function authorize(): bool
    {
        $ticket = $this->workableTicketOrNotFound();
        if ($this->has('actor_user_id') && filter_var($this->input('actor_user_id'), FILTER_VALIDATE_INT) !== false) {
            abort_unless($this->integer('actor_user_id') === (int) $this->user()?->id, 403);
        }
        if (filter_var($this->input('approval_id'), FILTER_VALIDATE_INT) !== false && $this->integer('approval_id') > 0) {
            abort_unless(ItTicketApproval::query()->whereKey($this->integer('approval_id'))->where('it_ticket_id', $ticket->id)->exists(), 404);
        }

        return (bool) $this->user()?->canDo('it.manage');
    }

    public function rules(): array
    {
        return ['actor_user_id' => [$this->isMethod('post') ? 'required' : 'sometimes', 'integer', 'min:1'],
            'approval_id' => $this->route('operation') !== 'request' ? ['required', 'integer', 'min:1'] : ['prohibited']];
    }
}
