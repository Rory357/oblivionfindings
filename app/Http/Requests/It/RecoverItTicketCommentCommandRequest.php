<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\BindsItBrowserActor;
use App\Http\Requests\It\Concerns\ConcealsInaccessibleItWork;
use Illuminate\Foundation\Http\FormRequest;

class RecoverItTicketCommentCommandRequest extends FormRequest
{
    use BindsItBrowserActor;
    use ConcealsInaccessibleItWork;

    public function authorize(): bool
    {
        $this->visibleTicketOrNotFound();

        return $this->user() !== null && $this->hasCurrentBrowserActor();
    }

    public function rules(): array
    {
        return ['actor_user_id' => ['required', 'integer', 'min:1']];
    }
}
