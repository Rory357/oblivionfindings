<?php

namespace App\Domain\It\Services;

use App\Domain\It\ItStaffDirectory;
use App\Models\ItTicket;
use App\Models\ItTicketApproval;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Pending assignment is distinct from the immutable identity of the actual decider. */
final class ItTicketApprovalResponsibilityService
{
    public function __construct(private readonly ItTicketRoutingEligibility $eligibility, private readonly ItWorkAccessService $access) {}

    public function eligible(?int $id, ItTicket $ticket, bool $available = false): ?User
    {
        $user = $this->eligibility->agent($id, $ticket, $available);

        return $user && $this->access->canWork($user, $ticket) ? $user : null;
    }

    public function candidates(ItTicket $ticket, User $requester): Collection
    {
        return ItStaffDirectory::agentsForTicket($ticket)
            ->filter(fn (User $user): bool => (int) $user->id !== (int) $requester->id && $this->eligible((int) $user->id, $ticket) !== null)
            ->values();
    }

    /** Validate current responsibility only on a new command, never on receipt replay. */
    public function proposal(ItTicket $ticket, User $requester, array $input): array
    {
        $primary = isset($input['primary_approver_user_id']) ? (int) $input['primary_approver_user_id'] : null;
        $cover = isset($input['cover_approver_user_id']) ? (int) $input['cover_approver_user_id'] : null;
        if ($primary === (int) $requester->id || ! $this->eligible($primary, $ticket)) {
            throw ValidationException::withMessages(['primary_approver_user_id' => 'Choose another currently eligible IT approver for this ticket.']);
        }
        if ($cover !== null && ($cover === $primary || $cover === (int) $requester->id || ! $this->eligible($cover, $ticket))) {
            throw ValidationException::withMessages(['cover_approver_user_id' => 'Choose a different eligible cover person, or leave cover unassigned.']);
        }
        $expiry = isset($input['expires_at']) ? CarbonImmutable::parse($input['expires_at'])->setTimezone(config('app.timezone')) : null;
        $reminder = isset($input['remind_at']) ? CarbonImmutable::parse($input['remind_at'])->setTimezone(config('app.timezone')) : null;
        if ($expiry && $expiry->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages(['expires_at' => 'Choose an approval deadline in the future.']);
        }
        if ($reminder && ($reminder->lessThanOrEqualTo(now()) || ($expiry && $reminder->greaterThanOrEqualTo($expiry)))) {
            throw ValidationException::withMessages(['remind_at' => 'Choose a future reminder before the approval deadline.']);
        }

        return ['primary_approver_user_id' => $primary, 'cover_approver_user_id' => $cover,
            'assignment_recorded_at' => now(), 'expires_at' => $expiry, 'remind_at' => $reminder];
    }

    /** This is an authorization verdict, not a write or fabricated historical assignment. */
    public function effectiveOwner(ItTicketApproval $approval, ItTicket $ticket): array
    {
        if ($approval->assignment_recorded_at === null) {
            return ['user' => null, 'basis' => 'legacy_unassigned'];
        }
        $primary = $this->eligible($approval->primary_approver_user_id, $ticket);
        if ($primary && $this->eligible((int) $primary->id, $ticket, available: true)) {
            return ['user' => $primary, 'basis' => 'primary'];
        }
        $cover = $this->eligible($approval->cover_approver_user_id, $ticket, available: true);

        return ['user' => $cover, 'basis' => $cover
            ? ($primary ? 'cover_primary_on_approved_leave' : 'cover_primary_ineligible')
            : 'no_available_approver'];
    }

    public function decisionBasis(ItTicketApproval $approval, ItTicket $ticket, User $actor): ?string
    {
        if ($approval->status !== 'pending' || $approval->isPastDeadline()
            || (int) $approval->requested_by === (int) $actor->id
            || ! $actor->canDo('it.manage') || ! $this->access->canWork($actor, $ticket)) {
            return null;
        }
        if ($approval->assignment_recorded_at === null) {
            return $this->eligible((int) $actor->id, $ticket, available: true) ? 'legacy_eligible_agent' : null;
        }
        $owner = $this->effectiveOwner($approval, $ticket);

        return (int) ($owner['user']?->id ?? 0) === (int) $actor->id ? $owner['basis'] : null;
    }
}
