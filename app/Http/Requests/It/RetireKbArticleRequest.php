<?php

namespace App\Http\Requests\It;

use App\Domain\It\Services\ItKbAccessService;
use App\Http\Requests\It\Concerns\BindsItBrowserActor;
use App\Http\Requests\It\Concerns\ConcealsInaccessibleItKnowledge;
use Illuminate\Foundation\Http\FormRequest;

class RetireKbArticleRequest extends FormRequest
{
    use BindsItBrowserActor;
    use ConcealsInaccessibleItKnowledge;

    public function authorize(): bool
    {
        return $this->hasCurrentBrowserActor() && $this->canManageKnowledge(ItKbAccessService::REVIEW);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            ...$this->browserActorRules(),
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
