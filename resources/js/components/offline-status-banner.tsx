/* eslint-disable no-restricted-syntax -- The banner's inline actions are
 * compact text buttons that inherit the banner's semantic tone colour
 * (border-current); a full <Button> would break the one-line status strip. */
import { AlertTriangle, CloudOff, LogIn, RefreshCw, Users } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';

import { ConfirmDialog } from '@/components/confirm-dialog';
import { NotRecordedDialog } from '@/components/emar/offline/not-recorded-dialog';
import { useOfflineQueueState } from '@/hooks/use-offline-queue';
import {
    purgeMedicationDeviceData,
    retryOfflineSubmissionsNeedingAttention,
} from '@/lib/offline-queue';
import { router } from '@inertiajs/react';

/**
 * PR 26 — A thin, calm banner that surfaces offline state and the number of
 * locally queued submissions waiting to sync. Mounted once at the app root so
 * it appears consistently across frontline surfaces without requiring each
 * page to opt in.
 *
 * Visibility rules:
 *   - Hidden when online AND nothing is waiting, refused or stranded.
 *   - Shown when offline (always) or when sync is in progress or when there
 *     is a backlog of queued items, so the worker can trust what the app is
 *     doing in the background.
 *
 * Saved actions the server refused (e.g. a PRN over its daily limit) were NOT
 * recorded. They get their own strip IN ADDITION to the offline/waiting line
 * (EA-046), and each is dealt with one at a time in the review dialog — no
 * single "Dismiss" removes them (EA-049). Another worker's saved doses on this
 * device are shown too (EA-048), and signing out asks first while anything is
 * waiting to send.
 */
export default function OfflineStatusBanner() {
    const {
        online,
        pendingCount,
        needsAttentionCount,
        rejectedCount = 0,
        rejectedSubmissions = [],
        strandedSubmissions = [],
        signInRequired = false,
        syncing,
    } = useOfflineQueueState();
    const [reviewing, setReviewing] = useState(false);
    const [signOutPending, setSignOutPending] = useState<number | null>(null);

    useEffect(() => {
        const onBlocked = (event: Event) => {
            const pending = (event as CustomEvent<{ pending?: number }>).detail
                ?.pending;
            setSignOutPending(typeof pending === 'number' ? pending : 1);
        };
        window.addEventListener('emar:sign-out-blocked', onBlocked);
        return () =>
            window.removeEventListener('emar:sign-out-blocked', onBlocked);
    }, []);

    const strips: ReactNode[] = [];
    const strandedCount = strandedSubmissions.length;

    if (rejectedCount > 0) {
        strips.push(
            <div
                key="refused"
                role="alert"
                className="w-full border-b border-status-critical/30 bg-status-critical-bg px-3 py-1.5 text-xs font-medium text-status-critical dark:border-status-critical/60"
            >
                <div className="mx-auto flex max-w-3xl items-center justify-center gap-2">
                    <AlertTriangle aria-hidden className="h-4 w-4" />
                    <span>
                        {rejectedCount === 1
                            ? 'A saved medication record was '
                            : `${rejectedCount} saved medication records were `}
                        <strong>not</strong> recorded. Check{' '}
                        {rejectedCount === 1 ? 'it' : 'each one'} and tell your
                        lead.
                    </span>
                    <button
                        type="button"
                        className="frontline-hit rounded border border-current px-2 py-0.5 font-semibold"
                        onClick={() => setReviewing(true)}
                    >
                        Review
                    </button>
                </div>
            </div>,
        );
    }

    if (strandedCount > 0) {
        strips.push(
            <div
                key="stranded"
                role="status"
                className="w-full border-b border-status-warning/30 bg-status-warning-bg px-3 py-1.5 text-xs font-medium text-status-warning dark:border-status-warning/60"
            >
                <div className="mx-auto flex max-w-3xl items-center justify-center gap-2">
                    <Users aria-hidden className="h-4 w-4" />
                    <span>
                        {strandedCount === 1
                            ? '1 medication record on this device was saved by another worker and isn’t on the chart.'
                            : `${strandedCount} medication records on this device were saved by another worker and aren’t on the chart.`}
                    </span>
                    <button
                        type="button"
                        className="frontline-hit rounded border border-current px-2 py-0.5 font-semibold"
                        onClick={() => setReviewing(true)}
                    >
                        Review
                    </button>
                </div>
            </div>,
        );
    }

    if (signInRequired) {
        strips.push(
            <div
                key="sign-in"
                role="status"
                className="w-full border-b border-status-warning/30 bg-status-warning-bg px-3 py-1.5 text-xs font-medium text-status-warning dark:border-status-warning/60"
            >
                <div className="mx-auto flex max-w-3xl items-center justify-center gap-2">
                    <LogIn aria-hidden className="h-4 w-4" />
                    <span>
                        Sign in again to send the medication records saved on
                        this device. They aren’t on the chart yet.
                    </span>
                </div>
            </div>,
        );
    }

    const showingQueue = !online || pendingCount > 0 || syncing;
    if (showingQueue && !signInRequired) {
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
        const iconClass =
            !offline && syncing ? 'h-4 w-4 animate-spin' : 'h-4 w-4';

        let message: string;
        if (needsAttentionCount > 0) {
            message = offline
                ? `${needsAttentionCount} queued item${needsAttentionCount === 1 ? '' : 's'} will need a manual retry after reconnecting.`
                : `${needsAttentionCount} queued item${needsAttentionCount === 1 ? '' : 's'} need${needsAttentionCount === 1 ? 's' : ''} attention. Check the chart before trying again. Trying again won’t create a duplicate.`;
        } else if (offline && pendingCount === 0) {
            message =
                'You’re offline. We’ll send anything you save when you’re back.';
        } else if (offline && pendingCount === 1) {
            message = 'Offline — 1 item will send when you’re back.';
        } else if (offline) {
            message = `Offline — ${pendingCount} items will send when you’re back.`;
        } else if (syncing) {
            message =
                pendingCount > 0
                    ? `Sending ${pendingCount} queued item${pendingCount === 1 ? '' : 's'}…`
                    : 'Syncing…';
        } else if (pendingCount === 1) {
            // EA-010: online, so the server didn't answer — it isn't "offline".
            message =
                '1 item saved on this device is waiting to send. The server didn’t answer; we’ll keep trying.';
        } else {
            message = `${pendingCount} items saved on this device are waiting to send. The server didn’t answer; we’ll keep trying.`;
        }

        strips.push(
            <div
                key="queue"
                role="status"
                aria-live="polite"
                className={`w-full border-b px-3 py-1.5 text-xs font-medium ${tone}`}
            >
                <div className="mx-auto flex max-w-3xl items-center justify-center gap-2">
                    <Icon aria-hidden className={iconClass} />
                    <span>{message}</span>
                    {!offline && needsAttentionCount > 0 && !syncing ? (
                        <button
                            type="button"
                            className="frontline-hit rounded border border-current px-2 py-0.5 font-semibold"
                            onClick={() => {
                                void retryOfflineSubmissionsNeedingAttention();
                            }}
                        >
                            Retry safely
                        </button>
                    ) : null}
                </div>
            </div>,
        );
    }

    return (
        <>
            {strips.length > 0 ? (
                <div className="sticky top-0 z-50 w-full">{strips}</div>
            ) : null}
            {reviewing ? (
                <NotRecordedDialog
                    refused={rejectedSubmissions}
                    stranded={strandedSubmissions}
                    onClose={() => setReviewing(false)}
                />
            ) : null}
            <ConfirmDialog
                open={signOutPending !== null}
                onClose={() => setSignOutPending(null)}
                onConfirm={() => {
                    setSignOutPending(null);
                    void purgeMedicationDeviceData().finally(() => {
                        router.flushAll();
                        router.post('/logout');
                    });
                }}
                title="Medication records are saved only on this device"
                description={`${signOutPending === 1 ? '1 record hasn’t' : `${signOutPending ?? 0} records haven’t`} reached the chart yet. Stay signed in and reconnect so ${signOutPending === 1 ? 'it sends' : 'they send'}. If you sign out now, ${signOutPending === 1 ? 'it stays' : 'they stay'} on this device and only ${signOutPending === 1 ? 'sends' : 'send'} when you sign in here again.`}
                confirmText="Sign out anyway"
                cancelText="Stay signed in"
                variant="destructive"
                frontline
            />
        </>
    );
}
