<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\ConcealsInaccessibleItWork;
use App\Models\ItTicket;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Merge the route ticket (SOURCE) into a TARGET survivor. Authorisation is the
 * Controller-owned canonical access checks conceal either inaccessible parent
 * before the lifecycle policy evaluates the two visible tickets.
 */
class MergeTicketRequest extends FormRequest
{
    use ConcealsInaccessibleItWork;

    public function authorize(): bool
    {
        $user = $this->user();
        $this->workableMergeParentsOrNotFound();

        return $user !== null && $user->canDo('it.manage');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'target_ticket_id' => ['required', 'integer', 'exists:it_tickets,id'],
        ];
    }

    /** The resolved target ticket (survivor), or null when absent/unknown. */
    public function targetTicket(): ?ItTicket
    {
        $id = $this->integer('target_ticket_id');

        return $id > 0 ? ItTicket::query()->find($id) : null;
    }
}
