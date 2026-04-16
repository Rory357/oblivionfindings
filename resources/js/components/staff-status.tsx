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
        'border-slate-300 bg-slate-100 text-slate-800 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-100',
    info:
        'border-sky-300 bg-sky-100 text-sky-900 dark:border-sky-500/40 dark:bg-sky-500/15 dark:text-sky-100',
    progress:
        'border-amber-300 bg-amber-100 text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/15 dark:text-amber-100',
    success:
        'border-emerald-300 bg-emerald-100 text-emerald-900 dark:border-emerald-500/40 dark:bg-emerald-500/15 dark:text-emerald-100',
    warning:
        'border-orange-300 bg-orange-100 text-orange-900 dark:border-orange-500/40 dark:bg-orange-500/15 dark:text-orange-100',
    danger:
        'border-red-300 bg-red-100 text-red-900 dark:border-red-500/40 dark:bg-red-500/15 dark:text-red-100',
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
