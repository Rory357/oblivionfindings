<?php

namespace App\Http\Requests\It;

use App\Models\ItTicketApproval;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Approve or reject a pending sign-off request (§P-S3). Authorised by
 * ItTicketApprovalPolicy@decide — a different agent, pending only.
 */
class DecideApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $approval = $this->route('approval');

        return (bool) ($approval instanceof ItTicketApproval && $this->user()?->can('decide', $approval));
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
