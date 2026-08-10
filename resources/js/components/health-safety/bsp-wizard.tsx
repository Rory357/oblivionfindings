import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    InfoCard,
    Ring,
    Segmented,
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
import { cn } from '@/lib/utils';
import {
    RESTRAINT_TYPE_META,
    RESTRAINT_TYPE_OPTIONS,
    titleCase,
    type ClientOption,
    type StaffOption,
} from '@/pages/health-safety/restraints/shared';
import { useForm, usePage } from '@inertiajs/react';
import {
    Activity,
    BookOpen,
    CalendarClock,
    CheckCircle2,
    ClipboardList,
    Plus,
    ShieldCheck,
    ThumbsDown,
    ThumbsUp,
    User,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';

type PlanForm = {
    client_id: string;
    title: string;
    developed_by: string;
    developed_at: string;
    triggers: string;
    de_escalation_strategies: string;
    approved_interventions: string;
    prohibited_interventions: string;
    restrictive_practice_type: string;
    review_date: string;
    status: string;
    notes: string;
};

const STEPS: WizardStep[] = [
    {
        key: 'person',
        label: 'Person & plan',
        blurb: 'Who & the title',
        icon: User,
    },
    {
        key: 'triggers',
        label: 'Triggers & de-escalation',
        blurb: 'Antecedents & strategies',
        icon: Activity,
    },
    {
        key: 'interventions',
        label: 'Interventions',
        blurb: 'Approved vs prohibited',
        icon: ClipboardList,
    },
    {
        key: 'practice',
        label: 'Practice & review',
        blurb: 'Type & review cadence',
        icon: CalendarClock,
    },
    {
        key: 'review',
        label: 'Review & create',
        blurb: 'Check and save',
        icon: CheckCircle2,
    },
];

const TYPE_TILES = RESTRAINT_TYPE_OPTIONS.map((o) => ({
    key: o.value,
    label: o.label,
    description: RESTRAINT_TYPE_META[o.value]?.blurb,
    icon: RESTRAINT_TYPE_META[o.value]?.icon,
}));

const APPROVED_SUGGESTIONS = [
    'Verbal de-escalation',
    'Offer a quiet space',
    'Distraction / redirection',
    'Two-person guided hold',
    'PRN as charted',
    'Sensory tools',
];
const PROHIBITED_SUGGESTIONS = [
    'Prone (face-down) restraint',
    'Seclusion beyond agreed limit',
    'Pain-compliance techniques',
    'Restriction of food or fluids',
    'Restraint as punishment',
];

const ERROR_STEP: { prefix: string; step: number }[] = [
    { prefix: 'client_id', step: 0 },
    { prefix: 'title', step: 0 },
    { prefix: 'developed', step: 0 },
    { prefix: 'triggers', step: 1 },
    { prefix: 'de_escalation_strategies', step: 1 },
    { prefix: 'approved_interventions', step: 2 },
    { prefix: 'prohibited_interventions', step: 2 },
    { prefix: 'restrictive_practice_type', step: 3 },
    { prefix: 'review_date', step: 3 },
    { prefix: 'status', step: 3 },
];

function todayLocal(): string {
    const d = new Date();
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000)
        .toISOString()
        .slice(0, 10);
}

/**
 * Behaviour support plan wizard — the Add-Client idiom on WizardShell. Five steps:
 * person & title → triggers & de-escalation → approved vs prohibited interventions →
 * restrictive-practice type & review cadence → review. Interventions are captured as
 * tag lists and stored newline-delimited (the controller splits them back to chips).
 */
export function BspWizard({
    open,
    onClose,
    clients,
    staff,
    defaultClientId,
    onOpenPlan,
}: {
    open: boolean;
    onClose: () => void;
    clients: ClientOption[];
    staff: StaffOption[];
    defaultClientId?: number | null;
    onOpenPlan?: (id: number) => void;
}) {
    const page = usePage().props as {
        flash?: { error?: string };
        detail?: { id?: number } | null;
    };
    const [stepIndex, setStepIndex] = useState(0);
    const [submitted, setSubmitted] = useState(false);

    const form = useForm<PlanForm>({
        client_id: defaultClientId ? String(defaultClientId) : '',
        title: '',
        developed_by: '',
        developed_at: todayLocal(),
        triggers: '',
        de_escalation_strategies: '',
        approved_interventions: '',
        prohibited_interventions: '',
        restrictive_practice_type: '',
        review_date: '',
        status: 'draft',
        notes: '',
    });
    const { data, setData, errors, processing } = form;

    const lastIndex = STEPS.length - 1;
    const stepKey = STEPS[stepIndex].key;

    const clientOptions = useMemo(
        () => clients.map((c) => ({ value: String(c.id), label: c.name })),
        [clients],
    );
    const staffOptions = useMemo(
        () => staff.map((s) => ({ value: String(s.id), label: s.name })),
        [staff],
    );

    const approved = useMemo(
        () => splitLines(data.approved_interventions),
        [data.approved_interventions],
    );
    const prohibited = useMemo(
        () => splitLines(data.prohibited_interventions),
        [data.prohibited_interventions],
    );

    const pct = useMemo(() => {
        const checks = [
            !!data.client_id,
            !!data.title.trim(),
            !!data.triggers.trim(),
            !!data.de_escalation_strategies.trim(),
            approved.length > 0,
            !!data.restrictive_practice_type,
            !!data.review_date,
        ];
        return Math.round(
            (checks.filter(Boolean).length / checks.length) * 100,
        );
    }, [data, approved.length]);

    const stepValid = (key: string): boolean => {
        switch (key) {
            case 'person':
                return !!data.client_id && !!data.title.trim();
            default:
                return true;
        }
    };

    const canSubmit = !!data.client_id && !!data.title.trim();

    const jumpToFirstError = (keys: string[]) => {
        for (const k of keys) {
            const m = ERROR_STEP.find((e) => k.startsWith(e.prefix));
            if (m) {
                setStepIndex(m.step);
                return;
            }
        }
    };

    const submit = () => {
        form.transform((d) => ({
            ...d,
            client_id: d.client_id ? Number(d.client_id) : null,
            developed_by: d.developed_by ? Number(d.developed_by) : null,
            developed_at: d.developed_at || null,
            review_date: d.review_date || null,
        }));
        form.post('/health-safety/restraints/plans', {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                if (!page.flash?.error) setSubmitted(true);
            },
            onError: (e) => jumpToFirstError(Object.keys(e)),
        });
    };

    const reset = () => {
        form.reset();
        form.clearErrors();
        setData((d) => ({
            ...d,
            client_id: defaultClientId ? String(defaultClientId) : '',
            developed_at: todayLocal(),
            status: 'draft',
        }));
        setStepIndex(0);
        setSubmitted(false);
    };

    const createdId = page.detail?.id;
    const success = submitted ? (
        <WizardSuccessPane
            title="Behaviour support plan created"
            blurb={
                <>
                    Saved as{' '}
                    <span className="font-semibold">
                        {titleCase(data.status)}
                    </span>
                    .{' '}
                    {data.status === 'draft'
                        ? 'Activate it from the plan detail when it’s ready to govern restrictive practice.'
                        : 'It now governs restrictive practice for this person.'}
                </>
            }
            actions={
                <>
                    {createdId && onOpenPlan ? (
                        <Button onClick={() => onOpenPlan(createdId)}>
                            Open plan
                        </Button>
                    ) : null}
                    <Button variant="outline" onClick={reset}>
                        Create another
                    </Button>
                    <Button variant="ghost" onClick={onClose}>
                        Done
                    </Button>
                </>
            }
        />
    ) : undefined;

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title="Create a behaviour support plan"
            description="Author a behaviour support plan"
            railIcon={BookOpen}
            railTitle="Behaviour support plan"
            railSub="Least-restrictive-practice plan"
            steps={STEPS}
            stepIndex={stepIndex}
            onStepClick={setStepIndex}
            pct={submitted ? null : pct}
            footerStart={submitted ? undefined : <Ring pct={pct} size={40} />}
            footerEnd={
                submitted ? undefined : (
                    <div className="flex items-center gap-2">
                        {stepIndex > 0 ? (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    setStepIndex((i) => Math.max(0, i - 1))
                                }
                            >
                                Back
                            </Button>
                        ) : null}
                        {stepIndex < lastIndex ? (
                            <Button
                                onClick={() =>
                                    setStepIndex((i) =>
                                        Math.min(lastIndex, i + 1),
                                    )
                                }
                                disabled={!stepValid(stepKey)}
                            >
                                Continue
                            </Button>
                        ) : (
                            <Button
                                onClick={submit}
                                disabled={processing || !canSubmit}
                            >
                                Create plan
                            </Button>
                        )}
                    </div>
                )
            }
            success={success}
        >
            <WizardStepPane>
                {stepKey === 'person' ? (
                    <div className="flex flex-col gap-4">
                        <StepHead
                            icon={User}
                            title="Person & plan"
                            blurb="Who is this plan for, and what is it called?"
                        />
                        <Field label="Client" required error={errors.client_id}>
                            <SelectInput
                                value={data.client_id}
                                onChange={(v) => setData('client_id', v)}
                                placeholder="Select client"
                                options={clientOptions}
                            />
                        </Field>
                        <Field label="Plan title" required error={errors.title}>
                            <Input
                                value={data.title}
                                onChange={(e) =>
                                    setData('title', e.target.value)
                                }
                                placeholder="e.g. Positive behaviour support plan"
                            />
                        </Field>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label="Developed by"
                                error={errors.developed_by}
                            >
                                <SelectInput
                                    value={data.developed_by}
                                    onChange={(v) => setData('developed_by', v)}
                                    placeholder="Select author"
                                    options={staffOptions}
                                />
                            </Field>
                            <Field
                                label="Developed on"
                                error={errors.developed_at}
                            >
                                <Input
                                    type="date"
                                    value={data.developed_at}
                                    onChange={(e) =>
                                        setData('developed_at', e.target.value)
                                    }
                                />
                            </Field>
                        </div>
                    </div>
                ) : null}

                {stepKey === 'triggers' ? (
                    <div className="flex flex-col gap-4">
                        <StepHead
                            icon={Activity}
                            title="Triggers & de-escalation"
                            blurb="What sets off behaviours of concern, and what works."
                        />
                        <Field
                            label="Triggers / antecedents"
                            error={errors.triggers}
                        >
                            <Textarea
                                rows={4}
                                value={data.triggers}
                                onChange={(e) =>
                                    setData('triggers', e.target.value)
                                }
                                placeholder="Known triggers and early-warning signs"
                            />
                        </Field>
                        <Field
                            label="De-escalation strategies"
                            error={errors.de_escalation_strategies}
                        >
                            <Textarea
                                rows={4}
                                value={data.de_escalation_strategies}
                                onChange={(e) =>
                                    setData(
                                        'de_escalation_strategies',
                                        e.target.value,
                                    )
                                }
                                placeholder="Least-restrictive strategies that help this person"
                            />
                        </Field>
                    </div>
                ) : null}

                {stepKey === 'interventions' ? (
                    <div className="flex flex-col gap-4">
                        <StepHead
                            icon={ClipboardList}
                            title="Approved vs prohibited"
                            blurb="Be explicit about what is and isn't allowed."
                        />
                        <InfoCard icon={ShieldCheck} tone="info">
                            Listing prohibited interventions is as important as
                            approved ones — it protects the person and gives
                            staff clear boundaries.
                        </InfoCard>
                        <Field
                            label="Approved interventions"
                            hint="What staff may use"
                        >
                            <TagListInput
                                tone="approved"
                                icon={ThumbsUp}
                                values={approved}
                                suggestions={APPROVED_SUGGESTIONS}
                                onChange={(v) =>
                                    setData(
                                        'approved_interventions',
                                        v.join('\n'),
                                    )
                                }
                                placeholder="Add an approved intervention…"
                            />
                        </Field>
                        <Field
                            label="Prohibited interventions"
                            hint="What staff must never use"
                        >
                            <TagListInput
                                tone="prohibited"
                                icon={ThumbsDown}
                                values={prohibited}
                                suggestions={PROHIBITED_SUGGESTIONS}
                                onChange={(v) =>
                                    setData(
                                        'prohibited_interventions',
                                        v.join('\n'),
                                    )
                                }
                                placeholder="Add a prohibited intervention…"
                            />
                        </Field>
                    </div>
                ) : null}

                {stepKey === 'practice' ? (
                    <div className="flex flex-col gap-4">
                        <StepHead
                            icon={CalendarClock}
                            title="Practice & review"
                            blurb="What restrictive practice does this cover, and when is it reviewed?"
                        />
                        <Field
                            label="Restrictive practice type"
                            error={errors.restrictive_practice_type}
                        >
                            <TilePicker
                                value={data.restrictive_practice_type}
                                onChange={(v) =>
                                    setData('restrictive_practice_type', v)
                                }
                                options={TYPE_TILES}
                                cols={3}
                            />
                        </Field>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label="Next review date"
                                hint="When the plan must be reviewed"
                                error={errors.review_date}
                            >
                                <Input
                                    type="date"
                                    value={data.review_date}
                                    onChange={(e) =>
                                        setData('review_date', e.target.value)
                                    }
                                />
                            </Field>
                            <Field label="Status">
                                <Segmented
                                    value={data.status}
                                    onChange={(v) => setData('status', v)}
                                    options={[
                                        { value: 'draft', label: 'Draft' },
                                        { value: 'active', label: 'Active' },
                                    ]}
                                />
                            </Field>
                        </div>
                        <Field label="Notes" hint="Optional">
                            <Textarea
                                rows={3}
                                value={data.notes}
                                onChange={(e) =>
                                    setData('notes', e.target.value)
                                }
                                placeholder="Any other context for this plan"
                            />
                        </Field>
                    </div>
                ) : null}

                {stepKey === 'review' ? (
                    <div className="flex flex-col gap-4">
                        <StepHead
                            icon={CheckCircle2}
                            title="Review & create"
                            blurb="Check the plan, then save."
                        />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <ReviewCard
                                icon={User}
                                title="Person & plan"
                                onEdit={() => setStepIndex(0)}
                            >
                                <ReviewRow
                                    label="Client"
                                    value={
                                        clientOptions.find(
                                            (o) => o.value === data.client_id,
                                        )?.label
                                    }
                                />
                                <ReviewRow label="Title" value={data.title} />
                                <ReviewRow
                                    label="Status"
                                    value={titleCase(data.status)}
                                />
                            </ReviewCard>
                            <ReviewCard
                                icon={CalendarClock}
                                title="Practice & review"
                                onEdit={() => setStepIndex(3)}
                            >
                                <ReviewRow
                                    label="Type"
                                    value={
                                        data.restrictive_practice_type
                                            ? titleCase(
                                                  data.restrictive_practice_type,
                                              )
                                            : undefined
                                    }
                                />
                                <ReviewRow
                                    label="Next review"
                                    value={data.review_date}
                                />
                                <ReviewRow
                                    label="Developed by"
                                    value={
                                        staffOptions.find(
                                            (o) =>
                                                o.value === data.developed_by,
                                        )?.label
                                    }
                                />
                            </ReviewCard>
                            <ReviewCard
                                icon={ThumbsUp}
                                title="Approved interventions"
                                onEdit={() => setStepIndex(2)}
                            >
                                <p className="text-[13px] text-foreground">
                                    {approved.length ? (
                                        approved.join(', ')
                                    ) : (
                                        <span className="text-muted-foreground">
                                            —
                                        </span>
                                    )}
                                </p>
                            </ReviewCard>
                            <ReviewCard
                                icon={ThumbsDown}
                                title="Prohibited interventions"
                                onEdit={() => setStepIndex(2)}
                            >
                                <p className="text-[13px] text-foreground">
                                    {prohibited.length ? (
                                        prohibited.join(', ')
                                    ) : (
                                        <span className="text-muted-foreground">
                                            —
                                        </span>
                                    )}
                                </p>
                            </ReviewCard>
                        </div>
                    </div>
                ) : null}
            </WizardStepPane>
        </WizardShell>
    );
}

function splitLines(raw: string): string[] {
    return raw
        .split('\n')
        .map((s) => s.trim())
        .filter(Boolean);
}

/* ------------------------------------------------------------------ */
/*  Tag-list input (chips + free entry + suggestions)                 */
/* ------------------------------------------------------------------ */

function TagListInput({
    values,
    onChange,
    suggestions,
    placeholder,
    tone,
    icon: Icon,
}: {
    values: string[];
    onChange: (v: string[]) => void;
    suggestions: string[];
    placeholder: string;
    tone: 'approved' | 'prohibited';
    icon: typeof ThumbsUp;
}) {
    const [draft, setDraft] = useState('');
    const add = (v: string) => {
        const t = v.trim();
        if (t && !values.includes(t)) onChange([...values, t]);
        setDraft('');
    };
    const remove = (v: string) => onChange(values.filter((x) => x !== v));
    const chipCls =
        tone === 'approved'
            ? 'border-status-success/40 bg-status-success-bg text-status-success'
            : 'border-status-critical/40 bg-status-critical-bg text-status-critical';
    const remaining = suggestions.filter((s) => !values.includes(s));

    return (
        <div className="flex flex-col gap-2">
            {values.length ? (
                <div className="flex flex-wrap gap-1.5">
                    {values.map((v) => (
                        <span
                            key={v}
                            className={cn(
                                'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[13px] font-medium',
                                chipCls,
                            )}
                        >
                            <Icon className="h-3 w-3" />
                            {v}
                            <Button
                                unstyled
                                type="button"
                                aria-label={`Remove ${v}`}
                                onClick={() => remove(v)}
                                className="ml-0.5 opacity-70 hover:opacity-100"
                            >
                                <X className="h-3 w-3" />
                            </Button>
                        </span>
                    ))}
                </div>
            ) : null}
            <div className="flex gap-2">
                <Input
                    value={draft}
                    onChange={(e) => setDraft(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            add(draft);
                        }
                    }}
                    placeholder={placeholder}
                    className="h-9"
                />
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => add(draft)}
                    disabled={!draft.trim()}
                >
                    <Plus className="h-4 w-4" />
                </Button>
            </div>
            {remaining.length ? (
                <div className="flex flex-wrap gap-1.5">
                    {remaining.map((s) => (
                        <Button
                            unstyled
                            key={s}
                            type="button"
                            onClick={() => add(s)}
                            className="inline-flex items-center gap-1 rounded-full border border-dashed border-border px-2.5 py-1 text-[12px] text-muted-foreground transition-colors hover:border-primary/50 hover:text-foreground"
                        >
                            <Plus className="h-3 w-3" /> {s}
                        </Button>
                    ))}
                </div>
            ) : null}
        </div>
    );
}
