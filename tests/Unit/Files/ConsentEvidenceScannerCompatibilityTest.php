<?php

namespace Tests\Unit\Files;

use App\Services\Consents\ConsentEvidenceMalwareScanner;
use App\Services\Files\MalwareScanDisposition;
use App\Services\Files\MalwareScanner;
use App\Services\Files\MalwareScanResult;
use Illuminate\Config\Repository;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConsentEvidenceScannerCompatibilityTest extends TestCase
{
    public static function dispositions(): array
    {
        return array_map(fn (MalwareScanDisposition $value): array => [$value], MalwareScanDisposition::cases());
    }

    #[DataProvider('dispositions')]
    public function test_keeps_the_existing_consent_config_and_exact_array_contract(MalwareScanDisposition $disposition): void
    {
        $settings = ['binary' => 'configured-existing-binary', 'name' => 'configured-engine', 'fd_pass' => true, 'timeout_seconds' => 17];
        $config = new Repository(['consent-evidence' => ['malware_scanner' => $settings]]);
        $file = $this->createMock(UploadedFile::class);
        $file->method('getRealPath')->willReturn('server-owned-input');
        $scanner = $this->createMock(MalwareScanner::class);
        $scanner->expects(self::once())->method('scanPath')->with('server-owned-input', $settings)
            ->willReturn(new MalwareScanResult($disposition, 'configured-engine', $disposition === MalwareScanDisposition::Unavailable ? 'scanner_timeout' : null));

        $legacy = new ConsentEvidenceMalwareScanner($scanner, $config);

        self::assertSame(['disposition' => $disposition->value, 'scanner' => 'configured-engine'], $legacy->scan($file));
    }

    public function test_missing_real_path_and_configuration_reach_the_fail_closed_neutral_contract(): void
    {
        $file = $this->createMock(UploadedFile::class);
        $file->method('getRealPath')->willReturn(false);
        $scanner = $this->createMock(MalwareScanner::class);
        $scanner->expects(self::once())->method('scanPath')->with('', [])
            ->willReturn(new MalwareScanResult(MalwareScanDisposition::Unavailable, 'clamav', 'scanner_unavailable'));

        self::assertSame(
            ['disposition' => 'unavailable', 'scanner' => 'clamav'],
            (new ConsentEvidenceMalwareScanner($scanner, new Repository))->scan($file),
        );
    }
}
