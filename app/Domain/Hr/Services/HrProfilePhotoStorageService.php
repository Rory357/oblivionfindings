<?php

namespace App\Domain\Hr\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class HrProfilePhotoStorageService
{
    public const PRIVATE_DISK = 'private';

    public const LEGACY_DISK = 'public';

    public const MOVED = 'moved';

    public const ALREADY_PRESENT = 'already_present';

    public const MISSING = 'missing';

    public const INVALID = 'invalid';

    public const CONFLICT = 'conflict';

    public const FAILED = 'failed';

    public const CLEANUP_FAILED = 'cleanup_failed';

    public function store(UploadedFile $photo, int $profileId): string|false
    {
        return $photo->store("hr/photos/{$profileId}", self::PRIVATE_DISK);
    }

    public function privateExists(mixed $path, int $profileId): bool
    {
        return $this->existsOn(self::PRIVATE_DISK, $path, $profileId);
    }

    public function readableDisk(mixed $path, int $profileId): ?string
    {
        if (! $this->isOwnedPath($path, $profileId)) {
            return null;
        }

        try {
            if (Storage::disk(self::PRIVATE_DISK)->exists($path)) {
                return self::PRIVATE_DISK;
            }

            // Compatibility is read-only: old objects remain available only
            // through the authorised controller until the migration command
            // has moved them off the public disk.
            if (Storage::disk(self::LEGACY_DISK)->exists($path)) {
                return self::LEGACY_DISK;
            }
        } catch (Throwable) {
            // Fail closed if storage availability cannot be established.
        }

        return null;
    }

    public function response(mixed $path, int $profileId, array $headers = []): ?StreamedResponse
    {
        $disk = $this->readableDisk($path, $profileId);
        if ($disk === null) {
            return null;
        }

        try {
            return Storage::disk($disk)->response($path, null, $headers);
        } catch (Throwable) {
            return null;
        }
    }

    /** Delete a newly written private object during database rollback. */
    public function deletePrivate(mixed $path, int $profileId): void
    {
        $this->deleteFrom(self::PRIVATE_DISK, $path, $profileId);
    }

    /** Delete every owned copy after a replacement has committed. */
    public function deleteEverywhere(mixed $path, int $profileId): void
    {
        if (! $this->isOwnedPath($path, $profileId)) {
            return;
        }

        foreach ([self::PRIVATE_DISK, self::LEGACY_DISK] as $disk) {
            $this->deleteFrom($disk, $path, $profileId);
        }
    }

    public function migrateToPrivate(mixed $path, int $profileId): string
    {
        return $this->moveVerified(
            $path,
            $profileId,
            self::LEGACY_DISK,
            self::PRIVATE_DISK,
        );
    }

    public function rollbackToPublic(mixed $path, int $profileId): string
    {
        return $this->moveVerified(
            $path,
            $profileId,
            self::PRIVATE_DISK,
            self::LEGACY_DISK,
        );
    }

    /**
     * Count every object still reachable through /storage/hr/photos.
     *
     * Null means the public disk could not be inspected and callers must fail
     * closed. Paths are deliberately not returned because unreferenced objects
     * may still contain identifying filenames.
     */
    public function publicResidueCount(): ?int
    {
        try {
            return count(Storage::disk(self::LEGACY_DISK)->allFiles('hr/photos'));
        } catch (Throwable) {
            return null;
        }
    }

    public function isOwnedPath(mixed $path, int $profileId): bool
    {
        return is_string($path)
            && preg_match(
                '~\Ahr/photos/'.preg_quote((string) $profileId, '~').'/[A-Za-z0-9][A-Za-z0-9._-]*\z~D',
                $path,
            ) === 1;
    }

    private function existsOn(string $disk, mixed $path, int $profileId): bool
    {
        if (! $this->isOwnedPath($path, $profileId)) {
            return false;
        }

        try {
            return Storage::disk($disk)->exists($path);
        } catch (Throwable) {
            return false;
        }
    }

    private function deleteFrom(string $diskName, mixed $path, int $profileId): void
    {
        if (! $this->isOwnedPath($path, $profileId)) {
            return;
        }

        $disk = Storage::disk($diskName);
        if (! $disk->exists($path)) {
            return;
        }

        if (! $disk->delete($path) || $disk->exists($path)) {
            throw new RuntimeException('An HR profile photo object could not be removed.');
        }
    }

    private function moveVerified(
        mixed $path,
        int $profileId,
        string $sourceName,
        string $destinationName,
    ): string {
        if (! $this->isOwnedPath($path, $profileId)) {
            return self::INVALID;
        }

        try {
            $source = Storage::disk($sourceName);
            $destination = Storage::disk($destinationName);
            $sourceExists = $source->exists($path);
            $destinationExists = $destination->exists($path);

            if ($destinationExists) {
                if (! $sourceExists) {
                    return self::ALREADY_PRESENT;
                }
                if (! $this->objectsMatch($sourceName, $destinationName, $path)) {
                    return self::CONFLICT;
                }

                return $this->deleteVerified($sourceName, $path)
                    ? self::MOVED
                    : self::CLEANUP_FAILED;
            }

            if (! $sourceExists) {
                return self::MISSING;
            }

            $stream = $source->readStream($path);
            if (! is_resource($stream)) {
                return self::FAILED;
            }

            try {
                if (! $destination->writeStream($path, $stream)) {
                    $this->deleteVerified($destinationName, $path);

                    return self::FAILED;
                }
            } finally {
                fclose($stream);
            }

            if (! $destination->exists($path)
                || ! $this->objectsMatch($sourceName, $destinationName, $path)) {
                $this->deleteVerified($destinationName, $path);

                return self::FAILED;
            }

            return $this->deleteVerified($sourceName, $path)
                ? self::MOVED
                : self::CLEANUP_FAILED;
        } catch (Throwable) {
            return self::FAILED;
        }
    }

    private function objectsMatch(string $firstDisk, string $secondDisk, string $path): bool
    {
        return hash_equals(
            hash('sha256', Storage::disk($firstDisk)->get($path)),
            hash('sha256', Storage::disk($secondDisk)->get($path)),
        );
    }

    private function deleteVerified(string $diskName, string $path): bool
    {
        $disk = Storage::disk($diskName);

        return (! $disk->exists($path) || $disk->delete($path))
            && ! $disk->exists($path);
    }
}
