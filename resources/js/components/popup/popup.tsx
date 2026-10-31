/* eslint-disable no-restricted-syntax -- The pop-up kit is the one place that
 * draws pop-up chrome (DESIGN.md "Pop-up kit"): it sizes DialogContent to the
 * four approved widths and owns the header, body and footer geometry. Every
 * colour is a semantic token; the lens and horizon marks live in app.css. */
/**
 * The pop-up kit — one shared frame for every pop-up (approved by Stephan,
 * 23 Oct 2026; Control Room is the first module on it). Feature code passes
 * an icon, a title, one line, fields and the main action; it never sets
 * widths, paddings, colours or footers.
 *
 * Types (POPUP-SYSTEM.md): Form (sm 480 · md 720 · lg 900), Confirm (480),
 * Viewer (480 · 720 · 900). Wizards stay on WizardShell (1100).
 *
 * Words: sentence case; the title says what you are doing ("Assign alert");
 * confirms ask a question that names the thing ("Delete signal rule?"); the
 * main button names the action, never "Submit" or "OK"; Cancel comes first,
 * the main button last; red only when data is destroyed or a safety check is
 * loosened. Closing with typing asks the one "Discard this draft?" guard.
 */
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import {
    CircleAlert,
    CircleCheck,
    CircleHelp,
    FileWarning,
    Hash,
    Info,
    LoaderCircle,
    Trash2,
    TriangleAlert,
    X,
    type LucideIcon,
} from 'lucide-react';
import {
    useCallback,
    useState,
    type ComponentProps,
    type FormEvent,
    type ReactNode,
} from 'react';

export type PopupSize = 'sm' | 'md' | 'lg' | 'xl';

/** The only pop-up widths (DESIGN.md "Invented pop-up widths"). */
export const POPUP_WIDTH: Record<PopupSize, number> = {
    sm: 480,
    md: 720,
    lg: 900,
    xl: 1100,
};

type AutoFocusHandler = ComponentProps<typeof DialogContent>['onCloseAutoFocus'];

/* ------------------------------------------------------------------ */
/*  Frame                                                              */
/* ------------------------------------------------------------------ */

function PopupFrame({
    open,
    onRequestClose,
    size,
    role = 'dialog',
    blockOutsideClose = false,
    onCloseAutoFocus,
    className,
    testId,
    children,
}: {
    open: boolean;
    onRequestClose: () => void;
    size: PopupSize;
    role?: 'dialog' | 'alertdialog';
    /** Confirms never close on a stray click outside. */
    blockOutsideClose?: boolean;
    onCloseAutoFocus?: AutoFocusHandler;
    className?: string;
    testId?: string;
    children: ReactNode;
}) {
    const width = POPUP_WIDTH[size];
    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) onRequestClose();
            }}
        >
            <DialogContent
                role={role}
                showCloseButton={false}
                data-popup-size={size}
                data-testid={testId}
                onCloseAutoFocus={onCloseAutoFocus}
                onInteractOutside={
                    blockOutsideClose
                        ? (event) => event.preventDefault()
                        : undefined
                }
                className={cn(
                    'popup-horizon flex max-h-[88vh] flex-col gap-0 overflow-hidden rounded-[18px] border border-border bg-card p-0 text-card-foreground shadow-2xl sm:max-w-none',
                    className,
                )}
                style={{
                    width: `min(92vw, ${width}px)`,
                    maxWidth: `min(92vw, ${width}px)`,
                }}
            >
                {children}
            </DialogContent>
        </Dialog>
    );
}

/* ------------------------------------------------------------------ */
/*  Blocks                                                             */
/* ------------------------------------------------------------------ */

export type PopupTone = 'brand' | 'warning' | 'critical';

/** The round icon lens — the one brand mark at the top of a pop-up. */
export function PopupLens({
    icon: Icon,
    tone = 'brand',
    className,
}: {
    icon: LucideIcon;
    tone?: PopupTone;
    className?: string;
}) {
    return (
        <span
            aria-hidden="true"
            className={cn(
                'popup-lens',
                tone === 'critical' && 'popup-lens--critical',
                tone === 'warning' && 'popup-lens--warning',
                className,
            )}
        >
            <Icon className="size-5" />
        </span>
    );
}

/** Header: icon lens, title, one line, ✕. */
export function PopupHeader({
    icon,
    tone = 'brand',
    title,
    description,
    onClose,
    closeDisabled,
}: {
    icon: LucideIcon;
    tone?: PopupTone;
    title: string;
    description?: ReactNode;
    onClose: () => void;
    closeDisabled?: boolean;
}) {
    return (
        <div className="flex shrink-0 items-start gap-3.5 px-[22px] pt-5 pb-4">
            <PopupLens icon={icon} tone={tone} />
            <div className="min-w-0 flex-1 pt-0.5">
                <DialogTitle className="text-section-title leading-snug">
                    {title}
                </DialogTitle>
                {description ? (
                    <DialogDescription className="mt-0.5 text-sm text-muted-foreground">
                        {description}
                    </DialogDescription>
                ) : (
                    <DialogDescription className="sr-only">
                        {title}
                    </DialogDescription>
                )}
            </div>
            <button
                type="button"
                onClick={onClose}
                disabled={closeDisabled}
                aria-label="Close"
                className="frontline-hit -mt-1 -mr-1 inline-flex size-8 shrink-0 items-center justify-center rounded-[9px] text-muted-foreground transition-colors outline-none hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50"
            >
                <X className="size-[18px]" />
            </button>
        </div>
    );
}

export type PopupContextRow = {
    /** The record the pop-up acts on, e.g. "CR-2026-00418 · Reported outside the safe zone". */
    title: ReactNode;
    /** A short tag beside it, e.g. the Site. */
    tag?: ReactNode;
    /** One line under it. */
    note?: ReactNode;
    icon?: LucideIcon;
};

/** The locked record the pop-up came from — a plain line between hairlines. */
export function PopupContext({ title, tag, note, icon = Hash }: PopupContextRow) {
    const Icon = icon;
    return (
        <div
            data-slot="popup-context"
            className="flex items-start gap-2.5 border-y border-border py-2.5"
        >
            <span className="popup-lens popup-lens--sm" aria-hidden="true">
                <Icon className="size-[15px]" />
            </span>
            <div className="min-w-0 flex-1">
                <p className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm font-semibold text-foreground">
                    <span className="min-w-0 break-words">{title}</span>
                    {tag ? (
                        <span className="inline-flex items-center rounded-md border border-border px-1.5 py-px text-xs font-medium text-muted-foreground">
                            {tag}
                        </span>
                    ) : null}
                </p>
                <p className="text-caption mt-0.5">
                    {note ?? "From the record you opened — can't change here."}
                </p>
            </div>
        </div>
    );
}

export type PopupCalloutTone = 'info' | 'warning' | 'critical' | 'success';

const CALLOUT_ICON: Record<PopupCalloutTone, LucideIcon> = {
    info: Info,
    warning: TriangleAlert,
    critical: CircleAlert,
    success: CircleCheck,
};

/** A banner at the top of the body. Errors and safety notes use the fixed status pairs. */
export function PopupCallout({
    tone = 'info',
    children,
    className,
}: {
    tone?: PopupCalloutTone;
    children: ReactNode;
    className?: string;
}) {
    const Icon = CALLOUT_ICON[tone];
    return (
        <div
            role={tone === 'critical' ? 'alert' : undefined}
            className={cn(
                'flex gap-2.5 rounded-[10px] px-3.5 py-3 text-sm',
                tone === 'info' && 'bg-status-info-bg text-status-info-foreground',
                tone === 'warning' &&
                    'bg-status-warning-bg text-status-warning-foreground',
                tone === 'critical' &&
                    'bg-status-critical-bg text-status-critical-foreground',
                tone === 'success' &&
                    'bg-status-success-bg text-status-success-foreground',
                className,
            )}
        >
            <Icon
                aria-hidden="true"
                className={cn(
                    'mt-px size-[18px] shrink-0',
                    tone === 'info' && 'text-status-info',
                    tone === 'warning' && 'text-status-warning',
                    tone === 'critical' && 'text-status-critical',
                    tone === 'success' && 'text-status-success',
                )}
            />
            <div className="min-w-0 flex-1">{children}</div>
        </div>
    );
}

/** A titled group of fields inside a body. */
export function PopupSection({
    icon: Icon,
    title,
    hint,
    columns = 1,
    children,
}: {
    icon?: LucideIcon;
    title?: string;
    hint?: ReactNode;
    columns?: 1 | 2 | 3;
    children: ReactNode;
}) {
    return (
        <section
            data-slot="popup-section"
            className="grid gap-3 border-t border-border pt-4 first:border-t-0 first:pt-0"
        >
            {title ? (
                <h3 className="flex items-center gap-2 text-sm font-semibold text-foreground">
                    {Icon ? (
                        <Icon
                            aria-hidden="true"
                            className="size-4 text-muted-foreground"
                        />
                    ) : null}
                    {title}
                    {hint ? (
                        <span className="text-caption font-normal">{hint}</span>
                    ) : null}
                </h3>
            ) : null}
            <div
                className={cn(
                    'grid gap-x-[18px] gap-y-3',
                    columns === 2 && 'sm:grid-cols-2',
                    columns === 3 && 'sm:grid-cols-3',
                )}
            >
                {children}
            </div>
        </section>
    );
}

/** One labelled field: label (+ required star), hint, the control, the error. */
export function PopupField({
    label,
    htmlFor,
    required,
    hint,
    error,
    wide,
    children,
}: {
    label: string;
    htmlFor?: string;
    required?: boolean;
    hint?: ReactNode;
    error?: string;
    /** Span every column of a multi-column section. */
    wide?: boolean;
    children: ReactNode;
}) {
    return (
        <div className={cn('grid min-w-0 gap-1.5', wide && 'sm:col-span-full')}>
            <Label htmlFor={htmlFor} className="leading-snug font-semibold">
                {label}
                {required ? (
                    <span className="ml-0.5 text-status-critical" aria-hidden="true">
                        *
                    </span>
                ) : null}
                {required ? <span className="sr-only"> (required)</span> : null}
            </Label>
            {children}
            {hint ? <p className="text-caption">{hint}</p> : null}
            <InputError message={error} />
        </div>
    );
}

/** Read-only facts as label / value rows (viewers, review steps, summaries). */
export function PopupFacts({
    rows,
    className,
}: {
    rows: ReadonlyArray<readonly [string, ReactNode]>;
    className?: string;
}) {
    return (
        <dl
            className={cn(
                'grid grid-cols-[minmax(0,11rem)_minmax(0,1fr)] overflow-hidden rounded-[12px] border border-border text-sm',
                className,
            )}
        >
            {rows.map(([label, value], index) => (
                <div key={label} className="contents">
                    <dt
                        className={cn(
                            'bg-muted/40 px-3.5 py-2.5 font-medium text-muted-foreground',
                            index > 0 && 'border-t border-border',
                        )}
                    >
                        {label}
                    </dt>
                    <dd
                        className={cn(
                            'min-w-0 px-3.5 py-2.5 break-words text-foreground',
                            index > 0 && 'border-t border-border',
                        )}
                    >
                        {value}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

/** Footer band: optional left slot, then Cancel, then the main button. */
export function PopupFooter({
    start,
    children,
}: {
    start?: ReactNode;
    children: ReactNode;
}) {
    return (
        <div
            data-slot="popup-footer"
            className="flex shrink-0 flex-wrap items-center justify-end gap-2.5 border-t border-border bg-muted/40 px-[22px] py-3.5"
        >
            {start ? <div className="mr-auto flex items-center gap-2">{start}</div> : null}
            {children}
        </div>
    );
}

function PrimaryLabel({
    processing,
    processingLabel,
    children,
}: {
    processing?: boolean;
    processingLabel?: string;
    children: ReactNode;
}) {
    if (!processing) return <>{children}</>;
    return (
        <>
            <LoaderCircle
                aria-hidden="true"
                className="size-4 animate-spin motion-reduce:animate-none"
            />
            {processingLabel ?? 'Saving…'}
        </>
    );
}

/* ------------------------------------------------------------------ */
/*  The one "Discard this draft?" guard                                */
/* ------------------------------------------------------------------ */

/**
 * Close handling for a pop-up that holds typing. Esc, ✕ and Cancel do the
 * same thing; when `dirty`, they ask "Discard this draft?" first.
 */
export function usePopupClose({
    dirty,
    processing,
    onClose,
}: {
    dirty?: boolean;
    processing?: boolean;
    onClose: () => void;
}) {
    const [asking, setAsking] = useState(false);
    const requestClose = useCallback(() => {
        if (processing) return;
        if (dirty) {
            setAsking(true);
            return;
        }
        onClose();
    }, [dirty, processing, onClose]);
    return {
        requestClose,
        asking,
        keepEditing: () => setAsking(false),
        discard: () => {
            setAsking(false);
            onClose();
        },
    };
}

export function DiscardDraftConfirm({
    open,
    what,
    onKeepEditing,
    onDiscard,
}: {
    open: boolean;
    /** What was started, e.g. "Assign alert for CR-2026-00418". */
    what: ReactNode;
    onKeepEditing: () => void;
    onDiscard: () => void;
}) {
    return (
        <PopupConfirm
            open={open}
            onClose={onKeepEditing}
            onConfirm={onDiscard}
            variant="warning"
            icon={FileWarning}
            title="Discard this draft?"
            description={
                <>
                    You&apos;ve started <b className="font-semibold text-foreground">{what}</b>. If
                    you close now, what you entered is lost.
                </>
            }
            cancelLabel="Keep editing"
            confirmLabel="Discard draft"
            confirmDestructive
        />
    );
}

/* ------------------------------------------------------------------ */
/*  Form                                                               */
/* ------------------------------------------------------------------ */

export type PopupFormProps = {
    open: boolean;
    /** Called once closing is confirmed (after the discard guard, if dirty). */
    onClose: () => void;
    /** sm 480 (≤ 3 fields) · md 720 (≤ ~10) · lg 900 (sections inside). */
    size?: Exclude<PopupSize, 'xl'>;
    icon: LucideIcon;
    title: string;
    description?: ReactNode;
    context?: PopupContextRow;
    /** A banner at the top of the body. */
    callout?: { tone?: PopupCalloutTone; content: ReactNode };
    /** A save failure: shown as a critical callout; every entry is kept. */
    error?: string;
    children: ReactNode;
    submitLabel: string;
    processingLabel?: string;
    processing?: boolean;
    submitDisabled?: boolean;
    /** Red only when data is destroyed or a safety check is loosened. */
    destructive?: boolean;
    onSubmit: () => void;
    cancelLabel?: string;
    /** Left of Cancel, e.g. a ghost "Remove" in an edit form. */
    footerStart?: ReactNode;
    /** Ask "Discard this draft?" before closing. */
    dirty?: boolean;
    /** Names the draft in the discard guard; defaults to the title. */
    draftName?: ReactNode;
    onCloseAutoFocus?: AutoFocusHandler;
    testId?: string;
};

export function PopupForm({
    open,
    onClose,
    size = 'sm',
    icon,
    title,
    description,
    context,
    callout,
    error,
    children,
    submitLabel,
    processingLabel,
    processing,
    submitDisabled,
    destructive,
    onSubmit,
    cancelLabel = 'Cancel',
    footerStart,
    dirty,
    draftName,
    onCloseAutoFocus,
    testId,
}: PopupFormProps) {
    const close = usePopupClose({ dirty, processing, onClose });
    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (processing || submitDisabled) return;
        onSubmit();
    };

    return (
        <>
            <PopupFrame
                open={open && !close.asking}
                onRequestClose={close.requestClose}
                size={size}
                onCloseAutoFocus={onCloseAutoFocus}
                testId={testId}
            >
                <form
                    onSubmit={submit}
                    noValidate
                    className="flex min-h-0 flex-1 flex-col"
                >
                    <PopupHeader
                        icon={icon}
                        tone={destructive ? 'critical' : 'brand'}
                        title={title}
                        description={description}
                        onClose={close.requestClose}
                        closeDisabled={processing}
                    />
                    <div className="grid min-h-0 flex-1 content-start gap-3.5 overflow-y-auto px-[22px] pt-1 pb-[18px] scrollbar-pretty">
                        {context ? <PopupContext {...context} /> : null}
                        {error ? (
                            <PopupCallout tone="critical">{error}</PopupCallout>
                        ) : null}
                        {callout ? (
                            <PopupCallout tone={callout.tone}>
                                {callout.content}
                            </PopupCallout>
                        ) : null}
                        {children}
                    </div>
                    <PopupFooter start={footerStart}>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={close.requestClose}
                            disabled={processing}
                        >
                            {cancelLabel}
                        </Button>
                        <Button
                            type="submit"
                            variant={destructive ? 'destructive' : 'default'}
                            disabled={processing || submitDisabled}
                        >
                            <PrimaryLabel
                                processing={processing}
                                processingLabel={processingLabel}
                            >
                                {submitLabel}
                            </PrimaryLabel>
                        </Button>
                    </PopupFooter>
                </form>
            </PopupFrame>
            <DiscardDraftConfirm
                open={open && close.asking}
                what={draftName ?? title}
                onKeepEditing={close.keepEditing}
                onDiscard={close.discard}
            />
        </>
    );
}

/* ------------------------------------------------------------------ */
/*  Confirm                                                            */
/* ------------------------------------------------------------------ */

export type PopupConfirmVariant = 'plain' | 'destructive' | 'warning' | 'alert';

export type PopupConfirmProps = {
    open: boolean;
    onClose: () => void;
    onConfirm: () => void;
    /** A question that names the thing: "Delete signal rule?". */
    title: string;
    description?: ReactNode;
    /**
     * plain — yes / no; destructive — data is removed (red); warning — a
     * consequential step (amber lens); alert — one OK button.
     */
    variant?: PopupConfirmVariant;
    icon?: LucideIcon;
    context?: PopupContextRow;
    confirmLabel: string;
    cancelLabel?: string;
    /** Force the red main button (destructive variant does this already). */
    confirmDestructive?: boolean;
    processing?: boolean;
    processingLabel?: string;
    confirmDisabled?: boolean;
    error?: string;
    /** Extra fields, e.g. a reason. */
    children?: ReactNode;
    onCloseAutoFocus?: AutoFocusHandler;
    testId?: string;
};

const CONFIRM_ICON: Record<PopupConfirmVariant, LucideIcon> = {
    plain: CircleHelp,
    destructive: Trash2,
    warning: TriangleAlert,
    alert: Info,
};

export function PopupConfirm({
    open,
    onClose,
    onConfirm,
    title,
    description,
    variant = 'plain',
    icon,
    context,
    confirmLabel,
    cancelLabel = 'Cancel',
    confirmDestructive,
    processing,
    processingLabel,
    confirmDisabled,
    error,
    children,
    onCloseAutoFocus,
    testId,
}: PopupConfirmProps) {
    const danger = variant === 'destructive' || confirmDestructive;
    const tone: PopupTone =
        variant === 'destructive'
            ? 'critical'
            : variant === 'warning'
              ? 'warning'
              : 'brand';
    const requestClose = () => {
        if (!processing) onClose();
    };
    return (
        <PopupFrame
            open={open}
            onRequestClose={requestClose}
            size="sm"
            role="alertdialog"
            blockOutsideClose
            onCloseAutoFocus={onCloseAutoFocus}
            testId={testId}
        >
            <form
                noValidate
                onSubmit={(event) => {
                    event.preventDefault();
                    if (processing || confirmDisabled) return;
                    onConfirm();
                }}
                className="flex min-h-0 flex-1 flex-col"
            >
                <div className="grid min-h-0 flex-1 content-start gap-3 overflow-y-auto px-[22px] pt-[22px] pb-[18px] scrollbar-pretty">
                    <PopupLens icon={icon ?? CONFIRM_ICON[variant]} tone={tone} />
                    <DialogTitle className="text-section-title leading-snug">
                        {title}
                    </DialogTitle>
                    {description ? (
                        <DialogDescription asChild>
                            <div className="text-sm text-muted-foreground">
                                {description}
                            </div>
                        </DialogDescription>
                    ) : (
                        <DialogDescription className="sr-only">
                            {title}
                        </DialogDescription>
                    )}
                    {context ? <PopupContext {...context} /> : null}
                    {error ? (
                        <PopupCallout tone="critical">{error}</PopupCallout>
                    ) : null}
                    {children}
                </div>
                <PopupFooter>
                    {variant === 'alert' ? null : (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={requestClose}
                            disabled={processing}
                        >
                            {cancelLabel}
                        </Button>
                    )}
                    <Button
                        type="submit"
                        variant={danger ? 'destructive' : 'default'}
                        disabled={processing || confirmDisabled}
                    >
                        <PrimaryLabel
                            processing={processing}
                            processingLabel={processingLabel ?? 'Working…'}
                        >
                            {confirmLabel}
                        </PrimaryLabel>
                    </Button>
                </PopupFooter>
            </form>
        </PopupFrame>
    );
}

/* ------------------------------------------------------------------ */
/*  Viewer                                                             */
/* ------------------------------------------------------------------ */

export function PopupViewer({
    open,
    onClose,
    size = 'sm',
    icon,
    title,
    description,
    context,
    callout,
    facts,
    children,
    action,
    closeLabel = 'Close',
    onCloseAutoFocus,
    testId,
}: {
    open: boolean;
    onClose: () => void;
    size?: Exclude<PopupSize, 'xl'>;
    icon: LucideIcon;
    title: string;
    description?: ReactNode;
    context?: PopupContextRow;
    callout?: { tone?: PopupCalloutTone; content: ReactNode };
    facts?: ReadonlyArray<readonly [string, ReactNode]>;
    children?: ReactNode;
    /** One optional action after Close, e.g. "Open full record". */
    action?: ReactNode;
    closeLabel?: string;
    onCloseAutoFocus?: AutoFocusHandler;
    testId?: string;
}) {
    return (
        <PopupFrame
            open={open}
            onRequestClose={onClose}
            size={size}
            onCloseAutoFocus={onCloseAutoFocus}
            testId={testId}
        >
            <PopupHeader
                icon={icon}
                title={title}
                description={description}
                onClose={onClose}
            />
            <div className="grid min-h-0 flex-1 content-start gap-3.5 overflow-y-auto px-[22px] pt-1 pb-[18px] scrollbar-pretty">
                {context ? <PopupContext {...context} /> : null}
                {callout ? (
                    <PopupCallout tone={callout.tone}>{callout.content}</PopupCallout>
                ) : null}
                {facts?.length ? <PopupFacts rows={facts} /> : null}
                {children}
            </div>
            <PopupFooter>
                <Button type="button" variant="outline" onClick={onClose}>
                    {closeLabel}
                </Button>
                {action}
            </PopupFooter>
        </PopupFrame>
    );
}

/* ------------------------------------------------------------------ */
/*  Selection bar                                                      */
/* ------------------------------------------------------------------ */

/**
 * Act on ticked rows: the count, a way to clear, and the bulk actions. It
 * sits above the list it belongs to and stays in view while scrolling.
 */
export function PopupSelectionBar({
    count,
    noun = 'alert',
    note,
    onClear,
    children,
}: {
    count: number;
    noun?: string;
    /** e.g. "2 can be escalated". */
    note?: ReactNode;
    onClear: () => void;
    children: ReactNode;
}) {
    if (count === 0) return null;
    return (
        <div
            role="region"
            aria-label="Selected rows"
            className="popup-horizon sticky top-2 z-20 flex flex-wrap items-center gap-3 overflow-hidden rounded-[14px] border border-border bg-card px-4 py-2.5 shadow-lg"
        >
            <span className="text-sm font-semibold tabular-nums">
                {count} {noun}
                {count === 1 ? '' : 's'} selected
            </span>
            {note ? <span className="text-subtle">{note}</span> : null}
            <div className="ml-auto flex flex-wrap items-center gap-2">
                <Button type="button" variant="ghost" onClick={onClear}>
                    Clear selection
                </Button>
                {children}
            </div>
        </div>
    );
}
