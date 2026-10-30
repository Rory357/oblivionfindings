<?php

namespace Tests\Unit\Medication;

use App\Services\Medication\BackupDelivery\BackupPdfEncryption;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class BackupPdfEncryptionTest extends TestCase
{
    public function test_real_aes256_pdf_needs_password_and_retains_fictional_content(): void
    {
        $root = dirname(__DIR__, 3);
        $binary = getenv('EMAR_BACKUP_QPDF_PATH') ?: $root.'/storage/logs/tooling/qpdf-12.4.2/portable/qpdf-12.4.2-mingw64/bin/qpdf.exe';
        if (! is_file($binary)) {
            $this->markTestSkipped('A reviewed qpdf executable is required for the real encryption/openability test.');
        }
        $prior = Container::getInstance();
        $application = new Application($root);
        $temporary = sys_get_temp_dir().'/emar-pdf-unit-'.bin2hex(random_bytes(8));
        $application->useStoragePath($temporary);
        $application->instance('config', new Repository(['emar-catalogue-backups' => ['qpdf_path' => $binary, 'maximum_pdf_bytes' => 20971520]]));
        Container::setInstance($application);
        mkdir($temporary, 0700, true);
        $protected = $temporary.'/fictional.pdf';
        $decrypted = $temporary.'/decrypted.pdf';
        $password = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        try {
            $bytes = (new BackupPdfEncryption)->encrypt($this->pdf(), $password);
            file_put_contents($protected, $bytes);
            $this->assertStringNotContainsString('FICTIONAL_OPENABILITY_MARKER', $bytes);
            foreach ([[], ['--password=wrong-password']] as $arguments) {
                $process = $this->qpdf($binary, array_merge($arguments, ['--check', $protected]));
                $this->assertSame(2, $process->getExitCode(), 'An encrypted chart must reject no password and a wrong password.');
            }
            $inspection = $this->qpdf($binary, ['--password='.$password, '--show-encryption', $protected]);
            $this->assertSame(0, $inspection->getExitCode());
            $this->assertStringContainsString('R = 6', $inspection->getOutput());
            $this->assertStringContainsString('AESv3', $inspection->getOutput());
            $opened = $this->qpdf($binary, ['--password='.$password, '--decrypt', '--stream-data=uncompress', $protected, $decrypted]);
            $this->assertSame(0, $opened->getExitCode());
            $this->assertStringContainsString('FICTIONAL_OPENABILITY_MARKER', file_get_contents($decrypted));
            $pages = $this->qpdf($binary, ['--password='.$password, '--show-npages', $protected]);
            $this->assertSame('1', trim($pages->getOutput()));
        } finally {
            foreach ([$protected, $decrypted] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            foreach ([$temporary.'/app/private/emar-backup-temporary', $temporary.'/app/private', $temporary.'/app', $temporary] as $directory) {
                if (is_dir($directory)) {
                    rmdir($directory);
                }
            }
            Container::setInstance($prior);
        }
    }

    public function test_stale_owned_plaintext_and_encrypted_files_are_removed_without_touching_live_or_foreign_work(): void
    {
        $prior = Container::getInstance();
        $application = new Application(dirname(__DIR__, 3));
        $temporary = sys_get_temp_dir().'/emar-pdf-cleanup-'.bin2hex(random_bytes(8));
        $application->useStoragePath($temporary);
        Container::setInstance($application);
        $root = $temporary.'/app/private/emar-backup-temporary';
        mkdir($root, 0700, true);
        $names = ['stale' => '11111111-1111-4111-8111-111111111111', 'active' => '22222222-2222-4222-8222-222222222222',
            'recent' => '33333333-3333-4333-8333-333333333333', 'foreign' => '44444444-4444-4444-8444-444444444444',
            'unknown' => '55555555-5555-4555-8555-555555555555'];
        foreach ($names as $kind => $name) {
            $directory = $root.'/'.$name;
            mkdir($directory, 0700);
            file_put_contents($directory.'/source.pdf', '%PDF-fictional-plain');
            file_put_contents($directory.'/protected.pdf', '%PDF-fictional-encrypted');
            file_put_contents($directory.'/lease.json', json_encode(['format' => $kind === 'foreign' ? 'unrelated' : 'emar-backup-encryption-v1',
                'workspace' => $name, 'created_at' => $kind === 'recent' ? time() : time() - 7200, 'pid' => getmypid()]));
        }
        file_put_contents($root.'/'.$names['unknown'].'/unrelated.txt', 'Preserve unknown workspace content');
        file_put_contents($root.'/unrelated.txt', 'Preserve adjacent file');
        $active = fopen($root.'/'.$names['active'].'/lease.json', 'r+b');
        flock($active, LOCK_EX);
        try {
            $this->assertSame(1, (new BackupPdfEncryption)->cleanupStale());
            $this->assertDirectoryDoesNotExist($root.'/'.$names['stale']);
            foreach (['active', 'recent', 'foreign', 'unknown'] as $kind) {
                $this->assertFileExists($root.'/'.$names[$kind].'/source.pdf');
                $this->assertFileExists($root.'/'.$names[$kind].'/protected.pdf');
            }
            $this->assertFileExists($root.'/unrelated.txt');
            $this->assertFileExists($root.'/'.$names['unknown'].'/unrelated.txt');
            fclose($active);
            $active = null;
            $this->assertSame(1, (new BackupPdfEncryption)->cleanupStale());
            $this->assertDirectoryDoesNotExist($root.'/'.$names['active']);
            $this->assertSame(0, (new BackupPdfEncryption)->cleanupStale());
        } finally {
            if (is_resource($active)) {
                fclose($active);
            }
            foreach ($names as $name) {
                $directory = $root.'/'.$name;
                foreach (['source.pdf', 'protected.pdf', 'lease.json', 'unrelated.txt'] as $file) {
                    if (is_file($directory.'/'.$file)) {
                        unlink($directory.'/'.$file);
                    }
                }
                if (is_dir($directory)) {
                    rmdir($directory);
                }
            }
            unlink($root.'/unrelated.txt');
            foreach ([$root, $temporary.'/app/private', $temporary.'/app', $temporary] as $directory) {
                rmdir($directory);
            }
            Container::setInstance($prior);
        }
    }

    private function qpdf(string $binary, array $arguments): Process
    {
        $process = new Process([$binary, '@-']);
        $process->setInput(implode("\n", $arguments)."\n");
        $process->setTimeout(30);
        $process->run();

        return $process;
    }

    private function pdf(): string
    {
        $stream = "BT /F1 12 Tf 72 720 Td (FICTIONAL_OPENABILITY_MARKER) Tj ET\n";
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Count 1 /Kids [3 0 R] >>', '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', '<< /Length '.strlen($stream)." >>\nstream\n".$stream.'endstream'];
        $pdf = "%PDF-1.7\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }
}
