<?php

namespace App\Services\Sites;

use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\EmergencyDrill;
use App\Models\PpeInventory;
use App\Models\Site;
use App\Models\SiteChecklistRun;
use App\Models\SiteDocument;
use App\Models\SiteHazard;
use App\Models\SiteInspectionSchedule;
use App\Models\User;
use App\Services\ShiftCoverageService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SiteProfileAttentionService
{
    private const MAX_ITEMS = 8;

    public function __construct(
        private readonly ShiftCoverageService $coverage,
    ) {}

    /**
     * @return array{
     *   summary: array{total: int, critical: int, warning: int},
     *   groups: array{overview: int, people: int, safety: int, operations: int, admin: int},
     *   items: array<int, array<string, int|string|null>>
     * }
     */
    public function forSite(User $user, Site $site): array
    {
        $items = collect();

        if ($user->canDo('hazards.view')) {
            $items = $items
                ->concat($this->hazards($site))
                ->concat($this->inspections($site))
                ->concat($this->drills($site))
                ->concat($this->ppe($site));
        }

        if ($user->canDo('sites.viewAny')) {
            $items = $items->concat($this->documents($site));
        }

        if ($user->canDo('rostering.viewAny')) {
            $items = $items->concat($this->coverage($site));
        }

        if ($user->canDo('assets.viewAny') || $user->canDo('assets.viewAssigned')) {
            $items = $items->concat($this->assets($site));
        }

        if ($user->canDo('checklists.view')) {
            $items = $items->concat($this->checklists($site));
        }

        if ($user->canDo('securityDevices.devices.view')) {
            $items = $items->concat($this->hardware($site));
        }

        $items = $items->values();
        $critical = $items->where('severity', 'critical')->count();
        $groups = [
            'overview' => 0,
            'people' => 0,
            'safety' => 0,
            'operations' => 0,
            'admin' => 0,
        ];

        foreach ($items as $item) {
            $groups[$this->groupFor((string) $item['source'])]++;
        }

        return [
            'summary' => [
                'total' => $items->count(),
                'critical' => $critical,
                'warning' => $items->count() - $critical,
            ],
            'groups' => $groups,
            'items' => $items
                ->sort(function (array $left, array $right): int {
                    $severity = ['critical' => 0, 'warning' => 1];
                    $severityOrder = ($severity[$left['severity']] ?? 2)
                        <=> ($severity[$right['severity']] ?? 2);

                    if ($severityOrder !== 0) {
                        return $severityOrder;
                    }

                    return ($left['due_date'] ?? '9999-12-31')
                        <=> ($right['due_date'] ?? '9999-12-31');
                })
                ->take(self::MAX_ITEMS)
                ->values()
                ->all(),
        ];
    }

    private function hazards(Site $site): Collection
    {
        $today = now()->toDateString();

        return SiteHazard::query()
            ->where('site_id', $site->id)
            ->whereIn('status', ['open', 'in_progress', 'reopened'])
            ->where(function ($query) use ($today): void {
                $query->whereDate('due_date', '<', $today)
                    ->orWhereDate('review_date', '<=', $today);
            })
            ->orderByRaw("FIELD(severity, 'critical', 'high', 'medium', 'low')")
            ->orderByRaw('COALESCE(review_date, due_date)')
            ->limit(20)
            ->get([
                'id',
                'reference_number',
                'description',
                'severity',
                'risk_rating',
                'due_date',
                'review_date',
            ])
            ->map(function (SiteHazard $hazard): array {
                $dueDate = collect([
                    $hazard->due_date?->toDateString(),
                    $hazard->review_date?->toDateString(),
                ])->filter()->sort()->first();
                $severity = $hazard->severity === 'critical'
                    || $hazard->risk_rating === 'extreme'
                        ? 'critical'
                        : 'warning';

                return $this->item(
                    id: "hazard:{$hazard->id}",
                    source: 'hazard',
                    severity: $severity,
                    title: "Hazard {$hazard->reference_number} needs review",
                    detail: Str::limit($hazard->description, 140),
                    dueDate: $dueDate,
                    tab: 'hazards',
                    href: "/compliance/hazards?hazard={$hazard->id}",
                );
            });
    }

    private function inspections(Site $site): Collection
    {
        return SiteInspectionSchedule::query()
            ->where('site_id', $site->id)
            ->where('is_active', true)
            ->whereDate('next_due_date', '<', now()->toDateString())
            ->orderBy('next_due_date')
            ->limit(12)
            ->get(['id', 'title', 'inspection_type', 'next_due_date'])
            ->map(fn (SiteInspectionSchedule $schedule): array => $this->item(
                id: "inspection:{$schedule->id}",
                source: 'inspection',
                severity: 'warning',
                title: $schedule->title,
                detail: 'Scheduled inspection is overdue.',
                dueDate: $schedule->next_due_date?->toDateString(),
                tab: 'inspections',
                href: "/sites/{$site->id}?tab=inspections",
            ));
    }

    private function drills(Site $site): Collection
    {
        return EmergencyDrill::query()
            ->where('site_id', $site->id)
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->where('scheduled_at', '<', now())
            ->orderBy('scheduled_at')
            ->limit(12)
            ->get(['id', 'title', 'drill_type', 'scheduled_at'])
            ->map(fn (EmergencyDrill $drill): array => $this->item(
                id: "drill:{$drill->id}",
                source: 'drill',
                severity: 'warning',
                title: $drill->title,
                detail: 'Scheduled emergency drill is overdue.',
                dueDate: $drill->scheduled_at?->toDateString(),
                tab: 'drills',
                href: "/health-safety/drills?site_id={$site->id}",
            ));
    }

    private function documents(Site $site): Collection
    {
        return SiteDocument::query()
            ->where('site_id', $site->id)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays(30)->toDateString())
            ->orderBy('expiry_date')
            ->limit(12)
            ->get(['id', 'title', 'category', 'expiry_date'])
            ->map(fn (SiteDocument $document): array => $this->item(
                id: "document:{$document->id}",
                source: 'document',
                severity: 'warning',
                title: $document->title ?: 'Site document',
                detail: $document->expiry_date?->isPast()
                    ? 'Document has expired.'
                    : 'Document expires within 30 days.',
                dueDate: $document->expiry_date?->toDateString(),
                tab: 'documents',
                href: "/sites/{$site->id}?tab=documents",
            ));
    }

    private function coverage(Site $site): Collection
    {
        $summary = collect($this->coverage->buildSiteSummaries(
            now()->startOfDay(),
            now()->addDays(7)->endOfDay(),
            $site->id,
        ))->firstWhere('site_id', $site->id);

        if (! $summary) {
            return collect();
        }

        return collect($summary['alerts'] ?? [])
            ->take(12)
            ->map(function (array $alert, int $index) use ($site): array {
                $missing = (int) ($alert['missing_staff'] ?? 0);

                return $this->item(
                    id: 'coverage:'.($alert['rule_id'] ?? $index).':'.$index,
                    source: 'coverage',
                    severity: $missing >= 2 ? 'critical' : 'warning',
                    title: 'Shift coverage gap',
                    detail: ($alert['window_label'] ?? 'Upcoming coverage window')
                        ." · {$missing} staff missing",
                    dueDate: isset($alert['starts_at'])
                        ? Carbon::parse($alert['starts_at'])->toDateString()
                        : null,
                    tab: 'shift_coverage',
                    href: "/sites/{$site->id}?tab=shift_coverage",
                );
            });
    }

    private function assets(Site $site): Collection
    {
        $today = now()->toDateString();

        return Asset::query()
            ->where('site_id', $site->id)
            ->where(function ($query) use ($today): void {
                $query->where(function ($inspection) use ($today): void {
                    $inspection->where('requires_inspection', true)
                        ->whereDate('inspection_due_at', '<=', $today);
                })->orWhere(function ($maintenance) use ($today): void {
                    $maintenance->where('requires_maintenance', true)
                        ->whereDate('maintenance_due_at', '<=', $today);
                });
            })
            ->orderByRaw('LEAST(COALESCE(inspection_due_at, ?), COALESCE(maintenance_due_at, ?))', [
                '9999-12-31',
                '9999-12-31',
            ])
            ->limit(12)
            ->get([
                'id',
                'name',
                'asset_tag',
                'inspection_due_at',
                'maintenance_due_at',
            ])
            ->map(function (Asset $asset): array {
                $dueDate = collect([
                    $asset->inspection_due_at?->toDateString(),
                    $asset->maintenance_due_at?->toDateString(),
                ])->filter()->sort()->first();

                return $this->item(
                    id: "asset:{$asset->id}",
                    source: 'asset',
                    severity: 'warning',
                    title: $asset->name,
                    detail: 'Asset inspection or maintenance is due.',
                    dueDate: $dueDate,
                    tab: 'assets',
                    href: "/fleet-assets/assets/{$asset->id}",
                );
            });
    }

    private function ppe(Site $site): Collection
    {
        $today = now()->toDateString();

        return PpeInventory::query()
            ->with('ppeType:id,name')
            ->where('site_id', $site->id)
            ->whereNotIn('status', ['disposed'])
            ->where(function ($query) use ($today): void {
                $query->whereDate('next_inspection_due', '<=', $today)
                    ->orWhereDate('expiry_date', '<=', now()->addDays(30)->toDateString());
            })
            ->orderByRaw('LEAST(COALESCE(next_inspection_due, ?), COALESCE(expiry_date, ?))', [
                '9999-12-31',
                '9999-12-31',
            ])
            ->limit(12)
            ->get([
                'id',
                'ppe_type_id',
                'serial_number',
                'next_inspection_due',
                'expiry_date',
            ])
            ->map(function (PpeInventory $ppe) use ($site): array {
                $dueDate = collect([
                    $ppe->next_inspection_due?->toDateString(),
                    $ppe->expiry_date?->toDateString(),
                ])->filter()->sort()->first();

                return $this->item(
                    id: "ppe:{$ppe->id}",
                    source: 'ppe',
                    severity: 'warning',
                    title: $ppe->ppeType?->name ?: 'PPE item',
                    detail: 'PPE inspection or expiry action is due.',
                    dueDate: $dueDate,
                    tab: 'ppe',
                    href: "/health-safety/ppe?site_id={$site->id}&item={$ppe->id}",
                );
            });
    }

    private function checklists(Site $site): Collection
    {
        return SiteChecklistRun::query()
            ->with('template:id,name')
            ->where('site_id', $site->id)
            ->whereDate('scheduled_date', '<', now()->toDateString())
            ->whereIn('status', ['scheduled', 'in_progress', 'overdue'])
            ->orderBy('scheduled_date')
            ->limit(12)
            ->get(['id', 'template_id', 'scheduled_date', 'status'])
            ->map(fn (SiteChecklistRun $run): array => $this->item(
                id: "checklist:{$run->id}",
                source: 'checklist',
                severity: 'warning',
                title: $run->template?->name ?: 'Site checklist',
                detail: 'Checklist run is overdue.',
                dueDate: $run->scheduled_date?->toDateString(),
                tab: 'checklists',
                href: "/sites/{$site->id}/checklists?run={$run->id}",
            ));
    }

    private function hardware(Site $site): Collection
    {
        return Device::query()
            ->needingAttention()
            ->whereHas('assignments', fn ($query) => $query
                ->active()
                ->forTarget(DeviceAssignment::TARGET_SITE, $site->id))
            ->orderByRaw("FIELD(health_status, 'critical', 'warning', 'unknown', 'healthy')")
            ->limit(12)
            ->get(['id', 'name', 'device_uid', 'status', 'health_status', 'last_seen_at'])
            ->map(function (Device $device): array {
                $health = $device->health_status?->value;

                return $this->item(
                    id: "hardware:{$device->id}",
                    source: 'hardware',
                    severity: $health === HealthStatus::Critical->value
                        ? 'critical'
                        : 'warning',
                    title: $device->name,
                    detail: $health === HealthStatus::Critical->value
                        ? 'Device health is critical.'
                        : 'Device is offline or degraded.',
                    dueDate: null,
                    tab: 'hardware',
                    href: "/security-devices/devices/{$device->id}",
                );
            });
    }

    /**
     * @return array{id: string, source: string, severity: string, title: string, detail: string, due_date: ?string, tab: string, href: string}
     */
    private function item(
        string $id,
        string $source,
        string $severity,
        string $title,
        string $detail,
        ?string $dueDate,
        string $tab,
        string $href,
    ): array {
        return [
            'id' => $id,
            'source' => $source,
            'severity' => $severity,
            'title' => $title,
            'detail' => $detail,
            'due_date' => $dueDate,
            'tab' => $tab,
            'href' => $href,
        ];
    }

    private function groupFor(string $source): string
    {
        return match ($source) {
            'coverage' => 'people',
            'hazard', 'inspection', 'drill', 'ppe' => 'safety',
            'asset', 'checklist', 'hardware' => 'operations',
            'document' => 'admin',
            default => 'overview',
        };
    }
}
