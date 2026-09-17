/**
 * Te Tiriti o Waitangi commitment dialogs (design_styles/POPUP_STYLE_GUIDE.md):
 *
 *   - TeTiritiObligationWizardDialog — add/edit wizard (WizardShell); Add and
 *     Edit share the same steps, prefilled on edit.
 *   - TeTiritiObligationDetailDialog — read-only detail viewer with an Edit
 *     hand-off for managers.
 *
 * Fields mirror TeTiritiController::store / update. Principles are the five
 * Hauora (Wai 2575) principles sent by the server.
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
    SelectInput,
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
import { useForm, usePage } from '@inertiajs/react';
import {
    Check,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    CircleDashed,
    ClipboardList,
    Crown,
    Handshake,
    Landmark,
    Loader2,
    Pencil,
    Plus,
    Scale,
    Shield,
    Sparkles,
    Timer,
    Waypoints,
    type LucideIcon,
} from 'lucide-react';
import { useMemo, useState } from 'react';

export interface Principle {
    value: string;
    label: string;
    description: string;
}

export interface CommitmentOwner {
    id: number;
    name: string;
}

export interface TeTiritiObligation {
    id: number;
    principle: string;
    title: string;
    description: string;
    implementation_status: string;
    evidence_notes: string | null;
    target_date: string | null;
    owner: CommitmentOwner | null;
}

const PRINCIPLE_ICONS: Record<string, LucideIcon> = {
    tino_rangatiratanga: Crown,
    equity: Scale,
    active_protection: Shield,
    options: Waypoints,
    partnership: Handshake,
};

export function principleIcon(value: string): LucideIcon {
    return PRINCIPLE_ICONS[value] ?? Landmark;
}

function PrincipleGlyph({ principle }: { principle: string }) {
    switch (principle) {
        case 'tino_rangatiratanga':
            return <Crown className="size-4" />;
        case 'equity':
            return <Scale className="size-4" />;
        case 'active_protection':
            return <Shield className="size-4" />;
        case 'options':
            return <Waypoints className="size-4" />;
        case 'partnership':
            return <Handshake className="size-4" />;
        default:
            return <Landmark className="size-4" />;
    }
}

/**
 * "Done" and "Part of everyday practice" are both finished states, so both
 * use the success tone.
 */
export const IMPLEMENTATION_STATUSES = [
    {
        key: 'not_started',
        label: 'Not started',
        description: 'Agreed, but no work has started.',
        icon: CircleDashed,
    },
    {
        key: 'in_progress',
        label: 'In progress',
        description: 'Work is under way.',
        icon: Timer,
    },
    {
        key: 'implemented',
        label: 'Done',
        description: 'Delivered, with evidence.',
        icon: CheckCircle2,
    },
    {
        key: 'embedded',
        label: 'Part of everyday practice',
        description: 'Done and now simply how we work.',
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
        case 'implemented':
            return 'success';
        case 'in_progress':
            return 'info';
        default:
            return 'neutral';
    }
}

export function isDelivered(status: string): boolean {
    return status === 'implemented' || status === 'embedded';
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
    owner_id: string;
    evidence_notes: string;
    target_date: string;
};

const STEPS: readonly WizardStep[] = [
    {
        key: 'principle',
        label: 'Principle',
        blurb: 'Which principle it meets',
        icon: Landmark,
    },
    {
        key: 'commitment',
        label: 'Commitment',
        blurb: 'What we commit to and who leads it',
        icon: ClipboardList,
    },
    {
        key: 'evidence',
        label: 'Evidence',
        blurb: 'Evidence and target date',
        icon: CheckCircle2,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check before saving',
        icon: Check,
    },
];

const STEP_FOR_FIELD: Record<string, number> = {
    principle: 0,
    title: 0,
    description: 1,
    implementation_status: 1,
    status: 1,
    owner_id: 1,
    evidence_notes: 2,
    evidence: 2,
    target_date: 2,
};

function initialForm(
    obligation: TeTiritiObligation | null,
    defaultOwnerId: number | null,
    defaultPrinciple: string,
): ObligationForm {
    return {
        principle: obligation?.principle ?? defaultPrinciple,
        title: obligation?.title ?? '',
        description: obligation?.description ?? '',
        implementation_status:
            obligation?.implementation_status ?? 'not_started',
        owner_id: obligation
            ? obligation.owner
                ? String(obligation.owner.id)
                : ''
            : defaultOwnerId
              ? String(defaultOwnerId)
              : '',
        evidence_notes: obligation?.evidence_notes ?? '',
        target_date: obligation?.target_date
            ? obligation.target_date.slice(0, 10)
            : '',
    };
}

function validateStep(index: number, data: ObligationForm) {
    const e: Record<string, string> = {};
    if (index === 0) {
        if (!data.principle) e.principle = 'Choose a principle.';
        if (!data.title.trim()) e.title = 'Give the commitment a name.';
        else if (data.title.length > 255)
            e.title = 'Keep the name to 255 characters or fewer.';
    }
    if (index === 1) {
        if (!data.description.trim()) {
            e.description = 'Describe what the organisation commits to.';
        }
        if (!data.owner_id) e.owner_id = 'Choose who is responsible.';
    }
    return e;
}

export interface TeTiritiObligationWizardDialogProps {
    open: boolean;
    onClose: () => void;
    principles: Principle[];
    owners: CommitmentOwner[];
    obligation?: TeTiritiObligation | null;
}

export function TeTiritiObligationWizardDialog(
    props: TeTiritiObligationWizardDialogProps,
) {
    return props.open ? <WizardBody {...props} /> : null;
}

type AuthPage = { auth?: { user?: { id?: number } | null } };

function WizardBody({
    open,
    onClose,
    principles,
    owners,
    obligation = null,
}: TeTiritiObligationWizardDialogProps) {
    const isEdit = obligation != null;
    const page = usePage();
    const currentUserId =
        (page.props as AuthPage).auth?.user?.id ?? null;
    const defaultOwnerId = owners.some((o) => o.id === currentUserId)
        ? currentUserId
        : null;
    const defaultPrinciple = principles[0]?.value ?? '';
    const initial = useMemo(
        () => initialForm(obligation, defaultOwnerId, defaultPrinciple),
        [obligation, defaultOwnerId, defaultPrinciple],
    );
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
    const ownerName =
        owners.find((o) => String(o.id) === data.owner_id)?.name ??
        obligation?.owner?.name;

    const pct = useMemo(() => {
        const checks = [
            !!data.principle,
            !!data.title.trim(),
            !!data.description.trim(),
            !!data.owner_id,
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
        setData(initialForm(null, defaultOwnerId, defaultPrinciple));
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
            onSuccess: (successPage: unknown) => {
                const error = flashError(successPage);
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

        form.transform(
            (current) =>
                ({
                    ...current,
                    owner_id: current.owner_id ? Number(current.owner_id) : null,
                    target_date: current.target_date || null,
                }) as unknown as ObligationForm,
        );

        if (isEdit && obligation) {
            form.put(`/governance/te-tiriti/${obligation.id}`, visit);
            return;
        }

        form.post('/governance/te-tiriti', visit);
    };

    const success = done ? (
        <WizardSuccessPane
            title={isEdit ? 'Commitment saved' : 'Commitment added'}
            blurb={
                <>
                    <strong>{data.title}</strong> ({principleLabel}) is{' '}
                    {isEdit ? 'saved' : 'now on the list'} as{' '}
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
                title={isEdit ? 'Edit commitment' : 'Add commitment'}
                description="Record how the organisation meets a Te Tiriti o Waitangi principle, and who leads it."
                railIcon={Landmark}
                railTitle={isEdit ? 'Edit commitment' : 'New commitment'}
                railSub="Te Tiriti o Waitangi"
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
                                {isEdit ? 'Save changes' : 'Add commitment'}
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
                            title="Which principle does it meet?"
                            blurb="The five principles come from the Waitangi Tribunal's Hauora report (Wai 2575)."
                        />
                        <div className="grid gap-4">
                            <Field
                                label="Principle"
                                required
                                error={err('principle')}
                            >
                                <TilePicker
                                    value={data.principle}
                                    onChange={(v) => set('principle', v)}
                                    cols={2}
                                    options={principles.map((p) => ({
                                        key: p.value,
                                        label: p.label,
                                        description: p.description,
                                        icon: principleIcon(p.value),
                                    }))}
                                />
                            </Field>
                            <Field label="Name" required error={err('title')}>
                                <Input
                                    value={data.title}
                                    maxLength={255}
                                    onChange={(e) =>
                                        set('title', e.target.value)
                                    }
                                    placeholder="e.g. Māori representation on the board"
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
                            title="What do we commit to?"
                            blurb="Describe the commitment, who leads it and how far it has got."
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
                                label="Who is responsible"
                                required
                                error={err('owner_id')}
                            >
                                <SelectInput
                                    value={data.owner_id}
                                    onChange={(v) => set('owner_id', v)}
                                    placeholder="Choose a person"
                                    ariaLabel="Who is responsible"
                                    options={owners.map((owner) => ({
                                        value: String(owner.id),
                                        label: owner.name,
                                    }))}
                                />
                            </Field>
                            <Field
                                label="How far it has got"
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
                            title="Evidence and timing"
                            blurb="Note what shows progress, and when it should be done."
                        />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label="Evidence"
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
                                    placeholder="e.g. Hui notes, iwi partnership agreement, kaupapa Māori service review."
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
                            title={isEdit ? 'Check your changes' : 'Check and add'}
                            blurb="Make sure the commitment is right before saving it."
                        />
                        <div className="grid gap-3 sm:grid-cols-2">
                            <ReviewCard
                                icon={Landmark}
                                title="Commitment"
                                onEdit={() => goTo(0)}
                            >
                                <ReviewRow
                                    label="Principle"
                                    value={principleLabel}
                                />
                                <ReviewRow label="Name" value={data.title} />
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
                                    label="Who is responsible"
                                    value={ownerName}
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
                                ? 'Your unsaved changes to this commitment will be lost.'
                                : 'The details entered for this commitment will be lost.'}
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
    principle,
    onClose,
    onEdit,
}: {
    obligation: TeTiritiObligation | null;
    principle: Principle | null;
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
                                    <PrincipleGlyph principle={obligation.principle} />
                                </span>
                                {obligation.title}
                            </DialogTitle>
                            <DialogDescription>
                                {principle
                                    ? `${principle.label} — ${principle.description}`
                                    : obligation.principle}
                            </DialogDescription>
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
                                <p className="text-caption">Who is responsible</p>
                                <p>{obligation.owner?.name ?? 'No one yet'}</p>
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
                                    commitment
                                </Button>
                            ) : null}
                        </DialogFooter>
                    </>
                ) : null}
            </DialogContent>
        </Dialog>
    );
}
