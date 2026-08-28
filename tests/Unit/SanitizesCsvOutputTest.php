<?php

namespace Tests\Unit;

use App\Http\Controllers\Concerns\SanitizesCsvOutput;
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
}
