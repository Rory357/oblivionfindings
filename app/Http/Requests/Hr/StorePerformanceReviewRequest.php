<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;

class StorePerformanceReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('hr.performance.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'employee_user_id' => ['required', 'integer'],
            'review_type' => ['required', 'string', 'in:annual,mid_year,quarterly,ad_hoc'],
            'review_period_start' => ['required', 'date'],
            'review_period_end' => ['required', 'date', 'after:review_period_start'],
            'overall_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'strengths' => ['nullable', 'string', 'max:5000'],
            'development_areas' => ['nullable', 'string', 'max:5000'],
            'goals' => ['nullable', 'array'],
            'goals.*' => ['string', 'max:500'],
            'training_recommendations' => ['nullable', 'array'],
            'training_recommendations.*' => ['string', 'max:500'],
            'next_review_date' => ['nullable', 'date'],
        ];
    }
}
