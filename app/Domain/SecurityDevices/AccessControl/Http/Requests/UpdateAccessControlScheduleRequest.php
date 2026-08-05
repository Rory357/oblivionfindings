<?php

namespace App\Domain\SecurityDevices\AccessControl\Http\Requests;

use App\Domain\SecurityDevices\AccessControl\Models\AccessControlSchedule;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAccessControlScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user?->canDo('securityDevices.accessControl.manage')) {
            return false;
        }

        $schedule = $this->route('accessSchedule');
        if (! $schedule instanceof AccessControlSchedule) {
            return false;
        }
        app(SecurityDevicesAccessService::class)->assertCanViewSite($user, (int) $schedule->site_id);

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expected_version' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:120'],
            'days' => ['required', 'array', 'min:1', 'max:7'],
            'days.*' => ['required', 'string', 'distinct', 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'reason' => ['required', 'string', 'max:500'],
            'confirmed_active_credentials' => ['nullable', 'integer', 'min:0'],
            'confirmation_text' => ['nullable', 'string', 'max:80'],
        ];
    }
}
