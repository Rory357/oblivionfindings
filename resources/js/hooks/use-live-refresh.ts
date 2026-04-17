import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

/* -------------------------------------------------------------------------- */
/*  useLiveRefresh — visible, safe auto-refresh for frontline pages           */
/* -------------------------------------------------------------------------- */
/*
 * PR 6 — replaces the silent `setInterval(router.reload, 60s)` pattern that
 * used to shift content under frontline workers mid-interaction.
 *
 * Behaviour:
 *   - Auto-refresh fires on an interval (default 60s) but is *guarded* —
 *     the tick is skipped whenever a refresh would be disruptive.
 *   - `refreshNow()` is always available for a manual user-driven refresh.
 *   - `lastUpdatedAt` advances every time a refresh finishes so the
 *     RefreshPill can show freshness honestly.
 *
 * Guards (the "no-mutate-while-focused" rule):
 *   - the tab is hidden (`document.hidden`)
 *   - the user is focused inside an input / textarea / select /
 *     contentEditable element
 *   - a Radix dialog, alertdialog, or popover is open (detected via the
 *     `[data-state="open"]` attribute Radix writes on the portal root)
 *
 * When a tick is skipped the timer keeps running — the next safe tick
 * refreshes the page, so freshness catches up as soon as the user is idle.
 */

type RefreshDoneFn = () => void;

export interface UseLiveRefreshOptions {
    /** Poll interval in ms. Default 60s. */
    intervalMs?: number;
    /** Disable auto-refresh entirely (manual refresh still works). */
    enabled?: boolean;
    /**
     * Override the default refresh action. The callback receives a `done`
     * function that must be invoked when the refresh finishes, so the hook
     * can flip `isRefreshing` back off and advance `lastUpdatedAt`.
     *
     * Omit to use Inertia's `router.reload({ preserveScroll: true })`.
     */
    onRefresh?: (done: RefreshDoneFn) => void;
}

export interface UseLiveRefreshResult {
    lastUpdatedAt: Date;
    isRefreshing: boolean;
    refreshNow: () => void;
}

function isSafeToRefresh(): boolean {
    if (typeof document === 'undefined') return false;
    if (document.hidden) return false;

    const active = document.activeElement as HTMLElement | null;
    if (active) {
        const tag = active.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') {
            return false;
        }
        if (active.isContentEditable) return false;
    }

    // Radix writes data-state="open" on dialogs, alertdialogs and popovers
    // while they are visible. Any open overlay blocks auto-refresh.
    if (
        document.querySelector(
            '[role="dialog"][data-state="open"],[role="alertdialog"][data-state="open"]',
        )
    ) {
        return false;
    }

    return true;
}

export function useLiveRefresh(
    options: UseLiveRefreshOptions = {},
): UseLiveRefreshResult {
    const { intervalMs = 60_000, enabled = true, onRefresh } = options;

    const [lastUpdatedAt, setLastUpdatedAt] = useState<Date>(() => new Date());
    const [isRefreshing, setIsRefreshing] = useState(false);
    const isRefreshingRef = useRef(false);

    const refreshNow = useCallback(() => {
        if (isRefreshingRef.current) return;
        isRefreshingRef.current = true;
        setIsRefreshing(true);

        const done: RefreshDoneFn = () => {
            isRefreshingRef.current = false;
            setIsRefreshing(false);
            setLastUpdatedAt(new Date());
        };

        if (onRefresh) {
            onRefresh(done);
        } else {
            router.reload({
                preserveScroll: true,
                preserveState: true,
                onFinish: done,
            });
        }
    }, [onRefresh]);

    // Keep the latest refreshNow in a ref so the polling effect doesn't need
    // to tear down and rebuild the timer every time the callback identity
    // changes (which would cause interval drift).
    const refreshNowRef = useRef(refreshNow);
    useEffect(() => {
        refreshNowRef.current = refreshNow;
    }, [refreshNow]);

    // Guarded auto-refresh tick.
    useEffect(() => {
        if (!enabled) return;
        const id = window.setInterval(() => {
            if (!isSafeToRefresh()) return;
            refreshNowRef.current();
        }, intervalMs);
        return () => window.clearInterval(id);
    }, [enabled, intervalMs]);

    return { lastUpdatedAt, isRefreshing, refreshNow };
}

export default useLiveRefresh;
