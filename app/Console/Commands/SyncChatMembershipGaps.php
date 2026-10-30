<?php

namespace App\Console\Commands;

use App\Services\Chat\ChatMembershipGaps;
use Illuminate\Console\Command;

/**
 * Marks private staff chat members who are no longer current staff as left, and closes the gap
 * once they're current again, so a re-hire never sees what was sent while they were away. An HR
 * end date passes on its own, so this runs hourly rather than on HR saves.
 */
final class SyncChatMembershipGaps extends Command
{
    protected $signature = 'chat:sync-membership-gaps';

    protected $description = 'Mark leavers in private staff chats and close the gap when they return';

    public function handle(ChatMembershipGaps $gaps): int
    {
        $this->info(sprintf('%d membership gaps opened or closed.', $gaps->sync()));

        return self::SUCCESS;
    }
}
