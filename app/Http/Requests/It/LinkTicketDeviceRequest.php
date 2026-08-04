<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\ConcealsInaccessibleItWork;
use Illuminate\Foundation\Http\FormRequest;

class LinkTicketDeviceRequest extends FormRequest
{
    use ConcealsInaccessibleItWork;

    public function authorize(): bool
    {
        $this->workableTicketOrNotFound();

        return (bool) $this->user()?->canDo('it.manage');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            // Existence, source permission, Site and privacy are deliberately
            // repeated by the locked domain lifecycle without a leaky exists rule.
            'device_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
