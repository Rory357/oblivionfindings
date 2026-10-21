<?php

namespace App\Services\Chat;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Cursor identities are anchors in the current priority order, not numeric ID ranges. */
final class ChatInboxWindow
{
    public function page(array $orderedIds, array $input, string $revision): array
    {
        if (isset($input['revision']) && ! hash_equals($revision, $input['revision'])) {
            throw new ConflictHttpException('Family work changed. Refresh the queue before loading more.');
        }
        $total = count($orderedIds);
        $start = 0;
        if (isset($input['before'])) {
            $position = array_search((int) $input['before'], $orderedIds, true);
            if ($position === false) {
                throw new ConflictHttpException('This queue position changed. Refresh the queue before loading more.');
            }
            $start = $position + 1;
        }
        $end = $total;
        if (isset($input['through'])) {
            $position = array_search((int) $input['through'], $orderedIds, true);
            // If the former boundary left this queue, retain at least the loaded window.
            $end = min($total, max($position === false ? 0 : $position + 1, (int) ($input['minimum'] ?? 30)));
        }
        $ids = array_slice($orderedIds, $start, max(0, min(30, $end - $start)));
        $floor = $ids === [] ? null : $ids[array_key_last($ids)];

        return ['ids' => $ids, 'revision' => $revision, 'next_before' => $start + count($ids) < $end ? $floor : null,
            'scan_floor' => $floor, 'older_before' => $end < $total && $end > 0 ? $orderedIds[$end - 1] : null];
    }
}
