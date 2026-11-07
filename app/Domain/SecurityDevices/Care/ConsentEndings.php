<?php

namespace App\Domain\SecurityDevices\Care;

use App\Domain\SecurityDevices\Care\Models\DevicePersonLink;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Client;
use App\Models\Permission;
use App\Models\User;
use App\Notifications\SecurityDeviceConsentNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Safety fix round (7 Nov 2026, F2): when a resident's consent to monitoring sensors ends, their
 * sensor comes off their care plan and its care alerts stop (DevicePersonLinks::sweepConsents). Until
 * now nobody was told. The house manager is now warned 14 days and 3 days before it ends, and told
 * when it has ended; the sensor page and the house page say "Care alerts stopped: consent ended".
 *
 * The house manager is whoever may link sensors to people at that house (people.link), those bound
 * to their own houses first; if a house has none, those who may see every house. Names follow
 * record access: a person who can't see the resident's record reads "a resident".
 */
final class ConsentEndings
{
    /** Days before consent ends that the house manager is warned, longest first. */
    public const array WARN_DAYS = [14, 3];

    /** How long the "care alerts stopped" note stays on the sensor and house pages. */
    public const int SHOW_STOPPED_DAYS = 30;

    private const string KEY = 'securityDevices.people.link';

    public function __construct(
        private readonly SensorMonitoringConsent $consent,
        private readonly SecurityDevicesAccessService $access,
    ) {}

    /** Warn about consents ending soon (once per threshold per link). Returns how many warnings went out. */
    public function warn(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now('UTC');
        $zone = (string) config('app.worker_timezone', 'Pacific/Auckland');
        $today = $now->setTimezone($zone)->startOfDay();
        $sent = 0;
        DevicePersonLink::query()->current()->with(['client', 'device'])->orderBy('id')->limit(1000)->get()
            ->each(function (DevicePersonLink $link) use ($today, $zone, &$sent): void {
                try {
                    if (! $link->client instanceof Client) {
                        return;
                    }
                    $state = $this->consent->state($link->client);
                    if (! $state['active'] || $state['until'] === null) {
                        return;
                    }
                    $ends = CarbonImmutable::parse($state['until'])->setTimezone($zone)->startOfDay();
                    $daysLeft = (int) round($today->diffInDays($ends, false));
                    if ($daysLeft < 0) {
                        return;
                    }
                    $warned = (array) data_get($link->state, 'consent_warned', []);
                    $due = array_values(array_filter(self::WARN_DAYS, fn (int $days): bool => $daysLeft <= $days && ! array_key_exists((string) $days, $warned)));
                    if ($due === []) {
                        return;
                    }
                    // One notice even when two thresholds are due at once (a link made close to the end).
                    foreach ($due as $days) {
                        $warned[(string) $days] = $today->toDateString();
                    }
                    $link->forceFill(['state' => [...(array) $link->state, 'consent_warned' => $warned]])->save();
                    $this->tell($link, 'ending', $ends);
                    $sent++;
                } catch (Throwable $exception) {
                    Log::warning('Consent ending warning could not be sent', ['link_id' => $link->id, 'error' => $exception->getMessage()]);
                }
            });

        return $sent;
    }

    /** A link came off because consent ended: the house manager hears. */
    public function stopped(DevicePersonLink $link): void
    {
        try {
            $link->loadMissing(['client', 'device']);
            $this->tell($link, 'ended', null);
        } catch (Throwable $exception) {
            Log::warning('Consent ended notice could not be sent', ['link_id' => $link->id, 'error' => $exception->getMessage()]);
        }
    }

    /**
     * Links at a house (or on one sensor) that came off because consent ended, recently: for the
     * sensor and house pages. The resident's name only for someone who may see their record.
     *
     * @return list<array{device_id: int, device: string, device_href: string, person: string, ended_at: string|null}>
     */
    public function recentStops(User $viewer, ?int $siteId, ?int $deviceId = null, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $query = DevicePersonLink::query()->with(['client', 'device'])
            ->where('unlink_reason', 'consent_ended')
            ->where('unlinked_at', '>=', $now->subDays(self::SHOW_STOPPED_DAYS));
        if ($deviceId !== null) {
            $query->where('device_id', $deviceId);
        } elseif ($siteId !== null) {
            $query->where('site_id', $siteId);
        } else {
            return [];
        }

        return $query->latest('unlinked_at')->limit(20)->get()
            // A sensor linked again since (new consent) no longer needs the note.
            ->reject(fn (DevicePersonLink $link): bool => DevicePersonLink::query()->current()->where('device_id', $link->device_id)->exists())
            ->unique('device_id')
            ->map(fn (DevicePersonLink $link): array => [
                'device_id' => (int) $link->device_id,
                'device' => (string) ($link->device?->name ?? 'A sensor'),
                'device_href' => '/security-devices/devices/'.$link->device_id,
                'person' => $link->client instanceof Client && Gate::forUser($viewer)->allows('view', $link->client) ? DevicePersonLinks::name($link->client) : 'A resident',
                'ended_at' => $link->unlinked_at?->toIso8601String(),
            ])->values()->all();
    }

    /** @return Collection<int, User> */
    public function houseManagers(int $siteId): Collection
    {
        $permissionId = Permission::query()->where('key', self::KEY)->value('id');
        if ($permissionId === null) {
            return collect();
        }
        $candidates = User::query()
            ->whereNotNull('approved_at')
            ->where(fn ($query) => $query
                ->whereHas('roles.permissions', fn ($permissions) => $permissions->whereKey($permissionId))
                ->orWhereHas('permissionOverrides', fn ($overrides) => $overrides->whereKey($permissionId)->where('permission_user.allowed', true)))
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->filter(fn (User $user): bool => $user->canDo(self::KEY) && in_array($siteId, $this->access->accessibleSiteIds($user), true))
            ->values();
        $local = $candidates->reject(fn (User $user): bool => $user->canDo('securityDevices.devices.viewAllSites'))->values();

        return $local->isNotEmpty() ? $local : $candidates;
    }

    private function tell(DevicePersonLink $link, string $what, ?CarbonImmutable $ends): void
    {
        $siteId = (int) ($link->site_id ?? 0);
        if ($siteId <= 0) {
            return;
        }
        $managers = $this->houseManagers($siteId);
        if ($managers->isEmpty()) {
            Log::warning('Nobody to tell about a resident’s sensor consent', ['link_id' => $link->id, 'site_id' => $siteId, 'what' => $what]);

            return;
        }
        Notification::send($managers, new SecurityDeviceConsentNotification(
            linkId: (int) $link->id,
            clientId: (int) $link->client_id,
            deviceId: (int) $link->device_id,
            deviceName: (string) ($link->device?->name ?? 'A sensor'),
            what: $what,
            endsOn: $ends?->toDateString(),
        ));
    }
}
