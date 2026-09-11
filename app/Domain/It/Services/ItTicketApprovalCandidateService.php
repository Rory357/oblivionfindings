<?php

namespace App\Domain\It\Services;

use App\Domain\It\Data\ItTicketApprovalInput;
use App\Models\ItTicket;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Authorizes one exact RAM candidate; neither persists private text nor grants mutation authority. */
final class ItTicketApprovalCandidateService
{
    public function __construct(private readonly ItTicketApprovalService $approvals, private readonly ItTicketApprovalResponsibilityService $responsibility) {}

    public function validate(ItTicket $ticket, User $actor, array $input): array
    {
        return DB::transaction(function () use ($ticket, $actor, $input): array {
            [$ticket, $actor] = $this->approvals->lockContext($ticket, $actor, mutation: false);
            if ((int) $input['actor_user_id'] !== (int) $actor->id) {
                throw new AuthorizationException('Recover this approval work using its original account.');
            }
            $operation = $input['operation'];
            $approvalId = isset($input['approval_id']) ? (int) $input['approval_id'] : null;
            if (($operation === 'request') !== ($approvalId === null)) {
                throw ValidationException::withMessages(['approval_id' => 'Keep this proposal with its original approval operation.']);
            }
            $approval = $approvalId !== null ? $ticket->approvals()->findOrFail($approvalId) : null;
            $version = (int) $input['base_ticket_version'];
            if ($version > (int) $ticket->lock_version) {
                throw ValidationException::withMessages(['base_ticket_version' => 'Keep the original reviewed ticket version with this proposal.']);
            }
            $this->fields($operation, $input['fields']);
            $this->bindings($ticket, $input['fields']);
            foreach ($input['bound_scopes'] ?? [] as $scope) {
                $this->bindings($ticket, $scope);
            }
            if (isset($input['pending_approval'])) {
                $pending = $input['pending_approval'];
                Validator::make($pending, ['actor_user_id' => ['required', 'integer', 'min:1'],
                    'ticket_id' => ['required', 'integer', 'min:1'], 'request_uuid' => ['required', 'uuid'],
                    'operation' => ['required', 'string'], 'approval_id' => ['present', 'nullable', 'integer', 'min:1'],
                    'expected_version' => ['required', 'integer', 'min:1'], 'fields' => ['present', 'array', 'max:6']])->validate();
                if ((int) $pending['actor_user_id'] !== (int) $actor->id || (int) $pending['ticket_id'] !== (int) $ticket->id
                    || $pending['operation'] !== $operation || (isset($pending['approval_id']) ? (int) $pending['approval_id'] : null) !== $approvalId
                    || (int) $pending['expected_version'] !== $version) {
                    throw ValidationException::withMessages(['pending_approval' => 'The earlier command must keep its exact actor, ticket, approval, operation and version.']);
                }
                $this->fields($operation, $pending['fields']);
                ItTicketApprovalInput::normalize($operation, $pending['fields']);
                $this->bindings($ticket, $pending['fields']);
            }
            $blocker = match (true) {
                $ticket->isMerged() => ['code' => 'ticket_merged', 'message' => 'This proposal stays with its original ticket. Continue on the surviving ticket.'],
                ! in_array($ticket->status, ItTicket::OPEN_STATUSES, true) => ['code' => 'ticket_settled', 'message' => 'Reopen this ticket before changing its approvals.'],
                $version !== (int) $ticket->lock_version => ['code' => 'ticket_changed', 'message' => 'Review the current ticket before applying this proposal.'],
                $approval && $approval->effectiveStatus() !== 'pending' => ['code' => 'approval_ended', 'message' => 'This approval has ended. Its original outcome and your separate proposal are retained.'],
                $operation === 'decide' && $this->responsibility->decisionBasis($approval, $ticket, $actor) === null => ['code' => 'approval_authority_changed', 'message' => 'You are not the currently responsible approver for this request.'],
                $operation === 'withdraw' && (int) $approval->requested_by !== (int) $actor->id
                    && $this->responsibility->decisionBasis($approval, $ticket, $actor) === null => ['code' => 'approval_authority_changed', 'message' => 'Only the requester or current responsible approver may cancel this request.'],
                $operation === 'request' && ! $actor->can('requestApproval', $ticket) => ['code' => 'approval_request_unavailable', 'message' => 'Review the existing approval state before requesting another decision.'],
                default => null,
            };

            return ['candidate' => ['kind' => 'memory', 'memory_uuid' => $input['memory_uuid'],
                'candidate_uuid' => $input['candidate_uuid'], 'actor_user_id' => (int) $actor->id,
                'purpose' => 'approval_work',
                'context_key' => 'ticket:'.$ticket->id.':approval:'.($approvalId ?? 'new').':operation:'.$operation,
                'base_ticket_version' => $version, 'current_ticket_version' => (int) $ticket->lock_version,
                'authorized' => true, 'capabilities' => ['submit' => $blocker === null], 'blocker' => $blocker]];
        });
    }

    private function fields(string $operation, array $fields): void
    {
        if (array_diff(array_keys($fields), array_keys(ItTicketApprovalInput::rules($operation))) !== []) {
            throw ValidationException::withMessages(['fields' => 'This browser copy contains fields for a different approval operation.']);
        }
        // An unfinished or overlong reason must remain recoverable for editing.
        Validator::make($fields, ['reason' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'decision' => ['sometimes', 'nullable', 'string', 'max:50'],
            'expires_at' => ['sometimes', 'nullable', 'string', 'max:100'],
            'remind_at' => ['sometimes', 'nullable', 'string', 'max:100']])->validate();
    }

    private function bindings(ItTicket $ticket, array $fields): void
    {
        foreach (['primary_approver_user_id', 'cover_approver_user_id'] as $field) {
            Validator::make($fields, [$field => ['sometimes', 'nullable', 'integer', 'min:1']])->validate();
            if (isset($fields[$field]) && ! $this->responsibility->eligible((int) $fields[$field], $ticket)) {
                throw new AuthorizationException('A person selected in this browser copy is no longer available in this ticket scope.');
            }
        }
    }
}
