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

    private function currentActor(User $user, bool $lock = false): User
    {
        $query = User::query()->whereKey($user->id);
        $actor = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($actor, 403);

        return $actor;
    }

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
            'actor_id' => (int) $user->id,
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
        $actor = $this->currentActor($request->user());
        $this->assertRead($actor, $announcement);

        return response()->json($this->payload($actor, $announcement))->header('Cache-Control', 'private, no-store');
    }

    public function acknowledge(Request $request, HrAnnouncement $announcement)
    {
        $data = $request->validate([
            'actor_id' => 'required|integer|min:1|max:2147483647',
            'version' => 'required|string|size:64',
        ]);
        $payload = DB::transaction(function () use ($request, $announcement, $data) {
            $actor = $this->currentActor($request->user(), true);
            app(HubAccess::class)->assertEnter($actor);
            abort_unless((int) $data['actor_id'] === (int) $actor->id, 409, 'Your signed-in account changed. Reload this announcement before confirming it.');
            $announcement = HrAnnouncement::whereKey($announcement->id)->lockForUpdate()->firstOrFail();
            $this->assertRead($actor, $announcement);
            app(AnnouncementReadState::class)->confirm($announcement, $actor, $data['version']);

            return ['saved' => true, ...$this->payload($actor, $announcement)];
        });

        return response()->json($payload)->header('Cache-Control', 'private, no-store');
    }

    public function attachment(Request $request, HrAnnouncementAttachment $attachment)
    {
        $actor = $this->currentActor($request->user());
        $announcement = $attachment->announcement;
        abort_unless($announcement, 404);
        $this->assertRead($actor, $announcement);
        $response = $this->streamPrivateAttachment($attachment->disk, $attachment->path, $attachment->original_name, $attachment->mime);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
