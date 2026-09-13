<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\BindsItBrowserActor;
use Illuminate\Foundation\Http\FormRequest;

class RecoverItTicketCommandRequest extends FormRequest
{
    use BindsItBrowserActor;

    public function authorize(): bool
    {
        return $this->user() !== null && $this->hasCurrentBrowserActor();
    }

    public function rules(): array
    {
        return $this->browserActorRules();
    }
}
