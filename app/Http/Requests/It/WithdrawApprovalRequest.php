<?php

namespace App\Http\Requests\It;

use App\Domain\It\Data\ItTicketApprovalInput;

class WithdrawApprovalRequest extends DecideApprovalRequest
{
    public function rules(): array
    {
        return [...$this->approvalCommandRules(), ...ItTicketApprovalInput::rules('withdraw')];
    }
}
