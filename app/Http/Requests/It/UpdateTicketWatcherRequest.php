<?php

namespace App\Http\Requests\It;

use App\Domain\It\Services\ItWorkAccessService;
use App\Http\Requests\It\Concerns\BindsItBrowserActor;
use App\Http\Requests\It\Concerns\ConcealsInaccessibleItWork;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTicketWatcherRequest extends FormRequest
{
    use BindsItBrowserActor;
    use ConcealsInaccessibleItWork;

    public function authorize(): bool
    {
        $ticket = $this->visibleTicketOrNotFound();

        return $this->hasCurrentBrowserActor()
            && app(ItWorkAccessService::class)->canWork($this->user(), $ticket);
    }

    public function rules(): array
    {
        return [
            'actor_user_id' => ['required', 'integer', 'min:1'],
            'expected_version' => ['required', 'integer', 'min:1'],
            'watching' => ['required', 'boolean'],
        ];
    }
}
