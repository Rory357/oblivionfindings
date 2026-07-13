/* eslint-disable no-restricted-syntax -- The wizard shell mirrors the bespoke
 * Add-client modal chrome (stepper rail + scroll-contained body + custom
 * footer) and intentionally uses styled native controls for the rail steps and
 * close button. Every colour is a semantic design token. */
/* Shared multi-step wizard dialog chrome, extracted from the Add Client wizard
 * (resources/js/components/clients/add-client-dialog.tsx — the reference
 * contract for every popup workflow): 248px stepper rail that collapses below
 * `sm`, "Step x of y" header with close button, 3px progress strip, scrollable
 * body, muted footer band, and the green-check success pane. */
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { Check, Pencil, Sparkles, X } from 'lucide-react';
import type { ComponentType, ReactNode } from 'react';

export type WizardStep = {
    key: string;
    label: string;
    blurb: string;
    icon: ComponentType<{ className?: string }>;
};

export function WizardShell({
    open,
    onClose,
    title,
    description,
    railIcon: RailIcon,
    railTitle,
    railSub,
    steps,
    stepIndex,
    onStepClick,
    headerLabel,
    pct,
    pctLabel = 'Completeness',
    railExtra,
    footerStart,
    footerEnd,
    success,
    maxWidth = 'min(94vw, 980px)',
    maxHeight = 'min(88vh, 760px)',
    children,
}: {
    open: boolean;
    onClose: () => void;
    /** Screen-reader dialog title/description (visually hidden). */
    title: string;
    description: string;
    railIcon: ComponentType<{ className?: string }>;
    railTitle: string;
    railSub: string;
    steps: readonly WizardStep[];
    stepIndex: number;
    onStepClick: (index: number) => void;
    /** Replaces the "Step x of y · label" header line. Detail dialogs use this —
     *  their rail entries are SECTIONS, not sequential steps, so "Step 1 of 7"
     *  reads wrong; a pane title or section name goes here instead. */
    headerLabel?: string;
    pct?: number | null;
    pctLabel?: string;
    /** Extra rail content pinned below the steps (e.g. a live clinical card). */
    railExtra?: ReactNode;
    footerStart?: ReactNode;
    footerEnd?: ReactNode;
    /** When set, replaces the whole shell body (rail + steps) — success pane. */
    success?: ReactNode;
    /** Dialog width — defaults to the Add-Client 980px; pass a wider value for matrix-heavy modals. */
    maxWidth?: string;
    /** Dialog body height — defaults to 760px; pass taller (e.g. Add-Client's 860px) for step-heavy modals. */
    maxHeight?: string;
    children?: ReactNode;
}) {
    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent
                className="overflow-hidden p-0 [&>button]:hidden"
                style={{ maxWidth, width: maxWidth }}
            >
                <DialogTitle className="sr-only">{title}</DialogTitle>
                <DialogDescription className="sr-only">
                    {description}
                </DialogDescription>

                {success ? (
                    success
                ) : (
                    <div className="flex min-h-0 overflow-hidden" style={{ height: maxHeight }}>
                        {/* ── Stepper rail ── */}
                        <aside
                            data-wizard-region="rail"
                            className="hidden w-[248px] shrink-0 flex-col gap-1 overflow-y-auto border-r border-sidebar-border bg-sidebar p-4 sm:flex"
                        >
                            <div className="mb-3 flex items-center gap-2.5">
                                <span className="grid h-9 w-9 place-items-center rounded-lg bg-primary text-primary-foreground">
                                    <RailIcon className="h-5 w-5" />
                                </span>
                                <div>
                                    <div className="text-sm leading-tight font-bold">
                                        {railTitle}
                                    </div>
                                    <div className="text-[11px] text-muted-foreground">
                                        {railSub}
                                    </div>
                                </div>
                            </div>

                            {steps.map((s, i) => {
                                const active = i === stepIndex;
                                const complete = i < stepIndex;
                                const Icon = s.icon;
                                return (
                                    <button
                                        key={s.key}
                                        type="button"
                                        onClick={() => onStepClick(i)}
                                        className={cn(
                                            'flex items-center gap-2.5 rounded-md p-2 text-left transition-colors',
                                            active
                                                ? 'bg-primary/10'
                                                : 'hover:bg-accent',
                                        )}
                                    >
                                        <span
                                            className={cn(
                                                'grid h-[26px] w-[26px] shrink-0 place-items-center rounded-full text-[11px] font-bold transition-colors',
                                                active
                                                    ? 'bg-primary text-primary-foreground'
                                                    : complete
                                                      ? 'bg-status-success-bg text-status-success'
                                                      : 'bg-muted text-muted-foreground',
                                            )}
                                        >
                                            {complete ? (
                                                <Check className="h-3.5 w-3.5" />
                                            ) : (
                                                <Icon className="h-3.5 w-3.5" />
                                            )}
                                        </span>
                                        <span className="min-w-0">
                                            <span
                                                className={cn(
                                                    'block text-[13px]',
                                                    active
                                                        ? 'font-bold text-foreground'
                                                        : complete
                                                          ? 'font-semibold text-foreground'
                                                          : 'font-semibold text-muted-foreground',
                                                )}
                                            >
                                                {s.label}
                                            </span>
                                            <span className="block truncate text-[11px] text-muted-foreground">
                                                {s.blurb}
                                            </span>
                                        </span>
                                    </button>
                                );
                            })}

                            {railExtra ? (
                                <div className="mt-auto pt-4">{railExtra}</div>
                            ) : null}

                            {pct != null ? (
                                <div className={cn('pt-4', railExtra ? '' : 'mt-auto')}>
                                    <div className="mb-1.5 flex justify-between text-[11px] text-muted-foreground">
                                        <span>{pctLabel}</span>
                                        <span className="font-bold text-primary">
                                            {pct}%
                                        </span>
                                    </div>
                                    <div
                                        className="h-1.5 overflow-hidden rounded-full bg-muted"
                                        role="progressbar"
                                        aria-valuenow={pct ?? 0}
                                        aria-valuemin={0}
                                        aria-valuemax={100}
                                        aria-label={pctLabel}
                                    >
                                        <div
                                            className="h-full rounded-full bg-primary transition-[width] duration-500"
                                            style={{ width: `${pct}%` }}
                                        />
                                    </div>
                                </div>
                            ) : null}
                        </aside>

                        {/* ── Main column ── */}
                        <div className="flex min-h-0 min-w-0 flex-1 flex-col">
                            <header
                                data-wizard-region="header"
                                className="flex shrink-0 items-center justify-between border-b border-border px-5 py-3.5"
                            >
                                <div className="text-[13px] font-semibold text-muted-foreground">
                                    {headerLabel ? (
                                        <span className="text-foreground">{headerLabel}</span>
                                    ) : steps.length > 1 ? (
                                        <>
                                            Step {stepIndex + 1} of {steps.length} ·{' '}
                                            <span className="text-foreground">
                                                {steps[stepIndex]?.label}
                                            </span>
                                        </>
                                    ) : (
                                        <span className="text-foreground">{steps[stepIndex]?.label}</span>
                                    )}
                                </div>
                                <button
                                    type="button"
                                    onClick={onClose}
                                    aria-label="Close"
                                    className="grid h-8 w-8 place-items-center rounded-md text-muted-foreground hover:bg-muted"
                                >
                                    <X className="h-5 w-5" />
                                </button>
                            </header>

                            <div
                                data-wizard-region="progress"
                                className="h-[3px] shrink-0 bg-muted"
                            >
                                <div
                                    className="h-full bg-primary transition-[width] duration-300"
                                    style={{
                                        width: `${((stepIndex + 1) / steps.length) * 100}%`,
                                    }}
                                />
                            </div>

                            <div
                                data-wizard-region="body"
                                className="min-h-0 flex-1 overflow-x-hidden overflow-y-auto px-6 py-6"
                            >
                                {children}
                            </div>

                            <footer
                                data-wizard-region="footer"
                                className="flex shrink-0 items-center justify-between gap-3 border-t border-border bg-muted/30 px-5 py-3.5"
                            >
                                <div>{footerStart}</div>
                                <div className="flex items-center gap-2.5">
                                    {footerEnd}
                                </div>
                            </footer>
                        </div>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}

/** Per-step body wrapper — 300ms fade/slide-in, motion-safe only. */
export function WizardStepPane({ children }: { children: ReactNode }) {
    return (
        <div className="motion-safe:animate-in motion-safe:fade-in-0 motion-safe:slide-in-from-right-2 motion-safe:duration-300">
            {children}
        </div>
    );
}

/** Green-check success pane (Add Client contract). */
export function WizardSuccessPane({
    title,
    blurb,
    actions,
}: {
    title: string;
    blurb: ReactNode;
    actions: ReactNode;
}) {
    return (
        <div
            className="flex min-h-0 w-full flex-col items-center justify-center px-10 py-12 text-center"
            style={{ minHeight: 'min(60vh, 460px)' }}
        >
            <div className="relative mb-5">
                <span className="grid h-[76px] w-[76px] place-items-center rounded-full bg-status-success-bg text-status-success">
                    <Check className="h-10 w-10" strokeWidth={2.5} />
                </span>
                <Sparkles className="absolute -top-1.5 -right-3.5 h-5 w-5 text-primary" />
            </div>
            <h2 className="text-2xl font-bold">{title}</h2>
            <p className="mt-2 max-w-md text-sm leading-relaxed text-muted-foreground">
                {blurb}
            </p>
            <div className="mt-6 flex gap-3">{actions}</div>
        </div>
    );
}

/** Review-step card with an Edit link jumping back to the owning step. */
export function ReviewCard({
    icon: Icon,
    title,
    onEdit,
    span,
    children,
}: {
    icon: ComponentType<{ className?: string }>;
    title: string;
    onEdit?: () => void;
    span?: boolean;
    children: ReactNode;
}) {
    return (
        <div
            className={cn(
                'rounded-xl border border-border bg-card/70 p-4',
                span && 'sm:col-span-2',
            )}
        >
            <div className="mb-2 flex items-center justify-between">
                <div className="flex items-center gap-2 text-sm font-bold">
                    <Icon className="h-4 w-4 text-primary" /> {title}
                </div>
                {onEdit ? (
                    <button
                        type="button"
                        onClick={onEdit}
                        className="inline-flex items-center gap-1 text-[13px] font-semibold text-primary hover:underline"
                    >
                        <Pencil className="h-3 w-3" /> Edit
                    </button>
                ) : null}
            </div>
            <div>{children}</div>
        </div>
    );
}

/** Label/value line inside a ReviewCard — em-dash for empty values. */
export function ReviewRow({
    label,
    value,
}: {
    label: string;
    value?: ReactNode;
}) {
    const empty = value == null || value === '';
    return (
        <div className="flex justify-between gap-4 border-b border-border py-1.5 last:border-0">
            <span className="shrink-0 text-[13px] text-muted-foreground">
                {label}
            </span>
            <span className="min-w-0 text-right text-[13px] font-medium">
                {empty ? (
                    <span className="font-normal text-muted-foreground">—</span>
                ) : (
                    value
                )}
            </span>
        </div>
    );
}
