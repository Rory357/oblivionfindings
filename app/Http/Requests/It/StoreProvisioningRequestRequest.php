<?php

namespace App\Http\Requests\It;

use App\Models\ItProvisioningRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Agent-raised (manual) provisioning request — the ad-hoc path alongside the
 * onboarding bridge. Tenant ownership of the chosen employee profile and the
 * assignee is asserted in the controller (mirroring assign/fulfil), so this
 * only shapes the fields.
 */
class StoreProvisioningRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return (bool) ($user && $user->canDo('it.manage'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'employee_profile_id' => ['required', 'integer', 'exists:hr_employee_profiles,id'],
            'type' => ['required', Rule::in(ItProvisioningRequest::TYPES)],
            'item' => ['required', 'string', 'max:255'],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'priority' => ['required', Rule::in(ItProvisioningRequest::PRIORITIES)],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
