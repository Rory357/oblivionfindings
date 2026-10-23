import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderRail,
} from '@/components/page';
import { WizardShell } from '@/components/wizard/shell';
import { ShieldAlert, X } from 'lucide-react';
import { useEffect, useRef, type ComponentProps } from 'react';

/** Reuse the capture steps on a real page without mounting a register or dialog. */
export function SafeguardingRaisePageShell({
    title,
    description,
    onClose,
    steps,
    stepIndex,
    onStepClick,
    footerStart,
    footerEnd,
    success,
    children,
}: ComponentProps<typeof WizardShell>) {
    const body = useRef<HTMLElement>(null);
    const previousStep = useRef(stepIndex);
    useEffect(() => {
        if (previousStep.current !== stepIndex) body.current?.focus();
        previousStep.current = stepIndex;
    }, [stepIndex]);
    return (
        <div className="frontline-dialog flex min-w-0 flex-col gap-5">
            <PageHeader
                frontline
                icon={ShieldAlert}
                title={title}
                wrapTitle
                subline={
                    success
                        ? description
                        : `Step ${stepIndex + 1} of ${steps.length} · ${steps[stepIndex].label}`
                }
                actions={
                    <PageHeaderGlassButton icon={X} onClick={onClose}>
                        Close reporting
                    </PageHeaderGlassButton>
                }
                rail={
                    !success && (
                        <PageHeaderRail
                            ariaLabel="Reporting steps"
                            items={[...steps]}
                            value={steps[stepIndex].key}
                            onSelect={(key) =>
                                onStepClick(
                                    steps.findIndex((step) => step.key === key),
                                )
                            }
                        />
                    )
                }
            />
            <section
                ref={body}
                tabIndex={-1}
                aria-label={
                    success ? 'Concern recorded' : steps[stepIndex].label
                }
                className="min-w-0 rounded-2xl border border-border bg-card p-4 shadow-sm sm:p-6"
            >
                {success ?? children}
            </section>
            {!success && (
                <footer className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-card p-4">
                    {footerStart}
                    {footerEnd}
                </footer>
            )}
        </div>
    );
}
