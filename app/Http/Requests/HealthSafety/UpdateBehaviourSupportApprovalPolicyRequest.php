<?php

namespace App\Http\Requests\HealthSafety;

use App\Services\HealthSafety\BehaviourSupportApprovalPolicySettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateBehaviourSupportApprovalPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless($this->user(), 403);
        app(BehaviourSupportApprovalPolicySettings::class)->assertManager($this->user());

        return true;
    }

    public function rules(): array
    {
        return [
            'mode' => ['required', 'string', Rule::in(BehaviourSupportApprovalPolicySettings::MODES)],
            'expected_policy_revision' => ['required', 'integer', 'min:0', 'max:'.(PHP_INT_MAX - 1)],
            'idempotency_key' => ['required', 'string', 'uuid'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['mode', 'expected_policy_revision', 'idempotency_key', 'reason', '_token', '_method'])) {
                $validator->errors()->add('mode', 'Only the approval policy command fields may be submitted.');
            }
        });
    }
}
