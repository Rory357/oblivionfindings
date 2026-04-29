<?php

namespace App\Http\Requests\Operations\Rostering;

class UpdateRosterTemplateRequest extends StoreRosterTemplateRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return (bool) $user && ($user->canDo('roster_templates.update') || $user->canDo('rostering.edit'));
    }
}
