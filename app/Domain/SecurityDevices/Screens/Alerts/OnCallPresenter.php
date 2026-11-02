<?php

namespace App\Domain\SecurityDevices\Screens\Alerts;

use App\Domain\SecurityDevices\Alerts\DeviceAlertEngine;
use App\Domain\SecurityDevices\Alerts\ItOnCall;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlert;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlertEvent;
use App\Domain\SecurityDevices\Alerts\Models\OnCallHandover;
use App\Domain\SecurityDevices\Setup\SecurityDevicesSettings;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Alerts › On call (S&D drawing pg-sdr-13d): who covers IT now and next (Rostering's on-call shifts),
 * the latest handover note, what is waiting on the person on call, and how each kind of IT alert
 * reaches them, as Setup › Alerts & checks sets it. Safety alerts aren't part of this.
 */
final class OnCallPresenter
{
    /** The IT alerts that go to the person on call first. */
    public const array KINDS = DeviceAlertEngine::ON_CALL_KINDS;

    public function __construct(
        private readonly ItOnCall $onCall,
        private readonly SecurityDevicesSettings $settings,
    ) {}

    /**
     * @param  Builder<DeviceAlert>  $alerts  the viewer's alerts (their Sites' devices)
     * @return array<string, mixed>
     */
    public function present(User $viewer, Builder $alerts, CarbonImmutable $now): array
    {
        $shifts = $this->onCall->shifts($now->subDays(7), $now->addDays(14));
        $current = $shifts->first(fn (Shift $shift): bool => $shift->starts_at <= $now && $shift->ends_at > $now);
        $later = $shifts->filter(fn (Shift $shift): bool => $shift->starts_at > $now)->values();
        $earlier = $shifts->filter(fn (Shift $shift): bool => $shift->ends_at <= $now)->sortByDesc('ends_at')->values();
        $people = collect([$current, $later->first(), $later->get(1), $current === null ? $earlier->first() : null])
            ->filter()
            ->unique('id')
            ->take(3)
            ->map(fn (Shift $shift): array => [
                'id' => (int) $shift->id,
                'name' => (string) $shift->staff?->name,
                'role' => $this->roleLabel($shift->staff),
                'when' => $shift === $current ? 'now' : ($shift->starts_at > $now ? 'next' : 'before'),
                'from' => CarbonImmutable::parse($shift->starts_at)->utc()->toIso8601String(),
                'to' => CarbonImmutable::parse($shift->ends_at)->utc()->toIso8601String(),
            ])
            ->values()
            ->all();

        $roleName = $this->settings->role(SecurityDevicesSettings::DEVICES_ROLE);
        $readsNotes = $viewer->canDo('securityDevices.alerts.acknowledge');

        return [
            'now' => $current === null ? null : [
                'name' => (string) $current->staff?->name,
                'first_name' => (string) explode(' ', trim((string) $current->staff?->name))[0],
                'until' => CarbonImmutable::parse($current->ends_at)->utc()->toIso8601String(),
            ],
            'people' => $people,
            'role' => $roleName === null ? null : (string) (Role::query()->where('name', $roleName)->value('label') ?: $roleName),
            'handover' => $readsNotes ? $this->handover() : null,
            'waiting' => $this->waiting($alerts),
            'delivery' => $this->delivery(),
            'can' => [
                'rostering' => $viewer->canDo('rostering.viewAny'),
                'handover' => $readsNotes && Schema::hasTable('security_device_on_call_handovers'),
                'setup' => $viewer->canDo('securityDevices.monitoring.manage'),
            ],
        ];
    }

    private function roleLabel(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }
        $user->loadMissing('roles:id,name,label');
        $role = $user->roles->first();

        return $role === null ? null : (string) ($role->label ?: ucfirst(str_replace('_', ' ', (string) $role->name)));
    }

    /** @return array{from: string, to: string|null, at: string, still_open: string|null, coming_up: string|null, good_to_know: string|null}|null */
    private function handover(): ?array
    {
        if (! Schema::hasTable('security_device_on_call_handovers')) {
            return null;
        }
        $note = OnCallHandover::query()->with(['from:id,name', 'to:id,name'])->latest('id')->first();

        return $note === null ? null : [
            'from' => (string) $note->from?->name,
            'to' => $note->to?->name,
            'at' => CarbonImmutable::parse($note->created_at)->utc()->toIso8601String(),
            'still_open' => $note->still_open,
            'coming_up' => $note->coming_up,
            'good_to_know' => $note->good_to_know,
        ];
    }

    /**
     * Open IT alerts nobody has acknowledged yet, newest first, with when people were told and reminded.
     *
     * @param  Builder<DeviceAlert>  $alerts
     * @return list<array{id: int, at: string, tone: string, title: string, told_at: string|null, reminded_at: string|null}>
     */
    private function waiting(Builder $alerts): array
    {
        $open = (clone $alerts)
            ->where('state', DeviceAlert::STATE_OPEN)
            ->whereIn('kind', self::KINDS)
            ->whereNull('acknowledged_at')
            ->orderByDesc('opened_at')
            ->limit(8)
            ->get(['id', 'title', 'severity', 'opened_at', 'reminded_at']);
        $told = $open->isEmpty() ? collect() : DeviceAlertEvent::query()
            ->whereIn('alert_id', $open->pluck('id'))
            ->where('kind', 'told')
            ->orderBy('at')
            ->get(['alert_id', 'at'])
            ->groupBy('alert_id');

        return $open->map(fn (DeviceAlert $alert): array => [
            'id' => (int) $alert->id,
            'at' => $alert->opened_at->toIso8601String(),
            'tone' => $alert->severity === 'critical' ? 'critical' : 'warning',
            'title' => (string) $alert->title,
            'told_at' => ($at = $told->get($alert->id)?->first()?->at) === null ? null : CarbonImmutable::parse($at)->utc()->toIso8601String(),
            'reminded_at' => $alert->reminded_at === null ? null : CarbonImmutable::parse($alert->reminded_at)->utc()->toIso8601String(),
        ])->values()->all();
    }

    /** The same switches as Setup › Alerts & checks. Text messages need a provider first (D13). @return list<array<string, mixed>> */
    private function delivery(): array
    {
        $on = fn (string $key): bool => $this->settings->bool($key);

        return [
            [
                'key' => 'devices',
                'when' => 'A device or hub stops answering',
                'sub' => 'Or a check fails · everyone in the IT role is reminded after an hour if nobody acknowledges',
                'app' => $on(SecurityDevicesSettings::DEVICES_APP), 'email' => $on(SecurityDevicesSettings::DEVICES_EMAIL), 'push' => $on(SecurityDevicesSettings::DEVICES_PUSH),
            ],
            [
                'key' => 'logins',
                'when' => 'Wrong sensor logins at a hub',
                'sub' => '5 in 10 minutes from one address',
                'app' => $on(SecurityDevicesSettings::DEVICES_APP), 'email' => $on(SecurityDevicesSettings::DEVICES_EMAIL), 'push' => $on(SecurityDevicesSettings::DEVICES_PUSH),
            ],
            [
                'key' => 'found',
                'when' => 'New device found by a scan',
                'sub' => 'With Add or Ignore',
                'app' => $on(SecurityDevicesSettings::NEW_DEVICES_APP), 'email' => $on(SecurityDevicesSettings::NEW_DEVICES_EMAIL), 'push' => $on(SecurityDevicesSettings::NEW_DEVICES_PUSH),
            ],
        ];
    }
}
