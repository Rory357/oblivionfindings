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

    /**
     * EA-141 (Stephan, 10 Oct): a recipient's personal backup passphrase.
     * Encrypts a one-page template with the passphrase as the user password
     * and a random owner password. Only the template and the owner password
     * are kept; with them, later backups get the same encryption parameters
     * (qpdf --copy-encryption), so the recipient opens their copy with their
     * own passphrase and the app isn't needed. The passphrase itself is never
     * stored.
     *
     * @return array{template: string, owner: string}
     */
    public function passphraseTemplate(string $passphrase): array
    {
        if (! $this->ready() || ! preg_match('/^[\x20-\x7E]{12,128}$/D', $passphrase)) {
            throw new RuntimeException('backup_encryption_unavailable');
        }
        $owner = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');

        return $this->inWorkspace(function (string $directory) use ($passphrase, $owner): array {
            $input = $directory.'/source.pdf';
            $output = $directory.'/protected.pdf';
            $this->writeFile($input, self::blankPdf());
            $this->run(['--encrypt', $passphrase, $owner, '256', '--', $input, $output]);
            // Both the passphrase and the kept owner password open it, as AES-256 (R6).
            $this->run(['--password='.$passphrase, '--check', $output]);
            $this->run(['--password='.$owner, '--check', $output]);
            $inspection = $this->run(['--password='.$owner, '--show-encryption', $output]);
            if (! str_contains($inspection, 'R = 6') || ! str_contains($inspection, 'AESv3')) {
                throw new RuntimeException('backup_encryption_failed');
            }
            $template = file_get_contents($output);
            if (! is_string($template) || ! str_starts_with($template, '%PDF-')) {
                throw new RuntimeException('backup_encryption_failed');
            }

            return ['template' => $template, 'owner' => $owner];
        });
    }

    /**
     * EA-141: re-encrypt a protected backup for one recipient: decrypted with
     * the backup's own password, written with the recipient's template
     * parameters (it then opens with their passphrase), checked, and returned.
     */
    public function encryptForRecipient(string $protected, string $password, string $template, string $owner): string
    {
        if (! $this->ready() || ! str_starts_with($protected, '%PDF-') || ! str_starts_with($template, '%PDF-')
            || strlen($protected) > (int) config('emar-catalogue-backups.maximum_pdf_bytes', 20971520)
            || ! preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $password) || ! preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $owner)) {
            throw new RuntimeException('backup_encryption_unavailable');
        }

        return $this->inWorkspace(function (string $directory) use ($protected, $password, $template, $owner): string {
            $input = $directory.'/source.pdf';
            $copy = $directory.'/template.pdf';
            $output = $directory.'/protected.pdf';
            $this->writeFile($input, $protected);
            $this->writeFile($copy, $template);
            $this->run(['--password='.$password, '--copy-encryption='.$copy, '--encryption-file-password='.$owner, '--', $input, $output]);
            $this->run(['--password='.$owner, '--check', $output]);
            $inspection = $this->run(['--password='.$owner, '--show-encryption', $output]);
            if (! str_contains($inspection, 'R = 6') || ! str_contains($inspection, 'AESv3')) {
                throw new RuntimeException('backup_encryption_failed');
            }
            $bytes = file_get_contents($output);
            if (! is_string($bytes) || ! str_starts_with($bytes, '%PDF-') || strlen($bytes) > (int) config('emar-catalogue-backups.maximum_pdf_bytes', 20971520)) {
                throw new RuntimeException('backup_encryption_failed');
            }

            return $bytes;
        });
    }

    /** A valid one-page PDF with an exact cross-reference table (qpdf exits non-zero on repairs). */
    public static function blankPdf(): string
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << >> >>',
        ];
        $pdf = "%PDF-1.7\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    /** The same leased, marker-owned workspace as encrypt(), for the passphrase paths. */
    private function inWorkspace(callable $work): mixed
    {
        $root = storage_path('app/private/emar-backup-temporary');
        if (! is_dir($root) && ! mkdir($root, 0700, true) && ! is_dir($root)) {
            throw new RuntimeException('backup_encryption_unavailable');
        }
        $name = (string) Str::uuid();
        $directory = $root.'/'.$name;
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('backup_encryption_unavailable');
        }
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

            return $work($directory);
        } finally {
            $removed = $this->removePdfFiles($directory);
            if (is_resource($lease)) {
                fclose($lease);
            }
            if ($removed && (! is_file($leasePath) || @unlink($leasePath))) {
                @rmdir($directory);
            }
        }
    }

    private function writeFile(string $path, string $bytes): void
    {
        if (file_put_contents($path, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('backup_encryption_failed');
        }
        chmod($path, 0600);
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
            if (! in_array($entry->getFilename(), ['source.pdf', 'protected.pdf', 'template.pdf', 'lease.json'], true) || ! $entry->isFile() || $entry->isLink()) {
                return false;
            }
        }

        return true;
    }

    private function removePdfFiles(string $directory): bool
    {
        foreach (['source.pdf', 'protected.pdf', 'template.pdf'] as $name) {
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
