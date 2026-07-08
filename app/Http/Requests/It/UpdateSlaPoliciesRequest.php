<?php

namespace App\Http\Requests\It;

use App\Models\ItTicket;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The SLA target grid: one pair of minutes per priority, all four required
 * (§N7 — the editor writes the whole grid, tenant rows override the §G
 * defaults). Admin-only on top of it.manage: retuning the helpdesk's
 * promises is an over-the-queue decision.
 */
class UpdateSlaPoliciesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return (bool) ($user && $user->canDo('it.manage') && $user->hasRole('admin'));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $rules = [];
        foreach (ItTicket::PRIORITIES as $priority) {
            // 5 minutes to 30 days for first response; up to 90 days to
            // resolve — wide enough for any sane policy, tight enough to
            // catch a fat-fingered "0" or an hours-vs-minutes mix-up.
            $rules["{$priority}.first_response_minutes"] = ['required', 'integer', 'min:5', 'max:43200'];
            $rules["{$priority}.resolution_minutes"] = [
                'required', 'integer', 'min:5', 'max:129600',
                "gte:{$priority}.first_response_minutes",
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];
        foreach (ItTicket::PRIORITIES as $priority) {
            $attributes["{$priority}.first_response_minutes"] = "{$priority} first-response target";
            $attributes["{$priority}.resolution_minutes"] = "{$priority} resolution target";
        }

        return $attributes;
    }
}
