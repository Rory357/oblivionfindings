<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\BindsItBrowserActor;
use App\Models\ItTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveItRecurrencePlanRequest extends FormRequest
{
    use BindsItBrowserActor;

    public function authorize(): bool
    {
        return (bool) $this->user()?->canDo('it.manage') && $this->hasCurrentBrowserActor();
    }

    public function rules(): array
    {
        $plan = $this->route('plan');

        return [
            ...$this->browserActorRules(),
            'name' => ['required', 'string', 'max:120'],
            'cron_expression' => ['required', 'string', 'max:100'],
            'timezone' => ['sometimes', 'required', 'timezone'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'exception_dates' => ['sometimes', 'array', 'max:100'],
            'exception_dates.*' => ['date_format:Y-m-d', 'distinct'],
            'owner_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'ticket_template' => ['required', 'array'],
            'ticket_template.title' => ['required', 'string', 'max:255'],
            'ticket_template.description' => ['nullable', 'string', 'max:10000'],
            'ticket_template.site_id' => ['required', 'integer', Rule::exists('sites', 'id')->where('is_active', true)->where('archived', false)],
            'ticket_template.category' => ['sometimes', 'required', Rule::in(ItTicket::CATEGORIES)],
            'ticket_template.work_type' => ['sometimes', 'required', Rule::in(ItTicket::WORK_TYPES)],
            'ticket_template.priority' => ['sometimes', 'required', Rule::in(ItTicket::PRIORITIES)],
            'ticket_template.it_service_id' => ['nullable', 'integer', Rule::exists('it_services', 'id')],
            'lock_version' => [...($plan ? ['required'] : ['sometimes']), 'integer', 'min:1'],
        ];
    }
}
