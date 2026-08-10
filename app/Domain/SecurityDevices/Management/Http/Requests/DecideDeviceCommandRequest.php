<?php

namespace App\Domain\SecurityDevices\Management\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideDeviceCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canDo('securityDevices.commands.approve') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'comment' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }
}
