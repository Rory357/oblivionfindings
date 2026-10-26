<?php

namespace App\Console\Commands;

use App\Domain\Referrals\Services\ReferralDelivery;
use Illuminate\Console\Command;

class DeliverReferralCommunications extends Command
{
    protected $signature = 'referrals:deliver-communications {--limit=25}';

    protected $description = 'Deliver due referral intake notifications, safe acknowledgements and staff messages';

    public function handle(ReferralDelivery $delivery): int
    {
        $this->info($delivery->run((int) $this->option('limit')).' delivery records checked.');

        return self::SUCCESS;
    }
}
