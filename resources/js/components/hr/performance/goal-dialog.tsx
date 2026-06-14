/* eslint-disable no-restricted-syntax -- Wizard footer uses native buttons to
 * match the Add-Client modal chrome (see components/wizard/shell.tsx). */
import { useForm } from '@inertiajs/react';
import { ClipboardCheck, Gauge, Target } from 'lucide-react';
import { useMemo } from 'react';

import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

import { PeoplePicker, type PersonOption } from '../people-picker';
import {
    Field,
    ReviewCard,
    ReviewRow,
    SelectInput,
    StepHead,
    useWizard,
    WizardShell,
    WizardStepPane,
    type WizardStep,
} from '../wizard';

export interface GoalOwner {
    id: number;
    name: string;
    email?: string;
}

export interface GoalOption {
    value: string;
    label: string;
}

export interface ParentGoalOption {
    id: number;
    title: string;
}

export interface ExistingGoal {
    id: number;
    user?: { id: number; name: string } | null;
    title: string;
    description?: string | null;
    goal_type: string;
    priority: string;
    parent_goal_id?: number | null;
    target_value?: number | string | null;
    unit?: string | null;
    start_date: string | null;
    due_date: string | null;
}

const STEPS: readonly WizardStep[] = [
    { key: 'objective', label: 'Objective', blurb: 'What & who', icon: Target },
    { key: 'target', label: 'Target', blurb: 'Measure & dates', icon: Gauge },
    { key: 'review', label: 'Review', blurb: 'Confirm & save', icon: ClipboardCheck },
];

const NO_PARENT = '__none__';

/**
 * Create / edit an OKR-style goal in a WizardShell modal, replacing the
 * page-based goals/create form. Create POSTs hr.goals.store; edit PUTs
 * hr.goals.update (which previously had no UI caller). Owner is fixed in edit.
 */
export function GoalDialog({
    open,
    onClose,
    owners,
    goalTypes,
    priorities,
    parentGoals,
    goal,
}: {
    open: boolean;
    onClose: () => void;
    owners: GoalOwner[];
    goalTypes: GoalOption[];
    priorities: GoalOption[];
    parentGoals: ParentGoalOption[];
    goal?: ExistingGoal | null;
}) {
    const isEdit = !!goal;
    const wizard = useWizard(STEPS.length);
    const form = useForm<{
        user_id: string;
        title: string;
        description: string;
        goal_type: string;
        priority: string;
        parent_goal_id: string;
        target_value: string;
        unit: string;
        start_date: string;
        due_date: string;
    }>({
        user_id: goal?.user?.id ? String(goal.user.id) : '',
        title: goal?.title ?? '',
        description: goal?.description ?? '',
        goal_type: goal?.goal_type ?? '',
        priority: goal?.priority ?? 'medium',
        parent_goal_id: goal?.parent_goal_id ? String(goal.parent_goal_id) : '',
        target_value: goal?.target_value != null ? String(goal.target_value) : '',
        unit: goal?.unit ?? '',
        start_date: goal?.start_date ?? '',
        due_date: goal?.due_date ?? '',
    });

    const close = () => {
        form.reset();
        form.clearErrors();
        wizard.reset();
        onClose();
    };

    const people: PersonOption[] = useMemo(
        () =>
            owners.map((o) => ({
                value: String(o.id),
                label: o.name,
                sub: o.email,
            })),
        [owners],
    );

    // A goal can't be its own parent.
    const parentOptions = useMemo(
        () => [
            { value: NO_PARENT, label: 'No parent (top-level)' },
            ...parentGoals
                .filter((p) => !goal || p.id !== goal.id)
                .map((p) => ({ value: String(p.id), label: p.title })),
        ],
        [parentGoals, goal],
    );

    const ownerName =
        goal?.user?.name ??
        owners.find((o) => String(o.id) === form.data.user_id)?.name ??
        '—';
    const typeLabel =
        goalTypes.find((t) => t.value === form.data.goal_type)?.label ?? '—';
    const priorityLabel =
        priorities.find((p) => p.value === form.data.priority)?.label ?? '—';

    const canSubmit =
        form.data.user_id !== '' &&
        form.data.title.trim() !== '' &&
        form.data.goal_type !== '' &&
        form.data.priority !== '' &&
        form.data.start_date !== '' &&
        form.data.due_date !== '';

    const submit = () => {
        form.transform((data) => ({
            ...data,
            target_value: data.target_value === '' ? null : data.target_value,
            parent_goal_id:
                data.parent_goal_id === '' || data.parent_goal_id === NO_PARENT
                    ? null
                    : data.parent_goal_id,
        }));

        const opts = {
            preserveScroll: true,
            onSuccess: () => close(),
            onError: () => {
                if (
                    form.errors.user_id ||
                    form.errors.title ||
                    form.errors.goal_type ||
                    form.errors.priority ||
                    form.errors.start_date ||
                    form.errors.due_date
                ) {
                    wizard.goTo(0);
                }
            },
        };

        if (isEdit) {
            form.put(`/hr/goals/${goal!.id}`, opts);
        } else {
            form.post('/hr/goals', opts);
        }
    };

    return (
        <WizardShell
            open={open}
            onClose={close}
            title={isEdit ? 'Edit goal' : 'New goal'}
            description="Set an OKR-style objective for a staff member, team, or the company."
            railIcon={Target}
            railTitle={isEdit ? 'Edit goal' : 'New goal'}
            railSub="Goals & OKRs"
            steps={STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={wizard.progress}
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
                        <button
                            type="button"
                            onClick={submit}
                            disabled={!canSubmit || form.processing}
                            className={cn(
                                'rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-opacity',
                                (!canSubmit || form.processing) &&
                                    'cursor-not-allowed opacity-50',
                            )}
                        >
                            {form.processing
                                ? 'Saving…'
                                : isEdit
                                  ? 'Save changes'
                                  : 'Create goal'}
                        </button>
                    ) : (
                        <button
                            type="button"
                            onClick={wizard.next}
                            className="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground"
                        >
                            Continue
                        </button>
                    )}
                </>
            }
        >
            {wizard.index === 0 && (
                <WizardStepPane>
                    <StepHead
                        icon={Target}
                        title="Objective"
                        blurb="The owner, the objective, and how it cascades."
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Owner"
                            required
                            span
                            error={form.errors.user_id}
                        >
                            {isEdit ? (
                                <Input value={ownerName} disabled />
                            ) : (
                                <PeoplePicker
                                    value={form.data.user_id}
                                    onChange={(v) => form.setData('user_id', v)}
                                    people={people}
                                    placeholder="Select an owner…"
                                />
                            )}
                        </Field>
                        <Field label="Title" required span error={form.errors.title}>
                            <Input
                                value={form.data.title}
                                onChange={(e) =>
                                    form.setData('title', e.target.value)
                                }
                                placeholder="e.g. Reduce med errors by 20%"
                            />
                        </Field>
                        <Field
                            label="Description"
                            hint="optional"
                            span
                            error={form.errors.description}
                        >
                            <Textarea
                                rows={3}
                                value={form.data.description}
                                onChange={(e) =>
                                    form.setData('description', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Goal type"
                            required
                            error={form.errors.goal_type}
                        >
                            <SelectInput
                                value={form.data.goal_type}
                                onChange={(v) => form.setData('goal_type', v)}
                                placeholder="Select a type"
                                options={goalTypes}
                            />
                        </Field>
                        <Field
                            label="Priority"
                            required
                            error={form.errors.priority}
                        >
                            <SelectInput
                                value={form.data.priority}
                                onChange={(v) => form.setData('priority', v)}
                                placeholder="Select a priority"
                                options={priorities}
                            />
                        </Field>
                        <Field
                            label="Parent goal"
                            hint="optional"
                            span
                            error={form.errors.parent_goal_id}
                        >
                            <SelectInput
                                value={form.data.parent_goal_id || NO_PARENT}
                                onChange={(v) =>
                                    form.setData(
                                        'parent_goal_id',
                                        v === NO_PARENT ? '' : v,
                                    )
                                }
                                placeholder="No parent (top-level)"
                                options={parentOptions}
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={Gauge}
                        title="Target & timing"
                        blurb="An optional measurable target and the goal window."
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Target value"
                            hint="optional"
                            error={form.errors.target_value}
                        >
                            <Input
                                type="number"
                                min="0"
                                step="0.01"
                                value={form.data.target_value}
                                onChange={(e) =>
                                    form.setData('target_value', e.target.value)
                                }
                                placeholder="e.g. 20"
                            />
                        </Field>
                        <Field label="Unit" hint="optional" error={form.errors.unit}>
                            <Input
                                value={form.data.unit}
                                onChange={(e) =>
                                    form.setData('unit', e.target.value)
                                }
                                placeholder="e.g. %, hours, count"
                            />
                        </Field>
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
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 2 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Review & save"
                        blurb={
                            isEdit
                                ? 'Save your changes to this goal.'
                                : 'Create the goal — you can add key results next.'
                        }
                    />
                    <ReviewCard
                        icon={Target}
                        title="Goal"
                        onEdit={() => wizard.goTo(0)}
                    >
                        <ReviewRow label="Owner" value={ownerName} />
                        <ReviewRow label="Title" value={form.data.title} />
                        <ReviewRow label="Type" value={typeLabel} />
                        <ReviewRow label="Priority" value={priorityLabel} />
                        <ReviewRow
                            label="Target"
                            value={
                                form.data.target_value
                                    ? `${form.data.target_value} ${form.data.unit}`.trim()
                                    : undefined
                            }
                        />
                        <ReviewRow
                            label="Window"
                            value={
                                form.data.start_date && form.data.due_date
                                    ? `${form.data.start_date} → ${form.data.due_date}`
                                    : undefined
                            }
                        />
                    </ReviewCard>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

export default GoalDialog;
