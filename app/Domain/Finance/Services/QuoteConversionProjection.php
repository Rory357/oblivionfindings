<?php

namespace App\Domain\Finance\Services;

use App\Models\Quote;
use JsonException;
use RuntimeException;

final class QuoteConversionProjection
{
    /**
     * @param  iterable<int, array<string, mixed>|object>  $lineItems
     * @return array{
     *     digest:string,
     *     subtotal:string,
     *     tax:string,
     *     total:string,
     *     lines:list<array{description:string,quantity:string,unit:string,unit_price:string,tax:string,gross:string}>
     * }
     */
    public static function make(array|object $quote, iterable $lineItems): array
    {
        $records = [];
        $subtotal = '0.00';
        $lines = [];
        foreach ($lineItems as $lineItem) {
            $records[] = $lineItem;
            $quantity = self::decimal($lineItem, 'quantity');
            $unitPrice = self::decimal($lineItem, 'unit_price');
            $net = bcmul($quantity, $unitPrice, 2);
            if (bccomp($net, (string) self::value($lineItem, 'amount'), 2) !== 0) {
                throw new RuntimeException(
                    'The accepted quote line totals have changed and require review.',
                );
            }

            $subtotal = bcadd($subtotal, $net, 2);
            $lines[] = [
                'description' => (string) self::value($lineItem, 'description'),
                'quantity' => $quantity,
                'unit' => (string) (self::value($lineItem, 'unit') ?: 'hour'),
                'unit_price' => $unitPrice,
                'net' => $net,
            ];
        }

        if ($lines === []) {
            throw new RuntimeException('A quote must contain at least one line before conversion.');
        }

        $tax = bcmul($subtotal, '0.15', 2);
        $total = bcadd($subtotal, $tax, 2);
        if (bccomp($subtotal, (string) self::value($quote, 'subtotal'), 2) !== 0
            || bccomp($tax, (string) self::value($quote, 'tax_amount'), 2) !== 0
            || bccomp($total, (string) self::value($quote, 'total_amount'), 2) !== 0) {
            throw new RuntimeException(
                'The accepted quote totals have changed and require review.',
            );
        }

        $allocatedTax = '0.00';
        $lastIndex = count($lines) - 1;
        foreach ($lines as $index => &$line) {
            $lineTax = $index === $lastIndex
                ? bcsub($tax, $allocatedTax, 2)
                : bcmul($line['net'], '0.15', 2);
            if (bccomp($lineTax, '0.00', 2) < 0) {
                throw new RuntimeException('The accepted quote GST allocation is invalid.');
            }

            $allocatedTax = bcadd($allocatedTax, $lineTax, 2);
            $line['tax'] = $lineTax;
            $line['gross'] = bcadd($line['net'], $lineTax, 2);
            unset($line['net']);
        }
        unset($line);

        try {
            $digest = QuoteConversionFingerprint::make($quote, $records);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'The accepted quote payload cannot be fingerprinted.',
                previous: $exception,
            );
        }

        return compact('digest', 'subtotal', 'tax', 'total', 'lines');
    }

    /**
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     */
    public static function agreementHeader(array|object $quote, array $projection): array
    {
        return [
            'client_id' => (int) self::value($quote, 'client_id'),
            'title' => (string) self::value($quote, 'title'),
            'agreement_type' => 'private',
            'total_budget' => $projection['total'],
            'gst_inclusive' => true,
            'terms' => self::nullableString($quote, 'terms'),
            'notes' => self::nullableString($quote, 'notes'),
        ];
    }

    /**
     * @param  array<string, mixed>  $projection
     * @return list<array<string, mixed>>
     */
    public static function agreementLines(array $projection): array
    {
        return collect($projection['lines'])
            ->values()
            ->map(fn (array $line, int $index): array => [
                'item_number' => (string) ($index + 1),
                'description' => $line['description'],
                'quantity' => $line['quantity'],
                'unit' => $line['unit'],
                'unit_price' => $line['unit_price'],
                'budget_allocated' => $line['gross'],
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     */
    public static function invoiceHeader(
        array|object $quote,
        array|object $client,
        array $projection,
        int $storageContextId,
    ): array {
        $clientName = (string) (self::value($quote, 'client_name')
            ?: trim((string) self::value($client, 'first_name').' '.(string) self::value($client, 'last_name')));

        return [
            'organization_id' => $storageContextId,
            'client_id' => (int) self::value($quote, 'client_id'),
            'client_name' => $clientName,
            'client_email' => self::nullableString($quote, 'client_email'),
            'subtotal' => $projection['subtotal'],
            'tax_amount' => $projection['tax'],
            'total_amount' => $projection['total'],
            'currency_code' => 'NZD',
            'source' => 'quote',
            'source_type' => Quote::class,
            'source_id' => (int) self::value($quote, 'id'),
            'notes' => self::nullableString($quote, 'notes'),
            'terms' => self::nullableString($quote, 'terms'),
        ];
    }

    /**
     * @param  array<string, mixed>  $projection
     * @return list<array<string, mixed>>
     */
    public static function invoiceLines(array $projection): array
    {
        return collect($projection['lines'])
            ->values()
            ->map(fn (array $line, int $index): array => [
                'description' => $line['description'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'tax_amount' => $line['tax'],
                'line_total' => $line['gross'],
                'sort_order' => $index,
            ])
            ->all();
    }

    /**
     * @param  iterable<int, array<string, mixed>|object>  $actualLines
     * @param  array<string, mixed>  $projection
     */
    public static function agreementMatches(
        array|object $quote,
        array|object $agreement,
        iterable $actualLines,
        array $projection,
    ): bool {
        return self::rowMatches(
            $agreement,
            self::agreementHeader($quote, $projection),
            ['total_budget'],
        ) && self::orderedRowsMatch(
            $actualLines,
            self::agreementLines($projection),
            ['quantity', 'unit_price', 'budget_allocated'],
        );
    }

    /**
     * @param  iterable<int, array<string, mixed>|object>  $actualLines
     * @param  array<string, mixed>  $projection
     */
    public static function invoiceMatches(
        array|object $quote,
        array|object $client,
        array|object $invoice,
        iterable $actualLines,
        array $projection,
        int $storageContextId,
    ): bool {
        return self::rowMatches(
            $invoice,
            self::invoiceHeader($quote, $client, $projection, $storageContextId),
            ['subtotal', 'tax_amount', 'total_amount'],
        ) && self::orderedRowsMatch(
            $actualLines,
            self::invoiceLines($projection),
            ['quantity', 'unit_price', 'tax_amount', 'line_total'],
        );
    }

    /**
     * @param  iterable<int, array<string, mixed>|object>  $actualRows
     * @param  iterable<int, array<string, mixed>>  $expectedRows
     * @param  list<string>  $decimalFields
     */
    private static function orderedRowsMatch(
        iterable $actualRows,
        iterable $expectedRows,
        array $decimalFields,
    ): bool {
        $actual = collect($actualRows)->values();
        $expected = collect($expectedRows)->values();
        if ($actual->count() !== $expected->count()) {
            return false;
        }

        return $expected->every(fn (array $row, int $index): bool => self::rowMatches(
            $actual->get($index),
            $row,
            $decimalFields,
        ));
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  list<string>  $decimalFields
     */
    private static function rowMatches(
        array|object $actual,
        array $expected,
        array $decimalFields = [],
    ): bool {
        foreach ($expected as $field => $expectedValue) {
            $actualValue = self::value($actual, $field);
            if ($actualValue === null || $expectedValue === null) {
                if ($actualValue !== $expectedValue) {
                    return false;
                }

                continue;
            }
            if (in_array($field, $decimalFields, true)) {
                if (bccomp((string) $actualValue, (string) $expectedValue, 2) !== 0) {
                    return false;
                }

                continue;
            }
            if ((string) $actualValue !== (string) $expectedValue) {
                return false;
            }
        }

        return true;
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
