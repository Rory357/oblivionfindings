<?php

namespace App\Services\Clients;

use App\Models\ClientPhoto;

final class ClientPhotoMediaUrls
{
    /** @return array{url: string, thumbnail_url: ?string} */
    public function portal(ClientPhoto $photo): array
    {
        return $this->forRoutes(
            $photo,
            'portal.clients.photos.media',
            'portal.clients.photos.thumbnail',
        );
    }

    /** @return array{url: string, thumbnail_url: ?string} */
    public function staff(ClientPhoto $photo): array
    {
        return $this->forRoutes(
            $photo,
            'operations.clients.gallery-photos.media',
            'operations.clients.gallery-photos.thumbnail',
        );
    }

    /**
     * @return array{url: string, thumbnail_url: ?string}
     */
    private function forRoutes(
        ClientPhoto $photo,
        string $mediaRoute,
        string $thumbnailRoute,
    ): array {
        $parameters = [
            'client' => $photo->client_id,
            'photo' => $photo->id,
        ];

        return [
            'url' => route($mediaRoute, $parameters, false),
            'thumbnail_url' => $photo->thumbnail_path
                ? route($thumbnailRoute, $parameters, false)
                : null,
        ];
    }
}
