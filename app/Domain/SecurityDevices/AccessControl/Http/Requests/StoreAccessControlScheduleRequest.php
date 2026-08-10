<?php

namespace App\Domain\SecurityDevices\AccessControl\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAccessControlScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('securityDevices.accessControl.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'site_id' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:120'],
            'days' => ['required', 'array', 'min:1', 'max:7'],
            'days.*' => ['required', 'string', 'distinct', 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
        ];
    }
}
