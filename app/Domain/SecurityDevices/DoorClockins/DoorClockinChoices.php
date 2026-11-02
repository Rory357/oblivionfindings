<?php

namespace App\Domain\SecurityDevices\DoorClockins;

use App\Domain\Hr\Services\AttendanceService;
use App\Domain\SecurityDevices\DoorClockins\Models\DoorClockin;
use App\Models\Shift;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The person's own answer to a suggested clock-in (My Day band 19g, pop-up 88): clock in at the
 * badge time, clock in now instead, or "I'm not working". "Later" just closes the pop-up.
 *
 * Clocking in always goes through AttendanceService::clockIn with every rule it enforces (the
 * shift is theirs and still open, their Site, no open session, no overlap, payroll locks). The
 * badge time is the door event's own time, never typed, and the clock-in has the source
 * "door_badge" with the badge kept in its meta. Nothing here loosens an attendance rule.
 */
final class DoorClockinChoices
{
    public const array CHOICES = ['badge', 'now', 'not_working'];

    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly DoorClockinSetup $setup,
    ) {}

    /** @return array{answer: string, clock_in_at: string|null, session_id: int|null} */
    public function answer(User $user, int $clockinId, string $choice, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $row = DoorClockin::query()
            ->whereKey($clockinId)
            ->where('user_id', $user->id)
            ->where('kind', DoorClockin::ROSTERED)
            ->with(['door:id,name', 'site:id,name'])
            ->first();
        abort_if($row === null, 404);
        if (! in_array($choice, self::CHOICES, true)) {
            throw ValidationException::withMessages(['choice' => 'Choose how to clock in.']);
        }
        if (! $row->isWaiting() || $row->badge_at === null) {
            throw ValidationException::withMessages(['choice' => $row->answer !== null ? 'You’ve already answered this one.' : 'This suggestion has closed. Clock in the usual way if you’re working.']);
        }
        if ($row->shift_ends_at !== null && $row->shift_ends_at->lte($now)) {
            throw ValidationException::withMessages(['choice' => 'That shift has ended. Ask your manager if your times need changing.']);
        }
        $config = $this->setup->config();
        if ($choice === 'badge' && ! $config->badge_time) {
            throw ValidationException::withMessages(['choice' => 'Clocking in at the badge time is turned off. Clock in now instead.']);
        }
        // The shift as it is now, not as it was when they badged: a moved, cancelled or reassigned
        // shift, or a narrower window, means the badge no longer belongs to it.
        $shift = $row->shift_id !== null ? Shift::query()->find($row->shift_id, ['id', 'user_id', 'site_id', 'starts_at', 'ends_at', 'status']) : null;
        if ($choice !== 'not_working' && ($shift === null || (int) $shift->user_id !== (int) $user->id || (int) $shift->site_id !== (int) $row->site_id
            || ! in_array($shift->status, DoorClockinRules::CLOCKABLE, true) || ($shift->ends_at !== null && $shift->ends_at->lte($now)))) {
            throw ValidationException::withMessages(['choice' => 'Your shift has changed since you badged in. Clock in the usual way if you’re working.']);
        }
        if ($choice === 'badge' && $shift !== null) {
            $badgeAt = CarbonImmutable::instance($row->badge_at);
            $starts = CarbonImmutable::instance($shift->starts_at);
            if ($badgeAt->lt($starts->subMinutes((int) $config->before_minutes)) || $badgeAt->gt($starts->addMinutes((int) $config->after_minutes))) {
                throw ValidationException::withMessages(['choice' => 'Your shift has moved since you badged in, so the badge time no longer fits it. Clock in now instead.']);
            }
        }

        // Claim the suggestion so a double tap can't clock in twice; give it back if clocking in fails.
        $claimed = DoorClockin::query()->whereKey($row->id)->whereNull('closed_at')
            ->where(fn ($query) => $query->whereNull('answer')->orWhere(fn ($stale) => $stale->where('answer', 'claiming')->where('updated_at', '<', $now->subMinutes(2))))
            ->update(['answer' => 'claiming', 'updated_at' => $now]);
        if ($claimed !== 1) {
            throw ValidationException::withMessages(['choice' => 'You’ve already answered this one.']);
        }

        if ($choice === 'not_working') {
            $row->forceFill(['answer' => 'not_working', 'answered_at' => $now])->save();
            AuditLogger::log('security_devices.door_clockin.not_working', $row, ['actor_id' => (int) $user->id, 'shift_id' => $row->shift_id, 'site_id' => (int) $row->site_id]);

            return ['answer' => 'not_working', 'clock_in_at' => null, 'session_id' => null];
        }

        $badge = [
            'clockin_id' => (int) $row->id,
            'event_id' => $row->event_id,
            'site' => $row->site?->name,
            'door' => $row->door?->name,
            'method' => $row->method,
            'at' => $row->badge_at->toIso8601String(),
        ];
        try {
            $session = $this->attendance->clockIn($user, $choice === 'badge'
                ? ['shift_id' => $row->shift_id, 'clock_in_at' => $row->badge_at->utc()->toIso8601String(), 'source' => 'door_badge', 'meta' => ['door_badge' => $badge]]
                // "Now instead" isn't a badge time record: only which suggestion it answered, not the door
                // or the badge time (attendance is kept 6 years; unconfirmed badges go at 90 days).
                : ['shift_id' => $row->shift_id, 'source' => 'manual', 'meta' => ['door_badge_suggestion' => ['clockin_id' => (int) $row->id, 'chose' => 'now']]]);
        } catch (Throwable $exception) {
            DoorClockin::query()->whereKey($row->id)->where('answer', 'claiming')->update(['answer' => null, 'updated_at' => $now]);
            throw ValidationException::withMessages(['choice' => self::refusal($exception)]);
        }

        $row->forceFill(['answer' => $choice, 'answered_at' => $now, 'attendance_session_id' => (int) $session->id])->save();

        return ['answer' => $choice, 'clock_in_at' => $session->clock_in_at?->toIso8601String(), 'session_id' => (int) $session->id];
    }

    /** Attendance's own words when it refuses, or a plain line for a concealed refusal. */
    private static function refusal(Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            return (string) collect($exception->errors())->flatten()->first();
        }
        if ($exception instanceof HttpExceptionInterface) {
            return $exception->getStatusCode() === 403
                ? 'You can’t clock in from here. Ask your manager.'
                : 'This shift can’t be clocked into from here. Ask your manager.';
        }
        if ($exception instanceof \LogicException) {
            return $exception->getMessage();
        }
        report($exception);

        return 'You weren’t clocked in. Try again, or clock in the usual way.';
    }
}
