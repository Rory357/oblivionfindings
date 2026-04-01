<?php

namespace App\Console\Commands;

use App\Domain\Hr\Models\HrJobPosting;
use Illuminate\Console\Command;

class CloseExpiredJobPostings extends Command
{
    protected $signature = 'postings:close-expired';

    protected $description = 'Close job postings that have passed their closing date';

    public function handle(): int
    {
        $count = HrJobPosting::where('status', 'published')
            ->whereNotNull('closes_at')
            ->where('closes_at', '<', now()->toDateString())
            ->update(['status' => 'closed']);

        $this->info("Closed {$count} expired job posting(s).");

        return self::SUCCESS;
    }
}
