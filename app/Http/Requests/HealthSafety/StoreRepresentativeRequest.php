<?php

namespace App\Http\Requests\HealthSafety;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Add an H&S representative. The wizard's client-side validateStep mirrors these
 * rules, so this is the single source of truth.
 */
class StoreRepresentativeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('hazards.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'exists:users,id'],
            'site_id' => ['required', 'exists:sites,id'],
            'work_group' => ['nullable', 'string', 'max:120'],
            'election_method' => ['required', 'string', 'in:elected,appointed,volunteered'],
            'elected_at' => ['required', 'date'],
            // HSR term is capped at 3 years (HSWA / HSRC Amendment 2023).
            'term_expires_at' => ['nullable', 'date', 'after:elected_at', 'before_or_equal:'.now()->addYears(3)->toDateString()],
            'training_days_completed' => ['nullable', 'integer', 'min:0', 'max:30'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
