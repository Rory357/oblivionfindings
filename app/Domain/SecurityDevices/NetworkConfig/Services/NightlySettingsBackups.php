<?php

namespace App\Domain\SecurityDevices\NetworkConfig\Services;

use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\SecurityDevices\Credentials\Services\CommandCredentialLeaseService;
use App\Domain\SecurityDevices\Drivers\Contracts\NetworkConfigBackupDriver;
use App\Domain\SecurityDevices\Drivers\Services\DeviceDriverRegistry;
use App\Domain\SecurityDevices\Management\Data\CommandRequestInput;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\CommandCapabilityRegistry;
use App\Domain\SecurityDevices\Management\Services\CommandExecutionRouteResolver;
use App\Domain\SecurityDevices\Management\Services\DeclaredDeviceCommandCapabilities;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandQueueService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandRequestService;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * S&D Phase G (G1): a settings backup for every router and switch, every night, through exactly the
 * path a person's "Back up now" takes: the governed network.config.backup request, routed to the hub
 * that covers the Site (never the server, where a hub covers it), then queued.
 *
 * The governed framework has no "system" requester: every command is asked for by a person whose
 * current permissions are checked (DeviceCommandRequestService needs a User). This doesn't loosen
 * that. The nightly run asks in the name of one named Oblivion user, set in
 * config('security_devices.nightly_settings_backups.requested_by_user_id'). Every night that
 * user's live permissions and Site access are checked again for every device, and the audit shows
 * them with "Nightly settings backup" as the reason. With nobody named, nothing is asked for.
 *
 * A device is backed up only when it can be: it declares backups, sits at a Site, has a backup
 * profile (its brand's export) and a working saved login, and its route is open (an offline hub
 * means no backup tonight, not a backup from the server). At most a few per hub per run, so a hub
 * finishes each backup before its request runs out of time.
 */
final class NightlySettingsBackups
{
    public const string CAPABILITY = 'network.config.backup';

    public const int PER_ROUTE_PER_RUN = 5;

    public const string REASON = 'Nightly settings backup (scheduled). It only reads the device’s settings.';

    private const array WAITING = ['requested', 'awaiting_step_up', 'awaiting_approval', 'awaiting_change', 'ready', 'queued', 'dispatching', 'accepted', 'running', 'reconciling'];

    public function __construct(
        private readonly DeclaredDeviceCommandCapabilities $declared,
        private readonly CommandCapabilityRegistry $capabilities,
        private readonly CanonicalDeviceSiteResolver $sites,
        private readonly CommandExecutionRouteResolver $routes,
        private readonly CommandCredentialLeaseService $credentials,
        private readonly DeviceDriverRegistry $drivers,
        private readonly DeviceCommandRequestService $requests,
        private readonly DeviceCommandQueueService $queue,
    ) {}

    /** The person the nightly backups are asked for as, or null while nightly backups are off. */
    public function requester(): ?User
    {
        $id = (int) config('security_devices.nightly_settings_backups.requested_by_user_id', 0);

        return $id < 1 ? null : User::query()->whereKey($id)->whereNotNull('approved_at')->first();
    }

    public function on(): bool
    {
        return $this->requester() !== null;
    }

    /** "02:00" and "05:00", NZ time. */
    public static function window(): array
    {
        $from = (string) config('security_devices.nightly_settings_backups.from', '02:00');
        $until = (string) config('security_devices.nightly_settings_backups.until', '05:00');

        return [preg_match('/^\d{2}:\d{2}$/', $from) === 1 ? $from : '02:00', preg_match('/^\d{2}:\d{2}$/', $until) === 1 ? $until : '05:00'];
    }

    /** "every night, 2:00 am – 5:00 am", or null while it's off. */
    public function scheduleWords(): ?string
    {
        if (! $this->on()) {
            return null;
        }
        [$from, $until] = self::window();

        return 'every night, '.self::clock($from).' – '.self::clock($until);
    }

    public static function idempotencyKey(Device $device, CarbonImmutable $now): string
    {
        return 'nightly-settings-backup:'.$device->id.':'.$now->setTimezone('Pacific/Auckland')->toDateString();
    }

    /**
     * One run: asks for tonight's backup for each device that can have one and hasn't yet.
     *
     * @return array{off: bool, requested: int, refused: int, skipped: array<string, int>, refusals: list<string>}
     */
    public function run(CarbonImmutable $now, bool $dryRun = false): array
    {
        $result = ['off' => false, 'requested' => 0, 'refused' => 0, 'skipped' => [], 'refusals' => []];
        $requester = $this->requester();
        if (! $requester instanceof User) {
            $result['off'] = true;

            return $result;
        }
        $skip = function (string $why) use (&$result): void {
            $result['skipped'][$why] = ($result['skipped'][$why] ?? 0) + 1;
        };
        $allowedStates = $this->capabilities->definition(self::CAPABILITY)->allowedCurrentStates;

        /** @var array<string, int> $taken per route this run */
        $taken = [];
        /** @var array<string, bool> $busy routes still running an earlier backup */
        $busy = [];

        $devices = Device::query()
            ->where('domain', 'it_infrastructure')
            ->whereIn('category', ['network', 'networking'])
            ->orderBy('id')
            ->get();
        foreach ($devices as $device) {
            if (! in_array($device->status?->value, $allowedStates, true)) {
                $skip('not_in_service');

                continue;
            }
            if (! $this->declared->supports($device, self::CAPABILITY)) {
                $skip('no_backup_support');

                continue;
            }
            try {
                $siteId = $this->sites->resolve((int) $device->id);
            } catch (Throwable) {
                $skip('no_site');

                continue;
            }
            if (! $this->drivers->resolveNetworkConfigBackupDriver($device) instanceof NetworkConfigBackupDriver) {
                $skip('no_backup_profile');

                continue;
            }
            if (! $this->credentials->available($device, $siteId, self::CAPABILITY)) {
                $skip('no_saved_login');

                continue;
            }
            $route = $this->routes->resolve($device, $siteId, self::CAPABILITY);
            if (! $route->available) {
                $skip('cant_reach');

                continue;
            }
            $key = self::idempotencyKey($device, $now);
            if (DeviceCommandRequest::query()->where('device_id', $device->id)->where('capability', self::CAPABILITY)
                ->where('requested_by_user_id', $requester->id)->where('idempotency_key', $key)->exists()) {
                $skip('done_tonight');

                continue;
            }
            if (DeviceCommandRequest::query()->where('device_id', $device->id)->where('capability', self::CAPABILITY)
                ->whereIn('status', self::WAITING)->exists()) {
                $skip('already_waiting');

                continue;
            }

            $routeKey = $route->mode === 'collector' ? 'hub:'.(int) $route->collector?->id : 'server';
            $busy[$routeKey] ??= DeviceCommandRequest::query()
                ->where('capability', self::CAPABILITY)
                ->whereIn('status', self::WAITING)
                ->when($route->mode === 'collector',
                    fn ($query) => $query->where('collector_id', (int) $route->collector?->id),
                    fn ($query) => $query->whereNull('collector_id'))
                ->exists();
            if ($busy[$routeKey] || ($taken[$routeKey] ?? 0) >= self::PER_ROUTE_PER_RUN) {
                $skip('next_run');

                continue;
            }
            if ($dryRun) {
                $taken[$routeKey] = ($taken[$routeKey] ?? 0) + 1;
                $result['requested']++;

                continue;
            }

            try {
                $request = $this->requests->request($device, $requester, new CommandRequestInput(
                    capability: self::CAPABILITY,
                    parameters: [],
                    reason: self::REASON,
                    idempotencyKey: $key,
                    impactAcknowledged: true,
                ));
                if ($request->status === CommandStatus::Ready) {
                    $this->queue->queue($request, $requester);
                }
                $taken[$routeKey] = ($taken[$routeKey] ?? 0) + 1;
                $result['requested']++;
            } catch (ValidationException $refused) {
                $result['refused']++;
                $result['refusals'][] = $device->name.': '.collect($refused->errors())->flatten()->first();
            } catch (HttpExceptionInterface $refused) {
                // The named person may not do this for this device any more (403), or can't see it (404).
                $result['refused']++;
                $result['refusals'][] = $device->name.': '.($refused->getStatusCode() === 404 ? 'the named person can’t see this device' : 'the named person may not ask for its backup');
            }
        }

        return $result;
    }

    private static function clock(string $time): string
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return sprintf('%d:%02d %s', $hour % 12 === 0 ? 12 : $hour % 12, $minute, $hour < 12 ? 'am' : 'pm');
    }
}
