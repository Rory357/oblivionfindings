<?php

namespace App\Services\HealthSafety;

use App\Models\ChemicalSourceVersion;
use App\Models\HazardousSubstance;
use App\Models\SafetyDataSheet;
use App\Models\Site;
use App\Models\SubstanceStorageLocation;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use DateTimeZone;
use LogicException;

/** Nonprivate reference and Site storage reads; private exposure policy is a separate prerequisite. */
final class ChemicalRegisterReadService
{
    private const DEFINITION_FIELDS = ['id', 'name', 'common_name', 'un_number', 'hsno_approval', 'hsno_classification',
        'hazard_classifications', 'ghs_pictograms', 'signal_word', 'hazard_statements', 'precautionary_statements',
        'physical_form', 'requires_tracking', 'is_controlled_substance', 'status', 'status_reason', 'ppe_required',
        'storage_requirements', 'handling_precautions', 'first_aid_measures', 'firefighting_measures', 'spill_procedures',
        'exposure_limit_type', 'exposure_limit_value'];

    public function __construct(private readonly ChemicalAccessService $access, private readonly ChemicalCommandService $commands) {}

    public function detail(User $viewer, int $id): array
    {
        return $this->access->withCurrentViewer($viewer, fn ($current, $reads, $sites) => $this->detailInReads($id, $current, $reads, $sites));
    }

    public function detailInContext(User $viewer, int $id, HealthSafetyReadContext $context): array
    {
        $context->assertViewer($viewer);

        return $this->access->withReadContext($context, fn ($current, $reads, $sites) => $this->detailInReads($id, $current, $reads, $sites));
    }

    private function detailInReads(int $id, User $current, CurrentAuthorizationReads $reads, array $sites): array
    {
        $row = $this->access->assertCatalogue($id, $current, $reads, 'view', true);
        $proof = $this->commands->proofForCurrentReads($row, null, $current, $reads, $sites);
        if ($proof['source_integrity'] === 'repair_required') {
            return [...array_fill_keys(self::DEFINITION_FIELDS, null), 'id' => $id, ...$this->metadata($proof),
                'sds_state' => 'repair_required', 'current_sds' => $proof['current_sds'], 'current_sds_id' => null,
                'sds_records' => null, 'storage_locations' => null, 'history' => null, 'inventory_state' => 'unknown',
                'archived_storage' => ['items' => null, 'recorded_count' => null, 'physical_record_count' => null, 'repair_count' => null, 'truncated' => null],
                'exposure_access' => 'restricted', 'exposure_records' => null,
                'counts' => ['sds' => null, 'storage' => null, 'exposures' => null],
                'created_by' => null, 'created_at' => null, 'updated_at' => null, 'can' => $this->can($current, false)];
        }
        $sheets = $reads->query(SafetyDataSheet::query())->where('hazardous_substance_id', $id)->orderByDesc('issue_date')->orderByDesc('id')->get();
        $storage = $this->access->storageQuery($id, $current, $reads, $sites)->orderBy('site_id')->orderBy('id')->get();
        $sheetDtos = $sheets->map(fn ($sheet) => $this->sheet($row, $sheet, $current, $reads, $sites))->all();
        $locations = $storage->map(fn ($location) => $this->location($row, $location, $current, $reads, $sites))->all();
        $archived = [];
        foreach ($this->access->storageQuery($id, $current, $reads, $sites)->onlyTrashed()->orderBy('site_id')->orderBy('id')->get() as $location) {
            if ($this->archivedOriginalSiteReadable($row, $location, $reads, $sites)) {
                $archived[] = $this->location($row, $location, $current, $reads, $sites, true);
            }
        }
        $archivedRepair = $this->repairs($archived);
        $sheetRepair = $this->repairs($sheetDtos); $storageRepair = $this->repairs($locations);

        return [...$this->definition($proof), ...$this->metadata($proof), 'sds_state' => $this->currentState($proof['current_sds'], $sheets->count()),
            'current_sds_id' => $proof['current_sds_id'], 'current_sds' => $proof['current_sds'],
            'sds_records' => array_slice($this->repairFirst($sheetDtos), 0, 100),
            'storage_locations' => array_slice($this->repairFirst($locations), 0, 100), 'inventory_state' => 'unknown',
            'archived_storage' => ['items' => array_slice($this->repairFirst($archived), 0, 100),
                'recorded_count' => $archivedRepair ? null : count($archived), 'physical_record_count' => count($archived),
                'repair_count' => $archivedRepair, 'truncated' => count($archived) > 100],
            'history' => $this->commands->historyForCurrentReads($row, null, $current, $reads, $sites),
            'exposure_access' => 'restricted', 'exposure_records' => null,
            'counts' => ['sds' => $sheetRepair ? null : count($sheetDtos), 'storage' => $storageRepair ? null : count($locations), 'exposures' => null],
            'physical_counts' => ['sds' => count($sheetDtos), 'storage' => count($locations)],
            'repair_counts' => ['sds' => $sheetRepair, 'storage' => $storageRepair],
            'truncated' => ['sds' => count($sheetDtos) > 100, 'storage' => count($locations) > 100],
            'created_by' => $this->actorName($row->created_by, $reads), 'created_at' => $row->created_at?->toISOString(),
            'updated_at' => $row->updated_at?->toISOString(), 'can' => $this->can($current)];
    }

    public function index(User $viewer, array $filters = []): array
    {
        return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($filters): array {
            $siteId = isset($filters['site_id']) && $filters['site_id'] !== '' ? (int) $filters['site_id'] : null;
            if ($siteId !== null) {
                $this->access->assertSite($siteId, $reads, $sites);
            }
            $query = $this->access->catalogueQuery($current, $reads);
            if ($siteId !== null) {
                $query->whereHas('storageLocations', fn ($storage) => $storage->where('site_id', $siteId));
            }
            $rows = $query->orderBy('id')->get();
            $sheets = $reads->query(SafetyDataSheet::query())->whereIn('hazardous_substance_id', $rows->modelKeys())->get()
                ->groupBy('hazardous_substance_id');
            $storage = $reads->query(SubstanceStorageLocation::query())->whereIn('hazardous_substance_id', $rows->modelKeys())
                ->whereIn('site_id', $siteId === null ? $sites : [$siteId])
                ->whereHas('site', fn ($site) => $site->active()->notArchived()->whereNull('archived_at'))->orderBy('id')->get();
            $all = $rows->map(function ($row) use ($sheets, $storage, $current, $reads, $sites): array {
                $proof = $this->commands->proofForCurrentReads($row, null, $current, $reads, $sites);
                $valid = $proof['source_integrity'] !== 'repair_required';
                $facts = $valid ? $this->definition($proof) : array_fill_keys(self::DEFINITION_FIELDS, null);
                $locations = $valid ? $storage->where('hazardous_substance_id', $row->id)
                    ->map(fn ($location) => $this->location($row, $location, $current, $reads, $sites))->all() : [];
                $storageRepair = $valid ? $this->repairs($locations) : null;
                return ['id' => (int) $row->id, 'name' => $facts['name'], 'common_name' => $facts['common_name'],
                    'un_number' => $facts['un_number'], 'hsno_classification' => $facts['hsno_classification'],
                    'hazard_classifications' => $facts['hazard_classifications'], 'ghs_pictograms' => $facts['ghs_pictograms'],
                    'physical_form' => $facts['physical_form'], 'is_controlled_substance' => $facts['is_controlled_substance'],
                    'requires_tracking' => $facts['requires_tracking'], 'status' => $facts['status'], ...$this->metadata($proof),
                    'sds_state' => $valid ? $this->currentState($proof['current_sds'], ($sheets->get($row->id) ?? collect())->count()) : 'repair_required',
                    'current_sds_id' => $proof['current_sds_id'], 'current_sds' => $proof['current_sds'],
                    'storage_count' => ! $valid || $storageRepair ? null : count($locations),
                    'physical_storage_count' => $valid ? count($locations) : null, 'storage_repair_count' => $storageRepair,
                    'inventory_state' => $storageRepair ? 'repair_required' : 'unknown', 'can' => $this->can($current, $valid)];
            })->all();
            usort($all, fn ($a, $b) => ($this->needsRepair($b) <=> $this->needsRepair($a))
                ?: strcasecmp((string) $a['name'], (string) $b['name']) ?: ($a['id'] <=> $b['id']));
            $q = trim((string) ($filters['q'] ?? ''));
            $controlled = $this->controlled($filters['controlled'] ?? null);
            $normalized = ['q' => $q, 'tab' => in_array($filters['tab'] ?? null, ['all', 'active', 'controlled', 'sds_expiring', 'sds_missing', 'inactive'], true) ? $filters['tab'] : 'all',
                'site_id' => $siteId, 'physical_form' => $filters['physical_form'] ?? null, 'status' => $filters['status'] ?? null,
                'controlled' => $controlled === null ? null : ($controlled ? 'controlled' : 'standard'), 'sds_state' => $filters['sds_state'] ?? null,
                'period' => max(7, min(365, (int) ($filters['period'] ?? 90)))];
            $refined = array_values(array_filter($all, fn ($row) => $row['source_integrity'] === 'repair_required' || (($q === '' || str_contains(mb_strtolower(implode(' ', [$row['name'], $row['common_name'], $row['un_number'], $row['hsno_classification']])), mb_strtolower($q)))
                && (! $normalized['physical_form'] || $row['physical_form'] === $normalized['physical_form'])
                && (! $normalized['status'] || $row['status'] === $normalized['status'])
                && ($controlled === null || $row['is_controlled_substance'] === $controlled)
                && (! $normalized['sds_state'] || ($normalized['sds_state'] === 'expiring' ? in_array($row['sds_state'], ['expired', 'expiring'], true) : $row['sds_state'] === $normalized['sds_state'])))));
            $parentRepair = count(array_filter($all, fn ($row) => $row['source_integrity'] === 'repair_required'));
            $sdsRepair = count(array_filter($all, fn ($row) => $row['sds_state'] === 'repair_required'));
            $storageRepair = $parentRepair + array_sum(array_column($all, 'storage_repair_count'));
            $counts = [];
            foreach (['all', 'active', 'controlled', 'sds_expiring', 'sds_missing', 'inactive'] as $tab) {
                $counts[$tab] = $parentRepair || (in_array($tab, ['sds_expiring', 'sds_missing'], true) && $sdsRepair)
                    ? null : count(array_filter($refined, fn ($row) => $this->tab($row, $tab)));
            }
            $selected = array_values(array_filter($refined, fn ($row) => $this->tab($row, $normalized['tab'])));
            $page = max(1, (int) ($filters['page'] ?? 1));
            $active = array_values(array_filter($all, fn ($row) => $row['status'] === 'active'));
            $stateCount = fn (array $states) => $parentRepair || $sdsRepair ? null : count(array_filter($active, fn ($row) => in_array($row['sds_state'], $states, true)));
            $visibleSites = $reads->query(Site::query())->whereIn('id', $sites)->active()->notArchived()->whereNull('archived_at')->orderBy('name')->get(['id', 'name'])->toArray();

            return ['filters' => $normalized, 'tab' => $normalized['tab'], 'tabCounts' => $counts,
                'rows' => ['data' => array_slice($selected, ($page - 1) * 20, 20), 'current_page' => $page, 'per_page' => 20,
                    'total' => count($selected), 'last_page' => max(1, (int) ceil(count($selected) / 20))],
                'source_integrity' => $parentRepair || $sdsRepair || $storageRepair ? 'repair_required' : (in_array('legacy_unknown', array_column($all, 'source_integrity'), true) ? 'legacy_unknown' : 'valid'),
                'physical_record_count' => count($all), 'repair_counts' => ['catalogue' => $parentRepair, 'sds' => $sdsRepair, 'storage' => $storageRepair],
                'hero' => ['live' => ['active' => $parentRepair ? null : count($active), 'controlled' => $parentRepair ? null : count(array_filter($all, fn ($row) => $row['is_controlled_substance'] && $row['status'] !== 'removed')),
                    'sds_current' => $stateCount(['current']), 'storage_locations' => $storageRepair ? null : $storage->count(), 'inventory_state' => 'unknown'],
                    'attention' => ['sds_expiring' => $stateCount(['expired', 'expiring']), 'sds_missing' => $stateCount(['missing']),
                        'sds_unknown' => $stateCount(['unknown', 'ambiguous', 'unavailable']), 'awaiting_review' => null, 'exposures' => null]],
                'badges' => ['sds_to_action' => $stateCount(['expired', 'expiring', 'missing']), 'worksafe_awaiting' => null],
                'exposure_access' => 'restricted', 'sites' => $visibleSites, 'can' => $this->can($current), 'detail' => null];
        });
    }

    public function storageForSite(User $viewer, int $siteId): array
    {
        return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($siteId): array {
            $this->access->assertOperation($current);
            $this->access->assertSite($siteId, $reads, $sites);
            $rows = $reads->query(SubstanceStorageLocation::query())->where('site_id', $siteId)->whereHas('hazardousSubstance')->orderBy('id')->get();
            $locations = [];
            foreach ($rows as $row) {
                $definition = $this->access->assertCatalogue((int) $row->hazardous_substance_id, $current, $reads, 'view', true);
                $proof = $this->commands->proofForCurrentReads($definition, null, $current, $reads, $sites);
                $locations[] = [...$this->location($definition, $row, $current, $reads, $sites),
                    'substance' => ['id' => (int) $definition->id, 'name' => $proof['record']['name'] ?? null, 'status' => $proof['record']['status'] ?? null]];
            }
            $repair = $this->repairs($locations);
            return ['site_id' => $siteId, 'storage_locations' => $this->repairFirst($locations),
                'recorded_location_count' => $repair ? null : count($locations), 'physical_record_count' => count($locations),
                'repair_count' => $repair, 'source_integrity' => $repair ? 'repair_required' : (in_array('legacy_unknown', array_column($locations, 'source_integrity'), true) ? 'legacy_unknown' : 'valid'),
                'inventory_state' => 'unknown', 'can' => $this->can($current)];
        });
    }

    /** Internal download transport only; callers must not serialize its private path into page props. */
    public function sdsForDownload(User $viewer, int $substanceId, int $sheetId): array
    {
        $proof = $this->commands->downloadProof($substanceId, $sheetId, $viewer);
        abort_unless($proof['available'], 404);
        return ['id' => $sheetId, 'substance_id' => $substanceId, 'disk' => $proof['disk'], 'path' => $proof['path'],
            'name' => $proof['filename'], 'attribution_state' => $proof['attribution_state'],
            'source_version_id' => $proof['source_version_id'], 'source_revision' => $proof['source_revision']];
    }

    private function definition(array $proof): array
    {
        return [...array_fill_keys(self::DEFINITION_FIELDS, null), ...array_intersect_key($proof['record'] ?? [], array_flip(self::DEFINITION_FIELDS))];
    }

    private function sheet(HazardousSubstance $parent, SafetyDataSheet $sheet, User $current, CurrentAuthorizationReads $reads, array $sites): array
    {
        $proof = $this->commands->proofForCurrentReads($parent, $sheet, $current, $reads, $sites);
        $record = $proof['record']; $valid = $proof['source_integrity'] !== 'repair_required';
        $state = $valid ? $this->sheetState($record) : ['status' => null, 'state' => 'repair_required', 'available' => false];
        return ['id' => (int) $sheet->id, 'version' => $record['version'] ?? null, 'issue_date' => $record['issue_date'] ?? null,
            'review_date' => $record['review_date'] ?? null, 'supplier_name' => $record['supplier_name'] ?? null, 'supplier_contact' => $record['supplier_contact'] ?? null,
            ...$state, ...$this->metadata($proof), 'file_name' => $state['available'] ? ($record['original_name'] ?: basename($sheet->document_path)) : null,
            'uploaded_by' => $valid ? $this->actorName($sheet->uploaded_by, $reads) : null, 'created_at' => $record['created_at'] ?? null,
            'download_url' => $state['available'] ? '/health-safety/substances/'.$parent->id.'/sds/'.$sheet->id.'/download' : null];
    }

    private function sheetState(array $record): array
    {
        $available = $record['file']['available'];
        $today = now($this->timezone())->toDateString();
        $review = $record['review_date']; $issue = $record['issue_date'];
        $state = $record['status'] === 'not_yet_effective' ? 'not_yet_effective' : ($record['status'] !== 'current' ? 'superseded' : (! $available ? 'unavailable'
            : ($review === null || $issue === null || $issue > $today || $review <= $issue ? 'unknown'
                : ($review < $today ? 'expired' : ($review <= now($this->timezone())->addDays(SafetyDataSheet::REVIEW_HORIZON_DAYS)->toDateString() ? 'expiring' : 'current')))));

        return ['status' => $record['status'], 'state' => $state, 'available' => $available];
    }

    private function currentState(array $current, int $physicalSheets): string
    {
        return match ($current['state']) {
            'recorded' => $current['currency'] === 'overdue' ? 'expired' : $current['currency'],
            'none' => 'missing', 'legacy_unknown' => $physicalSheets ? 'unknown' : 'missing',
            default => $current['state'],
        };
    }

    private function timezone(): string
    {
        $zone = (string) (config('app.worker_timezone') ?: config('app.timezone') ?: 'UTC');
        try { new DateTimeZone($zone); } catch (\Exception $invalid) { throw new LogicException('The configured chemical reader timezone is invalid.', 0, $invalid); }
        return $zone;
    }

    private function location(HazardousSubstance $parent, SubstanceStorageLocation $row, User $current, CurrentAuthorizationReads $reads, array $sites, bool $archived = false): array
    {
        $proof = $this->commands->proofForCurrentReads($parent, $row, $current, $reads, $sites);
        $record = $proof['record'];
        $site = $reads->query(Site::query())->active()->notArchived()->whereNull('archived_at')->whereKey($row->site_id)->firstOrFail(['id', 'name']);
        $valid = $proof['source_integrity'] !== 'repair_required';
        $history = $valid ? $this->commands->historyForCurrentReads($parent, $row, $current, $reads, $sites) : null;
        if ($archived && ($history['source_integrity'] ?? null) === 'repair_required') {
            $valid = false; $record = null; $history = null;
            $proof = [...$proof, 'source_integrity' => 'repair_required', 'attribution_state' => 'repair_required',
                'source_revision' => null, 'expected_version' => null, 'parent_expected_version' => null, 'source_version_id' => null];
        }
        return ['id' => (int) $row->id, 'site' => ['id' => (int) $site->id, 'name' => $site->name], ...$this->metadata($proof),
            'location_description' => $record['location_description'] ?? null, 'current_quantity' => ($record['current_quantity'] ?? null) === null ? null : (float) $record['current_quantity'],
            'quantity_unit' => $record['quantity_unit'] ?? null, 'maximum_quantity' => ($record['maximum_quantity'] ?? null) === null ? null : (float) $record['maximum_quantity'],
            'container_type' => $record['container_type'] ?? null, 'properly_labelled' => $record['properly_labelled'] ?? null,
            'segregation_compliant' => $record['segregation_compliant'] ?? null, 'last_audit_date' => $record['last_audit_date'] ?? null,
            'storage_notes' => $record['storage_notes'] ?? null, 'inventory_state' => 'unknown', 'can' => $this->can($current, $valid),
            'history' => $history,
            ...($archived ? ['archived_at' => $valid ? ($record['deleted_at'] ?? null) : null] : [])];
    }

    /** Archived discovery admits the retained original Site before any source facts or counts. */
    private function archivedOriginalSiteReadable(HazardousSubstance $parent, SubstanceStorageLocation $row, CurrentAuthorizationReads $reads, array $sites): bool
    {
        $original = $reads->query(ChemicalSourceVersion::query())->where('root_type', 'storage')->where('root_id', $row->id)
            ->where('source_type', 'storage')->where('source_id', $row->id)->where('parent_id', $parent->id)
            ->orderBy('aggregate_revision')->orderBy('id')->first(['site_id']);
        // No governed history is honest legacy uncertainty; typed missing history is shaped as repair by source proof.
        return $original === null || ($original->site_id !== null && in_array($original->site_id, $sites, true)
            && $reads->query(Site::query())->active()->notArchived()->whereNull('archived_at')->whereKey($original->site_id)->exists());
    }

    private function actorName(?int $id, CurrentAuthorizationReads $reads): ?string
    {
        return $id === null ? null : $reads->query(User::query())->whereKey($id)->value('name');
    }

    private function can(User $current, bool $confirmed = true): array
    {
        return ['create' => $confirmed && ($current->canDo('hazards.create') || $current->canDo('hazards.manage')),
            'manage' => $confirmed && $current->canDo('hazards.manage')];
    }

    private function metadata(array $proof): array
    {
        return array_intersect_key($proof, array_flip(['source_integrity', 'attribution_state', 'source_revision',
            'expected_version', 'parent_expected_version', 'source_version_id']));
    }

    private function needsRepair(array $row): bool
    {
        return ($row['source_integrity'] ?? null) === 'repair_required' || ($row['sds_state'] ?? null) === 'repair_required'
            || ($row['inventory_state'] ?? null) === 'repair_required';
    }

    private function repairs(array $rows): int { return count(array_filter($rows, fn ($row) => $this->needsRepair($row))); }

    private function repairFirst(array $rows): array
    {
        usort($rows, fn ($a, $b) => $this->needsRepair($b) <=> $this->needsRepair($a));
        return $rows;
    }

    private function controlled(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return match (strtolower((string) $value)) {
            '1', 'true', 'yes', 'controlled' => true, '0', 'false', 'no', 'standard' => false, default => null,
        };
    }

    private function tab(array $row, string $tab): bool
    {
        if ($row['source_integrity'] === 'repair_required') { return true; }
        return match ($tab) {
            'active' => $row['status'] === 'active', 'controlled' => $row['is_controlled_substance'] && $row['status'] !== 'removed',
            'sds_expiring' => $row['status'] === 'active' && in_array($row['sds_state'], ['expired', 'expiring'], true),
            'sds_missing' => $row['status'] === 'active' && $row['sds_state'] === 'missing', 'inactive' => in_array($row['status'], ['inactive', 'removed'], true),
            default => $row['status'] !== 'removed',
        };
    }
}
