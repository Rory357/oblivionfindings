<?php

namespace App\Domain\SecurityDevices\Screens\Devices;

use App\Domain\Monitoring\Discovery\Data\DeviceIdentification;
use App\Domain\Monitoring\Discovery\Data\DiscoveredIdentity;
use App\Domain\Monitoring\Discovery\Models\DiscoveryCandidate;
use App\Domain\Monitoring\Discovery\Models\DiscoveryRun;
use App\Domain\Monitoring\Discovery\Services\DeviceFingerprintService;
use App\Domain\SecurityDevices\Hubs\Services\CoveringHubResolver;
use App\Domain\SecurityDevices\Screens\DeviceKind;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Found devices (drawing pg-sdr-14): one scan's results as New · Already added · Ignored, with
 * what we think each one is, how sure we are and why. Pop-up 4 reads progress() while it runs.
 */
final class FoundDevicesPresenter
{
    public const array TABS = ['new', 'added', 'ignored'];

    private const array ADDED_ACTIONS = ['adopted', 'same_as', 'merged'];

    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly DeviceFingerprintService $fingerprinter = new DeviceFingerprintService,
    ) {}

    /** The run, only when its Site (and its hub's Site) are the viewer's. */
    public function run(User $viewer, int $runId): DiscoveryRun
    {
        $siteIds = $this->access->accessibleSiteIds($viewer);
        $run = DiscoveryRun::query()
            ->with(['scope:id,name,site_id,collector_id,cidrs', 'scope.site:id,name', 'scope.collector:id,name,site_id'])
            ->whereKey($runId)
            ->whereHas('scope', fn ($scope) => $siteIds === [] ? $scope->whereRaw('1 = 0') : $scope->whereIn('site_id', $siteIds))
            ->firstOrFail();
        $collector = $run->scope?->collector;
        abort_if($collector !== null && ! in_array((int) $collector->site_id, $siteIds, true), 404);

        return $run;
    }

    /**
     * @param  array{tab?: mixed, q?: mixed, sure?: mixed}  $input
     * @return array<string, mixed>
     */
    public function present(User $viewer, DiscoveryRun $run, array $input): array
    {
        $tab = in_array($input['tab'] ?? null, self::TABS, true) ? $input['tab'] : 'new';
        $search = mb_strtolower(trim((string) ($input['q'] ?? '')));
        $unsureOnly = ($input['sure'] ?? null) === 'low';
        $candidates = $this->candidates($viewer, $run);

        $mapped = $candidates->map(fn (DiscoveryCandidate $candidate): array => $this->row($candidate));
        $groups = $mapped->groupBy('tab');
        $rows = collect($groups->get($tab, []))
            ->filter(fn (array $row): bool => ! $unsureOnly || $row['sure']['band'] === 'low')
            ->filter(fn (array $row): bool => $search === '' || str_contains(mb_strtolower(implode(' ', array_filter([
                $row['guess'], $row['sub'], $row['address'], $row['maker'],
            ]))), $search))
            ->values();

        $canManage = $viewer->canDo('securityDevices.integrations.manage');

        return [
            'scan' => $this->scan($viewer, $run),
            'tab' => $tab,
            'filters' => ['q' => trim((string) ($input['q'] ?? '')), 'sure' => $unsureOnly ? 'low' : 'all'],
            'counts' => [
                'new' => count($groups->get('new', [])),
                'added' => count($groups->get('added', [])),
                'ignored' => count($groups->get('ignored', [])),
                'unsure' => collect($groups->get('new', []))->where('sure.band', 'low')->count(),
                // "Add all" adds the new ones we're sure aren't already here; the rest ask Same device? first.
                'addable' => collect($groups->get('new', []))->where('actionable', true)->where('decision', 'proposed')->count(),
            ],
            'rows' => $rows->all(),
            'can' => [
                'add' => $canManage && $viewer->canDo('securityDevices.devices.create'),
                'same_as' => $canManage && $viewer->canDo('securityDevices.devices.update'),
                'ignore' => $canManage,
                'scan' => $canManage,
            ],
        ];
    }

    /**
     * Pop-up 4: progress in plain numbers and the first finds.
     *
     * @return array<string, mixed>
     */
    public function progress(User $viewer, DiscoveryRun $run): array
    {
        $run->loadCount([
            'results as checked_count' => fn ($query) => $query->where('outcome', '!=', 'pending'),
        ]);
        $finds = $this->candidates($viewer, $run)
            ->sortBy('id')
            ->take(4)
            ->map(fn (DiscoveryCandidate $candidate): array => $this->row($candidate))
            ->map(fn (array $row): array => [
                'address' => $row['address'],
                'text' => implode(' · ', array_filter([
                    $row['guess'],
                    match ($row['tab']) {
                        'added' => $row['matched'] !== null ? 'already added as '.$row['matched']['name'] : 'already added',
                        'ignored' => 'ignored',
                        default => 'new',
                    },
                    $row['sure']['pct'] !== null ? $row['sure']['pct'].'% sure' : null,
                ])),
            ])
            ->values();

        return [
            ...$this->scan($viewer, $run),
            'checked' => (int) $run->checked_count,
            'found' => (int) $run->found_count,
            'finds' => $finds->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function scan(User $viewer, DiscoveryRun $run): array
    {
        $scope = $run->scope;
        $collector = $scope?->collector;
        $range = collect((array) ($scope?->cidrs ?? []))->first(fn (mixed $cidr): bool => is_string($cidr));

        return [
            'id' => (int) $run->id,
            'scope_id' => $scope?->id !== null ? (int) $scope->id : null,
            'site_id' => $scope?->site_id !== null ? (int) $scope->site_id : null,
            'site' => $scope?->site?->name,
            'range' => is_string($range) ? $range : null,
            'runs_from' => $collector !== null ? CoveringHubResolver::label($collector) : 'the Oblivion server',
            'status' => $run->status,
            'running' => in_array($run->status, ['queued', 'running'], true),
            'planned' => (int) $run->planned_targets,
            'started_at' => $run->started_at?->toIso8601String() ?? $run->created_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
            'cancelled' => $run->status === 'cancelled',
        ];
    }

    /** @return Collection<int, DiscoveryCandidate> */
    private function candidates(User $viewer, DiscoveryRun $run): Collection
    {
        $visibleDeviceIds = $this->access->visibleDevices($viewer)->pluck('devices.id');

        return DiscoveryCandidate::query()
            ->where('discovery_run_id', $run->id)
            ->where(function ($query) use ($visibleDeviceIds): void {
                $query->whereNull('canonical_device_id');
                if ($visibleDeviceIds->isNotEmpty()) {
                    $query->orWhereIn('canonical_device_id', $visibleDeviceIds);
                }
            })
            ->whereNotIn('decision', ['rejected', 'excluded'])
            ->with('canonicalDevice:id,name')
            ->orderByDesc('confidence')
            ->orderBy('id')
            ->limit(500)
            ->get();
    }

    /** @return array<string, mixed> */
    private function row(DiscoveryCandidate $candidate): array
    {
        $snapshot = is_array($candidate->evidence_snapshot) ? $candidate->evidence_snapshot : [];
        $hostname = $this->safeHostname($snapshot['hostname'] ?? null);
        $address = $this->safeIp(($snapshot['addresses'] ?? [])[0] ?? null);
        $mac = $this->safeMac(($snapshot['mac_addresses'] ?? [])[0] ?? null);
        $identification = $this->fingerprinter->identify(new DiscoveredIdentity(
            provider: is_string($snapshot['provider'] ?? null) && preg_match('/^[a-z0-9_-]{1,64}$/i', $snapshot['provider']) === 1 ? $snapshot['provider'] : null,
            providerId: is_string($snapshot['provider_id'] ?? null) ? $snapshot['provider_id'] : null,
            serialNumber: is_string($snapshot['serial_number'] ?? null) ? $snapshot['serial_number'] : null,
            hardwareId: is_string($snapshot['hardware_id'] ?? null) ? $snapshot['hardware_id'] : null,
            macAddresses: $mac !== null ? [$mac] : [],
            certificateFingerprint: is_string($snapshot['certificate_fingerprint'] ?? null) ? $snapshot['certificate_fingerprint'] : null,
            hostname: $hostname,
            addresses: $address !== null ? [$address] : [],
            fingerprint: is_string($snapshot['fingerprint'] ?? null) ? $snapshot['fingerprint'] : null,
            signals: is_array($snapshot['signals'] ?? null) ? $snapshot['signals'] : [],
        ));

        $tab = match (true) {
            $candidate->review_action === 'ignored' => 'ignored',
            in_array($candidate->review_action, self::ADDED_ACTIONS, true), $candidate->decision === 'matched' => 'added',
            default => 'new',
        };
        $unreviewed = $candidate->reviewed_at === null && in_array($candidate->decision, ['proposed', 'review'], true);
        $device = $candidate->canonicalDevice;

        return [
            'id' => (int) $candidate->id,
            'tab' => $tab,
            'guess' => $identification->typeLabel,
            'sub' => implode(' · ', array_filter([
                trim(implode(' ', array_filter([$identification->manufacturer, $identification->model]))) ?: null,
                $hostname !== null ? '“'.$hostname.'”' : null,
            ])) ?: null,
            'kind' => DeviceKind::from_($identification->domain, $identification->category, (string) $identification->subcategory)->value,
            'address' => $address,
            'maker' => $identification->manufacturer ?? $identification->macVendor,
            'why' => $this->why($identification),
            'sure' => [
                'pct' => $identification->confidence,
                'band' => $identification->confidenceBand,
                'label' => match ($identification->confidenceBand) {
                    'high' => 'Very sure',
                    'medium' => 'Fairly sure',
                    default => 'Not sure',
                },
            ],
            'suggested_name' => $identification->suggestedName,
            // A "review" candidate may point at the closest existing device; Same device? shows it first.
            'closest' => $device !== null && $candidate->decision === 'review' ? ['id' => (int) $device->id, 'name' => (string) $device->name] : null,
            'matched' => $device !== null && $tab === 'added' ? [
                'id' => (int) $device->id,
                'name' => (string) $device->name,
                'href' => '/security-devices/devices/'.$device->id,
            ] : null,
            'actionable' => $unreviewed,
            'decision' => $candidate->decision,
        ];
    }

    private function why(DeviceIdentification $identification): string
    {
        $reason = $identification->reason;
        $marker = ' — matched ';
        $at = mb_strpos($reason, $marker);

        return $at === false ? $reason : 'Matched '.mb_substr($reason, $at + mb_strlen($marker));
    }

    private function safeHostname(mixed $raw): ?string
    {
        $value = is_string($raw) ? trim($raw) : '';

        return $value !== '' && strlen($value) <= 253
            && preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(?:\.(?!-)[a-z0-9-]{1,63}(?<!-))*$/i', $value) === 1
            ? $value
            : null;
    }

    private function safeIp(mixed $raw): ?string
    {
        $value = is_string($raw) ? trim($raw) : '';

        return filter_var($value, FILTER_VALIDATE_IP) !== false ? $value : null;
    }

    private function safeMac(mixed $raw): ?string
    {
        $value = is_string($raw) ? trim($raw) : '';
        if (preg_match('/^(?:[0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/', $value) === 1) {
            return strtoupper(str_replace('-', ':', $value));
        }

        return preg_match('/^[0-9A-Fa-f]{12}$/', $value) === 1 ? implode(':', str_split(strtoupper($value), 2)) : null;
    }
}
