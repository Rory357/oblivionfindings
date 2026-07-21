<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\ConcealsInaccessibleItWork;
use App\Models\ItTicketApproval;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The controller first conceals an inaccessible parent ticket, then applies
 * ItTicketApprovalPolicy@decide for pending-state and separation-of-duty rules.
 */
class DecideApprovalRequest extends FormRequest
{
    use ConcealsInaccessibleItWork;

    public function authorize(): bool
    {
        $approval = $this->workableApprovalOrNotFound();

        return (bool) ($approval instanceof ItTicketApproval && $this->user()?->canDo('it.manage'));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
