<?php

namespace App\Http\Requests\It;

use App\Models\ItKbArticle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edit a knowledge-base article, or flip its publish state. Fields are
 * `sometimes` so the full editor and a status-only Publish/Unpublish toggle
 * share one request. Agent-only (`it.manage`).
 */
class UpdateKbArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return (bool) ($user && $user->canDo('it.manage'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'category' => ['sometimes', 'required', Rule::in(ItKbArticle::CATEGORIES)],
            'body' => ['sometimes', 'required', 'string', 'max:20000'],
            'status' => ['sometimes', 'required', Rule::in(ItKbArticle::STATUSES)],
        ];
    }
}
