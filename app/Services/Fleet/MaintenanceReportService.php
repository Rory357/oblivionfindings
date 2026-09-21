<?php

namespace App\Services\Fleet;

use App\Models\FleetWorkOrder;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaintenanceReportService
{
    public function __construct(
        private readonly MaintenanceAccessService $access,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    /**
     * @param array{asset_id:int, title:string, description?:?string, priority:string,
     *   observed_at?:?string, estimated_start_date?:?string, estimated_end_date?:?string,
     *   source_type?:?string, source_id?:?int, existing_work_order_id?:?int,
     *   corrects_report_id?:?int, request_key:string} $data
     */
    public function submit(User $actor, array $data): FleetWorkOrder
    {
        abort_unless($this->access->canReport($actor), 403);

        $identity = [
            'actor_id' => (int) $actor->id,
            'operation' => 'maintenance.report',
            'asset_id' => (int) $data['asset_id'],
            'title' => trim($data['title']),
            'description' => $data['description'] ?? null,
            'priority' => $data['priority'],
            'observed_at' => $data['observed_at'] ?? null,
            'estimated_start_date' => $data['estimated_start_date'] ?? null,
            'estimated_end_date' => $data['estimated_end_date'] ?? null,
            'source_type' => $data['source_type'] ?? null,
            'source_id' => $data['source_id'] ?? null,
            'existing_work_order_id' => $data['existing_work_order_id'] ?? null,
            'corrects_report_id' => $data['corrects_report_id'] ?? null,
        ];
        $fingerprint = MaintenanceFingerprint::of($identity);

        try {
            return DB::transaction(function () use ($actor, $data, $fingerprint): FleetWorkOrder {
            $currentActor = User::query()->findOrFail($actor->id);
            abort_unless($this->access->canReport($currentActor), 403);

            // Shared ordering with booking and release: lock the canonical
            // asset before the work order, policy, route or source records.
            $asset = $this->access->asset($currentActor, (int) $data['asset_id'], true);
            $siteId = (int) $asset->site_id;
            $category = (string) $asset->category;
            if ($category === '') {
                throw ValidationException::withMessages(['asset_id' => 'This asset has no category and needs assessment before reporting.']);
            }

            $existing = DB::table('fleet_maintenance_reports')
                ->where('submitted_by_user_id', $currentActor->id)
                ->where('request_key', $data['request_key'])
                ->lockForUpdate()->first();
            if ($existing) {
                abort_unless((int) $existing->asset_id === (int) $asset->id, 409);
                abort_unless(hash_equals((string) $existing->request_fingerprint, $fingerprint), 409);

                return FleetWorkOrder::query()->whereKey($existing->work_order_id)
                    ->where('asset_id', $asset->id)->firstOrFail();
            }

            $route = DB::table('fleet_maintenance_site_routes')->where('site_id', $siteId)->lockForUpdate()->first();
            if (! $route || ! $route->approved_at
                || (int) $route->coordinator_user_id === (int) $route->backup_user_id) {
                throw ValidationException::withMessages([
                    'asset_id' => 'This site needs an approved Coordinator and backup before the report can be submitted. Your draft is still available to retry.',
                ]);
            }

            foreach ([(int) $route->coordinator_user_id, (int) $route->backup_user_id] as $ownerId) {
                try {
                    $this->siteAccess->assertCanUseCurrentStaffAtSite(
                        $currentActor, $ownerId, $siteId, ['sites.viewAll'],
                    );
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                    throw ValidationException::withMessages([
                        'asset_id' => 'The configured Coordinator or backup is no longer approved current staff at this site. Ask a manager to repair routing, then retry your draft.',
                    ]);
                }
            }

            $this->assertSourceBelongsToAsset($data['source_type'] ?? null, $data['source_id'] ?? null, (int) $asset->id);

            $linkedOrder = null;
            if (! empty($data['existing_work_order_id'])) {
                abort_unless($this->access->canManage($currentActor), 403);
                $linkedOrder = FleetWorkOrder::query()
                    ->whereKey((int) $data['existing_work_order_id'])
                    ->where('asset_id', $asset->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }
            if (! empty($data['corrects_report_id'])) {
                abort_unless($linkedOrder, 422);
                $priorReport = DB::table('fleet_maintenance_reports')
                    ->where('id', (int) $data['corrects_report_id'])
                    ->where('work_order_id', $linkedOrder->id)
                    ->where('asset_id', $asset->id)->lockForUpdate()->first();
                abort_unless($priorReport, 404);
            }

            $order = $linkedOrder ?? FleetWorkOrder::create([
                'asset_id' => $asset->id,
                'reported_by_user_id' => $currentActor->id,
                'assigned_to_user_id' => $route->coordinator_user_id,
                'title' => trim($data['title']),
                'description' => $data['description'] ?? null,
                'priority' => $data['priority'],
                'category' => $category,
                'status' => 'open',
                'version' => 0,
            ]);

            DB::table('fleet_maintenance_reports')->insert([
                'work_order_id' => $order->id,
                'asset_id' => $asset->id,
                'site_id' => $siteId,
                'submitted_by_user_id' => $currentActor->id,
                'observed_at' => $data['observed_at'] ?? null,
                'submitted_at' => now(),
                'source_type' => $data['source_type'] ?? null,
                'source_id' => $data['source_id'] ?? null,
                'corrects_report_id' => $data['corrects_report_id'] ?? null,
                'request_key' => $data['request_key'],
                'request_fingerprint' => $fingerprint,
                'title' => trim($data['title']),
                'description' => $data['description'] ?? null,
                'estimated_start_date' => $data['estimated_start_date'] ?? null,
                'estimated_end_date' => $data['estimated_end_date'] ?? null,
                'created_at' => now(),
            ]);

            return $order;
            }, 3);
        } catch (QueryException $exception) {
            // A concurrent same-key create on a different asset can race the
            // asset locks. Its transaction rolls back the new WO before this
            // lookup, so no orphan record or effect survives.
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }

            $currentActor = User::query()->findOrFail($actor->id);
            abort_unless($this->access->canReport($currentActor), 403);
            $asset = $this->access->asset($currentActor, (int) $data['asset_id']);
            $prior = DB::table('fleet_maintenance_reports')
                ->where('submitted_by_user_id', $currentActor->id)
                ->where('request_key', $data['request_key'])->first();
            if (! $prior) {
                throw $exception;
            }
            abort_unless((int) $prior->asset_id === (int) $asset->id
                && hash_equals((string) $prior->request_fingerprint, $fingerprint), 409);

            return FleetWorkOrder::query()->whereKey($prior->work_order_id)
                ->where('asset_id', $asset->id)->firstOrFail();
        }
    }

    private function assertSourceBelongsToAsset(?string $type, ?int $id, int $assetId): void
    {
        if ($type === null && $id === null) {
            return;
        }

        if ($type !== 'fleet_checklist_run' || ! $id || ! DB::table('fleet_checklist_runs')
            ->where('id', $id)->where('asset_id', $assetId)->exists()) {
            throw ValidationException::withMessages(['source_id' => 'Choose a permitted check for this asset.']);
        }
    }
}
