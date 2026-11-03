<?php

namespace App\Domain\SecurityDevices\Screens\Devices\Pages;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringMaintenanceWindow;
use App\Domain\SecurityDevices\Alerts\DeviceAlertEngine;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlert;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReferenceAuditEvent;
use App\Domain\SecurityDevices\Credentials\Services\DeviceSavedLogin;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Screens\Devices\DeviceCustody;
use App\Domain\SecurityDevices\Terminal\Models\TerminalSession;
use App\Models\AuditLog;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One logbook per device (S&D v13, pg-sdr-3e, and the Retired view's last lines in pg-sdr-2f):
 * alerts and how they ended, settings backups (with Compare), quiet times, moves, renames, saved
 * login changes, watching on and off, and terminal sessions, each with who did it. Only lines from
 * while the device sat at one of the viewer's Sites are kept (DeviceCustody), so a moved or removed
 * device never brings another Site's history. Each kind keeps the permission its own tab has:
 * alerts, checks, quiet times and backups need securityDevices.events.view, terminal sessions are
 * for IT admins (securityDevices.terminal.use), and saved-login changes for people who may replace
 * logins. Nothing is invented: a kind with no records simply has no lines.
 */
final class DeviceLogbook
{
    /** The "What" dropdown, in the drawn order. */
    public const array KINDS = [
        'alerts' => 'Alerts',
        'settings' => 'Settings',
        'quiet' => 'Quiet times',
        'logins' => 'Logins',
        'moves' => 'Moves',
        'terminal' => 'Terminal',
    ];

    /** Lines read from each source before merging. */
    private const int PER_SOURCE = 120;

    /** How a stored stop-watching reason code reads (NativeMonitoringDefinitionService's four). */
    public const array REASON_WORDS = [
        'replaced' => 'replaced',
        'obsolete' => 'no longer needed',
        'coverage_removed' => 'no longer covered',
        'device_retired' => 'retired',
    ];

    /** @var array<int, string> */
    private array $names = [];

    public function __construct(
        private readonly DeviceCustody $custody,
        private readonly DeviceSettingsBackups $backups,
    ) {}

    /**
     * The kinds this viewer may see, for the "What" dropdown.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function kindOptions(User $viewer, bool $network): array
    {
        $allowed = self::allowedKinds($viewer, $network);

        return [
            ['value' => 'all', 'label' => 'Everything'],
            ...array_values(array_map(
                fn (string $key): array => ['value' => $key, 'label' => self::KINDS[$key]],
                array_values(array_filter(array_keys(self::KINDS), fn (string $key): bool => in_array($key, $allowed, true))),
            )),
        ];
    }

    /** @return list<string> */
    private static function allowedKinds(User $viewer, bool $network): array
    {
        $events = $viewer->canDo('securityDevices.events.view');

        return array_values(array_filter([
            $events ? 'alerts' : null,
            $events ? 'settings' : null,
            $events ? 'quiet' : null,
            $network && DeviceSavedLogin::canReplace($viewer) ? 'logins' : null,
            'moves',
            $network && $viewer->canDo('securityDevices.terminal.use') ? 'terminal' : null,
        ]));
    }

    /**
     * Newest first. `$siteId` is the Site its settings backups and saved login belong to (its
     * current Site, or the last one for a removed device).
     *
     * @return list<array<string, mixed>>
     */
    public function lines(User $viewer, Device $device, ?int $siteId, string $what = 'all', ?CarbonImmutable $from = null, int $limit = 150, bool $network = true): array
    {
        $windows = $this->custody->windows($viewer, (int) $device->id);
        $earliest = DeviceCustody::earliest($windows);
        if ($earliest === null) {
            return [];
        }
        $since = $from !== null && $from->greaterThan($earliest) ? $from : $earliest;
        $allowed = self::allowedKinds($viewer, $network);
        $wanted = fn (string $kind): bool => in_array($kind, $allowed, true) && ($what === 'all' || $what === $kind);

        $lines = collect();
        if ($wanted('alerts')) {
            $lines = $lines->concat($this->alerts($viewer, $device, $since))
                ->concat($this->watching($device, $since));
        }
        if ($wanted('settings') && $siteId !== null && $network) {
            $lines = $lines->concat($this->backupLines($viewer, $device, $siteId, $since));
        }
        if ($wanted('settings') && $network) {
            $lines = $lines->concat($this->settingsExtras($device, $since, 'settings'));
        }
        if ($wanted('quiet')) {
            $lines = $lines->concat($this->quietTimes($device, DeviceCustody::siteIds($windows), $since));
        }
        if ($wanted('logins') && $siteId !== null) {
            $lines = $lines->concat($this->logins($device, $siteId, $since));
        }
        if ($wanted('moves')) {
            $lines = $lines->concat($this->moves($device, $since))
                ->concat($this->edits($device, $since))
                ->concat($this->settingsExtras($device, $since, 'moves'))
                ->concat($this->removal($device));
        }
        if ($wanted('terminal')) {
            $lines = $lines->concat($this->terminal($device, $since))
                ->concat($this->settingsExtras($device, $since, 'terminal'));
        }

        return $lines
            ->filter(fn (array $line): bool => $line['at'] instanceof CarbonImmutable
                && ($from === null || $line['at']->greaterThanOrEqualTo($from))
                && (($line['always'] ?? false) || DeviceCustody::covers($windows, $line['at'])))
            ->sortByDesc(fn (array $line): string => $line['at']->format('Y-m-d H:i:s.u').'|'.$line['key'])
            ->take($limit)
            ->map(function (array $line): array {
                unset($line['always']);
                $line['at'] = $line['at']->toIso8601String();

                return $line;
            })
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function line(string $key, string $kind, CarbonImmutable $at, string $tone, string $title, ?string $sub, ?string $who, array $extra = []): array
    {
        return [
            'key' => $key,
            'kind' => $kind,
            'at' => $at,
            'tone' => $tone,
            'title' => $title,
            'sub' => $sub,
            'who' => $who,
            'compare_id' => null,
            'span' => null,
            'href' => null,
            'tab' => null,
            ...$extra,
        ];
    }

    private function name(?int $userId): ?string
    {
        if ($userId === null || $userId < 1) {
            return null;
        }
        if (! array_key_exists($userId, $this->names)) {
            $this->names[$userId] = (string) (User::query()->whereKey($userId)->value('name') ?? '');
        }

        return $this->names[$userId] !== '' ? $this->names[$userId] : null;
    }

    /** @param list<int> $ids */
    private function rememberNames(array $ids): void
    {
        $missing = array_values(array_diff(array_unique(array_filter($ids)), array_keys($this->names)));
        if ($missing === []) {
            return;
        }
        $found = User::query()->whereKey($missing)->pluck('name', 'id');
        foreach ($missing as $id) {
            $this->names[$id] = (string) ($found[$id] ?? '');
        }
    }

    private static function at(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof CarbonImmutable ? $value : CarbonImmutable::parse($value);
    }

    /**
     * Alerts and how they ended, then the device's other reported events (not repeating the
     * offline/online pairs an alert already tells).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function alerts(User $viewer, Device $device, CarbonImmutable $since): Collection
    {
        $alerts = DeviceAlert::query()
            ->where('device_id', $device->id)
            ->where('opened_at', '>=', $since)
            ->latest('opened_at')
            ->limit(self::PER_SOURCE)
            ->get(['id', 'kind', 'severity', 'title', 'state', 'opened_at', 'closed_at', 'close_reason', 'acknowledged_at', 'acknowledged_by_user_id']);
        $this->rememberNames($alerts->pluck('acknowledged_by_user_id')->filter()->map(fn (mixed $id): int => (int) $id)->all());
        $now = CarbonImmutable::now();

        $lines = $alerts->map(function (DeviceAlert $alert) use ($now): array {
            $active = in_array($alert->state, DeviceAlert::ACTIVE_STATES, true);
            $acked = $alert->acknowledged_at !== null ? $this->name((int) $alert->acknowledged_by_user_id) : null;
            $ended = match (true) {
                $active => $alert->state === DeviceAlert::STATE_SNOOZED ? 'Snoozed, still open' : 'Still open',
                $alert->close_reason === 'recovered' => 'Back by itself after '.DeviceAlertEngine::duration($alert->opened_at, $alert->closed_at ?? $now),
                $alert->close_reason === 'behind' => 'Covered by the alert for the device in front of it',
                $alert->close_reason === 'done' => 'Marked done after '.DeviceAlertEngine::duration($alert->opened_at, $alert->closed_at ?? $now),
                $alert->close_reason === 'removed' => 'Closed when it was removed',
                default => 'Closed after '.DeviceAlertEngine::duration($alert->opened_at, $alert->closed_at ?? $now),
            };

            return $this->line(
                'alert-'.$alert->id,
                'alerts',
                $alert->opened_at,
                $alert->severity === 'critical' ? 'critical' : 'warning',
                (string) $alert->title,
                $ended.($acked !== null ? ' · '.$acked.' acknowledged it' : ''),
                $acked ?? 'Oblivion',
                ['href' => '/security-devices/alerts?alert='.$alert->id],
            );
        });

        $skip = $alerts->isNotEmpty() ? ['offline', 'online', 'recovered'] : [];
        $events = DeviceEvent::query()
            ->where('device_id', $device->id)
            ->where('occurred_at', '>=', $since)
            ->when($skip !== [], fn ($query) => $query->whereNotIn('event_type', $skip))
            ->latest('occurred_at')
            ->limit(self::PER_SOURCE)
            ->get(['id', 'event_type', 'severity', 'occurred_at', 'source'])
            ->filter(fn (DeviceEvent $event): bool => $event->occurred_at !== null)
            ->map(function (DeviceEvent $event): array {
                $words = DevicePageWords::event($event);

                return $this->line('event-'.$event->id, 'alerts', self::at($event->occurred_at), (string) $words['tone'], (string) $words['title'], $words['sub'], 'Oblivion');
            });

        return $lines->concat($events)->values();
    }

    /**
     * Watching turned on and off (its checks added or stopped), with the stored reason.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function watching(Device $device, CarbonImmutable $since): Collection
    {
        $monitorIds = Monitor::query()->where('device_id', $device->id)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        if ($monitorIds === []) {
            return collect();
        }
        $audits = AuditLog::query()
            ->where('auditable_type', (new Monitor)->getMorphClass())
            ->whereIn('auditable_id', $monitorIds)
            ->whereIn('action', ['monitoring.monitor.created', 'monitoring.monitor.deactivated', 'monitoring.monitor.reactivated'])
            ->where('created_at', '>=', $since)
            ->latest('created_at')
            ->limit(self::PER_SOURCE)
            ->get(['id', 'user_id', 'action', 'meta', 'created_at']);
        $this->rememberNames($audits->pluck('user_id')->filter()->map(fn (mixed $id): int => (int) $id)->all());

        // One line per change, even when it touched several checks at once.
        return $audits
            ->groupBy(fn (AuditLog $audit): string => $audit->action.'|'.$audit->user_id.'|'.$audit->created_at?->format('Y-m-d H:i'))
            ->map(function (Collection $group): array {
                /** @var AuditLog $audit */
                $audit = $group->first();
                $who = $this->name((int) $audit->user_id);
                $reason = self::REASON_WORDS[(string) data_get($audit->meta, 'reason_code')] ?? null;
                $stopped = $audit->action === 'monitoring.monitor.deactivated';

                return $this->line(
                    'watch-'.$audit->id,
                    'alerts',
                    self::at($audit->created_at),
                    $stopped ? 'none' : 'success',
                    $stopped ? 'Watching stopped' : ($audit->action === 'monitoring.monitor.reactivated' ? 'Watched again' : 'Watching started'),
                    implode(' · ', array_filter([$who, $reason, $group->count() > 1 ? $group->count().' checks' : null])) ?: null,
                    $who,
                );
            })
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function backupLines(User $viewer, Device $device, int $siteId, CarbonImmutable $since): Collection
    {
        return collect($this->backups->history($viewer, $device, $siteId, self::PER_SOURCE))
            ->filter(fn (array $copy): bool => is_string($copy['at']) && CarbonImmutable::parse($copy['at'])->greaterThanOrEqualTo($since))
            ->map(function (array $copy): array {
                $changes = $copy['changes'] ?? 0;

                return $this->line(
                    'backup-'.$copy['id'],
                    'settings',
                    CarbonImmutable::parse($copy['at']),
                    $changes > 0 ? 'info' : 'success',
                    match (true) {
                        $copy['first'] => 'Settings backed up for the first time',
                        $changes > 0 => 'Settings changed: '.$changes.' '.($changes === 1 ? 'line' : 'lines'),
                        default => 'Settings backed up',
                    },
                    match (true) {
                        $copy['first'] => 'The first copy kept',
                        $changes > 0 => 'Compared with the copy before',
                        default => 'Same as the copy before · kept with the others',
                    },
                    'Oblivion',
                    ['compare_id' => $changes > 0 && $copy['compare'] ? $copy['id'] : null],
                );
            })
            ->values();
    }

    /**
     * Quiet times that covered it: for this device, one of its checks, or its whole Site.
     *
     * @param  list<int>  $siteIds
     * @return Collection<int, array<string, mixed>>
     */
    private function quietTimes(Device $device, array $siteIds, CarbonImmutable $since): Collection
    {
        if ($siteIds === []) {
            return collect();
        }
        $monitorIds = Monitor::query()->where('device_id', $device->id)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $windows = MonitoringMaintenanceWindow::query()
            ->whereIn('site_id', $siteIds)
            ->where('ends_at', '>=', $since)
            ->where(function ($query) use ($device, $monitorIds): void {
                $query->where(fn ($site) => $site->whereNull('device_id')->whereNull('monitor_id'))
                    ->orWhere('device_id', $device->id)
                    ->orWhereIn('monitor_id', $monitorIds === [] ? [0] : $monitorIds);
            })
            ->latest('starts_at')
            ->limit(self::PER_SOURCE)
            ->get(['id', 'site_id', 'device_id', 'monitor_id', 'name', 'starts_at', 'ends_at', 'reason', 'status', 'created_by_user_id', 'created_at', 'cancelled_at']);
        $this->rememberNames($windows->pluck('created_by_user_id')->filter()->map(fn (mixed $id): int => (int) $id)->all());

        // Cameras, alarms, doors and care kit never pause, even in quiet time (the monitoring engine's rule).
        $safety = in_array((string) $device->domain, ['security', 'iot_healthcare'], true);

        return $windows->map(function (MonitoringMaintenanceWindow $window) use ($safety): array {
            $who = $this->name((int) $window->created_by_user_id);
            $planned = self::at($window->created_at) ?? $window->starts_at;
            $wholeSite = $window->device_id === null && $window->monitor_id === null;

            return $this->line(
                'quiet-'.$window->id,
                'quiet',
                // Placed at when it was set, so the plan sits where it was made; the span says when it runs.
                $planned,
                $window->cancelled_at !== null ? 'none' : 'info',
                $window->cancelled_at !== null ? 'Quiet time cancelled' : 'Quiet time',
                implode(' · ', array_filter([
                    $who,
                    trim((string) $window->name) ?: null,
                    $window->reason ? trim((string) $window->reason) : null,
                    $wholeSite ? 'for the whole Site' : null,
                    $window->cancelled_at !== null ? null : ($safety ? 'its own alerts still went out: safety devices never pause' : 'its alerts waited'),
                ])),
                $who,
                // The time column shows when it was set; both ends of the quiet time go with it.
                ['span' => ['from' => $window->starts_at?->toIso8601String(), 'to' => $window->ends_at?->toIso8601String()]],
            );
        })->filter(fn (array $line): bool => $line['at'] !== null)->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function logins(Device $device, int $siteId, CarbonImmutable $since): Collection
    {
        $reference = app(DeviceSavedLogin::class)->reference($device, $siteId);
        if ($reference === null) {
            return collect();
        }
        $events = CredentialReferenceAuditEvent::query()
            ->where('credential_reference_id', $reference->id)
            ->whereIn('action', ['rotated', 'created'])
            ->where('occurred_at', '>=', $since)
            ->latest('occurred_at')
            ->limit(self::PER_SOURCE)
            ->get(['id', 'actor_user_id', 'action', 'occurred_at']);
        $this->rememberNames($events->pluck('actor_user_id')->filter()->map(fn (mixed $id): int => (int) $id)->all());

        return $events->map(function (CredentialReferenceAuditEvent $event): array {
            $who = $this->name((int) $event->actor_user_id);

            return $this->line(
                'login-'.$event->id,
                'logins',
                self::at($event->occurred_at),
                'info',
                $event->action === 'created' ? 'Saved login added' : 'Saved login replaced',
                implode(' · ', array_filter([$who, 'stored encrypted · nothing secret is shown here'])),
                $who,
            );
        })->values();
    }

    /**
     * Where it was put: the first Site it came to, then each move (Sites and rooms by name; a person
     * or vehicle never named).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function moves(Device $device, CarbonImmutable $since): Collection
    {
        $assignments = DeviceAssignment::query()
            ->where('device_id', $device->id)
            ->orderBy('assigned_at')
            ->orderBy('id')
            ->get(['id', 'assignable_type', 'assignable_id', 'custody_site_id', 'assigned_at', 'assigned_by_user_id']);
        $siteNames = Site::query()->whereKey($assignments->pluck('custody_site_id')->filter()->unique()->all())->pluck('name', 'id');
        $roomIds = $assignments->where('assignable_type', DeviceAssignment::TARGET_ROOM)->pluck('assignable_id')->filter()->unique()->all();
        $roomNames = $roomIds === [] ? collect() : SiteRoom::query()->whereKey($roomIds)->pluck('name', 'id');
        $this->rememberNames($assignments->pluck('assigned_by_user_id')->filter()->map(fn (mixed $id): int => (int) $id)->all());

        $previousSite = null;
        $lines = collect();
        foreach ($assignments as $index => $assignment) {
            $at = self::at($assignment->assigned_at);
            $site = (string) ($siteNames[$assignment->custody_site_id] ?? 'a Site');
            $where = match ($assignment->assignable_type) {
                DeviceAssignment::TARGET_ROOM => $site.' · '.($roomNames[$assignment->assignable_id] ?? 'a room'),
                DeviceAssignment::TARGET_CLIENT => $site.' · with a resident',
                DeviceAssignment::TARGET_STAFF => $site.' · with a staff member',
                DeviceAssignment::TARGET_VEHICLE => 'a vehicle',
                default => $site,
            };
            $who = $this->name((int) $assignment->assigned_by_user_id);
            $sameSite = $previousSite !== null && $previousSite === (int) $assignment->custody_site_id;
            $previousSite = (int) $assignment->custody_site_id;
            if ($at === null || $at->lessThan($since)) {
                continue;
            }
            $lines->push($this->line(
                'move-'.$assignment->id,
                'moves',
                $at,
                'info',
                match (true) {
                    $index === 0 => 'Added at '.$where,
                    $sameSite => 'Moved within '.$site,
                    default => 'Moved to '.$where,
                },
                $who,
                $who,
            ));
        }

        return $lines;
    }

    /**
     * Renames and room changes people made in Oblivion (the device's own change audit).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function edits(Device $device, CarbonImmutable $since): Collection
    {
        $audits = AuditLog::query()
            ->where('auditable_type', $device->getMorphClass())
            ->where('auditable_id', $device->id)
            ->where('action', 'device.update')
            ->whereNotNull('user_id')
            ->where('created_at', '>=', $since)
            ->latest('created_at')
            ->limit(self::PER_SOURCE)
            ->get(['id', 'user_id', 'meta', 'created_at']);
        $this->rememberNames($audits->pluck('user_id')->filter()->map(fn (mixed $id): int => (int) $id)->all());

        return $audits->map(function (AuditLog $audit): ?array {
            $fields = array_values(array_filter((array) data_get($audit->meta, 'fields', []), 'is_string'));
            $named = array_values(array_intersect($fields, ['name', 'location_description', 'notes', 'manufacturer', 'model']));
            if ($named === []) {
                return null;
            }
            $who = $this->name((int) $audit->user_id);
            $words = array_map(fn (string $field): string => match ($field) {
                'name' => 'its name',
                'location_description' => 'its room or spot',
                'notes' => 'its note',
                default => 'its make or model',
            }, $named);
            $title = match (true) {
                $named === ['name'] => 'Renamed',
                $named === ['location_description'] => 'Room or spot changed',
                default => 'Details changed',
            };

            return $this->line(
                'edit-'.$audit->id,
                'moves',
                self::at($audit->created_at),
                'info',
                $title,
                implode(' · ', array_filter([$who, count($named) > 1 ? 'changed '.implode(', ', $words) : null])) ?: null,
                $who,
            );
        })->filter()->values();
    }

    /**
     * "Removed": always the last line of a removed device (Remove keeps its history, Stephan 1 Nov).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function removal(Device $device): Collection
    {
        if ($device->deleted_at === null) {
            return collect();
        }
        $audit = AuditLog::query()
            ->where('auditable_type', $device->getMorphClass())
            ->where('auditable_id', $device->id)
            ->where('action', 'device.decommissioned')
            ->latest('id')
            ->first(['id', 'user_id', 'created_at']);
        $who = $this->name($audit?->user_id === null ? null : (int) $audit->user_id);

        return collect([$this->line(
            'removed-'.$device->id,
            'moves',
            self::at($audit?->created_at ?? $device->deleted_at),
            'none',
            'Removed',
            implode(' · ', array_filter([$who, 'off every list from now · its history stays'])),
            $who,
            // Written while it was still at its last Site, then released in the same moment.
            ['always' => true],
        )]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function terminal(Device $device, CarbonImmutable $since): Collection
    {
        $sessions = TerminalSession::query()
            ->where('device_id', $device->id)
            ->where('created_at', '>=', $since)
            ->withCount(['lines as commands_count' => fn ($query) => $query->where('direction', 'in')])
            ->latest('id')
            ->limit(self::PER_SOURCE)
            ->get(['id', 'user_id', 'state', 'created_at', 'closed_at', 'close_reason']);
        $this->rememberNames($sessions->pluck('user_id')->filter()->map(fn (mixed $id): int => (int) $id)->all());

        return $sessions->map(function (TerminalSession $session): array {
            $who = $this->name((int) $session->user_id);
            $count = (int) ($session->commands_count ?? 0);
            $closed = match ($session->close_reason) {
                null => $session->isLive() ? 'still open' : null,
                'idle' => 'closed by itself after it sat idle',
                'too_long' => 'closed when it reached its time limit',
                'ended', 'closed', 'user' => 'closed',
                default => 'closed: '.DevicePageWords::sentence((string) $session->close_reason),
            };

            return $this->line(
                'terminal-'.$session->id,
                'terminal',
                self::at($session->created_at),
                'info',
                'Terminal opened',
                implode(' · ', array_filter([$who, $count === 1 ? '1 command, kept' : $count.' commands, all kept', $closed])),
                $who,
                ['tab' => 'terminal'],
            );
        })->values();
    }

    /**
     * Per-device Settings extras (pg-sdr-3f): each field keeps its own tab's permission boundary
     * ("settings" needs events.view, "moves" is device custody, and "terminal" is IT admins only).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function settingsExtras(Device $device, CarbonImmutable $since, string $bucket): Collection
    {
        $audits = AuditLog::query()
            ->where('auditable_type', $device->getMorphClass())
            ->where('auditable_id', $device->id)
            ->where('action', 'security_devices.device.settings_updated')
            ->whereNotNull('user_id')
            ->where('created_at', '>=', $since)
            ->latest('created_at')
            ->limit(self::PER_SOURCE)
            ->get(['id', 'user_id', 'meta', 'created_at']);
        $this->rememberNames($audits->pluck('user_id')->filter()->map(fn (mixed $id): int => (int) $id)->all());

        $out = collect();
        foreach ($audits as $audit) {
            $fields = array_values(array_filter((array) data_get($audit->meta, 'fields', []), 'is_string'));
            $who = $this->name((int) $audit->user_id);
            $at = self::at($audit->created_at);
            if ($at === null || $fields === []) {
                continue;
            }
            if ($bucket === 'settings' && in_array('tell_it_update_ready', $fields, true)) {
                $to = data_get($audit->meta, 'after.is_enabled');
                $title = is_bool($to)
                    ? ($to ? 'Update notices turned on' : 'Update notices turned off')
                    : 'Update notices changed';
                $detail = is_bool($to)
                    ? ($to ? 'in the 6:00 am summary' : 'left out of the 6:00 am summary')
                    : null;
                $out->push($this->line(
                    'setting-update-ready-'.$audit->id,
                    'settings',
                    $at,
                    'info',
                    $title,
                    implode(' · ', array_filter([$who, $detail])) ?: null,
                    $who,
                ));
            }
            if ($bucket === 'moves' && in_array('looked_after_by_user_id', $fields, true)) {
                $out->push($this->line(
                    'setting-looked-after-'.$audit->id,
                    'moves',
                    $at,
                    'info',
                    'Looked after by changed',
                    $who,
                    $who,
                ));
            }
            if ($bucket === 'terminal' && in_array('ssh_user', $fields, true)) {
                $out->push($this->line(
                    'setting-ssh-user-'.$audit->id,
                    'terminal',
                    $at,
                    'info',
                    'SSH user changed',
                    $who,
                    $who,
                ));
            }
        }

        return $out->values();
    }
}
