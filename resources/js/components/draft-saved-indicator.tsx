import { Check, CloudOff } from 'lucide-react';
import { useEffect, useState } from 'react';
import { formatRelative, formatTime } from '@/lib/datetime';
import { cn } from '@/lib/utils';

/* -------------------------------------------------------------------------- */
/*  Shared autosave indicator                                                 */
/* -------------------------------------------------------------------------- */
/*
 * PR 16 — A calm, non-flickery "saved a moment ago" badge used by every
 * long-form write surface that reuses `useFormAutosave`. Stays quiet unless
 * the worker has something to recover: no text, no badge.
 */

export type DraftSavedIndicatorProps = {
    savedAt: number | null;
    className?: string;
    /** When true, overrides the timestamp display with "Saving…". */
    saving?: boolean;
};

function formatSavedLabel(savedAt: number, now: number): string {
    const diff = Math.max(0, Math.floor((now - savedAt) / 1000));
    if (diff < 5) return 'just now';
    if (diff < 60) return `${diff}s ago`;
    if (diff < 3600) return formatRelative(savedAt, now);
    return formatTime(savedAt);
}

export default function DraftSavedIndicator({
    savedAt,
    className,
    saving = false,
}: DraftSavedIndicatorProps) {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        if (!savedAt) return;
        const id = setInterval(() => setNow(Date.now()), 15000);
        return () => clearInterval(id);
    }, [savedAt]);

    if (!savedAt && !saving) return null;

    return (
        <div
            role="status"
            aria-live="polite"
            className={cn(
                'inline-flex items-center gap-1.5 text-xs text-muted-foreground',
                className,
            )}
        >
            {saving ? (
                <>
                    <CloudOff className="h-3.5 w-3.5 animate-pulse" aria-hidden />
                    <span>Saving draft…</span>
                </>
            ) : savedAt ? (
                <>
                    <Check className="h-3.5 w-3.5 text-emerald-600" aria-hidden />
                    <span>Draft saved on this device · {formatSavedLabel(savedAt, now)}</span>
                </>
            ) : null}
        </div>
    );
}
