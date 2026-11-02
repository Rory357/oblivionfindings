<?php

namespace App\Domain\SecurityDevices\Access;

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Access\Models\AccessConsole;
use App\Domain\SecurityDevices\Access\Models\AccessDoor;
use App\Domain\SecurityDevices\Access\Models\AccessPerson;
use App\Domain\SecurityDevices\Cctv\Services\CameraEndpointTrustService;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Credentials\Services\CredentialReferenceManager;
use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Management\Services\HubJobTypes;
use App\Domain\SecurityDevices\Management\Services\UnifiAccessControlCollectorDescriptor;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Domain\SecurityDevices\Setup\SecurityDevicesSettings;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * S&D Phase H (pg-sdr-15m, pop-up 91): Setup › Doors & access. One UniFi Access console per Site,
 * reached through that Site's hub: its address, the token (entered by the person, kept in the
 * secret store, never shown again), its certificate (read by the hub, trusted by a person), and
 * the installer's confirmation that every door opens from the inside before lockdown is offered.
 */
final class AccessSetup
{
    /** What the console's token may be used for, in Oblivion's words (one saved login per Site). */
    public const array CREDENTIAL_CAPABILITIES = [
        'read:access.sync',
        'write:access.admin',
        'command:access.door.keep_unlocked',
        'command:access.door.keep_unlocked_end',
        'command:access.site.lockdown',
        'command:access.site.evacuate',
        'command:access.site.emergency_end',
    ];

    /** The UniFi Access API token permissions to tick, in UniFi's own words. */
    public const array TOKEN_PERMISSIONS = [
        'Locations: View',
        'Users: View, Edit',
        'Visitors: View, Edit',
        'Access policies: View, Edit',
        'Credentials (NFC cards): View, Edit',
        'Devices: View',
        'Space (doors): View, Edit',
        'System logs: View',
    ];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly CoveringHubResolver $hubs,
        private readonly CameraEndpointTrustService $trust,
        private readonly SecurityDevicesSettings $settings,
    ) {}

    public static function canManage(User $viewer): bool
    {
        return $viewer->canDo('securityDevices.integrations.manage');
    }

    /** @return array<string, mixed> */
    public function present(User $viewer): array
    {
        $sites = $this->sites($viewer);
        $consoles = AccessConsole::query()->whereIn('site_id', $sites->keys()->all())->get()->keyBy('site_id');
        $places = $sites->map(function (string $name, int $siteId) use ($consoles, $viewer): array {
            $console = $consoles->get($siteId);
            $hub = $this->hubs->forSite($siteId);

            return [
                'site_id' => $siteId,
                'site' => $name,
                'hub' => $hub instanceof MonitoringCollector ? $this->hub($hub) : null,
                'console' => $console instanceof AccessConsole ? $this->console($viewer, $console) : null,
            ];
        })->values()->all();
        $settings = $this->settings->forViewer($viewer);

        return [
            'places' => $places,
            'switches' => [
                'leavers' => $settings[SecurityDevicesSettings::ACCESS_LEAVERS],
                'changes' => $settings[SecurityDevicesSettings::ACCESS_CHANGES],
            ],
            'token_permissions' => self::TOKEN_PERMISSIONS,
            'can' => [
                'connect' => self::canManage($viewer),
                // The secret store itself also asks for commands.admin.
                'save_token' => $viewer->canDo('securityDevices.savedLogins.manage') && $viewer->canDo('securityDevices.commands.admin'),
                'confirm_doors' => $viewer->canDo('securityDevices.accessControl.manage'),
            ],
        ];
    }

    /** One console's state, for the Setup card and the wizard's waiting steps. @return array<string, mixed> */
    public function console(User $viewer, AccessConsole $console): array
    {
        $record = $this->trust->record((int) $console->site_id, $console->address, (int) $console->port);
        $reference = UnifiAccessSync::reference((int) $console->site_id);

        return [
            'id' => (int) $console->id,
            'address' => $console->address,
            'port' => (int) $console->port,
            'state' => (string) $console->state,
            'last_error' => $console->last_error,
            'last_synced_at' => $console->last_synced_at?->toIso8601String(),
            'emergency_mode' => (string) $console->emergency_mode,
            'doors' => AccessDoor::query()->where('console_id', $console->id)->count(),
            'staff' => AccessPerson::query()->where('console_id', $console->id)->where('kind', 'staff')->where('status', 'active')->count(),
            'to_match' => AccessPerson::query()->where('console_id', $console->id)->whereIn('match_state', ['suggested', 'unmatched'])->where('kind', '!=', 'resident')->count(),
            'token' => $reference === null ? null : [
                'saved_at' => $reference->created_at?->toIso8601String(),
                'complete' => array_diff(self::CREDENTIAL_CAPABILITIES, $reference->capabilities ?? []) === [],
            ],
            'certificate' => $this->trust->present($viewer, $record),
            'trusted' => is_string($record?->trusted_pin),
            'doors_confirmed_at' => $console->exit_buttons_confirmed_at?->toIso8601String(),
        ];
    }

    /** Pop-up 91, step 2: the console's address at a Site with a hub. */
    public function connect(User $actor, int $siteId, string $address, int $port): AccessConsole
    {
        abort_unless(self::canManage($actor), 403);
        abort_unless($this->sites($actor)->has($siteId), 404);
        if (filter_var($address, FILTER_VALIDATE_IP) === false
            || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
            throw ValidationException::withMessages(['address' => 'Enter the console’s address on the house network, like 172.16.0.5.']);
        }
        if (! in_array($port, [443, 12445], true)) {
            throw ValidationException::withMessages(['port' => 'UniFi Access answers on port 12445 (or 443).']);
        }
        if (! $this->hubs->forSite($siteId) instanceof MonitoringCollector) {
            throw ValidationException::withMessages(['site_id' => 'This Site has no hub yet. UniFi Access only answers on the Site’s own network, so add a hub first.']);
        }

        return DB::transaction(function () use ($actor, $siteId, $address, $port): AccessConsole {
            $console = AccessConsole::query()->where('site_id', $siteId)->lockForUpdate()->first();
            $moved = $console instanceof AccessConsole && ($console->address !== $address || (int) $console->port !== $port);
            $console ??= new AccessConsole(['site_id' => $siteId]);
            $console->forceFill([
                'address' => $address,
                'port' => $port,
                'state' => $console->exists && ! $moved ? $console->state : 'waiting',
                'last_error' => $console->exists && ! $moved ? $console->last_error : null,
                'connected_by_user_id' => $actor->id,
                // A different console hasn't had its doors checked.
                'exit_buttons_confirmed_at' => $moved ? null : $console->exit_buttons_confirmed_at,
                'exit_buttons_confirmed_by_user_id' => $moved ? null : $console->exit_buttons_confirmed_by_user_id,
            ])->save();
            AuditLogger::logOrFail('security_devices.access.console_connected', $console, [
                'site_id' => $siteId,
                'address' => $address,
                'port' => $port,
            ]);

            return $console;
        });
    }

    /**
     * Pop-up 91, step 3: the token the person made in UniFi Access. It goes straight to the secret
     * store; Oblivion never shows it again.
     */
    public function saveToken(User $actor, AccessConsole $console, #[\SensitiveParameter] string $token): CredentialReference
    {
        abort_unless($actor->canDo('securityDevices.savedLogins.manage') && $actor->canDo('securityDevices.commands.admin'), 403);
        abort_unless($this->sites($actor)->has((int) $console->site_id), 404);
        $token = trim($token);
        if (strlen($token) < 8 || strlen($token) > 4090 || preg_match('/[\x00-\x20\x7f]/', $token) === 1) {
            throw ValidationException::withMessages(['token' => 'Paste the whole token from UniFi Access, with no spaces.']);
        }
        $manager = app(CredentialReferenceManager::class);
        $existing = CredentialReference::query()->where('site_id', $console->site_id)->where('provider', AccessConsoles::PROVIDER)
            ->where('status', '!=', 'revoked')->orderByDesc('id')->first();
        try {
            $reference = $existing instanceof CredentialReference
                ? $manager->rotate($actor, $existing, '', ['api_token' => $token])
                : $manager->register($actor, [
                    'site_id' => (int) $console->site_id,
                    'reference_key' => 'database:unifi_access/'.$console->site_id.'/console',
                    'provider' => AccessConsoles::PROVIDER,
                    'purpose' => 'device_management',
                    'capabilities' => self::CREDENTIAL_CAPABILITIES,
                    'credential_material' => ['api_token' => $token],
                ]);

            return $manager->test($actor, $reference);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw ValidationException::withMessages(['token' => 'Oblivion couldn’t keep the token safely. Nothing was saved; try again.']);
        } finally {
            sodium_memzero($token);
        }
    }

    /** Pop-up 91, step 4: trust the certificate the hub read from the console. */
    public function trustCertificate(User $actor, AccessConsole $console, string $fingerprint): void
    {
        abort_unless(self::canManage($actor), 403);
        abort_unless($this->sites($actor)->has((int) $console->site_id), 404);
        $this->trust->trust($actor, (int) $console->site_id, $console->address, (int) $console->port, $fingerprint);
    }

    /**
     * Pop-up 91, step 5: the person confirms every door has an exit button and a handle. Until
     * then lockdown isn't offered at this Site.
     */
    public function confirmDoors(User $actor, AccessConsole $console): void
    {
        abort_unless($actor->canDo('securityDevices.accessControl.manage'), 403);
        abort_unless($this->sites($actor)->has((int) $console->site_id), 404);
        $console->forceFill([
            'exit_buttons_confirmed_at' => CarbonImmutable::now('UTC'),
            'exit_buttons_confirmed_by_user_id' => $actor->id,
        ])->save();
        AuditLogger::logOrFail('security_devices.access.exit_buttons_confirmed', $console, [
            'site_id' => (int) $console->site_id,
            'doors' => AccessDoor::query()->where('console_id', $console->id)->count(),
        ]);
    }

    /** @return array{name: string, runs: bool, needs_update: bool, online: bool} */
    private function hub(MonitoringCollector $hub): array
    {
        $runs = HubJobTypes::runs($hub, UnifiAccessSync::HUB_FEATURE);

        return [
            'name' => CoveringHubResolver::label($hub),
            'runs' => $runs,
            'needs_update' => ! $runs || ! HubJobTypes::runs($hub, AccessJobs::HUB_FEATURE) || ! UnifiAccessControlCollectorDescriptor::hubRunsIt($hub),
            'online' => in_array((string) $hub->status, ['online', 'degraded'], true),
        ];
    }

    /** @return \Illuminate\Support\Collection<int, string> Sites this person can see, id => name */
    private function sites(User $viewer): \Illuminate\Support\Collection
    {
        $query = Site::query()->where('is_active', true)->where(fn ($site) => $site->whereNull('archived')->orWhere('archived', false))->orderBy('name');
        if (! $this->access->canViewAllSites($viewer)) {
            $query->whereIn('id', $this->access->accessibleSiteIds($viewer));
        }

        return $query->limit(500)->pluck('name', 'id')->map(fn ($name): string => (string) $name);
    }
}
