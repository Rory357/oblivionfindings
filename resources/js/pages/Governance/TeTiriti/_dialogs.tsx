/**
 * Te Tiriti o Waitangi framework dialogs (design_styles/POPUP_STYLE_GUIDE.md):
 *
 *   - TeTiritiObligationWizardDialog — the entity add/edit wizard
 *     (WizardShell); Add and Edit share the same steps, prefilled on edit.
 *   - TeTiritiObligationDetailDialog — read-only detail viewer with an Edit
 *     hand-off for managers.
 *
 * Fields mirror TeTiritiController::store / update.
 */
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    InfoCard,
    StepHead,
    TilePicker,
} from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/wizard/shell';
import { formatDateOnly } from '@/lib/datetime';
import { useForm } from '@inertiajs/react';
import {
    Check,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    CircleDashed,
    ClipboardList,
    Handshake,
    Landmark,
    Loader2,
    Pencil,
    Plus,
    Scale,
    Shield,
    Sparkles,
    Timer,
    Users,
    Waypoints,
    type LucideIcon,
} from 'lucide-react';
import { useMemo, useState } from 'react';

export interface Principle {
    value: string;
    label: string;
}

export interface TeTiritiObligation {
    id: number;
    principle: string;
    title: string;
    description: string;
    implementation_status: string;
    evidence_notes: string | null;
    target_date: string | null;
}

const PRINCIPLE_ICONS: Record<string, LucideIcon> = {
    partnership: Handshake,
    participation: Users,
    protection: Shield,
    equity: Scale,
    options: Waypoints,
};

export function principleIcon(value: string): LucideIcon {
    return PRINCIPLE_ICONS[value] ?? Landmark;
}

function PrincipleGlyph({ principle }: { principle: string }) {
    switch (principle) {
        case 'partnership':
            return <Handshake className="size-4" />;
        case 'participation':
            return <Users className="size-4" />;
        case 'protection':
            return <Shield className="size-4" />;
        case 'equity':
            return <Scale className="size-4" />;
        case 'options':
            return <Waypoints className="size-4" />;
        default:
            return <Landmark className="size-4" />;
    }
}

export const IMPLEMENTATION_STATUSES = [
    {
        key: 'not_started',
        label: 'Not started',
        description: 'Commitment recorded; no action yet.',
        icon: CircleDashed,
    },
    {
        key: 'in_progress',
        label: 'In progress',
        description: 'Actions under way.',
        icon: Timer,
    },
    {
        key: 'implemented',
        label: 'Implemented',
        description: 'Delivered and evidenced.',
        icon: CheckCircle2,
    },
    {
        key: 'embedded',
        label: 'Embedded',
        description: 'Part of everyday practice.',
        icon: Sparkles,
    },
];

export function implementationStatusLabel(status: string): string {
    return (
        IMPLEMENTATION_STATUSES.find((s) => s.key === status)?.label ??
        status.replace(/_/g, ' ')
    );
}

export function implementationStatusVariant(status: string): StatusVariant {
    switch (status) {
        case 'embedded':
            return 'success';
        case 'implemented':
            return 'info';
        case 'in_progress':
            return 'warning';
        default:
            return 'neutral';
    }
}

type FlashPage = { props: { flash?: { error?: string | null } } };

function flashError(page: unknown): string | null {
    return (page as FlashPage | undefined)?.props?.flash?.error ?? null;
}

/* ------------------------------------------------------------------ */
/*  Wizard (add + edit)                                                */
/* ------------------------------------------------------------------ */

type ObligationForm = {
    principle: string;
    title: string;
    description: string;
    implementation_status: string;
    evidence_notes: string;
    target_date: string;
};

const STEPS: readonly WizardStep[] = [
    {
        key: 'principle',
        label: 'Principle',
        blurb: 'Te Tiriti principle & title',
        icon: Landmark,
    },
    {
        key: 'commitment',
        label: 'Commitment',
        blurb: 'What it means & progress',
        icon: ClipboardList,
    },
    {
        key: 'evidence',
        label: 'Evidence',
        blurb: 'Evidence notes & target date',
        icon: CheckCircle2,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm before saving',
        icon: Check,
    },
];

const STEP_FOR_FIELD: Record<string, number> = {
    principle: 0,
    title: 0,
    description: 1,
    implementation_status: 1,
    status: 1,
    evidence_notes: 2,
    evidence: 2,
    target_date: 2,
};

function initialForm(obligation: TeTiritiObligation | null): ObligationForm {
    return {
        principle: obligation?.principle ?? 'partnership',
        title: obligation?.title ?? '',
        description: obligation?.description ?? '',
        implementation_status:
            obligation?.implementation_status ?? 'not_started',
        evidence_notes: obligation?.evidence_notes ?? '',
        target_date: obligation?.target_date
            ? obligation.target_date.slice(0, 10)
            : '',
    };
}

function validateStep(index: number, data: ObligationForm) {
    const e: Record<string, string> = {};
    if (index === 0) {
        if (!data.principle) e.principle = 'Choose a principle';
        if (!data.title.trim()) e.title = 'A title is required';
        else if (data.title.length > 255)
            e.title = 'Keep the title to 255 characters or fewer';
    }
    if (index === 1 && !data.description.trim()) {
        e.description = 'Describe the obligation';
    }
    return e;
}

export interface TeTiritiObligationWizardDialogProps {
    open: boolean;
    onClose: () => void;
    principles: Principle[];
    obligation?: TeTiritiObligation | null;
}

export function TeTiritiObligationWizardDialog(
    props: TeTiritiObligationWizardDialogProps,
) {
    return props.open ? <WizardBody {...props} /> : null;
}

function WizardBody({
    open,
    onClose,
    principles,
    obligation = null,
}: TeTiritiObligationWizardDialogProps) {
    const isEdit = obligation != null;
    const initial = useMemo(() => initialForm(obligation), [obligation]);
    const form = useForm<ObligationForm>(initial);
    const { data, setData, processing } = form;

    const [stepIndex, setStepIndex] = useState(0);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [submitError, setSubmitError] = useState<string | null>(null);
    const [done, setDone] = useState(false);
    const [confirmClose, setConfirmClose] = useState(false);

    const set = (key: keyof ObligationForm, value: string) =>
        setData((prev) => ({ ...prev, [key]: value }));
    const err = (name: string): string | undefined => {
        const server = form.errors as Record<string, string>;
        return (
            errors[name] ??
            server[name] ??
            (name === 'implementation_status' ? server.status : undefined) ??
            (name === 'evidence_notes' ? server.evidence : undefined)
        );
    };

    const cur = STEPS[stepIndex];
    const isReview = cur.key === 'review';
    const principleLabel =
        principles.find((p) => p.value === data.principle)?.label ??
        data.principle;

    const pct = useMemo(() => {
        const checks = [
            !!data.principle,
            !!data.title.trim(),
            !!data.description.trim(),
            !!data.implementation_status,
            !!data.evidence_notes.trim(),
            !!data.target_date,
        ];
        return Math.round(
            (checks.filter(Boolean).length / checks.length) * 100,
        );
    }, [data]);

    const goTo = (index: number) =>
        setStepIndex(Math.max(0, Math.min(index, STEPS.length - 1)));

    const next = () => {
        const e = validateStep(stepIndex, data);
        setErrors(e);
        if (Object.keys(e).length === 0) goTo(stepIndex + 1);
    };

    const requestClose = () => {
        if (form.isDirty && !done) {
            setConfirmClose(true);
            return;
        }
        onClose();
    };

    const resetAll = () => {
        form.clearErrors();
        setData(initialForm(null));
        setErrors({});
        setSubmitError(null);
        setStepIndex(0);
        setDone(false);
    };

    const submit = () => {
        const all = { ...validateStep(0, data), ...validateStep(1, data) };
        if (Object.keys(all).length) {
            setErrors(all);
            goTo(STEP_FOR_FIELD[Object.keys(all)[0]] ?? 0);
            return;
        }
        setErrors({});
        setSubmitError(null);

        const visit = {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page: unknown) => {
                const error = flashError(page);
                if (error) {
                    setSubmitError(error);
                    return;
                }
                setDone(true);
            },
            onError: (errs: Record<string, string>) => {
                const first = Object.keys(errs)[0];
                if (first) goTo(STEP_FOR_FIELD[first] ?? 0);
            },
        };

        if (isEdit && obligation) {
            // TeTiritiController::update does not change the principle.
            form.transform(
                (current) =>
                    ({
                        title: current.title,
                        description: current.description,
                        implementation_status: current.implementation_status,
                        evidence_notes: current.evidence_notes,
                        target_date: current.target_date || null,
                    }) as unknown as ObligationForm,
            );
            form.put(`/governance/te-tiriti/${obligation.id}`, visit);
            return;
        }

        form.transform(
            (current) =>
                ({
                    ...current,
                    target_date: current.target_date || null,
                }) as unknown as ObligationForm,
        );
        form.post('/governance/te-tiriti', visit);
    };

    const success = done ? (
        <WizardSuccessPane
            title={isEdit ? 'Obligation updated' : 'Obligation added'}
            blurb={
                <>
                    <strong>{data.title}</strong> ({principleLabel}) is{' '}
                    {isEdit ? 'updated' : 'now tracked'} as{' '}
                    {implementationStatusLabel(
                        data.implementation_status,
                    ).toLowerCase()}
                    .
                </>
            }
            actions={
                isEdit ? (
                    <Button type="button" onClick={onClose}>
                        Done
                    </Button>
                ) : (
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={resetAll}
                        >
                            <Plus className="h-4 w-4" /> Add another
                        </Button>
                        <Button type="button" onClick={onClose}>
                            Done
                        </Button>
                    </>
                )
            }
        />
    ) : undefined;

    return (
        <>
            <WizardShell
                open={open}
                onClose={requestClose}
                title={
                    isEdit
                        ? 'Edit Te Tiriti obligation'
                        : 'Add Te Tiriti obligation'
                }
                description="Record an obligation under a Te Tiriti o Waitangi principle and track its implementation."
                railIcon={Landmark}
                railTitle={isEdit ? 'Edit obligation' : 'New obligation'}
                railSub="Te Tiriti framework"
                steps={STEPS}
                stepIndex={stepIndex}
                onStepClick={goTo}
                pct={pct}
                success={success}
                footerStart={
                    stepIndex > 0 ? (
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => goTo(stepIndex - 1)}
                        >
                            <ChevronLeft className="h-4 w-4" /> Back
                        </Button>
                    ) : null
                }
                footerEnd={
                    <>
                        {submitError ? (
                            <span
                                role="alert"
                                className="max-w-xs text-xs text-status-critical"
                            >
                                {submitError}
                            </span>
                        ) : null}
                        <Button
                            type="button"
                            variant="outline"
                            onClick={requestClose}
                        >
                            Cancel
                        </Button>
                        {isReview ? (
                            <Button
                                type="button"
                                onClick={submit}
                                disabled={processing}
                            >
                                {processing ? (
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                ) : (
                                    <Check className="h-4 w-4" />
                                )}
                                {isEdit ? 'Save changes' : 'Add obligation'}
                            </Button>
                        ) : (
                            <Button type="button" onClick={next}>
                                Continue <ChevronRight className="h-4 w-4" />
                            </Button>
                        )}
                    </>
                }
            >
                {cur.key === 'principle' ? (
                    <WizardStepPane>
                        <StepHead
                            icon={Landmark}
                            title="Which principle?"
                            blurb="Choose the Te Tiriti principle this obligation gives effect to."
                        />
                        <div className="grid gap-4">
                            <Field
                                label="Principle"
                                required={!isEdit}
                                hint={isEdit ? 'set at creation' : undefined}
                                error={err('principle')}
                            >
                                {isEdit ? (
                                    <InfoCard
                                        icon={principleIcon(data.principle)}
                                    >
                                        <strong>{principleLabel}</strong> — the
                                        principle is fixed once an obligation
                                        is recorded.
                                    </InfoCard>
                                ) : (
                                    <TilePicker
                                        value={data.principle}
                                        onChange={(v) => set('principle', v)}
                                        cols={2}
                                        options={principles.map((p) => ({
                                            key: p.value,
                                            label: p.label,
                                            icon: principleIcon(p.value),
                                        }))}
                                    />
                                )}
                            </Field>
                            <Field label="Title" required error={err('title')}>
                                <Input
                                    value={data.title}
                                    maxLength={255}
                                    onChange={(e) =>
                                        set('title', e.target.value)
                                    }
                                    placeholder="e.g. Māori representation on the clinical governance committee"
                                    aria-invalid={!!err('title')}
                                />
                            </Field>
                        </div>
                    </WizardStepPane>
                ) : null}

                {cur.key === 'commitment' ? (
                    <WizardStepPane>
                        <StepHead
                            icon={ClipboardList}
                            title="What is the commitment?"
                            blurb="Describe the obligation and where implementation stands."
                        />
                        <div className="grid gap-4">
                            <Field
                                label="Description"
                                required
                                error={err('description')}
                            >
                                <Textarea
                                    rows={4}
                                    value={data.description}
                                    onChange={(e) =>
                                        set('description', e.target.value)
                                    }
                                    placeholder="What the organisation commits to, and for whom."
                                    aria-invalid={!!err('description')}
                                />
                            </Field>
                            <Field
                                label="Implementation status"
                                error={err('implementation_status')}
                            >
                                <TilePicker
                                    value={data.implementation_status}
                                    onChange={(v) =>
                                        set('implementation_status', v)
                                    }
                                    cols={2}
                                    options={IMPLEMENTATION_STATUSES}
                                />
                            </Field>
                        </div>
                    </WizardStepPane>
                ) : null}

                {cur.key === 'evidence' ? (
                    <WizardStepPane>
                        <StepHead
                            icon={CheckCircle2}
                            title="Evidence & timing"
                            blurb="Note the evidence of progress and when it should be delivered."
                        />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label="Evidence notes"
                                hint="optional"
                                span
                                error={err('evidence_notes')}
                            >
                                <Textarea
                                    rows={3}
                                    value={data.evidence_notes}
                                    onChange={(e) =>
                                        set('evidence_notes', e.target.value)
                                    }
                                    placeholder="e.g. Hui minutes, iwi partnership agreement, kaupapa Māori service review."
                                />
                            </Field>
                            <Field
                                label="Target date"
                                hint="optional"
                                error={err('target_date')}
                            >
                                <Input
                                    type="date"
                                    value={data.target_date}
                                    onChange={(e) =>
                                        set('target_date', e.target.value)
                                    }
                                />
                            </Field>
                        </div>
                    </WizardStepPane>
                ) : null}

                {isReview ? (
                    <WizardStepPane>
                        <StepHead
                            icon={Check}
                            title={isEdit ? 'Review changes' : 'Review & add'}
                            blurb="Confirm the obligation before saving it to the framework."
                        />
                        <div className="grid gap-3 sm:grid-cols-2">
                            <ReviewCard
                                icon={Landmark}
                                title="Obligation"
                                onEdit={() => goTo(0)}
                            >
                                <ReviewRow
                                    label="Principle"
                                    value={principleLabel}
                                />
                                <ReviewRow label="Title" value={data.title} />
                            </ReviewCard>
                            <ReviewCard
                                icon={ClipboardList}
                                title="Progress"
                                onEdit={() => goTo(1)}
                            >
                                <ReviewRow
                                    label="Status"
                                    value={implementationStatusLabel(
                                        data.implementation_status,
                                    )}
                                />
                                <ReviewRow
                                    label="Target date"
                                    value={
                                        data.target_date
                                            ? formatDateOnly(data.target_date)
                                            : undefined
                                    }
                                />
                            </ReviewCard>
                            <ReviewCard
                                icon={CheckCircle2}
                                title="Details"
                                span
                                onEdit={() => goTo(1)}
                            >
                                <ReviewRow
                                    label="Description"
                                    value={data.description}
                                />
                                <ReviewRow
                                    label="Evidence"
                                    value={data.evidence_notes}
                                />
                            </ReviewCard>
                        </div>
                    </WizardStepPane>
                ) : null}
            </WizardShell>

            <Dialog open={confirmClose} onOpenChange={setConfirmClose}>
                <DialogContent style={{ maxWidth: 'min(92vw, 480px)' }}>
                    <DialogHeader>
                        <DialogTitle>Discard this draft?</DialogTitle>
                        <DialogDescription>
                            {isEdit
                                ? 'Your unsaved changes to this obligation will be lost.'
                                : 'The details entered for this obligation will be lost.'}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setConfirmClose(false)}
                        >
                            Keep editing
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => {
                                setConfirmClose(false);
                                onClose();
                            }}
                        >
                            Discard
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

/* ------------------------------------------------------------------ */
/*  Read-only detail viewer                                            */
/* ------------------------------------------------------------------ */

export function TeTiritiObligationDetailDialog({
    obligation,
    principleLabel,
    onClose,
    onEdit,
}: {
    obligation: TeTiritiObligation | null;
    principleLabel: string;
    onClose: () => void;
    onEdit?: () => void;
}) {
    return (
        <Dialog open={obligation != null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto"
                style={{ maxWidth: 'min(92vw, 480px)', width: 'min(92vw, 480px)' }}
            >
                {obligation ? (
                    <>
                        <DialogHeader>
                            <DialogTitle className="flex items-center gap-2">
                                <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-[10px] bg-primary/15 text-primary">
                                    <PrincipleGlyph
                                        principle={obligation.principle}
                                    />
                                </span>
                                {obligation.title}
                            </DialogTitle>
                            <DialogDescription>{principleLabel}</DialogDescription>
                        </DialogHeader>
                        <div className="mt-2 flex flex-col gap-3 text-sm">
                            <StatusBadge
                                variant={implementationStatusVariant(
                                    obligation.implementation_status,
                                )}
                                className="w-fit"
                            >
                                {implementationStatusLabel(
                                    obligation.implementation_status,
                                )}
                            </StatusBadge>
                            <div>
                                <p className="text-caption">Description</p>
                                <p className="whitespace-pre-wrap">
                                    {obligation.description}
                                </p>
                            </div>
                            <div>
                                <p className="text-caption">Evidence</p>
                                <p className="whitespace-pre-wrap">
                                    {obligation.evidence_notes || '—'}
                                </p>
                            </div>
                            <div>
                                <p className="text-caption">Target date</p>
                                <p>
                                    {formatDateOnly(
                                        obligation.target_date?.slice(0, 10),
                                    )}
                                </p>
                            </div>
                        </div>
                        <DialogFooter className="mt-4">
                            <Button variant="outline" onClick={onClose}>
                                Close
                            </Button>
                            {onEdit ? (
                                <Button onClick={onEdit}>
                                    <Pencil className="h-4 w-4" /> Edit
                                    obligation
                                </Button>
                            ) : null}
                        </DialogFooter>
                    </>
                ) : null}
            </DialogContent>
        </Dialog>
    );
}
