<?php

namespace App\Services\ControlRoom;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;

/** One viewer's broadcast reader for one response or command (see BroadcastRecipientReadService). Not authority on its own. */
final class BroadcastRecipientReadContext
{
    public function __construct(public readonly BroadcastRecipientReadService $owner) {}
    public function scope(MorphMany $query): MorphMany { return $this->owner->scope($this, $query); }
    public function project(DatabaseNotification $notice): ?array { return $this->owner->project($this, $notice); }
    public function envelope(): array { return $this->owner->envelope($this); }
    public function selected(string $id): array { return $this->owner->selected($this, $id); }
    public function unreadBatch(int $maximum = 200): BroadcastRecipientReadBatch { return $this->owner->unreadBatch($this, $maximum); }
    public function singleBatch(string $id): BroadcastRecipientReadBatch { return $this->owner->singleBatch($this, $id); }
    public function restoreBatch(array $references): BroadcastRecipientReadBatch { return $this->owner->restoreBatch($this, $references); }
}
