<?php

namespace App\Domain\SecurityDevices\Alerts;

use App\Domain\SecurityDevices\Setup\SecurityDevicesSettings;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * IT on call (S&D drawing pg-sdr-13d): who covers IT, read from the on-call shifts in Rostering. A shift
 * counts when it's marked on call (the flag or the on-call shift type), it isn't a draft or cancelled, it is
 * published where Rostering publishes, and its person may look after IT devices (and is in the IT role
 * chosen in Setup › Alerts & checks, when one is chosen).
 *
 * Device and hub alerts tell the person on call first; everyone else in the IT role hears at the
 * one-hour reminder, as before. Safety alerts (panic, alarms, doors, care) never come through here.
 */
final class ItOnCall
{
    public function __construct(private readonly SecurityDevicesSettings $settings) {}

    /** @return Collection<int, Shift> on-call shifts overlapping [from, to), earliest first */
    public function shifts(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $role = $this->settings->role(SecurityDevicesSettings::DEVICES_ROLE);

        return Shift::query()
            ->employeeDuties()
            ->visibleToFrontline()
            ->where(fn (Builder $query) => $query->where('is_on_call', true)->orWhere('shift_type', 'on_call'))
            ->whereNotNull('user_id')
            ->whereNotIn('status', ['draft', 'cancelled', 'no_show'])
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->when($role !== null, fn (Builder $query) => $query->whereHas('staff.roles', fn (Builder $roles) => $roles->where('name', $role)))
            ->with('staff:id,name,approved_at')
            ->orderBy('starts_at')
            ->limit(60)
            ->get()
            ->filter(fn (Shift $shift): bool => $shift->staff instanceof User
                && $shift->staff->approved_at !== null
                && ($shift->staff->canDo('it.view') || $shift->staff->canDo('it.manage') || $shift->staff->canDo('securityDevices.monitoring.manage')))
            ->values();
    }

    /** The on-call shift covering this moment; the one that started first when two overlap. */
    public function now(?CarbonImmutable $now = null): ?Shift
    {
        $now ??= CarbonImmutable::now('UTC');

        return $this->shifts($now, $now->addSecond())->first();
    }

    /**
     * Who to tell first: the person on call, when they would be told anyway (they're among `$people`, so they
     * can see that Site). Otherwise everyone in `$people`, as before.
     *
     * @param  Collection<int, User>  $people
     * @return Collection<int, User>
     */
    public function first(Collection $people, ?CarbonImmutable $now = null): Collection
    {
        if ($people->count() < 2) {
            return $people;
        }
        $shift = $this->now($now);
        $match = $shift === null ? null : $people->first(fn (User $user): bool => (int) $user->id === (int) $shift->user_id);

        return $match === null ? $people : collect([$match]);
    }
}
