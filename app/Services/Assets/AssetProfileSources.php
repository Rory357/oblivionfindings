<?php

namespace App\Services\Assets;

use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinCostAllocation;
use App\Domain\Finance\Models\FinCurrency;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\AssetScanEvent;
use App\Models\FleetChecklistRun;
use App\Models\Site;
use App\Models\User;
use App\Services\Fleet\MaintenanceAccessService;
use App\Services\Fleet\MaintenanceAttachmentService;
use App\Services\Fleet\VehicleFinanceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Read canonical source records without copying their authority into the profile. */
final class AssetProfileSources
{
    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly MaintenanceAccessService $maintenance,
        private readonly MaintenanceAttachmentService $attachments,
        private readonly VehicleFinanceService $finance,
    ) {}

    public function present(User $actor, Asset $asset): array
    {
        Gate::forUser($actor)->authorize('view', $asset);
        $sites = $this->access->accessibleSiteIds($actor);
        $ownerships = $asset->ownerships()->where('effective_from', '<=', now())->whereNull('effective_to')->limit(2)->get();
        $owner = $ownerships->count() === 1 ? $ownerships->first() : null;
        $ownerName = null;
        if ($owner?->owner_type === 'site' && in_array((int) $owner->owner_id, $sites, true)) {
            $ownerName = Site::whereKey($owner->owner_id)->value('name');
        } elseif ($owner?->owner_type === 'client') {
            $client = $this->access->assignableClient($actor, (int) $owner->owner_id);
            $ownerName = $client ? trim($client->first_name.' '.$client->last_name) : null;
        }
        $scans = AssetScanEvent::where('asset_id', $asset->id)->whereIn('site_id', $sites)
            ->where(fn ($query) => $query->whereNull('client_id')->orWhereIn('client_id', $this->access->authorizedClientIds($actor)))
            ->with('site:id,name')->latest('scanned_at')->limit(20)->get()->map(fn ($scan) => [
                'id' => $scan->id, 'site' => $scan->site?->name,
                'observed_at' => $scan->scanned_at?->toISOString(), 'received_at' => $scan->created_at?->toISOString(),
                'by' => in_array($scan->scanned_by_type, ['user', User::class], true) ? User::whereKey($scan->scanned_by_id)->value('name') : null,
                // Context can contain arbitrary legacy metadata; never project it wholesale.
                'source' => 'QR scan record SC-'.$scan->id,
            ])->values();

        $canChecks = $actor->canDo('fleet.viewAny') || $actor->canDo('assets.viewAny');
        $runs = $canChecks && in_array((int) $asset->site_id, $this->maintenance->approvedSiteIds($actor), true)
            ? FleetChecklistRun::where('asset_id', $asset->id)->with('user:id,name')->latest('completed_at')->limit(100)->get() : collect();
        $checks = $runs->map(fn ($run) => [
            'id' => $run->id, 'name' => $run->presented_template_json['name'] ?? 'Original submitted check',
            'at' => $run->completed_at?->toISOString(), 'by' => $run->user?->name,
            'outcome' => $run->outcome ?: 'needs_assessment', 'kind' => $run->check_kind,
            'rule_version' => $run->rule_version_id, 'corrects_id' => $run->corrects_run_id,
            'url' => '/fleet-assets/inspections/'.$run->id,
        ])->values();
        $files = [];
        if ($this->maintenance->canRead($actor)) {
            $workIds = $this->maintenance->scopedWorkOrders($actor)->where('asset_id', $asset->id)->pluck('id');
            foreach (DB::table('fleet_maintenance_attachments')->whereIn('work_order_id', $workIds)->latest('id')->limit(100)->get() as $candidate) {
                try {
                    $row = $this->attachments->record($actor, (int) $candidate->work_order_id, (int) $candidate->id);
                } catch (HttpExceptionInterface $error) {
                    if (! in_array($error->getStatusCode(), [403, 404], true)) {
                        throw $error;
                    }

                    continue;
                }
                $url = '/fleet-assets/maintenance/work-orders/'.$row->work_order_id;
                $files[] = $this->file('maintenance-'.$row->id, $row->original_name, $row->mime_type, $row->byte_size,
                    $row->created_at, 'Maintenance work WO-'.$row->work_order_id, $url, $url.'/attachments/'.$row->id,
                    Storage::disk('private')->exists($row->path));
            }
        }
        if ($this->maintenance->canManage($actor) || $this->maintenance->canReview($actor, $asset)) {
            foreach ($runs as $run) {
                foreach ($run->responses ?? [] as $question => $answer) {
                    $file = is_array($answer) ? ($answer['evidence_file'] ?? null) : null;
                    if (! is_array($file) || ! isset($file['path'], $file['original_name'], $file['mime_type'])) {
                        continue;
                    }
                    $files[] = $this->file('check-'.$run->id.'-'.$question, $file['original_name'], $file['mime_type'], $file['size'] ?? null,
                        $run->completed_at?->toISOString(), 'Original check CHK-'.$run->id, '/fleet-assets/inspections/'.$run->id,
                        '/fleet-assets/maintenance/checklists/runs/'.$run->id.'/evidence/'.rawurlencode((string) $question),
                        Storage::disk('private')->exists($file['path']));
                }
            }
        }

        return [
            'ownership' => ['name' => $ownerships->count() > 1 ? 'Conflicting ownership records — review required' : ($ownerName ?: ($owner ? 'Restricted owner' : 'Not recorded')),
                'type' => $ownerName ? $owner->owner_type : null, 'since' => $ownerName ? $owner->effective_from?->toISOString() : null],
            'observations' => $scans, 'original_checks' => $checks, 'source_files' => $files,
            'costs' => $this->costs($actor, $asset),
        ];
    }

    private function file(string $id, string $name, string $mime, ?int $bytes, ?string $at, string $source, string $sourceUrl, string $url, bool $available): array
    {
        return ['id' => $id, 'name' => $name, 'filename' => $name, 'mime' => $mime, 'bytes' => $bytes,
            'category' => 'Source evidence', 'version' => 1, 'set_id' => null, 'set_version' => 0,
            'current' => true, 'archived' => false, 'state' => $available ? 'available' : 'unavailable',
            'added_at' => $at, 'added_by' => null, 'expiry_date' => null, 'reason' => null,
            'source' => $source, 'sourceUrl' => $sourceUrl, 'sourceOwned' => true,
            'previewUrl' => $available ? $url : null, 'downloadUrl' => $available ? $url : null,
            'unavailableReason' => $available ? null : 'The original file is unavailable. Follow its source to resolve this.'];
    }

    private function costs(User $actor, Asset $asset): array
    {
        $sites = array_values(array_intersect($this->access->accessibleSiteIds($actor), $this->finance->financeSiteIds($actor)));
        $allowed = $actor->canDo('finance.assets.view') && $actor->canDo('finance.ledger.view') && in_array((int) $asset->site_id, $sites, true);
        $bills = $actor->canDo('finance.ap.view') ? FinBill::where('asset_id', $asset->id)->whereIn('site_id', $sites)
            ->with('vendor:id,name')->latest('bill_date')->limit(50)->get()->map(fn ($bill) => [
                'id' => $bill->id, 'reference' => $bill->bill_number, 'supplier' => $bill->vendor?->name,
                'status' => $bill->status, 'amount' => $bill->total_amount, 'date' => $bill->bill_date?->toDateString(),
                'url' => '/finance/bills/'.$bill->id,
            ])->values() : collect();
        $from = today()->subYear()->addDay()->toDateString();
        $to = today()->toDateString();
        $base = FinCurrency::where('is_base', true)->first()?->code ?: 'NZD';
        $query = FinCostAllocation::where('asset_id', $asset->id)->whereIn('site_id', $sites)
            ->whereIn('event_type', ['asset_maintenance_expense', 'fleet_maintenance_expense'])
            ->whereBetween('event_date', [$from, $to])->whereHas('journal', fn ($journal) => $journal->where('status', 'posted')->whereNull('reversed_by_journal_id')->whereNull('reversal_of_journal_id'));
        // A work estimate, invoice and allocation are not three separate expenses.
        // Only posted allocation rows contribute; never add operational estimates.
        $groups = $allowed ? (clone $query)->with('journal.currency')->get()->groupBy(fn ($row) => $row->journal->currency?->code ?: $base)
            ->map(fn ($rows, $currency) => ['currency' => $currency, 'amount' => $rows->reduce(fn ($sum, $row) => bcadd($sum, (string) $row->amount, 2), '0.00')])->values() : collect();
        $entries = $allowed ? (clone $query)->with('journal.currency')->latest('event_date')->limit(50)->get()->map(fn ($row) => [
            'id' => $row->id, 'date' => $row->event_date->toDateString(), 'amount' => $row->amount,
            'currency' => $row->journal->currency?->code ?: $base, 'reference' => $row->journal->journal_number,
            'url' => '/finance/journals/'.$row->journal_id,
        ])->values() : collect();

        return ['allowed' => $allowed, 'from' => $from, 'to' => $to, 'totals' => $groups, 'entries' => $entries, 'bills' => $bills];
    }
}
