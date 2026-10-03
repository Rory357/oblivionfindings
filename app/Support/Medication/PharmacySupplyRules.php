<?php

namespace App\Support\Medication;

use InvalidArgumentException;

final class PharmacySupplyRules
{
    public const CLOSED = ['delivered', 'received', 'closed_short', 'cancelled'];

    public static function assertEditable(string $status): void
    {
        if (in_array($status, self::CLOSED, true)) {
            throw new InvalidArgumentException('This order is closed. Its record is read only.');
        }
    }

    public static function afterReceipt(string $status, int|float|string $ordered, int|float|string $alreadyReceived, int|float|string $arrived): array
    {
        self::assertEditable($status);
        if (! in_array($status, ['dispensed', 'part_received'], true)) {
            throw new InvalidArgumentException('Record what the pharmacy dispensed before receiving this delivery.');
        }
        $arrived = MedicationStockQuantity::normalize($arrived);
        if (! MedicationStockQuantity::greaterThan($arrived, 0)) {
            throw new InvalidArgumentException('Count what arrived and enter a quantity above zero.');
        }
        $total = MedicationStockQuantity::add($alreadyReceived, $arrived);
        if (MedicationStockQuantity::greaterThan($total, $ordered)) {
            throw new InvalidArgumentException('This exceeds the quantity on the supply record. Ask the house lead to check it with the pharmacy.');
        }

        return ['quantity_received' => $total, 'status' => MedicationStockQuantity::equals($total, $ordered) ? 'delivered' : 'part_received'];
    }

    public static function assertClosure(string $status, string $action, string $reason): void
    {
        self::assertEditable($status);
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Enter why this order is being closed.');
        }
        if ($action === 'closed_short' && $status !== 'part_received') {
            throw new InvalidArgumentException('Only a part-received order can be closed short.');
        }
        if ($action === 'cancelled' && $status === 'part_received') {
            throw new InvalidArgumentException('This order has received stock. Close it short so the received supply is retained.');
        }
        if (! in_array($action, ['cancelled', 'closed_short'], true)) {
            throw new InvalidArgumentException('Choose Cancel order or Close short.');
        }
    }
}
