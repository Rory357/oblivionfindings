<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\ConcealsInaccessibleItKnowledge;
use Illuminate\Foundation\Http\FormRequest;

class DeleteKbArticleRequest extends FormRequest
{
    use ConcealsInaccessibleItKnowledge;

    public function authorize(): bool
    {
        return $this->canManageKnowledge();
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
