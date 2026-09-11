<?php

namespace App\Http\Requests\It;

use App\Domain\It\Data\ItTicketApprovalInput;
use App\Http\Requests\It\Concerns\ConcealsInaccessibleItWork;
use App\Http\Requests\It\Concerns\ValidatesItApprovalCommand;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base agent gate for raising sign-off. The request conceals an inaccessible
 * ticket before the locked approval lifecycle revalidates the decision rules.
 */
class RequestApprovalRequest extends FormRequest
{
    use ConcealsInaccessibleItWork, ValidatesItApprovalCommand;

    public function authorize(): bool
    {
        $this->workableTicketOrNotFound();

        return (bool) $this->user()?->canDo('it.manage');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            ...$this->approvalCommandRules(),
            'approval_id' => ['prohibited'],
            ...ItTicketApprovalInput::rules('request'),
        ];
    }
}
