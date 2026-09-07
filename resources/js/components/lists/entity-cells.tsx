/* eslint-disable no-restricted-syntax -- Cell-library primitives are small
 * styled-native spans bound to semantic tokens (LIST_STYLE_GUIDE.md §3). */
/**
 * The Event Horizon list cell library (design_styles/LIST_STYLE_GUIDE.md).
 * Every non-identity card chip / table cell composes from these — never
 * invent a new cell per page. Status colours ride the fixed status token
 * pairs; brand accents derive from --primary.
 */
import { UserRound } from 'lucide-react';
import type { ComponentType, ReactNode } from 'react';

import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { cn } from '@/lib/utils';

type IconType = ComponentType<{ className?: string }>;

/** Neutral fact chip — muted fill, radius 8, 11.5/500, optional 10px icon. */
export function EntityChip({
    icon: Icon,
    outline = false,
    children,
}: {
    icon?: IconType;
    /** Hairline variant for secondary facts (e.g. region). */
    outline?: boolean;
    children: ReactNode;
}) {
    return (
        <span
            className={cn(
                'inline-flex h-[22px] items-center gap-1 rounded-[8px] px-2 text-[11.5px] font-medium text-muted-foreground',
                outline ? 'border border-border' : 'bg-muted',
            )}
        >
            {Icon ? <Icon className="size-2.5 shrink-0" /> : null}
            {children}
        </span>
    );
}

/** Status chip at the list radius-8 — same verified pairs as StatusBadge. */
export function EntityStatusChip({
    variant,
    icon: Icon,
    children,
    className,
}: {
    variant: StatusVariant;
    icon?: IconType;
    children: ReactNode;
    className?: string;
}) {
    return (
        <StatusBadge
            variant={variant}
            className={cn('rounded-[8px] font-semibold', className)}
        >
            {Icon ? <Icon className="size-3" /> : null}
            {children}
        </StatusBadge>
    );
}

/** Counter pill — 20px tall, radius 6, min-width 22, status pair, 11/700. */
export function CounterPill({
    tone,
    children,
}: {
    tone: 'critical' | 'warning' | 'success' | 'neutral';
    children: ReactNode;
}) {
    const pair = {
        critical: 'bg-status-critical-bg text-status-critical',
        warning: 'bg-status-warning-bg text-status-warning',
        success: 'bg-status-success-bg text-status-success',
        neutral: 'bg-muted text-muted-foreground',
    }[tone];
    return (
        <span
            className={cn(
                'inline-flex h-5 min-w-[22px] items-center justify-center rounded-[6px] px-1.5 text-[11px] font-bold tabular-nums',
                pair,
            )}
        >
            {children}
        </span>
    );
}

/**
 * Person disc — brand-tinted initials; muted person icon when empty
 * (honest empty state: never fabricate an assignee).
 */
export function PersonDisc({
    name,
    size = 24,
    icon: EmptyIcon = UserRound,
}: {
    name?: string | null;
    size?: number;
    icon?: IconType;
}) {
    if (!name) {
        return (
            <span
                style={{ width: size, height: size }}
                className="flex shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground"
            >
                <EmptyIcon className="size-3.5" />
            </span>
        );
    }
    return (
        <span
            style={{ width: size, height: size, fontSize: size * 0.38 }}
            className="flex shrink-0 items-center justify-center rounded-full bg-primary/15 font-semibold text-primary"
        >
            {initialsFromName(name)}
        </span>
    );
}

/** Person cell — disc + name; muted em-dash when unassigned. */
export function PersonCell({ name }: { name?: string | null }) {
    return (
        <span className="flex min-w-0 items-center gap-2">
            <PersonDisc name={name} size={22} />
            <span className="truncate text-[12.5px]">
                {name ?? <span className="text-muted-foreground">—</span>}
            </span>
        </span>
    );
}

/** Progress + value — 6px bar (brand or status fill) + 12/600 text. */
export function ProgressValue({
    percent,
    tone = 'brand',
    children,
}: {
    /** null renders an empty muted track (honest "no data"). */
    percent: number | null;
    tone?: 'brand' | 'warning' | 'critical' | 'success';
    children?: ReactNode;
}) {
    const fill = {
        brand: 'bg-primary',
        warning: 'bg-status-warning',
        critical: 'bg-status-critical',
        success: 'bg-status-success',
    }[tone];
    return (
        <span className="flex min-w-0 flex-col gap-1">
            {children ? (
                <span className="truncate text-xs font-semibold tabular-nums">
                    {children}
                </span>
            ) : null}
            <span className="block h-[6px] w-full overflow-hidden rounded-full bg-muted">
                {percent != null ? (
                    <span
                        className={cn('block h-full rounded-full', fill)}
                        style={{
                            width: `${Math.min(100, Math.max(percent, 3))}%`,
                        }}
                    />
                ) : null}
            </span>
        </span>
    );
}

/** Muted em-dash for honest empty values. */
export function EmptyValue() {
    return <span className="text-muted-foreground">—</span>;
}

export function initialsFromName(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);
    if (parts.length === 0) return '?';
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}
