<?php

namespace App\Http\Requests\It;

use App\Http\Requests\It\Concerns\ConcealsInaccessibleItWork;
use App\Models\ItTicketApproval;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The request first conceals an inaccessible parent ticket; the locked approval
 * lifecycle then revalidates pending state and separation of duties.
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
            'reason' => ['required_if:decision,reject', 'nullable', 'string', 'max:1000'],
        ];
    }
}
