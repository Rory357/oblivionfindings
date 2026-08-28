import { CloudOff, RefreshCw } from 'lucide-react';

import { useOfflineQueueState } from '@/hooks/use-offline-queue';
import { retryOfflineSubmissionsNeedingAttention } from '@/lib/offline-queue';

/**
 * PR 26 — A thin, calm banner that surfaces offline state and the number of
 * locally queued submissions waiting to sync. Mounted once at the app root so
 * it appears consistently across frontline surfaces without requiring each
 * page to opt in.
 *
 * Visibility rules:
 *   - Hidden when online AND no pending items.
 *   - Shown when offline (always) or when sync is in progress or when there
 *     is a backlog of queued items, so the worker can trust what the app is
 *     doing in the background.
 *
 * The banner sits at the very top of the viewport, above the frontline
 * sticky header, so it never covers interactive content.
 */
export default function OfflineStatusBanner() {
    const { online, pendingCount, needsAttentionCount, syncing } =
        useOfflineQueueState();

    const showing = !online || pendingCount > 0 || syncing;
    if (!showing) return null;

    const offline = !online;

    let tone =
        'border-status-warning/30 bg-status-warning-bg text-status-warning dark:border-status-warning/60 dark:bg-status-warning-bg dark:text-status-warning';
    if (!offline && syncing) {
        tone =
            'border-status-info/30 bg-status-info-bg text-status-info dark:border-status-info/60 dark:bg-status-info-bg dark:text-status-info';
    } else if (!offline && pendingCount > 0 && needsAttentionCount === 0) {
        tone =
            'border-status-info/30 bg-status-info-bg text-status-info dark:border-status-info/60 dark:bg-status-info-bg dark:text-status-info';
    }

    const Icon = offline ? CloudOff : RefreshCw;
    const iconClass = !offline && syncing ? 'h-4 w-4 animate-spin' : 'h-4 w-4';

    let message: string;
    if (needsAttentionCount > 0) {
        message = offline
            ? `${needsAttentionCount} queued item${needsAttentionCount === 1 ? '' : 's'} will need a manual retry after reconnecting.`
            : `${needsAttentionCount} queued item${needsAttentionCount === 1 ? '' : 's'} need${needsAttentionCount === 1 ? 's' : ''} attention. A manual retry will reuse the original request ID.`;
    } else if (offline && pendingCount === 0) {
        message =
            'You\u2019re offline. We\u2019ll send anything you save when you\u2019re back.';
    } else if (offline && pendingCount === 1) {
        message = 'Offline \u2014 1 item will send when you\u2019re back.';
    } else if (offline) {
        message = `Offline \u2014 ${pendingCount} items will send when you\u2019re back.`;
    } else if (syncing) {
        message =
            pendingCount > 0
                ? `Sending ${pendingCount} queued item${pendingCount === 1 ? '' : 's'}\u2026`
                : 'Syncing\u2026';
    } else if (pendingCount === 1) {
        message = '1 item waiting to send. We\u2019ll retry automatically.';
    } else {
        message = `${pendingCount} items waiting to send. We\u2019ll retry automatically.`;
    }

    return (
        <div
            role="status"
            aria-live="polite"
            className={`sticky top-0 z-50 w-full border-b px-3 py-1.5 text-xs font-medium ${tone}`}
        >
            <div className="mx-auto flex max-w-3xl items-center justify-center gap-2">
                <Icon aria-hidden className={iconClass} />
                <span>{message}</span>
                {!offline && needsAttentionCount > 0 && !syncing ? (
                    <button
                        type="button"
                        className="rounded border border-current px-2 py-0.5 font-semibold"
                        onClick={() => {
                            void retryOfflineSubmissionsNeedingAttention();
                        }}
                    >
                        Retry safely
                    </button>
                ) : null}
            </div>
        </div>
    );
}
