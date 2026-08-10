<?php

namespace App\Domain\SecurityDevices\Management\Http\Requests;

use App\Domain\SecurityDevices\Management\Enums\BreakGlassReviewOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReviewDeviceCommandBreakGlassRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'outcome' => ['required', Rule::enum(BreakGlassReviewOutcome::class)],
            'summary' => ['required', 'string', 'min:20', 'max:1000'],
        ];
    }
}
