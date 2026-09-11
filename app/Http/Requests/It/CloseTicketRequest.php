<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\BindsItBrowserActor;
use App\Http\Requests\It\Concerns\ConcealsInaccessibleItWork;
use Illuminate\Foundation\Http\FormRequest;

/** Closing is terminal work and therefore always needs recorded evidence. */
class CloseTicketRequest extends FormRequest
{
    use BindsItBrowserActor;
    use ConcealsInaccessibleItWork;

    public function authorize(): bool
    {
        $this->workableTicketOrNotFound();

        return $this->hasCurrentBrowserActor() && (bool) $this->user()?->canDo('it.manage');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim((string) $this->input('reason'))]);
        }
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            ...$this->browserActorRules(),
            'expected_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
