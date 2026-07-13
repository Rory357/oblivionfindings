<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientPhoto;
use App\Models\FamilyPortalSetting;
use App\Services\AuditLogger;
use App\Services\Clients\ClientPhotoMediaUrls;
use App\Services\Clients\ClientPhotoStorage;
use App\Services\Portal\PortalClientSectionAccess;
use App\Services\Timeline\TimelineEmitter;
use Illuminate\Http\Request;

class PortalPhotoController extends Controller
{
    public function __construct(
        private readonly ClientPhotoMediaUrls $mediaUrls,
        private readonly ClientPhotoStorage $photoStorage,
    ) {}

    public function index(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);
        $canViewSharedPhotos = app(PortalClientSectionAccess::class)
            ->for($user, $client)['has_family_information_consent'];

        $photos = ClientPhoto::where('client_id', $client->id)
            ->approved()
            ->visibleToFamily()
            ->when(! $canViewSharedPhotos, fn ($query) => $query->where('uploaded_by_user_id', $user->id))
            ->with('uploadedBy:id,name')
            ->orderByDesc('created_at')
            ->paginate(24)
            ->through(function (ClientPhoto $photo): array {
                return [
                    'id' => $photo->id,
                    ...$this->mediaUrls->portal($photo),
                    'caption' => $photo->caption,
                    'tags' => $photo->tags,
                    'created_at' => $photo->created_at?->toISOString(),
                    'uploaded_by_name' => $photo->uploadedBy?->name,
                ];
            });

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
            'photo' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,gif,webp'],
            'caption' => ['nullable', 'string', 'max:500'],
            'tags' => ['nullable', 'array'],
        ]);

        $file = $request->file('photo');
        $stored = $this->photoStorage->store($file, $client);

        $requiresApproval = $portalSettings ? $portalSettings->require_photo_approval : false;
        $status = $requiresApproval ? 'pending_approval' : 'approved';

        $photo = ClientPhoto::create([
            'client_id' => $client->id,
            'uploaded_by_user_id' => $user->id,
            ...$stored,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'caption' => $validated['caption'] ?? null,
            'tags' => $validated['tags'] ?? null,
            'visibility' => 'family',
            'status' => $status,
        ]);

        app(TimelineEmitter::class)->record([
            'source_type' => ClientPhoto::class,
            'source_id' => $photo->id,
            'occurred_at' => now(),
            'type' => 'photo_uploaded',
            'actor_user_id' => $user->id,
            'client_id' => $client->id,
            'site_id' => $client->site_id,
            'subject' => 'Photo uploaded by family',
            'body' => $validated['caption'] ?? null,
            'visibility' => 'portal',
            'is_pinned' => false,
            'created_by' => $user->id,
        ]);

        AuditLogger::log('portal.photo.upload', $photo);

        return redirect()->back()->with('success', 'Photo uploaded successfully.');
    }
}
