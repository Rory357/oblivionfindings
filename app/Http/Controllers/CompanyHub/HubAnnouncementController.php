<?php

namespace App\Http\Controllers\CompanyHub;

use App\Domain\CompanyHub\Services\HubAccess;
use App\Domain\Hr\Models\HrAnnouncement;
use App\Domain\Hr\Models\HrAnnouncementAttachment;
use App\Domain\Hr\Services\AnnouncementAudienceResolver;
use App\Domain\Hr\Services\AnnouncementReadState;
use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HubAnnouncementController extends Controller
{
    use ServesPrivateAttachments;

    private function assertRead(User $user, HrAnnouncement $announcement): void
    {
        app(HubAccess::class)->assertEnter($user);
        abort_unless($announcement->status === 'published' && $announcement->published_at?->lte(now())
            && (! $announcement->expires_at || $announcement->expires_at->isFuture())
            && app(AnnouncementAudienceResolver::class)->includesCurrentUser($announcement, $user), 404);
    }

    private function payload(User $user, HrAnnouncement $announcement): array
    {
        $announcement->loadMissing(['creator:id,name', 'attachments']);
        $attachments = $announcement->attachments->map(fn ($file) => [
            'id' => $file->id, 'name' => $file->original_name, 'size' => $file->size,
            'url' => '/company-hub/announcement-files/'.$file->id,
        ])->all();

        return [
            ...$announcement->only(['id', 'title', 'content', 'priority', 'requires_acknowledgement']),
            'author' => $announcement->creator?->name, 'published_at' => $announcement->published_at->toIso8601String(),
            'ack_deadline' => $announcement->ack_deadline?->toIso8601String(), 'attachments' => $attachments,
            'acknowledged_at' => $announcement->acknowledgements()->where('user_id', $user->id)->value('acknowledged_at'),
            'version' => app(AnnouncementReadState::class)->token($announcement),
            'read_version' => $announcement->read_version,
            'previously_acknowledged' => $announcement->acknowledgementHistory()->where('user_id', $user->id)->where('read_version', '<', $announcement->read_version)->exists(),
        ];
    }

    public function show(Request $request, HrAnnouncement $announcement)
    {
        $this->assertRead($request->user(), $announcement);

        return response()->json($this->payload($request->user(), $announcement))->header('Cache-Control', 'private, no-store');
    }

    public function acknowledge(Request $request, HrAnnouncement $announcement)
    {
        $data = $request->validate(['version' => 'required|string|size:64']);
        $payload = DB::transaction(function () use ($request, $announcement, $data) {
            $announcement = HrAnnouncement::whereKey($announcement->id)->lockForUpdate()->firstOrFail();
            $this->assertRead($request->user(), $announcement);
            app(AnnouncementReadState::class)->confirm($announcement, $request->user(), $data['version']);

            return $this->payload($request->user(), $announcement);
        });

        return response()->json($payload)->header('Cache-Control', 'private, no-store');
    }

    public function attachment(Request $request, HrAnnouncementAttachment $attachment)
    {
        $announcement = $attachment->announcement;
        abort_unless($announcement, 404);
        $this->assertRead($request->user(), $announcement);
        $response = $this->streamPrivateAttachment($attachment->disk, $attachment->path, $attachment->original_name, $attachment->mime);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
