<?php

namespace App\Http\Requests\It\Concerns;

trait ValidatesItTaskCommand
{
    /** Keep already-served legacy Inertia forms usable; any new tuple is complete. */
    protected function taskCommandRules(bool $required = false): array
    {
        $required = $required || $this->expectsJson()
            || $this->hasAny(['actor_user_id', 'request_uuid', 'expected_version']);
        $presence = $required ? 'required' : 'sometimes';

        return ['actor_user_id' => [$presence, 'integer', 'min:1'],
            'request_uuid' => [$presence, 'uuid'], 'expected_version' => [$presence, 'integer', 'min:1']];
    }
}
