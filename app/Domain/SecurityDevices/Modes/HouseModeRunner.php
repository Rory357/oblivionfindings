<?php

namespace App\Domain\SecurityDevices\Modes;

use App\Domain\SecurityDevices\Alerts\DeviceAlertEngine;
use App\Domain\SecurityDevices\Alerts\StaffOnShift;
use App\Domain\SecurityDevices\Automations\SafeActions;
use App\Domain\SecurityDevices\Cctv\Services\CameraPrivacy;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Modes\Models\HouseMode;
use App\Domain\SecurityDevices\Modes\Models\HouseModeEvent;
use App\Domain\SecurityDevices\Screens\Devices\Pages\AlarmPagePresenter;
use App\Domain\SecurityDevices\Screens\Devices\Pages\DoorPagePresenter;
use App\Models\Site;
use App\Models\User;
use App\Notifications\HouseModeNotification;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Throwable;

/**
 * The one way a house mode switches and runs its actions (S&D Phase F, D12), by schedule or by hand.
 *
 * Every action goes through SafeActions::assertAllowed() first; anything else is skipped and said so
 * in the log. Nothing here sends a device command, ever: "Check doors are locked" reads each lock and
 * tells the staff on shift about any that isn't locked; "Check the alarm is armed" reads the panel's
 * areas and reminds them; camera privacy only ever goes on. Every switch is in the audit log.
 */
final class HouseModeRunner
{
    public function __construct(
        private readonly HouseModeDevices $devices,
        private readonly CameraPrivacy $privacy,
        private readonly StaffOnShift $staff,
        private readonly DeviceAlertEngine $alerts,
    ) {}

    /**
     * Switch the Site to this mode and run its actions. Returns null when nothing switched: by
     * schedule, the Site already switched since that start (or is already in the mode).
     */
    public function switchTo(HouseMode $mode, string $source, ?User $actor, CarbonImmutable $at, ?CarbonImmutable $scheduledStart = null): ?HouseModeEvent
    {
        $siteId = (int) $mode->site_id;
        $event = DB::transaction(function () use ($mode, $source, $actor, $at, $scheduledStart, $siteId): ?HouseModeEvent {
            // One switch at a time per Site; read the current mode only after the lock.
            Site::query()->whereKey($siteId)->lockForUpdate()->first(['id']);
            $current = self::currentSwitch($siteId);
            if ($scheduledStart !== null) {
                $since = HouseModeEvent::query()->where('site_id', $siteId)->where('kind', HouseModeEvent::KIND_SWITCH)
                    ->done()->where('at', '>=', $scheduledStart)->exists();
                if ($since || $current?->mode_id === (int) $mode->id) {
                    return null;
                }
            } elseif ($current?->mode_id === (int) $mode->id) {
                abort(409, 'The house is already in '.$mode->name.'.');
            }
            // A switch replaces any alarm check still waiting from the last one.
            HouseModeEvent::query()->where('site_id', $siteId)->where('state', HouseModeEvent::STATE_PENDING)
                ->update(['state' => HouseModeEvent::STATE_CANCELLED, 'updated_at' => now()]);
            $from = $current?->mode?->name;

            return HouseModeEvent::query()->create([
                'site_id' => $siteId,
                'mode_id' => $mode->id,
                'kind' => HouseModeEvent::KIND_SWITCH,
                'state' => HouseModeEvent::STATE_DONE,
                'source' => $source,
                'title' => mb_substr($from !== null ? $from.' → '.$mode->name : $mode->name, 0, 191),
                'actor_user_id' => $actor?->id,
                'lines' => [],
                'at' => $at,
                'payload' => ['from_mode_id' => $current?->mode_id],
            ]);
        }, 3);

        if ($event === null) {
            return null;
        }

        $lines = $this->run($mode, $event, $at);
        $event->forceFill(['lines' => $lines])->save();

        AuditLogger::logOrFail('security_devices.house_mode.switched', $event, [
            'actor_id' => $actor?->id,
            'site_id' => $siteId,
            'mode_id' => (int) $mode->id,
            'from_mode_id' => $event->payload['from_mode_id'] ?? null,
            'source' => $source,
            'actions' => array_values(array_map(fn (array $action): string => (string) ($action['action'] ?? ''), $mode->actionList())),
            'findings' => array_column($lines, 'text'),
        ], systemActor: $actor === null);

        return $event;
    }

    /** The Site's latest switch, with its mode. */
    public static function currentSwitch(int $siteId): ?HouseModeEvent
    {
        return HouseModeEvent::query()->with('mode')->where('site_id', $siteId)->where('kind', HouseModeEvent::KIND_SWITCH)
            ->done()->orderByDesc('at')->orderByDesc('id')->first();
    }

    /**
     * Run a waiting alarm check ("armed by 9:15 pm") once it's due.
     */
    public function runPendingCheck(HouseModeEvent $pending, CarbonImmutable $now): void
    {
        $claimed = HouseModeEvent::query()->whereKey($pending->id)->where('state', HouseModeEvent::STATE_PENDING)
            ->update(['state' => HouseModeEvent::STATE_DONE, 'at' => $now, 'updated_at' => now()]);
        if ($claimed === 0) {
            return;
        }
        $payload = (array) $pending->payload;
        $site = Site::query()->find($pending->site_id, ['id', 'name']);
        try {
            SafeActions::assertAllowed('alarm_armed_check');
            [$passed, $lines] = $this->alarmCheck((int) $pending->site_id, (string) ($site?->name ?? 'The house'), (string) ($payload['mode'] ?? 'the mode'), array_map('intval', (array) ($payload['panels'] ?? [])), (bool) ($payload['open_alert'] ?? false), $now);
            $title = $passed ? 'Alarm armed check passed' : 'Alarm not armed at '.HouseModeWords::at($now);
        } catch (Throwable $exception) {
            Log::error('House mode alarm check could not run', ['event_id' => $pending->id, 'error' => $exception->getMessage()]);
            [$title, $lines] = ['Alarm check couldn’t run', [['tone' => 'warning', 'text' => 'Check the alarm by hand']]];
        }
        HouseModeEvent::query()->whereKey($pending->id)->update(['title' => mb_substr($title, 0, 191), 'lines' => json_encode($lines, JSON_UNESCAPED_UNICODE)]);
    }

    /**
     * Each action, safe ones only, in a fixed order. One failing never stops the rest.
     *
     * @return list<array{tone: string, text: string}>
     */
    private function run(HouseMode $mode, HouseModeEvent $event, CarbonImmutable $at): array
    {
        $siteId = (int) $mode->site_id;
        $siteName = (string) (Site::query()->whereKey($siteId)->value('name') ?? 'The house');
        $openAlert = $mode->action('open_alert') !== null;
        $actions = $mode->actionList();
        usort($actions, fn (array $a, array $b): int => self::rank($a) <=> self::rank($b));

        $lines = [];
        foreach ($actions as $action) {
            $key = is_string($action['action'] ?? null) ? $action['action'] : '';
            try {
                SafeActions::assertAllowed($key);
            } catch (InvalidArgumentException) {
                // Never stored through the form; refused here too, whatever put it there.
                Log::warning('House mode skipped an action modes may not take', ['mode_id' => $mode->id, 'action' => mb_substr($key, 0, 64)]);
                $lines[] = ['tone' => 'warning', 'text' => 'Skipped an action modes may not take'];

                continue;
            }
            try {
                array_push($lines, ...match ($key) {
                    'camera_privacy_on' => $this->cameraPrivacy($siteId, $action, $event, $at),
                    'door_locked_check' => $this->doorCheck($siteId, $siteName, $mode->name, array_map('intval', (array) ($action['doors'] ?? [])), $openAlert, $at),
                    'alarm_armed_check' => $this->alarmStep($siteId, $siteName, $mode->name, $action, $openAlert, $at),
                    'notify' => $this->notify($siteId, $siteName, $mode->name, $action['message'] ?? null),
                    default => [],
                });
            } catch (Throwable $exception) {
                Log::error('House mode action could not run', ['mode_id' => $mode->id, 'action' => $key, 'error' => $exception->getMessage()]);
                $lines[] = ['tone' => 'warning', 'text' => SafeActions::ACTIONS[$key]['label'].' couldn’t run'];
            }
        }

        return $lines;
    }

    /** @param array<string, mixed> $action */
    private static function rank(array $action): int
    {
        $index = array_search($action['action'] ?? null, HouseModeActions::ORDER, true);

        return $index === false ? 99 : (int) $index;
    }

    /**
     * @param  array<string, mixed>  $action
     * @return list<array{tone: string, text: string}>
     */
    private function cameraPrivacy(int $siteId, array $action, HouseModeEvent $event, CarbonImmutable $at): array
    {
        $ids = array_map('intval', (array) ($action['cameras'] ?? []));
        $cameras = $this->devices->stillAt($siteId, 'cameras', $ids);
        $until = self::nextClock((string) ($action['until'] ?? ''), $at);
        if ($until === null) {
            return [['tone' => 'warning', 'text' => 'Camera privacy wasn’t turned on: its end time isn’t readable']];
        }
        foreach ($cameras as $camera) {
            $this->privacy->holdUntil((int) $camera->id, $until, (int) $event->id);
        }
        $lines = [];
        if ($cameras !== []) {
            $lines[] = ['tone' => 'info', 'text' => 'Camera privacy on until '.HouseModeWords::at($until).': '.HouseModeWords::list(array_map(fn (Device $device): string => (string) $device->name, array_values($cameras)))];
        }
        $gone = count($ids) - count($cameras);
        if ($gone > 0) {
            $lines[] = ['tone' => 'warning', 'text' => $gone.' '.($gone === 1 ? 'camera is' : 'cameras are').' no longer at this Site, so nothing changed for '.($gone === 1 ? 'it' : 'them')];
        }

        return $lines;
    }

    /**
     * Read each door's lock. Any not locked (or that hasn't reported its lock) goes to the staff on
     * shift to lock by hand. No command is ever sent.
     *
     * @param  list<int>  $ids
     * @return list<array{tone: string, text: string}>
     */
    private function doorCheck(int $siteId, string $siteName, string $modeName, array $ids, bool $openAlert, CarbonImmutable $at): array
    {
        $doors = $this->devices->stillAt($siteId, 'doors', $ids);
        $reader = app(DoorPagePresenter::class);
        $locked = [];
        $open = [];
        $unknown = [];
        foreach ($doors as $door) {
            $lock = $reader->lockNow($door);
            match ($lock['locked']) {
                true => $locked[] = (string) $door->name,
                false => $open[] = $door,
                null => $unknown[] = $door,
            };
        }

        $lines = [];
        if ($locked !== []) {
            $lines[] = ['tone' => 'success', 'text' => HouseModeWords::list($locked).' locked'];
        }
        $problems = [...$open, ...$unknown];
        if ($problems !== []) {
            $told = $this->report($siteId, $problems, $openAlert, 'mode_door_check', $at,
                fn (Device $door): string => in_array($door, $open, true) ? (string) $door->name.': not locked for '.$modeName : (string) $door->name.': lock not reported for '.$modeName,
                fn (Device $door): string => in_array($door, $open, true) ? 'Not locked when '.$modeName.' started' : 'Its lock hasn’t reported, so it may not be locked',
                $siteName.': '.HouseModeWords::list(array_map(fn (Device $door): string => (string) $door->name, $problems)).(count($problems) === 1 ? ' isn’t' : ' aren’t').' locked',
                'Lock '.(count($problems) === 1 ? 'it' : 'them').' by hand. Oblivion never locks or unlocks a door for a house mode.',
            );
            if ($open !== []) {
                $lines[] = ['tone' => 'warning', 'text' => HouseModeWords::list(array_map(fn (Device $door): string => (string) $door->name, $open)).' not locked · '.$told];
            }
            if ($unknown !== []) {
                $lines[] = ['tone' => 'warning', 'text' => HouseModeWords::list(array_map(fn (Device $door): string => (string) $door->name, $unknown)).': lock not reported · '.$told];
            }
        }
        $gone = count($ids) - count($doors);
        if ($gone > 0) {
            $lines[] = ['tone' => 'warning', 'text' => $gone.' '.($gone === 1 ? 'door is' : 'doors are').' no longer at this Site, so '.($gone === 1 ? 'it wasn’t' : 'they weren’t').' checked'];
        }

        return $lines;
    }

    /**
     * Check the alarm now, or leave a check waiting until "N min after the switch".
     *
     * @param  array<string, mixed>  $action
     * @return list<array{tone: string, text: string}>
     */
    private function alarmStep(int $siteId, string $siteName, string $modeName, array $action, bool $openAlert, CarbonImmutable $at): array
    {
        $panels = array_map('intval', (array) ($action['panels'] ?? []));
        $minutes = (int) ($action['after_minutes'] ?? 0);
        if ($minutes > 0) {
            $due = $at->addMinutes($minutes);
            HouseModeEvent::query()->create([
                'site_id' => $siteId,
                'mode_id' => null,
                'kind' => HouseModeEvent::KIND_CHECK,
                'state' => HouseModeEvent::STATE_PENDING,
                'source' => 'schedule',
                'title' => 'Alarm armed check',
                'lines' => [],
                'payload' => ['panels' => $panels, 'open_alert' => $openAlert, 'mode' => $modeName],
                'at' => $due,
                'due_at' => $due,
            ]);

            return [['tone' => 'info', 'text' => 'Alarm check at '.HouseModeWords::at($due)]];
        }

        return $this->alarmCheck($siteId, $siteName, $modeName, $panels, $openAlert, $at)[1];
    }

    /**
     * Read each panel's areas. Any area not armed (or not reporting) reminds the staff on shift to arm
     * it by hand. Oblivion never arms or disarms for a mode.
     *
     * @param  list<int>  $ids
     * @return array{0: bool, 1: list<array{tone: string, text: string}>}
     */
    private function alarmCheck(int $siteId, string $siteName, string $modeName, array $ids, bool $openAlert, CarbonImmutable $at): array
    {
        $panels = $this->devices->stillAt($siteId, 'panels', $ids);
        $reader = app(AlarmPagePresenter::class);
        $lines = [];
        $problems = [];
        $notArmed = [];
        foreach ($panels as $panel) {
            $areas = $reader->areasNow($panel);
            $off = array_values(array_filter($areas, fn (array $area): bool => $area['armed'] !== true));
            if ($areas === [] || $off !== []) {
                $problems[] = $panel;
                $notArmed[(int) $panel->id] = $areas === [] ? ['the alarm'] : array_map(fn (array $area): string => $area['name'], $off);

                continue;
            }
            $since = collect($areas)->pluck('since')->filter()->max();
            $lines[] = ['tone' => 'success', 'text' => HouseModeWords::list(array_map(fn (array $area): string => $area['name'], $areas)).' armed'
                .(is_string($since) ? ' at '.HouseModeWords::at(CarbonImmutable::parse($since)) : '').' (from the panel’s report)'];
        }
        if ($problems !== []) {
            $what = HouseModeWords::list(array_merge(...array_values($notArmed)));
            $told = $this->report($siteId, $problems, $openAlert, 'mode_alarm_check', $at,
                fn (Device $panel): string => (string) $panel->name.': not armed for '.$modeName,
                fn (Device $panel): string => HouseModeWords::list($notArmed[(int) $panel->id]).' not armed, or not reported, when '.$modeName.' checked',
                $siteName.': '.$what.' isn’t armed',
                'Arm it at the keypad. Oblivion never arms or disarms the alarm for a house mode.',
            );
            $lines[] = ['tone' => 'warning', 'text' => ucfirst($what).' not armed · '.str_replace('told', 'reminded', $told)];
        }
        if (count($panels) < count($ids)) {
            $lines[] = ['tone' => 'warning', 'text' => 'The alarm panel is no longer at this Site, so it wasn’t checked'];
        }

        return [$problems === [] && $panels !== [], $lines];
    }

    /**
     * Tell the staff on shift about what a check found: an alert on each device when the mode opens
     * alerts, otherwise one message in the app. Returns the words for the log ("staff on shift told").
     *
     * @param  list<Device>  $devices
     * @param  callable(Device): string  $alertTitle
     * @param  callable(Device): string  $alertWhat
     */
    private function report(int $siteId, array $devices, bool $openAlert, string $kind, CarbonImmutable $at, callable $alertTitle, callable $alertWhat, string $title, string $line): string
    {
        if ($openAlert) {
            foreach ($devices as $device) {
                $this->alerts->openModeCheck($device, $kind, $alertTitle($device), $at, $alertWhat($device), null);
            }

            return 'alert opened for staff on shift';
        }
        $people = $this->staff->toTell($siteId, $at);
        if ($people->isEmpty()) {
            return 'nobody on shift to tell';
        }
        try {
            Notification::send($people, new HouseModeNotification($siteId, $title, $line, 'warning'));
        } catch (Throwable $exception) {
            Log::warning('House mode message could not be sent', ['site_id' => $siteId, 'error' => $exception->getMessage()]);

            return 'staff on shift couldn’t be told';
        }

        return 'staff on shift told';
    }

    /**
     * @return list<array{tone: string, text: string}>
     */
    private function notify(int $siteId, string $siteName, string $modeName, mixed $message): array
    {
        $people = $this->staff->toTell($siteId);
        if ($people->isEmpty()) {
            return [['tone' => 'warning', 'text' => 'Nobody on shift to tell']];
        }
        $line = is_string($message) && trim($message) !== '' ? trim($message) : 'The house mode changed.';
        Notification::send($people, new HouseModeNotification($siteId, $siteName.' is in '.$modeName.' now', $line));

        return [['tone' => 'info', 'text' => 'Staff on shift told']];
    }

    /** The next time the NZ wall clock reads "HH:MM" after `$at`. */
    private static function nextClock(string $hhmm, CarbonImmutable $at): ?CarbonImmutable
    {
        if (preg_match('/\A([01]\d|2[0-3]):[0-5]\d\z/', $hhmm) !== 1) {
            return null;
        }
        $local = $at->setTimezone(HouseModeSchedule::ZONE);
        $candidate = CarbonImmutable::parse($local->format('Y-m-d').' '.$hhmm, HouseModeSchedule::ZONE);
        if ($candidate->lte($at)) {
            $candidate = CarbonImmutable::parse($local->addDay()->format('Y-m-d').' '.$hhmm, HouseModeSchedule::ZONE);
        }

        return $candidate->utc();
    }
}
