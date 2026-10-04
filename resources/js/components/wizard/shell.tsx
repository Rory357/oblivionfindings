/* eslint-disable no-restricted-syntax -- The wizard shell mirrors the bespoke
 * Add-client modal chrome (stepper rail + scroll-contained body + custom
 * footer) and intentionally uses styled native controls for the rail steps and
 * close button. Every colour is a semantic design token. */
/* Shared multi-step wizard dialog chrome, extracted from the Add Client wizard
 * (resources/js/components/clients/add-client-dialog.tsx — the reference
 * contract for every popup workflow): 248px stepper rail (from a 1024 CSS px
 * viewport; below that it collapses into a one-line top stepper so the body
 * keeps its width at 200 % zoom), "Step x of y" header with close button, 3px
 * progress strip, scrollable body, muted footer band, and the green-check
 * success pane. */
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { useIsDesktopLg } from '@/hooks/use-mobile';
import { cn } from '@/lib/utils';
import { Check, Pencil, Sparkles, X } from 'lucide-react';
import {
    useCallback,
    useEffect,
    useRef,
    type ComponentProps,
    type ComponentType,
    type ReactNode,
    type Ref,
} from 'react';

/** On the collapsed top stepper, scroll the current step into view. */
function keepCurrentStepInView(strip: HTMLElement | null) {
    if (!strip || strip.dataset.layout !== 'strip') return;
    const current = strip.querySelector<HTMLElement>('[aria-current="step"]');
    if (!current) return;
    const s = strip.getBoundingClientRect();
    const c = current.getBoundingClientRect();
    if (c.left < s.left) strip.scrollLeft -= s.left - c.left + 12;
    else if (c.right > s.right) strip.scrollLeft += c.right - s.right + 12;
}

export type WizardStep = {
    key: string;
    label: string;
    blurb: string;
    icon: ComponentType<{ className?: string }>;
    /** Temporarily unavailable while a command or recovery owns the form. */
    disabled?: boolean;
};

export function WizardShell({
    open,
    onClose,
    onOpenAutoFocus,
    onCloseAutoFocus,
    title,
    description,
    railIcon: RailIcon,
    railTitle,
    railSub,
    steps,
    stepIndex,
    onStepClick,
    headerLabel,
    sequential = true,
    pct,
    pctLabel = 'Completeness',
    railExtra,
    footerStart,
    footerEnd,
    success,
    maxWidth,
    maxHeight = 'min(88vh, 760px)',
    children,
    bodyRef,
    frontline = false,
}: {
    open: boolean;
    onClose: () => void;
    /** Optional initial focus after the shared Dialog captures its opener. */
    onOpenAutoFocus?: ComponentProps<typeof DialogContent>['onOpenAutoFocus'];
    /** A replacement workspace may own focus after this dialog unmounts. */
    onCloseAutoFocus?: ComponentProps<typeof DialogContent>['onCloseAutoFocus'];
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
    headerLabel?: ReactNode;
    /** Detail viewers have sections rather than sequential completion steps. */
    sequential?: boolean;
    pct?: number | null;
    pctLabel?: string;
    /** Extra rail content pinned below the steps (e.g. a live clinical card). */
    railExtra?: ReactNode;
    footerStart?: ReactNode;
    footerEnd?: ReactNode;
    /** When set, replaces the whole shell body (rail + steps) — success pane. */
    success?: ReactNode;
    /** Medication dialogs use Rory's 1100px wizard token; other callers retain 980px. */
    maxWidth?: string;
    /** Dialog body height — defaults to 760px; pass taller (e.g. Add-Client's 860px) for step-heavy modals. */
    maxHeight?: string;
    children?: ReactNode;
    /** Read-only section viewers may attach their scroll spy to the shared body. */
    bodyRef?: Ref<HTMLDivElement>;
    /** Medication workflows opt in to real touch-sized form controls. */
    frontline?: boolean;
}) {
    // The rail is a column from Tailwind's `lg` (1024 CSS px). Below that —
    // 200 % zoom on a 1440 px screen is 720 CSS px — a 248px column would
    // leave the body about 385 px wide, so the rail becomes a top stepper.
    const wideRail = useIsDesktopLg();
    const railRef = useRef<HTMLElement | null>(null);
    // The dialog content mounts after this component's first commit (portal),
    // so a callback ref catches the mount and the effect catches step changes.
    const attachRail = useCallback((node: HTMLElement | null) => {
        railRef.current = node;
        keepCurrentStepInView(node);
    }, []);
    useEffect(() => {
        keepCurrentStepInView(railRef.current);
    }, [wideRail, stepIndex]);

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent
                className={cn(
                    'overflow-hidden p-0',
                    frontline && 'frontline-dialog',
                )}
                showCloseButton={false}
                style={{
                    maxWidth:
                        maxWidth ??
                        (frontline ? 'min(92vw, 1100px)' : 'min(94vw, 980px)'),
                    width:
                        maxWidth ??
                        (frontline ? 'min(92vw, 1100px)' : 'min(94vw, 980px)'),
                }}
                onOpenAutoFocus={onOpenAutoFocus}
                onCloseAutoFocus={onCloseAutoFocus}
            >
                <DialogTitle className="sr-only">{title}</DialogTitle>
                <DialogDescription className="sr-only">
                    {description}
                </DialogDescription>

                {success ? (
                    success
                ) : (
                    <div
                        className={cn(
                            'flex min-h-0 overflow-hidden',
                            !wideRail && 'flex-col',
                        )}
                        style={{ height: maxHeight }}
                    >
                        {/* ── Stepper rail: a 248px column from 1024 CSS px,
                            a one-line top stepper below (200 % zoom on a
                            1440 px screen is 720 CSS px). ── */}
                        <aside
                            ref={attachRail}
                            data-wizard-region="rail"
                            data-layout={wideRail ? 'rail' : 'strip'}
                            className={cn(
                                'shrink-0 border-border bg-muted/30 text-foreground',
                                wideRail
                                    ? 'flex w-[248px] flex-col gap-1 overflow-y-auto border-r p-4'
                                    : 'scrollbar-pretty flex items-center gap-0.5 overflow-x-auto border-b px-3',
                            )}
                        >
                            <div
                                className={cn(
                                    'flex items-center',
                                    wideRail
                                        ? 'mb-3 gap-2.5'
                                        : 'mr-1.5 shrink-0 gap-2 border-r border-border pr-3',
                                )}
                            >
                                <span
                                    className={cn(
                                        'grid place-items-center rounded-lg bg-primary-fill text-primary-fill-foreground',
                                        wideRail
                                            ? 'h-9 w-9'
                                            : 'h-7 w-7 shrink-0',
                                    )}
                                >
                                    <RailIcon
                                        className={
                                            wideRail ? 'h-5 w-5' : 'h-4 w-4'
                                        }
                                    />
                                </span>
                                <div
                                    className={wideRail ? undefined : 'min-w-0'}
                                >
                                    <div
                                        className={cn(
                                            'text-sm leading-tight font-bold',
                                            !wideRail &&
                                                'max-w-[9rem] truncate text-[13px]',
                                        )}
                                    >
                                        {railTitle}
                                    </div>
                                    <div
                                        className={cn(
                                            'text-[11px] text-muted-foreground',
                                            !wideRail && 'hidden',
                                        )}
                                    >
                                        {railSub}
                                    </div>
                                </div>
                            </div>

                            {steps.map((s, i) => {
                                const active = i === stepIndex;
                                const complete = sequential && i < stepIndex;
                                const Icon = s.icon;
                                // On the strip only the active step shows its
                                // name; the others keep it as their accessible
                                // name, visually hidden.
                                const textHidden = !wideRail && !active;
                                return (
                                    <button
                                        key={s.key}
                                        type="button"
                                        disabled={s.disabled}
                                        aria-current={
                                            active ? 'step' : undefined
                                        }
                                        onClick={() => onStepClick(i)}
                                        className={cn(
                                            'frontline-tap frontline-focus flex items-center rounded-md text-left transition-colors disabled:pointer-events-none',
                                            wideRail
                                                ? 'gap-2.5 p-2'
                                                : 'frontline-tap relative shrink-0 justify-center gap-2 px-2',
                                            active
                                                ? 'bg-primary-fill/10'
                                                : 'hover:bg-muted',
                                        )}
                                    >
                                        <span
                                            className={cn(
                                                'grid h-[26px] w-[26px] shrink-0 place-items-center rounded-full text-[11px] font-bold transition-colors',
                                                active
                                                    ? 'bg-primary-fill text-primary-fill-foreground'
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
                                        <span
                                            className={
                                                textHidden
                                                    ? 'sr-only'
                                                    : 'min-w-0'
                                            }
                                        >
                                            <span
                                                className={cn(
                                                    'block text-[13px]',
                                                    !wideRail &&
                                                        'whitespace-nowrap',
                                                    active
                                                        ? 'font-bold text-foreground'
                                                        : complete
                                                          ? 'font-semibold text-foreground'
                                                          : 'font-semibold text-muted-foreground',
                                                )}
                                            >
                                                {s.label}
                                            </span>
                                            <span
                                                className={
                                                    wideRail
                                                        ? 'block truncate text-[11px] text-muted-foreground'
                                                        : 'sr-only'
                                                }
                                            >
                                                {s.blurb}
                                            </span>
                                        </span>
                                    </button>
                                );
                            })}

                            {railExtra && wideRail ? (
                                <div className="mt-auto pt-4">{railExtra}</div>
                            ) : null}

                            {pct != null ? (
                                <div
                                    className={cn(
                                        wideRail
                                            ? cn(
                                                  'pt-4',
                                                  railExtra ? '' : 'mt-auto',
                                              )
                                            : 'ml-auto flex shrink-0 items-center gap-2 pl-3',
                                    )}
                                >
                                    <div
                                        className={cn(
                                            'flex text-[11px] text-muted-foreground',
                                            wideRail &&
                                                'mb-1.5 justify-between',
                                        )}
                                    >
                                        <span
                                            className={
                                                wideRail ? undefined : 'sr-only'
                                            }
                                        >
                                            {pctLabel}
                                        </span>
                                        <span className="font-bold text-primary">
                                            {pct}%
                                        </span>
                                    </div>
                                    <div
                                        className={cn(
                                            'h-1.5 overflow-hidden rounded-full bg-muted',
                                            !wideRail && 'w-14',
                                        )}
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
                                        <span className="text-foreground">
                                            {headerLabel}
                                        </span>
                                    ) : steps.length > 1 ? (
                                        <>
                                            Step {stepIndex + 1} of{' '}
                                            {steps.length} ·{' '}
                                            <span className="text-foreground">
                                                {steps[stepIndex]?.label}
                                            </span>
                                        </>
                                    ) : (
                                        <span className="text-foreground">
                                            {steps[stepIndex]?.label}
                                        </span>
                                    )}
                                </div>
                                {/* Keep the close target at least 44 px. */}
                                <button
                                    type="button"
                                    onClick={onClose}
                                    aria-label="Close"
                                    className="frontline-tap frontline-focus grid h-8 w-8 place-items-center rounded-md text-muted-foreground hover:bg-muted"
                                >
                                    <X className="h-5 w-5" />
                                </button>
                            </header>

                            {sequential && (
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
                            )}

                            <div
                                data-wizard-region="body"
                                ref={bodyRef}
                                className="relative min-h-0 flex-1 overflow-x-hidden overflow-y-auto px-6 py-6"
                            >
                                {children}
                                {/* The strip has no room for rail extras
                                    (running totals, balances, notes), so
                                    they follow the step content. */}
                                {railExtra && !wideRail ? (
                                    <div
                                        data-wizard-region="rail-extra"
                                        className="mt-6 border-t border-border pt-4"
                                    >
                                        <div className="max-w-sm">
                                            {railExtra}
                                        </div>
                                    </div>
                                ) : null}
                            </div>

                            <footer
                                data-wizard-region="footer"
                                className={cn(
                                    'flex shrink-0 items-center justify-between gap-3 border-t border-border bg-muted/30 px-5 py-3.5',
                                    frontline &&
                                        'flex-wrap [&_button]:max-w-full [&_button]:whitespace-normal',
                                )}
                            >
                                <div className="max-w-full min-w-0">
                                    {footerStart}
                                </div>
                                <div className="flex max-w-full min-w-0 flex-wrap items-center justify-end gap-2.5">
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
        <div className="motion-safe:animate-in motion-safe:duration-300 motion-safe:fade-in-0 motion-safe:slide-in-from-right-2">
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
                'wizard-review-card min-w-0 rounded-xl border border-border bg-card/70 p-4',
                span && 'sm:col-span-2',
            )}
        >
            <div className="mb-2 flex items-center justify-between gap-3">
                <div className="flex min-w-0 items-center gap-2 text-sm font-bold">
                    <Icon className="h-4 w-4 shrink-0 text-primary" /> {title}
                </div>
                {onEdit ? (
                    <button
                        type="button"
                        onClick={onEdit}
                        className="inline-flex shrink-0 items-center gap-1 text-[13px] font-semibold text-primary hover:underline"
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
        <div className="wizard-review-row flex justify-between gap-4 border-b border-border py-1.5 last:border-0">
            <span className="max-w-[45%] shrink-0 text-[13px] break-words text-muted-foreground">
                {label}
            </span>
            <span className="min-w-0 text-right text-[13px] font-medium break-words">
                {empty ? (
                    <span className="font-normal text-muted-foreground">—</span>
                ) : (
                    value
                )}
            </span>
        </div>
    );
}
