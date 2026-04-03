<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientPhoto;
use App\Models\FamilyPortalSetting;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PortalPhotoController extends Controller
{
    public function index(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);

        $photos = ClientPhoto::where('client_id', $client->id)
            ->approved()
            ->visibleToFamily()
            ->with('uploadedBy:id,name')
            ->orderByDesc('created_at')
            ->paginate(24)
            ->through(fn ($photo) => [
                'id' => $photo->id,
                'url' => $photo->storage_path ? Storage::disk('public')->url($photo->storage_path) : null,
                'thumbnail_url' => $photo->thumbnail_path ? Storage::disk('public')->url($photo->thumbnail_path) : null,
                'caption' => $photo->caption,
                'tags' => $photo->tags,
                'created_at' => $photo->created_at?->toISOString(),
                'uploaded_by_name' => $photo->uploadedBy?->name,
            ]);

        $portalSettings = FamilyPortalSetting::where('client_id', $client->id)->first();

        return inertia('portal/photos', [
            'client' => [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'avatar' => $client->avatar,
                'profile_photo_url' => $client->profile_photo_url,
            ],
            'photos' => $photos,
            'canUpload' => $portalSettings ? (bool) $portalSettings->allow_family_photo_upload : true,
            'requiresApproval' => $portalSettings ? (bool) $portalSettings->require_photo_approval : false,
        ]);
    }

    public function store(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);

        $portalSettings = FamilyPortalSetting::where('client_id', $client->id)->first();
        $uploadsAllowed = $portalSettings ? $portalSettings->allow_family_photo_upload : true;
        abort_unless($uploadsAllowed, 403, 'Photo uploads are not enabled for this client.');

        $validated = $request->validate([
            'photo' => 'required|image|max:10240',
            'caption' => 'nullable|string|max:500',
            'tags' => 'nullable|array',
        ]);

        $file = $request->file('photo');
        $directory = "client-photos/{$client->id}";
        $thumbDirectory = "client-photos/{$client->id}/thumbs";

        // Store the original photo
        $storagePath = $file->store($directory, 'public');

        // Create thumbnail using GD
        $thumbnailPath = null;
        $fullPath = Storage::disk('public')->path($storagePath);

        if (file_exists($fullPath)) {
            $imageInfo = getimagesize($fullPath);

            if ($imageInfo !== false) {
                $sourceImage = match ($imageInfo[2]) {
                    IMAGETYPE_JPEG => imagecreatefromjpeg($fullPath),
                    IMAGETYPE_PNG => imagecreatefrompng($fullPath),
                    IMAGETYPE_GIF => imagecreatefromgif($fullPath),
                    IMAGETYPE_WEBP => imagecreatefromwebp($fullPath),
                    default => null,
                };

                if ($sourceImage) {
                    $origWidth = imagesx($sourceImage);
                    $origHeight = imagesy($sourceImage);
                    $maxDim = 400;

                    if ($origWidth > $maxDim || $origHeight > $maxDim) {
                        $ratio = min($maxDim / $origWidth, $maxDim / $origHeight);
                        $newWidth = (int) round($origWidth * $ratio);
                        $newHeight = (int) round($origHeight * $ratio);
                    } else {
                        $newWidth = $origWidth;
                        $newHeight = $origHeight;
                    }

                    $thumbnail = imagecreatetruecolor($newWidth, $newHeight);

                    // Preserve transparency for PNG/GIF
                    if (in_array($imageInfo[2], [IMAGETYPE_PNG, IMAGETYPE_GIF], true)) {
                        imagealphablending($thumbnail, false);
                        imagesavealpha($thumbnail, true);
                        $transparent = imagecolorallocatealpha($thumbnail, 0, 0, 0, 127);
                        imagefilledrectangle($thumbnail, 0, 0, $newWidth, $newHeight, $transparent);
                    }

                    imagecopyresampled($thumbnail, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);

                    // Ensure thumbs directory exists
                    Storage::disk('public')->makeDirectory($thumbDirectory);

                    $thumbFilename = pathinfo($storagePath, PATHINFO_FILENAME) . '_thumb.jpg';
                    $thumbnailPath = "{$thumbDirectory}/{$thumbFilename}";
                    $thumbFullPath = Storage::disk('public')->path($thumbnailPath);

                    imagejpeg($thumbnail, $thumbFullPath, 85);

                    imagedestroy($sourceImage);
                    imagedestroy($thumbnail);
                }
            }
        }

        $requiresApproval = $portalSettings ? $portalSettings->require_photo_approval : false;
        $status = $requiresApproval ? 'pending_approval' : 'approved';

        $photo = ClientPhoto::create([
            'client_id' => $client->id,
            'uploaded_by_user_id' => $user->id,
            'storage_path' => $storagePath,
            'thumbnail_path' => $thumbnailPath,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'caption' => $validated['caption'] ?? null,
            'tags' => $validated['tags'] ?? null,
            'visibility' => 'family',
            'status' => $status,
        ]);

        AuditLogger::log('portal.photo.upload', $photo);

        return redirect()->back()->with('success', 'Photo uploaded successfully.');
    }
}
