<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\BindsItBrowserActor;
use Illuminate\Foundation\Http\FormRequest;

class RecoverCatalogSubmissionRequest extends FormRequest
{
    use BindsItBrowserActor;

    public function authorize(): bool
    {
        return $this->user()?->isApproved()
            && ($this->user()->canDo('it.request') || $this->user()->canDo('it.manage'))
            && $this->hasCurrentBrowserActor();
    }

    public function rules(): array
    {
        return [
            'actor_user_id' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }
}
