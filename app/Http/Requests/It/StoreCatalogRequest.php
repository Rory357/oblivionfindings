<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\BindsItBrowserActor;
use Illuminate\Foundation\Http\FormRequest;

class StoreCatalogRequest extends FormRequest
{
    use BindsItBrowserActor;

    public function authorize(): bool
    {
        $user = $this->user();

        return (bool) ($user?->isApproved()
            && ($user->canDo('it.request') || $user->canDo('it.manage'))
            && $this->hasCurrentBrowserActor());
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'actor_user_id' => ['required', 'integer', 'min:1'],
            'schema_version' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'uuid'],
            'values' => ['present', 'array'],
            'site_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'requested_for_user_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'draft_uuid' => ['required_with:draft_revision,draft_actor_user_id,staged_attachment_ids', 'uuid'],
            'draft_revision' => ['required_with:draft_uuid', 'integer', 'min:0'],
            'draft_actor_user_id' => ['required_with:draft_uuid', 'integer', 'min:1'],
            'staged_attachment_ids' => ['sometimes', 'array', 'list', 'max:5'],
            'staged_attachment_ids.*' => ['integer', 'min:1', 'distinct'],
        ];
    }
}
