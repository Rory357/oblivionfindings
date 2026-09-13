<?php

namespace App\Domain\It\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Models\ItTicket;
use App\Models\User;

/** New responsibility requires current employment and scope, not prior ownership. */
final class ItTicketRoutingEligibility
{
    public function __construct(private readonly ItWorkAccessService $workAccess) {}

    public function agent(?int $userId, ItTicket $ticket, bool $available = true): ?User
    {
        if ($userId === null) {
            return null;
        }
        $agent = User::query()->whereKey($userId)->whereNotNull('approved_at')
            ->whereDoesntHave('roles', fn ($query) => $query->whereIn('name', ['client', 'next_of_kin']))
            ->where(fn ($query) => $query->whereNull('role')->orWhereNotIn('role', ['client', 'next_of_kin']))
            ->first();
        if (! $agent?->canDo('it.manage') || ! $this->currentlyEmployed($userId)) {
            return null;
        }
        if ($ticket->is_sensitive && ! $agent->canDo('it.viewSensitive')) {
            return null;
        }
        if (! $this->workAccess->canAssignScope($agent, $ticket->site_id, (bool) $ticket->is_organisation_wide)) {
            return null;
        }
        if ($available && HrLeaveRequest::query()->approved()->where('user_id', $userId)
            ->where('starts_at', '<=', now())->where('ends_at', '>', now())->exists()) {
            return null;
        }

        return $agent;
    }

    public function currentlyEmployed(int $userId): bool
    {
        return HrEmployeeProfile::query()->where('user_id', $userId)->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('start_date')->orWhereDate('start_date', '<=', today()))
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', today()))
            ->exists();
    }
}
