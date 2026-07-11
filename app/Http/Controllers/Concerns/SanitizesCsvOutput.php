<?php

namespace App\Http\Controllers\Concerns;

use Symfony\Component\HttpFoundation\StreamedResponse;

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
     * Stream a sanitised CSV download. The canonical list-export helper: pass a
     * filename, a header row, and the data rows (each an ordered array matching
     * the header). Every cell is neutralised against CSV formula injection.
     *
     * NB the name is `streamSanitizedCsv`, NOT `streamCsv`/`exportCsv`: several
     * controllers already define a `private streamCsv`/`exportCsv`, and a
     * `protected` trait method of the same name inherited via the base Controller
     * would be an illegal visibility reduction (PHP fatal). This name avoids it.
     *
     * @param  array<int, string>  $header
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    protected function streamSanitizedCsv(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $handle = fopen('php://output', 'w');
            // BOM so Excel opens UTF-8 (macrons in NZ names) correctly.
            fwrite($handle, "\xEF\xBB\xBF");
            $this->putCsv($handle, $header);
            foreach ($rows as $row) {
                $this->putCsv($handle, $row);
            }
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    /**
     * Neutralise a single cell value.
     */
    protected function sanitizeCsvCell(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        // Ignore harmless visual spacing without stripping the tab/CR control
        // prefixes that spreadsheet applications treat as executable input.
        $trimmed = ltrim($value, " \v\f");

        $firstMeaningful = $trimmed[0] ?? '';

        if (in_array($firstMeaningful, ["\t", "\r"], true)) {
            return "'".$value;
        }

        // A purely numeric cell (negative numbers, "+64…" phone numbers) is not
        // a formula threat — leave it so the spreadsheet keeps it as a number and
        // exact-value expectations aren't disturbed. Real payloads (=cmd, @SUM,
        // -2+cmd) are never numeric.
        if (is_numeric($trimmed)) {
            return $value;
        }

        if (in_array($firstMeaningful, ['=', '+', '-', '@'], true)) {
            return "'".$value;
        }

        return $value;
    }
}
