<?php

namespace App\Http\Requests\Operations\Rostering;

use Illuminate\Foundation\Http\FormRequest;

class RosteringIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->canDo('rostering.viewAny');
    }

    public function rules(): array
    {
        return [
            'week' => ['nullable', 'date'],
            'staff_id' => ['nullable', 'integer', 'exists:users,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            // site_id may be a single integer (back-compat) or an array of integers.
            'site_id' => ['nullable'],
            'site_id.*' => ['integer', 'exists:sites,id'],
        ];
    }

    /**
     * Normalise the validated site_id to either null, a single int (one site selected),
     * or an int[] (multiple sites selected). Use this from the controller.
     *
     * @return null|int|int[]
     */
    public function siteFilter(): null|int|array
    {
        $value = $this->validated('site_id');

        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (is_array($value)) {
            $ids = array_values(array_filter(array_map('intval', $value), fn ($i) => $i > 0));
            if ($ids === []) return null;
            if (count($ids) === 1) return $ids[0];
            return $ids;
        }

        $int = (int) $value;
        return $int > 0 ? $int : null;
    }
}
