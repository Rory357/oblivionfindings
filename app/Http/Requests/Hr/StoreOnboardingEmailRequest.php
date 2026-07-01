<?php

namespace App\Http\Requests\Hr;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create or update an onboarding email template.
 */
class StoreOnboardingEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('hr.onboarding.manage') ?? false;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'template_name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'subject' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'body' => [$creating ? 'required' : 'sometimes', 'string', 'max:50000'],
            'send_days_before_start' => [$creating ? 'required' : 'sometimes', 'integer', 'min:-90', 'max:90'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
