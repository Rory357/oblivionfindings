<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Prepare the data for validation so each card can submit partial data
     * against the shared profile endpoint without clobbering other fields.
     */
    protected function prepareForValidation(): void
    {
        $user = $this->user();

        if (! $user) {
            return;
        }

        $this->merge([
            'name' => $this->input('name', $user->name),
            'email' => $this->input('email', $user->email),
            'phone' => $this->has('phone') ? $this->input('phone') : $user->cellphone,
            'job_title' => $this->has('job_title') ? $this->input('job_title') : $user->staffProfile?->job_title,
            'timezone' => $this->input('timezone', $user->timezone ?? 'Pacific/Auckland'),
            'locale' => $this->input('locale', $user->locale ?? 'en'),
            'date_format' => $this->input('date_format', $user->date_format ?? 'DD/MM/YYYY'),
            'time_format' => $this->input('time_format', $user->time_format ?? '24'),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],

            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'timezone' => ['required', 'timezone'],
            'locale' => ['required', Rule::in(array_keys((array) config('locales.available', ['en' => []])))],
            'date_format' => ['required', Rule::in(['DD/MM/YYYY', 'MM/DD/YYYY', 'YYYY-MM-DD'])],
            'time_format' => ['required', Rule::in(['12', '24'])],
        ];
    }
}
