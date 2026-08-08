/* eslint-disable no-restricted-syntax -- Shared medication wizard chrome,
 * ported 1:1 from the Add Client dialog (components/clients/add-client-dialog.tsx),
 * the reference implementation for every multi-step popup workflow: 248px
 * stepper rail on bg-sidebar, header strip, 3px progress bar, scroll-contained
 * body, muted footer band. Every colour comes from semantic design tokens. */
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    WIZARD_FOOTER_CLASS,
    WIZARD_PROGRESS_BAR_CLASS,
    WIZARD_PROGRESS_TRACK_CLASS,
    WIZARD_RAIL_CLASS,
    type IconType,
} from '@/components/wizard/primitives';
import { cn } from '@/lib/utils';
import { Check, X } from 'lucide-react';
import type { ReactNode } from 'react';

export type MedsWizardStep = {
    key: string;
    label: string;
    blurb: string;
    icon: IconType;
};

export function MedsWizardDialog({
    open,
    onClose,
    title,
    description,
    railIcon: RailIcon,
    railTitle,
    railSubtitle,
    railFooter,
    steps,
    stepIndex,
    onStepClick,
    footer,
    children,
}: {
    open: boolean;
    onClose: () => void;
    /** Screen-reader dialog title/description (visually hidden). */
    title: string;
    description: string;
    railIcon: IconType;
    railTitle: string;
    railSubtitle: string;
    railFooter?: ReactNode;
    steps: MedsWizardStep[];
    stepIndex: number;
    /** Rail steps allow jumping back only; forward clicks are ignored. */
    onStepClick: (index: number) => void;
    footer: ReactNode;
    children: ReactNode;
}) {
    const cur = steps[stepIndex];

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent
                className="overflow-hidden p-0 [&>button]:hidden"
                style={{
                    maxWidth: 'min(94vw, 1080px)',
                    width: 'min(94vw, 1080px)',
                }}
            >
                <DialogTitle className="sr-only">{title}</DialogTitle>
                <DialogDescription className="sr-only">
                    {description}
                </DialogDescription>

                <div className="flex h-[min(92vh,860px)] min-h-0 overflow-hidden">
                    {/* ── Stepper rail ── */}
                    <aside className={WIZARD_RAIL_CLASS}>
                        <div className="mb-3 flex items-center gap-2.5">
                            <span className="grid h-9 w-9 place-items-center rounded-lg bg-primary text-primary-foreground">
                                <RailIcon className="h-5 w-5" />
                            </span>
                            <div>
                                <div className="text-sm leading-tight font-bold">
                                    {railTitle}
                                </div>
                                <div className="text-[11px] text-muted-foreground">
                                    {railSubtitle}
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
                                            : 'hover:bg-sidebar-accent',
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

                        {railFooter ? (
                            <div className="mt-auto pt-4">{railFooter}</div>
                        ) : null}
                    </aside>

                    {/* ── Main column ── */}
                    <div className="flex min-h-0 min-w-0 flex-1 flex-col">
                        <header className="flex shrink-0 items-center justify-between border-b border-border px-5 py-3.5">
                            <div className="text-[13px] font-semibold text-muted-foreground">
                                Step {stepIndex + 1} of {steps.length} ·{' '}
                                <span className="text-foreground">
                                    {cur?.label}
                                </span>
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

                        <div className={WIZARD_PROGRESS_TRACK_CLASS}>
                            <div
                                className={WIZARD_PROGRESS_BAR_CLASS}
                                style={{
                                    width: `${((stepIndex + 1) / steps.length) * 100}%`,
                                }}
                            />
                        </div>

                        <div className="min-h-0 flex-1 overflow-x-hidden overflow-y-auto px-6 py-6">
                            {children}
                        </div>

                        <footer className={WIZARD_FOOTER_CLASS}>
                            {footer}
                        </footer>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}

/** Key/value row used in wizard review steps. */
export function SummaryRow({
    label,
    value,
    tone,
}: {
    label: string;
    value: ReactNode;
    tone?: 'crit' | 'success';
}) {
    return (
        <div className="flex items-baseline justify-between gap-4 border-b border-border/60 py-2 last:border-0">
            <span className="text-[13px] text-muted-foreground">{label}</span>
            <span
                className={cn(
                    'text-right text-sm font-semibold',
                    tone === 'crit' && 'text-status-critical',
                    tone === 'success' && 'text-status-success',
                )}
            >
                {value}
            </span>
        </div>
    );
}

export default MedsWizardDialog;
