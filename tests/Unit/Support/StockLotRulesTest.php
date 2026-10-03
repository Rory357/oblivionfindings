<?php

namespace Tests\Unit\Support;

use App\Support\Medication\StockLotRules;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class StockLotRulesTest extends TestCase
{
    public function test_fefo_allocates_fractions_across_usable_packs_with_unknown_expiry_last(): void
    {
        $lots = [
            ['id' => 1, 'quantity_remaining' => '9.00', 'expiry_date' => null],
            ['id' => 2, 'quantity_remaining' => '0.75', 'expiry_date' => '2026-10-03'],
            ['id' => 3, 'quantity_remaining' => '2.00', 'expiry_date' => '2026-10-30'],
            ['id' => 4, 'quantity_remaining' => '99.00', 'expiry_date' => '2026-10-02'],
            ['id' => 5, 'quantity_remaining' => '99.00', 'expiry_date' => '2027-01-01', 'state' => 'quarantined'],
        ];
        $this->assertSame([
            ['lot_id' => 2, 'quantity' => '0.75', 'before' => '0.75', 'after' => '0.00'],
            ['lot_id' => 3, 'quantity' => '0.50', 'before' => '2.00', 'after' => '1.50'],
        ], StockLotRules::allocate($lots, '1.25', '2026-10-03'));
        $this->assertSame('11.75', StockLotRules::onHand($lots, '2026-10-03'));
    }

    public function test_shortage_fails_without_producing_a_partial_allocation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        StockLotRules::allocate([['id' => 1, 'quantity_remaining' => '0.25']], '0.50', '2026-10-03');
    }

    public function test_month_expiry_keeps_the_last_day_and_leap_year(): void
    {
        $this->assertSame('2028-02-29', StockLotRules::monthExpiry('02/2028'));
        $this->assertSame('2027-02-28', StockLotRules::monthExpiry('02/2027'));
    }

    public function test_malformed_month_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        StockLotRules::monthExpiry('13/2027');
    }
}
