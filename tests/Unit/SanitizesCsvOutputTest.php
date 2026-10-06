<?php

namespace Tests\Unit;

use App\Http\Controllers\Concerns\SanitizesCsvOutput;
use App\Services\Reporting\ReportExporter;
use App\Support\CsvCell;
use PHPUnit\Framework\TestCase;

final class SanitizesCsvOutputTest extends TestCase
{
    public function test_csv_writer_preserves_output_and_emits_no_php_84_deprecation(): void
    {
        $writer = new class
        {
            use SanitizesCsvOutput {
                putCsv as public;
            }
        };
        $stream = fopen('php://temp', 'w+');
        $this->assertIsResource($stream);
        $deprecations = [];
        $csv = null;

        set_error_handler(static function (int $severity, string $message) use (&$deprecations): bool {
            if ($severity === E_DEPRECATED) {
                $deprecations[] = $message;

                return true;
            }

            return false;
        }, E_DEPRECATED);

        try {
            $writer->putCsv($stream, ['Smith, John', '=1+1']);
            rewind($stream);
            $csv = stream_get_contents($stream);
        } finally {
            restore_error_handler();
            fclose($stream);
        }

        $this->assertSame([], $deprecations);
        $this->assertSame("\"Smith, John\",'=1+1\n", $csv);
    }

    public function test_numeric_cells_and_formula_prefix_boundaries_are_preserved(): void
    {
        foreach (['-42.50', '+641234', '-4.25e1', '0', '', 'Ordinary text'] as $value) {
            $this->assertSame($value, CsvCell::sanitize($value));
            $this->assertSame($value, ReportExporter::csvCell($value));
        }
        foreach ([null, 0, -42.5, false] as $value) {
            $this->assertSame($value, CsvCell::sanitize($value));
        }
        $this->assertSame('-42.5', ReportExporter::csvCell(-42.5));
        $this->assertSame('0', ReportExporter::csvCell(0));
        $this->assertSame('', ReportExporter::csvCell(null));
        foreach ([
            '=2+3', '+SUM(1,1)', '-1+2', '@SUM(A1)', ' -42.5', '-42.5 ',
            " \v-42.5", " \f+641234", "\t-42.5", "\r-42.5", "\n-42.5",
            " \t=2+3", " \r+SUM(1,1)", " \n@SUM(A1)", " \v=2+3", " \f-1+2",
        ] as $value) {
            $this->assertSame("'".$value, CsvCell::sanitize($value));
            $this->assertSame("'".$value, ReportExporter::csvCell($value));
        }
    }
}
