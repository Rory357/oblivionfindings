<?php

namespace App\Domain\SecurityDevices\Screens\Devices\Pages;

use App\Domain\Monitoring\Models\ConfigurationSnapshot;
use App\Domain\Monitoring\Services\ConfigurationSnapshotService;
use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\CommandCapabilityRegistry;
use App\Domain\SecurityDevices\Management\Services\CommandExecutionRouteResolver;
use App\Domain\SecurityDevices\Management\Services\DeclaredDeviceCommandCapabilities;
use App\Domain\SecurityDevices\Management\Services\DeviceManagementAuthorizationService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\NetworkConfig\Services\SettingsBackupLines;
use App\Domain\SecurityDevices\NetworkConfig\Services\SshHostKeyTrust;
use App\Models\User;
use DomainException;

/**
 * A network device's settings backups: the stored copies (only those taken at its current Site)
 * and "Back up settings now", which is the governed network.config.backup request. When a hub
 * covers the Site the backup runs there and nowhere else (Phase B); when it can't, the route's
 * reason is shown in plain words. The SSH key notice goes wherever a backup is offered.
 */
final class DeviceSettingsBackups
{
    public const string CAPABILITY = 'network.config.backup';

    private const array WAITING = ['requested', 'awaiting_step_up', 'awaiting_approval', 'awaiting_change', 'ready', 'queued', 'dispatching', 'accepted', 'running', 'reconciling'];

    public function __construct(
        private readonly ConfigurationSnapshotService $snapshots,
        private readonly DeclaredDeviceCommandCapabilities $declared,
        private readonly CommandCapabilityRegistry $capabilities,
        private readonly DeviceManagementAuthorizationService $authorization,
        private readonly CommandExecutionRouteResolver $routes,
        private readonly SshHostKeyTrust $hostKeys,
        private readonly SettingsBackupLines $lines,
    ) {}

    /**
     * Stored copies, newest first. Each says how many lines changed from the one before.
     *
     * @return list<array<string, mixed>>
     */
    public function history(User $viewer, Device $device, ?int $siteId, int $limit = 12): array
    {
        if ($siteId === null) {
            return [];
        }
        $canOpen = $this->snapshots->canViewSnapshots($viewer);

        $copies = ConfigurationSnapshot::query()
            ->where('device_id', $device->id)
            ->where('site_id', $siteId)
            ->latest('captured_at')
            ->latest('id')
            ->limit($limit)
            ->get();
        // S&D Phase G: "N lines changed" is a real line count against the copy before (pop-up 24).
        $previousIds = $copies->pluck('previous_snapshot_id')->filter()->unique()->values();
        $previous = $previousIds->isEmpty() ? collect() : ConfigurationSnapshot::query()
            ->whereIn('id', $previousIds)
            ->where('device_id', $device->id)
            ->where('site_id', $siteId)
            ->get()
            ->keyBy('id');

        return $copies
            ->map(function (ConfigurationSnapshot $snapshot) use ($canOpen, $previous): array {
                $diff = is_array($snapshot->diff_summary) ? $snapshot->diff_summary : [];
                $changed = collect(['added', 'removed', 'changed'])
                    ->sum(fn (string $key): int => count(array_filter((array) ($diff[$key] ?? []), 'is_string')));
                $before = $snapshot->previous_snapshot_id === null ? null : $previous->get($snapshot->previous_snapshot_id);
                $lines = $before instanceof ConfigurationSnapshot ? $this->lines->changedLines($before, $snapshot) : null;
                if ($lines !== null && ! $lines['too_many'] && $lines['lines'] !== null) {
                    $changed = $lines['lines'];
                }

                return [
                    'id' => (int) $snapshot->id,
                    'at' => $snapshot->captured_at?->toIso8601String(),
                    'changes' => $snapshot->previous_snapshot_id === null ? null : $changed,
                    'first' => $snapshot->previous_snapshot_id === null,
                    'compare' => $canOpen && $snapshot->storage_state === 'available' && $snapshot->previous_snapshot_id !== null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * "Back up settings now", or null when the device can't be backed up or the viewer may not
     * know about it.
     *
     * @return array<string, mixed>|null
     */
    public function action(User $viewer, Device $device, ?int $siteId): ?array
    {
        if (! $this->declared->supports($device, self::CAPABILITY)) {
            return null;
        }
        try {
            $definition = $this->capabilities->definition(self::CAPABILITY);
        } catch (DomainException) {
            return null;
        }
        $this->authorization->resetMemoizedState();
        $decision = $this->authorization->evaluate($viewer, $device, $definition);
        if (! $decision->allowed && $decision->concealed) {
            return null;
        }

        $route = $siteId === null ? null : $this->routes->resolve($device, $siteId, self::CAPABILITY);
        $stateAllowed = in_array($device->status?->value, $definition->allowedCurrentStates, true);
        $via = $route?->mode === 'collector' && $route->collector !== null
            ? CoveringHubResolver::label($route->collector)
            : null;

        $reason = match (true) {
            ! $decision->allowed => 'You can’t ask for a settings backup on this device.',
            $siteId === null => 'Put it at a Site first, so Oblivion knows which hub reaches it.',
            ! $route->available => $this->plainRouteReason($route->reason),
            ! $stateAllowed => 'It can’t be backed up while it’s marked as '.mb_strtolower((string) $device->status?->label()).'.',
            default => null,
        };

        $pending = DeviceCommandRequest::query()
            ->where('device_id', $device->id)
            ->where('capability', self::CAPABILITY)
            ->latest('id')
            ->first(['id', 'status', 'created_at', 'requested_by_user_id', 'safe_failure_reason']);

        return [
            'available' => $reason === null,
            'reason' => $reason,
            'via' => $via ?? ($route?->mode === 'central' ? 'the Oblivion server' : null),
            'ssh_key' => [
                ...$this->hostKeys->present($viewer, $device),
                'trust_url' => route('security-devices.devices.ssh-host-key.trust', ['device' => $device->id], false),
            ],
            'last_request' => $pending === null ? null : [
                'id' => (int) $pending->id,
                'status' => $pending->status->value,
                'label' => $this->statusWords($pending->status),
                'waiting' => in_array($pending->status->value, self::WAITING, true),
                'at' => $pending->created_at?->toIso8601String(),
                'mine' => (int) $pending->requested_by_user_id === (int) $viewer->id,
                'can_run' => $pending->status === CommandStatus::Ready
                    && ((int) $pending->requested_by_user_id === (int) $viewer->id || $viewer->canDo('securityDevices.commands.admin')),
            ],
        ];
    }

    /** The resolver's hub reasons are already plain words; the older ones are not. */
    private function plainRouteReason(string $reason): string
    {
        if (str_contains($reason, 'hub') && ! str_contains($reason, 'collector')) {
            return $reason;
        }

        return 'Oblivion can’t reach it to back up its settings yet. It needs an approved address, a saved login and a supported model.';
    }

    private function statusWords(CommandStatus $status): string
    {
        return match ($status) {
            CommandStatus::Requested, CommandStatus::AwaitingStepUp => 'Asked for',
            CommandStatus::AwaitingApproval => 'Waiting for approval',
            CommandStatus::AwaitingChange => 'Waiting for an approved change',
            CommandStatus::Ready => 'Ready to run',
            CommandStatus::Queued, CommandStatus::Dispatching, CommandStatus::Accepted => 'Waiting to run',
            CommandStatus::Running, CommandStatus::Reconciling => 'Running',
            CommandStatus::Succeeded, CommandStatus::Reconciled => 'Backed up',
            CommandStatus::Failed, CommandStatus::Mismatch => 'Didn’t work',
            CommandStatus::Rejected => 'Turned down',
            CommandStatus::Expired => 'Ran out of time',
            CommandStatus::Cancelled => 'Cancelled',
            CommandStatus::Blocked => 'Stopped by a safety check',
            CommandStatus::Uncertain => 'Result not confirmed',
        };
    }

    /**
     * The newest copy, for the header's Settings number.
     *
     * @param  list<array<string, mixed>>  $history
     * @return array{at: ?string, changes: ?int}|null
     */
    public static function latest(array $history): ?array
    {
        $first = $history[0] ?? null;

        return $first === null ? null : ['at' => $first['at'], 'changes' => $first['changes']];
    }
}
