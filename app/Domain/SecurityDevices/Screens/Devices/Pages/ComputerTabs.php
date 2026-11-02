<?php

namespace App\Domain\SecurityDevices\Screens\Devices\Pages;

use App\Domain\SecurityDevices\Endpoints\Models\EndpointRemoteSession;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointRemoteSupportSettings;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A computer's page parts (drawings pg-sdr-9, 9c, 9b), read from what Obli sends today: Windows
 * version, CPU, memory, disks, service states and installed programs. Readings Obli can't take yet
 * (updates waiting, BitLocker, backups, start-up time) say so; they are never guessed.
 */
final class ComputerTabs
{
    /** Windows services behind the safety checks. */
    private const array SAFETY_SERVICES = [
        'defender' => ['WinDefend'],
        'firewall' => ['MpsSvc'],
        'updates' => ['wuauserv'],
        'bits' => ['BITS'],
    ];

    /**
     * The safety checks Obli can read from service states, and the ones it can't read yet.
     *
     * @param  array<string, mixed>|null  $inventory
     * @return list<array{label: string, state: string, note: ?string}>
     */
    public function safety(?array $inventory): array
    {
        $defender = $this->serviceState($inventory, 'defender');
        $firewall = $this->serviceState($inventory, 'firewall');

        return [
            ['label' => 'Antivirus on', 'state' => $this->tone($defender), 'note' => $this->note('Microsoft Defender', $defender)],
            ['label' => 'Firewall on', 'state' => $this->tone($firewall), 'note' => $this->note('Windows Firewall', $firewall)],
            ['label' => 'Drive encrypted', 'state' => 'none', 'note' => 'BitLocker · not available'],
            ['label' => 'Files backed up', 'state' => 'none', 'note' => 'Not available'],
        ];
    }

    /**
     * The Health tab's safety cards.
     *
     * @param  array<string, mixed>|null  $inventory
     * @return list<array<string, mixed>>
     */
    public function safetyCards(?array $inventory): array
    {
        $updates = $this->serviceState($inventory, 'updates');
        $bits = $this->serviceState($inventory, 'bits');
        $defender = $this->serviceState($inventory, 'defender');
        $firewall = $this->serviceState($inventory, 'firewall');

        return [
            [
                'name' => 'Windows updates',
                'sub' => 'Windows Update and background downloads',
                'flag' => $this->flag($updates),
                'facts' => [
                    ['tone' => $this->tone($updates), 'text' => 'Windows Update · '.$this->stateWords($updates)],
                    ['tone' => $this->tone($bits), 'text' => 'Background downloads (BITS) · '.$this->stateWords($bits)],
                    ['tone' => 'none', 'text' => 'Updates waiting and restart needed: Not available, Obli can’t read them yet'],
                ],
            ],
            [
                'name' => 'Microsoft Defender',
                'sub' => 'Antivirus',
                'flag' => $this->flag($defender),
                'facts' => [
                    ['tone' => $this->tone($defender), 'text' => 'Service · '.$this->stateWords($defender)],
                    ['tone' => 'none', 'text' => 'Scans and virus definitions: Not available, Obli can’t read them yet'],
                ],
            ],
            [
                'name' => 'Firewall',
                'sub' => 'Windows Firewall',
                'flag' => $this->flag($firewall),
                'facts' => [
                    ['tone' => $this->tone($firewall), 'text' => 'Service · '.$this->stateWords($firewall)],
                ],
            ],
            [
                'name' => 'Drive encryption',
                'sub' => 'BitLocker',
                'flag' => ['label' => 'Not available', 'tone' => 'neutral'],
                'facts' => [
                    ['tone' => 'none', 'text' => 'Obli can’t read BitLocker yet'],
                ],
            ],
        ];
    }

    /**
     * Remote support on this computer: whether it's ready, and every past session.
     *
     * @param  array<string, mixed>  $posture
     * @return array<string, mixed>
     */
    public function remoteSupport(Device $device, array $posture): array
    {
        $agentState = (string) data_get($posture, 'agent.state', 'not_enrolled');
        $policy = Schema::hasTable('endpoint_remote_policies')
            ? DB::table('endpoint_remote_policies')->where('device_id', $device->id)->first(['enabled', 'consent_mode', 'allow_control', 'max_minutes'])
            : null;
        $turnedOn = app(EndpointRemoteSupportSettings::class)->endpointEnabled() && (bool) ($policy->enabled ?? false);

        $sessions = Schema::hasTable('endpoint_remote_sessions')
            ? EndpointRemoteSession::query()
                ->where('device_id', $device->id)
                ->latest('id')
                ->limit(20)
                ->get(['id', 'actor_user_id', 'purpose', 'status', 'allowed_at', 'started_at', 'ended_at', 'created_at'])
            : collect();
        $names = $sessions->isEmpty()
            ? collect()
            : User::query()->whereIn('id', $sessions->pluck('actor_user_id')->unique())->pluck('name', 'id');

        return [
            'ready' => [
                ['label' => 'PC online', 'state' => $agentState === 'fresh' ? 'success' : 'none', 'note' => $agentState === 'fresh' ? null : 'Obli hasn’t been heard from recently'],
                ['label' => 'Obli '.(data_get($posture, 'agent.agentVersion') ?? '').' running', 'state' => in_array($agentState, ['fresh', 'stale'], true) ? 'success' : 'none', 'note' => null],
                ['label' => 'Remote support turned on for this PC', 'state' => $turnedOn ? 'success' : 'none', 'note' => $turnedOn ? null : 'Turned on by IT in Setup'],
            ],
            'asked_every_time' => ($policy->consent_mode ?? 'attended') === 'attended',
            'can_control' => (bool) ($policy->allow_control ?? false),
            'max_minutes' => $policy?->max_minutes === null ? null : (int) $policy->max_minutes,
            'start_href' => $turnedOn ? data_get($posture, 'links.remoteSupportHref') : null,
            'sessions' => $sessions->map(fn (EndpointRemoteSession $session): array => [
                'key' => 'session-'.$session->id,
                'at' => ($session->started_at ?? $session->created_at)?->toIso8601String(),
                'who' => (string) ($names[$session->actor_user_id] ?? 'Someone from IT'),
                'outcome' => match ($session->status) {
                    'declined' => 'Declined at the PC',
                    'expired' => $session->allowed_at === null ? 'Nobody answered' : 'Ended',
                    'requested', 'offered' => 'Waiting for Allow',
                    default => $session->allowed_at !== null ? 'Allowed at the PC' : 'Ended',
                },
                'tone' => match ($session->status) {
                    'declined', 'expired' => 'none',
                    'requested', 'offered' => 'info',
                    default => 'success',
                },
                'minutes' => $session->started_at !== null && $session->ended_at !== null
                    ? max(1, (int) round(abs($session->started_at->diffInSeconds($session->ended_at)) / 60))
                    : null,
                'why' => trim((string) $session->purpose) !== '' ? mb_strimwidth((string) $session->purpose, 0, 140, '…') : null,
            ])->values()->all(),
        ];
    }

    /**
     * The disk Windows runs from (C:), else the first.
     *
     * @param  array<string, mixed>|null  $inventory
     * @return array{name: string, used_percent: ?float, used: int, total: int}|null
     */
    public function systemDisk(?array $inventory): ?array
    {
        $disks = collect($inventory['disks'] ?? []);
        $disk = $disks->first(fn (array $row): bool => str_starts_with(strtoupper((string) $row['name']), 'C')) ?? $disks->first();
        if (! is_array($disk) || (int) $disk['totalBytes'] <= 0) {
            return null;
        }

        return [
            'name' => (string) $disk['name'],
            'used_percent' => $disk['usedPercent'] === null ? null : (float) $disk['usedPercent'],
            'used' => (int) $disk['usedBytes'],
            'total' => (int) $disk['totalBytes'],
        ];
    }

    /**
     * When it last started, from the uptime Obli sent with its reading.
     *
     * @param  array<string, mixed>|null  $inventory
     */
    public function lastRestart(?array $inventory, ?string $observedAt): ?string
    {
        $uptime = $inventory['uptimeSeconds'] ?? null;
        if (! is_int($uptime) || $uptime <= 0 || $observedAt === null) {
            return null;
        }

        return CarbonImmutable::parse($observedAt)->subSeconds($uptime)->toIso8601String();
    }

    /**
     * The approved services Obli watches, with their states.
     *
     * @param  array<string, mixed>|null  $inventory
     * @return list<array{label: string, state: string, note: string}>
     */
    public function services(?array $inventory): array
    {
        return collect($inventory['services'] ?? [])
            ->filter(fn (array $service): bool => (bool) ($service['approved'] ?? false))
            ->map(fn (array $service): array => [
                'label' => (string) ($service['label'] ?? $service['name']),
                'state' => $this->tone(strtolower((string) $service['state'])),
                'note' => $this->stateWords(strtolower((string) $service['state'])),
            ])
            ->values()
            ->all();
    }

    /** @param array<string, mixed>|null $inventory */
    private function serviceState(?array $inventory, string $key): ?string
    {
        if ($inventory === null) {
            return null;
        }
        $names = array_map('strtolower', self::SAFETY_SERVICES[$key]);
        foreach ($inventory['services'] ?? [] as $service) {
            if (in_array(strtolower((string) ($service['name'] ?? '')), $names, true)) {
                return strtolower((string) $service['state']);
            }
        }

        return null;
    }

    private function tone(?string $state): string
    {
        return match (true) {
            $state === null => 'none',
            str_contains($state, 'running') => 'success',
            str_contains($state, 'stopped') => 'critical',
            default => 'warning',
        };
    }

    private function stateWords(?string $state): string
    {
        return match (true) {
            $state === null => 'Not available',
            str_contains($state, 'running') => 'Running',
            str_contains($state, 'stopped') => 'Stopped',
            str_contains($state, 'pending') => 'Starting or stopping',
            default => DevicePageWords::sentence($state),
        };
    }

    private function note(string $name, ?string $state): string
    {
        return $name.' · '.mb_strtolower($this->stateWords($state));
    }

    /** @return array{label: string, tone: string} */
    private function flag(?string $state): array
    {
        return match ($this->tone($state)) {
            'success' => ['label' => 'On', 'tone' => 'success'],
            'critical' => ['label' => 'Off', 'tone' => 'critical'],
            'none' => ['label' => 'Not available', 'tone' => 'neutral'],
            default => ['label' => 'Check it', 'tone' => 'warning'],
        };
    }
}
