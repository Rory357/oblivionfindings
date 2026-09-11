<?php

namespace App\Http\Requests\It;

use App\Domain\It\Services\ItKbAccessService;
use App\Http\Requests\It\Concerns\ConcealsInaccessibleItKnowledge;
use Illuminate\Foundation\Http\FormRequest;

class RetireKbArticleRequest extends FormRequest
{
    use ConcealsInaccessibleItKnowledge;

    public function authorize(): bool
    {
        return $this->canManageKnowledge(ItKbAccessService::REVIEW);
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
