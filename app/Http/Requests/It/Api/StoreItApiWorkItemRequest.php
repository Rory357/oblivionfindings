<?php

namespace App\Http\Requests\It\Api;

use App\Domain\It\Services\ItApiFieldPolicy;
use App\Domain\It\Services\ItApiWorkItemService;
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
        $siteIds = app(ItApiWorkItemService::class)->allowedSiteIds($identity);

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
                    ->where('is_active', true)
                    ->where('archived', false)
                    ->whereNull('archived_at')
                    ->whereIn('id', $siteIds)),
            ],
            'is_organisation_wide' => ['nullable', 'boolean'],
            'it_service_id' => [
                'nullable', 'integer',
                Rule::exists('it_services', 'id')->where(fn ($service) => $service
                    ->where('is_active', true)
                    ->where('status', '!=', 'retired')),
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

            $siteId = $this->filled('site_id') ? (int) $this->input('site_id') : null;
            $isOrganisationWide = $this->boolean('is_organisation_wide');
            if ($siteId === null && ! $isOrganisationWide) {
                $validator->errors()->add('site_id', 'Choose an approved Site or explicitly request organisation-wide work.');
            }
            if ($siteId !== null && $isOrganisationWide) {
                $validator->errors()->add('is_organisation_wide', 'Organisation-wide work cannot also be linked to a Site.');
            }

            if (! $validator->errors()->hasAny([
                'site_id', 'is_organisation_wide', 'work_type', 'it_service_id', 'asset_id',
            ]) && ! app(ItApiWorkItemService::class)->canCreateWithScope(
                $this->identity(),
                $this->all(),
            )) {
                $field = $isOrganisationWide ? 'is_organisation_wide' : 'site_id';
                $validator->errors()->add($field, 'This service identity cannot create work in that scope.');
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
