/* Shared visual atoms for the eMAR Medication Rounds surfaces (timeline, board,
   chart, audit, context menu) — status pills + dose dots + the donut arc colour.
   All colours are semantic tokens. */
import { cn } from '@/lib/utils';
import { Check, Play } from 'lucide-react';
import { doseStatusMeta, roundStatusMeta, type RoundStatus } from './types';

const ROUND_TONE_BADGE: Record<string, string> = {
    success: 'bg-status-success-bg text-status-success',
    info: 'bg-status-info-bg text-status-info',
    warning: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
    neutral: 'bg-muted text-muted-foreground',
};

const DOSE_TONE_BADGE: Record<string, string> = {
    success: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
    muted: 'bg-muted text-muted-foreground',
};

const DOSE_DOT_BG: Record<string, string> = {
    success: 'bg-status-success',
    warning: 'bg-status-warning',
    critical: 'bg-status-critical',
    muted: 'bg-muted-foreground',
};

/** The conic-gradient arc colour for a round's progress donut (token var). */
export function roundArcColor(status: RoundStatus): string {
    switch (status) {
        case 'completed':
            return 'var(--status-success)';
        case 'partial':
            return 'var(--status-warning)';
        case 'in_progress':
            return 'var(--primary)';
        default:
            return 'var(--muted-foreground)';
    }
}

export function RoundStatusBadge({
    status,
    className,
    showIcon = true,
}: {
    status: RoundStatus;
    className?: string;
    showIcon?: boolean;
}) {
    const meta = roundStatusMeta(status);
    const Icon =
        status === 'completed' ? Check : status === 'in_progress' ? Play : null;
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold',
                ROUND_TONE_BADGE[meta.tone],
                className,
            )}
        >
            {showIcon && Icon ? <Icon className="h-3 w-3" /> : null}
            {meta.label}
        </span>
    );
}

export function DoseStatusBadge({ status }: { status: string }) {
    const meta = doseStatusMeta(status);
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold',
                DOSE_TONE_BADGE[meta.tone],
            )}
        >
            {meta.label}
        </span>
    );
}

export function DoseDot({ status, title }: { status: string; title?: string }) {
    const meta = doseStatusMeta(status);
    return (
        <span
            title={title}
            aria-hidden
            className={cn(
                'inline-block h-2.5 w-2.5 rounded-full',
                DOSE_DOT_BG[meta.tone],
            )}
        />
    );
}
