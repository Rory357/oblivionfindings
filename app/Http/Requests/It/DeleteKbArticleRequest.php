<?php

namespace App\Http\Requests\It;

use App\Domain\It\Services\ItKbRevisionService;
use App\Http\Requests\It\Concerns\BindsItBrowserActor;
use App\Http\Requests\It\Concerns\ConcealsInaccessibleItKnowledge;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeleteKbArticleRequest extends FormRequest
{
    use BindsItBrowserActor;
    use ConcealsInaccessibleItKnowledge;

    public function authorize(): bool
    {
        return $this->hasCurrentBrowserActor() && $this->canManageKnowledge();
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            ...$this->browserActorRules(),
            'lock_version' => [Rule::requiredIf(fn () => app(ItKbRevisionService::class)->ready()), 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
