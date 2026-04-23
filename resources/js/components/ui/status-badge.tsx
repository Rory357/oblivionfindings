import * as React from 'react';
import { cn } from '@/lib/utils';
import { getStatusColor } from '@/lib/status-colors';

/**
 * Unified status pill. Source of truth for every "this thing is
 * approved / pending / critical / …" badge.
 *
 * Colours come from semantic tokens (--status-success / -warning /
 * -critical / -info / --muted) defined in resources/css/app.css, so
 * re-branding propagates here automatically.
 *
 * Two usage styles:
 *   - Explicit variant:  <StatusBadge variant="success">Approved</StatusBadge>
 *   - Status-driven:      <StatusBadge status={record.status} />
 *                        (label auto-formatted from the key)
 */

export type StatusVariant =
    | 'success'
    | 'warning'
    | 'critical'
    | 'info'
    | 'neutral';

const VARIANT_CLASSES: Record<StatusVariant, string> = {
    success: 'bg-status-success-bg text-status-success border-status-success/30',
    warning: 'bg-status-warning-bg text-status-warning border-status-warning/30',
    critical: 'bg-status-critical-bg text-status-critical border-status-critical/30',
    info: 'bg-status-info-bg text-status-info border-status-info/30',
    neutral: 'bg-muted text-muted-foreground border-border',
};

export interface StatusBadgeProps
    extends React.HTMLAttributes<HTMLSpanElement> {
    /** Explicit severity variant — wins if set. */
    variant?: StatusVariant;
    /** Status key — mapped via status-colors.ts. */
    status?: string;
    /** Override the auto-formatted label when using `status`. */
    label?: string;
    size?: 'sm' | 'md';
}

function formatStatus(raw: string): string {
    return raw
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());
}

export function StatusBadge({
    variant,
    status,
    label,
    size = 'md',
    className,
    children,
    ...rest
}: StatusBadgeProps) {
    const classes = variant
        ? VARIANT_CLASSES[variant]
        : status
          ? getStatusColor(status)
          : VARIANT_CLASSES.neutral;

    const sizing =
        size === 'sm'
            ? 'px-1.5 py-0.5 text-[10px] font-semibold'
            : 'px-2.5 py-0.5 text-xs font-medium';

    const content =
        children ?? label ?? (status ? formatStatus(status) : null);

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-full border',
                sizing,
                classes,
                className,
            )}
            {...rest}
        >
            {content}
        </span>
    );
}
