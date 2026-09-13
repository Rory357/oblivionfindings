<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\BindsItBrowserActor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RecoverItSetupCommandRequest extends FormRequest
{
    use BindsItBrowserActor;

    public function authorize(): bool
    {
        return $this->user()?->isApproved() && $this->user()->canDo('it.manage') && $this->hasCurrentBrowserActor();
    }

    public function rules(): array
    {
        return [
            'actor_user_id' => ['required', 'integer', 'min:1'],
            'resource' => ['required', Rule::in(['teams', 'queues', 'services', 'catalogue-items', 'provisioning-templates'])],
        ];
    }
}
