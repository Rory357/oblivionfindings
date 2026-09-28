<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Models\AssetDocument;
use App\Models\FleetWorkOrder;
use App\Models\User;
use App\Services\Fleet\MaintenanceAccessService;
use App\Services\Fleet\MaintenanceCostPresenter;
use App\Services\Fleet\VehicleFinanceReviewQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class BillWorkContext
{
    public function work(FinBill $bill): ?FleetWorkOrder
    {
        return FleetWorkOrder::query()->whereIn('id', DB::table('fleet_maintenance_fin_bill_links')->where('fin_bill_id', $bill->id)->select('work_order_id'))
            ->where('asset_id', $bill->asset_id)->whereHas('asset', fn ($q) => $q->where('site_id', $bill->site_id))->first();
    }

    public function present(User $actor, FinBill $bill): array
    {
        $work = $this->work($bill);
        $estimate = $work ? (app(MaintenanceCostPresenter::class)->estimates($work->id)[0] ?? null) : null;
        $access = app(MaintenanceAccessService::class);
        $canWork = $work && $access->canRead($actor) && in_array((int) $bill->site_id, $access->approvedSiteIds($actor), true);

        return ['work' => $work ? ['id' => $work->id, 'reference' => $work->reference_number, 'title' => $work->title,
            'status' => $work->status, 'url' => $canWork ? "/fleet-assets/maintenance/work-orders/{$work->id}?tab=finance" : null] : null,
            'estimate' => $estimate,
            'documents' => $this->documents($bill)->map(fn ($file) => ['id' => $file->id, 'name' => $file->original_name ?: $file->title,
                'mime' => $file->detected_mime ?: $file->mime_type, 'state' => $file->archived_at ? 'archived' : $file->state,
                'url' => $file->isOpenable() && ! $file->archived_at && $file->scan_disposition === 'clean' ? "/finance/bills/{$bill->id}/work-evidence/{$file->id}" : null])->all(),
            'requests' => $work ? app(VehicleFinanceReviewQueue::class)->forWork($actor, $work->asset_id, $work->id, [$bill->id]) : [],
            'period' => FinFiscalPeriod::query()->forDate($bill->bill_date)->first(['id', 'name', 'status'])];
    }

    public function documents(FinBill $bill, bool $lock = false): Collection
    {
        $work = $this->work($bill);
        $quote = $work ? DB::table('fleet_maintenance_actions')->where('work_order_id', $work->id)
            ->where('action_type', 'record_cost_estimate')->latest('id')->first() : null;
        $ids = $quote ? (json_decode($quote->payload_json, true)['document_ids'] ?? []) : [];

        return AssetDocument::query()->where('asset_id', $bill->asset_id)->whereIn('id', $ids)->whereNull('source_type')->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
    }

    public function snapshot(FinBill $bill, bool $lock = false): array
    {
        $work = $this->work($bill);
        $quote = $work ? DB::table('fleet_maintenance_actions')->where('work_order_id', $work->id)
            ->where('action_type', 'record_cost_estimate')->latest('id')->first(['id', 'payload_json']) : null;

        return ['work_id' => $work?->id, 'quote' => $quote,
            'documents' => $this->documents($bill, $lock)->map(fn ($file) => $file->only(['id', 'sha256', 'state', 'scan_disposition', 'archived_at']))->all()];
    }
}
