<?php

namespace App\Domain\SecurityDevices\DoorClockins;

use App\Domain\SecurityDevices\Access\Models\AccessDoor;
use App\Domain\SecurityDevices\Access\Models\AccessPerson;
use App\Domain\SecurityDevices\DoorClockins\Models\DoorClockin;
use App\Domain\SecurityDevices\DoorClockins\Models\DoorClockinConfig;
use App\Domain\SecurityDevices\DoorClockins\Models\DoorClockinDoor;
use App\Domain\SecurityDevices\DoorClockins\Models\DoorClockinNotice;
use App\Domain\SecurityDevices\DoorClockins\Models\DoorClockinTold;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use App\Notifications\DoorClockinNotification;
use App\Notifications\QueuedDoorClockinNotification;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Turning clock-ins from doors on and off, and its settings (Setup › Doors & access › Clock-ins
 * from doors, pg-sdr-15n; pop-up 92). Off by default.
 *
 * Adding a door always sends the notice first (Privacy Act IPP3; door events used for a second
 * purpose, IPP10) to everyone matched to staff with a card at that door's Site, and the door counts
 * only from a start date at least a week later. Removing a door, or turning it off, takes effect at
 * once; confirmed clock-ins stay. Someone sees and changes only the doors at their own Sites; the
 * window, the badge-time switch and gap alerts apply to every Site, so only someone who sees every
 * Site changes those. Each person told is recorded (DoorClockinTold) and counts only a week later;
 * staff matched to a card afterwards are told then. Every change is in the audit log, and so in
 * Setup › Change history.
 */
final class DoorClockinSetup
{
    public function __construct(private readonly SecurityDevicesAccessService $access) {}

    /** The one settings row, made with its defaults on first use. */
    public function config(): DoorClockinConfig
    {
        $config = DoorClockinConfig::query()->orderBy('id')->first();
        if ($config !== null) {
            return $config;
        }
        $config = new DoorClockinConfig;
        $config->save();

        return $config->refresh();
    }

    /**
     * The doors that count at the viewer's Sites (all Sites when null), with their door and Site.
     *
     * @return Collection<int, DoorClockinDoor>
     */
    public function doors(?User $viewer = null): Collection
    {
        $query = DoorClockinDoor::query()->with(['door:id,console_id,name', 'site:id,name', 'notice']);
        if ($viewer !== null) {
            $query->whereIn('site_id', $this->access->accessibleSiteIds($viewer));
        }

        return $query->orderBy('site_id')->orderBy('door_id')->get();
    }

    /**
     * Turn it on, or change which doors count and the window (pop-up 92).
     *
     * @param  array<string, mixed>  $input  door_ids, before, after, notice, email, starts_on
     * @return array{added: int, removed: int, notice_id: int|null, told: int, not_told: int, starts_on: string|null}
     */
    public function saveDoors(User $actor, array $input, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        if (! DoorClockinRules::canChange($actor)) {
            throw new AuthorizationException('You can’t change clock-ins from doors.');
        }
        $siteIds = $this->access->accessibleSiteIds($actor);
        $doorIds = collect(is_array($input['door_ids'] ?? null) ? $input['door_ids'] : [])
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)->unique()->values();
        $before = (int) ($input['before'] ?? 0);
        $after = (int) ($input['after'] ?? 0);
        $errors = [];

        $doors = AccessDoor::query()->with('console:id,site_id')->whereIn('id', $doorIds)->get()->keyBy('id');
        if ($doors->count() !== $doorIds->count() || $doors->contains(fn (AccessDoor $door): bool => ! in_array((int) $door->console?->site_id, $siteIds, true))) {
            $errors['door_ids'] = 'Choose doors at your own Sites.';
        }
        if (! in_array($before, DoorClockinRules::BEFORE_OPTIONS, true)) {
            $errors['before'] = 'Choose 30, 60 or 90 minutes before.';
        }
        if (! in_array($after, DoorClockinRules::AFTER_OPTIONS, true)) {
            $errors['after'] = 'Choose 15, 30 or 60 minutes after.';
        }
        $config = $this->config();
        if (($before !== (int) $config->before_minutes || $after !== (int) $config->after_minutes) && ! DoorClockinRules::seesEverySite($actor)) {
            $errors['before'] = 'The window applies to every Site, so only someone who looks after every Site changes it.';
        }

        $current = DoorClockinDoor::query()->whereIn('site_id', $siteIds)->get()->keyBy('door_id');
        $added = $doorIds->reject(fn (int $id): bool => $current->has($id))->values();
        $removed = $current->keys()->map(fn (mixed $id): int => (int) $id)->reject(fn (int $id): bool => $doorIds->contains($id))->values();
        if ($doorIds->isEmpty() && $current->isEmpty()) {
            $errors['door_ids'] = 'Choose at least one entry door.';
        }

        $notice = trim((string) ($input['notice'] ?? ''));
        $startsOn = is_string($input['starts_on'] ?? null) ? $input['starts_on'] : null;
        $earliest = DoorClockinRules::local($now)->startOfDay()->addDays(DoorClockinRules::NOTICE_DAYS);
        if ($added->isNotEmpty()) {
            if (mb_strlen($notice) < 40) {
                $errors['notice'] = 'Write the notice staff get before it starts.';
            } elseif (mb_strlen($notice) > 4000) {
                $errors['notice'] = 'Keep the notice under 4,000 characters.';
            }
            $start = null;
            try {
                $start = $startsOn !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startsOn) === 1
                    ? CarbonImmutable::createFromFormat('Y-m-d', $startsOn, DoorClockinRules::timezone())?->startOfDay()
                    : null;
            } catch (Throwable) {
                $start = null;
            }
            if ($start === null) {
                $errors['starts_on'] = 'Choose the day it starts.';
            } elseif ($start->lt($earliest)) {
                $errors['starts_on'] = 'It starts at least a week after the notice goes out: '.DoorClockinRules::date($earliest, $now).' or later.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $email = (bool) ($input['email'] ?? false);
        $addedSiteIds = $added->map(fn (int $id): int => (int) $doors[$id]->console->site_id)->unique()->values();
        $recipients = $added->isEmpty() ? collect() : $this->recipients($addedSiteIds->all());
        $recipientSites = $added->isEmpty() ? [] : $this->recipientSites($addedSiteIds->all());

        $result = DB::transaction(function () use ($actor, $now, $before, $after, $current, $added, $removed, $doors, $notice, $startsOn, $email, $addedSiteIds, $recipients, $siteIds): array {
            $config = DoorClockinConfig::query()->whereKey($this->config()->id)->lockForUpdate()->firstOrFail();
            $changes = [];
            if ($config->before_minutes !== $before || $config->after_minutes !== $after) {
                $changes['window'] = ['from' => "{$config->before_minutes}/{$config->after_minutes}", 'to' => "{$before}/{$after}"];
                $config->before_minutes = $before;
                $config->after_minutes = $after;
            }
            if ($added->isNotEmpty()) {
                $config->turned_off_at = null;
                $config->turned_off_by_user_id = null;
            }
            $config->updated_by_user_id = $actor->id;
            $config->save();

            $noticeRow = null;
            if ($added->isNotEmpty()) {
                $noticeRow = DoorClockinNotice::query()->create([
                    'body' => $notice,
                    'by_email' => $email,
                    'site_ids' => $addedSiteIds->all(),
                    'door_ids' => $added->all(),
                    'starts_on' => $startsOn,
                    'sent_by_user_id' => $actor->id,
                    'sent_at' => $now,
                    'recipients' => $recipients->count(),
                ]);
                foreach ($added as $doorId) {
                    DoorClockinDoor::query()->create([
                        'door_id' => $doorId,
                        'site_id' => (int) $doors[$doorId]->console->site_id,
                        'notice_id' => $noticeRow->id,
                        'starts_on' => $startsOn,
                        'added_by_user_id' => $actor->id,
                    ]);
                }
            }
            if ($removed->isNotEmpty()) {
                DoorClockinDoor::query()->whereIn('door_id', $removed)->whereIn('site_id', $siteIds)->delete();
                $this->closeSitesWithoutDoors($current->whereIn('door_id', $removed->all())->pluck('site_id')->unique()->all(), $now);
            }

            if ($added->isNotEmpty() || $removed->isNotEmpty()) {
                AuditLogger::logOrFail('security_devices.settings.changed', $config, [
                    'actor_id' => (int) $actor->id,
                    'key' => $current->isEmpty() ? 'clockins.on' : 'clockins.doors',
                    'from' => $current->count(),
                    'to' => $current->isEmpty() ? true : $current->count() - $removed->count() + $added->count(),
                    'added_door_ids' => $added->all(),
                    'removed_door_ids' => $removed->all(),
                    'notice_id' => $noticeRow?->id,
                    'starts_on' => $noticeRow !== null ? $startsOn : null,
                    'told' => $recipients->count(),
                    'loosens_safety' => false,
                    'reason' => null,
                ]);
            }
            if (isset($changes['window'])) {
                AuditLogger::logOrFail('security_devices.settings.changed', $config, [
                    'actor_id' => (int) $actor->id,
                    'key' => 'clockins.window',
                    'from' => $changes['window']['from'],
                    'to' => $changes['window']['to'],
                    'loosens_safety' => false,
                    'reason' => null,
                ]);
            }

            return ['notice' => $noticeRow];
        }, 3);

        /** @var DoorClockinNotice|null $noticeRow */
        $noticeRow = $result['notice'];
        if ($noticeRow !== null) {
            $this->sendNotice($noticeRow, $recipientSites, $now);
        }

        return [
            'added' => $added->count(),
            'removed' => $removed->count(),
            'notice_id' => $noticeRow?->id,
            'told' => $recipients->count(),
            'not_told' => $added->isEmpty() ? 0 : $this->unmatchedWithCards($addedSiteIds->all()),
            'starts_on' => $noticeRow !== null ? $startsOn : null,
        ];
    }

    /** Turn it off at the viewer's Sites: no new suggestions; waiting ones close; confirmed clock-ins stay. */
    public function turnOff(User $actor, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now('UTC');
        if (! DoorClockinRules::canChange($actor)) {
            throw new AuthorizationException('You can’t change clock-ins from doors.');
        }
        $siteIds = $this->access->accessibleSiteIds($actor);

        return DB::transaction(function () use ($actor, $siteIds, $now): int {
            $config = DoorClockinConfig::query()->whereKey($this->config()->id)->lockForUpdate()->firstOrFail();
            $rows = DoorClockinDoor::query()->whereIn('site_id', $siteIds)->lockForUpdate()->get();
            if ($rows->isEmpty()) {
                return 0;
            }
            DoorClockinDoor::query()->whereIn('id', $rows->pluck('id'))->delete();
            $this->closeSitesWithoutDoors($rows->pluck('site_id')->unique()->all(), $now);
            $config->forceFill(DoorClockinDoor::query()->exists()
                ? ['updated_by_user_id' => $actor->id] // still on at other Sites
                : ['turned_off_at' => $now, 'turned_off_by_user_id' => $actor->id, 'updated_by_user_id' => $actor->id])->save();
            AuditLogger::logOrFail('security_devices.settings.changed', $config, [
                'actor_id' => (int) $actor->id,
                'key' => 'clockins.on',
                'from' => $rows->count(),
                'to' => false,
                'removed_door_ids' => $rows->pluck('door_id')->all(),
                'loosens_safety' => false,
                'reason' => null,
            ]);

            return $rows->count();
        }, 3);
    }

    /**
     * Change the badge-time switch and who hears about gaps (the rows on 15n).
     *
     * @param  array<string, mixed>  $changes
     * @return list<string> the fields that changed
     */
    public function updateSettings(User $actor, array $changes): array
    {
        if (! DoorClockinRules::canChange($actor)) {
            throw new AuthorizationException('You can’t change clock-ins from doors.');
        }
        if (! DoorClockinRules::seesEverySite($actor)) {
            throw ValidationException::withMessages(['changes' => 'These settings apply to every Site, so only someone who looks after every Site changes them.']);
        }
        $fields = ['badge_time', 'gap_unconfirmed_app', 'gap_unconfirmed_email', 'gap_no_badge_app', 'gap_no_badge_email', 'gap_not_rostered_app', 'gap_not_rostered_email'];
        $minutes = ['before_minutes' => DoorClockinRules::BEFORE_OPTIONS, 'after_minutes' => DoorClockinRules::AFTER_OPTIONS];
        $errors = [];
        foreach ($changes as $key => $value) {
            if (array_key_exists((string) $key, $minutes)) {
                if (! is_int($value) || ! in_array($value, $minutes[$key], true)) {
                    $errors[(string) $key] = 'Choose one of the times listed.';
                }
            } elseif (! in_array($key, $fields, true)) {
                $errors[(string) $key] = 'This setting doesn’t exist.';
            } elseif (! is_bool($value)) {
                $errors[(string) $key] = 'Choose On or Off.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($actor, $changes, $minutes): array {
            $config = DoorClockinConfig::query()->whereKey($this->config()->id)->lockForUpdate()->firstOrFail();
            $changed = [];
            $window = "{$config->before_minutes}/{$config->after_minutes}";
            foreach ($changes as $key => $value) {
                if (array_key_exists((string) $key, $minutes)) {
                    if ((int) $config->getAttribute($key) !== $value) {
                        $config->setAttribute($key, $value);
                        $changed[] = $key;
                    }

                    continue;
                }
                $before = (bool) $config->getAttribute($key);
                if ($before === $value) {
                    continue;
                }
                $config->setAttribute($key, $value);
                $changed[] = $key;
                AuditLogger::logOrFail('security_devices.settings.changed', $config, [
                    'actor_id' => (int) $actor->id,
                    'key' => 'clockins.'.$key,
                    'from' => $before,
                    'to' => $value,
                    'loosens_safety' => false,
                    'reason' => null,
                ]);
            }
            if (array_intersect($changed, array_keys($minutes)) !== []) {
                AuditLogger::logOrFail('security_devices.settings.changed', $config, [
                    'actor_id' => (int) $actor->id,
                    'key' => 'clockins.window',
                    'from' => $window,
                    'to' => "{$config->before_minutes}/{$config->after_minutes}",
                    'loosens_safety' => false,
                    'reason' => null,
                ]);
            }
            if ($changed !== []) {
                $config->updated_by_user_id = $actor->id;
                $config->save();
            }

            return $changed;
        }, 3);
    }

    /**
     * Everyone matched to staff, with a card, PIN or phone at these Sites: who gets the notice.
     *
     * @param  list<int>  $siteIds
     * @return Collection<int, User>
     */
    public function recipients(array $siteIds): Collection
    {
        if ($siteIds === []) {
            return collect();
        }
        $userIds = AccessPerson::query()
            ->whereHas('console', fn ($query) => $query->whereIn('site_id', $siteIds))
            ->where('kind', 'staff')
            ->where('match_state', 'matched')
            ->whereIn('status', ['active', 'pending'])
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id');

        return User::query()->whereIn('id', $userIds)->whereNotNull('approved_at')->orderBy('id')->get();
    }

    /**
     * The same people, with the Sites (of these) where they have a card, PIN or phone: who is told
     * for which Site.
     *
     * @param  list<int>  $siteIds
     * @return array<int, list<int>> user id => Site ids
     */
    public function recipientSites(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }
        $approved = User::query()->whereNotNull('approved_at')->pluck('id')->flip();
        $map = [];
        AccessPerson::query()
            ->with('console:id,site_id')
            ->whereHas('console', fn ($query) => $query->whereIn('site_id', $siteIds))
            ->where('kind', 'staff')
            ->where('match_state', 'matched')
            ->whereIn('status', ['active', 'pending'])
            ->whereNotNull('user_id')
            ->get(['id', 'console_id', 'user_id'])
            ->each(function (AccessPerson $person) use (&$map, $approved): void {
                $userId = (int) $person->user_id;
                $siteId = (int) $person->console?->site_id;
                if ($siteId > 0 && $approved->has($userId)) {
                    $map[$userId] = array_values(array_unique([...($map[$userId] ?? []), $siteId]));
                }
            });
        ksort($map);

        return $map;
    }

    /**
     * Staff matched to a card at a counting Site after its notice went out haven't been told: they
     * get the notice now, word for word, with the day it starts for them (a week from today). Run
     * by the engine every minute; at most 100 people a run.
     *
     * @param  list<int>  $siteIds  Sites where a door counts
     */
    public function catchUpNotices(array $siteIds, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now('UTC');
        if ($siteIds === []) {
            return 0;
        }
        $notices = DoorClockinNotice::query()->orderByDesc('sent_at')->orderByDesc('id')->limit(200)->get();
        $already = DoorClockinTold::query()->whereIn('site_id', $siteIds)->get(['user_id', 'site_id'])
            ->mapWithKeys(fn (DoorClockinTold $row): array => [$row->user_id.':'.$row->site_id => true]);
        $told = 0;
        foreach ($this->recipientSites($siteIds) as $userId => $sites) {
            foreach ($sites as $siteId) {
                if ($told >= 100) {
                    return $told;
                }
                if ($already->has($userId.':'.$siteId)) {
                    continue;
                }
                $notice = $notices->first(fn (DoorClockinNotice $row): bool => in_array($siteId, array_map('intval', (array) $row->site_ids), true));
                if ($notice === null) {
                    continue;
                }
                $told += $this->sendNotice($notice, [$userId => [$siteId]], $now, late: true);
            }
        }

        return $told;
    }

    /** @param  list<int>  $siteIds  people at these Sites who can open doors but aren't matched to staff */
    private function unmatchedWithCards(array $siteIds): int
    {
        return AccessPerson::query()
            ->whereHas('console', fn ($query) => $query->whereIn('site_id', $siteIds))
            ->where('kind', '!=', 'resident')
            ->where('match_state', '!=', 'matched')
            ->whereIn('status', ['active', 'pending'])
            ->count();
    }

    /**
     * The notice to each person, one at a time, so one failure never stops the rest. The in-app copy
     * is what counts as told (recorded per Site); the email copy is queued. Someone not told here is
     * picked up by catchUpNotices on the next run, and counts a week after that.
     *
     * @param  array<int, list<int>>  $recipientSites  user id => Site ids
     * @return int people told
     */
    private function sendNotice(DoorClockinNotice $notice, array $recipientSites, CarbonImmutable $now, bool $late = false): int
    {
        if ($recipientSites === []) {
            return 0;
        }
        $starts = $notice->starts_on->format('Y-m-d');
        if ($late) {
            $own = DoorClockinRules::local($now)->startOfDay()->addDays(DoorClockinRules::NOTICE_DAYS)->toDateString();
            $starts = max($starts, $own);
        }
        $title = 'Clock-ins from doors start on '.DoorClockinRules::date($starts, $now);
        $body = $late
            ? 'You have a card, PIN or phone for a door where clock-ins from doors are on. For you, it starts on '.DoorClockinRules::date($starts, $now).".

".$notice->body
            : $notice->body;
        $users = User::query()->whereIn('id', array_keys($recipientSites))->get()->keyBy('id');
        $told = 0;
        foreach ($recipientSites as $userId => $sites) {
            $user = $users->get($userId);
            if ($user === null) {
                continue;
            }
            try {
                $user->notify(new DoorClockinNotification(kind: 'notice', title: $title, body: $body, url: '/my-day', channels: ['database']));
            } catch (Throwable $exception) {
                report($exception);
                Log::warning('A clock-ins from doors notice could not be shown in the app', ['notice_id' => $notice->id, 'user_id' => $userId]);

                continue;
            }
            foreach ($sites as $siteId) {
                DoorClockinTold::query()->firstOrCreate(
                    ['notice_id' => $notice->id, 'site_id' => $siteId, 'user_id' => $userId],
                    ['told_at' => $now, 'by_email' => $notice->by_email],
                );
            }
            $told++;
            if ($notice->by_email) {
                try {
                    $user->notify(new QueuedDoorClockinNotification(kind: 'notice', title: $title, body: $body, url: '/my-day', channels: ['mail']));
                } catch (Throwable $exception) {
                    report($exception); // the in-app copy stands; the email is extra
                }
            }
        }
        if (! $late && $told !== (int) $notice->recipients) {
            $notice->forceFill(['recipients' => $told])->save();
        }

        return $told;
    }

    /**
     * A Site with no door left that counts stops at once: its waiting suggestions close and its
     * rows stop being checked. Answered suggestions and clock-ins are untouched.
     *
     * @param  list<int|string>  $siteIds
     */
    private function closeSitesWithoutDoors(array $siteIds, CarbonImmutable $now): void
    {
        $siteIds = array_map('intval', $siteIds);
        $stillOn = DoorClockinDoor::query()->whereIn('site_id', $siteIds)->distinct()->pluck('site_id')->map(fn (mixed $id): int => (int) $id)->all();
        $off = array_values(array_diff($siteIds, $stillOn));
        if ($off === []) {
            return;
        }
        DoorClockin::query()
            ->whereIn('site_id', $off)
            ->whereNull('closed_at')
            ->whereNull('answer')
            ->update(['closed_at' => $now, 'close_reason' => 'turned_off', 'updated_at' => $now]);
    }
}
