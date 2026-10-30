<?php

namespace App\Services\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\PpeAllocation;
use App\Models\PpeAllocationAttachment;
use App\Models\PpeAttachment;
use App\Models\PpeInspection;
use App\Models\PpeInspectionAttachment;
use App\Models\PpeInventory;
use App\Models\PpeType;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Catalogue reference authority is separate from Site-scoped operational records. */
final class PpeAccessService
{
    public const INVENTORY_OWN_COLUMNS = ['id', 'ppe_type_id', 'site_id', 'brand', 'model', 'serial_number',
        'condition', 'status', 'source_schema_version', 'source_revision'];

    public const ALLOCATION_FACTUAL_COLUMNS = ['id', 'ppe_inventory_id', 'user_id', 'allocated_at', 'returned_at',
        'fit_test_completed', 'fit_test_date', 'training_completed', 'training_date', 'acknowledged', 'acknowledged_at', 'source_schema_version', 'source_revision'];

    public const PERMISSIONS = ['hazards.view', 'hazards.manage', 'healthSafety.viewAllSites',
        // Read existing Site policy evidence for the profile action; this adds no Site grant.
        'sites.update', 'sites.viewAll', 'sites.type.head_office.view', 'sites.type.house.view', 'sites.type.facility.view'];

    public function __construct(private readonly AuthorizationEvidenceLockService $authorization,
        private readonly HrCurrentStaffService $staff, private readonly UserSiteAccessService $sites) {}

    public function withCurrentViewer(User $viewer, callable $operation): mixed
    {
        return DB::transaction(fn () => CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($viewer, $operation) {
            abort_unless($reads->query(User::query())->whereKey($viewer->id)->exists(), 404);
            $current = $this->authorization->lockForUserWithoutWaiting($viewer, self::PERMISSIONS);
            abort_unless($reads->query($this->staff->currentUsersQuery())->whereKey($current->id)->exists(), 403);
            $current->setRelation('hrEmployeeProfile', $reads->query(HrEmployeeProfile::query())->where('user_id', $current->id)->first());
            $sites = $this->sites->accessibleSiteIds($current, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS, $reads);
            $result = $operation($current, $reads, $sites);
            $this->assertDetached($result);
            return $result;
        }));
    }

    public function assertOperation(User $current, string $operation): void
    {
        $allowed = match ($operation) {
            'view', 'catalogue' => $current->canDo('hazards.view'),
            'catalogue_manage', 'create', 'update', 'allocate', 'acknowledge', 'return', 'inspect', 'condemn', 'dispose', 'evidence' => $current->canDo('hazards.manage'),
            // Ownership is proved by assertAllocation; this is not general record/evidence read authority.
            'own_view', 'acknowledge_own' => true,
            default => false,
        };
        abort_unless($allowed, 403);
    }

    public function assertSite(int $siteId, User $current, CurrentAuthorizationReads $reads, array $sites): void
    {
        $reads->assertActive();
        abort_unless($siteId > 0 && in_array($siteId, $sites, true), 404);
    }

    public function inventoryQuery(User $current, CurrentAuthorizationReads $reads, array $sites): Builder
    {
        $this->assertOperation($current, 'view');
        return $reads->query(PpeInventory::query()->whereIn('site_id', $sites));
    }

    public function allocationQuery(User $current, CurrentAuthorizationReads $reads, array $sites, bool $own = false): Builder
    {
        $this->assertOperation($current, $own ? 'own_view' : 'view');
        $query = PpeAllocation::query()->whereHas('ppeInventory', fn ($item) => $item->whereIn('site_id', $sites));
        if ($own) {
            $query->where('user_id', $current->id)->select(self::ALLOCATION_FACTUAL_COLUMNS);
        }
        return $reads->query($query);
    }

    public function assertType(PpeType|int $type, User $current, CurrentAuthorizationReads $reads, string $operation = 'catalogue'): PpeType
    {
        $this->assertOperation($current, $operation);
        return $reads->query(PpeType::query())->whereKey($type instanceof PpeType ? $type->id : $type)->firstOrFail();
    }

    public function assertInventory(PpeInventory|int $inventory, User $current, CurrentAuthorizationReads $reads,
        array $sites, string $operation = 'view'): PpeInventory
    {
        // Own allocation authority cannot be used to read an entire item's other records.
        abort_if(in_array($operation, ['own_view', 'acknowledge_own'], true), 403);
        $this->assertOperation($current, $operation);
        return $this->scopedInventory($inventory instanceof PpeInventory ? (int) $inventory->id : $inventory, $current, $reads, $sites);
    }

    private function scopedInventory(int $id, User $current, CurrentAuthorizationReads $reads, array $sites, bool $own = false): PpeInventory
    {
        $query = PpeInventory::query()->whereIn('site_id', $sites);
        if ($own) {
            $query->select(self::INVENTORY_OWN_COLUMNS);
        }
        $item = $reads->query($query)->whereKey($id)->firstOrFail();
        $this->assertSite((int) $item->site_id, $current, $reads, $sites);
        return $item;
    }

    public function assertAllocation(PpeAllocation|int $allocation, User $current, CurrentAuthorizationReads $reads,
        array $sites, string $operation = 'view'): PpeAllocation
    {
        $this->assertOperation($current, $operation);
        $query = PpeAllocation::query()->whereHas('ppeInventory', fn ($item) => $item->whereIn('site_id', $sites));
        if (in_array($operation, ['own_view', 'acknowledge_own'], true)) {
            $query->where('user_id', $current->id)->select(self::ALLOCATION_FACTUAL_COLUMNS);
        }
        $source = $reads->query($query)->whereKey($allocation instanceof PpeAllocation ? $allocation->id : $allocation)->firstOrFail();
        if (in_array($operation, ['own_view', 'acknowledge_own'], true)) {
            abort_unless((int) $source->user_id === (int) $current->id, 404);
        }
        $item = $this->scopedInventory((int) $source->ppe_inventory_id, $current, $reads, $sites, in_array($operation, ['own_view', 'acknowledge_own'], true));
        $source->setRelation('ppeInventory', $item);
        return $source;
    }

    public function assertInspection(PpeInspection|int $inspection, User $current, CurrentAuthorizationReads $reads,
        array $sites, string $operation = 'view'): PpeInspection
    {
        $this->assertOperation($current, $operation);
        $source = $reads->query(PpeInspection::query()->whereHas('ppeInventory', fn ($item) => $item->whereIn('site_id', $sites)))->whereKey($inspection instanceof PpeInspection ? $inspection->id : $inspection)->firstOrFail();
        $source->setRelation('ppeInventory', $this->assertInventory((int) $source->ppe_inventory_id, $current, $reads, $sites, $operation));
        return $source;
    }

    public function assertAttachment(Model $attachment, Model $parent, User $current, CurrentAuthorizationReads $reads,
        array $sites, string $operation = 'view'): Model
    {
        abort_unless(in_array($operation, ['view', 'evidence'], true), 403);
        if ($parent instanceof PpeInventory && $attachment instanceof PpeAttachment) {
            $source = $this->assertInventory($parent, $current, $reads, $sites, $operation);
            $foreignKey = 'ppe_inventory_id';
        } elseif ($parent instanceof PpeAllocation && $attachment instanceof PpeAllocationAttachment) {
            $source = $this->assertAllocation($parent, $current, $reads, $sites, $operation);
            $foreignKey = 'ppe_allocation_id';
        } elseif ($parent instanceof PpeInspection && $attachment instanceof PpeInspectionAttachment) {
            $source = $this->assertInspection($parent, $current, $reads, $sites, $operation);
            $foreignKey = 'ppe_inspection_id';
        } else {
            abort(404);
        }
        return $reads->query($attachment->newQuery())->whereKey($attachment->id)->where($foreignKey, $source->id)->firstOrFail();
    }

    public function currentStaff(int $id, int $siteId, User $current, CurrentAuthorizationReads $reads, array $sites): User
    {
        $this->assertOperation($current, 'allocate');
        $this->assertSite($siteId, $current, $reads, $sites);
        $person = $reads->query($this->staff->currentUsersQuery())->whereKey($id)->firstOrFail();
        $profile = $reads->query(HrEmployeeProfile::query())->where('user_id', $person->id)->firstOrFail();
        $memberships = array_map('intval', [(int) $profile->primary_site_id, ...(is_array($profile->secondary_site_ids) ? $profile->secondary_site_ids : [])]);
        abort_unless(in_array($siteId, $memberships, true), 404);
        return $person;
    }

    private function assertDetached(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $nested) {
                $this->assertDetached($nested);
            }
        } elseif ($value !== null && ! is_scalar($value)) {
            throw new LogicException('PPE authority callbacks must return detached scalar projections only.');
        }
    }
}
