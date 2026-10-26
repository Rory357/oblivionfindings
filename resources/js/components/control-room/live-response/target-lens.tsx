import type { ResponseClock } from '@/components/control-room/live-response/response-clock';
import { cn } from '@/lib/utils';
import type { CSSProperties } from 'react';

/**
 * The countdown lens — Control Room's own mark (Stephan, 24 Oct 2026: "light
 * board + Oblivion details"). The same round lens as the pop-up kit, with a
 * ring that empties as the response target gets closer: brand while there's
 * time, amber when it's close, red once missed, grey when there is no
 * target (it then shows how long the alert has waited).
 */
export function TargetLens({
    clock,
    size = 'md',
    label,
    className,
}: {
    clock: ResponseClock;
    size?: 'md' | 'lg';
    /** Which target, for the spoken name: "Acknowledge target". */
    label?: string;
    className?: string;
}) {
    const spoken = `${label ? `${label}: ` : ''}${clock.words}`;
    return (
        <span
            role="img"
            aria-label={spoken}
            title={spoken}
            data-tone={clock.tone}
            className={cn(
                'cr-lens',
                size === 'lg' && 'cr-lens--lg',
                clock.tone !== 'brand' && `cr-lens--${clock.tone}`,
                clock.mode === 'late' && 'cr-lens--late',
                className,
            )}
            style={{ '--cr-lens-left': `${Math.round(clock.left * 100)}%` } as CSSProperties}
        >
            <span className="cr-lens-value">{clock.short}</span>
            {size === 'lg' && clock.mode !== 'none' ? (
                <span className="cr-lens-unit">
                    {clock.mode === 'countdown'
                        ? 'left'
                        : clock.mode === 'late'
                          ? 'target'
                          : 'waiting'}
                </span>
            ) : null}
        </span>
    );
}

/** The live mark beside "updated": a soft pulse while the board refreshes itself. */
export function LiveMark({
    stale,
    refreshing,
    updatedLabel,
}: {
    stale: boolean;
    refreshing: boolean;
    /** "updated 9:46 am" */
    updatedLabel: string;
}) {
    return (
        <span
            className={cn('cr-live', stale && 'cr-live--stale')}
            aria-live="polite"
        >
            <span className="cr-live-dot" aria-hidden="true" />
            <span className="font-semibold">{stale ? 'Not updating' : 'Live'}</span>
            <span className="opacity-80">
                {refreshing ? 'refreshing…' : updatedLabel}
            </span>
        </span>
    );
}
