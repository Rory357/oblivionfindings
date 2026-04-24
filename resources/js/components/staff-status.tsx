import * as React from 'react';
import { cva, type VariantProps } from 'class-variance-authority';

import { cn } from '@/lib/utils';
import {
    getStaffStatusEntry,
    type StaffStatusKind,
    type StaffStatusStateMap,
    type StaffStatusTone,
} from '@/lib/status-vocab';

/**
 * Tone → Tailwind class map.
 *
 * Every tone pairs a shape/icon and a text label with a colour, so none of
 * these rely on colour alone. Colours include explicit `dark:` variants so the
 * pill is readable on both themes without a design-token detour.
 */
const toneClasses: Record<StaffStatusTone, string> = {
    neutral:
        'border-border bg-muted text-foreground dark:border-border dark:bg-muted/60 dark:text-foreground',
    info:
        'border-status-info/30 bg-status-info-bg text-status-info dark:border-status-info/40 dark:bg-status-info-bg dark:text-status-info',
    progress:
        'border-status-warning/30 bg-status-warning-bg text-status-warning dark:border-status-warning/40 dark:bg-status-warning-bg dark:text-status-warning',
    success:
        'border-status-success/30 bg-status-success-bg text-status-success dark:border-status-success/40 dark:bg-status-success-bg dark:text-status-success',
    warning:
        'border-status-warning/30 bg-status-warning-bg text-status-warning dark:border-status-warning/40 dark:bg-status-warning-bg dark:text-status-warning',
    danger:
        'border-status-critical/30 bg-status-critical-bg text-status-critical dark:border-status-critical/40 dark:bg-status-critical-bg dark:text-status-critical',
};

const staffStatusVariants = cva(
    // Pill: icon + text, mobile-readable, touch-friendly, never icon-only.
    'inline-flex items-center gap-1.5 rounded-full border font-medium whitespace-nowrap align-middle',
    {
        variants: {
            size: {
                sm: 'px-2 py-0.5 text-xs [&_svg]:size-3',
                md: 'px-2.5 py-1 text-sm [&_svg]:size-3.5',
                lg: 'px-3 py-1.5 text-sm [&_svg]:size-4',
            },
        },
        defaultVariants: {
            size: 'md',
        },
    },
);

export type StaffStatusSize = NonNullable<
    VariantProps<typeof staffStatusVariants>['size']
>;

type StaffStatusBaseProps = {
    /** Optional extra classes. */
    className?: string;
    /** Pill size. Defaults to `md`. */
    size?: StaffStatusSize;
    /** Override the default tooltip text (native `title` attribute). */
    title?: string;
    /**
     * Optional explicit accessible label. Defaults to the plain-language
     * worker-facing label (e.g. "Needs your changes").
     */
    'aria-label'?: string;
};

/**
 * Strongly-typed props: `state` must match the kind.
 *
 * e.g. `<StaffStatus kind="shift" state="in_progress" />` is valid,
 *      `<StaffStatus kind="shift" state="given" />` is a type error.
 */
type StaffStatusKindProps = {
    [K in StaffStatusKind]: { kind: K; state: StaffStatusStateMap[K] };
}[StaffStatusKind];

export type StaffStatusProps = StaffStatusBaseProps & StaffStatusKindProps;

/**
 * `<StaffStatus>` — worker-facing status pill.
 *
 * Renders **icon + text + colour** for a known (kind, state) pair. Falls back
 * to a neutral pill if the pair is unknown, so it never crashes on unexpected
 * input.
 *
 * Do not use this primitive for manager / admin surfaces — they have their own
 * richer badges. Use it for frontline/staff surfaces (My Day, clock/shift,
 * meds, timesheets, incidents) where clarity matters more than fidelity to
 * backend status names.
 */
export function StaffStatus({
    kind,
    state,
    size,
    className,
    title,
    'aria-label': ariaLabel,
}: StaffStatusProps) {
    const entry = getStaffStatusEntry(kind, state);

    // Defensive fallback: render the raw state as a neutral pill rather than
    // throw, so a backend surprise doesn't break the page.
    if (!entry) {
        const fallbackLabel = String(state).replace(/_/g, ' ');
        return (
            <span
                className={cn(
                    staffStatusVariants({ size }),
                    toneClasses.neutral,
                    className,
                )}
                title={title ?? fallbackLabel}
                aria-label={ariaLabel ?? fallbackLabel}
            >
                <span className="capitalize">{fallbackLabel}</span>
            </span>
        );
    }

    const Icon = entry.icon;
    const accessibleLabel = ariaLabel ?? entry.label;

    return (
        <span
            className={cn(
                staffStatusVariants({ size }),
                toneClasses[entry.tone],
                className,
            )}
            title={title ?? entry.label}
            aria-label={accessibleLabel}
            data-staff-status-kind={kind}
            data-staff-status-state={state}
            data-staff-status-tone={entry.tone}
        >
            {/*
             * Icon is decorative — the plain-language label already carries
             * the meaning, so assistive tech reads the label only.
             */}
            <Icon aria-hidden="true" />
            <span>{entry.label}</span>
        </span>
    );
}

export default StaffStatus;
