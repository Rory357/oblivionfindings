<?php

namespace App\Domain\SecurityDevices\Screens\Setup;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Services\CentralSiteMonitoringReadinessService;
use App\Domain\Monitoring\Services\MonitoringRuntimeHealthService;
use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Screens\Devices\DeviceDirectory;
use App\Domain\SecurityDevices\Screens\Devices\DeviceEntry;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;

/**
 * Setup › Hubs & networks › Networks & scans: "Sites the server checks itself" (drawing pg-sdr-15q), under the
 * server check, for the same admins. The retired Monitoring page's Central Site readiness, in plain words:
 * CentralSiteMonitoringReadinessService decides each Site's state from the viewer's own devices and checks.
 *
 * A Site with a hub is always checked by its hub; the server never reaches into it (S&D rebuild R4).
 */
final class SetupServerReadiness
{
    /** The service's states, as the page says them. */
    private const STATES = [
        'ready' => ['Ready', 'success', 'Checks, map and scan all up to date', 'Nothing to do'],
        'attention' => ['Needs a look', 'warning', 'Ready, but some devices there have a problem', 'Open the Site'],
        'awaiting_evidence' => ['Waiting for first results', 'info', 'Checks are set up; the first answers haven’t come back yet', 'Give it a few minutes'],
        'evidence_incomplete' => ['Map or scan out of date', 'warning', 'The checks answer, but the map is over an hour old or the last scan over a day', 'Scan now'],
        'runtime_unavailable' => ['Checking stopped', 'critical', 'The server’s jobs for checks, results, alerts or maps aren’t running', 'See the server check above'],
        'not_configured' => ['Not set up', 'warning', 'No hub, and the server doesn’t check it', 'Add a hub, or turn on server scans if the server can reach its network'],
    ];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly DeviceDirectory $directory,
        private readonly CoveringHubResolver $hubs,
        private readonly CentralSiteMonitoringReadinessService $readiness,
        private readonly MonitoringRuntimeHealthService $runtime,
    ) {}

    /** @return array{rows: list<array<string, mixed>>, meanings: list<array{tone: string, text: string, at: null}>} */
    public function present(User $viewer): array
    {
        $sites = $this->access->accessibleSites($viewer)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('archived')->orWhere('archived', false))
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get(['id', 'name']);
        if ($sites->isEmpty()) {
            return ['rows' => [], 'meanings' => self::meanings()];
        }
        $siteIds = $sites->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $entries = $this->directory->entries($viewer)->filter(fn (DeviceEntry $entry): bool => $entry->siteId !== null && in_array((int) $entry->siteId, $siteIds, true))->values();
        $devices = $entries->map(fn (DeviceEntry $entry) => $entry->device)->values();
        $sitesByDevice = $entries->mapWithKeys(fn (DeviceEntry $entry): array => [$entry->id() => (int) $entry->siteId]);
        $monitors = $devices->isEmpty() ? collect() : Monitor::query()
            ->whereIn('device_id', $devices->pluck('id'))
            ->with('profile:id,is_active,interval_seconds,stale_after_seconds')
            ->get();
        $reports = $this->readiness->assess($sites, $devices, $monitors, $sitesByDevice, $this->runtime->workerStates());

        $rows = $reports->map(function (array $report): array {
            $hub = $this->hubs->forSite((int) $report['site']['id']);
            [$label, $tone, $note, $next] = $hub !== null
                ? ['Checked by its hub', 'neutral', $hub->site_id === $report['site']['id'] ? 'The hub runs here' : 'Over the link to its hub', 'Nothing to do']
                : (self::STATES[$report['state']] ?? self::STATES['not_configured']);
            $checkedBy = match (true) {
                $hub !== null => CoveringHubResolver::label($hub),
                (int) $report['direct_monitors'] > 0 => 'Oblivion server',
                default => 'Nobody yet',
            };

            return [
                'site' => ['id' => (int) $report['site']['id'], 'name' => (string) $report['site']['name'], 'href' => $report['site']['href']],
                'checked_by' => $checkedBy,
                'state' => ['label' => $label, 'tone' => $tone, 'note' => $note],
                'devices' => (int) $report['devices'],
                'next' => $next,
                // Problems first, then Sites the server checks, then hub Sites.
                'rank' => match (true) {
                    $hub !== null => 3,
                    in_array($tone, ['critical', 'warning'], true) => 0,
                    $label === 'Ready' => 2,
                    default => 1,
                },
            ];
        })->sortBy(fn (array $row): string => $row['rank'].mb_strtolower($row['site']['name']))->values()
            ->map(function (array $row): array {
                unset($row['rank']);

                return $row;
            })->all();

        return ['rows' => $rows, 'meanings' => self::meanings()];
    }

    /** @return list<array{tone: string, text: string, at: null}> */
    private static function meanings(): array
    {
        $lines = [];
        foreach (['ready', 'attention', 'awaiting_evidence', 'evidence_incomplete', 'runtime_unavailable'] as $key) {
            [$label, $tone, $note] = self::STATES[$key];
            $lines[] = ['tone' => $tone, 'text' => "**{$label}:** ".lcfirst($note), 'at' => null];
        }
        $lines[] = ['tone' => 'neutral', 'text' => '**Checked by its hub** or **Not set up:** the server doesn’t check the Site itself', 'at' => null];

        return $lines;
    }
}
