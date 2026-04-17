import { CloudOff, RefreshCw } from 'lucide-react';

import { useOfflineQueueState } from '@/hooks/use-offline-queue';

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
    const { online, pendingCount, syncing } = useOfflineQueueState();

    const showing = !online || pendingCount > 0 || syncing;
    if (!showing) return null;

    const offline = !online;

    let tone =
        'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-100';
    if (!offline && syncing) {
        tone =
            'border-sky-200 bg-sky-50 text-sky-900 dark:border-sky-900/60 dark:bg-sky-950/40 dark:text-sky-100';
    } else if (!offline && pendingCount > 0) {
        tone =
            'border-sky-200 bg-sky-50 text-sky-900 dark:border-sky-900/60 dark:bg-sky-950/40 dark:text-sky-100';
    }

    const Icon = offline ? CloudOff : RefreshCw;
    const iconClass = !offline && syncing ? 'h-4 w-4 animate-spin' : 'h-4 w-4';

    let message: string;
    if (offline && pendingCount === 0) {
        message = 'You\u2019re offline. We\u2019ll send anything you save when you\u2019re back.';
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
            </div>
        </div>
    );
}
