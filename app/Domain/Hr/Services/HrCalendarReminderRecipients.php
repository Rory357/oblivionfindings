<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class HrCalendarReminderRecipients
{
    public function recipients(HrCalendarEvent $root, HrCalendarEvent $effective, string $date): Collection
    {
        $query = app(HrCurrentStaffService::class)->currentUsersQuery();
        $people = $root->attendees->where('audience_type', 'person');
        if ($people->isNotEmpty()) {
            $query->whereKey($people->where('rsvp_status', '!=', 'no')->pluck('user_id')->all());
        }
        if ($root->registration_enabled) {
            $query->whereIn('id', DB::table('hr_calendar_registrations')->where('event_id', $root->id)->where('occurrence_date', $date)->whereIn('status', ['going', 'maybe'])->select('user_id'));
        }

        return $query->with('hrEmployeeProfile')->get()
            ->filter(fn ($user) => $this->eligible($user, $root, $effective, $date))->values();
    }

    public function eligible(User $user, HrCalendarEvent $root, HrCalendarEvent $effective, string $date): bool
    {
        // Calendar managers can read an event without belonging to its reminder audience.
        $access = app(HrCalendarAccessService::class);
        if (! app(HrCurrentStaffService::class)->isCurrent($user) || ! $access->canViewEvent($user, $effective)) {
            return false;
        }
        $audiences = $root->attendees;
        $matches = $audiences->isEmpty() || $audiences->contains(function ($audience) use ($user, $access) {
            return match ($audience->audience_type) {
                'person' => (int) $audience->user_id === (int) $user->id && $audience->rsvp_status !== 'no',
                'org' => true,
                'site' => in_array((int) $audience->audience_ref, $access->accessibleSiteIds($user), true),
                'department' => (int) $user->hrEmployeeProfile?->department_id === (int) $audience->audience_ref,
                'team' => mb_strtolower(HrEmployeeProfile::normalizeTeam($user->hrEmployeeProfile?->team) ?? '') === mb_strtolower(HrEmployeeProfile::normalizeTeam($audience->audience_ref) ?? ''),
                default => false,
            };
        });
        if (! $matches) {
            return false;
        }
        $registration = DB::table('hr_calendar_registrations')->where('event_id', $root->id)->where('occurrence_date', $date)->where('user_id', $user->id)->first();

        return $root->registration_enabled ? in_array($registration?->status, ['going', 'maybe'], true) : $registration?->status !== 'declined';
    }
}
