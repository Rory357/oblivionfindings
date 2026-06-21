<?php

namespace App\Http\Requests\HealthSafety;

use App\Models\Client;
use App\Models\HsEvent;
use App\Models\Site;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for creating a risk assessment (also the base for Edit + Supersede).
 * Mirrors the wizard's per-step validateStep. The 5×5 matrix scalars are 1–5;
 * the assessable is a Site / Client / H&S event, or standalone.
 */
class StoreHsRiskAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is already gated by permission:hazards.manage.
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'risk_description' => ['nullable', 'string', 'max:5000'],

            'attach_type' => ['required', 'in:standalone,site,client,event'],
            'attach_id' => ['nullable', 'integer', 'required_unless:attach_type,standalone'],

            'likelihood' => ['required', 'integer', 'between:1,5'],
            'consequence' => ['required', 'integer', 'between:1,5'],

            'existing_controls' => ['nullable', 'string', 'max:5000'],
            'additional_controls' => ['nullable', 'string', 'max:5000'],

            'residual_likelihood' => ['nullable', 'integer', 'between:1,5'],
            'residual_consequence' => ['nullable', 'integer', 'between:1,5'],
            'risk_acceptable' => ['nullable', 'boolean'],

            'review_frequency_days' => ['nullable', 'integer', 'in:30,90,180,365'],
            'review_due_at' => ['nullable', 'date'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $type = $this->input('attach_type');
            $id = $this->input('attach_id');

            if ($type === 'standalone' || ! $id) {
                return;
            }

            $model = match ($type) {
                'site' => Site::class,
                'client' => Client::class,
                'event' => HsEvent::class,
                default => null,
            };

            // Use the model (respects SoftDeletes) so trashed entities can't be attached.
            if ($model && ! $model::query()->whereKey($id)->exists()) {
                $validator->errors()->add('attach_id', 'The selected '.$type.' could not be found.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'attach_id.required_unless' => 'Choose what this assessment is attached to.',
            'likelihood.between' => 'Pick a likelihood between 1 and 5.',
            'consequence.between' => 'Pick a consequence between 1 and 5.',
        ];
    }
}
