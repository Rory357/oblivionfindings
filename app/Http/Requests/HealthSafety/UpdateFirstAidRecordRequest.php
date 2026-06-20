<?php

namespace App\Http\Requests\HealthSafety;

use Illuminate\Foundation\Http\FormRequest;

/**
 * First Aid Register gold-standard upgrade — Step 3. Same canonical rule set as
 * StoreFirstAidRecordRequest, but every `required` rule is relaxed to `sometimes` so the
 * detail-modal Edit pane can submit partial updates without re-asserting absent fields.
 */
class UpdateFirstAidRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) ($this->user()?->canDo('hazards.manage') || $this->user()?->canDo('hazards.create'));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $rules = (new StoreFirstAidRecordRequest)->rules();

        foreach ($rules as $field => $constraints) {
            $rules[$field] = array_map(
                fn (string $rule) => $rule === 'required' ? 'sometimes' : $rule,
                $constraints,
            );
        }

        return $rules;
    }
}
