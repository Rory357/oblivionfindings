<?php

namespace App\Http\Requests\It;

use App\Models\ItCatalogItem;
use App\Models\ItProvisioningRequest;
use App\Models\ItTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveItCatalogItemRequest extends FormRequest
{
    public const FIELD_TYPES = [
        'text',
        'textarea',
        'email',
        'date',
        'integer',
        'number',
        'boolean',
        'select',
        'multiselect',
        'employee',
        'user',
        'asset',
    ];

    public function authorize(): bool
    {
        return (bool) $this->user()?->canDo('it.manage');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'it_service_id' => [
                'nullable',
                'integer',
                Rule::exists('it_services', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'outcome_type' => ['required', Rule::in(ItCatalogItem::OUTCOME_TYPES)],
            'category' => ['required', Rule::in(ItTicket::CATEGORIES)],
            'provisioning_type' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->input('outcome_type') === 'provisioning'),
                Rule::in(ItProvisioningRequest::TYPES),
            ],
            'default_priority' => ['required', Rule::in(ItTicket::PRIORITIES)],
            'requires_approval' => ['required', 'boolean'],
            'internal_only' => ['required', 'boolean'],
            'search_terms' => ['present', 'array', 'max:20'],
            'search_terms.*' => ['string', 'max:100', 'distinct:strict'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
            'form_schema' => ['required', 'array:fields'],
            'form_schema.fields' => ['present', 'array', 'max:20'],
            'form_schema.fields.*' => ['array:key,label,type,required,visibility,options,min,max,help'],
            'form_schema.fields.*.key' => ['required', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_]*$/'],
            'form_schema.fields.*.label' => ['required', 'string', 'max:255'],
            'form_schema.fields.*.type' => ['required', Rule::in(self::FIELD_TYPES)],
            'form_schema.fields.*.required' => ['required', 'boolean'],
            'form_schema.fields.*.visibility' => ['required', Rule::in(['requester', 'internal', 'restricted'])],
            'form_schema.fields.*.options' => ['nullable', 'array', 'max:30'],
            'form_schema.fields.*.options.*' => ['string', 'max:100', 'distinct:strict'],
            'form_schema.fields.*.min' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'form_schema.fields.*.max' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'form_schema.fields.*.help' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $fields = (array) $this->input('form_schema.fields', []);
            $keys = [];
            foreach ($fields as $index => $field) {
                if (! is_array($field)) {
                    continue;
                }

                $key = (string) ($field['key'] ?? '');
                if ($key !== '' && isset($keys[$key])) {
                    $validator->errors()->add(
                        "form_schema.fields.{$index}.key",
                        'Every form field needs a unique key.',
                    );
                }
                $keys[$key] = true;

                $type = (string) ($field['type'] ?? '');
                $options = array_values(array_filter(
                    (array) ($field['options'] ?? []),
                    fn (mixed $option): bool => is_string($option) && trim($option) !== '',
                ));
                if (in_array($type, ['select', 'multiselect'], true) && $options === []) {
                    $validator->errors()->add(
                        "form_schema.fields.{$index}.options",
                        'Add at least one choice for this field.',
                    );
                }
                if (! in_array($type, ['select', 'multiselect'], true) && $options !== []) {
                    $validator->errors()->add(
                        "form_schema.fields.{$index}.options",
                        'Only choice fields can define options.',
                    );
                }

                $min = $field['min'] ?? null;
                $max = $field['max'] ?? null;
                if (is_numeric($min) && is_numeric($max) && (int) $max < (int) $min) {
                    $validator->errors()->add(
                        "form_schema.fields.{$index}.max",
                        'Maximum must be greater than or equal to minimum.',
                    );
                }
            }
        }];
    }
}
