<?php

namespace App\Http\Requests\It;

use App\Models\ItKbArticle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a knowledge-base article (§I / §N6). Authoring is agent work —
 * `it.manage` only; requesters read published articles, they never write.
 */
class StoreKbArticleRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(ItKbArticle::CATEGORIES)],
            'body' => ['required', 'string', 'max:20000'],
            'status' => ['prohibited'],
            'audience' => ['sometimes', 'required', Rule::in(ItKbArticle::AUDIENCES)],
            'site_scope' => ['nullable', 'array', 'max:100', 'required_if:audience,specific_sites'],
            'site_scope.*' => [
                'integer',
                Rule::exists('sites', 'id')->where(fn ($query) => $query
                    ->where('is_active', true)
                    ->where('archived', false)
                    ->whereNull('archived_at')),
            ],
            'owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'related_service_id' => [
                'nullable',
                'integer',
                Rule::exists('it_services', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'review_due_at' => ['nullable', 'date'],
        ];
    }
}
