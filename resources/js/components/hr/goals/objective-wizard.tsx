/* eslint-disable no-restricted-syntax -- Wizard footer + KR rows use native
 * controls to match the Add-Client modal chrome (components/wizard/shell.tsx).
 * Colours are semantic design tokens. */
import { useForm } from '@inertiajs/react';
import {
    Building2,
    CalendarClock,
    ClipboardCheck,
    ListChecks,
    Minus,
    Plus,
    Target,
    User as UserIcon,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';

import {
    Field,
    ReviewCard,
    ReviewRow,
    Segmented,
    SelectInput,
    StepHead,
    TilePicker,
    useWizard,
    WizardShell,
    WizardStepPane,
    type WizardStep,
} from '@/components/hr/wizard';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

import {
    rollup,
    type Cycle,
    type KrType,
    type Objective,
    type ObjectiveTemplate,
} from './okr-shared';

interface KrRow {
    key: string;
    title: string;
    kr_type: KrType;
    start_value: string;
    target_value: string;
    unit: string;
    weight: string;
}

const KR_TYPE_OPTIONS = [
    { value: 'number', label: 'Number' },
    { value: 'percent', label: 'Percent' },
    { value: 'currency', label: 'Currency' },
    { value: 'milestone', label: 'Milestone' },
    { value: 'boolean', label: 'Yes / No' },
];

const NONE = '__none__';
let krSeq = 0;
const newKr = (): KrRow => ({
    key: `kr-${krSeq++}`,
    title: '',
    kr_type: 'percent',
    start_value: '0',
    target_value: '100',
    unit: '%',
    weight: '1',
});

export function ObjectiveWizard({
    open,
    onClose,
    owners,
    parentGoals,
    cycles,
    templates = [],
    defaultCycleId,
    prefillParentId,
    goal,
}: {
    open: boolean;
    onClose: () => void;
    owners: { id: number; name: string }[];
    parentGoals: { id: number; title: string }[];
    cycles: Cycle[];
    templates?: ObjectiveTemplate[];
    defaultCycleId: number | null;
    prefillParentId?: number | null;
    goal?: Objective | null;
}) {
    const isEdit = !!goal;
    const steps: WizardStep[] = useMemo(
        () =>
            isEdit
                ? [
                      {
                          key: 'objective',
                          label: 'Objective',
                          blurb: 'What & who',
                          icon: Target,
                      },
                      {
                          key: 'timing',
                          label: 'Timing',
                          blurb: 'Window & confidence',
                          icon: CalendarClock,
                      },
                      {
                          key: 'review',
                          label: 'Review',
                          blurb: 'Confirm & save',
                          icon: ClipboardCheck,
                      },
                  ]
                : [
                      {
                          key: 'objective',
                          label: 'Objective',
                          blurb: 'What & who',
                          icon: Target,
                      },
                      {
                          key: 'krs',
                          label: 'Key results',
                          blurb: 'Measure & weight',
                          icon: ListChecks,
                      },
                      {
                          key: 'timing',
                          label: 'Timing',
                          blurb: 'Window & confidence',
                          icon: CalendarClock,
                      },
                      {
                          key: 'review',
                          label: 'Review',
                          blurb: 'Confirm & save',
                          icon: ClipboardCheck,
                      },
                  ],
        [isEdit],
    );
    const wizard = useWizard(steps.length);
    const stepKey = steps[wizard.index]?.key;

    const form = useForm<{
        user_id: string;
        title: string;
        description: string;
        goal_type: string;
        priority: string;
        category: string;
        parent_goal_id: string;
        cycle_id: string;
        confidence: string;
        checkin_frequency: string;
        start_date: string;
        due_date: string;
        status: string;
    }>({
        user_id: goal?.user?.id ? String(goal.user.id) : '',
        title: goal?.title ?? '',
        description: goal?.description ?? '',
        goal_type: goal?.goal_type ?? 'team',
        priority: goal?.priority ?? 'medium',
        category: goal?.category ?? '',
        parent_goal_id: goal?.parent_goal_id
            ? String(goal.parent_goal_id)
            : prefillParentId
              ? String(prefillParentId)
              : NONE,
        cycle_id: goal?.cycle_id
            ? String(goal.cycle_id)
            : defaultCycleId
              ? String(defaultCycleId)
              : NONE,
        confidence: goal?.confidence ?? 'on_track',
        checkin_frequency: goal?.checkin_frequency ?? 'fortnightly',
        start_date: goal?.start_date ?? '',
        due_date: goal?.due_date ?? '',
        status: goal?.status === 'draft' ? 'draft' : 'active',
    });

    const [krs, setKrs] = useState<KrRow[]>([newKr()]);
    const [tags, setTags] = useState<string[]>(goal?.tags ?? []);
    const [tagDraft, setTagDraft] = useState('');
    const [templateId, setTemplateId] = useState(NONE);

    const addTag = () => {
        const t = tagDraft.trim();
        if (t && !tags.includes(t)) setTags((p) => [...p, t]);
        setTagDraft('');
    };

    const applyTemplate = (id: string) => {
        setTemplateId(id);
        if (id === NONE) return;
        const tpl = templates.find((t) => String(t.id) === id);
        if (!tpl) return;
        form.setData((d) => ({
            ...d,
            title: tpl.title,
            description: tpl.description ?? '',
            goal_type: tpl.goal_type,
            priority: tpl.priority,
            category: tpl.category ?? '',
        }));
        setKrs(
            (tpl.key_results.length
                ? tpl.key_results
                : [
                      {
                          title: '',
                          kr_type: 'percent',
                          start_value: 0,
                          target_value: 100,
                          unit: '%',
                          weight: 1,
                      },
                  ]
            ).map((k) => ({
                key: `kr-${krSeq++}`,
                title: k.title,
                kr_type: k.kr_type,
                start_value: String(k.start_value),
                target_value: String(k.target_value),
                unit: k.unit ?? '',
                weight: String(k.weight),
            })),
        );
    };

    const close = () => {
        form.reset();
        form.clearErrors();
        wizard.reset();
        setKrs([newKr()]);
        setTags(goal?.tags ?? []);
        setTagDraft('');
        setTemplateId(NONE);
        onClose();
    };

    const ownerName =
        owners.find((o) => String(o.id) === form.data.user_id)?.name ?? '—';
    const parentOptions = useMemo(
        () => [
            { value: NONE, label: 'Top-level (no parent)' },
            ...parentGoals
                .filter((p) => !goal || p.id !== goal.id)
                .map((p) => ({ value: String(p.id), label: p.title })),
        ],
        [parentGoals, goal],
    );
    const cycleOptions = useMemo(
        () => [
            { value: NONE, label: 'No cycle' },
            ...cycles.map((c) => ({ value: String(c.id), label: c.name })),
        ],
        [cycles],
    );

    const previewRollup = useMemo(
        () =>
            rollup(
                krs
                    .filter((k) => k.title.trim() !== '')
                    .map((k) => ({
                        weight: Number(k.weight) || 1,
                        start_value: Number(k.start_value) || 0,
                        current_value: Number(k.start_value) || 0,
                        target_value: Number(k.target_value) || 0,
                    })),
            ),
        [krs],
    );

    const step0Valid =
        form.data.user_id !== '' &&
        form.data.title.trim() !== '' &&
        form.data.goal_type !== '';
    const timingValid =
        form.data.start_date !== '' && form.data.due_date !== '';
    const canSubmit = step0Valid && timingValid;

    const completeness = (() => {
        let done = 0;
        const total = 4;
        if (form.data.user_id) done++;
        if (form.data.title.trim()) done++;
        if (!isEdit && krs.some((k) => k.title.trim())) done++;
        else if (isEdit) done++;
        if (timingValid) done++;
        return Math.round((done / total) * 100);
    })();

    const updateKr = (key: string, patch: Partial<KrRow>) =>
        setKrs((prev) =>
            prev.map((k) => (k.key === key ? { ...k, ...patch } : k)),
        );

    const submit = (stay = false) => {
        form.transform((data) => ({
            ...data,
            category: data.category.trim() === '' ? null : data.category.trim(),
            tags,
            parent_goal_id:
                data.parent_goal_id === NONE ? null : data.parent_goal_id,
            cycle_id: data.cycle_id === NONE ? null : data.cycle_id,
            stay,
            ...(isEdit
                ? {}
                : {
                      key_results: krs
                          .filter((k) => k.title.trim() !== '')
                          .map((k) => ({
                              title: k.title.trim(),
                              kr_type: k.kr_type,
                              start_value: Number(k.start_value) || 0,
                              target_value: Number(k.target_value) || 0,
                              unit: k.unit.trim() || null,
                              weight: Number(k.weight) || 1,
                          })),
                  }),
        }));

        const opts = {
            preserveScroll: true,
            onSuccess: () => {
                if (stay) {
                    form.reset();
                    setKrs([newKr()]);
                    wizard.reset();
                } else {
                    close();
                }
            },
            onError: () => {
                if (
                    form.errors.user_id ||
                    form.errors.title ||
                    form.errors.goal_type
                )
                    wizard.goTo(0);
            },
        };

        if (isEdit) form.put(`/hr/goals/${goal!.id}`, opts);
        else form.post('/hr/goals', opts);
    };

    return (
        <WizardShell
            open={open}
            onClose={close}
            title={isEdit ? 'Edit objective' : 'New objective'}
            description="Set an OKR objective with measurable key results."
            railIcon={Target}
            railTitle={isEdit ? 'Edit objective' : 'New objective'}
            railSub="Goals & OKRs"
            steps={steps}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={completeness}
            footerStart={
                wizard.isFirst ? null : (
                    <button
                        type="button"
                        onClick={wizard.back}
                        className="rounded-md px-3 py-2 text-sm font-semibold text-muted-foreground hover:bg-muted"
                    >
                        Back
                    </button>
                )
            }
            footerEnd={
                <>
                    <button
                        type="button"
                        onClick={close}
                        className="rounded-md px-3 py-2 text-sm font-semibold text-muted-foreground hover:bg-muted"
                    >
                        Cancel
                    </button>
                    {wizard.isLast ? (
                        <>
                            {!isEdit && (
                                <button
                                    type="button"
                                    onClick={() => submit(true)}
                                    disabled={!canSubmit || form.processing}
                                    className="rounded-md border border-border bg-card px-3 py-2 text-sm font-semibold text-foreground hover:bg-muted disabled:opacity-50"
                                >
                                    Save &amp; add another
                                </button>
                            )}
                            <button
                                type="button"
                                onClick={() => submit(false)}
                                disabled={!canSubmit || form.processing}
                                className={cn(
                                    'rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground',
                                    (!canSubmit || form.processing) &&
                                        'cursor-not-allowed opacity-50',
                                )}
                            >
                                {form.processing
                                    ? 'Saving…'
                                    : isEdit
                                      ? 'Save changes'
                                      : 'Create objective'}
                            </button>
                        </>
                    ) : (
                        <button
                            type="button"
                            onClick={wizard.next}
                            disabled={wizard.index === 0 && !step0Valid}
                            className={cn(
                                'rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground',
                                wizard.index === 0 &&
                                    !step0Valid &&
                                    'cursor-not-allowed opacity-50',
                            )}
                        >
                            Continue
                        </button>
                    )}
                </>
            }
        >
            {stepKey === 'objective' && (
                <WizardStepPane>
                    <StepHead
                        icon={Target}
                        title="Objective"
                        blurb="The owner, the objective, and how it cascades."
                    />
                    {!isEdit && templates.length > 0 && (
                        <div className="mb-4">
                            <Field label="Start from template" hint="optional">
                                <SelectInput
                                    value={templateId}
                                    onChange={applyTemplate}
                                    placeholder="Blank objective"
                                    options={[
                                        {
                                            value: NONE,
                                            label: 'Blank objective',
                                        },
                                        ...templates.map((t) => ({
                                            value: String(t.id),
                                            label: t.name,
                                        })),
                                    ]}
                                />
                            </Field>
                        </div>
                    )}
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Owner"
                            required
                            span
                            error={form.errors.user_id}
                        >
                            <SelectInput
                                value={form.data.user_id}
                                onChange={(v) => form.setData('user_id', v)}
                                placeholder="Select an owner…"
                                options={owners.map((o) => ({
                                    value: String(o.id),
                                    label: o.name,
                                }))}
                            />
                        </Field>
                        <Field
                            label="Title"
                            required
                            span
                            error={form.errors.title}
                        >
                            <Input
                                value={form.data.title}
                                onChange={(e) =>
                                    form.setData('title', e.target.value)
                                }
                                placeholder="e.g. Eliminate preventable medication errors"
                            />
                        </Field>
                        <Field
                            label="Description"
                            hint="optional"
                            span
                            error={form.errors.description}
                        >
                            <Textarea
                                rows={2}
                                value={form.data.description}
                                onChange={(e) =>
                                    form.setData('description', e.target.value)
                                }
                                placeholder="What does success look like?"
                            />
                        </Field>
                        <Field
                            label="Type"
                            required
                            span
                            error={form.errors.goal_type}
                        >
                            <TilePicker
                                value={form.data.goal_type}
                                onChange={(v) => form.setData('goal_type', v)}
                                cols={3}
                                options={[
                                    {
                                        key: 'company',
                                        label: 'Company',
                                        icon: Building2,
                                    },
                                    { key: 'team', label: 'Team', icon: Users },
                                    {
                                        key: 'individual',
                                        label: 'Individual',
                                        icon: UserIcon,
                                    },
                                ]}
                            />
                        </Field>
                        <Field
                            label="Priority"
                            required
                            error={form.errors.priority}
                        >
                            <Segmented
                                value={form.data.priority}
                                onChange={(v) => form.setData('priority', v)}
                                options={[
                                    { value: 'low', label: 'Low' },
                                    { value: 'medium', label: 'Medium' },
                                    { value: 'high', label: 'High' },
                                ]}
                            />
                        </Field>
                        <Field
                            label="Category"
                            hint="optional"
                            error={form.errors.category}
                        >
                            <Input
                                value={form.data.category}
                                onChange={(e) =>
                                    form.setData('category', e.target.value)
                                }
                                placeholder="e.g. Safety, Quality, Growth"
                            />
                        </Field>
                        <Field
                            label="Parent objective"
                            hint="optional"
                            error={form.errors.parent_goal_id}
                        >
                            <SelectInput
                                value={form.data.parent_goal_id}
                                onChange={(v) =>
                                    form.setData('parent_goal_id', v)
                                }
                                placeholder="Top-level (no parent)"
                                options={parentOptions}
                            />
                        </Field>
                        <Field label="Cycle" error={form.errors.cycle_id}>
                            <SelectInput
                                value={form.data.cycle_id}
                                onChange={(v) => form.setData('cycle_id', v)}
                                placeholder="Select a cycle"
                                options={cycleOptions}
                            />
                        </Field>
                        <Field label="Tags" hint="optional" span>
                            <div className="flex flex-wrap items-center gap-1.5 rounded-lg border border-border bg-card px-2 py-1.5">
                                {tags.map((t) => (
                                    <span
                                        key={t}
                                        className="inline-flex items-center gap-1 rounded-md bg-primary/10 px-1.5 py-0.5 text-[11px] font-semibold text-primary"
                                    >
                                        {t}
                                        <button
                                            type="button"
                                            onClick={() =>
                                                setTags((p) =>
                                                    p.filter((x) => x !== t),
                                                )
                                            }
                                            aria-label={`Remove ${t}`}
                                            className="text-primary/70 hover:text-primary"
                                        >
                                            ×
                                        </button>
                                    </span>
                                ))}
                                <input
                                    value={tagDraft}
                                    onChange={(e) =>
                                        setTagDraft(e.target.value)
                                    }
                                    onKeyDown={(e) => {
                                        if (
                                            e.key === 'Enter' ||
                                            e.key === ','
                                        ) {
                                            e.preventDefault();
                                            addTag();
                                        }
                                    }}
                                    onBlur={addTag}
                                    placeholder={
                                        tags.length
                                            ? ''
                                            : 'Add tags (Enter to add)…'
                                    }
                                    className="min-w-[120px] flex-1 bg-transparent text-[13px] outline-none"
                                />
                            </div>
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {stepKey === 'krs' && (
                <WizardStepPane>
                    <StepHead
                        icon={ListChecks}
                        title="Key results"
                        blurb="Add the measurable results — baseline → target. Weights drive the roll-up."
                    />
                    <div className="flex flex-col gap-4 lg:flex-row">
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-col gap-3">
                                {krs.map((k, i) => (
                                    <div
                                        key={k.key}
                                        className="rounded-xl border border-border bg-sidebar p-3.5"
                                    >
                                        <div className="mb-2.5 flex items-center gap-2">
                                            <span className="grid h-[22px] w-[22px] place-items-center rounded-md bg-primary text-[11px] font-bold text-primary-foreground">
                                                {i + 1}
                                            </span>
                                            <Input
                                                value={k.title}
                                                onChange={(e) =>
                                                    updateKr(k.key, {
                                                        title: e.target.value,
                                                    })
                                                }
                                                placeholder="Key result title"
                                                className="h-8 flex-1"
                                            />
                                            {krs.length > 1 && (
                                                <button
                                                    type="button"
                                                    aria-label="Remove key result"
                                                    onClick={() =>
                                                        setKrs((prev) =>
                                                            prev.filter(
                                                                (x) =>
                                                                    x.key !==
                                                                    k.key,
                                                            ),
                                                        )
                                                    }
                                                    className="grid h-8 w-8 place-items-center rounded-md border border-border bg-card text-muted-foreground hover:bg-muted"
                                                >
                                                    <Minus className="h-3.5 w-3.5" />
                                                </button>
                                            )}
                                        </div>
                                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-5">
                                            <label className="block">
                                                <span className="mb-1 block text-[10px] font-semibold text-muted-foreground">
                                                    Type
                                                </span>
                                                <SelectInput
                                                    value={k.kr_type}
                                                    onChange={(v) =>
                                                        updateKr(k.key, {
                                                            kr_type:
                                                                v as KrType,
                                                        })
                                                    }
                                                    placeholder="Type"
                                                    options={KR_TYPE_OPTIONS}
                                                />
                                            </label>
                                            <label className="block">
                                                <span className="mb-1 block text-[10px] font-semibold text-muted-foreground">
                                                    Baseline
                                                </span>
                                                <Input
                                                    value={k.start_value}
                                                    onChange={(e) =>
                                                        updateKr(k.key, {
                                                            start_value:
                                                                e.target.value,
                                                        })
                                                    }
                                                    className="h-8"
                                                />
                                            </label>
                                            <label className="block">
                                                <span className="mb-1 block text-[10px] font-semibold text-muted-foreground">
                                                    Target
                                                </span>
                                                <Input
                                                    value={k.target_value}
                                                    onChange={(e) =>
                                                        updateKr(k.key, {
                                                            target_value:
                                                                e.target.value,
                                                        })
                                                    }
                                                    className="h-8"
                                                />
                                            </label>
                                            <label className="block">
                                                <span className="mb-1 block text-[10px] font-semibold text-muted-foreground">
                                                    Unit
                                                </span>
                                                <Input
                                                    value={k.unit}
                                                    onChange={(e) =>
                                                        updateKr(k.key, {
                                                            unit: e.target
                                                                .value,
                                                        })
                                                    }
                                                    className="h-8"
                                                />
                                            </label>
                                            <label className="block">
                                                <span className="mb-1 block text-[10px] font-semibold text-muted-foreground">
                                                    Weight
                                                </span>
                                                <Input
                                                    value={k.weight}
                                                    onChange={(e) =>
                                                        updateKr(k.key, {
                                                            weight: e.target
                                                                .value,
                                                        })
                                                    }
                                                    className="h-8"
                                                />
                                            </label>
                                        </div>
                                    </div>
                                ))}
                                <button
                                    type="button"
                                    onClick={() =>
                                        setKrs((prev) => [...prev, newKr()])
                                    }
                                    className="inline-flex items-center justify-center gap-2 rounded-lg border border-dashed border-border py-2.5 text-[13px] font-semibold text-primary hover:bg-muted/40"
                                >
                                    <Plus className="h-4 w-4" /> Add another key
                                    result
                                </button>
                            </div>
                        </div>
                        <div className="w-full shrink-0 lg:w-[188px]">
                            <div className="rounded-xl border border-border bg-gradient-to-b from-primary/5 to-card p-4 text-center">
                                <div className="mb-3 text-[10.5px] font-bold tracking-wide text-muted-foreground uppercase">
                                    Roll-up preview
                                </div>
                                <div
                                    className="relative mx-auto h-24 w-24 rounded-full"
                                    style={{
                                        background: `conic-gradient(var(--primary) ${previewRollup}%, var(--muted) 0)`,
                                    }}
                                >
                                    <div className="absolute inset-[9px] grid place-items-center rounded-full bg-card text-2xl font-extrabold tabular-nums">
                                        {previewRollup}%
                                    </div>
                                </div>
                                <p className="mt-3 text-[11.5px] leading-snug text-muted-foreground">
                                    Weighted from your key results at their
                                    baseline.
                                </p>
                            </div>
                        </div>
                    </div>
                </WizardStepPane>
            )}

            {stepKey === 'timing' && (
                <WizardStepPane>
                    <StepHead
                        icon={CalendarClock}
                        title="Timing & confidence"
                        blurb="The objective window and your starting confidence."
                    />
                    <div className="grid max-w-xl gap-4 sm:grid-cols-2">
                        <Field
                            label="Start date"
                            required
                            error={form.errors.start_date}
                        >
                            <Input
                                type="date"
                                value={form.data.start_date}
                                onChange={(e) =>
                                    form.setData('start_date', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Due date"
                            required
                            error={form.errors.due_date}
                        >
                            <Input
                                type="date"
                                value={form.data.due_date}
                                onChange={(e) =>
                                    form.setData('due_date', e.target.value)
                                }
                            />
                        </Field>
                        <Field label="Starting confidence" span>
                            <Segmented
                                value={form.data.confidence}
                                onChange={(v) => form.setData('confidence', v)}
                                options={[
                                    { value: 'on_track', label: 'On track' },
                                    { value: 'at_risk', label: 'At risk' },
                                    { value: 'off_track', label: 'Off track' },
                                ]}
                            />
                        </Field>
                        <Field label="Check-in cadence" span>
                            <Segmented
                                value={form.data.checkin_frequency}
                                onChange={(v) =>
                                    form.setData('checkin_frequency', v)
                                }
                                options={[
                                    { value: 'weekly', label: 'Weekly' },
                                    {
                                        value: 'fortnightly',
                                        label: 'Fortnightly',
                                    },
                                    { value: 'monthly', label: 'Monthly' },
                                    { value: 'quarterly', label: 'Quarterly' },
                                ]}
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {stepKey === 'review' && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Review & create"
                        blurb="Confirm the objective — its key results attach in the same step."
                    />
                    <div className="flex flex-wrap gap-4">
                        <div className="min-w-[280px] flex-1">
                            <ReviewCard
                                icon={Target}
                                title="Objective"
                                onEdit={() => wizard.goTo(0)}
                            >
                                <ReviewRow label="Owner" value={ownerName} />
                                <ReviewRow
                                    label="Title"
                                    value={form.data.title}
                                />
                                <ReviewRow
                                    label="Type"
                                    value={form.data.goal_type}
                                />
                                <ReviewRow
                                    label="Priority"
                                    value={form.data.priority}
                                />
                                <ReviewRow
                                    label="Category"
                                    value={form.data.category || undefined}
                                />
                                <ReviewRow
                                    label="Cycle"
                                    value={
                                        cycles.find(
                                            (c) =>
                                                String(c.id) ===
                                                form.data.cycle_id,
                                        )?.name
                                    }
                                />
                                <ReviewRow
                                    label="Window"
                                    value={
                                        form.data.start_date &&
                                        form.data.due_date
                                            ? `${form.data.start_date} → ${form.data.due_date}`
                                            : undefined
                                    }
                                />
                                {!isEdit && (
                                    <ReviewRow
                                        label="Key results"
                                        value={
                                            krs.filter((k) => k.title.trim())
                                                .length || undefined
                                        }
                                    />
                                )}
                            </ReviewCard>
                        </div>
                        {!isEdit && (
                            <div className="w-[170px] shrink-0 rounded-xl border border-border bg-sidebar p-4 text-center">
                                <div className="mb-2.5 text-[10.5px] font-bold tracking-wide text-muted-foreground uppercase">
                                    Roll-up
                                </div>
                                <div className="text-3xl font-extrabold tabular-nums">
                                    {previewRollup}%
                                </div>
                            </div>
                        )}
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

export default ObjectiveWizard;
