<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Issue a specific company asset to a new hire from an IT onboarding task.
 */
class ProvisionOnboardingAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('hr.onboarding.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            // Either pick a specific asset, or omit it to auto-pick the first
            // available one (optionally constrained to a category).
            'asset_id' => ['nullable', 'integer'],
            'category' => ['nullable', 'string', 'max:100'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'signed_off_by' => ['nullable', 'integer'],
        ];
    }
}
