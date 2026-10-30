<?php

namespace App\Jobs;

use App\Services\Fleet\FinanceReviewNotices;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverFinanceReviewNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    public function __construct(public int $noticeId) {}

    public function backoff(): array
    {
        return [300, 900, 1800];
    }

    public function handle(FinanceReviewNotices $notices): void
    {
        $notices->deliver($this->noticeId);
    }
}
