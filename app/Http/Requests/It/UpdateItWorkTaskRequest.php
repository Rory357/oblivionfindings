<?php

namespace App\Http\Requests\It;

use App\Domain\It\Data\ItWorkTaskInput;
use App\Http\Requests\It\Concerns\ConcealsInaccessibleItWork;
use App\Http\Requests\It\Concerns\ValidatesItTaskCommand;
use Illuminate\Foundation\Http\FormRequest;

class UpdateItWorkTaskRequest extends FormRequest
{
    use ConcealsInaccessibleItWork;
    use ValidatesItTaskCommand;

    public function authorize(): bool
    {
        $this->workableTaskOrNotFound();

        return (bool) $this->user()?->canDo('it.manage');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [...ItWorkTaskInput::rules('update'), ...$this->taskCommandRules()];
    }
}
