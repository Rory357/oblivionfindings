<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientIncidentAttachment;
use App\Services\Portal\PortalClientSectionAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PortalIncidentAttachmentController extends Controller
{
    public function download(Request $request, Client $client, ClientIncident $incident, ClientIncidentAttachment $attachment)
    {
        // Ensure portal user is linked to the client (ClientPolicy)
        $this->authorize('view', $client);

        $user = $request->user();
        abort_unless($user?->canDo('incidents.attachments.view.portal'), 403);

        // Cross-check ownership
        abort_unless((int) $incident->client_id === (int) $client->id, 404);
        abort_unless((int) $attachment->incident_id === (int) $incident->id, 404);

        $sectionAccess = app(PortalClientSectionAccess::class)->for($user, $client);
        abort_unless(
            $user->canDo('incidents.view.portal')
                && $sectionAccess['can_view_incidents'],
            403,
        );

        // Portal safety gates
        abort_unless($incident->portal_visible && $incident->reviewed_at, 403);
        abort_unless($attachment->portal_visible, 403);

        $disk = $attachment->disk ?? 'public';
        $path = $attachment->path;

        abort_unless(Storage::disk($disk)->exists($path), 404);

        return Storage::disk($disk)->download(
            $path,
            $attachment->original_name ?? basename($path),
        );
    }
}
