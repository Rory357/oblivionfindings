<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Contracts\BankFeedProviderInterface;
use App\Domain\Finance\Services\BankFeedProviders\AnzBankFeedProvider;
use App\Domain\Finance\Services\BankFeedProviders\AsbBankFeedProvider;
use App\Domain\Finance\Services\BankFeedProviders\BnzBankFeedProvider;
use App\Domain\Finance\Services\BankFeedProviders\WestpacBankFeedProvider;

class BankFeedProviderFactory
{
    public function make(string $provider): BankFeedProviderInterface
    {
        return match ($provider) {
            'asb' => new AsbBankFeedProvider(),
            'anz' => new AnzBankFeedProvider(),
            'westpac' => new WestpacBankFeedProvider(),
            'bnz' => new BnzBankFeedProvider(),
            default => throw new \InvalidArgumentException("Unknown bank feed provider: {$provider}"),
        };
    }
}
