import { DiscardDraftDialog } from '@/components/governance/DiscardDraftDialog';
import { SettingsModal } from '@/components/settings/settings-modal';
import type { IconType } from '@/components/wizard/primitives';
import { WizardShell, WizardStepPane } from '@/components/wizard/shell';
import { cn } from '@/lib/utils';
import {
    Children,
    Fragment,
    cloneElement,
    isValidElement,
    useEffect,
    useRef,
    useState,
    type ReactNode,
} from 'react';

export type MedsWizardStep = {
    key: string;
    label: string;
    blurb: string;
    icon: IconType;
};

/** Medication workflows use the canonical responsive wizard; their own validation
 * and clinical command handlers continue to own step transitions and saves. */
export function MedsWizardDialog({
    open,
    onClose,
    title,
    description,
    railIcon,
    railTitle,
    railSubtitle,
    railFooter,
    steps,
    stepIndex,
    onStepClick,
    footer,
    children,
    formState,
    completeness,
    success,
    sequential = true,
}: {
    open: boolean;
    onClose: () => void;
    title: string;
    description: string;
    railIcon: IconType;
    railTitle: string;
    railSubtitle: string;
    railFooter?: ReactNode;
    steps: MedsWizardStep[];
    stepIndex: number;
    onStepClick: (index: number) => void;
    footer: ReactNode;
    children: ReactNode;
    sequential?: boolean;
    completeness?: { completed: number; total: number };
    success?: ReactNode;
    /** The owning form supplies real dirtiness and request state. Successful saves close through the owner. */
    formState?: {
        isDirty: boolean;
        processing: boolean;
        errors?: Record<string, string | undefined>;
    };
}) {
    const [discard, setDiscard] = useState(false);
    const errorRef = useRef<HTMLDivElement>(null);
    const errors = Object.values(formState?.errors ?? {}).filter(
        (error): error is string => !!error,
    );
    const errorKey = errors.join('\n');
    useEffect(() => {
        if (errorKey) errorRef.current?.focus();
    }, [errorKey]);
    const errorSummary = errors.length ? (
        <div
            ref={errorRef}
            tabIndex={-1}
            role="alert"
            className="mb-4 rounded-lg border border-status-critical/30 bg-status-critical-bg p-3 text-sm text-status-critical"
        >
            <p className="font-semibold">Changes were not saved</p>
            <ul className="mt-1 list-disc pl-5">
                {errors.map((error, index) => (
                    <li key={index}>{error}</li>
                ))}
            </ul>
        </div>
    ) : null;
    const requestClose = () => {
        if (success) {
            onClose();
            return;
        }
        if (formState?.processing) return;
        if (formState?.isDirty) setDiscard(true);
        else onClose();
    };
    const footerItems = Children.toArray(
        isValidElement<{ children?: ReactNode }>(footer) &&
            footer.type === Fragment
            ? footer.props.children
            : footer,
    );
    // The legacy footer contract passes the same close callback to Cancel. Keep
    // that route consistent with Escape and the shell's close control, while
    // preserving Back, submit and any recovery-owned callbacks unchanged.
    const guardFooter = (item: ReactNode): ReactNode => {
        if (
            !isValidElement<{
                onClick?: () => void;
                onCancel?: () => void;
                children?: ReactNode;
            }>(item)
        )
            return item;
        return cloneElement(item, {
            ...(item.props.onClick === onClose
                ? { onClick: requestClose }
                : {}),
            ...(item.props.onCancel === onClose
                ? { onCancel: requestClose }
                : {}),
            ...(item.props.children !== undefined
                ? { children: Children.map(item.props.children, guardFooter) }
                : {}),
        });
    };
    const guardedFooter = footerItems.map(guardFooter);
    const guard = (
        <DiscardDraftDialog
            open={discard}
            description="These medication changes have not been saved."
            onKeepEditing={() => setDiscard(false)}
            onDiscard={() => {
                setDiscard(false);
                onClose();
            }}
        />
    );
    if (steps.length === 1)
        return open ? (
            <>
                <SettingsModal
                    frontline
                    title={title}
                    description={description}
                    width={720}
                    onClose={requestClose}
                    footer={success ? undefined : <>{guardedFooter}</>}
                >
                    {errorSummary}
                    {success ?? children}
                    {!success && railFooter}
                </SettingsModal>
                {guard}
            </>
        ) : null;
    return (
        <>
            <WizardShell
                frontline
                open={open}
                onClose={requestClose}
                title={title}
                description={description}
                railIcon={railIcon}
                railTitle={railTitle}
                railSub={railSubtitle}
                railExtra={railFooter}
                steps={steps}
                stepIndex={stepIndex}
                onStepClick={(index) => {
                    if (!formState?.processing) onStepClick(index);
                }}
                sequential={sequential}
                pct={
                    completeness && completeness.total > 0
                        ? Math.round(
                              (completeness.completed / completeness.total) *
                                  100,
                          )
                        : undefined
                }
                success={success}
                headerLabel={!sequential ? steps[stepIndex]?.label : undefined}
                footerStart={guardedFooter[0]}
                footerEnd={guardedFooter.slice(1)}
                maxWidth="min(92vw, 1100px)"
                maxHeight="min(88vh, 860px)"
            >
                <WizardStepPane key={stepIndex}>
                    {errorSummary}
                    {children}
                </WizardStepPane>
            </WizardShell>
            {guard}
        </>
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
