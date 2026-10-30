<?php

namespace App\Services\Fleet;

use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinCostCentre;
use App\Domain\Finance\Models\FinVendor;
use App\Domain\Finance\Services\BillSiteScope;
use App\Models\AssetDocument;
use App\Models\FleetWorkOrder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Projects retained work actions and canonical Finance records. */
final class MaintenanceCostPresenter
{
    public function canView(User $actor, int $siteId): bool
    {
        return $actor->canDo('finance.ap.view') && in_array($siteId, app(BillSiteScope::class)->siteIds($actor), true);
    }

    public function present(User $actor, FleetWorkOrder $order): ?array
    {
        $access = app(MaintenanceAccessService::class);
        $order = $access->workOrder($actor, $order->id);
        if (! $actor->canDo('finance.ap.view')) {
            return null;
        }
        $asset = $access->asset($actor, $order->asset_id);
        if (! $this->canView($actor, (int) $asset->site_id)) {
            return null;
        }
        $estimates = $this->estimates($order->id);
        $documentIds = collect($estimates)->flatMap(fn ($estimate) => $estimate['document_ids'])->unique();
        $canFiles = app(VehicleDocumentService::class)->canView($actor, $asset);
        $documents = AssetDocument::query()->where('asset_id', $asset->id)->whereIn('id', $documentIds)->get()
            ->map(fn ($file) => ['id' => $file->id, 'name' => $file->original_name ?: $file->title,
                'state' => $file->archived_at ? 'archived' : $file->state, 'mime' => $file->detected_mime ?: $file->mime_type,
                'revision' => $file->revision, 'set_id' => $file->document_set_id,
                'url' => $canFiles && $file->isOpenable() ? "/fleet-assets/vehicles/{$asset->id}/documents/{$file->id}/file" : null])->all();
        $billIds = DB::table('fleet_maintenance_fin_bill_links')->where('work_order_id', $order->id)->pluck('fin_bill_id');
        $bills = app(BillSiteScope::class)->apply(FinBill::query(), $actor)
            ->whereIn('id', $billIds)->where('asset_id', $asset->id)->where('site_id', $asset->site_id)
            ->with('vendor:id,name', 'purchaseOrder:id,po_number,total_amount,status', 'journal:id,journal_number,status')
            ->get()->map(fn ($bill) => ['id' => $bill->id, 'reference' => $bill->bill_number,
                'status' => $bill->status, 'total' => $bill->total_amount, 'paid' => $bill->amount_paid,
                'vendor' => $bill->vendor?->name, 'purchase_order' => $bill->purchaseOrder?->toArray(),
                'journal' => $bill->journal?->toArray(), 'url' => '/finance/bills/'.$bill->id])->all();

        return ['estimates' => $estimates, 'documents' => $documents, 'bills' => $bills,
            'summary' => app(MaintenanceCostSummary::class)->totals($bills, $billIds->count() - count($bills)),
            'unavailable_bill_count' => $billIds->count() - count($bills),
            'requests' => app(VehicleFinanceReviewQueue::class)->forWork($actor, $asset->id, $order->id, array_column($bills, 'id')),
            'vendors' => FinVendor::query()->active()->orderBy('name')->limit(50)->get(['id', 'name'])->all(),
            'cost_centres' => FinCostCentre::query()->active()->where(fn ($q) => $q->whereNull('site_id')->orWhere('site_id', $asset->site_id))
                ->orderBy('name')->get(['id', 'code', 'name'])->all(),
            'can' => ['estimate' => $access->canManage($actor),
                'upload' => $asset->category === 'vehicle' && $actor->canDo('fleet.manage') && app(VehicleDocumentService::class)->canManage($actor, $asset),
                'request' => $asset->category === 'vehicle' && $actor->canDo('fleet.manage') && $actor->canDo('finance.assets.view')],
            'site_name' => $asset->site?->name];
    }

    public function estimates(int $workId): array
    {
        $rows = DB::table('fleet_maintenance_actions as action')->leftJoin('users as actor', 'actor.id', '=', 'action.actor_user_id')
            ->where('action.work_order_id', $workId)->where('action.action_type', 'record_cost_estimate')
            ->latest('action.id')->limit(50)->get(['action.id', 'action.resulting_version', 'action.payload_json', 'action.occurred_at', 'actor.name']);
        $payloads = $rows->mapWithKeys(fn ($row) => [$row->id => json_decode($row->payload_json, true)]);
        $vendors = FinVendor::withTrashed()->whereIn('id', $payloads->pluck('vendor_id'))->pluck('name', 'id');

        return $rows->map(function ($row) use ($payloads, $vendors) {
            $payload = $payloads[$row->id];

            return ['id' => (int) $row->id, 'version' => (int) $row->resulting_version,
                'vendor_id' => (int) $payload['vendor_id'], 'vendor_name' => $vendors[$payload['vendor_id']] ?? 'Supplier unavailable',
                'quote_reference' => $payload['quote_reference'], 'amount' => (string) $payload['amount'],
                'cost_centre_id' => $payload['cost_centre_id'] ?? null, 'document_ids' => $payload['document_ids'] ?? [],
                'reason' => $payload['reason'], 'actor' => $row->name, 'at' => CarbonImmutable::parse($row->occurred_at, 'UTC')->toIso8601String()];
        })->all();
    }
}
