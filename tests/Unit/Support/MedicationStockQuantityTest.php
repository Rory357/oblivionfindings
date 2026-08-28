<?php

namespace Tests\Unit\Support;

use App\Support\Medication\MedicationStockQuantity;
use InvalidArgumentException;
use Tests\TestCase;

class MedicationStockQuantityTest extends TestCase
{
    public function test_half_unit_arithmetic_is_exact_at_the_shared_scale(): void
    {
        $this->assertSame('9.50', MedicationStockQuantity::subtract('10.00', '0.50'));
        $this->assertSame('10.00', MedicationStockQuantity::add('9.50', '0.50'));
        $this->assertSame('-0.50', MedicationStockQuantity::subtract('9.50', '10.00'));
        $this->assertTrue(MedicationStockQuantity::equals('9.5', '9.50'));
        $this->assertSame('0.50', MedicationStockQuantity::normalize('.5'));
        $this->assertSame('1.00', MedicationStockQuantity::normalize('1.'));
        $this->assertTrue(MedicationStockQuantity::lessThanOrEqual('9.50', 10));
        $this->assertFalse(MedicationStockQuantity::lessThanOrEqual('10.50', 10));
        $this->assertSame(2.5, MedicationStockQuantity::toFloat(
            MedicationStockQuantity::subtract('12.00', '9.50'),
        ));
    }

    public function test_more_than_two_decimal_places_are_rejected_instead_of_rounded(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MedicationStockQuantity::normalize('0.015');
    }

    public function test_decimal_12_2_storage_boundary_is_exact_and_rejects_the_next_minor_unit(): void
    {
        $this->assertSame('9999999999.99', MedicationStockQuantity::DECIMAL_12_2_MAX);
        $this->assertSame('max:9999999999.99', MedicationStockQuantity::DECIMAL_12_2_MAX_RULE);
        $this->assertSame(
            MedicationStockQuantity::DECIMAL_12_2_MAX,
            MedicationStockQuantity::normalize(MedicationStockQuantity::DECIMAL_12_2_MAX),
        );

        $this->expectException(InvalidArgumentException::class);

        MedicationStockQuantity::normalize('10000000000.00');
    }

    public function test_decimal_10_2_movement_boundary_is_exact_and_rejects_the_next_minor_unit(): void
    {
        $this->assertSame('99999999.99', MedicationStockQuantity::DECIMAL_10_2_MAX);
        $this->assertSame('max:99999999.99', MedicationStockQuantity::DECIMAL_10_2_MAX_RULE);
        $this->assertSame(
            MedicationStockQuantity::DECIMAL_10_2_MAX,
            MedicationStockQuantity::normalizeMovement(MedicationStockQuantity::DECIMAL_10_2_MAX),
        );

        $this->expectException(InvalidArgumentException::class);

        MedicationStockQuantity::normalizeMovement('100000000.00');
    }

    public function test_derived_balance_cannot_exceed_decimal_12_2_storage(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MedicationStockQuantity::add(MedicationStockQuantity::DECIMAL_12_2_MAX, '0.01');
    }
}
