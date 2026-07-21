<?php

namespace App\Http\Requests\It;

use App\Models\ItTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveItQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canDo('it.manage');
    }

    public function rules(): array
    {
        $queue = $this->route('queue');
        $required = $queue ? ['sometimes', 'required'] : ['required'];

        return [
            'key' => [
                ...$required, 'string', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('it_queues', 'key')->ignore($queue?->id),
            ],
            'name' => [...$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'team_id' => ['nullable', 'integer', Rule::exists('it_teams', 'id')],
            'routing_priority' => ['sometimes', 'integer', 'between:0,1000'],
            'is_default' => ['sometimes', 'boolean'],
            'work_types' => ['sometimes', 'array'],
            'work_types.*' => ['string', 'distinct', Rule::in(ItTicket::WORK_TYPES)],
            'categories' => ['sometimes', 'array'],
            'categories.*' => ['string', 'distinct', Rule::in(ItTicket::CATEGORIES)],
            'priorities' => ['sometimes', 'array'],
            'priorities.*' => ['string', 'distinct', Rule::in(ItTicket::PRIORITIES)],
            'service_ids' => ['sometimes', 'array'],
            'service_ids.*' => ['integer', 'distinct', Rule::exists('it_services', 'id')],
            'site_ids' => ['sometimes', 'array'],
            'site_ids.*' => ['integer', 'distinct', Rule::exists('sites', 'id')->where('is_active', true)->where('archived', false)],
            'default_assignee_user_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id'),
            ],
            'is_active' => [...($queue ? ['sometimes'] : ['required']), 'boolean'],
        ];
    }
}
