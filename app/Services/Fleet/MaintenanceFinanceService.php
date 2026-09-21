<?php

namespace App\Services\Fleet;

use App\Domain\Finance\Models\FinBill;
use App\Models\FleetWorkOrder;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class MaintenanceFinanceService
{
    public function __construct(private readonly MaintenanceAccessService $access) {}

    public function linkBill(User $actor, int $workOrderId, int $billId): object
    {
        abort_unless($this->access->canManage($actor) && $actor->canDo('finance.ap.view'), 403);
        $preview = FleetWorkOrder::query()->whereKey($workOrderId)->firstOrFail(['id', 'asset_id']);

        try {
            return DB::transaction(function () use ($actor, $preview, $workOrderId, $billId): object {
            $currentActor = User::query()->findOrFail($actor->id);
            abort_unless($this->access->canManage($currentActor)
                && $currentActor->canDo('finance.ap.view'), 403);
            $asset = $this->access->asset($currentActor, (int) $preview->asset_id, true);
            $order = FleetWorkOrder::query()->whereKey($workOrderId)
                ->where('asset_id', $asset->id)->lockForUpdate()->firstOrFail();
            $bill = FinBill::query()->whereKey($billId)->lockForUpdate()->firstOrFail();
            abort_unless((int) $bill->site_id === (int) $asset->site_id
                && (int) $bill->asset_id === (int) $asset->id, 404);

            $prior = DB::table('fleet_maintenance_fin_bill_links')->where('fin_bill_id', $bill->id)->first();
            if ($prior) {
                abort_unless((int) $prior->work_order_id === (int) $order->id, 409);
                return $prior;
            }
            $id = DB::table('fleet_maintenance_fin_bill_links')->insertGetId([
                'work_order_id' => $order->id, 'fin_bill_id' => $bill->id,
                'linked_by_user_id' => $currentActor->id, 'created_at' => now(),
            ]);

            return DB::table('fleet_maintenance_fin_bill_links')->where('id', $id)->first();
            }, 3);
        } catch (QueryException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                abort(409, 'This bill was linked while you were working. Refresh its Finance status.');
            }
            throw $error;
        }
    }

    /** Only Finance readers see bill identity and amounts. Other viewers receive coarse status. */
    public function projection(User $actor, int $workOrderId): array
    {
        $order = $this->access->workOrder($actor, $workOrderId);
        $asset = $this->access->asset($actor, (int) $order->asset_id);
        $rows = DB::table('fleet_maintenance_fin_bill_links as link')
            ->leftJoin('fin_bills as bill', 'bill.id', '=', 'link.fin_bill_id')
            ->where('link.work_order_id', $order->id)
            ->orderBy('link.id')
            ->get(['bill.id', 'bill.site_id', 'bill.asset_id', 'bill.bill_number', 'bill.status', 'bill.total_amount',
                'bill.approved_at', 'bill.journal_id', 'bill.deleted_at']);
        $financeReader = $actor->canDo('finance.ap.view');

        return $rows->map(static function ($bill) use ($financeReader, $asset): array {
            if (! $bill->id || $bill->deleted_at
                || (int) $bill->site_id !== (int) $asset->site_id
                || (int) $bill->asset_id !== (int) $asset->id) {
                return ['status' => 'reconciliation_required'];
            }
            return $financeReader ? [
            'id' => (int) $bill->id,
            'reference' => $bill->bill_number,
            'status' => $bill->status,
            'total_amount' => $bill->total_amount,
            'approved_at' => $bill->approved_at,
            'journal_id' => $bill->journal_id,
        ] : [
            'status' => $bill->status,
            'approved' => $bill->approved_at !== null,
        ];
        })->all();
    }
}
