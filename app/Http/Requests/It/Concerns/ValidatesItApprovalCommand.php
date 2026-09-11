<?php

namespace App\Http\Requests\It\Concerns;

trait ValidatesItApprovalCommand
{
    protected function approvalCommandRules(): array
    {
        $presence = $this->expectsJson() || $this->hasAny(['actor_user_id', 'request_uuid', 'expected_version']) ? 'required' : 'sometimes';

        return ['actor_user_id' => [$presence, 'integer', 'min:1'], 'request_uuid' => [$presence, 'uuid'],
            'expected_version' => [$presence, 'integer', 'min:1']];
    }
}
