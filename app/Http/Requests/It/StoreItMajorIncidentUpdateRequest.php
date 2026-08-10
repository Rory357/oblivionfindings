<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\ConcealsInaccessibleItWork;
use App\Models\ItMajorIncidentUpdate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreItMajorIncidentUpdateRequest extends FormRequest
{
    use ConcealsInaccessibleItWork;

    public function authorize(): bool
    {
        $this->workableMajorIncidentOrNotFound();

        return (bool) $this->user()?->canDo('it.manage');
    }

    public function rules(): array
    {
        return [
            'update_kind' => ['required', Rule::in(ItMajorIncidentUpdate::KINDS)],
            'audience' => ['required', Rule::in(ItMajorIncidentUpdate::AUDIENCES)],
            'summary' => ['required', 'string', 'max:20000'],
            'service_status' => ['nullable', 'string', 'max:48'],
        ];
    }
}
