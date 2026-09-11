<?php

namespace App\Http\Requests\It;

class CancelItTicketCommentCommandRequest extends RecoverItTicketCommentCommandRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'is_internal' => ['required', 'boolean']];
    }
}
