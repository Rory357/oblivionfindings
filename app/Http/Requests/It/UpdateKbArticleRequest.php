<?php

namespace App\Http\Requests\It;

use App\Models\ItKbArticle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edit knowledge content and governance metadata. Fields are `sometimes` so
 * partial editor saves remain valid, while lifecycle status is prohibited and
 * must use the reviewed transition endpoints. Agent-only (`it.manage`).
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
