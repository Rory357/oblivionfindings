<?php

namespace App\Services\Medication\BackupDelivery;

use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/** qpdf revision-6 AES-256. Passwords travel over stdin, never process arguments/logs. */
class BackupPdfEncryption
{
    private const LEASE_FORMAT = 'emar-backup-encryption-v1';

    private const STALE_SECONDS = 3600;

    public function ready(): bool
    {
        $path = config('emar-catalogue-backups.qpdf_path');

        return is_string($path) && preg_match('~^(?:[A-Za-z]:[/\\\\]|/)~', $path) && is_file($path) && is_executable($path);
    }

    public function encrypt(string $pdf, string $password): string
    {
        if (! $this->ready() || ! str_starts_with($pdf, '%PDF-') || strlen($pdf) > (int) config('emar-catalogue-backups.maximum_pdf_bytes', 20971520)
            || ! preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $password)) {
            throw new RuntimeException('backup_encryption_unavailable');
        }
        $ownerPassword = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $root = storage_path('app/private/emar-backup-temporary');
        if (! is_dir($root) && ! mkdir($root, 0700, true) && ! is_dir($root)) {
            throw new RuntimeException('backup_encryption_unavailable');
        }
        $name = (string) Str::uuid();
        $directory = $root.'/'.$name;
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('backup_encryption_unavailable');
        }
        $input = $directory.'/source.pdf';
        $output = $directory.'/protected.pdf';
        $leasePath = $directory.'/lease.json';
        $lease = null;
        try {
            $lease = fopen($leasePath, 'x+b');
            if (! is_resource($lease) || ! flock($lease, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('backup_encryption_unavailable');
            }
            $marker = json_encode(['format' => self::LEASE_FORMAT, 'workspace' => $name, 'created_at' => time(), 'pid' => getmypid()], JSON_THROW_ON_ERROR);
            if (fwrite($lease, $marker) !== strlen($marker) || ! fflush($lease)) {
                throw new RuntimeException('backup_encryption_unavailable');
            }
            chmod($leasePath, 0600);
            if (file_put_contents($input, $pdf) !== strlen($pdf)) {
                throw new RuntimeException('backup_encryption_failed');
            }
            chmod($input, 0600);
            $this->run(['--encrypt', $password, $ownerPassword, '256', '--', $input, $output]);
            $this->run(['--password='.$password, '--check', $output]);
            $inspection = $this->run(['--password='.$password, '--show-encryption', $output]);
            if (! str_contains($inspection, 'R = 6') || ! str_contains($inspection, 'AESv3')) {
                throw new RuntimeException('backup_encryption_failed');
            }
            $bytes = file_get_contents($output);
            if (! is_string($bytes) || ! str_starts_with($bytes, '%PDF-') || strlen($bytes) > (int) config('emar-catalogue-backups.maximum_pdf_bytes', 20971520)) {
                throw new RuntimeException('backup_encryption_failed');
            }

            return $bytes;
        } finally {
            $removed = $this->removePdfFiles($directory);
            if (is_resource($lease)) {
                fclose($lease);
            }
            // Failed filesystem cleanup retains the ownership lease for scheduled recovery.
            if ($removed && (! is_file($leasePath) || @unlink($leasePath))) {
                @rmdir($directory);
            }
        }
    }

    /** No recursion: recover only stale, marker-owned UUID workspaces with no active lease. */
    public function cleanupStale(): int
    {
        $root = storage_path('app/private/emar-backup-temporary');
        if (! is_dir($root) || is_link($root)) {
            return 0;
        }
        $resolvedRoot = realpath($root);
        $count = 0;
        foreach (new \DirectoryIterator($root) as $entry) {
            $name = $entry->getFilename();
            if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $name)
                || ! $entry->isDir() || $entry->isLink()) {
                continue;
            }
            $directory = $entry->getPathname();
            if (realpath(dirname($directory)) !== $resolvedRoot || ! $this->knownWorkspace($directory)) {
                continue;
            }
            $leasePath = $directory.'/lease.json';
            if (filesize($leasePath) > 4096) {
                continue;
            }
            $lease = @fopen($leasePath, 'r+b');
            if (! is_resource($lease)) {
                continue;
            }
            $removed = false;
            try {
                if (! flock($lease, LOCK_EX | LOCK_NB)) {
                    continue; // Live encryption retains this lock, even beyond the age threshold.
                }
                $marker = json_decode(stream_get_contents($lease, 4096), true);
                if (! is_array($marker) || array_diff(array_keys($marker), ['format', 'workspace', 'created_at', 'pid']) !== [] || ($marker['format'] ?? null) !== self::LEASE_FORMAT || ($marker['workspace'] ?? null) !== $name
                    || ! is_int($marker['created_at'] ?? null) || $marker['created_at'] < 1 || $marker['created_at'] > time() - self::STALE_SECONDS
                    || ! is_int($marker['pid'] ?? null) || $marker['pid'] < 1) {
                    continue;
                }
                $removed = $this->removePdfFiles($directory);
            } finally {
                fclose($lease);
            }
            if ($removed && @unlink($leasePath) && @rmdir($directory)) {
                $count++;
            }
        }

        return $count;
    }

    private function knownWorkspace(string $directory): bool
    {
        if (! is_file($directory.'/lease.json') || is_link($directory.'/lease.json')) {
            return false;
        }
        foreach (new \DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            if (! in_array($entry->getFilename(), ['source.pdf', 'protected.pdf', 'lease.json'], true) || ! $entry->isFile() || $entry->isLink()) {
                return false;
            }
        }

        return true;
    }

    private function removePdfFiles(string $directory): bool
    {
        foreach (['source.pdf', 'protected.pdf'] as $name) {
            $file = $directory.'/'.$name;
            if (is_file($file) && ! @unlink($file)) {
                return false;
            }
        }

        return true;
    }

    private function run(array $arguments): string
    {
        $process = new Process([config('emar-catalogue-backups.qpdf_path'), '@-']);
        $process->setInput(implode("\n", $arguments)."\n");
        $process->setTimeout(60);
        try {
            $process->run();
            if ($process->getExitCode() !== 0) {
                throw new RuntimeException('backup_encryption_failed');
            }

            return $process->getOutput();
        } catch (\Throwable) {
            // qpdf stderr/command strings can carry passwords or clinical file names.
            throw new RuntimeException('backup_encryption_failed');
        }
    }
}
