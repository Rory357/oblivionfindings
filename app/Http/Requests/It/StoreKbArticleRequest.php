<?php

namespace App\Http\Requests\It;

use App\Domain\It\Services\ItKbAccessService;
use App\Domain\It\Services\ItKnowledgeMedia;
use App\Domain\It\Services\ItKnowledgeRelationships;
use App\Http\Requests\It\Concerns\BindsItBrowserActor;
use App\Models\ItKbArticle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a knowledge-base article (§I / §N6). Authoring is agent work —
 * Explicit knowledge author permission; ticket management does not imply it.
 */
class StoreKbArticleRequest extends FormRequest
{
    use BindsItBrowserActor;

    public function authorize(): bool
    {
        $user = $this->user();

        return $this->hasCurrentBrowserActor() && $user !== null && app(ItKbAccessService::class)->canAuthorRecords($user);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...ItKnowledgeMedia::rules(),
            ...$this->browserActorRules(),
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(ItKbArticle::CATEGORIES)],
            'body' => ['required', 'string', 'max:20000'],
            'source_ticket_id' => ['nullable', 'integer', 'min:1', 'required_with:source_ticket_version'],
            'source_ticket_version' => ['nullable', 'integer', 'min:1', 'required_with:source_ticket_id'],
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
