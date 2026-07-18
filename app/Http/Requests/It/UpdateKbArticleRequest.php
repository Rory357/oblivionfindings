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
            'status' => ['prohibited'],
            'audience' => ['sometimes', 'required', Rule::in(ItKbArticle::AUDIENCES)],
            'site_scope' => ['nullable', 'array', 'max:100', 'required_if:audience,specific_sites'],
            'site_scope.*' => [
                'integer',
                Rule::exists('sites', 'id')->where(fn ($query) => $query->where('tenant_id', $this->user()?->organization_id)),
            ],
            'owner_user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('organization_id', $this->user()?->organization_id)),
            ],
            'related_service_id' => [
                'nullable',
                'integer',
                Rule::exists('it_services', 'id')->where(fn ($query) => $query->where('tenant_id', $this->user()?->organization_id)),
            ],
            'review_due_at' => ['nullable', 'date'],
        ];
    }
}
