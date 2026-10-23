import { PageHeader, PageHeaderGlassButton, PageLayout } from '@/components/page';
import { PageHeaderStages } from '@/components/page/page-header-strip';
import { WizardShell } from '@/components/wizard/shell';
import { ShieldAlert, X } from 'lucide-react';
import { useEffect, useRef, type ComponentProps } from 'react';

/**
 * Raise a safeguarding concern as a full page (approved drawings pg-hsn-23 …
 * 23e). The capture steps, fields, checks and footer are the wizard's own and
 * stay unchanged; this shell only lays them out as a page: the steps as the
 * header's stage line (each step a button, so any step can be reopened), the
 * current step in one card, Back / Next / Raise concern underneath. No
 * register or dialog is mounted, so a reporter without register access can use
 * it.
 */
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
        <PageLayout
            hero={
                <PageHeader
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
                    strip={
                        success ? undefined : (
                            <PageHeaderStages
                                ariaLabel="Reporting steps"
                                steps={steps.map((step, i) => ({
                                    label: step.label,
                                    state:
                                        i < stepIndex
                                            ? 'done'
                                            : i === stepIndex
                                              ? 'now'
                                              : 'todo',
                                }))}
                                onSelect={onStepClick}
                            />
                        )
                    }
                />
            }
        >
            <div className="grid min-w-0 gap-5">
                <section
                    ref={body}
                    tabIndex={-1}
                    aria-label={success ? 'Concern recorded' : steps[stepIndex].label}
                    className="sg-surface min-w-0 p-5 outline-none focus-visible:ring-2 focus-visible:ring-ring sm:p-6"
                >
                    {success ?? children}
                </section>
                {!success && (
                    <footer className="sg-surface flex flex-wrap items-center justify-between gap-3 px-5 py-3.5">
                        {footerStart}
                        {footerEnd}
                    </footer>
                )}
            </div>
        </PageLayout>
    );
}
