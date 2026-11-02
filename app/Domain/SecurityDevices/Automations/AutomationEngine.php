<?php

namespace App\Domain\SecurityDevices\Automations;

use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\SecurityDevices\Alerts\DeviceAlertEngine;
use App\Domain\SecurityDevices\Alerts\StaffOnShift;
use App\Domain\SecurityDevices\Automations\Models\Automation;
use App\Domain\SecurityDevices\Automations\Models\AutomationRun;
use App\Domain\SecurityDevices\Cctv\Models\CameraPrivacyOverride;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Modes\HouseModeRunner;
use App\Domain\SecurityDevices\Modes\Models\HouseMode;
use App\Domain\SecurityDevices\Screens\Devices\Pages\AlarmPagePresenter;
use App\Domain\SecurityDevices\Screens\Devices\Pages\DoorPagePresenter;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\SecurityDeviceAutomationNotification;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Throwable;

/**
 * The one way an automation starts and runs its actions (S&D Phase F, D12).
 *
 * - Triggers: a door opening (SafetyEventObserver), a sensor reading crossing a value
 *   (SensorReadingHistory), a device going offline or coming back (DeviceAlertEngine::onCheck),
 *   and a time of day (the every-minute tick). Only automations at the device's own Site run.
 * - Every action goes through SafeActions::assertAllowed() first; anything else is skipped and said
 *   so in the run. Nothing here sends a device command, ever: the checks read a lock or the alarm's
 *   areas and tell the staff on shift; camera privacy only ever goes on.
 * - A test (pop-up 41) runs once now with made-up trigger data: only the person testing gets a
 *   message, marked "TEST". Nobody else is told, no alert opens and no camera changes; the checks
 *   only read. Each line says what a real run would have done.
 * - Every run, real or test, goes in the run log and the audit log. Nothing here ever stops the
 *   reading, door event or check that started it.
 */
final class AutomationEngine
{
    /** After a run for a device, the same automation waits this long before running for it again. */
    public const int PAUSE_SECONDS = 120;

    /** A time-of-day start missed by more than this (the scheduler was down) is skipped, not run late. */
    public const int GRACE_MINUTES = 10;

    private const array REACHABILITY = [MonitorKind::Icmp, MonitorKind::Tcp, MonitorKind::Collector];

    /** @var array<string, string>|null role name => label, once per engine */
    private ?array $roleLabels = null;

    public function __construct(
        private readonly AutomationDevices $devices,
        private readonly StaffOnShift $staff,
        private readonly SecurityDevicesAccessService $access,
        private readonly DeviceAlertEngine $alerts,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Triggers                                                           */
    /* ------------------------------------------------------------------ */

    /** A door (or door contact) reported opening. */
    public function onDoorOpened(Device $door, CarbonImmutable $at): void
    {
        try {
            $this->fire('door_opened', $door, $at, (string) $door->name.' opened at '.AutomationWords::at($at));
        } catch (Throwable $exception) {
            Log::error('Door automations could not run', ['device_id' => $door->id, 'error' => $exception->getMessage()]);
        }
    }

    /**
     * Readings were stored for a sensor. An automation runs when a reading crosses its value (from
     * under to over, for "above"), not on every reading over it.
     *
     * @param  array<string, float|int>  $values  metric => value
     */
    public function onReading(int $deviceId, array $values, CarbonImmutable $at): void
    {
        try {
            $watched = array_intersect_key($values, AutomationDefinition::METRICS);
            if ($watched === []) {
                return;
            }
            $candidates = Automation::query()->running()->where('trigger_kind', 'reading')->get();
            if ($candidates->isEmpty()) {
                return;
            }
            $device = Device::query()->find($deviceId);
            $siteId = $device instanceof Device ? $this->devices->siteOf($device) : null;
            if ($siteId === null) {
                return;
            }
            foreach ($candidates as $automation) {
                $metric = (string) ($automation->trigger['metric'] ?? '');
                if ($automation->site_id !== $siteId || ! is_numeric($watched[$metric] ?? null)
                    || ($automation->triggerDeviceId() !== null && $automation->triggerDeviceId() !== $deviceId)) {
                    continue;
                }
                $value = (float) $watched[$metric];
                $limit = (float) ($automation->trigger['value'] ?? 0);
                $over = ($automation->trigger['direction'] ?? 'above') === 'below' ? $value < $limit : $value > $limit;
                $claimed = $this->claim($automation, $deviceId, $at, function (array $state) use ($deviceId, $over, $at, $automation): array {
                    $before = $state['sides'][(string) $deviceId] ?? null;
                    $state['sides'][(string) $deviceId] = $over ? 'over' : 'under';

                    return [$state, $over && $before !== 'over' && $this->within($automation, $at)];
                });
                if ($claimed !== null) {
                    $label = AutomationDefinition::METRICS[$metric]['label'] ?? 'Reading';
                    $this->run($claimed, [
                        'source' => 'reading',
                        'device' => $device,
                        'at' => $at,
                        'summary' => (string) $device->name.': '.($metric === 'co2' || $metric === 'pm25' ? $label : mb_strtolower($label)).' '.AutomationWords::value($metric, $value).' at '.AutomationWords::at($at),
                    ]);
                }
            }
        } catch (Throwable $exception) {
            Log::error('Reading automations could not run', ['device_id' => $deviceId, 'error' => $exception->getMessage()]);
        }
    }

    /**
     * A check's state changed. A device goes offline when its online check (ping, port or hub) fails
     * and no other online check was failing already; it's back when the last failing one passes.
     */
    public function onCheck(Device $device, Monitor $monitor, MonitorState $from, MonitorState $to, CarbonImmutable $at): void
    {
        try {
            $kind = $monitor->kind instanceof MonitorKind ? $monitor->kind : MonitorKind::tryFrom((string) $monitor->kind);
            if (! in_array($kind, self::REACHABILITY, true)) {
                return;
            }
            $offline = $to === MonitorState::Failed && $from !== MonitorState::Failed;
            $back = $to === MonitorState::Healthy && $from === MonitorState::Failed;
            if (! $offline && ! $back) {
                return;
            }
            $trigger = $offline ? 'device_offline' : 'device_back';
            if (! Automation::query()->running()->where('trigger_kind', $trigger)->exists()) {
                return;
            }
            $othersDown = Monitor::query()->where('device_id', $device->id)->whereKeyNot($monitor->id)->where('is_enabled', true)
                ->whereIn('kind', array_map(fn (MonitorKind $reach): string => $reach->value, self::REACHABILITY))
                ->where('effective_state', MonitorState::Failed->value)
                ->exists();
            if ($othersDown) {
                return;
            }
            $this->fire($trigger, $device, $at, (string) $device->name.($offline ? ' went offline at ' : ' is back online at ').AutomationWords::at($at));
        } catch (Throwable $exception) {
            Log::error('Device automations could not run', ['device_id' => $device->id, 'error' => $exception->getMessage()]);
        }
    }

    /**
     * Every minute: run the time-of-day automations whose start has come, once per start, and keep
     * the run log to its length. Returns how many ran.
     */
    public function tick(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now('UTC');
        $ran = 0;
        Automation::query()->running()->where('trigger_kind', 'time')->orderBy('id')->get()
            ->each(function (Automation $automation) use ($now, &$ran): void {
                try {
                    $start = self::lastStart((array) $automation->trigger, $now);
                    if ($start === null || $start->lt($now->subMinutes(self::GRACE_MINUTES)) || ! $this->within($automation, $start)) {
                        return;
                    }
                    $key = $start->toIso8601String();
                    $claimed = $this->claim($automation, null, $now, function (array $state) use ($key): array {
                        if (($state['time_for'] ?? null) === $key) {
                            return [$state, false];
                        }
                        $state['time_for'] = $key;

                        return [$state, true];
                    }, pause: false);
                    if ($claimed !== null) {
                        $this->run($claimed, ['source' => 'time', 'device' => null, 'at' => $now, 'summary' => 'It’s '.AutomationWords::at($start)]);
                        $ran++;
                    }
                } catch (Throwable $exception) {
                    Log::error('Time automation could not run', ['automation_id' => $automation->id, 'error' => $exception->getMessage()]);
                }
            });

        try {
            AutomationRun::query()->where('at', '<', $now->subDays(AutomationRun::KEEP_DAYS))->limit(1000)->delete();
        } catch (Throwable $exception) {
            Log::warning('Old automation runs could not be pruned', ['error' => $exception->getMessage()]);
        }

        return $ran;
    }

    /**
     * Pop-up 41: run once now with made-up trigger data. Only the tester is told, marked "TEST".
     */
    public function test(Automation $automation, User $tester, ?CarbonImmutable $now = null): AutomationRun
    {
        $now ??= CarbonImmutable::now('UTC');
        $trigger = (array) $automation->trigger;
        $device = $this->sampleDevice($automation, $tester);
        $name = $device instanceof Device ? (string) $device->name : null;
        $summary = match ((string) ($trigger['kind'] ?? '')) {
            'door_opened' => ($name ?? 'A door').' opened at '.AutomationWords::at($now),
            'reading' => $this->testReading($trigger, $name, $now),
            'device_offline' => ($name ?? 'A device').' went offline at '.AutomationWords::at($now),
            'device_back' => ($name ?? 'A device').' is back online at '.AutomationWords::at($now),
            'time' => 'It’s '.AutomationWords::clock((string) ($trigger['at'] ?? '')),
            default => 'A test',
        };

        return $this->run($automation, ['source' => (string) ($trigger['kind'] ?? 'test'), 'device' => $device, 'at' => $now, 'summary' => $summary], $tester);
    }

    /* ------------------------------------------------------------------ */
    /*  Running                                                            */
    /* ------------------------------------------------------------------ */

    /** Run every automation at the device's Site that this trigger starts. */
    private function fire(string $kind, Device $device, CarbonImmutable $at, string $summary): void
    {
        $candidates = Automation::query()->running()->where('trigger_kind', $kind)->get();
        if ($candidates->isEmpty()) {
            return;
        }
        $siteId = $this->devices->siteOf($device);
        if ($siteId === null) {
            return;
        }
        foreach ($candidates as $automation) {
            if ($automation->site_id !== $siteId || ($automation->triggerDeviceId() !== null && $automation->triggerDeviceId() !== (int) $device->id)
                || ! $this->within($automation, $at)) {
                continue;
            }
            $claimed = $this->claim($automation, (int) $device->id, $at, fn (array $state): array => [$state, true]);
            if ($claimed !== null) {
                $this->run($claimed, ['source' => $kind, 'device' => $device, 'at' => $at, 'summary' => $summary]);
            }
        }
    }

    /**
     * Lock the automation, check it still runs, and let `$decide` update its state and say whether to
     * run now. A run for the same device waits PAUSE_SECONDS. Returns the fresh automation to run,
     * or null.
     *
     * @param  callable(array<string, mixed>): array{0: array<string, mixed>, 1: bool}  $decide
     */
    private function claim(Automation $automation, ?int $deviceId, CarbonImmutable $at, callable $decide, bool $pause = true): ?Automation
    {
        return DB::transaction(function () use ($automation, $deviceId, $at, $decide, $pause): ?Automation {
            $locked = Automation::query()->running()->whereKey($automation->id)->lockForUpdate()->first();
            if (! $locked instanceof Automation) {
                return null;
            }
            [$state, $run] = $decide((array) ($locked->state ?? []));
            $last = $deviceId !== null ? ($state['last'][(string) $deviceId] ?? null) : null;
            if ($run && $pause && is_string($last) && CarbonImmutable::parse($last)->gt($at->subSeconds(self::PAUSE_SECONDS))) {
                $run = false;
            }
            if ($run && $deviceId !== null) {
                $state['last'][(string) $deviceId] = $at->toIso8601String();
            }
            // Loose and order-free: MySQL JSON hands keys back in its own order.
            if ($state != (array) ($locked->state ?? [])) {
                $locked->forceFill(['state' => $state])->save();
            }

            return $run ? $locked : null;
        }, 3);
    }

    /**
     * Each action, safe ones only, in the order they were added. One failing never stops the rest.
     *
     * @param  array{source: string, device: ?Device, at: CarbonImmutable, summary: string}  $context
     */
    private function run(Automation $automation, array $context, ?User $tester = null): AutomationRun
    {
        $test = $tester !== null;
        $siteName = (string) (Site::query()->whereKey($automation->site_id)->value('name') ?? 'The house');
        $context['site'] = $siteName;
        $lines = [];

        if ($test) {
            try {
                $tester->notify(new SecurityDeviceAutomationNotification((int) $automation->id, (int) $automation->site_id,
                    'TEST · '.$automation->name, 'TEST · '.$context['summary'].'. Only you got this: nobody else was told and nothing changed.', ['database'], 'info', true));
                $lines[] = self::line('test', 'success', 'You got “TEST · '.$automation->name.'” in the app');
            } catch (Throwable $exception) {
                Log::warning('Automation test message could not be sent', ['automation_id' => $automation->id, 'error' => $exception->getMessage()]);
                $lines[] = self::line('test', 'critical', 'Your test message couldn’t be sent');
            }
            $whyNot = $this->whyNot($automation, $context['at']);
            if ($whyNot !== null) {
                $lines[] = self::line('test', 'info', $whyNot.', so a real one wouldn’t run now');
            }
        }

        $failed = 0;
        $actions = $automation->actionList();
        foreach ($actions as $action) {
            $key = is_string($action['action'] ?? null) ? $action['action'] : '';
            try {
                SafeActions::assertAllowed($key);
            } catch (InvalidArgumentException) {
                // Never stored through the form; refused here too, whatever put it there.
                Log::warning('Automation skipped an action automations may not take', ['automation_id' => $automation->id, 'action' => mb_substr($key, 0, 64)]);
                $lines[] = self::line('skipped', 'critical', 'Skipped an action automations may not take');
                $failed++;

                continue;
            }
            try {
                array_push($lines, ...match ($key) {
                    'notify' => $this->notify($automation, $action, $context, $test),
                    'open_alert' => $this->openAlert($automation, $context, $test),
                    'camera_privacy_on' => $this->cameraPrivacy($automation, $action, $context, $test),
                    'door_locked_check' => $this->doorCheck($automation, $action, $context, $test),
                    'alarm_armed_check' => $this->alarmCheck($automation, $action, $context, $test),
                    default => [],
                });
            } catch (Throwable $exception) {
                Log::error('Automation action could not run', ['automation_id' => $automation->id, 'action' => $key, 'error' => $exception->getMessage()]);
                $lines[] = self::line($key, 'critical', SafeActions::ACTIONS[$key]['label'].' couldn’t run');
                $failed++;
            }
        }

        $outcome = match (true) {
            $actions === [] || $failed === 0 => AutomationRun::OK,
            $failed >= count($actions) => AutomationRun::FAILED,
            default => AutomationRun::PARTIAL,
        };
        $device = $context['device'];

        return DB::transaction(function () use ($automation, $context, $tester, $test, $lines, $outcome, $device, $actions): AutomationRun {
            $run = AutomationRun::query()->create([
                'automation_id' => $automation->id,
                'site_id' => $automation->site_id,
                'test' => $test,
                'source' => $test ? 'test' : $context['source'],
                'actor_user_id' => $tester?->id,
                'device_id' => $test ? null : $device?->id,
                'outcome' => $outcome,
                'summary' => mb_substr($context['summary'], 0, 191),
                'lines' => $lines,
                'at' => $context['at'],
            ]);
            Automation::query()->whereKey($automation->id)->update([
                'last_run_at' => $context['at'],
                'last_run_test' => $test,
                'last_run_outcome' => $outcome,
            ]);
            AuditLogger::logOrFail($test ? 'security_devices.automation.tested' : 'security_devices.automation.ran', $run, [
                'actor_id' => $tester?->id,
                'automation_id' => (int) $automation->id,
                'site_id' => (int) $automation->site_id,
                'source' => $context['source'],
                'device_id' => $device?->id,
                'outcome' => $outcome,
                'actions' => array_values(array_map(fn (array $action): string => (string) ($action['action'] ?? ''), $actions)),
                'findings' => array_column($lines, 'text'),
            ], systemActor: ! $test);

            return $run;
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $context
     * @return list<array{action: string, tone: string, text: string, at: string}>
     */
    private function notify(Automation $automation, array $action, array $context, bool $test): array
    {
        $people = $this->recipients($automation, $action, $context['at']);
        $who = AutomationWords::who($action, $this->roleLabels());
        $how = AutomationWords::channels($action);
        if ($test) {
            return [self::line('notify', 'info', $people->isEmpty()
                ? "A real run would tell {$who} ({$how}), but nobody is there to tell right now"
                : "A real run would tell {$who}: ".self::names($people)." ({$how}). Only you were told, marked “TEST”")];
        }
        if ($people->isEmpty()) {
            return [self::line('notify', 'warning', 'Nobody to tell: '.$who.' found no one')];
        }
        $message = is_string($action['message'] ?? null) && trim($action['message']) !== '' ? trim($action['message']) : null;
        $channels = ['database', ...(! empty($action['push']) ? ['push'] : []), ...(! empty($action['email']) ? ['mail'] : [])];
        Notification::send($people, new SecurityDeviceAutomationNotification((int) $automation->id, (int) $automation->site_id,
            $automation->name, $context['summary'].($message !== null ? '. '.$message : ''), $channels, 'warning'));

        return [self::line('notify', 'success', 'Told '.self::names($people)." ({$how})")];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<array{action: string, tone: string, text: string, at: string}>
     */
    private function openAlert(Automation $automation, array $context, bool $test): array
    {
        $device = $context['device'];
        if (! $device instanceof Device) {
            return [self::line('open_alert', $test ? 'info' : 'warning', $test
                ? 'A real run would open an alert on the device that started it, for the staff on shift. No alert was opened'
                : 'No alert opened: nothing with a device started it')];
        }
        if ($test) {
            return [self::line('open_alert', 'info', 'A real run would open an alert on '.$device->name.' for the staff on shift. No alert was opened')];
        }
        $created = $this->alerts->openAutomation($device, (string) $device->name.': '.$automation->name, $context['at'], $context['summary'], 'From the automation “'.$automation->name.'”');

        return [self::line('open_alert', 'success', $created !== null
            ? 'Opened an alert on '.$device->name.' for the staff on shift'
            : 'The alert already open on '.$device->name.' was updated')];
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $context
     * @return list<array{action: string, tone: string, text: string, at: string}>
     */
    private function cameraPrivacy(Automation $automation, array $action, array $context, bool $test): array
    {
        $ids = array_map('intval', (array) ($action['cameras'] ?? []));
        $cameras = $this->devices->stillAt((int) $automation->site_id, 'cameras', $ids);
        $until = self::nextClock((string) ($action['until'] ?? ''), $context['at']);
        if ($until === null) {
            return [self::line('camera_privacy_on', 'critical', 'Camera privacy wasn’t turned on: its end time isn’t readable')];
        }
        $names = AutomationWords::list(array_map(fn (Device $camera): string => (string) $camera->name, array_values($cameras)));
        $lines = [];
        if ($cameras !== []) {
            if ($test) {
                $lines[] = self::line('camera_privacy_on', 'info', 'A real run would turn camera privacy on until '.AutomationWords::at($until).' for '.$names.'. No camera changed');
            } else {
                foreach ($cameras as $camera) {
                    // Only ever adds privacy: nothing ends an override early (CameraPrivacy reads it).
                    CameraPrivacyOverride::query()->create(['device_id' => (int) $camera->id, 'until' => $until, 'source' => 'automation']);
                }
                $lines[] = self::line('camera_privacy_on', 'success', 'Camera privacy on until '.AutomationWords::at($until).': '.$names);
            }
        }
        $gone = count($ids) - count($cameras);
        if ($gone > 0) {
            $lines[] = self::line('camera_privacy_on', 'warning', $gone.' '.($gone === 1 ? 'camera is' : 'cameras are').' no longer at this Site, so nothing changed for '.($gone === 1 ? 'it' : 'them'));
        }

        return $lines;
    }

    /**
     * Read each door's lock. Any not locked (or not reporting its lock) goes to the staff on shift to
     * lock by hand. No command is ever sent.
     *
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $context
     * @return list<array{action: string, tone: string, text: string, at: string}>
     */
    private function doorCheck(Automation $automation, array $action, array $context, bool $test): array
    {
        $ids = array_map('intval', (array) ($action['doors'] ?? []));
        $doors = $this->devices->stillAt((int) $automation->site_id, 'doors', $ids);
        $reader = app(DoorPagePresenter::class);
        $locked = [];
        $notLocked = [];
        $unknown = [];
        foreach ($doors as $door) {
            match ($reader->lockNow($door)['locked']) {
                true => $locked[] = (string) $door->name,
                false => $notLocked[] = (string) $door->name,
                null => $unknown[] = (string) $door->name,
            };
        }
        $lines = [];
        if ($locked !== []) {
            $lines[] = self::line('door_locked_check', 'success', AutomationWords::list($locked).' locked');
        }
        $problems = [...$notLocked, ...$unknown];
        if ($problems !== []) {
            $told = $this->tellStaff($automation, $context, $test,
                $context['site'].': '.AutomationWords::list($problems).(count($problems) === 1 ? ' isn’t' : ' aren’t').' locked',
                'Lock '.(count($problems) === 1 ? 'it' : 'them').' by hand. Automations never lock or unlock a door.');
            if ($notLocked !== []) {
                $lines[] = self::line('door_locked_check', 'warning', AutomationWords::list($notLocked).' not locked · '.$told);
            }
            if ($unknown !== []) {
                $lines[] = self::line('door_locked_check', 'warning', AutomationWords::list($unknown).': lock not reported · '.$told);
            }
        }
        $gone = count($ids) - count($doors);
        if ($gone > 0) {
            $lines[] = self::line('door_locked_check', 'warning', $gone.' '.($gone === 1 ? 'door is' : 'doors are').' no longer at this Site, so '.($gone === 1 ? 'it wasn’t' : 'they weren’t').' checked');
        }

        return $lines;
    }

    /**
     * Read the alarm's areas. Any area not armed (or not reporting) reminds the staff on shift to arm
     * it by hand. Oblivion never arms or disarms for an automation.
     *
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $context
     * @return list<array{action: string, tone: string, text: string, at: string}>
     */
    private function alarmCheck(Automation $automation, array $action, array $context, bool $test): array
    {
        $ids = array_map('intval', (array) ($action['panels'] ?? []));
        $panels = $this->devices->stillAt((int) $automation->site_id, 'panels', $ids);
        $reader = app(AlarmPagePresenter::class);
        $lines = [];
        $off = [];
        foreach ($panels as $panel) {
            $areas = $reader->areasNow($panel);
            $notArmed = array_values(array_filter($areas, fn (array $area): bool => $area['armed'] !== true));
            if ($areas === [] || $notArmed !== []) {
                array_push($off, ...($areas === [] ? ['the alarm'] : array_map(fn (array $area): string => (string) $area['name'], $notArmed)));

                continue;
            }
            $lines[] = self::line('alarm_armed_check', 'success', AutomationWords::list(array_map(fn (array $area): string => (string) $area['name'], $areas)).' armed (from the panel’s report)');
        }
        if ($off !== []) {
            $what = AutomationWords::list($off);
            $told = $this->tellStaff($automation, $context, $test, $context['site'].': '.$what.' isn’t armed',
                'Arm it at the keypad. Automations never arm or disarm the alarm.');
            $lines[] = self::line('alarm_armed_check', 'warning', ucfirst($what).' not armed · '.str_replace('told', 'reminded', $told));
        }
        if (count($panels) < count($ids)) {
            $lines[] = self::line('alarm_armed_check', 'warning', 'The alarm panel is no longer at this Site, so it wasn’t checked');
        }

        return $lines;
    }

    /**
     * Tell the staff on shift what a check found, in the app. A test only says it would have.
     *
     * @param  array<string, mixed>  $context
     */
    private function tellStaff(Automation $automation, array $context, bool $test, string $title, string $line): string
    {
        if ($test) {
            return 'a real run would tell the staff on shift';
        }
        $people = $this->staff->toTell((int) $automation->site_id, $context['at']);
        if ($people->isEmpty()) {
            return 'nobody on shift to tell';
        }
        try {
            Notification::send($people, new SecurityDeviceAutomationNotification((int) $automation->id, (int) $automation->site_id, $title, $line, ['database'], 'warning'));
        } catch (Throwable $exception) {
            Log::warning('Automation check message could not be sent', ['automation_id' => $automation->id, 'error' => $exception->getMessage()]);

            return 'staff on shift couldn’t be told';
        }

        return 'staff on shift told';
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Who "Tell people" reaches: the staff on shift at the house (from the roster, or everyone at the
     * Site who looks after alerts when nobody is on shift), or the people in a role who can see it.
     *
     * @param  array<string, mixed>  $action
     * @return Collection<int, User>
     */
    private function recipients(Automation $automation, array $action, CarbonImmutable $at): Collection
    {
        $siteId = (int) $automation->site_id;
        if (($action['who'] ?? 'on_shift') !== 'role') {
            return $this->staff->toTell($siteId, $at);
        }
        $role = (string) ($action['role'] ?? '');

        return User::query()
            ->whereNotNull('approved_at')
            ->whereHas('roles', fn ($query) => $query->where('name', $role))
            ->with(['roles.permissions', 'permissionOverrides'])
            ->orderBy('name')
            ->limit(300)
            ->get()
            ->filter(fn (User $person): bool => $this->access->canViewAllSites($person) || in_array($siteId, $this->access->accessibleSiteIds($person), true))
            ->values();
    }

    /** Whether its "only if" holds: inside its hours, and the house in one of its modes (when it names any). */
    public function within(Automation $automation, CarbonImmutable $at): bool
    {
        return $this->whyNot($automation, $at) === null;
    }

    /** Why it wouldn't run at this time, in words, or null when it would. */
    public function whyNot(Automation $automation, CarbonImmutable $at): ?string
    {
        $between = $automation->conditions['between'] ?? null;
        if (is_array($between)) {
            $from = (string) ($between['from'] ?? '');
            $to = (string) ($between['to'] ?? '');
            $now = $at->setTimezone(AutomationWords::ZONE)->format('H:i');
            $inside = $from <= $to ? ($now >= $from && $now < $to) : ($now >= $from || $now < $to);
            if (! $inside) {
                return 'It’s outside '.AutomationWords::clock($from).' – '.AutomationWords::clock($to);
            }
        }
        // S&D Phase F: "House mode is", read from the Site's latest switch (house modes, pg-sdr-12c).
        $modes = array_values(array_map('intval', (array) ($automation->conditions['modes'] ?? [])));
        if ($modes !== []) {
            $current = HouseModeRunner::currentSwitch((int) $automation->site_id);
            if ($current === null || ! in_array((int) $current->mode_id, $modes, true)) {
                $names = HouseMode::query()->whereKey($modes)->orderBy('sort_order')->pluck('name')->map(fn (mixed $name): string => (string) $name)->all();

                return 'The house isn’t in '.AutomationWords::list($names, 'or').' mode now';
            }
        }

        return null;
    }

    /**
     * The latest time-of-day start at or before `$now` (today or yesterday, NZ wall clock), in UTC.
     *
     * @param  array<string, mixed>  $trigger
     */
    public static function lastStart(array $trigger, CarbonImmutable $now): ?CarbonImmutable
    {
        $at = (string) ($trigger['at'] ?? '');
        $days = array_map('intval', (array) ($trigger['days'] ?? []));
        if (preg_match('/\A([01]\d|2[0-3]):[0-5]\d\z/', $at) !== 1 || $days === []) {
            return null;
        }
        $local = $now->setTimezone(AutomationWords::ZONE);
        foreach ([0, 1] as $back) {
            $day = $local->subDays($back);
            if (! in_array($day->dayOfWeekIso, $days, true)) {
                continue;
            }
            $start = CarbonImmutable::parse($day->format('Y-m-d').' '.$at, AutomationWords::ZONE)->utc();
            if ($start->lte($now)) {
                return $start;
            }
        }

        return null;
    }

    /** The next time the NZ wall clock reads "HH:MM" after `$at`, in UTC. */
    private static function nextClock(string $hhmm, CarbonImmutable $at): ?CarbonImmutable
    {
        if (preg_match('/\A([01]\d|2[0-3]):[0-5]\d\z/', $hhmm) !== 1) {
            return null;
        }
        $local = $at->setTimezone(AutomationWords::ZONE);
        $candidate = CarbonImmutable::parse($local->format('Y-m-d').' '.$hhmm, AutomationWords::ZONE);
        if ($candidate->lte($at)) {
            $candidate = CarbonImmutable::parse($local->addDay()->format('Y-m-d').' '.$hhmm, AutomationWords::ZONE);
        }

        return $candidate->utc();
    }

    /** The trigger's device, or the first of its kind at the Site the tester can see (for a test's words). */
    private function sampleDevice(Automation $automation, User $tester): ?Device
    {
        $group = AutomationDefinition::TRIGGER_GROUP[(string) $automation->trigger_kind] ?? null;
        if ($group === null) {
            return null;
        }
        $choosable = $this->devices->forViewer($tester, (int) $automation->site_id)[(int) $automation->site_id][$group] ?? [];
        $id = $automation->triggerDeviceId();
        if ($id !== null) {
            return array_key_exists($id, $choosable) ? Device::query()->find($id) : null;
        }
        $first = array_key_first($choosable);

        return $first !== null ? Device::query()->find($first) : null;
    }

    /** @param array<string, mixed> $trigger */
    private function testReading(array $trigger, ?string $name, CarbonImmutable $now): string
    {
        $metric = (string) ($trigger['metric'] ?? '');
        $label = AutomationDefinition::METRICS[$metric]['label'] ?? 'Reading';
        $limit = (float) ($trigger['value'] ?? 0);
        $value = ($trigger['direction'] ?? 'above') === 'below' ? $limit - 1 : $limit + 1;

        return ($name ?? 'A sensor').': '.($metric === 'co2' || $metric === 'pm25' ? $label : mb_strtolower($label)).' '.AutomationWords::value($metric, $value).' at '.AutomationWords::at($now).' (a test reading)';
    }

    /** @return array<string, string> */
    private function roleLabels(): array
    {
        return $this->roleLabels ??= Role::query()->pluck('label', 'name')->map(fn (mixed $label): string => (string) $label)->all();
    }

    /** @return array{action: string, tone: string, text: string, at: string} */
    private static function line(string $action, string $tone, string $text): array
    {
        return ['action' => $action, 'tone' => $tone, 'text' => mb_substr($text, 0, 300), 'at' => CarbonImmutable::now('UTC')->toIso8601String()];
    }

    /** @param Collection<int, User> $people */
    private static function names(Collection $people): string
    {
        $names = $people->pluck('name')->map(fn (mixed $name): string => (string) $name)->unique()->values()->all();
        if (count($names) > 3) {
            return implode(', ', array_slice($names, 0, 3)).' and '.(count($names) - 3).' more';
        }

        return AutomationWords::list($names);
    }
}
