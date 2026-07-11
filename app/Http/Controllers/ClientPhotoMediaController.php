<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientPhoto;
use App\Services\Clients\ClientPhotoStorage;
use App\Services\Clients\ClientProfileSectionAccess;
use App\Services\Portal\PortalClientSectionAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientPhotoMediaController extends Controller
{
    public function portalMedia(
        Request $request,
        Client $client,
        ClientPhoto $photo,
    ): StreamedResponse {
        return $this->servePortal($request, $client, $photo, false);
    }

    public function portalThumbnail(
        Request $request,
        Client $client,
        ClientPhoto $photo,
    ): StreamedResponse {
        return $this->servePortal($request, $client, $photo, true);
    }

    public function staffMedia(
        Request $request,
        Client $client,
        ClientPhoto $photo,
    ): StreamedResponse {
        return $this->serveStaff($request, $client, $photo, false);
    }

    public function staffThumbnail(
        Request $request,
        Client $client,
        ClientPhoto $photo,
    ): StreamedResponse {
        return $this->serveStaff($request, $client, $photo, true);
    }

    private function servePortal(
        Request $request,
        Client $client,
        ClientPhoto $photo,
        bool $thumbnail,
    ): StreamedResponse {
        $user = $request->user();
        abort_unless($user && $user->canAccessClientPortal($client), 403);
        $this->assertBound($client, $photo);

        $access = app(PortalClientSectionAccess::class)->for($user, $client);
        abort_unless(
            $photo->status === 'approved'
                && in_array(
                    $photo->visibility,
                    ['family', 'all_portal_users'],
                    true,
                )
                && (
                    $access['has_family_information_consent']
                    || (int) $photo->uploaded_by_user_id === (int) $user->id
                ),
            403,
        );

        return $this->response($photo, $thumbnail);
    }

    private function serveStaff(
        Request $request,
        Client $client,
        ClientPhoto $photo,
        bool $thumbnail,
    ): StreamedResponse {
        $this->authorize('view', $client);
        $this->assertBound($client, $photo);
        $user = $request->user();
        abort_unless(
            $user
                && app(ClientProfileSectionAccess::class)
                    ->for($user, $client)['photos'],
            403,
        );

        return $this->response($photo, $thumbnail);
    }

    private function assertBound(Client $client, ClientPhoto $photo): void
    {
        abort_unless((int) $photo->client_id === (int) $client->id, 404);
    }

    private function response(
        ClientPhoto $photo,
        bool $thumbnail,
    ): StreamedResponse {
        $path = $thumbnail ? $photo->thumbnail_path : $photo->storage_path;
        abort_unless($path, 404);

        $disk = Storage::disk($photo->storage_disk ?: 'public');
        abort_unless($disk->exists($path), 404);
        $mimeType = $disk->mimeType($path);
        abort_unless(
            is_string($mimeType)
                && in_array(
                    strtolower($mimeType),
                    ClientPhotoStorage::SAFE_IMAGE_MIME_TYPES,
                    true,
                ),
            415,
        );

        $fileName = $thumbnail
            ? pathinfo($photo->original_name, PATHINFO_FILENAME).' thumbnail.jpg'
            : basename(str_replace('\\', '/', $photo->original_name));

        return $disk->response(
            $path,
            $fileName,
            [
                'Content-Type' => strtolower($mimeType),
                'Cache-Control' => 'private, no-store, max-age=0',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
                'Cross-Origin-Resource-Policy' => 'same-origin',
                'Referrer-Policy' => 'no-referrer',
                'X-Content-Type-Options' => 'nosniff',
            ],
            'inline',
        );
    }
}
