<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinAuditExport;
use App\Domain\Finance\Models\FinBankReconciliation;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinFixedAsset;
use App\Domain\Finance\Models\FinFixedAssetDepreciation;
use App\Domain\Finance\Models\FinGstReturn;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinJournalLine;
use App\Domain\Finance\Models\FinPaymentAllocation;
use App\Domain\Finance\Models\FinPaymentRun;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class AuditExportService
{
    /**
     * Generate a complete audit export ZIP containing CSV files for each selected section.
     *
     * @return string The storage path of the generated ZIP file.
     */
    public function generate(FinAuditExport $export): string
    {
        $export->update(['status' => 'generating']);

        $orgId = $export->organization_id;
        $from = $export->period_from;
        $to = $export->period_to;

        $sheets = [];

        if ($export->include_journals) {
            $sheets['Journals'] = $this->getJournalsData($orgId, $from, $to);
            $sheets['Journal_Lines'] = $this->getJournalLinesData($orgId, $from, $to);
        }

        if ($export->include_bank_reconciliations) {
            $sheets['Bank_Reconciliations'] = $this->getBankReconciliationsData($orgId, $from, $to);
        }

        if ($export->include_ap) {
            $sheets['Bills'] = $this->getBillsData($orgId, $from, $to);
            $sheets['Payment_Runs'] = $this->getPaymentRunsData($orgId, $from, $to);
        }

        if ($export->include_ar) {
            $sheets['Payment_Allocations'] = $this->getPaymentAllocationsData($orgId, $from, $to);
        }

        if ($export->include_gst) {
            $sheets['GST_Returns'] = $this->getGstReturnsData($orgId, $from, $to);
        }

        if ($export->include_fixed_assets) {
            $sheets['Fixed_Assets'] = $this->getFixedAssetsData($orgId, $from, $to);
            $sheets['Depreciation'] = $this->getDepreciationData($orgId, $from, $to);
        }

        $zipPath = "audit-exports/{$orgId}/{$export->id}.zip";
        $fullPath = Storage::disk('local')->path($zipPath);

        // Ensure directory exists
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $zip = new ZipArchive();
        if ($zip->open($fullPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $export->update(['status' => 'failed']);
            throw new \RuntimeException("Could not create ZIP archive at {$fullPath}");
        }

        foreach ($sheets as $name => $rows) {
            $csv = $this->arrayToCsv($rows);
            $zip->addFromString("{$name}.csv", $csv);
        }

        // Add a summary manifest
        $zip->addFromString('_manifest.txt', $this->buildManifest($export, $sheets));

        $zip->close();

        $export->update([
            'status' => 'completed',
            'file_path' => $zipPath,
            'file_size_bytes' => filesize($fullPath),
            'generated_at' => now(),
        ]);

        return $zipPath;
    }

    private function getJournalsData(int $orgId, $from, $to): array
    {
        return FinJournal::forOrganization($orgId)
            ->forPeriod($from, $to)
            ->with(['createdBy:id,name', 'postedBy:id,name', 'fiscalPeriod:id,name'])
            ->orderBy('journal_date')
            ->get()
            ->map(fn($j) => [
                'Journal Number' => $j->journal_number,
                'Date' => $j->journal_date->format('Y-m-d'),
                'Type' => $j->type,
                'Reference' => $j->reference,
                'Description' => $j->description,
                'Status' => $j->status,
                'Total Amount' => $j->total_amount,
                'Fiscal Period' => $j->fiscalPeriod->name ?? '',
                'Posted At' => $j->posted_at?->format('Y-m-d H:i:s'),
                'Posted By' => $j->postedBy->name ?? '',
                'Created By' => $j->createdBy->name ?? '',
                'Created At' => $j->created_at->format('Y-m-d H:i:s'),
            ])
            ->toArray();
    }

    private function getJournalLinesData(int $orgId, $from, $to): array
    {
        return FinJournalLine::whereHas('journal', function ($q) use ($orgId, $from, $to) {
                $q->forOrganization($orgId)->forPeriod($from, $to);
            })
            ->with([
                'journal:id,journal_number,journal_date',
                'account:id,code,name',
                'costCentre:id,code,name',
                'fundingStream:id,code,name',
                'taxRate:id,name,rate',
            ])
            ->orderBy('journal_id')
            ->get()
            ->map(fn($l) => [
                'Journal Number' => $l->journal->journal_number ?? '',
                'Journal Date' => $l->journal->journal_date?->format('Y-m-d') ?? '',
                'Account Code' => $l->account->code ?? '',
                'Account Name' => $l->account->name ?? '',
                'Description' => $l->description,
                'Debit' => $l->debit,
                'Credit' => $l->credit,
                'Tax Rate' => $l->taxRate->name ?? '',
                'Tax Amount' => $l->tax_amount,
                'Cost Centre' => $l->costCentre ? "{$l->costCentre->code} - {$l->costCentre->name}" : '',
                'Funding Stream' => $l->fundingStream ? "{$l->fundingStream->code} - {$l->fundingStream->name}" : '',
            ])
            ->toArray();
    }

    private function getBankReconciliationsData(int $orgId, $from, $to): array
    {
        return FinBankReconciliation::forOrganization($orgId)
            ->whereBetween('statement_date', [$from, $to])
            ->with(['bankAccount:id,account_name,account_number', 'completedBy:id,name', 'createdBy:id,name'])
            ->orderBy('statement_date')
            ->get()
            ->map(fn($r) => [
                'Statement Date' => $r->statement_date->format('Y-m-d'),
                'Bank Account' => $r->bankAccount->account_name ?? '',
                'Account Number' => $r->bankAccount->account_number ?? '',
                'Statement Balance' => $r->statement_balance,
                'Calculated Balance' => $r->calculated_balance,
                'Difference' => (float) $r->statement_balance - (float) $r->calculated_balance,
                'Status' => $r->status,
                'Completed At' => $r->completed_at?->format('Y-m-d H:i:s'),
                'Completed By' => $r->completedBy->name ?? '',
                'Created By' => $r->createdBy->name ?? '',
                'Notes' => $r->notes,
            ])
            ->toArray();
    }

    private function getBillsData(int $orgId, $from, $to): array
    {
        return FinBill::forOrganization($orgId)
            ->whereBetween('bill_date', [$from, $to])
            ->with(['vendor:id,name', 'approvedBy:id,name', 'createdBy:id,name'])
            ->orderBy('bill_date')
            ->get()
            ->map(fn($b) => [
                'Bill Number' => $b->bill_number,
                'Vendor' => $b->vendor->name ?? '',
                'Vendor Reference' => $b->vendor_reference,
                'Bill Date' => $b->bill_date->format('Y-m-d'),
                'Due Date' => $b->due_date->format('Y-m-d'),
                'Subtotal' => $b->subtotal,
                'GST Amount' => $b->gst_amount,
                'Total Amount' => $b->total_amount,
                'Amount Paid' => $b->amount_paid,
                'Status' => $b->status,
                'Approved By' => $b->approvedBy->name ?? '',
                'Approved At' => $b->approved_at?->format('Y-m-d H:i:s'),
                'Created By' => $b->createdBy->name ?? '',
                'Notes' => $b->notes,
            ])
            ->toArray();
    }

    private function getPaymentRunsData(int $orgId, $from, $to): array
    {
        return FinPaymentRun::forOrganization($orgId)
            ->whereBetween('payment_date', [$from, $to])
            ->with(['bankAccount:id,account_name', 'approvedBy:id,name', 'processedBy:id,name', 'createdBy:id,name'])
            ->orderBy('payment_date')
            ->get()
            ->map(fn($pr) => [
                'Run Number' => $pr->run_number,
                'Payment Date' => $pr->payment_date->format('Y-m-d'),
                'Bank Account' => $pr->bankAccount->account_name ?? '',
                'Total Amount' => $pr->total_amount,
                'Item Count' => $pr->item_count,
                'Status' => $pr->status,
                'Approved By' => $pr->approvedBy->name ?? '',
                'Approved At' => $pr->approved_at?->format('Y-m-d H:i:s'),
                'Processed By' => $pr->processedBy->name ?? '',
                'Processed At' => $pr->processed_at?->format('Y-m-d H:i:s'),
                'Created By' => $pr->createdBy->name ?? '',
                'Notes' => $pr->notes,
            ])
            ->toArray();
    }

    private function getPaymentAllocationsData(int $orgId, $from, $to): array
    {
        return FinPaymentAllocation::forOrganization($orgId)
            ->whereBetween('payment_date', [$from, $to])
            ->with(['createdBy:id,name'])
            ->orderBy('payment_date')
            ->get()
            ->map(fn($pa) => [
                'Payment Date' => $pa->payment_date->format('Y-m-d'),
                'Type' => $pa->type,
                'Amount' => $pa->amount,
                'Allocatable Type' => $pa->allocatable_type,
                'Allocatable ID' => $pa->allocatable_id,
                'Source Type' => $pa->source_type,
                'Source ID' => $pa->source_id,
                'Notes' => $pa->notes,
                'Created By' => $pa->createdBy->name ?? '',
                'Created At' => $pa->created_at->format('Y-m-d H:i:s'),
            ])
            ->toArray();
    }

    private function getGstReturnsData(int $orgId, $from, $to): array
    {
        return FinGstReturn::forOrganization($orgId)
            ->where('period_start', '>=', $from)
            ->where('period_end', '<=', $to)
            ->with(['createdBy:id,name'])
            ->orderBy('period_start')
            ->get()
            ->map(fn($g) => [
                'Period Start' => $g->period_start->format('Y-m-d'),
                'Period End' => $g->period_end->format('Y-m-d'),
                'Filing Frequency' => $g->filing_frequency,
                'Basis' => $g->basis,
                'Total Sales' => $g->total_sales,
                'GST Collected' => $g->total_gst_collected,
                'Total Purchases' => $g->total_purchases,
                'GST Paid' => $g->total_gst_paid,
                'GST Payable' => $g->gst_payable,
                'Adjustments' => $g->adjustments,
                'Status' => $g->status,
                'IRD Period' => $g->ird_period,
                'Filed At' => $g->filed_at?->format('Y-m-d H:i:s'),
                'Created By' => $g->createdBy->name ?? '',
            ])
            ->toArray();
    }

    private function getFixedAssetsData(int $orgId, $from, $to): array
    {
        return FinFixedAsset::where('organization_id', $orgId)
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('purchase_date', [$from, $to])
                    ->orWhere(function ($q2) use ($from, $to) {
                        $q2->where('purchase_date', '<=', $to)
                            ->where(function ($q3) use ($from) {
                                $q3->whereNull('disposed_date')
                                    ->orWhere('disposed_date', '>=', $from);
                            });
                    });
            })
            ->with(['createdBy:id,name'])
            ->orderBy('asset_tag')
            ->get()
            ->map(fn($a) => [
                'Asset Tag' => $a->asset_tag,
                'Asset Name' => $a->asset_name,
                'Category' => $a->category,
                'Purchase Date' => $a->purchase_date->format('Y-m-d'),
                'Purchase Cost' => $a->purchase_cost,
                'Residual Value' => $a->residual_value,
                'Useful Life (months)' => $a->useful_life_months,
                'Depreciation Method' => $a->depreciation_method,
                'Accumulated Depreciation' => $a->accumulated_depreciation,
                'Net Book Value' => (float) $a->purchase_cost - (float) $a->accumulated_depreciation,
                'Status' => $a->status,
                'Disposed Date' => $a->disposed_date?->format('Y-m-d'),
                'Disposal Proceeds' => $a->disposal_proceeds,
                'Created By' => $a->createdBy->name ?? '',
                'Notes' => $a->notes,
            ])
            ->toArray();
    }

    private function getDepreciationData(int $orgId, $from, $to): array
    {
        return FinFixedAssetDepreciation::whereHas('fixedAsset', function ($q) use ($orgId) {
                $q->where('organization_id', $orgId);
            })
            ->whereBetween('depreciation_date', [$from, $to])
            ->with(['fixedAsset:id,asset_tag,asset_name', 'journal:id,journal_number'])
            ->orderBy('depreciation_date')
            ->get()
            ->map(fn($d) => [
                'Asset Tag' => $d->fixedAsset->asset_tag ?? '',
                'Asset Name' => $d->fixedAsset->asset_name ?? '',
                'Depreciation Date' => $d->depreciation_date->format('Y-m-d'),
                'Amount' => $d->amount,
                'Accumulated Total' => $d->accumulated_total,
                'Book Value After' => $d->book_value_after,
                'Journal Number' => $d->journal->journal_number ?? '',
            ])
            ->toArray();
    }

    /**
     * Convert an array of associative arrays to a CSV string.
     */
    private function arrayToCsv(array $rows): string
    {
        if (empty($rows)) {
            return '';
        }

        $output = fopen('php://temp', 'r+');

        // Write header from first row's keys
        fputcsv($output, array_keys($rows[0]));

        // Write data rows
        foreach ($rows as $row) {
            fputcsv($output, array_values($row));
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Build a text manifest summarising the export.
     */
    private function buildManifest(FinAuditExport $export, array $sheets): string
    {
        $lines = [
            'AUDIT EXPORT MANIFEST',
            '=====================',
            '',
            "Export Name: {$export->export_name}",
            "Period: {$export->period_from->format('Y-m-d')} to {$export->period_to->format('Y-m-d')}",
            "Generated At: " . now()->format('Y-m-d H:i:s'),
            '',
            'Included Sections:',
        ];

        foreach ($sheets as $name => $rows) {
            $lines[] = "  - {$name}: " . count($rows) . ' records';
        }

        $lines[] = '';
        $lines[] = 'This export was generated for external audit review purposes.';

        return implode("\n", $lines);
    }
}
