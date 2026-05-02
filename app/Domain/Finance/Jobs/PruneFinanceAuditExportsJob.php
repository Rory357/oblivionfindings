<?php

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Models\FinAuditExport;
use App\Services\AuditLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PruneFinanceAuditExportsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $retentionYears = (int) config('finance.audit_exports.retention_years', 7);

        if ($retentionYears <= 0) {
            return;
        }

        $cutoff = now()->subYears($retentionYears);
        $disk = Storage::disk((string) config('finance.audit_exports.disk', 'local'));

        $exports = FinAuditExport::query()
            ->where('status', 'completed')
            ->where(function ($query) use ($cutoff) {
                $query->where('generated_at', '<', $cutoff)
                    ->orWhere(function ($query) use ($cutoff) {
                        $query->whereNull('generated_at')
                            ->where('created_at', '<', $cutoff);
                    });
            })
            ->get();

        foreach ($exports as $export) {
            DB::transaction(function () use ($export, $disk, $retentionYears) {
                if ($export->file_path && $disk->exists($export->file_path)) {
                    $disk->delete($export->file_path);
                }

                AuditLogger::log('data_retention.finance_audit_export_pruned', $export, [
                    'retention_period_years' => $retentionYears,
                    'file_path' => $export->file_path,
                ]);

                $export->delete();
            });
        }

        if ($exports->isNotEmpty()) {
            Log::info("Finance retention: pruned {$exports->count()} audit export(s).");
        }
    }
}
