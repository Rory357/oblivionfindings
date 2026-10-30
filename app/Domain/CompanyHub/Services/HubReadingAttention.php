<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\Hr\Models\HrAnnouncement;
use App\Domain\Hr\Services\AnnouncementReadState;
use App\Models\User;

/** Required reading is independent of the bounded, searchable activity feed. */
class HubReadingAttention
{
    public function __construct(private readonly HubAccess $access, private readonly AnnouncementReadState $readState) {}

    public function forUser(User $actor): array
    {
        $actor = $actor->fresh();
        abort_unless($actor, 403);
        $this->access->assertEnter($actor);
        $pending = 0;
        $first = null;

        // The relationship's current() scope compares the receipt's read_version
        // with the announcement. Historical or another person's receipts do not clear it.
        $candidates = HrAnnouncement::query()->active()->where('requires_acknowledgement', true)
            ->whereDoesntHave('acknowledgements', fn ($query) => $query->where('user_id', $actor->id))
            ->with('targets')->orderBy('published_at')->orderBy('id')->lazy(50);
        foreach ($candidates as $announcement) {
            // Reuse canonical publication, expiry, current-staff and audience rules.
            if (! $this->readState->canConfirm($announcement, $actor)) {
                continue;
            }
            $pending++;
            $first ??= ['id' => (int) $announcement->id, 'title' => $announcement->title];
        }

        return ['pending_count' => $pending, 'first' => $first];
    }
}
