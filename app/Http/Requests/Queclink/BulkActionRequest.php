<?php

namespace App\Http\Requests\Queclink;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canDo('securityDevices.integrations.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'device_ids' => ['required', 'array', 'min:1', 'max:100'],
            'device_ids.*' => ['required', 'integer'],
            'action' => ['required', 'string', Rule::in([
                'read_configuration',
                'reboot',
                'resident_safety_profile',
                'apply_preset',
            ])],
            'section' => ['nullable', 'string', 'in:all,BSI,SRI,CFG,PIN,DOG,TMA,NMD,PDS,GEO,BTS,WFI,BID,UPC,WLT,FVR'],
            'preset_id' => ['nullable', 'required_if:action,apply_preset', 'integer'],
        ];
    }
}
