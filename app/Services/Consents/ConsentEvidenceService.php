<?php

namespace App\Services\Consents;

use App\Models\ClientConsent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ConsentEvidenceService
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    private const DISK = 'private';

    private const PREFIX = 'consent-evidence/';

    private const OPAQUE_PATH_PATTERN = '/\Aconsent-evidence\/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.(?:pdf|jpg|png)\z/D';

    public function __construct(
        private readonly ConsentEvidenceMalwareScanner $scanner,
    ) {}

    /**
     * Validate, scan, and stage one evidence object before its consent record
     * is written. The returned keys map directly to ClientConsent columns.
     *
     * @return array<string, mixed>
     */
    public function prepare(UploadedFile $file, int $actorId): array
    {
        $realPath = $file->getRealPath();
        $size = $file->getSize();
        if (! $file->isValid()
            || ! is_string($realPath)
            || $realPath === ''
            || ! is_file($realPath)
            || ! is_int($size)
            || $size < 1
            || $size > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                'signed_document' => 'Upload a signed consent document no larger than 10 MB.',
            ]);
        }

        try {
            $mime = strtolower((string) $file->getMimeType());
        } catch (Throwable) {
            $mime = '';
        }
        $extension = match ($mime) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => null,
        };

        if ($extension === null) {
            throw ValidationException::withMessages([
                'signed_document' => 'Upload a PDF, JPEG, or PNG signed consent document.',
            ]);
        }

        $preScanSha256 = hash_file('sha256', $realPath);
        if (! is_string($preScanSha256) || strlen($preScanSha256) !== 64) {
            throw ValidationException::withMessages([
                'signed_document' => 'The signed document could not be verified.',
            ]);
        }

        $scan = $this->scanner->scan($file);
        if (($scan['disposition'] ?? null) === 'infected') {
            throw ValidationException::withMessages([
                'signed_document' => 'The signed document failed the malware safety check.',
            ]);
        }
        if (($scan['disposition'] ?? null) !== 'clean') {
            throw ValidationException::withMessages([
                'signed_document' => 'Signed document scanning is temporarily unavailable. Try again later.',
            ]);
        }

        if (! $this->contentMatchesMime($realPath, $mime)) {
            throw ValidationException::withMessages([
                'signed_document' => 'The signed document content does not match an allowed PDF, JPEG, or PNG file.',
            ]);
        }

        $sha256 = hash_file('sha256', $realPath);
        if (! is_string($sha256)
            || strlen($sha256) !== 64
            || ! hash_equals($preScanSha256, $sha256)) {
            throw ValidationException::withMessages([
                'signed_document' => 'The signed document changed during verification. Upload it again.',
            ]);
        }

        $storedName = Str::uuid().'.'.$extension;
        $storedPath = $file->storeAs(rtrim(self::PREFIX, '/'), $storedName, self::DISK);
        $expectedPath = self::PREFIX.$storedName;

        if (! is_string($storedPath) || ! hash_equals($expectedPath, $storedPath)) {
            if (is_string($storedPath) && self::isOpaquePath($storedPath)) {
                $this->deleteOrFail($storedPath);
            }

            throw new RuntimeException('Consent evidence could not be stored on the private disk.');
        }

        $storedFingerprint = $this->storedFingerprint($storedPath);
        if ($storedFingerprint === null
            || $storedFingerprint['size'] !== $size
            || ! hash_equals($sha256, $storedFingerprint['sha256'])) {
            $this->deleteOrFail($storedPath);

            throw new RuntimeException('Stored consent evidence failed its integrity check.');
        }

        $originalName = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $originalName = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $originalName));
        $originalName = Str::limit($originalName !== '' ? $originalName : 'signed-consent.'.$extension, 255, '');
        $scanner = trim((string) preg_replace(
            '/[\x00-\x1F\x7F]/u',
            '',
            (string) ($scan['scanner'] ?? 'unknown'),
        ));

        return [
            'signed_document_path' => $storedPath,
            'signed_document_disk' => self::DISK,
            'signed_document_original_name' => $originalName,
            'signed_document_mime_type' => $mime,
            'signed_document_size_bytes' => $size,
            'signed_document_sha256' => $sha256,
            'signed_document_malware_disposition' => 'clean',
            'signed_document_scanner' => Str::limit($scanner !== '' ? $scanner : 'unknown', 100, ''),
            'signed_document_scanned_at' => now(),
            'signed_document_uploaded_by_user_id' => $actorId,
        ];
    }

    /** @param array<string, mixed> $payload */
    public function commandDigest(array $payload): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($payload),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * Return a rewound stream containing the exact bytes whose size and digest
     * match the immutable evidence metadata. The response streams this same
     * handle, avoiding a verify-then-reopen race.
     *
     * @return resource|null
     */
    public function openVerifiedStream(ClientConsent $consent)
    {
        if (! $consent->hasDownloadableSignedDocument()) {
            return null;
        }

        $path = (string) $consent->signed_document_path;
        if (! self::isOpaquePath($path)) {
            return null;
        }

        $source = null;
        $verified = null;
        try {
            $source = Storage::disk(self::DISK)->readStream($path);
            if (! is_resource($source)) {
                return null;
            }

            $verified = fopen('php://temp/maxmemory:'.self::MAX_BYTES, 'w+b');
            if (! is_resource($verified)) {
                return null;
            }

            $hash = hash_init('sha256');
            $size = 0;
            while (! feof($source)) {
                $chunk = fread($source, 8192);
                if (! is_string($chunk) || ($chunk === '' && ! feof($source))) {
                    return null;
                }
                if ($chunk === '') {
                    continue;
                }

                $size += strlen($chunk);
                if ($size > self::MAX_BYTES
                    || fwrite($verified, $chunk) !== strlen($chunk)) {
                    return null;
                }
                hash_update($hash, $chunk);
            }

            $sha256 = hash_final($hash);
            if ($size !== (int) $consent->signed_document_size_bytes
                || ! hash_equals((string) $consent->signed_document_sha256, $sha256)
                || ! rewind($verified)) {
                return null;
            }

            $result = $verified;
            $verified = null;

            return $result;
        } catch (Throwable) {
            return null;
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($verified)) {
                fclose($verified);
            }
        }
    }

    /** @param array<string, mixed> $prepared */
    public function discard(array $prepared): void
    {
        $path = $prepared['signed_document_path'] ?? null;
        $disk = $prepared['signed_document_disk'] ?? null;

        if ($disk !== self::DISK || ! is_string($path) || ! self::isOpaquePath($path)) {
            return;
        }

        $this->deleteOrFail($path);
    }

    private function contentMatchesMime(string $path, string $mime): bool
    {
        if ($mime === 'application/pdf') {
            $handle = @fopen($path, 'rb');
            if (! is_resource($handle)) {
                return false;
            }

            try {
                $header = fread($handle, 5);
                $size = filesize($path);
                if (! is_string($header) || $header !== '%PDF-' || ! is_int($size)) {
                    return false;
                }

                if (fseek($handle, max(0, $size - 1024)) !== 0) {
                    return false;
                }
                $tail = stream_get_contents($handle);

                return is_string($tail) && str_contains($tail, '%%EOF');
            } finally {
                fclose($handle);
            }
        }

        $image = @getimagesize($path);

        return is_array($image)
            && ($image['mime'] ?? null) === $mime
            && in_array($mime, ['image/jpeg', 'image/png'], true);
    }

    /** @return array{size: int, sha256: string}|null */
    private function storedFingerprint(string $path): ?array
    {
        $stream = null;
        try {
            $stream = Storage::disk(self::DISK)->readStream($path);
            if (! is_resource($stream)) {
                return null;
            }

            $hash = hash_init('sha256');
            $size = 0;
            while (! feof($stream)) {
                $chunk = fread($stream, 8192);
                if (! is_string($chunk) || ($chunk === '' && ! feof($stream))) {
                    return null;
                }
                if ($chunk === '') {
                    continue;
                }

                $size += strlen($chunk);
                if ($size > self::MAX_BYTES) {
                    return null;
                }
                hash_update($hash, $chunk);
            }

            return ['size' => $size, 'sha256' => hash_final($hash)];
        } catch (Throwable) {
            return null;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public static function isOpaquePath(string $path): bool
    {
        return preg_match(self::OPAQUE_PATH_PATTERN, $path) === 1;
    }

    private function deleteOrFail(string $path): void
    {
        $storage = Storage::disk(self::DISK);
        if ($storage->exists($path) && ! $storage->delete($path)) {
            throw new RuntimeException('Prepared consent evidence could not be removed after rollback.');
        }

        if ($storage->exists($path)) {
            throw new RuntimeException('Prepared consent evidence could not be removed after rollback.');
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
