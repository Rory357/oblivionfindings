import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

export type StatusTone =
    | 'neutral'
    | 'info'
    | 'success'
    | 'warning'
    | 'critical'
    | 'primary';

// Always pair a tinted *-bg surface with the saturated text token so the label
// stays legible — this fixes the recurring `bg-status-success text-status-success`
// (same-colour, invisible text) bug found across drivers/documents/signatures.
const TONE_CLASS: Record<StatusTone, string> = {
    neutral: 'bg-muted text-muted-foreground',
    info: 'bg-status-info-bg text-status-info',
    success: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
    primary: 'bg-primary/10 text-primary',
};

/** Default HR status → tone map. Override per-usage via the `tone` prop. */
const STATUS_TONE: Record<string, StatusTone> = {
    // generic lifecycle
    draft: 'neutral',
    pending: 'warning',
    pending_review: 'warning',
    submitted: 'info',
    in_progress: 'info',
    in_review: 'info',
    open: 'info',
    scheduled: 'info',
    active: 'success',
    approved: 'success',
    completed: 'success',
    complete: 'success',
    published: 'success',
    paid: 'success',
    posted: 'success',
    signed: 'success',
    closed: 'neutral',
    archived: 'neutral',
    locked: 'primary',
    exported: 'primary',
    // negative
    rejected: 'critical',
    declined: 'critical',
    failed: 'critical',
    expired: 'critical',
    overdue: 'critical',
    suspended: 'critical',
    cancelled: 'neutral',
    canceled: 'neutral',
    inactive: 'neutral',
    // compliance
    compliant: 'success',
    expiring: 'warning',
    expiring_soon: 'warning',
    not_started: 'neutral',
    action_required: 'critical',
};

export function statusTone(status: string): StatusTone {
    return STATUS_TONE[status?.toLowerCase?.() ?? ''] ?? 'neutral';
}

function humanise(status: string): string {
    return status
        .replace(/[_-]+/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());
}

/**
 * Legible HR status pill. Derives a tone from the status string (override with
 * `tone`) and a humanised label (override with `label`).
 */
export function StatusBadge({
    status,
    tone,
    label,
    icon,
    className,
}: {
    status: string;
    tone?: StatusTone;
    label?: ReactNode;
    icon?: ReactNode;
    className?: string;
}) {
    const resolved = tone ?? statusTone(status);
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold',
                TONE_CLASS[resolved],
                className,
            )}
        >
            {icon}
            {label ?? humanise(status)}
        </span>
    );
}

export { TONE_CLASS as STATUS_TONE_CLASS };
export default StatusBadge;
