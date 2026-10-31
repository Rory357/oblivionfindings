<?php

namespace App\Http\Requests\HealthSafety;

use Illuminate\Foundation\Http\FormRequest;

class CompleteHsRiskAssessmentReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Current actor, source and completion evidence are checked under the service lock.
    }

    public function rules(): array
    {
        return [
            'review_note' => ['required', 'string', 'max:5000'],
            'review_due_at' => ['required', 'date_format:Y-m-d', 'after:'.now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString()],
            'review_frequency_days' => ['sometimes', 'nullable', 'integer', 'in:30,90,180,365'],
        ];
    }
}
