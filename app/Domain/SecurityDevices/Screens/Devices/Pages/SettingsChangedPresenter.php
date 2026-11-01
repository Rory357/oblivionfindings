<?php

namespace App\Domain\SecurityDevices\Screens\Devices\Pages;

use App\Domain\Monitoring\Models\ConfigurationSnapshot;
use App\Domain\Monitoring\Models\MonitoringRetentionPolicy;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\Monitoring\Services\ConfigurationSnapshotService;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\CommandCapabilityRegistry;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\NetworkConfig\Services\NightlySettingsBackups;
use App\Domain\SecurityDevices\NetworkConfig\Services\SettingsBackupLines;
use App\Domain\SecurityDevices\NetworkConfig\Services\SettingsLineDiff;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Domain\SecurityDevices\Terminal\Models\TerminalSession;
use App\Models\User;
use Throwable;
use UnexpectedValueException;

/**
 * Pop-up 24, "Settings changed" (S&D Phase G): two backups of a device's settings side by side, with
 * the changed lines picked out. Secrets stay redacted exactly as the snapshot service stored them;
 * the lines only come through its governed retrieve(). "Changed by" says only what a record shows:
 * a change someone asked for through Oblivion, or that someone used the Oblivion terminal on it.
 * Otherwise it says it isn't known.
 */
final class SettingsChangedPresenter
{
    /** Governed actions that change a network device's settings (a backup or a port restart doesn't). */
    private const array CHANGES_SETTINGS = [
        'network.port.enable', 'network.port.disable', 'network.firewall_policy.apply', 'configuration.apply', 'firmware.update',
    ];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly CanonicalDeviceSiteResolver $sites,
        private readonly ConfigurationSnapshotService $snapshots,
        private readonly SettingsBackupLines $lines,
        private readonly SettingsLineDiff $diff,
        private readonly CommandCapabilityRegistry $capabilities,
        private readonly NightlySettingsBackups $nightly,
    ) {}

    /**
     * The device, the copy and the Site it was taken at are checked before anything is read: another
     * Site's device, or a copy from where the device used to be, is not found (404).
     */
    public function assertCopy(User $viewer, Device $device, ConfigurationSnapshot $snapshot): int
    {
        $this->access->assertCanViewDevice($viewer, $device);
        try {
            $siteId = $this->sites->resolve((int) $device->id);
        } catch (UnexpectedValueException) {
            abort(404);
        }
        abort_unless((int) $snapshot->device_id === (int) $device->id && (int) $snapshot->site_id === $siteId, 404);
        abort_unless($this->snapshots->canViewSnapshots($viewer), 403);

        return $siteId;
    }

    /** @return array<string, mixed> */
    public function present(User $viewer, Device $device, ConfigurationSnapshot $snapshot): array
    {
        $siteId = $this->assertCopy($viewer, $device, $snapshot);
        abort_unless(SettingsBackupLines::readable($snapshot), 404);
        $baseline = $this->baseline($device, $snapshot, $siteId);

        $after = $this->lines->forViewer($snapshot, $viewer);
        $before = $baseline === null ? [] : $this->lines->forViewer($baseline, $viewer);
        $compared = $baseline === null
            ? ['lines' => null, 'added' => null, 'removed' => null, 'too_many' => false, 'rows' => [], 'truncated' => false]
            : $this->diff->compare($before, $after);

        return [
            'device' => [
                'id' => (int) $device->id,
                'name' => (string) $device->name,
                'model' => trim(trim((string) $device->manufacturer).' '.trim((string) $device->model)) ?: null,
            ],
            'before' => $baseline === null ? null : $this->copy($baseline),
            'after' => $this->copy($snapshot),
            'lines' => $compared['lines'],
            'added' => $compared['added'],
            'removed' => $compared['removed'],
            'too_many' => $compared['too_many'],
            'rows' => $compared['rows'],
            'truncated' => $compared['truncated'],
            'changed_by' => $baseline === null ? null : $this->changedBy($viewer, $device, $baseline, $snapshot),
            'kept' => $this->kept($snapshot),
            'schedule' => $this->nightly->scheduleWords(),
            'download_href' => route('security-devices.devices.settings-backups.download', ['device' => $device->id, 'snapshot' => $snapshot->id], false),
        ];
    }

    /**
     * Both copies as one text file, redacted as stored, for "Download both".
     *
     * @return array{name: string, body: string}
     */
    public function download(User $viewer, Device $device, ConfigurationSnapshot $snapshot): array
    {
        $siteId = $this->assertCopy($viewer, $device, $snapshot);
        abort_unless(SettingsBackupLines::readable($snapshot), 404);
        $baseline = $this->baseline($device, $snapshot, $siteId);
        $section = fn (string $label, ConfigurationSnapshot $copy): string => "# {$label}: {$device->name}, backed up "
            .$copy->captured_at?->setTimezone('Pacific/Auckland')->format('j M Y, g:i a')
            ." (NZ time). Passwords and keys were removed when it was stored.\n"
            .implode("\n", $this->lines->forViewer($copy, $viewer))."\n";
        $body = ($baseline === null ? '' : $section('Before', $baseline)."\n").$section($baseline === null ? 'Backup' : 'After', $snapshot);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower((string) $device->name)), '-') ?: 'device-'.$device->id;

        return ['name' => $slug.'-settings-'.$snapshot->captured_at?->setTimezone('Pacific/Auckland')->format('Y-m-d').'.txt', 'body' => $body];
    }

    /** The copy before this one at the same Site: the one it was compared with when stored, or the latest earlier one. */
    private function baseline(Device $device, ConfigurationSnapshot $snapshot, int $siteId): ?ConfigurationSnapshot
    {
        $readable = fn ($query) => $query->where('device_id', $device->id)->where('site_id', $siteId)
            ->where('storage_state', 'available')->whereNull('payload_deleted_at');
        if ($snapshot->previous_snapshot_id !== null) {
            $linked = $readable(ConfigurationSnapshot::query()->whereKey($snapshot->previous_snapshot_id))->first();
            if ($linked instanceof ConfigurationSnapshot) {
                return $linked;
            }
        }

        return $readable(ConfigurationSnapshot::query())
            ->whereKeyNot($snapshot->id)
            ->where(fn ($query) => $query->where('captured_at', '<', $snapshot->captured_at)
                ->orWhere(fn ($same) => $same->where('captured_at', $snapshot->captured_at)->where('id', '<', $snapshot->id)))
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->first();
    }

    /** @return array{id: int, at: ?string, how: string} */
    private function copy(ConfigurationSnapshot $snapshot): array
    {
        return [
            'id' => (int) $snapshot->id,
            'at' => $snapshot->captured_at?->toIso8601String(),
            'how' => match ($snapshot->source_kind) {
                'command' => 'Settings backup',
                'provider' => 'Read from the maker’s service',
                'ssh', 'winrm' => 'Read-only check',
                default => 'Stored copy',
            },
        ];
    }

    /**
     * What a record says about who changed it between the two copies. Terminal use is only named to
     * people who may see the terminal's history themselves.
     *
     * @return array{known: bool, changes: list<array{who: string, what: string, at: ?string}>, terminal: list<array{who: string, at: ?string}>|null}
     */
    private function changedBy(User $viewer, Device $device, ConfigurationSnapshot $before, ConfigurationSnapshot $after): array
    {
        $from = $before->captured_at;
        $to = $after->captured_at;
        $changes = DeviceCommandRequest::query()
            ->with('requestedBy:id,name')
            ->where('device_id', $device->id)
            ->whereIn('capability', self::CHANGES_SETTINGS)
            ->whereIn('status', [CommandStatus::Succeeded->value, CommandStatus::Reconciling->value, CommandStatus::Reconciled->value])
            ->where('created_at', '>', $from)
            ->where('created_at', '<=', $to)
            ->orderBy('created_at')
            ->limit(10)
            ->get()
            ->map(fn (DeviceCommandRequest $request): array => [
                'who' => (string) ($request->requestedBy?->name ?? 'Someone'),
                'what' => $this->label((string) $request->capability),
                'at' => ($request->execution_completed_at ?? $request->created_at)?->toIso8601String(),
            ])
            ->values()
            ->all();

        $terminal = null;
        if ($viewer->canDo('securityDevices.terminal.use')) {
            $terminal = TerminalSession::query()
                ->with('user:id,name')
                ->where('device_id', $device->id)
                ->whereNotNull('opened_at')
                ->where('opened_at', '<=', $to)
                ->where(fn ($query) => $query->whereNull('closed_at')->orWhere('closed_at', '>', $from))
                ->orderBy('opened_at')
                ->limit(10)
                ->get()
                ->map(fn (TerminalSession $session): array => [
                    'who' => (string) ($session->user?->name ?? 'Someone'),
                    'at' => $session->opened_at?->toIso8601String(),
                ])
                ->values()
                ->all();
        }

        return ['known' => $changes !== [], 'changes' => $changes, 'terminal' => $terminal];
    }

    private function label(string $capability): string
    {
        try {
            return $this->capabilities->definition($capability)->label;
        } catch (Throwable) {
            return 'A settings change';
        }
    }

    /** How long the copy is kept, from the retention rule that covers it, or null when no rule ends it. */
    private function kept(ConfigurationSnapshot $snapshot): ?string
    {
        $policies = MonitoringRetentionPolicy::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->where('scope_kind', 'application')
                ->orWhere(fn ($site) => $site->where('scope_kind', 'site')->where('site_id', $snapshot->site_id))
                ->orWhere(fn ($device) => $device->where('scope_kind', 'device')->where('device_id', $snapshot->device_id))
                ->orWhere(fn ($class) => $class->where('scope_kind', 'data_class')->where('data_class', 'configuration')))
            ->get(['id', 'daily_days', 'legal_hold']);
        if ($policies->contains('legal_hold', true)) {
            return 'kept while on hold';
        }
        $days = $snapshot->retention_policy_id !== null
            ? $policies->firstWhere('id', $snapshot->retention_policy_id)?->daily_days
            : $policies->min('daily_days');
        if (! is_int($days) || $days < 1) {
            return null;
        }
        if ($days % 365 === 0) {
            $years = intdiv($days, 365);

            return $years === 1 ? 'kept for 12 months' : "kept for {$years} years";
        }
        if ($days % 30 === 0) {
            $months = intdiv($days, 30);

            return 'kept for '.$months.' '.($months === 1 ? 'month' : 'months');
        }

        return "kept for {$days} days";
    }
}
