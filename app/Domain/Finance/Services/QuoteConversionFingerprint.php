<?php

namespace App\Domain\Finance\Services;

use JsonException;

final class QuoteConversionFingerprint
{
    /**
     * Bind the accepted quote fields that determine either conversion effect.
     * Callers must supply line items in their canonical persisted order.
     *
     * @param  iterable<int, array<string, mixed>|object>  $lineItems
     *
     * @throws JsonException
     */
    public static function make(array|object $quote, iterable $lineItems): string
    {
        $lines = [];

        foreach ($lineItems as $lineItem) {
            $lines[] = [
                'description' => (string) self::value($lineItem, 'description'),
                'quantity' => self::decimal($lineItem, 'quantity'),
                'unit' => (string) (self::value($lineItem, 'unit') ?: 'hour'),
                'unit_price' => self::decimal($lineItem, 'unit_price'),
                'amount' => self::decimal($lineItem, 'amount'),
            ];
        }

        return hash('sha256', json_encode([
            'client_id' => (int) self::value($quote, 'client_id'),
            'client_name' => self::nullableString($quote, 'client_name'),
            'client_email' => self::nullableString($quote, 'client_email'),
            'title' => (string) self::value($quote, 'title'),
            'subtotal' => self::decimal($quote, 'subtotal'),
            'tax_amount' => self::decimal($quote, 'tax_amount'),
            'total_amount' => self::decimal($quote, 'total_amount'),
            'terms' => self::nullableString($quote, 'terms'),
            'notes' => self::nullableString($quote, 'notes'),
            'lines' => $lines,
        ], JSON_THROW_ON_ERROR));
    }

    private static function decimal(array|object $record, string $key): string
    {
        return bcadd((string) self::value($record, $key), '0', 2);
    }

    private static function nullableString(array|object $record, string $key): ?string
    {
        $value = self::value($record, $key);

        return $value === null ? null : (string) $value;
    }

    private static function value(array|object $record, string $key): mixed
    {
        return is_array($record) ? ($record[$key] ?? null) : ($record->{$key} ?? null);
    }
}
