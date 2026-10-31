<?php

namespace App\Console\Commands;

use App\Domain\Referrals\Services\ReferralSearchIndex;
use Illuminate\Console\Command;

final class RebuildReferralSearchIndex extends Command
{
    protected $signature = 'referrals:rebuild-search-index';

    protected $description = 'Rebuild the hashed referral name index (run after rotating the application key).';

    public function handle(ReferralSearchIndex $index): int
    {
        $this->info('Indexed '.$index->rebuild().' referrals.');

        return self::SUCCESS;
    }
}
