<?php

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Models\FinAuditExport;
use App\Domain\Finance\Services\AuditExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateAuditExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600; // 10 minutes for large exports

    public function __construct(
        public int $exportId,
    ) {}

    public function handle(AuditExportService $service): void
    {
        $export = FinAuditExport::findOrFail($this->exportId);

        try {
            $service->generate($export);

            Log::info("Audit export '{$export->export_name}' (ID: {$export->id}) generated successfully.");
        } catch (\Exception $e) {
            Log::error("Failed to generate audit export '{$export->export_name}' (ID: {$export->id}): {$e->getMessage()}");

            $export->update(['status' => 'failed']);

            throw $e;
        }
    }
}
