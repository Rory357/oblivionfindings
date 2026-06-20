/* eslint-disable no-restricted-syntax -- Bespoke register chrome (tone chips, score
 * squares, review flags) for the Risk Assessments module; styled spans using
 * semantic design tokens only, never hardcoded colours. Mirrors the look of
 * register-row-kit but adds the 4-band risk tone (incl. an `info`/medium band the
 * shared kit lacks) so low/medium/high/extreme stay visually distinct. */
import { cn } from '@/lib/utils';
import {
    AlertTriangle,
    Building2,
    CircleDashed,
    Clock,
    User,
    Zap,
    type LucideIcon,
} from 'lucide-react';
import type { AttachedTo, AttachType, RaLevel, RaStatus, ReviewState } from './types';

/* ------------------------------------------------------------------ */
/*  Tone system (semantic tokens only)                                 */
/* ------------------------------------------------------------------ */

export type RaTone = 'success' | 'info' | 'warning' | 'critical' | 'neutral';

export const RA_TONE_CHIP: Record<RaTone, string> = {
    success: 'bg-status-success-bg text-status-success',
    info: 'bg-primary/10 text-primary',
    warning: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
    neutral: 'bg-muted text-muted-foreground',
};

export const RA_TONE_SOLID: Record<RaTone, string> = {
    success: 'bg-status-success text-white',
    info: 'bg-primary text-primary-foreground',
    warning: 'bg-status-warning text-white',
    critical: 'bg-status-critical text-white',
    neutral: 'bg-muted-foreground text-white',
};

export const RA_TONE_DOT: Record<RaTone, string> = {
    success: 'bg-status-success',
    info: 'bg-primary',
    warning: 'bg-status-warning',
    critical: 'bg-status-critical',
    neutral: 'bg-muted-foreground',
};

/* ------------------------------------------------------------------ */
/*  Constants                                                          */
/* ------------------------------------------------------------------ */

export const LIKELIHOOD_LABELS = ['Rare', 'Unlikely', 'Possible', 'Likely', 'Almost certain'];
export const CONSEQUENCE_LABELS = ['Insignificant', 'Minor', 'Moderate', 'Major', 'Catastrophic'];

export const FREQ_OPTIONS: { value: number; label: string }[] = [
    { value: 30, label: '30 days' },
    { value: 90, label: '90 days' },
    { value: 180, label: '180 days' },
    { value: 365, label: '1 year' },
];

export const RIBBON_STAGES = ['Draft', 'Active', 'Review', 'Superseded', 'Archived'];

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

export function scoreLevel(score: number): RaLevel {
    if (score >= 16) return 'extreme';
    if (score >= 10) return 'high';
    if (score >= 5) return 'medium';
    return 'low';
}

export function levelTone(level: RaLevel): RaTone {
    return ({ low: 'success', medium: 'info', high: 'warning', extreme: 'critical' } as const)[level];
}

export function cap(s: string): string {
    return s ? s.charAt(0).toUpperCase() + s.slice(1) : s;
}

export function statusMeta(status: RaStatus): { label: string; tone: RaTone } {
    return {
        draft: { label: 'Draft', tone: 'neutral' as const },
        active: { label: 'Active', tone: 'success' as const },
        under_review: { label: 'Under review', tone: 'warning' as const },
        superseded: { label: 'Superseded', tone: 'neutral' as const },
        archived: { label: 'Archived', tone: 'neutral' as const },
    }[status];
}

export function attachMeta(type: AttachType): { icon: LucideIcon; tone: RaTone } {
    return {
        site: { icon: Building2, tone: 'info' as const },
        client: { icon: User, tone: 'success' as const },
        event: { icon: Zap, tone: 'warning' as const },
        standalone: { icon: CircleDashed, tone: 'neutral' as const },
    }[type];
}

export function fmtDate(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
}

/* ------------------------------------------------------------------ */
/*  Chips                                                              */
/* ------------------------------------------------------------------ */

/** Score square + level pill (inherent or residual). */
export function LevelCell({ score, level }: { score: number; level: RaLevel }) {
    const tone = levelTone(level);
    return (
        <span className="inline-flex items-center gap-1.5">
            <span
                className={cn(
                    'inline-flex h-7 w-7 items-center justify-center rounded-md text-xs font-bold tabular-nums',
                    RA_TONE_CHIP[tone],
                )}
            >
                {score}
            </span>
            <span className={cn('inline-flex items-center rounded-md px-1.5 py-0.5 text-[10.5px] font-bold', RA_TONE_CHIP[tone])}>
                {cap(level)}
            </span>
        </span>
    );
}

export function StatusChip({ status }: { status: RaStatus }) {
    const { label, tone } = statusMeta(status);
    return (
        <span className={cn('inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[11px] font-semibold', RA_TONE_CHIP[tone])}>
            <span className={cn('h-1.5 w-1.5 rounded-full', RA_TONE_DOT[tone])} />
            {label}
        </span>
    );
}

export function AttachChip({ attached }: { attached: AttachedTo }) {
    const { icon: Icon, tone } = attachMeta(attached.type);
    return (
        <span className={cn('inline-flex max-w-[180px] items-center gap-1.5 rounded-md px-2 py-1 text-[11px] font-semibold', RA_TONE_CHIP[tone])}>
            <Icon className="h-3 w-3 shrink-0" />
            <span className="truncate">{attached.name}</span>
        </span>
    );
}

export function AcceptableBadge({ value }: { value: boolean | null }) {
    if (value === null) return <span className="text-muted-foreground">—</span>;
    const tone: RaTone = value ? 'success' : 'critical';
    return (
        <span className={cn('inline-flex items-center rounded-md px-2 py-0.5 text-[10.5px] font-bold', RA_TONE_CHIP[tone])}>
            {value ? 'Yes' : 'No'}
        </span>
    );
}

/** Review-due flag: red overdue / amber due-soon / plain date / em-dash. */
export function ReviewBadge({ state, dueAt }: { state: ReviewState; dueAt: string | null }) {
    if (state.kind === 'overdue') {
        return (
            <span className={cn('inline-flex items-center gap-1 rounded-md px-2 py-1 text-[10.5px] font-bold', RA_TONE_CHIP.critical)}>
                <AlertTriangle className="h-3 w-3" /> Overdue
            </span>
        );
    }
    if (state.kind === 'soon') {
        return (
            <span className={cn('inline-flex items-center gap-1 rounded-md px-2 py-1 text-[10.5px] font-bold', RA_TONE_CHIP.warning)}>
                <Clock className="h-3 w-3" /> Due {state.days}d
            </span>
        );
    }
    if (state.kind === 'ok') {
        return <span className="text-xs text-muted-foreground">{fmtDate(dueAt)}</span>;
    }
    return <span className="text-muted-foreground">—</span>;
}
