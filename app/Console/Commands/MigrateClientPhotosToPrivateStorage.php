<?php

namespace App\Console\Commands;

use App\Models\ClientPhoto;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MigrateClientPhotosToPrivateStorage extends Command
{
    protected $signature = 'client-photos:migrate-private
        {--dry-run : Inspect and report without copying, updating, or deleting}
        {--chunk=200 : Number of database rows processed at a time}';

    protected $description = 'Copy and verify legacy public ClientPhoto blobs before switching their rows to private local storage.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk'));
        $counts = [
            'migrated' => 0,
            'cleaned' => 0,
            'already_private' => 0,
            'would_migrate' => 0,
            'would_clean' => 0,
            'failed' => 0,
        ];

        ClientPhoto::query()
            ->orderBy('id')
            ->chunkById($chunkSize, function ($photos) use ($dryRun, &$counts): void {
                foreach ($photos as $photo) {
                    if (($photo->storage_disk ?: 'public') === 'public') {
                        $this->migratePublicPhoto($photo, $dryRun, $counts);

                        continue;
                    }

                    if (($photo->storage_disk ?: 'public') === 'local') {
                        $this->cleanVerifiedPublicCopies($photo, $dryRun, $counts);

                        continue;
                    }

                    $this->error(
                        "Photo #{$photo->id}: unsupported storage disk [{$photo->storage_disk}].",
                    );
                    $counts['failed']++;
                }
            });

        $this->table(['Result', 'Count'], collect($counts)
            ->map(fn (int $count, string $label) => [$label, $count])
            ->values()
            ->all());

        if ($counts['failed'] > 0) {
            $this->error('Client photo media migration is incomplete; public source metadata was retained for every failed row.');

            return self::FAILURE;
        }

        $this->info($dryRun
            ? 'Dry run complete; no files or rows were changed.'
            : 'Client photo media migration is reconciled.');

        return self::SUCCESS;
    }

    /** @param array<string, int> $counts */
    private function migratePublicPhoto(
        ClientPhoto $photo,
        bool $dryRun,
        array &$counts,
    ): void {
        $public = Storage::disk('public');
        $local = Storage::disk('local');
        $paths = $this->paths($photo);

        foreach ($paths as $path) {
            if (! $public->exists($path)) {
                $this->error("Photo #{$photo->id}: public source is missing [{$path}].");
                $counts['failed']++;

                return;
            }
        }

        if ($dryRun) {
            $this->line("Photo #{$photo->id}: would copy and verify ".count($paths).' blob(s), update storage_disk, then remove public copies.');
            $counts['would_migrate']++;

            return;
        }

        foreach ($paths as $path) {
            if (! $this->copyAndVerify($public, $local, $path)) {
                $this->error("Photo #{$photo->id}: destination verification failed [{$path}].");
                $counts['failed']++;

                return;
            }
        }

        DB::transaction(function () use ($photo): void {
            $locked = ClientPhoto::query()->lockForUpdate()->findOrFail($photo->id);
            if (($locked->storage_disk ?: 'public') === 'public') {
                $locked->forceFill(['storage_disk' => 'local'])->save();
            }
        });

        foreach ($paths as $path) {
            if ($public->exists($path) && ! $public->delete($path)) {
                $this->error("Photo #{$photo->id}: verified private copy is active, but public cleanup failed [{$path}]. Re-run the command.");
                $counts['failed']++;

                return;
            }
        }

        $counts['migrated']++;
    }

    /** @param array<string, int> $counts */
    private function cleanVerifiedPublicCopies(
        ClientPhoto $photo,
        bool $dryRun,
        array &$counts,
    ): void {
        $public = Storage::disk('public');
        $local = Storage::disk('local');
        $publicPaths = collect($this->paths($photo))
            ->filter(fn (string $path) => $public->exists($path))
            ->values();

        if ($publicPaths->isEmpty()) {
            $counts['already_private']++;

            return;
        }

        foreach ($publicPaths as $path) {
            if (
                ! $local->exists($path)
                || $this->checksum($public, $path) !== $this->checksum($local, $path)
            ) {
                $this->error("Photo #{$photo->id}: public cleanup refused because the private copy is missing or differs [{$path}].");
                $counts['failed']++;

                return;
            }
        }

        if ($dryRun) {
            $this->line("Photo #{$photo->id}: would remove ".$publicPaths->count().' verified residual public blob(s).');
            $counts['would_clean']++;

            return;
        }

        foreach ($publicPaths as $path) {
            if (! $public->delete($path)) {
                $this->error("Photo #{$photo->id}: residual public cleanup failed [{$path}].");
                $counts['failed']++;

                return;
            }
        }

        $counts['cleaned']++;
    }

    private function copyAndVerify(
        FilesystemAdapter $source,
        FilesystemAdapter $destination,
        string $path,
    ): bool {
        $sourceChecksum = $this->checksum($source, $path);
        if ($sourceChecksum === null) {
            return false;
        }

        if ($destination->exists($path)) {
            return hash_equals(
                $sourceChecksum,
                (string) $this->checksum($destination, $path),
            );
        }

        $stream = $source->readStream($path);
        if (! is_resource($stream)) {
            return false;
        }

        try {
            if (! $destination->writeStream($path, $stream)) {
                return false;
            }
        } finally {
            fclose($stream);
        }

        $destinationChecksum = $this->checksum($destination, $path);

        return $destinationChecksum !== null
            && hash_equals($sourceChecksum, $destinationChecksum);
    }

    private function checksum(
        FilesystemAdapter $disk,
        string $path,
    ): ?string {
        $stream = $disk->readStream($path);
        if (! is_resource($stream)) {
            return null;
        }

        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);

            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }

    /** @return list<string> */
    private function paths(ClientPhoto $photo): array
    {
        return array_values(array_filter([
            $photo->storage_path,
            $photo->thumbnail_path,
        ], fn (?string $path) => filled($path)));
    }
}
