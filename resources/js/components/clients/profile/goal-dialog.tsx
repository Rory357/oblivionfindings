/* Unified goal wizard for the client-profile Goals Path tab. ONE modal, two
 * modes, both rendered through the shared WizardShell so they match the Add
 * Client UX:
 *   - create  (no goal passed)  → template-or-custom goal + first sub-goals
 *   - manage  (goal passed)     → progress · sub-goals · hurdles · details
 * Progress auto-calculates from sub-goals; a manual % is only offered when a
 * goal has no sub-goals. Sub-goals and hurdles mutate immediately (so the page
 * props — and the card grid — stay in sync); progress and details are explicit
 * saves. See CarePlanGoalController for the endpoints. */
import { ConfirmDialog } from '@/components/confirm-dialog';
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
    type WizardStep,
} from '@/components/wizard/shell';
import { cn } from '@/lib/utils';
import type { FormDataConvertible } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import {
    AlertTriangle,
    Bus,
    Check,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Circle,
    Flag,
    Heart,
    Loader2,
    PauseCircle,
    Pencil,
    Pill,
    Plus,
    Route as RouteIcon,
    Settings2,
    ShieldAlert,
    Sparkles,
    Target,
    Trash2,
    Users,
    Utensils,
    Wallet,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

/* ------------------------------------------------------------------ types */

export type GoalCard = {
    id: number | string;
    title?: string | null;
    status?: string | null;
    category?: string | null;
    priority?: string | null;
    progress_percentage?: number | null;
    target_date?: string | null;
    description?: string | null;
};

type GoalStep = {
    id: number;
    title: string;
    is_complete: boolean;
    sort_order: number;
    target_date?: string | null;
};

type GoalHurdle = {
    id: number;
    content: string;
    reason?: string | null;
    resolved: boolean;
    author?: string | null;
    created_at?: string | null;
};

type GoalProgressEntry = {
    id: number;
    content: string;
    author?: string | null;
    created_at?: string | null;
};

type GoalDetail = {
    goal: GoalCard;
    steps: GoalStep[];
    hurdles: GoalHurdle[];
    progress_log: GoalProgressEntry[];
};

const DOMAINS = [
    'Daily living',
    'Community',
    'Health',
    'Independence',
    'Finance',
    'Whānau',
    'Wellbeing',
];

const TEMPLATES: {
    key: string;
    label: string;
    description?: string;
    title: string;
    domain: string;
    icon: typeof Flag;
}[] = [
    {
        key: 'meal',
        label: 'Prepare a simple meal',
        description: 'Cook independently with fading prompts',
        title: 'Prepare a simple meal independently',
        domain: 'Daily living',
        icon: Utensils,
    },
    {
        key: 'bus',
        label: 'Travel independently',
        description: 'Catch the bus to the day programme',
        title: 'Catch the bus to day programme',
        domain: 'Independence',
        icon: Bus,
    },
    {
        key: 'meds',
        label: 'Manage own meds',
        description: 'Self-manage the morning routine',
        title: 'Manage own morning medication routine',
        domain: 'Health',
        icon: Pill,
    },
    {
        key: 'budget',
        label: 'Build a weekly budget',
        description: 'Plan spending with the key worker',
        title: 'Build a weekly budget with key worker',
        domain: 'Finance',
        icon: Wallet,
    },
    {
        key: 'group',
        label: 'Join a community group',
        description: 'Attend weekly, e.g. kapa haka',
        title: 'Attend a community group weekly',
        domain: 'Community',
        icon: Users,
    },
    {
        key: 'whanau',
        label: 'Reconnect with whānau',
        description: 'Visits or video calls monthly',
        title: 'Reconnect with whānau monthly',
        domain: 'Whānau',
        icon: Heart,
    },
    {
        key: 'custom',
        label: 'Write your own',
        description: 'Start from a blank, fully custom goal',
        title: '',
        domain: '',
        icon: Pencil,
    },
];

const STATUS_OPTIONS: { value: string; label: string; icon: typeof Flag }[] = [
    { value: 'not_started', label: 'Not started', icon: Circle },
    { value: 'in_progress', label: 'In progress', icon: RouteIcon },
    { value: 'on_hold', label: 'On hold', icon: PauseCircle },
    { value: 'completed', label: 'Achieved', icon: CheckCircle2 },
];

const PRIORITY_OPTIONS: { value: string; label: string; icon: typeof Flag }[] =
    [
        { value: 'low', label: 'Low', icon: Circle },
        { value: 'medium', label: 'Medium', icon: Flag },
        { value: 'high', label: 'High', icon: AlertTriangle },
    ];

const str = (v: unknown): string => String(v ?? '').trim();
const opt = (v: unknown): string | undefined => (str(v) ? str(v) : undefined);

/* --------------------------------------------------------------- component */

export function GoalWizardDialog({
    open,
    onClose,
    carePlanId,
    clientLabel,
    goal,
}: {
    open: boolean;
    onClose: () => void;
    carePlanId: number | null;
    clientLabel?: string;
    /** Present → manage an existing goal; absent → create a new one. */
    goal?: GoalCard | null;
}) {
    const managing = Boolean(goal);
    const base = carePlanId
        ? `/operations/care-plans/${carePlanId}/goals`
        : null;

    const [stepIndex, setStepIndex] = useState(0);
    const [busy, setBusy] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);

    /* create-mode form */
    const [template, setTemplate] = useState('custom');
    const [title, setTitle] = useState('');
    const [domain, setDomain] = useState('');
    const [why, setWhy] = useState('');
    const [priority, setPriority] = useState('medium');
    const [targetDate, setTargetDate] = useState('');
    const [newSteps, setNewSteps] = useState<string[]>(['']);

    /* manage-mode state (lazy-loaded) */
    const [detail, setDetail] = useState<GoalDetail | null>(null);
    const [loading, setLoading] = useState(false);
    const [status, setStatus] = useState('not_started');
    const [manualPct, setManualPct] = useState(0);
    const [progressNote, setProgressNote] = useState('');
    const [newStepTitle, setNewStepTitle] = useState('');
    const [hurdleText, setHurdleText] = useState('');
    /* details editor */
    const [dTitle, setDTitle] = useState('');
    const [dDomain, setDDomain] = useState('');
    const [dWhy, setDWhy] = useState('');
    const [dPriority, setDPriority] = useState('medium');
    const [dTarget, setDTarget] = useState('');

    const refetch = useCallback(async () => {
        if (!base || !goal) return;
        try {
            const res = await fetch(`${base}/${goal.id}`, {
                headers: { Accept: 'application/json' },
            });
            if (!res.ok) throw new Error('load failed');
            const data: GoalDetail = await res.json();
            setDetail(data);
            setStatus(data.goal.status ?? 'not_started');
            setManualPct(data.goal.progress_percentage ?? 0);
            setDTitle(data.goal.title ?? '');
            setDDomain(data.goal.category ?? '');
            setDWhy(data.goal.description ?? '');
            setDPriority(data.goal.priority ?? 'medium');
            setDTarget(data.goal.target_date ?? '');
        } catch {
            toast.error('Could not load the goal.');
        }
    }, [base, goal]);

    // Re-seed whenever the dialog (re)opens.
    useEffect(() => {
        if (!open) return;
        setStepIndex(0);
        setBusy(false);
        if (managing) {
            setLoading(true);
            void refetch().finally(() => setLoading(false));
        } else {
            setTemplate('custom');
            setTitle('');
            setDomain('');
            setWhy('');
            setPriority('medium');
            setTargetDate('');
            setNewSteps(['']);
        }
    }, [open, managing, refetch]);

    const steps = detail?.steps ?? [];
    const hasSteps = steps.length > 0;
    const doneCount = steps.filter((s) => s.is_complete).length;
    const livePct = hasSteps
        ? Math.round((doneCount / steps.length) * 100)
        : manualPct;

    /* ---- Inertia mutation helper (keeps page props + card grid in sync) ---- */
    const mutate = useCallback(
        (
            method: 'post' | 'put' | 'patch' | 'delete',
            url: string,
            payload: Record<string, FormDataConvertible> = {},
            o: { okToast?: string; onOk?: () => void; close?: boolean } = {},
        ) => {
            setBusy(true);
            const options = {
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page: { props: Record<string, unknown> }) => {
                    setBusy(false);
                    const flash = (page.props as { flash?: { error?: string } })
                        .flash;
                    if (flash?.error) {
                        toast.error(flash.error);
                        return;
                    }
                    if (o.okToast) toast.success(o.okToast);
                    o.onOk?.();
                    if (o.close) onClose();
                },
                onError: (errors: Record<string, string>) => {
                    setBusy(false);
                    const first = Object.values(errors ?? {})[0];
                    toast.error(
                        first ? String(first) : 'Something went wrong.',
                    );
                },
            };
            if (method === 'delete') {
                router.delete(url, options);
            } else {
                router[method](url, payload, options);
            }
        },
        [onClose],
    );

    /* ------------------------------------------------------- rail + chrome */

    const railSteps: WizardStep[] = useMemo(
        () =>
            managing
                ? [
                      {
                          key: 'progress',
                          label: 'Progress',
                          blurb: 'Status & %',
                          icon: Target,
                      },
                      {
                          key: 'steps',
                          label: 'Sub-goals',
                          blurb: 'Break it down',
                          icon: RouteIcon,
                      },
                      {
                          key: 'hurdles',
                          label: 'Hurdles',
                          blurb: 'Issues & barriers',
                          icon: ShieldAlert,
                      },
                      {
                          key: 'details',
                          label: 'Details',
                          blurb: 'Edit or remove',
                          icon: Settings2,
                      },
                  ]
                : [
                      {
                          key: 'goal',
                          label: 'The goal',
                          blurb: 'What & why',
                          icon: Flag,
                      },
                      {
                          key: 'plan',
                          label: 'First steps',
                          blurb: 'Break it down',
                          icon: RouteIcon,
                      },
                      {
                          key: '__review',
                          label: 'Review & save',
                          blurb: 'Confirm and save',
                          icon: CheckCircle2,
                      },
                  ],
        [managing],
    );

    const lastIndex = railSteps.length - 1;

    /* ----------------------------------------------------------- create */

    const applyTemplate = (key: string) => {
        setTemplate(key);
        const t = TEMPLATES.find((x) => x.key === key);
        if (t && key !== 'custom') {
            setTitle(t.title);
            setDomain(t.domain);
        }
    };

    const createValid = Boolean(str(title) && str(domain));

    const submitCreate = () => {
        if (!base) return;
        mutate(
            'post',
            base,
            {
                title: str(title),
                category: str(domain),
                priority: priority || 'medium',
                target_date: opt(targetDate),
                description: opt(why),
                steps: newSteps.map(str).filter(Boolean),
            },
            { okToast: 'Goal added to path', close: true },
        );
    };

    /* ----------------------------------------------------------- manage */

    const saveProgress = () => {
        if (!base || !goal) return;
        mutate(
            'patch',
            `${base}/${goal.id}/progress`,
            {
                progress_percentage: hasSteps ? livePct : manualPct,
                status,
                note: opt(progressNote),
            },
            {
                okToast: 'Progress updated',
                onOk: () => {
                    setProgressNote('');
                    void refetch();
                },
            },
        );
    };

    const toggleStep = (step: GoalStep) => {
        if (!base || !goal) return;
        setDetail((d) =>
            d
                ? {
                      ...d,
                      steps: d.steps.map((s) =>
                          s.id === step.id
                              ? { ...s, is_complete: !s.is_complete }
                              : s,
                      ),
                  }
                : d,
        );
        mutate(
            'put',
            `${base}/${goal.id}/steps/${step.id}`,
            { is_complete: !step.is_complete },
            { onOk: () => void refetch() },
        );
    };

    const addStep = () => {
        if (!base || !goal || !str(newStepTitle)) return;
        mutate(
            'post',
            `${base}/${goal.id}/steps`,
            { title: str(newStepTitle) },
            {
                okToast: 'Sub-goal added',
                onOk: () => {
                    setNewStepTitle('');
                    void refetch();
                },
            },
        );
    };

    const removeStep = (step: GoalStep) => {
        if (!base || !goal) return;
        setDetail((d) =>
            d ? { ...d, steps: d.steps.filter((s) => s.id !== step.id) } : d,
        );
        mutate(
            'delete',
            `${base}/${goal.id}/steps/${step.id}`,
            {},
            {
                onOk: () => void refetch(),
            },
        );
    };

    const addHurdle = () => {
        if (!base || !goal || !str(hurdleText)) return;
        mutate(
            'post',
            `${base}/${goal.id}/hurdles`,
            { content: str(hurdleText) },
            {
                okToast: 'Hurdle logged',
                onOk: () => {
                    setHurdleText('');
                    void refetch();
                },
            },
        );
    };

    const resolveHurdle = (h: GoalHurdle) => {
        if (!base || !goal) return;
        mutate(
            'patch',
            `${base}/${goal.id}/hurdles/${h.id}/resolve`,
            {},
            { okToast: 'Hurdle resolved', onOk: () => void refetch() },
        );
    };

    const saveDetails = () => {
        if (!base || !goal) return;
        mutate(
            'put',
            `${base}/${goal.id}`,
            {
                title: str(dTitle),
                category: str(dDomain),
                priority: dPriority || 'medium',
                target_date: opt(dTarget),
                description: opt(dWhy),
            },
            { okToast: 'Goal updated', close: true },
        );
    };

    const deleteGoal = () => {
        if (!base || !goal) return;
        mutate(
            'delete',
            `${base}/${goal.id}`,
            {},
            {
                okToast: 'Goal removed',
                close: true,
            },
        );
    };

    /* ----------------------------------------------------------- footer */

    const goBack = () => setStepIndex((i) => Math.max(0, i - 1));
    const goNext = () => setStepIndex((i) => Math.min(lastIndex, i + 1));

    const navButtons = (
        <>
            {stepIndex > 0 ? (
                <Button
                    type="button"
                    variant="ghost"
                    onClick={goBack}
                    disabled={busy}
                >
                    <ChevronLeft className="mr-1 h-4 w-4" /> Back
                </Button>
            ) : null}
        </>
    );

    let footerEnd: React.ReactNode;
    if (!managing) {
        const reviewing = stepIndex === lastIndex;
        footerEnd = (
            <>
                <Button
                    type="button"
                    variant="outline"
                    onClick={onClose}
                    disabled={busy}
                >
                    Cancel
                </Button>
                {reviewing ? (
                    <Button
                        type="button"
                        onClick={submitCreate}
                        disabled={busy || !createValid || !base}
                        data-test="goal-create-submit"
                    >
                        {busy ? (
                            <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                        ) : (
                            <Check className="mr-1.5 h-4 w-4" />
                        )}
                        {busy ? 'Saving…' : 'Create goal'}
                    </Button>
                ) : (
                    <Button
                        type="button"
                        onClick={goNext}
                        disabled={stepIndex === 0 && !createValid}
                        data-test="goal-create-continue"
                    >
                        Continue <ChevronRight className="ml-1 h-4 w-4" />
                    </Button>
                )}
            </>
        );
    } else {
        const onProgress = stepIndex === 0;
        const onDetails = stepIndex === lastIndex;
        footerEnd = (
            <>
                <Button
                    type="button"
                    variant="outline"
                    onClick={onClose}
                    disabled={busy}
                >
                    Close
                </Button>
                {onProgress ? (
                    <Button
                        type="button"
                        onClick={saveProgress}
                        disabled={busy}
                        data-test="goal-progress-save"
                    >
                        {busy ? (
                            <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                        ) : (
                            <Check className="mr-1.5 h-4 w-4" />
                        )}
                        Save progress
                    </Button>
                ) : onDetails ? (
                    <Button
                        type="button"
                        onClick={saveDetails}
                        disabled={busy}
                        data-test="goal-details-save"
                    >
                        {busy ? (
                            <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                        ) : (
                            <Check className="mr-1.5 h-4 w-4" />
                        )}
                        Save changes
                    </Button>
                ) : (
                    <Button type="button" onClick={goNext}>
                        Continue <ChevronRight className="ml-1 h-4 w-4" />
                    </Button>
                )}
            </>
        );
    }

    const footerStart =
        managing && stepIndex === lastIndex ? (
            <div className="flex items-center gap-3">
                {navButtons}
                <Button
                    type="button"
                    variant="ghost"
                    onClick={() => setDeleteOpen(true)}
                    disabled={busy}
                    className="text-status-critical hover:text-status-critical"
                >
                    <Trash2 className="mr-1.5 h-4 w-4" /> Delete goal
                </Button>
            </div>
        ) : (
            navButtons
        );

    /* ------------------------------------------------------------- panes */

    const stepKey = railSteps[stepIndex]?.key;

    return (
        <>
            <WizardShell
                open={open}
                onClose={() => !busy && onClose()}
                title={managing ? 'Manage goal' : 'Add goal'}
                description={
                    managing
                        ? 'Update progress, sub-goals and hurdles'
                        : 'Create a goal on the path'
                }
                railIcon={Flag}
                railTitle={managing ? (goal?.title ?? 'Goal') : 'Add goal'}
                railSub="Goals path"
                steps={railSteps}
                stepIndex={stepIndex}
                onStepClick={(i) => setStepIndex(i)}
                pct={managing ? livePct : null}
                pctLabel={managing ? 'Progress' : 'Completeness'}
                footerStart={footerStart}
                footerEnd={footerEnd}
            >
                {/* ============================ CREATE ============================ */}
                {!managing && stepKey === 'goal' ? (
                    <WizardStepPane key="goal">
                        <StepHead
                            icon={Flag}
                            title="What is the goal?"
                            blurb="Pick a starting point or write your own — in the client's words where possible."
                        />
                        {clientLabel ? (
                            <ClientChip label={clientLabel} />
                        ) : null}
                        {!base ? (
                            <InfoCard icon={AlertTriangle} tone="warn">
                                No active care plan yet — create one on the Care
                                &amp; Support Plan tab before adding goals.
                            </InfoCard>
                        ) : null}
                        <p className="mb-1.5 text-sm font-medium">Start from</p>
                        <TilePicker
                            value={template}
                            onChange={applyTemplate}
                            cols={3}
                            options={TEMPLATES.map((t) => ({
                                key: t.key,
                                label: t.label,
                                description: t.description,
                                icon: t.icon,
                            }))}
                        />
                        <div className="mt-4 grid gap-3.5 sm:grid-cols-2">
                            <Field label="Goal" required span>
                                <Input
                                    value={title}
                                    onChange={(e) => setTitle(e.target.value)}
                                    placeholder="e.g. Prepare a simple meal independently"
                                />
                            </Field>
                            <Field label="Domain" required>
                                <SelectInput
                                    value={domain}
                                    onChange={setDomain}
                                    placeholder="Select a domain…"
                                    options={DOMAINS.map((d) => ({
                                        value: d,
                                        label: d,
                                    }))}
                                />
                            </Field>
                            <Field label="Why it matters" span>
                                <Textarea
                                    value={why}
                                    rows={2}
                                    onChange={(e) => setWhy(e.target.value)}
                                    placeholder="What makes this goal meaningful to the client?"
                                />
                            </Field>
                        </div>
                    </WizardStepPane>
                ) : null}

                {!managing && stepKey === 'plan' ? (
                    <WizardStepPane key="plan">
                        <StepHead
                            icon={RouteIcon}
                            title="How will we get there?"
                            blurb="Add the first sub-goals. Progress is auto-calculated from these as they're completed."
                        />
                        <p className="mb-1.5 text-sm font-medium">Sub-goals</p>
                        <div className="space-y-2">
                            {newSteps.map((s, i) => (
                                <div
                                    key={i}
                                    className="flex items-center gap-2"
                                >
                                    <span className="grid h-7 w-7 shrink-0 place-items-center rounded-md bg-muted text-xs font-semibold text-muted-foreground">
                                        {i + 1}
                                    </span>
                                    <Input
                                        value={s}
                                        onChange={(e) =>
                                            setNewSteps((arr) =>
                                                arr.map((x, j) =>
                                                    j === i
                                                        ? e.target.value
                                                        : x,
                                                ),
                                            )
                                        }
                                        placeholder="e.g. Choose a recipe together"
                                    />
                                    {newSteps.length > 1 ? (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            onClick={() =>
                                                setNewSteps((arr) =>
                                                    arr.filter(
                                                        (_, j) => j !== i,
                                                    ),
                                                )
                                            }
                                        >
                                            <Trash2 className="h-4 w-4 text-muted-foreground" />
                                        </Button>
                                    ) : null}
                                </div>
                            ))}
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    setNewSteps((arr) => [...arr, ''])
                                }
                            >
                                <Plus className="mr-1.5 h-3.5 w-3.5" /> Add
                                sub-goal
                            </Button>
                        </div>
                        <div className="mt-5 grid gap-3.5 sm:grid-cols-2">
                            <Field label="Priority">
                                <Segmented
                                    value={priority}
                                    onChange={setPriority}
                                    options={PRIORITY_OPTIONS}
                                />
                            </Field>
                            <Field label="Target date">
                                <Input
                                    type="date"
                                    value={targetDate}
                                    onChange={(e) =>
                                        setTargetDate(e.target.value)
                                    }
                                />
                            </Field>
                        </div>
                        <InfoCard icon={Flag} tone="info">
                            You can leave sub-goals empty and add them later —
                            the goal will simply start at 0%.
                        </InfoCard>
                    </WizardStepPane>
                ) : null}

                {!managing && stepKey === '__review' ? (
                    <WizardStepPane key="__review">
                        <StepHead
                            icon={CheckCircle2}
                            title="Review & save"
                            blurb="Check the details — jump back to any step to edit."
                        />
                        <div className="mb-5 flex items-center gap-4 rounded-xl border border-border bg-muted/30 p-4">
                            <Ring pct={0} size={64} />
                            <div>
                                <div className="text-sm font-semibold">
                                    {str(title) || 'Untitled goal'}
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    {str(domain) || 'No domain'} · starts at 0%
                                </p>
                            </div>
                        </div>
                        <div className="space-y-3">
                            <ReviewCard
                                icon={Flag}
                                title="The goal"
                                onEdit={() => setStepIndex(0)}
                            >
                                <ReviewRow
                                    label="Goal"
                                    value={str(title) || undefined}
                                />
                                <ReviewRow
                                    label="Domain"
                                    value={str(domain) || undefined}
                                />
                                <ReviewRow
                                    label="Why it matters"
                                    value={opt(why)}
                                />
                            </ReviewCard>
                            <ReviewCard
                                icon={RouteIcon}
                                title="First steps"
                                onEdit={() => setStepIndex(1)}
                            >
                                <ReviewRow
                                    label="Sub-goals"
                                    value={
                                        newSteps
                                            .map(str)
                                            .filter(Boolean)
                                            .join(' · ') || undefined
                                    }
                                />
                                <ReviewRow label="Priority" value={priority} />
                                <ReviewRow
                                    label="Target date"
                                    value={opt(targetDate)}
                                />
                            </ReviewCard>
                        </div>
                    </WizardStepPane>
                ) : null}

                {/* ============================ MANAGE ============================ */}
                {managing && loading ? (
                    <WizardStepPane key="loading">
                        <div className="grid place-items-center py-16 text-muted-foreground">
                            <Loader2 className="h-6 w-6 animate-spin" />
                        </div>
                    </WizardStepPane>
                ) : null}

                {managing && !loading && stepKey === 'progress' ? (
                    <WizardStepPane key="progress">
                        <StepHead
                            icon={Target}
                            title="Progress"
                            blurb={
                                hasSteps
                                    ? 'Auto-calculated from sub-goals — set the status and add a note.'
                                    : 'Set the progress and status for this goal.'
                            }
                        />
                        <div className="mb-5 flex items-center gap-4 rounded-xl border border-border bg-muted/30 p-4">
                            <Ring pct={livePct} size={72} />
                            <div className="min-w-0">
                                <div className="text-sm font-semibold">
                                    {detail?.goal.title}
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    {hasSteps
                                        ? `Auto-calculated from ${steps.length} sub-goal${steps.length === 1 ? '' : 's'} · ${doneCount} done`
                                        : 'No sub-goals — set the percentage manually'}
                                </p>
                            </div>
                        </div>
                        <div className="space-y-4">
                            <Field label="Status">
                                <Segmented
                                    value={status}
                                    onChange={setStatus}
                                    options={STATUS_OPTIONS}
                                />
                            </Field>
                            {!hasSteps ? (
                                <Field label={`Progress — ${manualPct}%`}>
                                    <input
                                        type="range"
                                        min={0}
                                        max={100}
                                        step={5}
                                        value={manualPct}
                                        onChange={(e) =>
                                            setManualPct(Number(e.target.value))
                                        }
                                        className="w-full accent-[var(--primary)]"
                                    />
                                </Field>
                            ) : (
                                <InfoCard icon={RouteIcon} tone="info">
                                    Add or complete sub-goals to move this
                                    percentage. Switch to the Sub-goals step to
                                    manage them.
                                </InfoCard>
                            )}
                            <Field label="Progress note (optional)" span>
                                <Textarea
                                    value={progressNote}
                                    rows={2}
                                    onChange={(e) =>
                                        setProgressNote(e.target.value)
                                    }
                                    placeholder="What changed? Added to the goal's progress log."
                                />
                            </Field>
                        </div>
                        {detail && detail.progress_log.length > 0 ? (
                            <div className="mt-5">
                                <p className="mb-2 text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                                    Progress log
                                </p>
                                <div className="space-y-2">
                                    {detail.progress_log
                                        .slice(0, 5)
                                        .map((p) => (
                                            /* eslint-disable-next-line no-restricted-syntax -- compact log row inside the wizard pane */
                                            <div
                                                key={p.id}
                                                className="rounded-lg border border-border bg-card p-3 text-sm"
                                            >
                                                <p className="leading-snug">
                                                    {p.content}
                                                </p>
                                                <p className="mt-1 text-[11px] text-muted-foreground">
                                                    {p.author ?? 'Staff'}
                                                    {p.created_at
                                                        ? ` · ${new Date(p.created_at).toLocaleDateString('en-NZ')}`
                                                        : ''}
                                                </p>
                                            </div>
                                        ))}
                                </div>
                            </div>
                        ) : null}
                    </WizardStepPane>
                ) : null}

                {managing && !loading && stepKey === 'steps' ? (
                    <WizardStepPane key="steps">
                        <StepHead
                            icon={RouteIcon}
                            title="Sub-goals"
                            blurb="Break the goal into steps. Completing them moves the goal's progress automatically."
                        />
                        <div className="space-y-2">
                            {steps.length === 0 ? (
                                <p className="rounded-lg border border-dashed border-border p-4 text-center text-sm text-muted-foreground">
                                    No sub-goals yet. Add the first below.
                                </p>
                            ) : (
                                steps.map((s) => (
                                    <div
                                        key={s.id}
                                        className={cn(
                                            'flex items-center gap-2.5 rounded-lg border p-3',
                                            s.is_complete
                                                ? 'border-status-success/40 bg-status-success-bg/30'
                                                : 'border-border',
                                        )}
                                    >
                                        {/* eslint-disable-next-line no-restricted-syntax -- custom checkbox toggle for a sub-goal */}
                                        <button
                                            type="button"
                                            onClick={() => toggleStep(s)}
                                            aria-pressed={s.is_complete}
                                            className={cn(
                                                'grid h-6 w-6 shrink-0 place-items-center rounded-md border transition-colors',
                                                s.is_complete
                                                    ? 'border-status-success bg-status-success text-white'
                                                    : 'border-border hover:border-primary',
                                            )}
                                        >
                                            {s.is_complete ? (
                                                <Check className="h-3.5 w-3.5" />
                                            ) : null}
                                        </button>
                                        <span
                                            className={cn(
                                                'min-w-0 flex-1 text-sm',
                                                s.is_complete &&
                                                    'text-muted-foreground line-through',
                                            )}
                                        >
                                            {s.title}
                                        </span>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            onClick={() => removeStep(s)}
                                        >
                                            <Trash2 className="h-4 w-4 text-muted-foreground" />
                                        </Button>
                                    </div>
                                ))
                            )}
                        </div>
                        <div className="mt-3 flex items-center gap-2">
                            <Input
                                value={newStepTitle}
                                onChange={(e) =>
                                    setNewStepTitle(e.target.value)
                                }
                                placeholder="Add a sub-goal…"
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') {
                                        e.preventDefault();
                                        addStep();
                                    }
                                }}
                            />
                            <Button
                                type="button"
                                onClick={addStep}
                                disabled={busy || !str(newStepTitle)}
                            >
                                <Plus className="mr-1.5 h-4 w-4" /> Add
                            </Button>
                        </div>
                    </WizardStepPane>
                ) : null}

                {managing && !loading && stepKey === 'hurdles' ? (
                    <WizardStepPane key="hurdles">
                        <StepHead
                            icon={ShieldAlert}
                            title="Hurdles & issues"
                            blurb="Record what's getting in the way so the team can respond."
                        />
                        <div className="space-y-2">
                            {detail && detail.hurdles.length === 0 ? (
                                <p className="rounded-lg border border-dashed border-border p-4 text-center text-sm text-muted-foreground">
                                    No hurdles logged. 🎉
                                </p>
                            ) : (
                                detail?.hurdles.map((h) => (
                                    <div
                                        key={h.id}
                                        className={cn(
                                            'flex items-start gap-2.5 rounded-lg border p-3',
                                            h.resolved
                                                ? 'border-border bg-muted/30'
                                                : 'border-status-warning/40 bg-status-warning-bg/40',
                                        )}
                                    >
                                        <ShieldAlert
                                            className={cn(
                                                'mt-0.5 h-4 w-4 shrink-0',
                                                h.resolved
                                                    ? 'text-muted-foreground'
                                                    : 'text-status-warning',
                                            )}
                                        />
                                        <div className="min-w-0 flex-1">
                                            <p
                                                className={cn(
                                                    'text-sm leading-snug',
                                                    h.resolved &&
                                                        'text-muted-foreground line-through',
                                                )}
                                            >
                                                {h.content}
                                            </p>
                                            <p className="mt-1 text-[11px] text-muted-foreground">
                                                {h.author ?? 'Staff'}
                                                {h.created_at
                                                    ? ` · ${new Date(h.created_at).toLocaleDateString('en-NZ')}`
                                                    : ''}
                                            </p>
                                        </div>
                                        {h.resolved ? (
                                            <span className="shrink-0 text-[11px] font-medium text-status-success">
                                                Resolved
                                            </span>
                                        ) : (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() => resolveHurdle(h)}
                                                disabled={busy}
                                            >
                                                Resolve
                                            </Button>
                                        )}
                                    </div>
                                ))
                            )}
                        </div>
                        <div className="mt-3 flex items-center gap-2">
                            <Input
                                value={hurdleText}
                                onChange={(e) => setHurdleText(e.target.value)}
                                placeholder="Describe a hurdle or issue…"
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') {
                                        e.preventDefault();
                                        addHurdle();
                                    }
                                }}
                            />
                            <Button
                                type="button"
                                onClick={addHurdle}
                                disabled={busy || !str(hurdleText)}
                            >
                                <Plus className="mr-1.5 h-4 w-4" /> Log
                            </Button>
                        </div>
                    </WizardStepPane>
                ) : null}

                {managing && !loading && stepKey === 'details' ? (
                    <WizardStepPane key="details">
                        <StepHead
                            icon={Settings2}
                            title="Goal details"
                            blurb="Edit the goal, or remove it from the path."
                        />
                        <div className="grid gap-3.5 sm:grid-cols-2">
                            <Field label="Goal" required span>
                                <Input
                                    value={dTitle}
                                    onChange={(e) => setDTitle(e.target.value)}
                                />
                            </Field>
                            <Field label="Domain" required>
                                <SelectInput
                                    value={dDomain}
                                    onChange={setDDomain}
                                    placeholder="Select a domain…"
                                    options={DOMAINS.map((d) => ({
                                        value: d,
                                        label: d,
                                    }))}
                                />
                            </Field>
                            <Field label="Priority">
                                <Segmented
                                    value={dPriority}
                                    onChange={setDPriority}
                                    options={PRIORITY_OPTIONS}
                                />
                            </Field>
                            <Field label="Target date">
                                <Input
                                    type="date"
                                    value={dTarget}
                                    onChange={(e) => setDTarget(e.target.value)}
                                />
                            </Field>
                            <Field label="Why it matters" span>
                                <Textarea
                                    value={dWhy}
                                    rows={2}
                                    onChange={(e) => setDWhy(e.target.value)}
                                />
                            </Field>
                        </div>
                    </WizardStepPane>
                ) : null}
            </WizardShell>
            <ConfirmDialog
                open={deleteOpen}
                onClose={() => setDeleteOpen(false)}
                onConfirm={deleteGoal}
                title="Remove goal?"
                description={`Remove “${goal?.title ?? 'this goal'}” from the path? This action cannot be undone.`}
                confirmText="Remove goal"
            />
        </>
    );
}

function ClientChip({ label }: { label: string }) {
    return (
        <div className="mb-4 flex items-center gap-3 rounded-xl border border-primary/40 bg-accent px-3 py-2.5">
            <span className="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-card text-primary">
                <Sparkles className="h-[15px] w-[15px]" />
            </span>
            <div className="min-w-0">
                <div className="truncate text-sm font-medium">{label}</div>
                <div className="text-[11px] text-muted-foreground">
                    Locked to the client you opened.
                </div>
            </div>
        </div>
    );
}
