import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
} from '@/components/wizard/shell';
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { CheckCircle2, FileText, Loader2, type LucideIcon } from 'lucide-react';
import {
    useEffect,
    useRef,
    useState,
    type FormEvent,
    type ReactNode,
} from 'react';

export type SpecialistReview = { label: string; value: ReactNode }[];
type CurrentRecord = { version: number; review: SpecialistReview };

/** Shared details/review flow; the page retains its canonical command and buffer. */
export function SpecialistCommandWizard({
    open,
    onClose,
    onDiscard,
    title,
    description,
    icon,
    submitLabel,
    processing,
    dirty,
    errors,
    review,
    onSubmit,
    children,
    allowed = true,
    expectedVersion,
    current,
    onVersionReviewed,
    success,
}: {
    open: boolean;
    onClose: () => void;
    onDiscard: () => void;
    title: string;
    description: string;
    icon: LucideIcon;
    submitLabel: string;
    processing: boolean;
    dirty: boolean;
    errors: Record<string, string>;
    review: SpecialistReview;
    onSubmit: (event: FormEvent) => void;
    children: ReactNode;
    allowed?: boolean;
    expectedVersion?: number;
    current?: CurrentRecord;
    onVersionReviewed?: (version: number) => void;
    success?: ReactNode;
}) {
    const actorId = usePage<SharedData>().props.auth.user.id;
    const [originActor] = useState(actorId);
    const permitted = allowed && originActor === actorId;
    const handlers = useRef({ onDiscard, onClose });
    useEffect(() => {
        handlers.current = { onDiscard, onClose };
    }, [onDiscard, onClose]);
    const [step, setStep] = useState(0);
    const [discarding, setDiscarding] = useState(false);
    const [reviewingCurrent, setReviewingCurrent] = useState(false);
    const form = useRef<HTMLFormElement>(null);
    const errorSummary = useRef<HTMLDivElement>(null);
    const returnFocus = useRef<HTMLElement | null>(null);
    const stale =
        Boolean(errors.expected_version) ||
        (current !== undefined &&
            expectedVersion !== undefined &&
            current.version !== expectedVersion);
    const hasSuccess = Boolean(success);
    useEffect(() => {
        if (!permitted) {
            handlers.current.onDiscard();
            handlers.current.onClose();
        }
    }, [permitted]);
    useEffect(() => {
        if (!open) {
            setStep(0);
            setDiscarding(false);
            setReviewingCurrent(false);
        }
    }, [open]);
    useEffect(() => {
        if (hasSuccess) setStep(0);
    }, [hasSuccess]);
    useEffect(() => {
        setReviewingCurrent(false);
    }, [current?.version]);
    useEffect(() => {
        if (Object.keys(errors).length > 0 || stale) {
            setStep(0);
            errorSummary.current?.focus();
        }
    }, [errors, stale]);
    const close = () => {
        if (processing) return;
        if (success) {
            onClose();
            return;
        }
        if (dirty) {
            returnFocus.current =
                document.activeElement instanceof HTMLElement
                    ? document.activeElement
                    : null;
            setDiscarding(true);
        } else onClose();
    };
    const changeStep = (next: number) => {
        if (processing || !permitted) return;
        if (next === 0 || form.current?.reportValidity()) setStep(next);
    };
    if (!permitted) return null;
    return (
        <>
            <WizardShell
                open={open}
                onClose={close}
                title={title}
                description={description}
                railIcon={icon}
                railTitle={title}
                railSub="Details and review"
                steps={[
                    {
                        key: 'details',
                        label: 'Details',
                        blurb: 'Record the work and its impact',
                        icon: FileText,
                        disabled: processing,
                    },
                    {
                        key: 'review',
                        label: 'Review',
                        blurb: 'Check the details before saving',
                        icon: CheckCircle2,
                        disabled: processing,
                    },
                ]}
                stepIndex={step}
                onStepClick={changeStep}
                success={success}
                footerStart={
                    <Button
                        variant="outline"
                        disabled={processing}
                        onClick={close}
                    >
                        Cancel
                    </Button>
                }
                footerEnd={
                    <>
                        {step === 1 && (
                            <Button
                                variant="outline"
                                disabled={processing}
                                onClick={() => changeStep(0)}
                            >
                                Back
                            </Button>
                        )}
                        <Button
                            disabled={processing || (step === 1 && stale)}
                            onClick={() =>
                                step === 0
                                    ? changeStep(1)
                                    : form.current?.requestSubmit()
                            }
                        >
                            {processing ? (
                                <>
                                    <Loader2
                                        className="size-4 animate-spin"
                                        aria-hidden
                                    />
                                    Saving…
                                </>
                            ) : step === 0 ? (
                                'Review'
                            ) : (
                                submitLabel
                            )}
                        </Button>
                    </>
                }
            >
                <form
                    ref={form}
                    onSubmit={(event) => {
                        if (step === 0) {
                            event.preventDefault();
                            changeStep(1);
                            return;
                        }
                        if (processing || stale || !permitted) {
                            event.preventDefault();
                            return;
                        }
                        onSubmit(event);
                    }}
                >
                    {(Object.keys(errors).length > 0 || stale) && (
                        <div
                            ref={errorSummary}
                            tabIndex={-1}
                            role="alert"
                            className="mb-4 rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm"
                        >
                            <p className="font-semibold">
                                {stale
                                    ? 'This record has changed'
                                    : 'Check these details'}
                            </p>
                            <ul className="mt-2 list-disc pl-5">
                                {Object.entries(errors)
                                    .filter(
                                        ([key]) => key !== 'expected_version',
                                    )
                                    .map(([key, message]) => (
                                        <li key={key}>{message}</li>
                                    ))}
                            </ul>
                            {stale && (
                                <>
                                    <p className="mt-2">
                                        Your changes are still here. Review the
                                        current record before applying them
                                        again.
                                    </p>
                                    {current && onVersionReviewed && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="mt-3"
                                            disabled={processing}
                                            onClick={() => {
                                                if (
                                                    current.version ===
                                                    expectedVersion
                                                )
                                                    router.reload();
                                                else setReviewingCurrent(true);
                                            }}
                                        >
                                            {current.version === expectedVersion
                                                ? 'Reload current record'
                                                : 'Review current record'}
                                        </Button>
                                    )}
                                </>
                            )}
                        </div>
                    )}
                    {stale &&
                        reviewingCurrent &&
                        current &&
                        current.version !== expectedVersion &&
                        onVersionReviewed && (
                            <section
                                aria-label="Current record"
                                className="mb-5 space-y-3"
                            >
                                <ReviewCard
                                    icon={FileText}
                                    title="Current saved record"
                                >
                                    <Rows items={current.review} />
                                </ReviewCard>
                                <p className="text-sm text-muted-foreground">
                                    Compare these details with your proposal.
                                    Keeping your changes does not save them.
                                </p>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={processing}
                                    onClick={() => {
                                        onVersionReviewed(current.version);
                                        setReviewingCurrent(false);
                                        setStep(0);
                                    }}
                                >
                                    Keep my changes against this version
                                </Button>
                            </section>
                        )}
                    <fieldset
                        disabled={processing}
                        hidden={step !== 0}
                        className="min-w-0 space-y-4"
                    >
                        <WizardStepPane>{children}</WizardStepPane>
                    </fieldset>
                    {step === 1 && (
                        <WizardStepPane>
                            <section aria-label="Review details">
                                <ReviewCard
                                    icon={FileText}
                                    title="Your proposed details"
                                    onEdit={() => changeStep(0)}
                                >
                                    <Rows items={review} />
                                </ReviewCard>
                            </section>
                        </WizardStepPane>
                    )}
                </form>
            </WizardShell>
            <ConfirmDialog
                open={discarding}
                onClose={() => setDiscarding(false)}
                title="Discard these details?"
                description="Your unsaved changes will be removed."
                confirmText="Discard changes"
                onConfirm={() => {
                    if (!processing) {
                        onDiscard();
                        onClose();
                    }
                }}
                onCloseAutoFocus={(event) => {
                    event.preventDefault();
                    returnFocus.current?.focus();
                }}
            />
        </>
    );
}

function Rows({ items }: { items: SpecialistReview }) {
    return (
        <>
            {items.map((item) => (
                <ReviewRow
                    key={item.label}
                    label={item.label}
                    value={
                        <span className="break-words whitespace-pre-wrap">
                            {item.value === '' ||
                            item.value === null ||
                            item.value === undefined
                                ? 'Not provided'
                                : item.value}
                        </span>
                    }
                />
            ))}
        </>
    );
}

export const SpecialistCreateWizard = SpecialistCommandWizard;

export function specialistReviewNames(
    ids: number[],
    records: {
        id: number;
        name?: string;
        reference?: string | null;
        title?: string;
    }[],
): string {
    const byId = new Map(records.map((record) => [record.id, record]));
    return ids.length === 0
        ? 'None selected'
        : ids
              .map((id) => {
                  const record = byId.get(id);
                  return record
                      ? [record.reference, record.name ?? record.title]
                            .filter(Boolean)
                            .join(' · ')
                      : 'Record no longer available';
              })
              .join('\n');
}
