<?php

namespace App\Http\Requests\It;

use App\Domain\It\Services\ItKbRevisionService;
use App\Domain\It\Services\ItKnowledgeMedia;
use App\Domain\It\Services\ItKnowledgeRelationships;
use App\Http\Requests\It\Concerns\BindsItBrowserActor;
use App\Http\Requests\It\Concerns\ConcealsInaccessibleItKnowledge;
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
    use BindsItBrowserActor;
    use ConcealsInaccessibleItKnowledge;

    public function authorize(): bool
    {
        return $this->hasCurrentBrowserActor() && $this->canManageKnowledge();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...ItKnowledgeMedia::rules(),
            ...$this->browserActorRules(),
            'lock_version' => [Rule::requiredIf(fn () => app(ItKbRevisionService::class)->ready()), 'integer', 'min:1'],
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
            'document_type' => ['sometimes', 'required', Rule::in(ItKbArticle::DOCUMENT_TYPES)],
            'tags' => ['sometimes', 'array', 'max:12'],
            'tags.*' => ['required', 'string', 'max:40'],
            'structured_content' => ['nullable', 'array:'.implode(',', ItKbArticle::STRUCTURED_FIELDS)],
            'structured_content.*' => ['nullable', 'string', 'max:5000'],
            'related_records' => ['sometimes', 'array', 'max:30'],
            'related_records.*' => ['required', 'array:type,id,relation'],
            'related_records.*.type' => ['required', Rule::in(ItKnowledgeRelationships::TYPES)],
            'related_records.*.id' => ['required', 'integer', 'min:1'],
            'related_records.*.relation' => ['required', Rule::in(ItKnowledgeRelationships::RELATIONS)],
        ];
    }
}
