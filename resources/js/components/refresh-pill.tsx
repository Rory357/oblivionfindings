import { RefreshCw } from 'lucide-react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/* -------------------------------------------------------------------------- */
/*  RefreshPill — visible freshness chip for frontline pages                  */
/* -------------------------------------------------------------------------- */
/*
 * PR 6 — pairs with `useLiveRefresh`. The pill makes two things explicit on
 * pages that auto-refresh:
 *   1. *When* the page was last updated ("Updated 12s ago").
 *   2. That the worker can trigger a refresh themselves at any time — tapping
 *      the pill calls `onRefresh`.
 *
 * Kept intentionally small so it can sit next to other header actions (e.g.
 * the notifications bell on `/my-day`) without crowding the header.
 */

export interface RefreshPillProps {
    lastUpdatedAt: Date;
    isRefreshing: boolean;
    onRefresh: () => void;
    className?: string;
}

function formatFreshness(date: Date, now: number): string {
    const diffSec = Math.max(0, Math.floor((now - date.getTime()) / 1000));
    if (diffSec < 5) return 'Just updated';
    if (diffSec < 60) return `Updated ${diffSec}s ago`;
    const mins = Math.floor(diffSec / 60);
    if (mins < 60) return `Updated ${mins}m ago`;
    const hrs = Math.floor(mins / 60);
    return `Updated ${hrs}h ago`;
}

export function RefreshPill({
    lastUpdatedAt,
    isRefreshing,
    onRefresh,
    className,
}: RefreshPillProps) {
    // Tick every 15s so the "Xs ago" / "Xm ago" label stays honest without
    // re-rendering the host page. The underlying data isn't changing — this
    // is purely a display refresh.
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        const id = window.setInterval(() => setNow(Date.now()), 15_000);
        return () => window.clearInterval(id);
    }, []);

    const label = isRefreshing
        ? 'Refreshing…'
        : formatFreshness(lastUpdatedAt, now);

    return (
        <Button
            type="button"
            variant="outline"
            onClick={onRefresh}
            disabled={isRefreshing}
            aria-label={`${label}. Tap to refresh now.`}
            className={cn(
                'frontline-focus min-h-9 rounded-full border-border/70 bg-muted/60 px-3 text-xs text-muted-foreground hover:bg-muted hover:text-foreground disabled:cursor-wait disabled:opacity-70',
                className,
            )}
        >
            <RefreshCw
                aria-hidden
                className={cn('h-3.5 w-3.5', isRefreshing && 'animate-spin')}
            />
            <span aria-live="polite">{label}</span>
        </Button>
    );
}

export default RefreshPill;
