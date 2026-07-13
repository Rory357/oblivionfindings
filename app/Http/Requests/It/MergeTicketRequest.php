<?php

namespace App\Http\Requests\It;

use App\Models\ItTicket;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Merge the route ticket (SOURCE) into a TARGET survivor. Authorisation is the
 * ItTicketPolicy@merge gate over both tickets — agent-only, no self-merge, no
 * re-merging a merged source, a live target, same tenant.
 */
class MergeTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $source = $this->route('ticket');
        $target = $this->targetTicket();

        return $user !== null
            && $source instanceof ItTicket
            && $target instanceof ItTicket
            && $user->can('merge', [$source, $target]);
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
