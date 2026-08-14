<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\NotificationService;
use App\Services\Tasks\TaskAggregator;
use App\Services\Tasks\TaskItem;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Hourly escalation sweep over the company-wide work-item feed (TaskAggregator).
 *
 * Three escalation levels, deduped for the item's lifetime via task_escalations:
 *   • Level 1 — assignee nudge: the moment an assigned item is overdue,
 *     notify its assignee once. Skipped for unassigned items.
 *   • Level 2 — manager escalation: once an item has been overdue for 3+ days
 *     (dueAt <= now - 3d), notify the managers group once — regardless of
 *     whether the item has an assignee.
 *   • Level 3 — watcher FYI: the moment an item is overdue, notify each of
 *     its watchers ("Following") once, EXCEPT the assignee (who gets level 1).
 *     Deduped per (source, item, level=3, watcher_id) so each watcher is
 *     pinged once per item lifetime even across users' aggregator passes.
 *
 * DESIGN NOTE (pragmatic v1): TaskAggregator is strictly per-user — every
 * provider gates on the viewing user's permissions and there is no system
 * user, so there is no legitimate "see everything" pass. This command
 * therefore iterates every approved user and runs the aggregator per user
 * (O(users × providers)). That is acceptable for an hourly job at this
 * deployment's scale; the task_escalations unique key keeps the fan-out
 * idempotent even though the same item is seen through many users' eyes.
 */
class EscalateOverdueTasks extends Command
{
    protected $signature = 'tasks:escalate';

    protected $description = 'Nudge assignees about overdue work items and escalate 3-day-overdue items to managers (deduped via task_escalations).';

    private const MANAGER_ESCALATION_DAYS = 3;

    public function handle(TaskAggregator $aggregator, NotificationService $notifications): int
    {
        // Preload the dedupe ledger once; extend it in memory as we insert so
        // the same item seen through a later user's pass is not re-notified.
        $seen = DB::table('task_escalations')
            ->get(['source', 'item_id', 'level', 'assignee_id'])
            ->map(fn ($row) => $row->source.'|'.$row->item_id.'|'.$row->level.'|'.$row->assignee_id)
            ->flip()
            ->all();

        $managerCutoff = now()->subDays(self::MANAGER_ESCALATION_DAYS);
        $nudged = 0;
        $escalated = 0;
        $watchersPinged = 0;

        User::query()
            ->whereNotNull('approved_at')
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($aggregator, $notifications, $managerCutoff, &$seen, &$nudged, &$escalated, &$watchersPinged) {
                foreach ($users as $user) {
                    $overdue = $aggregator->filterItems(
                        $aggregator->itemsFor($user),
                        $user,
                        ['overdue' => true],
                    );

                    foreach ($overdue as $item) {
                        if ($item->bucket === TaskItem::BUCKET_DONE) {
                            continue;
                        }

                        // Level 1 — nudge the assignee. The per-user pass only
                        // fires for the assignee themself so the notification
                        // is always permission-consistent with what they see.
                        if (($item->assignee['id'] ?? null) === $user->id) {
                            $nudged += $this->escalate($notifications, $seen, $item, 1, [
                                'event_key' => 'tasks.overdue_assignee',
                                'body' => 'A work item assigned to you is overdue. Open it to update or complete it.',
                                'target_user_ids' => [$user->id],
                                'include_managers' => false,
                            ]) ? 1 : 0;
                        }

                        // Level 3 — FYI each watcher ("Following") that the item
                        // is overdue, once per watcher per item lifetime. The
                        // assignee is excluded (they get the level-1 nudge). The
                        // dedupe row keys on the watcher id in assignee_id, so
                        // this is idempotent regardless of which user's pass
                        // surfaced the item.
                        $assigneeId = (int) ($item->assignee['id'] ?? 0);
                        $watcherIds = $aggregator->candidateWatcherIdsForDelivery(
                            $item->identitySource(),
                            $item->numericId(),
                            $assigneeId > 0 ? [$assigneeId] : [],
                        );

                        foreach ($watcherIds as $watcherId) {
                            $watcherItem = $aggregator->authorizedWatcherItemForDelivery(
                                $item->identitySource(),
                                $item->numericId(),
                                $watcherId,
                            );
                            if ($watcherItem === null) {
                                continue;
                            }

                            $watchersPinged += $this->escalate($notifications, $seen, $watcherItem, 3, [
                                'event_key' => 'tasks.overdue_assignee',
                                'title' => 'Watching: '.trim(($watcherItem->ref ? $watcherItem->ref.' ' : '').$watcherItem->title).' is overdue',
                                'body' => 'A work item you are following is overdue.',
                                'target_user_ids' => [(int) $watcherId],
                                'include_managers' => false,
                            ], (int) $watcherId) ? 1 : 0;
                        }

                        // Level 2 — 3+ days overdue: escalate to the managers
                        // group, assignee or not.
                        if ($item->dueAt !== null && Carbon::parse($item->dueAt)->lte($managerCutoff)) {
                            $escalated += $this->escalate($notifications, $seen, $item, 2, [
                                'event_key' => 'tasks.overdue_escalation',
                                'body' => sprintf(
                                    'A work item has been overdue for %d+ days%s and needs management attention.',
                                    self::MANAGER_ESCALATION_DAYS,
                                    $item->assignee ? ' (assigned to '.($item->assignee['name'] ?? 'a staff member').')' : ' (unassigned)',
                                ),
                                'include_managers' => true,
                            ]) ? 1 : 0;
                        }
                    }
                }
            });

        $this->info("Overdue task escalations sent — assignee nudges: {$nudged}, manager escalations: {$escalated}, watcher FYIs: {$watchersPinged}.");

        return self::SUCCESS;
    }

    /**
     * Notify once per (source, item, level, assignee) and record the dedupe
     * row — level-1 nudges re-fire for a NEW assignee after reassignment;
     * level-2 manager escalations use assignee 0 (once per item lifetime);
     * level-3 watcher FYIs pass an explicit $dedupeKey (the watcher id) so
     * each follower is pinged once per item lifetime. Returns true when a new
     * notification was actually sent.
     */
    private function escalate(
        NotificationService $notifications,
        array &$seen,
        TaskItem $item,
        int $level,
        array $extra,
        ?int $dedupeKey = null,
    ): bool {
        $itemId = $item->numericId();
        $source = $item->identitySource();
        $assigneeKey = $dedupeKey ?? ($level === 1 ? (int) ($item->assignee['id'] ?? 0) : 0);
        $key = $source.'|'.$itemId.'|'.$level.'|'.$assigneeKey;
        $legacyKey = $item->source.'|'.$itemId.'|'.$level.'|'.$assigneeKey;

        if (isset($seen[$key])
            || ($source !== $item->source && isset($seen[$legacyKey]))) {
            return false;
        }

        try {
            $sent = DB::transaction(function () use (
                $notifications,
                $source,
                $itemId,
                $level,
                $assigneeKey,
                $item,
                $extra,
            ): bool {
                $claimed = DB::table('task_escalations')->insertOrIgnore([
                    'source' => $source,
                    'item_id' => $itemId,
                    'level' => $level,
                    'assignee_id' => $assigneeKey,
                    'notified_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($claimed !== 1) {
                    return false;
                }

                // AppEventNotification currently delivers through the database
                // channel, so the notification row and unique delivery claim
                // commit or roll back together. Claim-first prevents two
                // overlapping command runs from both notifying the same user.
                $notifications->notifyCrud(null, 'overdue', 'task', null, null, array_merge([
                    'title' => 'Overdue: '.trim(($item->ref ? $item->ref.' ' : '').$item->title),
                    'url' => $item->link ? url($item->link) : url('/tasks'),
                    'severity' => $item->severity,
                    'include_assigned_workers' => false,
                    'include_entity_user' => false,
                    'context' => array_filter([
                        'Module' => $item->sourceLabel,
                        'Ticket' => $item->ref,
                        'Severity' => $item->severity,
                        'Due' => $item->dueAt ? Carbon::parse($item->dueAt)->format('Y-m-d H:i') : null,
                        'Assigned to' => $item->assignee['name'] ?? null,
                    ]),
                ], $extra));

                return true;
            }, 3);

            if (! $sent) {
                $seen[$key] = true;

                return false;
            }

            $seen[$key] = true;

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }
}
