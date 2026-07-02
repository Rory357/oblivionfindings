<?php

namespace App\Http\Controllers\Concerns;

/**
 * Neutralises CSV formula injection (OWASP) for spreadsheet exports.
 *
 * A cell whose text begins with =, +, -, @, a tab or a carriage return is
 * interpreted as a formula by Excel/LibreOffice/Sheets — so an attacker-chosen
 * client name, medication name or note like `=cmd|'/c calc'!A1` executes on
 * open. Prefixing such a value with an apostrophe forces the spreadsheet to
 * treat it as literal text without changing the visible content in the app.
 *
 * Mounted on the base Controller so `putCsv()` is available everywhere. The
 * cell method is named `sanitizeCsvCell` (not `csvCell`) deliberately: several
 * controllers already define their own `private csvCell`, and a `protected`
 * trait method of the same name inherited via the parent would be an illegal
 * visibility reduction (PHP fatal). This name avoids that collision.
 */
trait SanitizesCsvOutput
{
    /**
     * Write one CSV row with every cell sanitised.
     *
     * Drop-in replacement for `fputcsv($handle, $row)`.
     *
     * @param  resource  $handle
     * @param  array<int, mixed>  $row
     */
    protected function putCsv($handle, array $row): void
    {
        fputcsv($handle, array_map([$this, 'sanitizeCsvCell'], $row));
    }

    /**
     * Neutralise a single cell value.
     */
    protected function sanitizeCsvCell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        $trimmed = ltrim($value);

        // A purely numeric cell (negative numbers, "+64…" phone numbers) is not
        // a formula threat — leave it so the spreadsheet keeps it as a number and
        // exact-value expectations aren't disturbed. Real payloads (=cmd, @SUM,
        // -2+cmd) are never numeric.
        if (is_numeric($trimmed)) {
            return $value;
        }

        $firstMeaningful = $trimmed[0] ?? '';

        if (in_array($firstMeaningful, ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
