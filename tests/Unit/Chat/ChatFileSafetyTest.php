<?php

namespace Tests\Unit\Chat;

use App\Services\Chat\ChatFileSafety;
use App\Services\Files\MalwareScanDisposition;
use App\Services\Files\MalwareScanner;
use App\Services\Files\MalwareScanResult;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChatFileSafetyTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = tempnam(sys_get_temp_dir(), 'chat-scan-');
        file_put_contents($this->path, 'Fictional file contents');
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
        parent::tearDown();
    }

    public function test_accepts_only_the_exact_scanned_bytes_and_rejects_a_later_replacement(): void
    {
        $settings = ['binary' => '/configured/scanner', 'timeout_seconds' => 12];
        $scanner = $this->createMock(MalwareScanner::class);
        $scanner->expects(self::once())->method('scanPath')->with($this->path, $settings)
            ->willReturn(new MalwareScanResult(MalwareScanDisposition::Clean, 'configured-engine'));
        $verdict = (new ChatFileSafety($scanner, new Repository(['chat' => ['files' => ['scanner' => $settings]]])))->inspect($this->path);
        self::assertSame('clean', $verdict->state);
        self::assertSame(hash_file('sha256', $this->path), $verdict->sha256);
        self::assertTrue($verdict->verifies($this->path));
        file_put_contents($this->path, 'Changed after scanning');
        self::assertFalse($verdict->verifies($this->path));
        self::assertFalse($verdict->verifies($this->path.'.missing'));
    }

    public static function deniedVerdicts(): array
    {
        return [[MalwareScanDisposition::Infected, 'blocked'], [MalwareScanDisposition::Unavailable, 'unavailable']];
    }

    #[DataProvider('deniedVerdicts')]
    public function test_never_treats_blocked_or_unavailable_scans_as_clean(MalwareScanDisposition $disposition, string $state): void
    {
        $scanner = $this->createMock(MalwareScanner::class);
        $scanner->method('scanPath')->willReturn(new MalwareScanResult($disposition, 'configured-engine'));
        $verdict = (new ChatFileSafety($scanner, new Repository))->inspect($this->path);
        self::assertSame($state, $verdict->state);
        self::assertNull($verdict->sha256);
        self::assertFalse($verdict->verifies($this->path));
    }

    public function test_rejects_bytes_changed_during_a_clean_scan(): void
    {
        $scanner = $this->createMock(MalwareScanner::class);
        $scanner->method('scanPath')->willReturnCallback(function () {
            file_put_contents($this->path, 'Replaced during scan');

            return new MalwareScanResult(MalwareScanDisposition::Clean, 'configured-engine');
        });
        $verdict = (new ChatFileSafety($scanner, new Repository))->inspect($this->path);
        self::assertSame('changed', $verdict->state);
        self::assertNull($verdict->sha256);
        self::assertFalse($verdict->verifies($this->path));
    }

    public function test_scanner_failure_does_not_expose_process_details(): void
    {
        $scanner = $this->createMock(MalwareScanner::class);
        $scanner->method('scanPath')->willThrowException(new \RuntimeException('Private scanner path and output'));
        $verdict = (new ChatFileSafety($scanner, new Repository))->inspect($this->path);
        self::assertSame('unavailable', $verdict->state);
        self::assertSame('scanner_failed', $verdict->errorCode);
        self::assertNull($verdict->sha256);
    }

    public function test_unconfigured_scanner_and_missing_file_fail_closed(): void
    {
        $safety = new ChatFileSafety(new MalwareScanner, new Repository);
        self::assertSame('unavailable', $safety->inspect($this->path)->state);
        self::assertSame('file_unavailable', $safety->inspect($this->path.'.missing')->errorCode);
    }
}
