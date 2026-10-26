<?php

namespace App\Domain\SecurityDevices\Endpoints\Presenters;

use App\Domain\It\Services\ItRemoteSupportAccess;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointAgent;
use App\Domain\SecurityDevices\Endpoints\Models\IntuneDeviceLink;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointActionPolicy;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointRestartPolicy;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptContract;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptPolicy;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointServiceCatalogue;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointSoftwarePackages;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointSoftwarePolicy;
use App\Domain\SecurityDevices\Management\Services\CommandAssignmentFingerprint;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use stdClass;
use Throwable;
use UnexpectedValueException;

final class EndpointDevicePosturePresenter
{
    private const SERVICE_LIMIT = 250;

    private const SOFTWARE_LIMIT = 250;

    private const FRESH_HEARTBEAT_SECONDS = 180;

    public function __construct(
        private readonly CanonicalDeviceSiteResolver $siteResolver,
        private readonly CommandAssignmentFingerprint $assignmentFingerprint,
        private readonly EndpointServiceCatalogue $serviceCatalogue,
        private readonly EndpointActionPolicy $actionPolicy,
        private readonly EndpointRestartPolicy $restartPolicy,
        private readonly EndpointSoftwarePolicy $softwarePolicy,
        private readonly EndpointScriptPolicy $scriptPolicy,
        private readonly ItRemoteSupportAccess $remoteSupportAccess,
    ) {}

    public static function isComputer(Device $device): bool
    {
        return $device->domain === 'it_infrastructure'
            && in_array($device->category, ['endpoint', 'server'], true);
    }

    public function canViewPosture(User $viewer): bool
    {
        return $viewer->canDo('securityDevices.endpoints.diagnostics')
            || $viewer->canDo('securityDevices.commands.admin')
            || $viewer->canDo('securityDevices.integrations.view')
            || $viewer->canDo('securityDevices.integrations.manage')
            || $viewer->canDo(EndpointActionPolicy::MANAGE)
            || $viewer->canDo(EndpointActionPolicy::SERVICE)
            || $viewer->canDo(EndpointRestartPolicy::MANAGE)
            || $viewer->canDo(EndpointRestartPolicy::SERVICE)
            || $viewer->canDo(EndpointSoftwarePolicy::MANAGE)
            || $viewer->canDo(EndpointSoftwarePolicy::SERVICE);
    }

    public static function agentState(
        ?EndpointAgent $agent,
        ?int $canonicalSiteId = null,
        ?string $currentFingerprint = null,
        bool $enforceSiteMatch = true,
    ): string {
        if ($agent === null) {
            return 'not_enrolled';
        }
        if ($agent->revoked_at !== null) {
            return 'revoked';
        }
        if ($agent->enrolled_at === null) {
            return $agent->enrolment_expires_at?->isFuture() ? 'pending_enrolment' : 'enrolment_expired';
        }
        if ($enforceSiteMatch && ($canonicalSiteId === null || (int) $agent->site_id !== $canonicalSiteId)) {
            return 'assignment_drift';
        }
        if ($currentFingerprint !== null && ! hash_equals((string) $agent->assignment_fingerprint, $currentFingerprint)) {
            return 'assignment_drift';
        }
        if ($agent->last_seen_at === null) {
            return 'enrolled_awaiting_heartbeat';
        }

        return $agent->last_seen_at->greaterThan(now()->subSeconds(self::FRESH_HEARTBEAT_SECONDS))
            ? 'fresh'
            : 'stale';
    }

    /** @return array{state: string, agentVersion: ?string, lastSeenAt: ?string, observedAt: ?string} */
    public static function agentBadge(
        ?EndpointAgent $agent,
        ?int $canonicalSiteId = null,
        ?string $currentFingerprint = null,
        bool $enforceSiteMatch = true,
    ): array {
        return [
            'state' => self::agentState($agent, $canonicalSiteId, $currentFingerprint, $enforceSiteMatch),
            'agentVersion' => $agent?->agent_version,
            'lastSeenAt' => $agent?->last_seen_at?->toISOString(),
            'observedAt' => $agent?->observed_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed>|null */
    public function present(User $viewer, Device $device, ?int $canonicalSiteId = null): ?array
    {
        if (! self::isComputer($device) || ! $this->canViewPosture($viewer)) {
            return null;
        }

        if ($canonicalSiteId === null) {
            try {
                $canonicalSiteId = $this->siteResolver->resolveForContext((int) $device->id);
            } catch (UnexpectedValueException) {
                $canonicalSiteId = null;
            }
        }

        $currentFingerprint = null;
        if ($canonicalSiteId !== null) {
            try {
                $currentFingerprint = $this->assignmentFingerprint->forDevice($device);
            } catch (Throwable) {
                $currentFingerprint = null;
            }
        }

        $agent = Schema::hasTable('endpoint_agents')
            ? EndpointAgent::query()->where('device_id', $device->id)->first()
            : null;
        $agentState = self::agentState($agent, $canonicalSiteId, $currentFingerprint, true);
        $canViewDiagnostics = $viewer->canDo('securityDevices.endpoints.diagnostics');
        $canRevoke = (bool) config('endpoint_management.enabled')
            && $viewer->canDo('securityDevices.commands.admin')
            && $viewer->canDo('securityDevices.devices.update')
            && $agent !== null
            && $agent->revoked_at === null;

        $serviceActions = is_array($agent?->service_actions) ? $agent->service_actions : null;
        $restartActions = is_array($agent?->restart_actions) ? $agent->restart_actions : null;
        $softwareActions = is_array($agent?->software_actions) ? $agent->software_actions : null;

        return [
            'agent' => [
                'uuid' => $agent?->uuid,
                'state' => $agentState,
                'platform' => $agent?->platform ?? 'windows',
                'agentVersion' => $agent?->agent_version,
                'enrolledAt' => $agent?->enrolled_at?->toISOString(),
                'enrolmentExpiresAt' => $agent?->enrolled_at === null ? $agent?->enrolment_expires_at?->toISOString() : null,
                'revokedAt' => $agent?->revoked_at?->toISOString(),
                'lastSeenAt' => $agent?->last_seen_at?->toISOString(),
                'observedAt' => $agent?->observed_at?->toISOString(),
                'sequence' => $agent ? (int) $agent->sequence : 0,
                'serviceActionsReady' => (bool) ($serviceActions['installed_service'] ?? false) && (bool) ($serviceActions['release_enabled'] ?? false),
                'restartActionsReady' => (bool) ($restartActions['installed_service'] ?? false) && (bool) ($restartActions['release_enabled'] ?? false),
                'softwareActionsReady' => (bool) ($softwareActions['installed_service'] ?? false) && (bool) ($softwareActions['release_enabled'] ?? false),
                'scriptExecutionAvailable' => $this->scriptPolicy->available(),
                'canRevoke' => $canRevoke,
                'revokeHref' => $canRevoke ? "/security-devices/devices/{$device->id}/endpoint/revoke" : null,
            ],
            'diagnosticsRestricted' => ! $canViewDiagnostics,
            'inventory' => $this->projectInventory($viewer, $device, $agent, $agentState, $canViewDiagnostics),
            'policies' => $this->projectPolicies($viewer, $device, $currentFingerprint),
            'intune' => $this->projectIntune($viewer, $device),
            'links' => [
                'remoteSupportHref' => $this->remoteSupportAccess->canOpen($viewer)
                    ? "/it/remote-support?device={$device->id}"
                    : null,
                'approvedSoftwareHref' => EndpointSoftwarePackages::canOpen($viewer)
                    ? '/security-devices/software'
                    : null,
                'agentDownloadsHref' => ($viewer->canDo('securityDevices.commands.admin') || $viewer->canDo('securityDevices.integrations.manage'))
                    ? '/security-devices/agent-downloads'
                    : null,
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function projectInventory(
        User $viewer,
        Device $device,
        ?EndpointAgent $agent,
        string $agentState,
        bool $canViewDiagnostics,
    ): ?array {
        if (! $canViewDiagnostics
            || $agent === null
            || $agent->enrolled_at === null
            || in_array($agentState, ['revoked', 'assignment_drift', 'not_enrolled', 'pending_enrolment', 'enrolment_expired'], true)
            || ! is_array($agent->inventory)
        ) {
            return null;
        }

        $raw = $agent->inventory;
        $disks = [];
        foreach (is_array($raw['disks'] ?? null) ? $raw['disks'] : [] as $disk) {
            if (! is_array($disk) || ! is_string($disk['name'] ?? null)) {
                continue;
            }
            $total = isset($disk['total_bytes']) && is_int($disk['total_bytes']) ? $disk['total_bytes'] : 0;
            $free = isset($disk['free_bytes']) && is_int($disk['free_bytes']) ? min($disk['free_bytes'], $total) : 0;
            $used = max(0, $total - $free);
            $disks[] = [
                'name' => $disk['name'],
                'totalBytes' => $total,
                'freeBytes' => $free,
                'usedBytes' => $used,
                'usedPercent' => $total > 0 ? round(($used / $total) * 100, 1) : null,
            ];
        }

        $approvedMap = [];
        foreach ($this->serviceCatalogue->all() as $entry) {
            $approvedMap[$entry['name']] = $entry;
        }

        $canManageService = $viewer->canDo(EndpointActionPolicy::SERVICE)
            && (
                $viewer->canDo('securityDevices.commands.operate')
                || $viewer->canDo('securityDevices.commands.manage')
                || $viewer->canDo('securityDevices.commands.control')
                || $viewer->canDo('securityDevices.commands.admin')
            );

        $rawServices = is_array($raw['services'] ?? null) ? $raw['services'] : [];
        $mappedServices = [];
        foreach ($rawServices as $svc) {
            if (! is_array($svc) || ! is_string($svc['name'] ?? null) || ! is_string($svc['state'] ?? null)) {
                continue;
            }
            $name = $svc['name'];
            $catalogueEntry = $approvedMap[$name] ?? null;
            $approved = $catalogueEntry !== null;
            $encodedName = rawurlencode($name);
            $mappedServices[] = [
                'name' => $name,
                'label' => $catalogueEntry['label'] ?? null,
                'state' => $svc['state'],
                'approved' => $approved,
                'allowedOperations' => $catalogueEntry['operations'] ?? [],
                'manageHref' => ($approved && $canManageService)
                    ? "/security-devices/devices/{$device->id}?section=management&action=endpoint.service.manage&command_service_name={$encodedName}"
                    : null,
            ];
        }
        usort($mappedServices, static function (array $a, array $b): int {
            if ($a['approved'] !== $b['approved']) {
                return $a['approved'] ? -1 : 1;
            }

            return strcasecmp($a['name'], $b['name']);
        });
        $servicesTotal = count($mappedServices);
        $servicesShown = array_slice($mappedServices, 0, self::SERVICE_LIMIT);

        $rawSoftware = is_array($raw['software'] ?? null) ? $raw['software'] : [];
        $mappedSoftware = [];
        foreach ($rawSoftware as $item) {
            if (! is_array($item) || ! is_string($item['name'] ?? null)) {
                continue;
            }
            $mappedSoftware[] = [
                'name' => $item['name'],
                'version' => is_string($item['version'] ?? null) ? $item['version'] : null,
                'publisher' => is_string($item['publisher'] ?? null) ? $item['publisher'] : null,
            ];
        }
        usort($mappedSoftware, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        $softwareTotal = count($mappedSoftware);
        $softwareShown = array_slice($mappedSoftware, 0, self::SOFTWARE_LIMIT);

        $probeErrors = [];
        foreach (is_array($raw['probe_errors'] ?? null) ? $raw['probe_errors'] : [] as $err) {
            if (is_string($err)) {
                $probeErrors[] = $err;
            }
        }

        $memTotal = isset($raw['memory_total_bytes']) && is_int($raw['memory_total_bytes']) ? $raw['memory_total_bytes'] : null;
        $memAvail = isset($raw['memory_available_bytes']) && is_int($raw['memory_available_bytes']) ? $raw['memory_available_bytes'] : null;

        return [
            'hostname' => is_string($raw['hostname'] ?? null) ? $raw['hostname'] : null,
            'osVersion' => is_string($raw['os_version'] ?? null) ? $raw['os_version'] : null,
            'architecture' => is_string($raw['architecture'] ?? null) ? $raw['architecture'] : null,
            'memoryTotalBytes' => $memTotal,
            'memoryAvailableBytes' => $memAvail,
            'uptimeSeconds' => isset($raw['uptime_seconds']) && is_int($raw['uptime_seconds']) ? $raw['uptime_seconds'] : null,
            'cpuPercent' => isset($raw['cpu_percent']) && is_numeric($raw['cpu_percent']) ? round((float) $raw['cpu_percent'], 1) : null,
            'disks' => $disks,
            'services' => $servicesShown,
            'servicesTotal' => $servicesTotal,
            'servicesTruncated' => $servicesTotal > count($servicesShown),
            'servicesComplete' => (bool) ($raw['services_complete'] ?? false),
            'software' => $softwareShown,
            'softwareTotal' => $softwareTotal,
            'softwareTruncated' => $softwareTotal > count($softwareShown),
            'softwareComplete' => (bool) ($raw['software_complete'] ?? false),
            'probeErrors' => $probeErrors,
        ];
    }

    /** @return array<string, mixed> */
    private function projectPolicies(User $viewer, Device $device, ?string $currentFingerprint): array
    {
        $actionRow = Schema::hasTable('endpoint_action_policies')
            ? DB::table('endpoint_action_policies')->where('device_id', $device->id)->first()
            : null;
        $action = $actionRow
            ? ['version' => (int) $actionRow->version, 'enabled' => (bool) $actionRow->enabled, 'consentMode' => (string) $actionRow->consent_mode]
            : ['version' => 0, 'enabled' => false, 'consentMode' => 'attended'];

        $restartRow = Schema::hasTable('endpoint_restart_policies')
            ? DB::table('endpoint_restart_policies')->where('device_id', $device->id)->first()
            : null;
        $restart = $restartRow
            ? [
                'version' => (int) $restartRow->version,
                'enabled' => (bool) $restartRow->enabled,
                'consentMode' => (string) $restartRow->consent_mode,
                'minimumWarningSeconds' => (int) $restartRow->minimum_warning_seconds,
            ]
            : ['version' => 0, 'enabled' => false, 'consentMode' => 'attended', 'minimumWarningSeconds' => 60];

        $softwareRow = Schema::hasTable('endpoint_software_policies')
            ? DB::table('endpoint_software_policies')->where('device_id', $device->id)->first()
            : null;
        $software = $softwareRow
            ? ['version' => (int) $softwareRow->version, 'enabled' => (bool) $softwareRow->enabled, 'consentMode' => (string) $softwareRow->consent_mode]
            : ['version' => 0, 'enabled' => false, 'consentMode' => 'attended'];

        $script = $this->readScriptPolicySummary($device, $currentFingerprint);

        return [
            'service' => [
                'available' => $this->actionPolicy->available(),
                ...$action,
                'catalogueCount' => count($this->serviceCatalogue->all()),
                'canManage' => $viewer->canDo(EndpointActionPolicy::MANAGE),
            ],
            'restart' => [
                'available' => $this->restartPolicy->available(),
                ...$restart,
                'canManage' => $viewer->canDo(EndpointRestartPolicy::MANAGE),
            ],
            'software' => [
                'available' => $this->softwarePolicy->available(),
                ...$software,
                'canManage' => $viewer->canDo(EndpointSoftwarePolicy::MANAGE),
            ],
            'script' => [
                'available' => $this->scriptPolicy->available(),
                'unavailableReason' => 'script_execution_not_implemented',
                ...$script,
                'canManage' => $viewer->canDo(EndpointScriptPolicy::MANAGE),
            ],
        ];
    }

    /** @return array{version: int, enabled: bool, consentMode: string} */
    private function readScriptPolicySummary(Device $device, ?string $currentFingerprint): array
    {
        if (! Schema::hasTable('endpoint_script_policy_revisions')) {
            return ['version' => 0, 'enabled' => false, 'consentMode' => 'attended'];
        }
        $row = DB::table('endpoint_script_policy_revisions')
            ->where('device_id', $device->id)
            ->orderByDesc('version')
            ->first();
        if (! $row) {
            return ['version' => 0, 'enabled' => false, 'consentMode' => 'attended'];
        }
        if ($currentFingerprint === null || ! hash_equals((string) $row->assignment_fingerprint, $currentFingerprint)) {
            return ['version' => (int) $row->version, 'enabled' => false, 'consentMode' => 'attended'];
        }
        try {
            $decoded = EndpointScriptContract::decode((string) $row->receipt_json, 8192);
            $policy = $decoded->policy ?? null;
            if ($policy instanceof stdClass && is_bool($policy->enabled ?? null) && is_string($policy->consent_mode ?? null)) {
                return [
                    'version' => (int) $row->version,
                    'enabled' => $policy->enabled,
                    'consentMode' => $policy->consent_mode,
                ];
            }
        } catch (Throwable) {
            // Fall through to safe disabled posture.
        }

        return ['version' => (int) $row->version, 'enabled' => false, 'consentMode' => 'attended'];
    }

    /** @return array<string, mixed>|null */
    private function projectIntune(User $viewer, Device $device): ?array
    {
        $canView = $viewer->canDo('securityDevices.integrations.view')
            || $viewer->canDo('securityDevices.integrations.manage')
            || $viewer->canDo('securityDevices.endpoints.diagnostics')
            || $viewer->canDo('securityDevices.commands.admin');
        if (! $canView) {
            return null;
        }

        $canManageLink = ($viewer->canDo('securityDevices.integrations.manage') || $viewer->canDo('securityDevices.commands.admin'))
            && $viewer->canDo('securityDevices.devices.update');
        $link = Schema::hasTable('endpoint_intune_links')
            ? IntuneDeviceLink::query()->where('device_id', $device->id)->first()
            : null;
        $rawSnapshot = is_array($link?->snapshot) ? $link->snapshot : null;

        return [
            'integrationEnabled' => (bool) config('endpoint_management.intune.enabled', false),
            'canManageLink' => $canManageLink,
            'linkHref' => $canManageLink ? "/security-devices/devices/{$device->id}/endpoint/intune-link" : null,
            'linked' => $link !== null,
            'managedDeviceId' => $link?->managed_device_id,
            'syncStatus' => $link?->sync_status,
            'syncedAt' => $link?->synced_at?->toISOString(),
            'lastAttemptAt' => $link?->last_attempt_at?->toISOString(),
            'snapshot' => $rawSnapshot ? [
                'deviceName' => is_string($rawSnapshot['deviceName'] ?? null) ? $rawSnapshot['deviceName'] : null,
                'operatingSystem' => is_string($rawSnapshot['operatingSystem'] ?? null) ? $rawSnapshot['operatingSystem'] : null,
                'osVersion' => is_string($rawSnapshot['osVersion'] ?? null) ? $rawSnapshot['osVersion'] : null,
                'complianceState' => is_string($rawSnapshot['complianceState'] ?? null) ? $rawSnapshot['complianceState'] : null,
                'isEncrypted' => is_bool($rawSnapshot['isEncrypted'] ?? null) ? $rawSnapshot['isEncrypted'] : null,
                'managementAgent' => is_string($rawSnapshot['managementAgent'] ?? null) ? $rawSnapshot['managementAgent'] : null,
                'managedDeviceOwnerType' => is_string($rawSnapshot['managedDeviceOwnerType'] ?? null) ? $rawSnapshot['managedDeviceOwnerType'] : null,
                'manufacturer' => is_string($rawSnapshot['manufacturer'] ?? null) ? $rawSnapshot['manufacturer'] : null,
                'model' => is_string($rawSnapshot['model'] ?? null) ? $rawSnapshot['model'] : null,
                'serialNumber' => is_string($rawSnapshot['serialNumber'] ?? null) ? $rawSnapshot['serialNumber'] : null,
                'enrolledDateTime' => is_string($rawSnapshot['enrolledDateTime'] ?? null) ? $rawSnapshot['enrolledDateTime'] : null,
                'lastSyncDateTime' => is_string($rawSnapshot['lastSyncDateTime'] ?? null) ? $rawSnapshot['lastSyncDateTime'] : null,
            ] : null,
        ];
    }
}
