<?php

namespace App\Domain\SecurityDevices\Alerts;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Domain\SecurityDevices\Setup\SecurityDevicesSettings;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Who to tell at a house about a safety alert (S&D Phase E, Setup › Alerts & checks: "Use the
 * roster to find who is on shift"): the staff rostered on now or clocked in there, at the moment
 * it happens. With the roster switch off, or nobody on shift, everyone at that Site who can
 * acknowledge device alerts is told instead, so a safety alert never reaches no one.
 */
final class StaffOnShift
{
    public function __construct(
        private readonly SecurityDevicesSettings $settings,
        private readonly SecurityDevicesAccessService $access,
    ) {}

    /** @return Collection<int, User> */
    public function toTell(int $siteId, ?CarbonImmutable $at = null): Collection
    {
        $at ??= CarbonImmutable::now('UTC');
        $onShift = $this->settings->bool(SecurityDevicesSettings::SAFETY_ROSTER) ? $this->onShift($siteId, $at) : collect();

        return $onShift->isNotEmpty() ? $onShift : $this->siteResponders($siteId);
    }

    /** @return Collection<int, User> */
    public function onShift(int $siteId, CarbonImmutable $at): Collection
    {
        $clockedIn = HrAttendanceSession::query()
            ->where('site_id', $siteId)
            ->where('clock_in_at', '<=', $at)
            ->whereNull('clock_out_at')
            ->where('status', 'open')
            ->pluck('user_id');
        $rostered = Shift::query()
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>=', $at)
            ->whereNotIn('status', ['cancelled', 'declined', 'no_show'])
            ->whereNotNull('user_id')
            ->where(fn ($query) => $query
                ->where('site_id', $siteId)
                ->orWhere(fn ($derived) => $derived->whereNull('site_id')->whereHas('client', fn ($client) => $client->where('site_id', $siteId))))
            ->pluck('user_id');
        $ids = $clockedIn->merge($rostered)->filter()->unique()->values();

        return $ids->isEmpty() ? collect() : User::query()->whereIn('id', $ids)->whereNotNull('approved_at')->orderBy('name')->get();
    }

    /** @return Collection<int, User> */
    private function siteResponders(int $siteId): Collection
    {
        return User::query()
            ->whereNotNull('approved_at')
            ->with(['roles.permissions', 'permissionOverrides'])
            ->orderBy('name')
            ->limit(300)
            ->get()
            ->filter(fn (User $person): bool => $person->canDo('securityDevices.alerts.acknowledge')
                && ($this->access->canViewAllSites($person) || in_array($siteId, $this->access->accessibleSiteIds($person), true)))
            ->values();
    }
}
