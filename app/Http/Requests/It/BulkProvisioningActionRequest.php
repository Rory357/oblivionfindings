<?php

namespace App\Http\Requests\It;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One bulk action over a selection of provisioning requests (§H): assign to
 * an agent, or fulfil. Cancel is deliberately absent — cancelling touches the
 * onboarding bridge (annotates the source task, notifies the checklist creator)
 * and reads better one request at a time; bulk is for the fast, safe moves.
 */
class BulkProvisioningActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return (bool) ($user && $user->canDo('it.manage'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:50'],
            'ids.*' => ['integer'],
            'action' => ['required', Rule::in(['assign', 'fulfil'])],
            // Assigning distributes work — a real recipient is required (unlike
            // tickets, provisioning has no "unassign" state worth bulk-setting).
            'assigned_to_user_id' => ['required_if:action,assign', 'integer', 'exists:users,id'],
        ];
    }
}
