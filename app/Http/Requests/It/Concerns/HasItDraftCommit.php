<?php

namespace App\Http\Requests\It\Concerns;

trait HasItDraftCommit
{
    private function draftCommitRules(): array
    {
        return [
            'draft_uuid' => ['required_with:draft_revision,draft_actor_user_id', 'uuid'],
            'draft_revision' => ['required_with:draft_uuid', 'integer', 'min:0'],
            'draft_actor_user_id' => ['required_with:draft_uuid', 'integer', 'min:1'],
        ];
    }
}
