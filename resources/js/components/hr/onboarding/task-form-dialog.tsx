import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/react';
import {
    CalendarClock,
    CheckCircle2,
    ClipboardList,
    ListTodo,
} from 'lucide-react';
import { useEffect, useState } from 'react';

import {
    Field,
    ReviewCard,
    ReviewRow,
    SelectInput,
    StepHead,
    TilePicker,
    useWizard,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/hr/wizard';

import { prettyLabel } from './shared';

export interface TaskFormTarget {
    id: number;
    title: string;
    description: string | null;
    category: string;
    due_date: string | null;
    is_required: boolean;
    sign_off_required: boolean;
    assigned_to_user_id: number | null;
}

export interface OwnerOption {
    id: number;
    name: string | null;
}

const CATEGORIES = ['general', 'compliance', 'it', 'payroll', 'induction'];
const UNASSIGNED = '__none__';

const STEPS: readonly WizardStep[] = [
    {
        key: 'details',
        label: 'Details',
        blurb: 'Title, category & notes',
        icon: ClipboardList,
    },
    {
        key: 'assignment',
        label: 'Assignment & timing',
        blurb: 'Owner, due date, flags',
        icon: CalendarClock,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm & save',
        icon: CheckCircle2,
    },
];

const blankData = () => ({
    title: '',
    description: '',
    category: 'general',
    due_date: '',
    is_required: false,
    sign_off_required: false,
    assigned_to_user_id: UNASSIGNED,
});

/**
 * Add an ad-hoc task to a checklist, or edit an existing one.
 *  - add:  checklistId set, task null → POST /hr/onboarding/{checklist}/tasks
 *  - edit: task set                  → PATCH /hr/onboarding/tasks/{task}
 */
export function TaskFormDialog({
    open,
    onClose,
    checklistId,
    task,
    owners,
}: {
    open: boolean;
    onClose: () => void;
    checklistId: number;
    task: TaskFormTarget | null;
    owners: OwnerOption[];
}) {
    const wizard = useWizard(STEPS.length);
    const [done, setDone] = useState(false);
    const form = useForm(blankData());

    useEffect(() => {
        if (!open) return;
        form.setData(
            task
                ? {
                      title: task.title,
                      description: task.description ?? '',
                      category: task.category || 'general',
                      due_date: task.due_date ?? '',
                      is_required: task.is_required,
                      sign_off_required: task.sign_off_required,
                      assigned_to_user_id: task.assigned_to_user_id
                          ? String(task.assigned_to_user_id)
                          : UNASSIGNED,
                  }
                : blankData(),
        );
        form.clearErrors();
        setDone(false);
        wizard.reset();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, task?.id]);

    const canContinue = form.data.title.trim() !== '';

    const ownerName =
        form.data.assigned_to_user_id === UNASSIGNED
            ? 'Unassigned'
            : (owners.find(
                  (o) => String(o.id) === form.data.assigned_to_user_id,
              )?.name ?? 'Unassigned');

    const submit = () => {
        form.transform((data) => ({
            ...data,
            description: data.description || null,
            due_date: data.due_date || null,
            assigned_to_user_id:
                data.assigned_to_user_id === UNASSIGNED
                    ? null
                    : data.assigned_to_user_id,
        }));
        const opts = {
            preserveScroll: true,
            onSuccess: () => setDone(true),
        } as const;
        if (task) {
            form.patch(`/hr/onboarding/tasks/${task.id}`, opts);
        } else {
            form.post(`/hr/onboarding/${checklistId}/tasks`, opts);
        }
    };

    const addAnother = () => {
        form.setData(blankData());
        form.clearErrors();
        setDone(false);
        wizard.reset();
    };

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title={task ? 'Edit task' : 'Add task'}
            description={
                task
                    ? 'Update this task or reassign its owner.'
                    : 'Ad-hoc task for this checklist.'
            }
            railIcon={ListTodo}
            railTitle={task ? 'Edit task' : 'Add task'}
            railSub="Onboarding checklist"
            steps={STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={wizard.progress}
            success={
                done ? (
                    <WizardSuccessPane
                        title={task ? 'Task updated' : 'Task added'}
                        blurb={
                            <>
                                “{form.data.title || 'Task'}” is{' '}
                                {task ? 'updated' : 'now on this checklist'}.
                            </>
                        }
                        actions={
                            <>
                                {!task ? (
                                    <Button
                                        variant="outline"
                                        onClick={addAnother}
                                    >
                                        Add another task
                                    </Button>
                                ) : null}
                                <Button onClick={onClose}>Done</Button>
                            </>
                        }
                    />
                ) : undefined
            }
            footerStart={
                wizard.isFirst ? null : (
                    <Button variant="outline" onClick={wizard.back}>
                        Back
                    </Button>
                )
            }
            footerEnd={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    {wizard.isLast ? (
                        <Button
                            onClick={submit}
                            disabled={form.processing || !canContinue}
                        >
                            {form.processing
                                ? 'Saving…'
                                : task
                                  ? 'Save task'
                                  : 'Add task'}
                        </Button>
                    ) : (
                        <Button
                            onClick={wizard.next}
                            disabled={wizard.index === 0 && !canContinue}
                        >
                            Continue
                        </Button>
                    )}
                </>
            }
        >
            {wizard.index === 0 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardList}
                        title="Describe the task"
                        blurb="What needs doing, and which part of onboarding it belongs to."
                    />
                    <div className="space-y-4">
                        <Field label="Title" required error={form.errors.title}>
                            <Input
                                value={form.data.title}
                                onChange={(e) =>
                                    form.setData('title', e.target.value)
                                }
                                placeholder="e.g. Order uniform"
                            />
                        </Field>
                        <Field label="Category">
                            <TilePicker
                                value={form.data.category}
                                onChange={(v) => form.setData('category', v)}
                                cols={3}
                                options={CATEGORIES.map((c) => ({
                                    key: c,
                                    label: prettyLabel(c),
                                }))}
                            />
                        </Field>
                        <Field label="Description" hint="optional">
                            <Textarea
                                rows={3}
                                value={form.data.description}
                                onChange={(e) =>
                                    form.setData('description', e.target.value)
                                }
                                placeholder="Optional details…"
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={CalendarClock}
                        title="Assignment & timing"
                        blurb="Who owns this task, when it's due and how strict it is."
                    />
                    <div className="grid gap-3.5 sm:grid-cols-2">
                        <Field
                            label="Owner"
                            error={form.errors.assigned_to_user_id}
                        >
                            <SelectInput
                                value={form.data.assigned_to_user_id}
                                onChange={(v) =>
                                    form.setData('assigned_to_user_id', v)
                                }
                                placeholder="Unassigned"
                                options={[
                                    { value: UNASSIGNED, label: 'Unassigned' },
                                    ...owners.map((o) => ({
                                        value: String(o.id),
                                        label: o.name ?? `User #${o.id}`,
                                    })),
                                ]}
                            />
                        </Field>
                        <Field label="Due date" error={form.errors.due_date}>
                            <Input
                                type="date"
                                value={form.data.due_date}
                                onChange={(e) =>
                                    form.setData('due_date', e.target.value)
                                }
                            />
                        </Field>
                    </div>
                    <div className="mt-4 flex gap-5">
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={form.data.is_required}
                                onCheckedChange={(c) =>
                                    form.setData('is_required', Boolean(c))
                                }
                            />
                            Required
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={form.data.sign_off_required}
                                onCheckedChange={(c) =>
                                    form.setData(
                                        'sign_off_required',
                                        Boolean(c),
                                    )
                                }
                            />
                            Sign-off required
                        </label>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 2 && (
                <WizardStepPane>
                    <StepHead
                        icon={CheckCircle2}
                        title="Review the task"
                        blurb="Check the details, then confirm below."
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard
                            icon={ClipboardList}
                            title="Details"
                            onEdit={() => wizard.goTo(0)}
                        >
                            <ReviewRow label="Title" value={form.data.title} />
                            <ReviewRow
                                label="Category"
                                value={prettyLabel(form.data.category)}
                            />
                            <ReviewRow
                                label="Description"
                                value={form.data.description}
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={CalendarClock}
                            title="Assignment & timing"
                            onEdit={() => wizard.goTo(1)}
                        >
                            <ReviewRow label="Owner" value={ownerName} />
                            <ReviewRow
                                label="Due date"
                                value={form.data.due_date}
                            />
                            <ReviewRow
                                label="Required"
                                value={form.data.is_required ? 'Yes' : 'No'}
                            />
                            <ReviewRow
                                label="Sign-off"
                                value={
                                    form.data.sign_off_required ? 'Yes' : 'No'
                                }
                            />
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

export default TaskFormDialog;
