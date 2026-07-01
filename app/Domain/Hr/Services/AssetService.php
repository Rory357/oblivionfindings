<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrAsset;
use App\Domain\Hr\Models\HrAssetAssignment;
use App\Domain\Hr\Models\HrAssetMaintenanceLog;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AssetService
{
    /**
     * Assign an asset to an employee, optionally capturing a return-by date and an
     * in-app acknowledgement (e-signature) at the point of handover.
     */
    public function assignAsset(HrAsset $asset, HrEmployeeProfile $profile, array $data): HrAssetAssignment
    {
        if ($asset->status !== 'available') {
            throw new \LogicException("Asset '{$asset->asset_tag}' is not available for assignment (current status: {$asset->status}).");
        }

        return DB::transaction(function () use ($asset, $profile, $data) {
            $assignment = HrAssetAssignment::create([
                'tenant_id' => $asset->tenant_id,
                'asset_id' => $asset->id,
                'employee_profile_id' => $profile->id,
                'assigned_at' => $data['assigned_at'] ?? now(),
                'due_at' => $data['due_at'] ?? null,
                'acknowledged_at' => $data['acknowledged_at'] ?? null,
                'signature_id' => $data['signature_id'] ?? null,
                'condition_on_assign' => $data['condition_on_assign'] ?? null,
                'photos' => $data['photos'] ?? null,
                'assigned_by' => $data['assigned_by'],
                'notes' => $data['notes'] ?? null,
            ]);

            $asset->update(['status' => 'assigned']);

            return $assignment;
        });
    }

    /**
     * Return an asset from an employee.
     */
    public function returnAsset(HrAssetAssignment $assignment, array $data): HrAssetAssignment
    {
        if ($assignment->returned_at !== null) {
            throw new \LogicException('This asset assignment has already been returned.');
        }

        return DB::transaction(function () use ($assignment, $data) {
            $assignment->update([
                'returned_at' => $data['returned_at'] ?? now(),
                'condition_on_return' => $data['condition_on_return'] ?? null,
                'notes' => $data['notes'] ?? $assignment->notes,
            ]);

            // A damaged/lost return routes onward to a repair log or retirement; the
            // caller decides. Default returns the asset to the available pool.
            $assignment->asset->update(['status' => $data['next_status'] ?? 'available']);

            return $assignment->fresh();
        });
    }

    /**
     * Log a maintenance / repair job and move the asset into maintenance. Replaces
     * the bare status flip with a real, auditable repair record.
     */
    public function logMaintenance(HrAsset $asset, array $data): HrAssetMaintenanceLog
    {
        if (! in_array($asset->status, ['available', 'maintenance'], true)) {
            throw new \LogicException("Only an available asset can be sent for repair (current status: {$asset->status}). Return it from the employee first.");
        }

        return DB::transaction(function () use ($asset, $data) {
            $log = HrAssetMaintenanceLog::create([
                'tenant_id' => $asset->tenant_id,
                'asset_id' => $asset->id,
                'type' => $data['type'] ?? 'repair',
                'vendor' => $data['vendor'] ?? null,
                'cost' => $data['cost'] ?? null,
                'sent_at' => $data['sent_at'] ?? now()->toDateString(),
                'expected_back_at' => $data['expected_back_at'] ?? null,
                'next_due_at' => $data['next_due_at'] ?? null,
                'notes' => $data['notes'] ?? null,
                'performed_by' => $data['performed_by'] ?? null,
                'attachments' => $data['attachments'] ?? null,
            ]);

            $asset->update(['status' => 'maintenance']);

            return $log;
        });
    }

    /**
     * Close the open maintenance job and return the asset to the available pool.
     */
    public function returnToService(HrAsset $asset, array $data = []): HrAsset
    {
        if ($asset->status !== 'maintenance') {
            throw new \LogicException("Only an asset in maintenance can be returned to service (current status: {$asset->status}).");
        }

        return DB::transaction(function () use ($asset, $data) {
            $log = $asset->openMaintenanceLog()->first();
            if ($log) {
                $log->update([
                    'completed_at' => $data['completed_at'] ?? now()->toDateString(),
                    'outcome' => $data['outcome'] ?? 'repaired',
                    'cost' => $data['cost'] ?? $log->cost,
                    'next_due_at' => $data['next_due_at'] ?? $log->next_due_at,
                    'notes' => $data['notes'] ?? $log->notes,
                ]);
            }

            $asset->update([
                'status' => 'available',
                'condition' => $data['condition'] ?? $asset->condition,
            ]);

            return $asset->fresh();
        });
    }

    /**
     * Send an available asset to maintenance (lightweight status flip, no vendor
     * record). Retained for back-compat; prefer logMaintenance().
     */
    public function sendToMaintenance(HrAsset $asset, array $data = []): HrAsset
    {
        if ($asset->status !== 'available') {
            throw new \LogicException("Only an available asset can be sent to maintenance (current status: {$asset->status}).");
        }

        $attrs = ['status' => 'maintenance'];
        if (! empty($data['notes'])) {
            $attrs['notes'] = $data['notes'];
        }
        $asset->update($attrs);

        return $asset->fresh();
    }

    /**
     * Return an asset from maintenance back to the available pool (lightweight).
     * Retained for back-compat; prefer returnToService().
     */
    public function returnFromMaintenance(HrAsset $asset, array $data = []): HrAsset
    {
        if ($asset->status !== 'maintenance') {
            throw new \LogicException("Only an asset in maintenance can be returned to service (current status: {$asset->status}).");
        }

        $attrs = ['status' => 'available'];
        if (! empty($data['notes'])) {
            $attrs['notes'] = $data['notes'];
        }
        $asset->update($attrs);

        return $asset->fresh();
    }

    /**
     * Retire (decommission) an asset. It must not be currently assigned — return
     * it from the employee first so no open assignment is orphaned.
     */
    public function retireAsset(HrAsset $asset, array $data = []): HrAsset
    {
        if (! in_array($asset->status, ['available', 'maintenance'], true)) {
            throw new \LogicException("Cannot retire a '{$asset->status}' asset. Return it from assignment first.");
        }

        $attrs = [
            'status' => 'retired',
            'disposal_reason' => $data['disposal_reason'] ?? null,
            'disposed_at' => $data['disposed_at'] ?? now()->toDateString(),
            'disposal_value' => $data['disposal_value'] ?? null,
        ];
        if (! empty($data['notes'])) {
            $attrs['notes'] = $data['notes'];
        }
        $asset->update($attrs);

        return $asset->fresh();
    }

    /**
     * Generate (or return the existing) scan-to-open QR token for an asset.
     */
    public function ensureQrToken(HrAsset $asset): string
    {
        if (! $asset->qr_token) {
            $asset->update(['qr_token' => (string) Str::uuid()]);
        }

        return $asset->qr_token;
    }

    /**
     * Get all assets currently assigned to an employee.
     */
    public function getAssetsForEmployee(HrEmployeeProfile $profile): Collection
    {
        return HrAssetAssignment::where('employee_profile_id', $profile->id)
            ->whereNull('returned_at')
            ->with('asset')
            ->get();
    }

    /**
     * Get all available assets for a tenant.
     */
    public function getAvailableAssets(?int $tenantId): Collection
    {
        return HrAsset::forTenant($tenantId)
            ->available()
            ->orderBy('category')
            ->orderBy('name')
            ->get();
    }

    /**
     * Tenant-wide aggregates for the hero band + overview tab. Counts the whole
     * register, never the current page — fixes the page-only count bug.
     *
     * @return array<string,mixed>
     */
    public function aggregates(?int $tenantId): array
    {
        $now = CarbonImmutable::now()->startOfDay();
        $in30 = $now->addDays(30);
        $in90 = $now->addDays(90);

        $base = HrAsset::forTenant($tenantId);

        $byStatus = (clone $base)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $total = (int) array_sum($byStatus->all());
        $available = (int) ($byStatus['available'] ?? 0);
        $assigned = (int) ($byStatus['assigned'] ?? 0);
        $maintenance = (int) ($byStatus['maintenance'] ?? 0);
        $retired = (int) ($byStatus['retired'] ?? 0);

        // HR-owned value excludes fleet-linked rows (those belong to the canonical
        // register) and retired stock.
        $ownedValue = (float) (clone $base)
            ->whereNull('fleet_asset_id')
            ->where('status', '!=', 'retired')
            ->sum('purchase_cost');

        $totalValue = (float) (clone $base)
            ->where('status', '!=', 'retired')
            ->sum('purchase_cost');

        $warrSoon = (int) (clone $base)
            ->whereNotNull('warranty_expiry')
            ->whereBetween('warranty_expiry', [$now, $in30])
            ->count();

        $warr90 = (int) (clone $base)
            ->whereNotNull('warranty_expiry')
            ->whereBetween('warranty_expiry', [$now, $in90])
            ->count();

        $overdue = (int) HrAssetAssignment::forTenant($tenantId)
            ->whereNull('returned_at')
            ->whereNotNull('due_at')
            ->where('due_at', '<', $now)
            ->count();

        $leavers = (int) HrAssetAssignment::forTenant($tenantId)
            ->whereNull('returned_at')
            ->whereHas('employeeProfile', fn ($q) => $q->where('is_active', false))
            ->count();

        $categoryMix = (clone $base)
            ->where('status', '!=', 'retired')
            ->selectRaw('category, count(*) as c')
            ->groupBy('category')
            ->orderByDesc('c')
            ->pluck('c', 'category')
            ->map(fn ($c) => (int) $c)
            ->all();

        return [
            'total' => $total,
            'available' => $available,
            'assigned' => $assigned,
            'maintenance' => $maintenance,
            'retired' => $retired,
            'owned_value' => $ownedValue,
            'total_value' => $totalValue,
            'warranties_30d' => $warrSoon,
            'warranties_90d' => $warr90,
            'overdue_returns' => $overdue,
            'leaver_held' => $leavers,
            'status_mix' => [
                ['status' => 'available', 'count' => $available],
                ['status' => 'assigned', 'count' => $assigned],
                ['status' => 'maintenance', 'count' => $maintenance],
                ['status' => 'retired', 'count' => $retired],
            ],
            'category_mix' => $categoryMix,
        ];
    }

    /**
     * Search the canonical Fleet & Assets register for a vehicle/key to federate to.
     * HR links (never re-types) these so a physical asset is registered exactly once.
     *
     * @return array<int,array<string,mixed>>
     */
    public function searchFleetAssets(string $query, int $limit = 20): array
    {
        $q = trim($query);

        return Asset::query()
            ->whereIn('category', ['vehicle', 'key'])
            ->when($q !== '', function ($builder) use ($q) {
                $builder->where(function ($w) use ($q) {
                    $w->where('name', 'like', "%{$q}%")
                        ->orWhere('asset_tag', 'like', "%{$q}%")
                        ->orWhere('registration_number', 'like', "%{$q}%")
                        ->orWhere('serial_number', 'like', "%{$q}%");
                });
            })
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'asset_tag', 'category', 'registration_number', 'serial_number', 'status'])
            ->map(fn (Asset $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'asset_tag' => $a->asset_tag,
                'category' => $a->category,
                'registration_number' => $a->registration_number,
                'serial_number' => $a->serial_number,
                'status' => $a->status,
            ])
            ->all();
    }

    /** Warranty reminder thresholds (days before expiry) — fire once each. */
    private const WARRANTY_THRESHOLDS = [30, 14, 7];

    /**
     * Build the full list of "attention" alerts across a tenant (or all tenants
     * when null) for the daily reminder job: warranties hitting a threshold,
     * overdue returns, overdue repairs and leaver-held assets.
     *
     * @return array<int,array<string,mixed>>
     */
    public function dueAlerts(?int $tenantId): array
    {
        $today = CarbonImmutable::now()->startOfDay();
        $alerts = [];

        // Warranties expiring on an exact threshold day (fire once per threshold).
        $warrantyDates = collect(self::WARRANTY_THRESHOLDS)
            ->mapWithKeys(fn ($d) => [$today->addDays($d)->toDateString() => $d]);

        HrAsset::query()
            ->when($tenantId !== null, fn ($q) => $q->forTenant($tenantId))
            ->whereNotNull('warranty_expiry')
            ->where('status', '!=', 'retired')
            ->whereIn('warranty_expiry', $warrantyDates->keys()->all())
            ->get(['id', 'tenant_id', 'name', 'asset_tag', 'warranty_expiry'])
            ->each(function (HrAsset $a) use (&$alerts, $warrantyDates) {
                $days = $warrantyDates[$a->warranty_expiry->toDateString()] ?? null;
                $alerts[] = [
                    'tenant_id' => $a->tenant_id,
                    'kind' => 'warranty',
                    'asset_id' => $a->id,
                    'title' => 'Warranty expiring',
                    'message' => "{$a->name} ({$a->asset_tag}) warranty expires in {$days} days, on {$a->warranty_expiry->format('d M Y')}.",
                    'action_url' => "/hr/assets/{$a->id}",
                    'dedupe_key' => "warranty:{$a->id}:{$days}",
                    'scope' => 'once',
                ];
            });

        // Overdue returns + leaver-held (active assignments).
        HrAssetAssignment::query()
            ->when($tenantId !== null, fn ($q) => $q->forTenant($tenantId))
            ->whereNull('returned_at')
            ->where(function ($q) use ($today) {
                $q->where('due_at', '<', $today)
                    ->orWhereHas('employeeProfile', fn ($p) => $p->where('is_active', false));
            })
            ->with(['asset:id,name,asset_tag', 'employeeProfile.user:id,name', 'employeeProfile:id,user_id,is_active'])
            ->get()
            ->each(function (HrAssetAssignment $asg) use (&$alerts, $today) {
                if (! $asg->asset) {
                    return;
                }
                $who = $asg->employeeProfile?->user?->name ?? 'a staff member';
                $isLeaver = $asg->employeeProfile && $asg->employeeProfile->is_active === false;
                $isOverdue = $asg->due_at !== null && $asg->due_at->lt($today);

                if ($isLeaver) {
                    $alerts[] = [
                        'tenant_id' => $asg->tenant_id,
                        'kind' => 'leaver',
                        'asset_id' => $asg->asset_id,
                        'title' => 'Recover asset from leaver',
                        'message' => "{$asg->asset->name} ({$asg->asset->asset_tag}) is still held by {$who}, who has left.",
                        'action_url' => '/hr/assets?tab=assignments',
                        'dedupe_key' => "leaver:{$asg->asset_id}",
                        'scope' => 'daily',
                    ];
                } elseif ($isOverdue) {
                    $overdueDays = (int) abs($today->diffInDays($asg->due_at));
                    $alerts[] = [
                        'tenant_id' => $asg->tenant_id,
                        'kind' => 'overdue',
                        'asset_id' => $asg->asset_id,
                        'title' => 'Asset return overdue',
                        'message' => "{$asg->asset->name} ({$asg->asset->asset_tag}) held by {$who} is {$overdueDays} day(s) overdue for return.",
                        'action_url' => '/hr/assets?tab=assignments',
                        'dedupe_key' => "overdue:{$asg->asset_id}",
                        'scope' => 'daily',
                    ];
                }
            });

        // Overdue repairs (open maintenance logs past expected-back date).
        HrAssetMaintenanceLog::query()
            ->when($tenantId !== null, fn ($q) => $q->forTenant($tenantId))
            ->whereNull('completed_at')
            ->whereNotNull('expected_back_at')
            ->whereDate('expected_back_at', '<', $today->toDateString())
            ->with('asset:id,name,asset_tag')
            ->get()
            ->each(function (HrAssetMaintenanceLog $log) use (&$alerts) {
                if (! $log->asset) {
                    return;
                }
                $alerts[] = [
                    'tenant_id' => $log->tenant_id,
                    'kind' => 'maintenance',
                    'asset_id' => $log->asset_id,
                    'title' => 'Repair overdue',
                    'message' => "{$log->asset->name} ({$log->asset->asset_tag}) was expected back from {$log->vendor} by {$log->expected_back_at->format('d M Y')}.",
                    'action_url' => '/hr/assets?tab=maintenance',
                    'dedupe_key' => "maintenance:{$log->asset_id}",
                    'scope' => 'daily',
                ];
            });

        return $alerts;
    }

    /**
     * Leaver-held alerts for a single employee — used to nudge HR the moment an
     * employee is offboarded while still holding equipment.
     *
     * @return array<int,array<string,mixed>>
     */
    public function leaverHeldAlerts(HrEmployeeProfile $profile): array
    {
        $name = $profile->user?->name ?? 'A departing employee';

        return HrAssetAssignment::query()
            ->where('employee_profile_id', $profile->id)
            ->whereNull('returned_at')
            ->with('asset:id,name,asset_tag')
            ->get()
            ->filter(fn (HrAssetAssignment $asg) => $asg->asset !== null)
            ->map(fn (HrAssetAssignment $asg) => [
                'tenant_id' => $asg->tenant_id,
                'kind' => 'leaver',
                'asset_id' => $asg->asset_id,
                'title' => 'Recover asset from leaver',
                'message' => "{$asg->asset->name} ({$asg->asset->asset_tag}) is still held by {$name}, who is leaving — arrange its return.",
                'action_url' => '/hr/assets?tab=assignments',
                'dedupe_key' => "leaver:{$asg->asset_id}",
                'scope' => 'daily',
            ])
            ->values()
            ->all();
    }
}
