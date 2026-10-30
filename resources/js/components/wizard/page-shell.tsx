import {
    PageHeader,
    PageHeaderGlassButton,
    PageLayout,
} from '@/components/page';
import { PageHeaderStages } from '@/components/page/page-header-strip';
import { WizardShell } from '@/components/wizard/shell';
import { X, type LucideIcon } from 'lucide-react';
import { useEffect, useRef, type ComponentProps } from 'react';

export type WizardPageShellProps = ComponentProps<typeof WizardShell> & {
    /** The header's ring mark; defaults to the wizard's rail icon. */
    icon?: LucideIcon;
    /** Accessible name of the card once the report is saved. */
    successLabel?: string;
    closeLabel?: string;
};

/**
 * A reporting wizard laid out as a full page (approved drawings pg-hsn-23 …
 * 23e, first used for raising a safeguarding concern). The capture steps,
 * fields, checks and footer stay the wizard's own; this shell only lays them
 * out as a page: the steps as the header's stage line (each step a button, so
 * any step can be reopened), the current step in one card, Back / Next / the
 * save action underneath. No register or dialog is mounted, so a reporter
 * without register access can use it.
 */
export function WizardPageShell({
    icon,
    successLabel = 'Report recorded',
    closeLabel = 'Close reporting',
    railIcon,
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
}: WizardPageShellProps) {
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
                    icon={icon ?? railIcon}
                    title={title}
                    wrapTitle
                    subline={
                        success
                            ? description
                            : `Step ${stepIndex + 1} of ${steps.length} · ${steps[stepIndex].label}`
                    }
                    actions={
                        <PageHeaderGlassButton icon={X} onClick={onClose}>
                            {closeLabel}
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
                    aria-label={success ? successLabel : steps[stepIndex].label}
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
