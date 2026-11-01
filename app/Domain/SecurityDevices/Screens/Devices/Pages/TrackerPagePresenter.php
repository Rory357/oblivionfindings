<?php

namespace App\Domain\SecurityDevices\Screens\Devices\Pages;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Screens\DeviceState;
use App\Domain\SecurityDevices\Screens\Devices\DeviceDirectory;
use App\Domain\SecurityDevices\Screens\Devices\DeviceEntry;
use App\Domain\SecurityDevices\Services\PersonalTrackingPrivacyService;
use App\Domain\SecurityDevices\Services\QueclinkUnitConsoleService;
use App\Models\AppSetting;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\Queclink\QueclinkDevice;
use App\Models\Queclink\QueclinkRawFrame;
use App\Models\User;
use App\Services\Queclink\CommandBuilder;
use App\Services\Queclink\ConfigurationSnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * The care tracker's page (S&D rebuild Phase E, drawings pg-sdr-8, 8b and 8c): Location · Console.
 *
 * Privacy first. The location shows only when today's rule allows it
 * (QueclinkUnitConsoleService::presentTrackerPosition: location permission, the person's active
 * location consent linked to this tracker, the assignment's own rules); otherwise the page says
 * why and who can fix it, and no coordinate reaches the browser. Consent dates and history show
 * only to someone who may see the person and their consents. Commands go through today's command
 * path (QueclinkUnitConsoleService::sendCommand) and also need securityDevices.trackers.command.
 *
 * Not here, on purpose: the Battery & checks and Settings tabs, the safe zone, planned outings and
 * the day's trail (not drawn for this round, or no data behind them yet).
 */
final class TrackerPagePresenter
{
    public const array TAB_LABELS = [
        'location' => 'Location',
        'console' => 'Console',
    ];

    /** The commands pop-up 19 offers: key => [label, what it does, risky]. */
    public const array COMMANDS = [
        'request_location' => ['Ask for position now', 'Gets a fresh location', false],
        'update_reporting_interval' => ['Change report interval', 'How often it sends its position', true],
        'reboot' => ['Restart the unit', 'Turns it off and on again', true],
        'read_config' => ['Ask for settings', 'Reads back what it’s set to', false],
    ];

    /** Report intervals offered, in minutes. */
    public const array INTERVALS = [1, 2, 5, 10, 30];

    public function __construct(
        private readonly DeviceDirectory $directory,
        private readonly QueclinkUnitConsoleService $console,
        private readonly PersonalTrackingPrivacyService $privacy,
        private readonly ConfigurationSnapshotService $snapshots,
        private readonly CommandBuilder $builder,
    ) {}

    public static function serves(Device $device): bool
    {
        return DeviceKind::of($device) === DeviceKind::CareTracker;
    }

    /**
     * @param  array{tab?: mixed}  $input
     * @return array<string, mixed>
     */
    public function present(User $viewer, Device $device, array $input): array
    {
        $now = CarbonImmutable::now();
        $entry = $this->directory->entries($viewer, null, [(int) $device->id])->first();
        $state = $entry?->state ?? DeviceState::Retired;
        $retired = $state === DeviceState::Retired;
        $supported = $this->console->supportsDevice($device);
        $tracker = $supported ? $this->console->resolveTracker($device) : null;
        $canConsole = $supported && $this->console->canViewConsole($viewer);

        $position = $this->console->presentTrackerPosition($viewer, $device);
        $shown = in_array($position['state'] ?? null, ['available', 'no_fix_yet'], true);
        $person = $this->person($viewer, $device);
        $snapshot = $tracker !== null ? $this->snapshots->latestForDevice($tracker) : null;
        $interval = self::intervalSeconds($snapshot);

        $tabs = array_values(array_filter(['location', $canConsole ? 'console' : null]));
        $tab = in_array($input['tab'] ?? null, $tabs, true) ? (string) $input['tab'] : 'location';
        $commands = $this->commands($viewer, $device, $tracker, $shown, $interval);

        return [
            'device' => [
                'id' => (int) $device->id,
                'name' => (string) $device->name,
                'kind' => DeviceKind::CareTracker->value,
                'kind_label' => DeviceKind::CareTracker->label(),
                'group' => 'tracker',
                'state' => $state->value,
                'badge' => ['label' => $state->label(), 'tone' => $state->tone()],
                'model' => $entry?->model() ?? trim(trim((string) $device->manufacturer).' '.trim((string) $device->model)),
                'site' => $entry?->siteName,
                'site_id' => $entry?->siteId,
                'address' => null,
                'watching' => $entry === null || $state === DeviceState::NotWatched ? 'Nothing' : Str::ucfirst(implode(', ', $entry->watching)),
                'subline' => $this->subline($device, $entry, $tracker, $person, $position, $shown),
                'back' => ['href' => '/security-devices/devices?tab=trackers', 'label' => 'Devices'],
            ],
            'tab' => $tab,
            'tabs' => array_map(fn (string $key): array => ['key' => $key, 'label' => self::TAB_LABELS[$key]], $tabs),
            'meters' => $this->meters($device, $position, $shown, $person, $snapshot, $interval),
            'can' => [
                'edit' => $viewer->canDo('securityDevices.devices.update') && ! $retired,
                'watch' => $viewer->canDo('securityDevices.monitoring.manage'),
                'move' => $viewer->canDo('securityDevices.devices.assign'),
                'assign' => $viewer->canDo('securityDevices.devices.assign'),
                'retire' => $viewer->canDo('securityDevices.devices.delete') && ! $retired,
                'upload' => false,
                'see_events' => $viewer->canDo('securityDevices.events.view'),
                'see_console' => $canConsole,
                'command' => $commands['options'] !== [],
                'locate' => collect($commands['options'])->contains(fn (array $option): bool => $option['key'] === 'request_location'),
            ],
            'privacy' => [
                'shown' => $shown,
                'state' => (string) ($position['state'] ?? 'restricted'),
                'person' => $person === null ? null : [
                    'kind' => $person['kind'],
                    'short' => $person['short'],
                    'consent' => $person['consent'],
                ],
            ],
            'commands' => $commands,
            'location' => $tab === 'location' ? $this->location($viewer, $device, $tracker, $position, $shown, $person, $snapshot, $now) : null,
            'console' => $tab === 'console' && $canConsole ? $this->consoleTab($viewer, $device, $person, $shown) : null,
        ];
    }

    /**
     * Who wears it. A client is named only to someone who may see that client; their consent dates
     * only to someone who may also see consents.
     *
     * @return array<string, mixed>|null
     */
    private function person(User $viewer, Device $device): ?array
    {
        $assignment = $device->assignments()->active()->with('consent:id,client_id,consent_type_id,status,given_at,expires_at,withdrawn_at,renewal_reminder_sent_at,given_by_relationship')
            ->latest('assigned_at')->latest('id')->first();
        if (! $assignment instanceof DeviceAssignment || ! in_array($assignment->assignable_type, [DeviceAssignment::TARGET_CLIENT, DeviceAssignment::TARGET_STAFF], true)) {
            return null;
        }

        if ($assignment->assignable_type === DeviceAssignment::TARGET_STAFF) {
            $staff = User::query()->find($assignment->assignable_id, ['id', 'name']);

            return [
                'kind' => 'staff',
                'short' => $staff ? (string) $staff->name : null,
                'full' => $staff ? (string) $staff->name : null,
                'site' => null,
                'client_id' => null,
                'consent' => null,
                'consent_details' => false,
                'record_href' => null,
                'consent_row' => null,
            ];
        }

        $client = Client::query()->find($assignment->assignable_id);
        if (! $client instanceof Client) {
            return null;
        }
        $mayView = Gate::forUser($viewer)->allows('view', $client);
        $details = $mayView && $viewer->canDo('consents.viewAny');
        $active = $this->privacy->assignmentAuthorisesResidentLocation($assignment, $client);
        $consent = $assignment->consent;
        $short = trim((string) ($client->preferred_name ?: $client->first_name));
        $full = trim($short.' '.trim((string) $client->last_name));

        return [
            'kind' => 'client',
            'short' => $mayView && $short !== '' ? $short : null,
            'full' => $mayView && $full !== '' ? $full : null,
            'site' => $mayView ? $client->site?->name : null,
            'client_id' => $mayView ? (int) $client->id : null,
            'consent' => [
                'active' => $active,
                'until' => $details && $active ? $consent?->expires_at?->toIso8601String() : null,
                'ended' => $details && ! $active ? ($consent?->withdrawn_at ?? ($consent?->expires_at?->isPast() ? $consent->expires_at : null))?->toIso8601String() : null,
                'details' => $details,
            ],
            'consent_details' => $details,
            'record_href' => $mayView && $viewer->canDo('consents.record') && $viewer->canDo('consents.viewAny') ? '/operations/clients/'.$client->id.'/consents' : null,
            'consent_row' => $details ? $consent : null,
        ];
    }

    /**
     * Model · who wears it · Site · IMEI · position (or check-in) N min ago.
     *
     * @param  array<string, mixed>|null  $person
     * @param  array<string, mixed>  $position
     * @return list<array{text: string, at: ?string}>
     */
    private function subline(Device $device, ?DeviceEntry $entry, ?QueclinkDevice $tracker, ?array $person, array $position, bool $shown): array
    {
        $parts = [['text' => $entry?->model() ?? DeviceKind::CareTracker->label(), 'at' => null]];
        if (($person['full'] ?? null) !== null) {
            $parts[] = ['text' => (string) $person['full'], 'at' => null];
        }
        if ($entry?->siteName !== null) {
            $parts[] = ['text' => $entry->siteName, 'at' => null];
        }
        $imei = self::maskImei($tracker?->imei ?? $device->imei);
        if ($imei !== null) {
            $parts[] = ['text' => 'IMEI '.$imei, 'at' => null];
        }
        $positionAt = $shown && is_string($position['occurred_at'] ?? null) ? $position['occurred_at'] : null;
        $heard = $this->lastHeard($device, $tracker);
        $parts[] = match (true) {
            $positionAt !== null => ['text' => 'position {ago}', 'at' => $positionAt],
            $heard !== null => ['text' => 'checked {ago}', 'at' => $heard],
            default => ['text' => 'not heard from yet', 'at' => null],
        };

        return $parts;
    }

    private function lastHeard(Device $device, ?QueclinkDevice $tracker): ?string
    {
        return collect([$tracker?->last_frame_at, $tracker?->last_seen_at, $device->last_seen_at, $device->last_signal_at])
            ->filter()
            ->max()
            ?->toIso8601String();
    }

    /**
     * Last position (or why it's hidden) · Battery · Reports · Location consent.
     *
     * @param  array<string, mixed>  $position
     * @param  array<string, mixed>|null  $person
     * @param  array<string, mixed>|null  $snapshot
     * @return list<array<string, mixed>>
     */
    private function meters(Device $device, array $position, bool $shown, ?array $person, ?array $snapshot, ?int $interval): array
    {
        $at = is_string($position['occurred_at'] ?? null) ? $position['occurred_at'] : null;
        $meters = [];
        $meters[] = match (true) {
            $shown && $at !== null => self::meter('position', 'Last position', null, [
                'text' => is_string($position['address'] ?? null) && $position['address'] !== ''
                    ? (string) $position['address']
                    : (is_int($position['accuracy_m'] ?? null) ? 'Accurate to about '.$position['accuracy_m'].' m' : 'Position {ago}'),
                'at' => $at,
            ], 'success', 'location', time: $at),
            $shown => self::meter('position', 'Last position', null, ['text' => 'No position from it yet', 'at' => null], 'brand', 'location'),
            default => self::meter('position', 'Last position', 'Hidden', ['text' => self::hiddenWords((string) ($position['state'] ?? '')), 'at' => null], 'brand', 'location'),
        };

        $battery = $device->battery_level !== null ? (int) $device->battery_level : (is_int($position['battery_pct'] ?? null) ? (int) $position['battery_pct'] : null);
        $meters[] = self::meter('battery', 'Battery', $battery === null ? null : $battery.'%', $battery === null
            ? ['text' => 'Not sent by the unit yet', 'at' => null]
            : ['text' => $device->battery_updated_at ? 'Read {ago}' : 'From its last report', 'at' => $device->battery_updated_at?->toIso8601String()],
            $battery === null ? 'brand' : ($battery <= DeviceEntry::LOW_BATTERY ? 'warning' : 'success'), 'location');

        $meters[] = self::meter('reports', 'Reports', $interval === null ? null : 'Every '.self::intervalWords($interval), $interval === null
            ? ['text' => 'Not read back from the unit yet', 'at' => null]
            : ['text' => 'Read back from the unit {ago}', 'at' => is_string($snapshot['received_at'] ?? null) ? $snapshot['received_at'] : null], 'brand', 'console');

        if (($person['kind'] ?? null) === 'client') {
            $consent = $person['consent'];
            $meters[] = self::meter('consent', 'Location consent', $consent['active'] ? 'Active' : 'Not active', match (true) {
                $consent['active'] && $consent['until'] !== null => ['text' => 'Until {date}', 'at' => $consent['until']],
                $consent['active'] && $consent['details'] => ['text' => 'No end date', 'at' => null],
                $consent['active'] => ['text' => 'Linked to this tracker', 'at' => null],
                $consent['ended'] !== null => ['text' => 'Ended {date}', 'at' => $consent['ended']],
                default => ['text' => 'Location stays hidden', 'at' => null],
            }, $consent['active'] ? 'success' : 'critical', 'location');
        }

        return $meters;
    }

    /**
     * @param  array{text: string, at: ?string}  $caption
     * @return array<string, mixed>
     */
    private static function meter(string $key, string $label, ?string $value, array $caption, string $tone, string $tab, ?string $time = null): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value, 'time' => $time, 'caption' => $caption, 'tone' => $tone, 'tab' => $tab];
    }

    private static function hiddenWords(string $state): string
    {
        return match ($state) {
            'consent_required' => 'No active location consent',
            'permission_required' => 'You can’t see locations',
            'unassigned' => 'Not linked to a person yet',
            'collection_paused' => 'Location collection is paused',
            default => 'Restricted for this person',
        };
    }

    /**
     * The commands this person may send, each with the exact message (password hidden). Hidden
     * when the server would refuse them.
     *
     * @return array{ready: bool, reason: ?string, options: list<array<string, mixed>>, interval: ?int, intervals: list<int>, locate_off: bool}
     */
    private function commands(User $viewer, Device $device, ?QueclinkDevice $tracker, bool $shown, ?int $interval): array
    {
        $ready = $tracker !== null && $tracker->isPaired();
        $empty = ['ready' => $ready, 'reason' => null, 'options' => [], 'interval' => $interval, 'intervals' => self::INTERVALS, 'locate_off' => false];
        if (! $viewer->canDo('securityDevices.trackers.command') || $tracker === null || ! $this->console->supportsDevice($device)) {
            return $empty;
        }
        if (! $ready) {
            return [...$empty, 'reason' => 'This tracker isn’t paired with a live unit yet, so it can’t take commands.'];
        }
        $family = $this->console->resolveFamily($tracker, $device);
        $safe = $this->console->canSendSafeCommands($viewer);
        $risky = $this->console->canSendRiskyCommands($viewer);

        $options = [];
        foreach (self::COMMANDS as $key => [$label, $sub, $isRisky]) {
            if ($isRisky ? ! $risky : ! $safe) {
                continue;
            }
            // Asking for a position needs the location to be shown in the first place.
            if ($key === 'request_location' && ! $shown) {
                continue;
            }
            $options[] = [
                'key' => $key,
                'label' => $label,
                'sub' => $sub,
                'risky' => $isRisky,
                'message' => $key === 'update_reporting_interval' ? null : $this->preview($family, $key, null),
                'messages' => $key === 'update_reporting_interval'
                    ? collect(self::INTERVALS)->mapWithKeys(fn (int $minutes): array => [(string) $minutes => $this->preview($family, $key, $minutes * 60)])->all()
                    : null,
            ];
        }

        return [...$empty, 'options' => $options, 'locate_off' => $safe && ! $shown];
    }

    /** The message the unit gets, built the same way as the real one, with the password and its number hidden. */
    private function preview(string $family, string $key, ?int $seconds): ?string
    {
        try {
            $built = match ($key) {
                'request_location' => $this->builder->requestLocation($family, 'preview'),
                'read_config' => $this->builder->readConfiguration($family, 'ALL', 'preview'),
                'reboot' => $this->builder->reboot($family, 'preview'),
                'update_reporting_interval' => $this->builder->setReportingInterval($family, (int) $seconds, 'preview'),
                default => null,
            };
        } catch (InvalidArgumentException) {
            return null;
        }
        if ($built === null) {
            return null;
        }
        $text = $this->console->redactRawFrame((string) $built['raw'], QueclinkRawFrame::DIRECTION_OUTBOUND, (string) $built['command_word'], true);
        $text = preg_replace('/,[0-9A-F]{4}\$$/i', ',····$', $text) ?? $text;

        return self::shown($text);
    }

    /**
     * The Location tab: the map only when the location may be shown; otherwise why not and who can
     * fix it. Battery and check-ins either way.
     *
     * @param  array<string, mixed>  $position
     * @param  array<string, mixed>|null  $person
     * @param  array<string, mixed>|null  $snapshot
     * @return array<string, mixed>
     */
    private function location(User $viewer, Device $device, ?QueclinkDevice $tracker, array $position, bool $shown, ?array $person, ?array $snapshot, CarbonImmutable $now): array
    {
        $heard = $this->lastHeard($device, $tracker);
        $checkins = [
            ['label' => 'Last heard', 'text' => $heard !== null ? '{time}' : 'Not yet', 'at' => $heard],
            ['label' => 'Connection', 'text' => match ($tracker?->connection_state) {
                QueclinkDevice::CONN_CONNECTED => 'Connected to Oblivion',
                QueclinkDevice::CONN_DISCONNECTED => 'Not connected now',
                default => 'Not available',
            }, 'at' => null],
        ];
        $firmware = $tracker?->firmware_version ?: $device->firmware_version;
        if ($firmware) {
            $checkins[] = ['label' => 'Firmware', 'text' => (string) $firmware, 'at' => null];
        }

        $isClient = ($person['kind'] ?? null) === 'client';
        $consentActive = (bool) ($person['consent']['active'] ?? false);

        return [
            'position' => $shown && ($position['latitude'] ?? null) !== null && ($position['longitude'] ?? null) !== null ? [
                'lat' => (float) $position['latitude'],
                'lng' => (float) $position['longitude'],
                'accuracy_m' => is_int($position['accuracy_m'] ?? null) ? $position['accuracy_m'] : null,
                'at' => $position['occurred_at'] ?? null,
                'address' => is_string($position['address'] ?? null) && $position['address'] !== '' ? $position['address'] : null,
            ] : null,
            'fix' => $this->fixWords($person, $isClient, $consentActive, (string) ($position['state'] ?? '')),
            'record_href' => $isClient && ! $consentActive ? ($person['record_href'] ?? null) : null,
            'battery' => $this->batteryTrend($device, $now),
            'checkins' => $checkins,
            'consent_history' => $isClient && ($person['consent_details'] ?? false) ? $this->consentHistory($person['consent_row'] ?? null) : null,
            'linked' => $person !== null && $person['full'] !== null ? [
                'name' => (string) $person['full'],
                'sub' => $person['site'] ?? ($person['kind'] === 'staff' ? 'Staff safety tracker' : null),
                'flag' => $isClient ? ($consentActive ? ['label' => 'Consent active', 'tone' => 'success'] : ['label' => 'Consent not active', 'tone' => 'warning']) : null,
                'href' => $isClient && $person['client_id'] !== null ? '/clients/'.$person['client_id'] : null,
            ] : null,
        ];
    }

    /**
     * Why the location isn't shown, in plain words, with who can change that. Null when it's shown.
     *
     * @param  array<string, mixed>|null  $person
     * @return array{tone: string, note: string, title: ?string, sub: ?string}|null
     */
    private function fixWords(?array $person, bool $isClient, bool $consentActive, string $state): ?array
    {
        $whose = ($person['short'] ?? null) !== null ? $person['short'].'’s' : 'the wearer’s';
        $name = $person['short'] ?? 'the person';

        return match (true) {
            in_array($state, ['available', 'no_fix_yet'], true) => null,
            $isClient && ! $consentActive => [
                'tone' => 'warning',
                'note' => 'Location is hidden because '.$whose.' location consent is not active.',
                'title' => 'Record '.$whose.' location consent in Clients',
                'sub' => 'Talk it through with '.$name.' and their whānau first. When consent is recorded and linked to this tracker, the location shows again.'
                    .(($person['record_href'] ?? null) === null ? ' Someone who can record consents in Clients can do this.' : ''),
            ],
            $state === 'permission_required' => [
                'tone' => 'warning',
                'note' => 'Location is hidden because you don’t have permission to see locations.',
                'title' => null,
                'sub' => 'An admin can give it to you in Settings › Roles, if your work needs it.',
            ],
            $state === 'unassigned' => [
                'tone' => 'warning',
                'note' => 'Location is hidden because this tracker isn’t linked to a person or a Site yet.',
                'title' => null,
                'sub' => 'Someone who manages trackers can link it to the person who wears it, with their consent.',
            ],
            $state === 'collection_paused' => [
                'tone' => 'warning',
                'note' => 'Location is hidden because location collection for this staff tracker is paused.',
                'title' => null,
                'sub' => 'It shows again when collection is turned back on for this tracker.',
            ],
            default => [
                'tone' => 'warning',
                'note' => 'Location is hidden because location details are restricted for this person.',
                'title' => null,
                'sub' => 'Only staff who look after this person can see where they are.',
            ],
        };
    }

    /**
     * Battery over the last 7 days from the unit's own reports (battery only; never a position).
     *
     * @return array<string, mixed>|null
     */
    private function batteryTrend(Device $device, CarbonImmutable $now): ?array
    {
        // Every 6 hours, so the graph reads by day.
        $span = 604_800;
        $points = 28;
        $step = intdiv($span, $points);
        $to = $now->utc();
        $from = $to->subSeconds($span);
        try {
            $rows = DB::table('fleet_telemetry_events')
                ->where('device_id', $device->id)
                ->whereNotNull('battery_pct')
                ->whereBetween('occurred_at', [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')])
                ->orderBy('occurred_at')
                ->limit(5000)
                ->get(['battery_pct', 'occurred_at']);
        } catch (Throwable) {
            return null;
        }
        if ($rows->isEmpty()) {
            return null;
        }
        $buckets = [];
        foreach ($rows as $row) {
            $index = intdiv(CarbonImmutable::parse((string) $row->occurred_at, 'UTC')->getTimestamp() - $from->getTimestamp(), $step);
            $buckets[max(0, min($points - 1, $index))][] = (float) $row->battery_pct;
        }
        $values = array_fill(0, $points, null);
        foreach ($buckets as $index => $list) {
            $values[$index] = round(array_sum($list) / count($list), 1);
        }

        return ['start' => $from->addSeconds(intdiv($step, 2))->toIso8601String(), 'step_seconds' => $step, 'points' => $values, 'unit' => '%'];
    }

    /**
     * The consent's own dates, newest first: recorded, a renewal reminder, withdrawn or ended.
     *
     * @return list<array<string, mixed>>
     */
    private function consentHistory(?ClientConsent $linked): array
    {
        if (! $linked instanceof ClientConsent) {
            return [];
        }
        $consents = ClientConsent::query()
            ->where('client_id', $linked->client_id)
            ->where('consent_type_id', $linked->consent_type_id)
            ->latest('given_at')
            ->limit(5)
            ->get(['id', 'status', 'given_at', 'expires_at', 'withdrawn_at', 'renewal_reminder_sent_at', 'given_by_relationship']);

        $rows = [];
        foreach ($consents as $consent) {
            if ($consent->given_at !== null && $consent->status !== 'refused') {
                $by = trim((string) $consent->given_by_relationship);
                $rows[] = [
                    'key' => 'given-'.$consent->id,
                    'at' => $consent->given_at->toIso8601String(),
                    'tone' => 'success',
                    'title' => 'Location consent recorded',
                    'sub' => ($consent->expires_at ? 'Until {date}' : 'No end date').($by !== '' ? ' · given by '.$by : ''),
                    'sub_at' => $consent->expires_at?->toIso8601String(),
                ];
            }
            if ($consent->renewal_reminder_sent_at !== null) {
                $rows[] = [
                    'key' => 'reminder-'.$consent->id,
                    'at' => $consent->renewal_reminder_sent_at->toIso8601String(),
                    'tone' => 'warning',
                    'title' => 'Reminder to renew sent',
                    'sub' => $consent->expires_at ? 'It ends {date}' : null,
                    'sub_at' => $consent->expires_at?->toIso8601String(),
                ];
            }
            if ($consent->withdrawn_at !== null) {
                $rows[] = ['key' => 'withdrawn-'.$consent->id, 'at' => $consent->withdrawn_at->toIso8601String(), 'tone' => 'critical', 'title' => 'Location consent withdrawn', 'sub' => 'Location hidden from then on', 'sub_at' => null];
            } elseif ($consent->expires_at !== null && $consent->expires_at->isPast()) {
                $rows[] = ['key' => 'ended-'.$consent->id, 'at' => $consent->expires_at->toIso8601String(), 'tone' => 'critical', 'title' => 'Location consent ended', 'sub' => 'Location hidden from then on', 'sub_at' => null];
            }
        }

        return collect($rows)->sortByDesc('at')->values()->take(8)->all();
    }

    /**
     * The Console tab (8b): the unit's messages as they arrive (newest last, as a terminal reads)
     * and the commands sent with each one's exact message and the unit's reply (newest first).
     * Every line is redacted by today's console: no password, the IMEI masked, and no coordinate
     * unless the location may be shown.
     *
     * @param  array<string, mixed>|null  $person
     * @return array<string, mixed>
     */
    private function consoleTab(User $viewer, Device $device, ?array $person, bool $shown): array
    {
        $data = $this->console->present($viewer, $device);
        $unit = is_array($data['unit'] ?? null) ? $data['unit'] : null;
        $port = (int) (AppSetting::query()->where('key', 'queclink.listener.port')->value('value') ?: config('services.queclink.port', 8090));
        $summary = is_array(data_get($data, 'settings.summary')) ? data_get($data, 'settings.summary') : [];
        $interval = is_numeric(data_get($summary, 'global.continuous_send_interval_seconds')) ? (int) data_get($summary, 'global.continuous_send_interval_seconds') : null;
        $host = trim((string) data_get($summary, 'server.main_host', ''));
        $serverPort = trim((string) data_get($summary, 'server.main_port', ''));
        $heartbeat = data_get($summary, 'server.heartbeat_interval_minutes');

        $settings = array_values(array_filter([
            $interval !== null ? ['Reports', 'Every '.self::intervalWords($interval)] : null,
            $host !== '' ? ['Server', $host.($serverPort !== '' ? ' · port '.$serverPort : '')] : null,
            is_numeric($heartbeat) ? ['Heartbeat', 'Every '.self::intervalWords((int) $heartbeat * 60)] : null,
            ($firmware = data_get($summary, 'firmware.firmware_version')) ? ['Firmware', (string) $firmware] : null,
        ]));

        return [
            'host' => implode(' · ', array_filter([
                (string) $device->name,
                isset($unit['imei_masked']) ? 'IMEI '.$unit['imei_masked'] : null,
                'TCP to Oblivion, port '.$port,
            ])),
            'lines' => collect($data['raw_messages'] ?? [])->reverse()->values()->map(fn (array $frame): array => [
                'id' => (int) $frame['id'],
                'at' => $frame['created_at'] ?? null,
                'out' => ($frame['direction'] ?? '') === QueclinkRawFrame::DIRECTION_OUTBOUND,
                'text' => self::shown((string) ($frame['raw_frame'] ?? '')),
                'note' => self::frameWords($frame),
            ])->all(),
            'commands' => collect($data['recent_commands'] ?? [])->take(10)->map(fn (array $command): array => [
                'id' => (int) $command['id'],
                'at' => $command['created_at'] ?? null,
                'title' => self::commandWords((string) ($command['command_word'] ?? ''), (string) ($command['raw_command'] ?? '')),
                'sub' => self::commandStatus($command),
                'tone' => match ($command['status'] ?? null) {
                    'acked' => 'success',
                    'failed', 'expired' => 'critical',
                    'cancelled' => 'none',
                    default => 'info',
                },
                'message' => self::shown((string) ($command['raw_command'] ?? '')),
                'reply' => is_string($command['ack_response'] ?? null) && $command['ack_response'] !== '' ? self::shown($command['ack_response']) : null,
            ])->values()->all(),
            'settings' => [
                'read_at' => data_get($data, 'settings.received_at'),
                'rows' => $settings,
            ],
            'location_hidden' => ! $shown,
        ];
    }

    /** The redaction markers in plain sight: "••••••" for a password, a word for a hidden position. */
    private static function shown(string $text): string
    {
        return str_replace(['[REDACTED]', '[LOCATION REDACTED]'], ['••••••', '[location hidden]'], $text);
    }

    /** @param array<string, mixed> $frame */
    private static function frameWords(array $frame): ?string
    {
        $type = strtoupper((string) ($frame['frame_type'] ?? ''));
        $word = strtoupper((string) ($frame['command_word'] ?? ''));
        if ($type === QueclinkRawFrame::FRAME_ACK) {
            return $word === 'GTHBD' ? 'heartbeat: still connected' : 'the unit got the command';
        }
        if ($type === QueclinkRawFrame::FRAME_SACK) {
            return 'Oblivion confirmed it got the message';
        }
        if ($type === QueclinkRawFrame::FRAME_AT) {
            return 'command sent to the unit';
        }
        if ($type === QueclinkRawFrame::FRAME_BUFF) {
            return 'a report it kept while out of touch';
        }

        return match ($word) {
            'GTFRI' => 'position report',
            'GTRTL' => 'position now',
            'GTHBD' => 'heartbeat: still connected',
            'GTALM' => 'settings read back',
            'GTINF' => 'status report',
            'GTSOS' => 'SOS button pressed',
            'GTPNA' => 'turned on',
            'GTPFA' => 'turned off',
            'GTBPL' => 'battery low',
            default => null,
        };
    }

    private static function commandWords(string $word, string $raw): string
    {
        $word = strtoupper($word);
        if ($word === 'GTRTO') {
            $fields = explode(',', $raw);

            return match (strtoupper(trim($fields[1] ?? ''))) {
                '1' => 'Ask for position now',
                '2' => 'Ask for its settings',
                '3' => 'Restart the unit',
                'A' => 'Ask for its status',
                default => 'Ask the unit',
            };
        }

        return match ($word) {
            'GTFRI', 'GTCFG' => 'Change its report settings',
            'GTDOG' => 'Change its restart schedule',
            default => 'Send it a setting',
        };
    }

    /** @param array<string, mixed> $command */
    private static function commandStatus(array $command): string
    {
        $who = is_string($command['created_by'] ?? null) && $command['created_by'] !== '' ? $command['created_by'] : 'Oblivion';
        $sent = isset($command['sent_at']) ? CarbonImmutable::parse($command['sent_at']) : null;
        $acked = isset($command['acked_at']) ? CarbonImmutable::parse($command['acked_at']) : null;

        $status = match ($command['status'] ?? null) {
            'queued' => 'waiting for the unit to check in',
            'sent' => 'sent, waiting for the unit’s reply',
            'acked' => $sent !== null && $acked !== null
                ? 'the unit replied in '.self::secondsWords(max(0, $acked->getTimestamp() - $sent->getTimestamp()))
                : 'the unit replied',
            'failed' => 'didn’t go through'.(is_string($command['failed_reason'] ?? null) && $command['failed_reason'] !== '' ? ': '.Str::lower(Str::limit($command['failed_reason'], 80)) : ''),
            'expired' => 'the unit didn’t check in within a day, so it was dropped',
            'cancelled' => 'cancelled',
            default => 'status not known',
        };

        return $who.' · '.$status;
    }

    private static function secondsWords(int $seconds): string
    {
        return match (true) {
            $seconds < 60 => $seconds.' '.($seconds === 1 ? 'second' : 'seconds'),
            $seconds < 3600 => intdiv($seconds, 60).' min',
            default => intdiv($seconds, 3600).' h',
        };
    }

    /** @param array<string, mixed>|null $snapshot */
    private static function intervalSeconds(?array $snapshot): ?int
    {
        if (! (bool) ($snapshot['available'] ?? false)) {
            return null;
        }
        $value = data_get($snapshot, 'summary.global.continuous_send_interval_seconds');

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function intervalWords(int $seconds): string
    {
        return match (true) {
            $seconds < 60 => $seconds.' seconds',
            $seconds === 60 => 'minute',
            $seconds < 3600 => (int) round($seconds / 60).' min',
            $seconds === 3600 => 'hour',
            default => (int) round($seconds / 3600).' hours',
        };
    }

    private static function maskImei(?string $imei): ?string
    {
        $trimmed = trim((string) $imei);
        if ($trimmed === '') {
            return null;
        }

        return strlen($trimmed) <= 8 ? $trimmed : substr($trimmed, 0, 4).'•••••••'.substr($trimmed, -4);
    }
}
