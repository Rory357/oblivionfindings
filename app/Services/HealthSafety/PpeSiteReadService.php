<?php

namespace App\Services\HealthSafety;

use App\Models\PpeInventory;
use App\Models\Site;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\UserSiteAccessService;
use App\Services\Sites\Calendar\CalendarItem;
use App\Support\HealthSafety\PpeReturn;
use DateTimeZone;
use Illuminate\Support\Carbon;
use LogicException;

/** Site projections retain source uncertainty; they never certify release, fit or completed inspection. */
final class PpeSiteReadService
{
    private const DATES = ['next_inspection_due' => 'PPE inspection due', 'expiry_date' => 'PPE expires'];

    public function __construct(private readonly PpeAccessService $access, private readonly PpeCommandService $commands) {}

    /** The existing hundred-row Site pane, with repairs proved and placed before the display cap. */
    public function profileForSite(User $viewer, int $siteId): array
    {
        $this->timezone(); // An invalid configured zone is refused before any read, with its own message.
        return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($siteId): array {
            $this->access->assertOperation($current, 'view');
            $site = $this->site($siteId, $current, $reads, $sites);
            $today = now($this->timezone())->toDateString();
            $rows = $this->rows($current, $reads, [$siteId]);
            $items = [];
            $repairs = 0;
            $units = 0;
            foreach ($rows as $row) {
                $proof = $this->proof($row, $reads);
                if ($proof['state'] === 'repair_required') {
                    $repairs++;
                    $items[] = ['id' => (int) $row->id, 'name' => 'PPE source needs repair',
                        'condition' => null, 'quantity' => null, 'status' => null, 'location' => null,
                        'expiry_date' => null, 'next_inspection_due' => null, 'expired' => null, 'inspection_due' => null,
                        'href' => $this->href((int) $row->id, $siteId),
                        'source_integrity' => 'repair_required', 'attribution_state' => 'repair_required'];
                    continue;
                }
                if (in_array($row->status, ['disposed', 'lost'], true)) {
                    continue;
                }
                $expiry = $row->expiry_date?->toDateString();
                $inspection = $row->next_inspection_due?->toDateString();
                $units += (int) $row->quantity;
                $items[] = ['id' => (int) $row->id, 'name' => $proof['name'] ?: trim($row->brand.' '.$row->model),
                    'condition' => $row->condition, 'quantity' => (int) $row->quantity, 'status' => $row->status,
                    'location' => $row->location, 'expiry_date' => $expiry, 'next_inspection_due' => $inspection,
                    'expired' => $expiry !== null && $expiry < $today,
                    'inspection_due' => $inspection !== null && $inspection <= $today,
                    'href' => $this->href((int) $row->id, $siteId),
                    'source_integrity' => $this->integrity($proof['state']), 'attribution_state' => $proof['state']];
            }
            $this->sortRepairs($items, 'expiry_date', true);
            $total = count($items);
            $items = array_slice($items, 0, 100);

            return ['locked' => false, 'items' => $items, 'can_manage' => $this->canManageSite($current, $site, $reads),
                'href' => PpeReturn::fromRegister(['site_id' => $siteId]),
                'physical_record_count' => $rows->count(), 'displayed_count' => count($items),
                'truncated' => $total > count($items), 'repair_count' => $repairs,
                'confirmed_units' => $repairs > 0 ? null : $units];
        });
    }

    /** Recorded operational counts. Damaged candidate facts cannot be filtered into a trustworthy zero. */
    public function kpisForViewer(User $viewer, ?array $siteIds = null): array
    {
        $this->validateSites($siteIds);
        $this->timezone();

        return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($siteIds): array {
            $this->access->assertOperation($current, 'view');
            $admitted = $this->admittedSites($siteIds, $sites);
            $clock = now($this->timezone());
            $today = $clock->toDateString();
            $horizon = $clock->copy()->addDays(60)->toDateString();
            $counts = ['ppe_inspections_overdue' => 0, 'ppe_expiring' => 0, 'ppe_unacknowledged' => 0];
            $repairs = array_fill_keys(array_keys($counts), 0);
            $states = array_fill_keys(array_keys($counts), 'intact');
            if ($admitted === []) {
                return ['counts' => $counts, 'integrity' => $states, 'repair_counts' => $repairs,
                    'physical_inventory_count' => 0, 'physical_allocation_count' => 0];
            }
            $rows = $this->rows($current, $reads, $admitted);
            $roots = [];
            $proofs = [];
            foreach ($rows as $row) {
                $id = (int) $row->id;
                $roots[$id] = $row;
                $proofs[$id] = $proof = $this->proof($row, $reads);
                foreach (['ppe_inspections_overdue', 'ppe_expiring'] as $key) {
                    $states[$key] = $this->combineIntegrity($states[$key], $this->integrity($proof['state']));
                    if ($proof['state'] === 'repair_required') {
                        $repairs[$key]++;
                    }
                }
                if ($proof['state'] === 'repair_required' || in_array($row->status, ['condemned', 'disposed'], true)) {
                    continue;
                }
                $inspection = $row->next_inspection_due?->toDateString();
                $expiry = $row->expiry_date?->toDateString();
                $counts['ppe_inspections_overdue'] += (int) ($inspection !== null && $inspection < $today);
                $counts['ppe_expiring'] += (int) ($expiry !== null && $expiry <= $horizon);
            }
            // The entire admitted child set is proved before returned/acknowledged predicates are used.
            $allocations = $this->access->allocationQuery($current, $reads, $admitted)->orderBy('id')->get();
            foreach ($allocations as $allocation) {
                $id = (int) $allocation->ppe_inventory_id;
                $proof = $proofs[$id] ?? ['state' => 'repair_required'];
                $state = $proof['state'] === 'repair_required' || ! isset($roots[$id])
                    ? 'repair_required' : $this->commands->sourceState($roots[$id], $allocation);
                $integrity = $this->combineIntegrity($this->integrity($proof['state']), $this->integrity($state));
                $states['ppe_unacknowledged'] = $this->combineIntegrity($states['ppe_unacknowledged'], $integrity);
                if ($integrity === 'repair_required') {
                    $repairs['ppe_unacknowledged']++;
                    continue;
                }
                $counts['ppe_unacknowledged'] += (int) ($allocation->returned_at === null && ! $allocation->acknowledged);
            }
            foreach ($counts as $key => $count) {
                if ($repairs[$key] > 0) {
                    $counts[$key] = null;
                }
            }

            return ['counts' => $counts, 'integrity' => $states, 'repair_counts' => $repairs,
                'physical_inventory_count' => $rows->count(), 'physical_allocation_count' => $allocations->count()];
        });
    }

    /** Feed candidates include undated repair notices; callers must retain them separately from dated sorting. */
    public function expiringForViewer(User $viewer, int|array|null $siteId = null, int $withinDays = 60, int $limit = 20): array
    {
        $requested = is_int($siteId) ? [$siteId] : $siteId;
        $this->validateSites($requested);
        if ($withinDays < 0 || $withinDays > 3650 || $limit < 1 || $limit > 100) {
            throw new LogicException('PPE feed days and display limit must be bounded non-negative days and one to one hundred items.');
        }
        $this->timezone();

        return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($requested, $withinDays, $limit): array {
            $this->access->assertOperation($current, 'view');
            $admitted = $this->admittedSites($requested, $sites);
            $siteRows = [];
            foreach ($admitted as $id) {
                $siteRows[$id] = $this->site($id, $current, $reads, $sites);
            }
            $timezone = $this->timezone();
            $today = now($timezone)->startOfDay();
            $horizon = $today->copy()->addDays($withinDays)->toDateString();
            if ($admitted === []) {
                return ['items' => [], 'confirmed_count' => 0, 'total' => 0, 'repair_count' => 0,
                    'physical_record_count' => 0, 'truncated' => false, 'source_integrity' => 'intact'];
            }
            $rows = $this->rows($current, $reads, $admitted);
            $items = [];
            $repairs = 0;
            $confirmed = 0;
            $integrity = 'intact';
            foreach ($rows as $row) {
                $proof = $this->proof($row, $reads);
                $integrity = $this->combineIntegrity($integrity, $this->integrity($proof['state']));
                $site = $siteRows[(int) $row->site_id];
                $base = ['inventory_id' => (int) $row->id, 'site_id' => (int) $site->id, 'site' => $site->name,
                    'source_integrity' => $this->integrity($proof['state']), 'attribution_state' => $proof['state']];
                if ($proof['state'] === 'repair_required') {
                    $repairs++;
                    $items[] = [...$base, 'type' => 'ppe_repair', 'type_label' => 'PPE source needs repair',
                        'label' => 'PPE source needs repair', 'due_date' => null, 'days_until' => null,
                        'register_url' => $this->href((int) $row->id, (int) $site->id)];
                    continue;
                }
                if (in_array($row->status, ['condemned', 'disposed'], true)) {
                    continue;
                }
                foreach (array_keys(self::DATES) as $field) {
                    $day = $row->$field?->toDateString();
                    if ($day === null || $day > $horizon) {
                        continue;
                    }
                    $inspection = $field === 'next_inspection_due';
                    $back = PpeReturn::fromRegister(['tab' => $inspection ? 'inv_inspection' : 'inv_expiring', 'site_id' => (int) $site->id]);
                    $items[] = [...$base, 'type' => $inspection ? 'ppe_inspection' : 'ppe_expiry',
                        'type_label' => $inspection ? 'PPE inspection' : 'PPE expiry', 'label' => trim(($proof['name'] ?: 'PPE item').($row->serial_number ? ' · '.$row->serial_number : '')),
                        'due_date' => $day, 'days_until' => (int) $today->diffInDays(Carbon::parse($day.' 00:00:00', $timezone), false),
                        'register_url' => PpeReturn::record('inventory', (int) $row->id, $back)];
                    $confirmed++;
                }
            }
            $this->sortRepairs($items, 'due_date');
            $total = count($items);
            $items = array_slice($items, 0, $limit);

            return ['items' => $items, 'confirmed_count' => $confirmed, 'total' => $repairs > 0 ? null : $confirmed,
                'repair_count' => $repairs, 'physical_record_count' => $rows->count(), 'truncated' => $total > count($items),
                'source_integrity' => $integrity];
        });
    }

    /** Existing Site attention shape, with explicit per-item source integrity. No arbitrary display cap hides repairs. */
    public function attentionForSite(User $viewer, int $siteId): array
    {
        $this->timezone();
        return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($siteId): array {
            $this->access->assertOperation($current, 'view');
            $this->site($siteId, $current, $reads, $sites);
            $clock = now($this->timezone());
            $today = $clock->toDateString();
            $soon = $clock->copy()->addDays(30)->toDateString();
            $items = [];
            foreach ($this->rows($current, $reads, [$siteId]) as $row) {
                $proof = $this->proof($row, $reads);
                $href = $this->href((int) $row->id, $siteId);
                if ($proof['state'] === 'repair_required') {
                    $items[] = ['id' => 'ppe:'.$row->id, 'source' => 'ppe', 'severity' => 'warning',
                        'title' => 'PPE source needs repair', 'detail' => 'Inspection and expiry facts are unconfirmed.',
                        'due_date' => null, 'tab' => 'ppe', 'href' => $href,
                        'source_integrity' => 'repair_required', 'attribution_state' => 'repair_required'];
                    continue;
                }
                if ($row->status === 'disposed') {
                    continue;
                }
                $inspection = $row->next_inspection_due?->toDateString();
                $expiry = $row->expiry_date?->toDateString();
                if (($inspection === null || $inspection > $today) && ($expiry === null || $expiry > $soon)) {
                    continue;
                }
                $dates = array_values(array_filter([$inspection, $expiry], fn ($date) => $date !== null));
                sort($dates);
                $items[] = ['id' => 'ppe:'.$row->id, 'source' => 'ppe', 'severity' => 'warning',
                    'title' => $proof['name'] ?: 'PPE item', 'detail' => 'PPE inspection or expiry action is due.',
                    'due_date' => $dates[0] ?? null, 'tab' => 'ppe', 'href' => $href,
                    'source_integrity' => $proof['state'] === 'recorded' ? 'intact' : 'legacy_unknown',
                    'attribution_state' => $proof['state']];
            }
            usort($items, fn ($a, $b) => (($a['source_integrity'] === 'repair_required' ? 0 : 1)
                <=> ($b['source_integrity'] === 'repair_required' ? 0 : 1))
                ?: (($a['due_date'] ?? '9999-12-31') <=> ($b['due_date'] ?? '9999-12-31')) ?: strcmp($a['id'], $b['id']));

            return $items;
        });
    }

    /** CalendarItem scalar DTOs. Null-start repair markers must be surfaced as notices, never given an invented date. */
    public function obligationsForViewer(array $siteIds, Carbon $start, Carbon $end, ?User $viewer): array
    {
        if ($viewer === null || $siteIds === []) {
            return [];
        }
        foreach ($siteIds as $id) {
            if (! is_int($id) || $id < 1) {
                throw new LogicException('PPE calendar Sites must be canonical positive integer identities.');
            }
        }
        $timezone = $this->timezone();
        $firstDay = $start->copy()->timezone($timezone)->toDateString();
        $lastDay = $end->copy()->timezone($timezone)->toDateString();
        if ($lastDay < $firstDay) {
            throw new LogicException('The PPE calendar end must follow its start.');
        }

        return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($siteIds, $firstDay, $lastDay, $timezone): array {
            $this->access->assertOperation($current, 'view');
            $admitted = array_values(array_unique(array_intersect($siteIds, $sites)));
            sort($admitted);
            $siteRows = [];
            foreach ($admitted as $siteId) {
                $siteRows[$siteId] = $this->site($siteId, $current, $reads, $sites);
            }
            if ($admitted === []) {
                return [];
            }
            $items = [];
            $today = now($timezone)->toDateString();
            foreach ($this->rows($current, $reads, $admitted) as $row) {
                $proof = $this->proof($row, $reads);
                $site = $siteRows[(int) $row->site_id];
                $siteDto = ['id' => (int) $site->id, 'name' => $site->name, 'type' => $site->type];
                $href = $this->href((int) $row->id, (int) $site->id);
                if ($proof['state'] === 'repair_required') {
                    $items[] = [...(new CalendarItem(id: 'ppe-'.$row->id.'-repair', source: 'ppe', group: 'auto',
                        title: 'PPE source needs repair', start: null, allDay: true, status: 'repair_required',
                        site: $siteDto, link: $href, desc: 'Inspection and expiry facts are unconfirmed.'))->toArray(),
                        'source_integrity' => 'repair_required', 'attribution_state' => 'repair_required'];
                    continue;
                }
                if (in_array($row->status, ['condemned', 'disposed'], true)) {
                    continue;
                }
                $label = trim(($proof['name'] ?: 'PPE item').($row->serial_number ? ' · '.$row->serial_number : ''));
                foreach (self::DATES as $field => $suffix) {
                    $day = $row->$field?->toDateString();
                    if ($day === null || $day < $firstDay || $day > $lastDay) {
                        continue;
                    }
                    // Date-only source values become midnight in the worker calendar, never server/UTC midnight.
                    $at = Carbon::parse($day.' 00:00:00', $timezone)->toIso8601String();
                    $items[] = [...(new CalendarItem(id: 'ppe-'.$row->id.'-'.$field, source: 'ppe', group: 'auto',
                        title: $label.' — '.$suffix, start: $at, allDay: true,
                        status: $day < $today ? 'overdue' : 'scheduled', ref: $row->serial_number,
                        site: $siteDto, link: $href))->toArray(),
                        'source_integrity' => $proof['state'] === 'recorded' ? 'intact' : 'legacy_unknown',
                        'attribution_state' => $proof['state']];
                }
            }

            return $items;
        });
    }

    private function rows(User $current, CurrentAuthorizationReads $reads, array $sites)
    {
        // Status and dates cannot exclude a damaged row before its immutable source is proved.
        return $this->access->inventoryQuery($current, $reads, $sites)->orderBy('id')->get();
    }

    private function validateSites(?array $sites): void
    {
        foreach ($sites ?? [] as $id) {
            if (! is_int($id) || $id < 1) {
                throw new LogicException('PPE Sites must be canonical positive integer identities.');
            }
        }
    }

    private function admittedSites(?array $requested, array $sites): array
    {
        $admitted = array_values(array_unique($requested === null ? $sites : array_intersect($requested, $sites)));
        sort($admitted);

        return $admitted;
    }

    private function integrity(string $state): string
    {
        return $state === 'recorded' ? 'intact' : $state;
    }

    private function combineIntegrity(string $first, string $second): string
    {
        if (in_array('repair_required', [$first, $second], true)) {
            return 'repair_required';
        }

        return in_array('legacy_unknown', [$first, $second], true) ? 'legacy_unknown' : 'intact';
    }

    private function sortRepairs(array &$items, string $date, bool $nullDatesFirst = false): void
    {
        usort($items, fn ($a, $b) => (($a['source_integrity'] === 'repair_required' ? 0 : 1)
            <=> ($b['source_integrity'] === 'repair_required' ? 0 : 1))
            ?: (($a[$date] ?? ($nullDatesFirst ? '' : '9999-12-31')) <=> ($b[$date] ?? ($nullDatesFirst ? '' : '9999-12-31')))
            ?: (($a['id'] ?? $a['inventory_id']) <=> ($b['id'] ?? $b['inventory_id']))
            ?: strcmp($a['type'] ?? '', $b['type'] ?? ''));
    }

    /** SitePolicy::update parity using its distinct current Site graph, never the H&S bypass or ordinary cache. */
    private function canManageSite(User $current, Site $site, CurrentAuthorizationReads $reads): bool
    {
        if (! $current->canDo('hazards.manage') || ! $current->canDo('sites.update') || $site->archived) {
            return false;
        }
        $types = ['head_office' => 'sites.type.head_office.view', 'house' => 'sites.type.house.view',
            'facility' => 'sites.type.facility.view', 'residential' => 'sites.type.house.view'];
        $restrictedTypes = array_filter(array_unique(array_values($types)), fn ($permission) => $current->canDo($permission));
        if ($restrictedTypes !== [] && (! isset($types[$site->type]) || ! $current->canDo($types[$site->type]))) {
            return false;
        }
        $siteIds = app(UserSiteAccessService::class)->accessibleSiteIds($current, ['sites.viewAll'], $reads);

        return in_array((int) $site->id, $siteIds, true);
    }

    private function site(int $id, User $current, CurrentAuthorizationReads $reads, array $sites): Site
    {
        $this->access->assertSite($id, $current, $reads, $sites);

        return $reads->query(Site::query())->active()->notArchived()->whereNull('archived_at')->whereKey($id)->firstOrFail(['id', 'name', 'type', 'archived']);
    }

    private function proof(PpeInventory $row, CurrentAuthorizationReads $reads): array
    {
        $state = $this->commands->sourceState($row);
        if ($state === 'repair_required') {
            return ['state' => 'repair_required', 'name' => null];
        }
        // A missing/archived catalogue identity is damage; no permissive fallback supplies a name or standard.
        $type = $reads->query(\App\Models\PpeType::query())->whereKey($row->ppe_type_id)->first();
        if ($type === null || $this->commands->sourceState($type) === 'repair_required') {
            return ['state' => 'repair_required', 'name' => null];
        }

        return ['state' => $state === 'recorded' && (int) $type->source_schema_version === 1 ? 'recorded' : 'legacy_unknown',
            'name' => $type->name];
    }

    private function href(int $id, int $siteId): string
    {
        return PpeReturn::record('inventory', $id, '/sites/'.$siteId.'?tab=ppe');
    }

    private function timezone(): string
    {
        // Same server resolver as the agreed Calendar reader. The browser never supplies authority for dates.
        $timezone = (string) (config('app.worker_timezone') ?: config('app.timezone') ?: 'UTC');
        try {
            new DateTimeZone($timezone);
        } catch (\Exception $invalid) {
            throw new LogicException('The configured PPE calendar timezone is invalid.', 0, $invalid);
        }

        return $timezone;
    }
}
