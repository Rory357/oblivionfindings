<?php

namespace App\Domain\SecurityDevices\DoorClockins;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Domain\Hr\Services\TimeTrackingService;
use App\Domain\SecurityDevices\DoorClockins\Models\DoorClockin;
use App\Models\User;
use App\Notifications\DoorClockinNotification;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * A manager sorts out a gap or mismatch (pop-up 89): keep the clock-in, ask the person, or, for a
 * badge from someone not on the roster, mark it not work.
 *
 * "Use the badge time" is not offered: attendance has no correction path for a clock-in time
 * (AttendanceService::correctSession changes only the clock-out and breaks), and a new one isn't
 * invented here. A note with "Keep" goes on the person's time entry through the existing
 * TimeTrackingService::addNote, with the manager's name, so they see it on their timesheet.
 */
final class DoorClockinReview
{
    public const array CHOICES = ['keep', 'ask', 'not_work'];

    public function __construct(
        private readonly TimeTrackingService $timeTracking,
    ) {}

    /** The row, only when it's at one of the viewer's Sites. */
    public function find(User $viewer, int $clockinId): ?DoorClockin
    {
        if (! DoorClockinRules::canReview($viewer)) {
            return null;
        }

        return DoorClockin::query()
            ->whereKey($clockinId)
            ->whereIn('site_id', DoorClockinRules::reviewSiteIds($viewer))
            ->first();
    }

    /** The person's clock-in for the row's shift, or their clock-in that day at that Site with no shift. */
    public function session(DoorClockin $row): ?HrAttendanceSession
    {
        if ($row->attendance_session_id !== null) {
            $own = HrAttendanceSession::query()->find($row->attendance_session_id);
            if ($own !== null) {
                return $own;
            }
        }
        if ($row->shift_id !== null) {
            return HrAttendanceSession::query()->where('user_id', $row->user_id)->where('shift_id', $row->shift_id)->orderBy('clock_in_at')->first();
        }
        $start = CarbonImmutable::createFromFormat('Y-m-d', $row->day->format('Y-m-d'), DoorClockinRules::timezone())->startOfDay()->utc();

        return HrAttendanceSession::query()->where('user_id', $row->user_id)->where('site_id', $row->site_id)
            ->whereBetween('clock_in_at', [$start, $start->addDay()])->orderBy('clock_in_at')->first();
    }

    /** @return list<string> what a manager can choose for this row, in the drawing's order */
    public function choices(User $viewer, DoorClockin $row): array
    {
        // Nobody sorts out their own clock-in: it stays with the other managers.
        if (! DoorClockinRules::canReview($viewer) || (int) $row->user_id === (int) $viewer->id) {
            return [];
        }
        $choices = [];
        if ($row->kind === DoorClockin::ROSTERED && $this->session($row) !== null) {
            $choices[] = 'keep';
        }
        if ($row->kind === DoorClockin::NOT_ROSTERED) {
            $choices[] = 'not_work';
        }
        $choices[] = 'ask';

        return $choices;
    }

    /** @return array{review: string, at: string, note_on_timesheet: bool} */
    public function review(User $actor, int $clockinId, string $choice, ?string $note, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        if (! DoorClockinRules::canReview($actor)) {
            throw new AuthorizationException('You can’t sort out clock-ins.');
        }
        $row = $this->find($actor, $clockinId);
        abort_if($row === null, 404);
        $note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 1000) : null;
        if (! in_array($choice, $this->choices($actor, $row), true)) {
            throw ValidationException::withMessages(['choice' => match (true) {
                (int) $row->user_id === (int) $actor->id => 'Another manager sorts out your own clock-in.',
                $choice === 'keep' => 'There’s no clock-in to keep yet.',
                default => 'Choose what to do with this clock-in.',
            }]);
        }

        $noteOnTimesheet = false;
        if ($choice === 'keep' && $note !== null) {
            $noteOnTimesheet = $this->noteOnTimesheet($actor, $row, $note);
        }
        if ($choice === 'ask') {
            $this->ask($actor, $row, $note); // sent first: "asked" is recorded only once the message went
        }

        $row->forceFill([
            'review' => $choice === 'keep' ? 'kept' : ($choice === 'ask' ? 'asked' : 'not_work'),
            'reviewed_by_user_id' => $actor->id,
            'reviewed_at' => $now,
            'review_note' => $note,
        ])->save();
        AuditLogger::log('security_devices.door_clockin.reviewed', $row, [
            'actor_id' => (int) $actor->id,
            'choice' => $choice,
            'site_id' => (int) $row->site_id,
            'user_id' => (int) $row->user_id,
            'note_on_timesheet' => $noteOnTimesheet,
        ]);

        return ['review' => (string) $row->review, 'at' => $now->toIso8601String(), 'note_on_timesheet' => $noteOnTimesheet];
    }

    /** "Keep" with a note: the note goes on the time entry, through attendance's own note path. */
    private function noteOnTimesheet(User $actor, DoorClockin $row, string $note): bool
    {
        if (! ($actor->canDo('timesheets.manageAny') || $actor->canDo('timesheets.approve'))) {
            throw ValidationException::withMessages(['note' => 'You can’t add notes to timesheets, so leave the note empty or ask someone who can.']);
        }
        $session = $this->session($row);
        $entry = $session !== null ? HrTimeEntry::query()->where('attendance_session_id', $session->id)->first() : null;
        if ($entry === null) {
            throw ValidationException::withMessages(['note' => 'The time entry isn’t ready yet, so the note can’t be kept with it. Try again in a minute.']);
        }
        try {
            $this->timeTracking->addNote($entry, $actor, $note);
        } catch (HttpExceptionInterface) {
            throw ValidationException::withMessages(['note' => 'You can’t add a note to this timesheet.']);
        } catch (Throwable $exception) {
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            report($exception);
            throw ValidationException::withMessages(['note' => 'The note wasn’t saved. Try again.']);
        }

        return true;
    }

    private function ask(User $actor, DoorClockin $row, ?string $note): void
    {
        $row->loadMissing(['user:id,name,email', 'site:id,name']);
        if ($row->user === null) {
            return;
        }
        $when = DoorClockinRules::date($row->day->format('Y-m-d'));
        $facts = [];
        if ($row->badge_at !== null) {
            $facts[] = 'you badged in at '.DoorClockinRules::time($row->badge_at);
        }
        $session = $this->session($row);
        if ($session?->clock_in_at !== null) {
            $facts[] = 'clocked in at '.DoorClockinRules::time($session->clock_in_at);
        } elseif ($row->shift_starts_at !== null) {
            $facts[] = 'were rostered from '.DoorClockinRules::time($row->shift_starts_at);
        }
        $body = "About {$when} at ".($row->site?->name ?? 'your Site').($facts !== [] ? ': '.implode(', ', $facts).'.' : '.');
        $body .= $note !== null ? "\n\n{$note}" : "\n\nReply to {$actor->name}, or check your times on My Day.";
        try {
            Notification::send($row->user, new DoorClockinNotification('question', "{$actor->name} asked about your clock-in", $body, '/my-day', ['database'], (int) $row->id));
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['choice' => 'The message wasn’t sent. Try again.']);
        }
    }
}
