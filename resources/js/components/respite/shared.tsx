/**
 * Shared Respite-workspace primitives: status/urgency vocab, tone-coloured
 * badges, an initials avatar, and small date helpers. Tones map onto the app's
 * --status-* design tokens so the workspace reads like the rest of the product.
 */
import { cn } from '@/lib/utils';
import type { Urgency } from './types';

export type Tone = 'success' | 'warning' | 'critical' | 'info' | 'neutral';

const TONE_CLASSES: Record<Tone, string> = {
    success: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
    info: 'bg-status-info-bg text-status-info',
    neutral: 'bg-muted text-muted-foreground',
};

export const TONE_DOT: Record<Tone, string> = {
    success: 'bg-status-success',
    warning: 'bg-status-warning',
    critical: 'bg-status-critical',
    info: 'bg-status-info',
    neutral: 'bg-muted-foreground',
};

type StatusMeta = { label: string; tone: Tone };

/** Status → label + tone across every pipeline stage (referral / request / booking / stay). */
export const STATUS_META: Record<string, StatusMeta> = {
    // referral
    received: { label: 'New', tone: 'info' },
    triaged: { label: 'Triaged', tone: 'warning' },
    accepted: { label: 'Accepted', tone: 'success' },
    declined: { label: 'Declined', tone: 'neutral' },
    // booking request
    draft: { label: 'Draft', tone: 'neutral' },
    submitted: { label: 'Submitted', tone: 'info' },
    under_review: { label: 'Under review', tone: 'warning' },
    approved: { label: 'Approved', tone: 'success' },
    rejected: { label: 'Rejected', tone: 'critical' },
    waitlisted: { label: 'Waitlisted', tone: 'warning' },
    // booking
    pending: { label: 'Pending', tone: 'warning' },
    confirmed: { label: 'Confirmed', tone: 'success' },
    in_progress: { label: 'In house', tone: 'info' },
    completed: { label: 'Completed', tone: 'neutral' },
    cancelled: { label: 'Cancelled', tone: 'neutral' },
    // stay
    admitted: { label: 'Arriving', tone: 'info' },
    active: { label: 'In house', tone: 'success' },
    extended: { label: 'Extended', tone: 'warning' },
    discharged: { label: 'Discharged', tone: 'neutral' },
};

export const URGENCY_META: Record<Urgency, StatusMeta> = {
    planned: { label: 'Planned', tone: 'neutral' },
    urgent: { label: 'Urgent', tone: 'warning' },
    crisis: { label: 'Crisis', tone: 'critical' },
};

export function statusMeta(status: string | null | undefined): StatusMeta {
    return (status && STATUS_META[status]) || { label: status ?? '—', tone: 'neutral' };
}

export function Pill({
    tone = 'neutral',
    dot = false,
    className,
    children,
}: {
    tone?: Tone;
    dot?: boolean;
    className?: string;
    children: React.ReactNode;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-0.5 text-[11px] font-semibold leading-tight',
                TONE_CLASSES[tone],
                className,
            )}
        >
            {dot ? <span className={cn('h-1.5 w-1.5 rounded-full', TONE_DOT[tone])} /> : null}
            {children}
        </span>
    );
}

export function StatusBadge({ status, dot = true }: { status: string; dot?: boolean }) {
    const m = statusMeta(status);
    return (
        <Pill tone={m.tone} dot={dot}>
            {m.label}
        </Pill>
    );
}

export function UrgencyBadge({ urgency }: { urgency: Urgency }) {
    const m = URGENCY_META[urgency] ?? URGENCY_META.planned;
    return <Pill tone={m.tone}>{m.label}</Pill>;
}

export function urgencyAccent(urgency: Urgency): string {
    if (urgency === 'crisis') return 'border-l-status-critical';
    if (urgency === 'urgent') return 'border-l-status-warning';
    return 'border-l-transparent';
}

/* ---- avatar ------------------------------------------------------------- */

export function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase() ?? '')
        .join('');
}

export function Avatar({ name, className }: { name: string; className?: string }) {
    return (
        <span
            className={cn(
                'inline-flex shrink-0 items-center justify-center rounded-full bg-primary/10 font-semibold text-primary',
                className ?? 'h-10 w-10 text-sm',
            )}
            aria-hidden="true"
        >
            {initials(name) || '—'}
        </span>
    );
}

/* ---- dates -------------------------------------------------------------- */

export function fmtDate(iso: string | null | undefined): string {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' });
}

export function fmtRange(a: string | null | undefined, b: string | null | undefined): string {
    return `${fmtDate(a)} → ${fmtDate(b)}`;
}

export function relTime(iso: string | null | undefined): string {
    if (!iso) return '';
    const then = new Date(iso).getTime();
    if (Number.isNaN(then)) return '';
    const hrs = (Date.now() - then) / 3.6e6;
    if (hrs < 1) return 'just now';
    if (hrs < 24) return `${Math.round(hrs)}h ago`;
    return `${Math.round(hrs / 24)}d ago`;
}

/** Whole nights between two ISO dates, if both present. */
export function nightsBetween(a: string | null | undefined, b: string | null | undefined): number | null {
    if (!a || !b) return null;
    const start = new Date(a).getTime();
    const end = new Date(b).getTime();
    if (Number.isNaN(start) || Number.isNaN(end)) return null;
    return Math.max(0, Math.round((end - start) / 8.64e7));
}
