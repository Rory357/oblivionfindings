<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrAnnouncement;
use App\Domain\Hr\Models\HrAnnouncementAcknowledgement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AnnouncementReadState
{
    public function snapshot(HrAnnouncement $announcement): array
    {
        return [
            ...$announcement->only(['title', 'content', 'priority', 'requires_acknowledgement']),
            'attachments' => $announcement->attachments()->orderBy('id')->get()
                ->map(fn ($file) => $file->only(['id', 'original_name', 'mime', 'size', 'disk', 'path']))->all(),
        ];
    }

    public function token(HrAnnouncement $announcement): string
    {
        return hash('sha256', json_encode([$announcement->id, $announcement->read_version, $this->snapshot($announcement)]));
    }

    public function canConfirm(HrAnnouncement $announcement, User $recipient): bool
    {
        return $announcement->status === 'published' && $announcement->published_at?->lte(now())
            && (! $announcement->expires_at || $announcement->expires_at->isFuture())
            && app(AnnouncementAudienceResolver::class)->includesCurrentUser($announcement, $recipient);
    }

    public function confirm(HrAnnouncement $announcement, User $recipient, string $token, ?User $actor = null): void
    {
        DB::transaction(function () use ($announcement, $recipient, $token, $actor) {
            $current = HrAnnouncement::whereKey($announcement->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->canConfirm($current, $recipient), 404);
            abort_unless($current->requires_acknowledgement, 422, 'This announcement does not request a read confirmation.');
            abort_unless(hash_equals($this->token($current), $token), 409, 'This announcement has changed. Refresh it and read the latest version before confirming.');

            HrAnnouncementAcknowledgement::firstOrCreate([
                'announcement_id' => $current->id, 'user_id' => $recipient->id, 'read_version' => $current->read_version,
            ], ['acknowledged_at' => now(), 'acknowledged_by' => $actor?->id]);
        });
    }

    public function preserve(HrAnnouncement $announcement): void
    {
        DB::table('hr_announcement_read_versions')->insertOrIgnore([
            'announcement_id' => $announcement->id, 'read_version' => $announcement->read_version,
            'content' => json_encode($this->snapshot($announcement)), 'created_at' => now(),
        ]);
    }
}
