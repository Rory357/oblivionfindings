<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes finished Chat delivery bookkeeping that nothing reads any more. Messages, notices,
 * delivered rows (they back the bell), call records and the sync change log are never touched:
 * those follow the retention policy.
 */
final class PruneChatRecords extends Command
{
    protected $signature = 'chat:prune';

    protected $description = 'Remove finished Chat delivery receipts and old call-event duplicates';

    /** Days to keep each kind of finished row. */
    public const KEEP_DAYS = ['deliveries' => 30, 'push' => 7, 'media_events' => 30];

    private const BATCH = 1000;

    public function handle(): int
    {
        $removed = 0;
        $removed += $this->prune('chat_browser_push_deliveries', fn ($query) => $query
            ->whereIn('status', ['accepted', 'expired', 'rejected', 'suppressed', 'failed'])
            ->where('updated_at', '<', now()->subDays(self::KEEP_DAYS['push'])));
        // Push receipts for these rows go with them (cascade).
        $removed += $this->prune('chat_message_deliveries', fn ($query) => $query
            ->whereIn('status', ['superseded', 'suppressed', 'failed'])
            ->where('updated_at', '<', now()->subDays(self::KEEP_DAYS['deliveries'])));
        foreach (['chat_family_work_deliveries', 'chat_call_notice_deliveries'] as $table) {
            $removed += $this->prune($table, fn ($query) => $query
                ->whereIn('status', ['suppressed', 'failed'])
                ->where('updated_at', '<', now()->subDays(self::KEEP_DAYS['deliveries'])));
        }
        $removed += $this->prune('chat_media_events', fn ($query) => $query
            ->where('received_at', '<', now()->subDays(self::KEEP_DAYS['media_events'])), 'id');

        $this->info((string) $removed);

        return self::SUCCESS;
    }

    /** Deletes in small batches so no delete holds a long lock on a busy table. */
    private function prune(string $table, \Closure $scope, string $key = 'id'): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }
        $removed = 0;
        do {
            $ids = $scope(DB::table($table))->orderBy($key)->limit(self::BATCH)->pluck($key);
            if ($ids->isNotEmpty()) {
                $removed += DB::table($table)->whereIn($key, $ids)->delete();
            }
        } while ($ids->count() === self::BATCH);

        return $removed;
    }
}
