<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\BindsItBrowserActor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveItReplyTemplateRequest extends FormRequest
{
    use BindsItBrowserActor;

    public function authorize(): bool
    {
        return (bool) $this->user()?->canDo('it.manage') && $this->hasCurrentBrowserActor();
    }

    public function rules(): array
    {
        $template = $this->route('template');

        return [
            ...$this->browserActorRules(),
            'name' => ['required', 'string', 'max:120'],
            'audience' => ['required', Rule::in(['public', 'internal'])],
            'body' => ['required', 'string', 'max:20000'],
            'owner_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'review_due_at' => ['nullable', 'date'],
            'lock_version' => [...($template ? ['required'] : ['sometimes']), 'integer', 'min:1'],
        ];
    }
}
