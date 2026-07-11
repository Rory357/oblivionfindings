<?php

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\ClientPhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class ClientPhotoStorage
{
    public const DISK = 'local';

    /** @var list<string> */
    public const SAFE_IMAGE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    /**
     * @return array{storage_disk: string, storage_path: string, thumbnail_path: ?string}
     */
    public function store(UploadedFile $file, Client $client): array
    {
        $directory = "client-photos/{$client->id}";
        $storagePath = $file->store($directory, self::DISK);

        return [
            'storage_disk' => self::DISK,
            'storage_path' => $storagePath,
            'thumbnail_path' => $this->createThumbnail($file, $client, $storagePath),
        ];
    }

    public function delete(ClientPhoto $photo): void
    {
        $disk = Storage::disk($photo->storage_disk ?: 'public');
        $disk->delete($photo->storage_path);

        if ($photo->thumbnail_path) {
            $disk->delete($photo->thumbnail_path);
        }
    }

    private function createThumbnail(
        UploadedFile $file,
        Client $client,
        string $storagePath,
    ): ?string {
        try {
            $data = file_get_contents($file->getRealPath());
            $source = $data === false ? false : @imagecreatefromstring($data);
            if (! $source) {
                return null;
            }

            $width = imagesx($source);
            $height = imagesy($source);
            $maximum = 400;
            $ratio = min(
                $maximum / max($width, 1),
                $maximum / max($height, 1),
                1,
            );
            $thumbnailWidth = max(1, (int) round($width * $ratio));
            $thumbnailHeight = max(1, (int) round($height * $ratio));
            $thumbnail = imagecreatetruecolor($thumbnailWidth, $thumbnailHeight);
            $white = imagecolorallocate($thumbnail, 255, 255, 255);
            imagefilledrectangle(
                $thumbnail,
                0,
                0,
                $thumbnailWidth,
                $thumbnailHeight,
                $white,
            );
            imagecopyresampled(
                $thumbnail,
                $source,
                0,
                0,
                0,
                0,
                $thumbnailWidth,
                $thumbnailHeight,
                $width,
                $height,
            );

            $thumbnailDirectory = "client-photos/{$client->id}/thumbs";
            $thumbnailPath = $thumbnailDirectory.'/'.pathinfo(
                $storagePath,
                PATHINFO_FILENAME,
            ).'_thumb.jpg';
            $disk = Storage::disk(self::DISK);
            $disk->makeDirectory($thumbnailDirectory);
            $saved = imagejpeg(
                $thumbnail,
                $disk->path($thumbnailPath),
                85,
            );

            imagedestroy($source);
            imagedestroy($thumbnail);

            return $saved ? $thumbnailPath : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
