<?php

namespace App\Http\Requests\It\Api;

use App\Domain\It\Services\ItApiFieldPolicy;
use App\Models\ItServiceIdentity;
use App\Models\ItTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class StoreItApiWorkItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('it_service_identity') instanceof ItServiceIdentity;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $identity = $this->identity();
        $siteIds = array_map('intval', $identity->allowed_site_ids ?? []);

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['required', Rule::in(ItTicket::CATEGORIES)],
            'subcategory' => ['nullable', 'string', 'max:255'],
            'priority' => ['required', Rule::in(ItTicket::PRIORITIES)],
            'impact' => ['nullable', Rule::in(ItTicket::IMPACTS)],
            'urgency' => ['nullable', Rule::in(ItTicket::URGENCIES)],
            'work_type' => ['required', Rule::in($identity->allowed_work_types ?? [])],
            'site_id' => [
                'nullable', 'integer',
                Rule::exists('sites', 'id')->where(fn ($query) => $query
                    ->where('tenant_id', $identity->tenant_id)
                    ->whereIn('id', $siteIds)),
            ],
            'it_service_id' => [
                'nullable', 'integer',
                Rule::exists('it_services', 'id')->where('tenant_id', $identity->tenant_id),
            ],
            'asset_id' => [
                'nullable', 'integer',
                Rule::exists('assets', 'id')->where(fn ($query) => $query->whereIn('site_id', $siteIds)),
            ],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            try {
                app(ItApiFieldPolicy::class)->assertCreateFields($this->identity(), array_keys($this->all()));
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            }
        }];
    }

    private function identity(): ItServiceIdentity
    {
        /** @var ItServiceIdentity $identity */
        $identity = $this->attributes->get('it_service_identity');

        return $identity;
    }
}
