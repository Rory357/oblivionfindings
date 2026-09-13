<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\BindsItBrowserActor;
use App\Http\Requests\It\Concerns\ConcealsInaccessibleItWork;
use Illuminate\Foundation\Http\FormRequest;

class ReadTicketMergeCommandRequest extends FormRequest
{
    use BindsItBrowserActor, ConcealsInaccessibleItWork;

    public function authorize(): bool
    {
        $this->workableMergeParentsOrNotFound();

        return $this->user()?->canDo('it.manage') && $this->hasCurrentBrowserActor();
    }

    public function rules(): array
    {
        return ['actor_user_id' => ['required', 'integer', 'min:1'], 'target_ticket_id' => ['required', 'integer', 'min:1']];
    }
}
