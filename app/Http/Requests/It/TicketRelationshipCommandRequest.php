<?php

namespace App\Http\Requests\It;

use App\Domain\It\Services\ItWorkAccessService;
use App\Http\Requests\It\Concerns\BindsItBrowserActor;
use App\Http\Requests\It\Concerns\ConcealsInaccessibleItWork;
use App\Models\ItTicket;
use App\Models\ItTicketLink;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TicketRelationshipCommandRequest extends FormRequest
{
    use BindsItBrowserActor, ConcealsInaccessibleItWork;

    public function authorize(): bool
    {
        $this->workableTicketOrNotFound();
        if ($this->integer('target_ticket_id') > 0) {
            $target = ItTicket::query()->find($this->integer('target_ticket_id'));
            abort_unless($target && app(ItWorkAccessService::class)->canWork($this->user(), $target), 404);
        }

        return $this->hasCurrentBrowserActor();
    }

    public function rules(): array
    {
        return ['actor_user_id' => ['required', 'integer', 'min:1'], 'target_ticket_id' => ['required', 'integer', 'min:1'],
            'relationship' => ['required', Rule::in(ItTicketLink::BROWSER_RELATIONSHIPS)],
            'action' => ['required', Rule::in(['add', 'remove'])],
            ...($this->route('requestUuid') === null ? ['request_uuid' => ['required', 'uuid'],
                'source_version' => ['required', 'integer', 'min:1'], 'target_version' => ['required', 'integer', 'min:1']] : [])];
    }
}
