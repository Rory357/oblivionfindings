<?php

namespace App\Support\Medication;

use DateTimeImmutable;
use InvalidArgumentException;

/** Pure precise rules; the caller supplies the NZ date and takes canonical locks. */
final class StockLotRules
{
    public static function monthExpiry(string $value): string
    {
        if (! preg_match('/\\A(0[1-9]|1[0-2])\\/(\\d{4})\\z/', trim($value), $parts) || (int) $parts[2] < 1900) {
            throw new InvalidArgumentException('Enter the expiry as MM/YYYY, as printed on the pack.');
        }

        return (new DateTimeImmutable($parts[2].'-'.$parts[1].'-01'))->format('Y-m-t');
    }

    /** The printed expiry day remains usable through its NZ calendar day. */
    public static function usable(array $lot, string $today): bool
    {
        return ($lot['state'] ?? 'open') === 'open'
            && MedicationStockQuantity::greaterThan($lot['quantity_remaining'], 0)
            && (($lot['expiry_date'] ?? null) === null || $lot['expiry_date'] >= $today);
    }

    /** @return list<array{lot_id: int, quantity: string, before: string, after: string}> */
    public static function allocate(array $lots, int|float|string $quantity, string $today): array
    {
        $quantity = MedicationStockQuantity::normalize($quantity);
        if (! MedicationStockQuantity::greaterThan($quantity, 0)) {
            throw new InvalidArgumentException('Enter a quantity above zero.');
        }
        $lots = array_values(array_filter($lots, fn (array $lot): bool => self::usable($lot, $today)));
        usort($lots, fn (array $a, array $b): int =>
            [($a['expiry_date'] ?? '9999-12-31'), ($a['received_at'] ?? ''), $a['id']]
            <=> [($b['expiry_date'] ?? '9999-12-31'), ($b['received_at'] ?? ''), $b['id']]);
        $remaining = $quantity;
        $result = [];
        foreach ($lots as $lot) {
            $before = MedicationStockQuantity::normalize($lot['quantity_remaining']);
            $take = MedicationStockQuantity::greaterThan($remaining, $before) ? $before : $remaining;
            $result[] = ['lot_id' => (int) $lot['id'], 'quantity' => $take, 'before' => $before, 'after' => MedicationStockQuantity::subtract($before, $take)];
            $remaining = MedicationStockQuantity::subtract($remaining, $take);
            if (MedicationStockQuantity::equals($remaining, 0)) {
                return $result;
            }
        }

        throw new InvalidArgumentException('There is not enough usable stock. Check the packs and reconcile the stock count.');
    }

    public static function onHand(array $lots, string $today): string
    {
        return array_reduce($lots, fn (string $sum, array $lot): string =>
            self::usable($lot, $today) ? MedicationStockQuantity::add($sum, $lot['quantity_remaining']) : $sum, '0.00');
    }
}
