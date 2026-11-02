<?php

namespace App\Domain\SecurityDevices\DoorClockins;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\SecurityDevices\Access\Models\AccessDoor;
use App\Domain\SecurityDevices\Access\Models\AccessEvent;
use App\Domain\SecurityDevices\Access\Models\AccessPerson;
use App\Domain\SecurityDevices\DoorClockins\Models\DoorClockin;
use App\Domain\SecurityDevices\DoorClockins\Models\DoorClockinConfig;
use App\Domain\SecurityDevices\DoorClockins\Models\DoorClockinDoor;
use App\Domain\SecurityDevices\DoorClockins\Models\DoorClockinTold;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\DoorClockinNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * The clock-ins-from-doors engine (D15), run every minute. It reads door events from its own place
 * in the stream (it never hooks into the hub sync), and:
 *
 *  1. opens a row for each rostered shift at a Site where a door counts, when its window opens;
 *  2. for a badge by card, PIN or phone at a counted door, from a person matched to staff who was
 *     told at least a week before (see DoorClockinTold), within the window of their own rostered
 *     shift at that Site, with no clock-in yet: records it and suggests the clock-in to that person
 *     (one per shift). Faces, fingerprints, residents' fobs, unmatched people, uncounted doors,
 *     people not yet told and badges before a door's start date never count;
 *  3. a badge from someone with no shift at that Site that day is kept as "not on the roster";
 *  4. 15 minutes after each rostered start: badged but not confirmed → a reminder to the person;
 *     no badge and no clock-in → the house manager; and 15 minutes after a badge from someone not
 *     on the roster (unless a shift has been added since, which then suggests it) → the house manager;
 *  5. closes suggestions nobody answered once the shift has ended.
 *
 * Nothing is ever clocked in here: only the person can, by choosing (DoorClockinChoices).
 */
final class DoorClockinEngine
{
    /** Door events read per tick; the rest wait for the next minute. */
    private const int BATCH = 1000;

    /** Door events newer than this are looked at again next minute: ids are handed out before commit, so a slower hub sync can still land a lower id. */
    private const int SETTLE_MINUTES = 2;

    /** @var array<string, bool> who was told in time, per user, Site and day, for this tick */
    private array $told = [];

    /** Starts further back than this are only marked checked, never told (a late tick tells nobody hours later). */
    private const int TELL_WITHIN_MINUTES = 120;

    public function __construct(private readonly DoorClockinSetup $setup) {}

    /** @return array{opened: int, suggested: int, not_rostered: int, reminded: int, told: int, closed: int} */
    public function tick(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $config = $this->setup->config();
        $result = ['opened' => 0, 'suggested' => 0, 'not_rostered' => 0, 'reminded' => 0, 'told' => 0, 'closed' => 0];

        $doors = DoorClockinDoor::query()->with('door:id,console_id,name')->get()->keyBy('door_id');
        if ($doors->isEmpty()) {
            // Off everywhere: skip what arrives meanwhile, so turning it on never replays old badges.
            $last = (int) AccessEvent::query()->max('id');
            if ($last > $config->cursor_event_id) {
                $config->forceFill(['cursor_event_id' => $last])->save();
            }

            return $result;
        }

        /** @var array<int, string> $siteStarts the first day each Site counts (its earliest door) */
        $siteStarts = $doors->groupBy('site_id')->map(fn (Collection $rows): string => (string) $rows->min(fn (DoorClockinDoor $row): string => $row->starts_on->format('Y-m-d')))->all();

        // Staff matched to a card after the notice went out are told now; they count a week later.
        $this->setup->catchUpNotices(array_map('intval', array_keys($siteStarts)), $now);
        $this->told = [];

        $result['opened'] = $this->openRows($config, $siteStarts, $now);
        $this->syncRows($config, $now);
        [$result['suggested'], $result['not_rostered']] = $this->readEvents($config, $doors, $now);
        [$result['reminded'], $told] = $this->checkRosteredGaps($config, $now);
        [$converted, $toldNotRostered] = $this->checkNotRostered($config, $now);
        $result['suggested'] += $converted;
        $result['told'] = $told + $toldNotRostered;
        $result['closed'] = $this->closeEnded($now);

        return $result;
    }

    /** Rows go at 90 days, like the names on door events (D14). Confirmed clock-ins live on as attendance. */
    public function prune(?CarbonImmutable $now = null): int
    {
        $cutoff = DoorClockinRules::local($now ?? CarbonImmutable::now('UTC'))->subDays(DoorClockinRules::KEEP_DAYS)->toDateString();

        return DoorClockin::query()->where('day', '<', $cutoff)->delete();
    }

    /**
     * 1. A row for each rostered shift at a counting Site whose window has opened.
     *
     * @param  array<int, string>  $siteStarts
     */
    private function openRows(DoorClockinConfig $config, array $siteStarts, CarbonImmutable $now): int
    {
        $shifts = Shift::query()
            ->with('client:id,site_id')
            ->whereIn('site_id', array_keys($siteStarts))
            ->whereNotNull('user_id')
            ->whereIn('status', DoorClockinRules::CLOCKABLE)
            ->where('starts_at', '<=', $now->addMinutes($config->before_minutes))
            ->where('starts_at', '>=', $now->subMinutes(self::TELL_WITHIN_MINUTES))
            ->visibleToFrontline()
            ->get(['id', 'user_id', 'client_id', 'site_id', 'starts_at', 'ends_at', 'status']);

        $opened = 0;
        foreach ($shifts as $shift) {
            $siteId = (int) $shift->site_id;
            // The canonical attendance Site rule: the shift's Site is its client's Site.
            if ($shift->client !== null && (int) $shift->client->site_id !== $siteId) {
                continue;
            }
            $day = DoorClockinRules::local($shift->starts_at)->toDateString();
            if (! isset($siteStarts[$siteId]) || $day < $siteStarts[$siteId]) {
                continue;
            }
            // One row per shift and person: a shift given to someone else gets its own row.
            $row = $this->firstOrCreate('shift:'.$shift->id.':'.$shift->user_id, [
                'kind' => DoorClockin::ROSTERED,
                'day' => $day,
                'site_id' => $siteId,
                'user_id' => (int) $shift->user_id,
                'shift_id' => (int) $shift->id,
                'shift_starts_at' => $shift->starts_at,
                'shift_ends_at' => $shift->ends_at,
            ]);
            if ($row?->wasRecentlyCreated) {
                $opened++;
            }
        }

        return $opened;
    }

    /**
     * Keep open rows in step with their shift: a moved start moves, a cancelled or reassigned shift
     * closes. A badge that no longer falls in the window of the moved start is dropped, so its
     * suggestion goes (the person can still clock in the usual way).
     */
    private function syncRows(DoorClockinConfig $config, CarbonImmutable $now): void
    {
        $rows = DoorClockin::query()
            ->where('kind', DoorClockin::ROSTERED)
            ->whereNull('closed_at')
            ->whereNull('answer')
            ->where('day', '>=', DoorClockinRules::local($now)->subDay()->toDateString())
            ->with('shift:id,user_id,site_id,starts_at,ends_at,status')
            ->get();
        foreach ($rows as $row) {
            $shift = $row->shift;
            if ($shift === null || $shift->status === 'cancelled' || (int) $shift->site_id !== (int) $row->site_id || (int) $shift->user_id !== (int) $row->user_id) {
                $row->forceFill(['closed_at' => $now, 'close_reason' => 'shift_changed'])->save();

                continue;
            }
            if (! $shift->starts_at->equalTo($row->shift_starts_at) || ! $shift->ends_at?->equalTo($row->shift_ends_at)) {
                $fields = ['shift_starts_at' => $shift->starts_at, 'shift_ends_at' => $shift->ends_at, 'day' => DoorClockinRules::local($shift->starts_at)->toDateString()];
                $starts = CarbonImmutable::instance($shift->starts_at);
                if ($row->badge_at !== null && ($row->badge_at->lt($starts->subMinutes((int) $config->before_minutes)) || $row->badge_at->gt($starts->addMinutes((int) $config->after_minutes)))) {
                    $fields += ['badge_at' => null, 'suggested_at' => null, 'event_id' => null, 'door_id' => null, 'method' => null, 'reminded_at' => null];
                }
                $row->forceFill($fields)->save();
            }
        }
    }

    /**
     * 2 and 3. New door events since the engine's own cursor.
     *
     * @param  Collection<int, DoorClockinDoor>  $doors  keyed by door id
     * @return array{0: int, 1: int}
     */
    private function readEvents(DoorClockinConfig $config, Collection $doors, CarbonImmutable $now): array
    {
        $events = AccessEvent::query()
            ->where('id', '>', $config->cursor_event_id)
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get(['id', 'console_id', 'door_id', 'person_id', 'kind', 'method', 'occurred_at', 'created_at']);
        if ($events->isEmpty()) {
            return [0, 0];
        }
        $people = AccessPerson::query()->whereIn('id', $events->pluck('person_id')->filter()->unique())->get(['id', 'console_id', 'kind', 'match_state', 'status', 'user_id'])->keyBy('id');
        $consoleSites = AccessDoor::query()->whereIn('id', $events->pluck('door_id')->filter()->unique())->with('console:id,site_id')->get(['id', 'console_id'])
            ->mapWithKeys(fn (AccessDoor $door): array => [(int) $door->id => (int) $door->console?->site_id]);

        $suggested = 0;
        $notRostered = 0;
        foreach ($events as $event) {
            try {
                $outcome = $this->consider($event, $doors, $people, $consoleSites, $config, $now);
                $suggested += $outcome === 'suggested' ? 1 : 0;
                $notRostered += $outcome === 'not_rostered' ? 1 : 0;
            } catch (Throwable $exception) {
                // One bad event never stops the stream; it is skipped and reported.
                report($exception);
                Log::warning('A door event could not be considered for a clock-in', ['event_id' => $event->id]);
            }
        }
        // Move the place only past events that have settled; newer ones are looked at again next
        // minute (every step above is safe to repeat: one suggestion per shift, one row per badge day).
        $settled = $now->subMinutes(self::SETTLE_MINUTES);
        $cursor = (int) $config->cursor_event_id;
        foreach ($events as $event) {
            if ($event->created_at === null || $event->created_at->gt($settled)) {
                break;
            }
            $cursor = (int) $event->id;
        }
        if ($cursor > (int) $config->cursor_event_id) {
            $config->forceFill(['cursor_event_id' => $cursor])->save();
        }

        return [$suggested, $notRostered];
    }

    /**
     * @param  Collection<int, DoorClockinDoor>  $doors
     * @param  Collection<int, AccessPerson>  $people
     * @param  Collection<int, int>  $consoleSites
     */
    private function consider(AccessEvent $event, Collection $doors, Collection $people, Collection $consoleSites, DoorClockinConfig $config, CarbonImmutable $now): ?string
    {
        if (! in_array($event->kind, DoorClockinRules::KINDS, true) || ! in_array($event->method, DoorClockinRules::METHODS, true)) {
            return null; // never a face, a fingerprint, an exit button, a remote unlock or a schedule
        }
        $door = $event->door_id !== null ? $doors->get((int) $event->door_id) : null;
        if ($door === null || (int) $door->door?->console_id !== (int) $event->console_id) {
            return null; // not a door that counts
        }
        $siteId = (int) $door->site_id;
        if (($consoleSites[(int) $event->door_id] ?? null) !== $siteId) {
            return null;
        }
        $badgeAt = CarbonImmutable::instance($event->occurred_at);
        $day = DoorClockinRules::local($badgeAt)->toDateString();
        if ($day < $door->starts_on->format('Y-m-d') || $badgeAt->gt($now->addMinutes(5))) {
            return null; // before staff were told, or a clock in the future
        }
        $person = $event->person_id !== null ? $people->get((int) $event->person_id) : null;
        if ($person === null || (int) $person->console_id !== (int) $event->console_id
            || $person->kind !== 'staff' || $person->match_state !== 'matched' || $person->user_id === null || $person->status === 'deactivated') {
            return null; // residents' fobs, unknown and unmatched people never count
        }
        $userId = (int) $person->user_id;
        if (! $this->toldInTime($userId, $siteId, $day)) {
            return null; // not told a week before this day (IPP3): their badges don't count yet
        }
        if (HrAttendanceSession::query()->where('user_id', $userId)->open()->exists()) {
            return null; // already clocked in
        }

        $row = DoorClockin::query()
            ->where('user_id', $userId)
            ->where('site_id', $siteId)
            ->where('kind', DoorClockin::ROSTERED)
            ->whereNull('closed_at')
            ->whereBetween('shift_starts_at', [$badgeAt->subMinutes($config->after_minutes), $badgeAt->addMinutes($config->before_minutes)])
            ->get()
            ->sortBy(fn (DoorClockin $candidate): int => (int) abs($candidate->shift_starts_at->getTimestamp() - $badgeAt->getTimestamp()))
            ->first();

        if ($row !== null) {
            return $this->suggest($row, $event, $badgeAt, $now) ? 'suggested' : null;
        }

        if ($this->hasShiftThatDay($userId, $siteId, $day)) {
            return null; // on the roster today, but outside the window
        }

        $created = $this->firstOrCreate("badge:{$userId}:{$siteId}:{$day}", [
            'kind' => DoorClockin::NOT_ROSTERED,
            'day' => $day,
            'site_id' => $siteId,
            'user_id' => $userId,
            'event_id' => (int) $event->id,
            'door_id' => (int) $event->door_id,
            'method' => $event->method,
            'badge_at' => $badgeAt,
        ]);

        return $created?->wasRecentlyCreated ? 'not_rostered' : null;
    }

    /** Record the badge on its shift's row and suggest the clock-in, once per shift. */
    private function suggest(DoorClockin $row, AccessEvent $event, CarbonImmutable $badgeAt, CarbonImmutable $now): bool
    {
        if ($row->badge_at !== null || $row->answer !== null || $row->closed_at !== null) {
            return false; // one suggestion per shift: the first badge
        }
        if ($row->shift_id !== null && HrAttendanceSession::query()->where('user_id', $row->user_id)->where('shift_id', $row->shift_id)->exists()) {
            return false; // already clocked into this shift
        }
        $updated = DoorClockin::query()->whereKey($row->id)->whereNull('badge_at')->whereNull('answer')->whereNull('closed_at')->update([
            'event_id' => (int) $event->id,
            'door_id' => (int) $event->door_id,
            'method' => $event->method,
            'badge_at' => $badgeAt,
            'suggested_at' => $now,
            'updated_at' => $now,
        ]);
        if ($updated !== 1) {
            return false;
        }
        $row->refresh();
        $this->tellPerson($row, 'suggestion');

        return true;
    }

    /** Told about clock-ins at this Site at least NOTICE_DAYS whole days before this NZ day. */
    private function toldInTime(int $userId, int $siteId, string $day): bool
    {
        $key = "{$userId}:{$siteId}:{$day}";
        if (! array_key_exists($key, $this->told)) {
            $by = CarbonImmutable::createFromFormat('Y-m-d', $day, DoorClockinRules::timezone())->startOfDay()->subDays(DoorClockinRules::NOTICE_DAYS - 1)->utc();
            $this->told[$key] = DoorClockinTold::query()->where('user_id', $userId)->where('site_id', $siteId)->where('told_at', '<', $by)->exists();
        }

        return $this->told[$key];
    }

    private function hasShiftThatDay(int $userId, int $siteId, string $day): bool
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', $day, DoorClockinRules::timezone())->startOfDay()->utc();

        return Shift::query()
            ->where('user_id', $userId)
            ->where('site_id', $siteId)
            ->where('status', '!=', 'cancelled')
            ->where('starts_at', '<', $start->addDay())
            ->where('ends_at', '>', $start)
            ->exists();
    }

    /**
     * 4. Fifteen minutes after each rostered start.
     *
     * @return array{0: int, 1: int} reminders sent, managers told
     */
    private function checkRosteredGaps(DoorClockinConfig $config, CarbonImmutable $now): array
    {
        $rows = DoorClockin::query()
            ->where('kind', DoorClockin::ROSTERED)
            ->whereNull('closed_at')
            ->whereNull('gap_checked_at')
            ->where('shift_starts_at', '<=', $now->subMinutes(DoorClockinRules::GAP_AFTER_MINUTES))
            ->with('user:id,name,email')
            ->orderBy('id')
            ->limit(500)
            ->get();
        $reminded = 0;
        $told = 0;
        foreach ($rows as $row) {
            $recent = $row->shift_starts_at->gte($now->subMinutes(self::TELL_WITHIN_MINUTES));
            $clockedIn = HrAttendanceSession::query()->where('user_id', $row->user_id)
                ->where(fn ($query) => $query->where('shift_id', $row->shift_id)->orWhere(fn ($open) => $open->open()))
                ->exists();
            $fields = ['gap_checked_at' => $now];
            if ($recent && ! $clockedIn && $row->answer === null) {
                if ($row->badge_at !== null) {
                    if ($this->tellPerson($row, 'reminder', $config->channels('unconfirmed'))) {
                        $fields['reminded_at'] = $now;
                        $reminded++;
                    }
                } else {
                    $managers = $this->tellManagers($row, 'no_badge', $config->channels('no_badge'));
                    if ($managers !== []) {
                        $fields += ['manager_told_at' => $now, 'manager_told_user_ids' => $managers];
                        $told++;
                    }
                }
            }
            $row->forceFill($fields)->save();
        }

        return [$reminded, $told];
    }

    /**
     * 4. Fifteen minutes after a badge from someone not on the roster: a shift added since turns it
     * into a suggestion; otherwise the house manager hears.
     *
     * @return array{0: int, 1: int} suggestions made, managers told
     */
    private function checkNotRostered(DoorClockinConfig $config, CarbonImmutable $now): array
    {
        $rows = DoorClockin::query()
            ->where('kind', DoorClockin::NOT_ROSTERED)
            ->whereNull('closed_at')
            ->whereNull('gap_checked_at')
            ->where('badge_at', '<=', $now->subMinutes(DoorClockinRules::GAP_AFTER_MINUTES))
            ->with('user:id,name,email')
            ->orderBy('id')
            ->limit(500)
            ->get();
        $converted = 0;
        $told = 0;
        foreach ($rows as $row) {
            $shift = Shift::query()
                ->where('user_id', $row->user_id)
                ->where('site_id', $row->site_id)
                ->whereIn('status', DoorClockinRules::CLOCKABLE)
                ->whereBetween('starts_at', [$row->badge_at->subMinutes($config->after_minutes), $row->badge_at->addMinutes($config->before_minutes)])
                ->visibleToFrontline()
                ->orderBy('starts_at')
                ->first(['id', 'user_id', 'site_id', 'starts_at', 'ends_at']);
            if ($shift !== null) {
                // On the roster after all: the badge becomes that shift's suggestion (if it has none
                // yet and they aren't clocked in), and this row goes.
                $event = $row->event_id !== null ? AccessEvent::query()->find($row->event_id) : null;
                if ($event !== null && ! HrAttendanceSession::query()->where('user_id', $row->user_id)->open()->exists()) {
                    $target = $this->firstOrCreate('shift:'.$shift->id.':'.$row->user_id, [
                        'kind' => DoorClockin::ROSTERED,
                        'day' => DoorClockinRules::local($shift->starts_at)->toDateString(),
                        'site_id' => (int) $row->site_id,
                        'user_id' => (int) $row->user_id,
                        'shift_id' => (int) $shift->id,
                        'shift_starts_at' => $shift->starts_at,
                        'shift_ends_at' => $shift->ends_at,
                    ]);
                    if ($target !== null && $this->suggest($target, $event, $row->badge_at, $now)) {
                        $converted++;
                    }
                }
                $row->delete();

                continue;
            }
            $fields = ['gap_checked_at' => $now];
            if ($row->badge_at->gte($now->subMinutes(self::TELL_WITHIN_MINUTES))) {
                $managers = $this->tellManagers($row, 'not_rostered', $config->channels('not_rostered'));
                if ($managers !== []) {
                    $fields += ['manager_told_at' => $now, 'manager_told_user_ids' => $managers];
                    $told++;
                }
            }
            $row->forceFill($fields)->save();
        }

        return [$converted, $told];
    }

    /**
     * 5. A suggestion nobody answered closes when its shift ends; it was never a time record. So does
     * one left "claiming" by an answer that never finished.
     */
    private function closeEnded(CarbonImmutable $now): int
    {
        return DoorClockin::query()
            ->whereNull('closed_at')
            ->where(fn ($query) => $query->whereNull('answer')
                ->orWhere(fn ($stuck) => $stuck->where('answer', 'claiming')->where('updated_at', '<', $now->subMinutes(5))))
            ->where(fn ($query) => $query
                ->where('shift_ends_at', '<=', $now)
                ->orWhere(fn ($old) => $old->whereNull('shift_ends_at')->where('day', '<', DoorClockinRules::today($now))))
            ->update(['closed_at' => $now, 'close_reason' => 'ended', 'updated_at' => $now]);
    }

    /**
     * The suggestion, or the reminder, to the person whose badge it was.
     *
     * @param  list<string>  $channels
     */
    private function tellPerson(DoorClockin $row, string $kind, array $channels = ['database']): bool
    {
        $user = $row->user ?? User::query()->find($row->user_id);
        if ($user === null || $channels === [] || $row->badge_at === null) {
            return false;
        }
        $row->loadMissing(['door:id,name', 'site:id,name']);
        $place = DoorClockinRules::doorPlace($row->site?->name, $row->door?->name);
        $badge = DoorClockinRules::time($row->badge_at);
        $start = $row->shift_starts_at !== null ? DoorClockinRules::time($row->shift_starts_at) : null;
        $title = $kind === 'reminder'
            ? 'Still to confirm: your clock-in'.($start !== null ? " for {$start}" : '')
            : 'Clock in for your '.($start !== null ? "{$start} " : '').'shift?';
        $body = $kind === 'reminder'
            ? "You badged in at {$place} at {$badge}, but haven’t chosen yet. Clock in at {$badge}, or clock in now instead, on My Day."
            : "You badged in at {$place} at {$badge}. Nothing is clocked in until you choose.";
        try {
            Notification::send($user, new DoorClockinNotification($kind, $title, $body, '/my-day?door_clockin='.$row->id, $channels, (int) $row->id));

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * A gap to the house managers. Returns who was told.
     *
     * @param  list<string>  $channels
     * @return list<int>
     */
    private function tellManagers(DoorClockin $row, string $gap, array $channels): array
    {
        if ($channels === []) {
            return [];
        }
        $managers = DoorClockinRules::houseManagers((int) $row->site_id)->reject(fn (User $user): bool => (int) $user->id === (int) $row->user_id)->values();
        if ($managers->isEmpty()) {
            return [];
        }
        $row->loadMissing(['door:id,name', 'site:id,name', 'user:id,name']);
        $name = (string) ($row->user?->name ?? 'Someone');
        $site = (string) ($row->site?->name ?? 'the Site');
        if ($gap === 'no_badge') {
            $start = DoorClockinRules::time($row->shift_starts_at);
            $by = DoorClockinRules::time($row->shift_starts_at->addMinutes(DoorClockinRules::GAP_AFTER_MINUTES));
            $title = "{$name} hasn’t clocked in";
            $body = "On the roster at {$site} from {$start}, with no badge and no clock-in by {$by}.";
        } else {
            $place = DoorClockinRules::doorPlace($row->site?->name, $row->door?->name);
            $title = "{$name} badged in but isn’t on the roster";
            $body = "{$place} at ".DoorClockinRules::time($row->badge_at).'. Nothing was suggested. Is it a shift that isn’t on the roster?';
        }
        try {
            Notification::send($managers, new DoorClockinNotification(
                $gap === 'no_badge' ? 'gap' : 'not_rostered',
                $title,
                $body,
                '/security-devices/access?tab=clockins&clockin='.$row->id,
                $channels,
                (int) $row->id,
            ));
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }

        return $managers->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();
    }

    /**
     * Create once by its dedupe key, safe when two ticks race.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function firstOrCreate(string $key, array $attributes): ?DoorClockin
    {
        $existing = DoorClockin::query()->where('dedupe_key', $key)->first();
        if ($existing !== null) {
            return $existing;
        }
        try {
            return DoorClockin::query()->create(['dedupe_key' => $key, ...$attributes]);
        } catch (QueryException) {
            return DoorClockin::query()->where('dedupe_key', $key)->first();
        }
    }
}
