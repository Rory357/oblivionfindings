<?php

namespace Tests\Unit\Support;

use App\Support\Medication\PharmacySupplyRules;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PharmacySupplyRulesTest extends TestCase
{
    public function test_part_receipt_then_final_receipt_preserves_precise_totals(): void
    {
        $this->assertSame(['quantity_received' => '4.25', 'status' => 'part_received'], PharmacySupplyRules::afterReceipt('dispensed', 10, 0, '4.25'));
        $this->assertSame(['quantity_received' => '10.00', 'status' => 'delivered'], PharmacySupplyRules::afterReceipt('part_received', 10, '4.25', '5.75'));
    }

    public function test_extra_stock_is_never_silently_added_to_a_supply_record(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PharmacySupplyRules::afterReceipt('part_received', 10, 8, 3);
    }

    public function test_received_supply_is_read_only(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PharmacySupplyRules::assertEditable('delivered');
    }

    public function test_received_stock_requires_close_short_instead_of_cancellation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PharmacySupplyRules::assertClosure('part_received', 'cancelled', 'Pharmacy cannot supply the rest.');
    }
}
