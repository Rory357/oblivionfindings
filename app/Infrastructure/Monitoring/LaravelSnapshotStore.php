<?php

namespace App\Infrastructure\Monitoring;

use App\Domain\Monitoring\Contracts\SnapshotStore;
use App\Domain\Monitoring\Exceptions\SnapshotStoreUnavailable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class LaravelSnapshotStore implements SnapshotStore
{
    public function put(string $path, string $contents): void
    {
        $this->assertPath($path);

        try {
            $encrypted = Crypt::encryptString($contents);
            $written = Storage::disk($this->disk())->put($path, $encrypted);
        } catch (Throwable $exception) {
            throw new SnapshotStoreUnavailable('Snapshot storage is unavailable.', previous: $exception);
        }

        if ($written !== true) {
            throw new SnapshotStoreUnavailable('Snapshot storage is unavailable.');
        }
    }

    public function read(string $path): string
    {
        $this->assertPath($path);

        try {
            $disk = Storage::disk($this->disk());
            if (! $disk->exists($path)) {
                throw new SnapshotStoreUnavailable('Snapshot storage is unavailable.');
            }

            return Crypt::decryptString($disk->get($path));
        } catch (SnapshotStoreUnavailable $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new SnapshotStoreUnavailable('Snapshot storage is unavailable.', previous: $exception);
        }
    }

    public function delete(string $path): void
    {
        $this->assertPath($path);

        try {
            $disk = Storage::disk($this->disk());
            if ($disk->exists($path) && ! $disk->delete($path)) {
                throw new SnapshotStoreUnavailable('Snapshot storage is unavailable.');
            }
        } catch (SnapshotStoreUnavailable $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new SnapshotStoreUnavailable('Snapshot storage is unavailable.', previous: $exception);
        }
    }

    public function exists(string $path): bool
    {
        $this->assertPath($path);

        try {
            return Storage::disk($this->disk())->exists($path);
        } catch (Throwable $exception) {
            throw new SnapshotStoreUnavailable('Snapshot storage is unavailable.', previous: $exception);
        }
    }

    private function disk(): string
    {
        $disk = (string) config('monitoring.storage.snapshots.disk', 'private');
        $configuration = config("filesystems.disks.{$disk}");

        if ($disk === '' || ! is_array($configuration)
            || ($configuration['visibility'] ?? null) === 'public'
            || ($configuration['serve'] ?? false) === true) {
            throw new SnapshotStoreUnavailable('Snapshot storage is not configured as private.');
        }

        return $disk;
    }

    private function assertPath(string $path): void
    {
        if (! str_starts_with($path, 'monitoring/configuration-snapshots/')
            || strlen($path) > 1024
            || str_contains($path, '..')
            || str_contains($path, '\\')
            || preg_match('/[\x00-\x1f\x7f]/', $path) === 1) {
            throw new SnapshotStoreUnavailable('Snapshot storage reference is invalid.');
        }
    }
}
