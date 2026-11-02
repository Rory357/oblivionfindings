<?php

namespace App\Domain\SecurityDevices\Screens\Http;

use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Discovery\Services\DiscoveryRunner;
use App\Domain\SecurityDevices\Screens\Devices\DeviceFormOptions;
use App\Domain\SecurityDevices\Screens\Setup\SetupHistory;
use App\Domain\SecurityDevices\Screens\Setup\SetupPresenter;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Domain\SecurityDevices\Setup\SecurityDevicesSettings;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnexpectedValueException;

/** Setup (S&D rebuild drawing pg-sdr-15 and its sections). */
final class SetupController extends Controller
{
    public function __construct(
        private readonly SetupPresenter $setup,
        private readonly DeviceFormOptions $formOptions,
    ) {}

    public function show(Request $request, ?string $section = null): Response
    {
        $user = $this->viewer($request);
        $props = $this->setup->present($user, $section ?? 'overview', $request->query('tab'), ['page' => $request->query('page')]);
        // S&D rebuild C6 ("Keep, move"): Settings › Server readiness as a Server check card on Hubs &
        // networks › Networks & scans, for admins only (settings.access.manage, as that page). It loads
        // after the page so the live checks never hold Setup up.
        $serverCheck = $props['section'] === 'hubs' && $props['tab'] === 'networks' && $user->canDo('settings.access.manage');

        return Inertia::render('security-devices/setup/index', [
            ...$props,
            'can' => [...$props['can'], 'server_check' => $serverCheck],
            'server_check' => $serverCheck
                ? Inertia::defer(fn (): array => app(\App\Services\System\ServerReadinessService::class)->evaluate(true))
                : null,
            // S&D rebuild C6 gap: the old Monitoring page's runtime health (service heartbeat, work
            // waiting, failed deliveries, outage watchdog) on the same card, same admins, own load.
            'server_health' => $serverCheck
                ? Inertia::defer(fn (): array => app(\App\Domain\SecurityDevices\Screens\Setup\ServerHealth::class)->present($user), 'server-health')
                : null,
            // Add a network loads its Site and hub choices only when it opens.
            'deviceForm' => Inertia::optional(fn (): array => $this->formOptions->present($user)),
        ]);
    }

    /** Save the switches changed on a Setup tab, after "Review changes". */
    public function settings(Request $request, SecurityDevicesSettings $settings): JsonResponse
    {
        $user = $this->viewer($request);
        $validated = $request->validate([
            'changes' => ['required', 'array', 'min:1', 'max:20'],
            // Required by the settings store when a change loosens a safety check (pop-up 80).
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $changed = $settings->update($user, $validated['changes'], $validated['reason'] ?? null);
        } catch (AuthorizationException) {
            abort(403, 'You can’t change this setting.');
        }

        return response()->json([
            'changed' => $changed,
            'settings' => $settings->forViewer($user),
        ]);
    }

    /** Scan one network now (the Networks table's "Scan now"). */
    public function scan(Request $request, int $scope, SecurityDevicesAccessService $access, DiscoveryRunner $runner): JsonResponse
    {
        $user = $this->viewer($request);
        abort_unless($user->canDo('securityDevices.integrations.manage'), 403);
        $record = DiscoveryScope::query()->where('status', 'active')->findOrFail($scope);
        $access->assertCanViewSite($user, (int) $record->site_id);

        try {
            $run = DB::transaction(function () use ($runner, $record, $user) {
                $run = $runner->start($record, "manual:user:{$user->id}");
                AuditLogger::logOrFail('monitoring.discovery.scope.applied', $record, [
                    'actor_id' => (int) $user->id,
                    'site_id' => (int) $record->site_id,
                    'run_id' => (int) $run->id,
                    'run_uuid' => $run->run_uuid,
                    'run_status' => $run->status,
                    'planned_targets' => (int) $run->planned_targets,
                    'collection_mode' => $record->collector_id === null ? 'central_direct' : 'remote_collector',
                    ...($record->collector_id === null ? [] : ['collector_id' => (int) $record->collector_id]),
                ]);

                return $run;
            }, 3);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages([
                'scope' => str_starts_with($exception->getMessage(), 'The Oblivion server')
                    ? $exception->getMessage()
                    : 'This network can’t be scanned right now. Check that its hub is approved and answering, then try again.',
            ]);
        }

        return response()->json(['run_id' => (int) $run->id], 202);
    }

    /**
     * Pop-up 98, step 2: make a gateway's own login on its Site's hub relay. The password is shown
     * once, here, and never stored in a readable form. Refused in plain words when the relay can't
     * take readings at that Site yet (no hub, unencrypted MQTT off, or an address range clash).
     */
    public function storeGateway(Request $request, SecurityDevicesAccessService $access, SecurityDevicesSettings $settings): JsonResponse
    {
        $user = $this->viewer($request);
        abort_unless($user->canDo('securityDevices.savedLogins.manage'), 403);
        $validated = $request->validate([
            'site_id' => ['required', 'integer', 'min:1'],
            'model' => ['required', 'string', \Illuminate\Validation\Rule::in(['Milesight UG65', 'Milesight UG67', 'Milesight UG56', 'Another MQTT gateway'])],
            'name' => ['required', 'string', 'min:3', 'max:80'],
        ]);
        $access->assertCanViewSite($user, (int) $validated['site_id']);
        $site = \App\Models\Site::query()->whereKey((int) $validated['site_id'])->where('is_active', true)->where('archived', false)->whereNull('archived_at')->firstOrFail();
        $hub = app(\App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver::class)->forSite((int) $site->id);
        // S&D Phase E (E5): encrypted (TLS on 8883) is the default once the hub has its certificate;
        // unencrypted on the house network only while Setup allows it and no other Site shares the range.
        $authority = app(\App\Domain\SecurityDevices\Iot\Relay\Services\SensorsCertificateAuthority::class);
        $tlsCapable = $hub !== null && \App\Domain\SecurityDevices\Management\Services\HubJobTypes::runs($hub, \App\Domain\SecurityDevices\Iot\Relay\Services\SensorsCertificateAuthority::HUB_FEATURE);
        $encrypted = $tlsCapable && $authority->currentFor($hub) !== null && $authority->existingFingerprint() !== null;
        $plainOn = $settings->bool(SecurityDevicesSettings::MQTT_PLAIN);
        $clash = in_array((int) $site->id, \App\Domain\SecurityDevices\Iot\Relay\Services\SensorRelaySites::clashing(), true);
        $refusal = match (true) {
            $hub === null => 'This Site has no hub yet. Add a hub first: sensor readings never cross the public internet.',
            $encrypted, $plainOn && ! $clash => null,
            $plainOn && $clash => 'Another Site uses the same address range, so unencrypted MQTT is refused here, and the hub can’t take encrypted readings yet. Give one of them a different range, or wait for the hub’s certificate.',
            $tlsCapable => 'The Site’s hub hasn’t got its certificate for encrypted readings yet. It asks within a day of its update; try again then.',
            default => \App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver::label($hub).' needs its software update before gateways can send to it encrypted.',
        };
        if ($refusal !== null) {
            throw ValidationException::withMessages(['site_id' => $refusal]);
        }
        $label = trim($validated['name']).($validated['model'] !== 'Another MQTT gateway' ? ' ('.str_replace('Milesight ', '', $validated['model']).')' : '');
        ['login' => $login, 'password' => $password] = app(\App\Domain\SecurityDevices\Iot\Relay\Services\SensorGatewayLoginService::class)->create($site, mb_substr($label, 0, 120), null, $user);
        $hubAddress = $hub->collector_device_id ? \App\Domain\SecurityDevices\Models\Device::query()->whereKey($hub->collector_device_id)->value('ip_address') : null;

        return response()->json([
            'id' => (int) $login->id,
            'username' => (string) $login->username,
            'password' => $password,
            'topic' => (string) $login->topic_prefix.'up',
            'hub' => ['label' => \App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver::label($hub), 'address' => is_string($hubAddress) && $hubAddress !== '' ? $hubAddress : null],
            'port' => $encrypted ? 8883 : 1883,
            'tls' => $encrypted ? [
                'fingerprint' => $authority->existingFingerprint(),
                'download_url' => route('security-devices.setup.broker.sensors-certificate', [], false),
            ] : null,
        ], 201)->header('Cache-Control', 'no-store');
    }

    /** Pop-up 98, step 4: has the gateway's first reading arrived? */
    public function showGateway(Request $request, int $login, SecurityDevicesAccessService $access): JsonResponse
    {
        $user = $this->viewer($request);
        abort_unless($user->canDo('securityDevices.savedLogins.manage'), 403);
        $record = \App\Domain\SecurityDevices\Iot\Relay\Models\SensorGatewayLogin::query()->findOrFail($login);
        $access->assertCanViewSite($user, (int) $record->site_id);

        return response()->json([
            'last_reading_at' => $record->last_reading_at?->toIso8601String(),
            'removed' => $record->revoked_at !== null,
        ]);
    }

    /** Setup › Sensor broker: remove one gateway's login; its hub stops accepting it at the next update. */
    public function revokeGateway(Request $request, int $login, SecurityDevicesAccessService $access): JsonResponse
    {
        $user = $this->viewer($request);
        abort_unless($user->canDo('securityDevices.savedLogins.manage'), 403);
        $record = \App\Domain\SecurityDevices\Iot\Relay\Models\SensorGatewayLogin::query()->active()->findOrFail($login);
        $access->assertCanViewSite($user, (int) $record->site_id);
        app(\App\Domain\SecurityDevices\Iot\Relay\Services\SensorGatewayLoginService::class)->revoke($record, $user);

        return response()->json(['message' => 'Login removed.']);
    }

    /**
     * S&D Phase E (E5): the sensors certificate, to load into a gateway so it can check it's talking
     * to its Site's hub over TLS (port 8883). It's public; the authority's private key never leaves
     * the server.
     */
    public function sensorsCertificate(Request $request, \App\Domain\SecurityDevices\Iot\Relay\Services\SensorsCertificateAuthority $authority): HttpResponse
    {
        $user = $this->viewer($request);
        abort_unless($user->canDo('securityDevices.savedLogins.manage'), 403);
        $certificate = $authority->existingCertificate();
        abort_if($certificate === null, 404);

        return response($certificate, 200, [
            'Content-Type' => 'application/x-pem-file',
            'Content-Disposition' => 'attachment; filename="oblivion-sensors-certificate.pem"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Change history as a CSV file (newest first, up to 5,000 rows). */
    public function exportHistory(Request $request, SetupHistory $history): StreamedResponse
    {
        $user = $this->viewer($request);
        abort_unless(SetupPresenter::canSeeHistory($user), 403);
        $rows = $history->export($user);
        $zone = (string) config('app.worker_timezone', 'Pacific/Auckland');
        AuditLogger::log('security_devices.setup.history_exported', null, ['actor_id' => (int) $user->id, 'rows' => count($rows)]);

        return response()->streamDownload(function () use ($rows, $zone): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['When (NZ time)', 'Who', 'What changed', 'Where']);
            foreach ($rows as $row) {
                $when = is_string($row['at']) ? CarbonImmutable::parse($row['at'])->setTimezone($zone)->format('Y-m-d H:i') : '';
                fputcsv($out, array_map(fn (string $cell): string => self::safeCell($cell), [$when, (string) $row['who'], (string) $row['what'], (string) $row['where']]));
            }
            fclose($out);
        }, 'security-devices-change-history.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** A cell can't start a spreadsheet formula. */
    private static function safeCell(string $value): string
    {
        return preg_match('/\A[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }

    private function viewer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && SetupPresenter::canOpen($user), 403);

        return $user;
    }
}
