<?php

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Services\BankReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ImportBankTransactionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $orgId,
        public int $bankAccountId,
        public string $filePath,
    ) {}

    public function handle(BankReconciliationService $service): void
    {
        $result = $service->importTransactions($this->orgId, $this->bankAccountId, $this->filePath);

        Log::info("Bank transactions imported for organisation #{$this->orgId}, bank account #{$this->bankAccountId}.", [
            'imported' => $result['imported'],
            'skipped' => $result['skipped'],
        ]);
    }
}
