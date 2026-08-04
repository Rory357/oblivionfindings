<?php

namespace App\Http\Requests\It;

use App\Models\ItAttachment;
use App\Models\ItTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreItTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', ItTicket::class);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['required', Rule::in(ItTicket::CATEGORIES)],
            'priority' => ['required', Rule::in(ItTicket::PRIORITIES)],
            'work_type' => ['nullable', Rule::in(ItTicket::INTAKE_WORK_TYPES)],
            'it_service_id' => ['nullable', 'integer', 'exists:it_services,id'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'is_organisation_wide' => ['nullable', 'boolean'],
            'subcategory' => ['nullable', 'string', 'max:255'],
            'requester_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'asset_id' => ['nullable', 'integer', 'exists:assets,id'],
            'device_id' => ['nullable', 'integer', 'exists:devices,id'],
            'watchers' => ['nullable', 'array'],
            'watchers.*' => ['integer', 'exists:users,id'],
            'provisioning_request_id' => ['nullable', 'integer', 'exists:it_provisioning_requests,id'],
            'attachments' => ['sometimes', 'array', 'max:5'],
            'attachments.*' => [
                'file',
                'max:'.ItAttachment::MAX_SIZE_KB,
                'mimes:'.ItAttachment::ALLOWED_MIMES,
            ],
        ];
    }
}
