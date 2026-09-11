<?php

namespace App\Http\Requests\It\Concerns;

trait BindsItBrowserActor
{
    /** An old browser form must not submit its private buffer as a new login. */
    private function hasCurrentBrowserActor(): bool
    {
        // Previously served forms remain compatible. Current forms always bind
        // their originating actor in addition to normal current authorization.
        if (! $this->exists('actor_user_id')) {
            return true;
        }

        $actorId = $this->input('actor_user_id');

        return (is_int($actorId) || is_string($actorId))
            && filter_var($actorId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false
            && (int) $actorId === (int) $this->user()?->id;
    }

    private function browserActorRules(): array
    {
        return ['actor_user_id' => ['sometimes', 'required', 'integer', 'min:1']];
    }
}
