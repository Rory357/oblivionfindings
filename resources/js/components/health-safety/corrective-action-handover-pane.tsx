import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, InfoCard, SelectInput } from '@/components/wizard/primitives';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { useForm } from '@inertiajs/react';
import {
    ArrowRightLeft,
    ListChecks,
    Loader2,
    UserRoundCheck,
} from 'lucide-react';
import type { FormEvent } from 'react';

export type CorrectiveActionHandover = {
    eligible_owners: Array<{ id: number; name: string }>;
    unresolved_control_room_tasks: Array<{
        id: number;
        reference: string;
        title: string;
        description: string | null;
        status: string;
        priority: string;
        due_at: string | null;
    }>;
};

type ResponsibilityChoice = '' | 'transfer_task' | 'new_responsibility';

type Props = {
    eventId: number;
    investigationId: number;
    recommendationIndex: number;
    recommendation: {
        description?: string;
        priority?: string;
    };
    handover: CorrectiveActionHandover;
    onDone: () => void;
};

const PRIORITIES = ['low', 'medium', 'high', 'critical'];

function titleCase(value: string): string {
    return value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}

export function CorrectiveActionHandoverPane({
    eventId,
    investigationId,
    recommendationIndex,
    recommendation,
    handover,
    onDone,
}: Props) {
    const initialPriority = PRIORITIES.includes(recommendation.priority ?? '')
        ? (recommendation.priority as string)
        : 'medium';
    const form = useForm<{
        recommendation_index: number;
        assigned_to_user_id: number | null;
        due_date: string;
        priority: string;
        responsibility_choice: ResponsibilityChoice;
        source_control_room_task_id: number | null;
        new_responsibility_reason: string;
    }>({
        recommendation_index: recommendationIndex,
        assigned_to_user_id: null,
        due_date: '',
        priority: initialPriority,
        responsibility_choice: '',
        source_control_room_task_id: null,
        new_responsibility_reason: '',
    });

    const selectedOwner =
        handover.eligible_owners.find(
            (owner) => owner.id === form.data.assigned_to_user_id,
        ) ?? null;
    const selectedTask =
        handover.unresolved_control_room_tasks.find(
            (task) => task.id === form.data.source_control_room_task_id,
        ) ?? null;
    const dueDateValid = /^\d{4}-\d{2}-\d{2}$/.test(form.data.due_date);
    const responsibilityValid =
        (form.data.responsibility_choice === 'transfer_task' &&
            selectedTask !== null) ||
        (form.data.responsibility_choice === 'new_responsibility' &&
            form.data.new_responsibility_reason.trim().length >= 10);
    const canSubmit =
        selectedOwner !== null &&
        dueDateValid &&
        PRIORITIES.includes(form.data.priority) &&
        responsibilityValid;

    const chooseResponsibility = (choice: ResponsibilityChoice) => {
        form.setData('responsibility_choice', choice);
        if (choice === 'transfer_task') {
            form.setData('new_responsibility_reason', '');
        } else {
            form.setData('source_control_room_task_id', null);
        }
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!canSubmit) return;

        form.post(
            `/health-safety/events/${eventId}/investigations/${investigationId}/seed-action`,
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    if (
                        !(page.props as { flash?: { error?: string } }).flash
                            ?.error
                    ) {
                        onDone();
                    }
                },
            },
        );
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <InfoCard icon={ListChecks} tone="info">
                <p className="font-semibold">Recommendation to hand over</p>
                <p className="mt-1">
                    {recommendation.description ?? 'Corrective action'}
                </p>
            </InfoCard>

            <div className="grid gap-3 sm:grid-cols-2">
                <Field
                    label="Action owner"
                    required
                    error={form.errors.assigned_to_user_id}
                >
                    <SelectInput
                        value={form.data.assigned_to_user_id?.toString() ?? ''}
                        onChange={(value) =>
                            form.setData(
                                'assigned_to_user_id',
                                Number(value) || null,
                            )
                        }
                        placeholder="Choose an eligible owner"
                        ariaLabel="Action owner"
                        options={handover.eligible_owners.map((owner) => ({
                            value: owner.id.toString(),
                            label: owner.name,
                        }))}
                    />
                </Field>
                <Field
                    label="Due date"
                    required
                    hint="YYYY-MM-DD"
                    error={form.errors.due_date}
                >
                    <Input
                        aria-label="Due date"
                        type="date"
                        value={form.data.due_date}
                        onChange={(event) =>
                            form.setData('due_date', event.target.value)
                        }
                    />
                </Field>
            </div>

            <Field label="Priority" required error={form.errors.priority}>
                <SelectInput
                    value={form.data.priority}
                    onChange={(value) => form.setData('priority', value)}
                    placeholder="Choose priority"
                    ariaLabel="Action priority"
                    options={PRIORITIES.map((priority) => ({
                        value: priority,
                        label: titleCase(priority),
                    }))}
                />
            </Field>

            <fieldset className="rounded-xl border border-border p-4">
                <legend className="px-1 text-sm font-bold">
                    Responsibility source
                    <span className="ml-1 text-status-critical">*</span>
                </legend>
                <p className="mb-3 text-xs text-muted-foreground">
                    Confirm whether H&S is taking over existing operational work
                    or creating a separate responsibility.
                </p>
                <div className="grid gap-2 sm:grid-cols-2">
                    <label className="flex cursor-pointer items-start gap-2 rounded-lg border border-border p-3 text-sm has-[:checked]:border-primary has-[:checked]:bg-primary/5">
                        <input
                            aria-label="Transfer this operational task"
                            type="radio"
                            name={`responsibility-${recommendationIndex}`}
                            value="transfer_task"
                            checked={
                                form.data.responsibility_choice ===
                                'transfer_task'
                            }
                            disabled={
                                handover.unresolved_control_room_tasks
                                    .length === 0
                            }
                            onChange={() =>
                                chooseResponsibility('transfer_task')
                            }
                            className="mt-1"
                        />
                        <span>
                            <span className="block font-semibold">
                                Transfer this operational task
                            </span>
                            <span className="block text-xs text-muted-foreground">
                                The selected Control Room task becomes this
                                corrective action.
                            </span>
                        </span>
                    </label>
                    <label className="flex cursor-pointer items-start gap-2 rounded-lg border border-border p-3 text-sm has-[:checked]:border-primary has-[:checked]:bg-primary/5">
                        <input
                            aria-label="Create a new H&S responsibility"
                            type="radio"
                            name={`responsibility-${recommendationIndex}`}
                            value="new_responsibility"
                            checked={
                                form.data.responsibility_choice ===
                                'new_responsibility'
                            }
                            onChange={() =>
                                chooseResponsibility('new_responsibility')
                            }
                            className="mt-1"
                        />
                        <span>
                            <span className="block font-semibold">
                                Create a new H&S responsibility
                            </span>
                            <span className="block text-xs text-muted-foreground">
                                Explain why no current operational task owns the
                                recommendation.
                            </span>
                        </span>
                    </label>
                </div>
            </fieldset>

            {handover.unresolved_control_room_tasks.length > 0 ? (
                <div className="rounded-xl border border-border bg-muted/20 p-4">
                    <p className="text-sm font-bold">
                        Unresolved Control Room tasks
                    </p>
                    <ul className="mt-2 space-y-2">
                        {handover.unresolved_control_room_tasks.map((task) => (
                            <li key={task.id} className="text-sm">
                                <span className="font-semibold">
                                    {task.title}
                                </span>
                                <span className="block text-xs text-muted-foreground">
                                    {task.reference} · {titleCase(task.status)}{' '}
                                    · {titleCase(task.priority)}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            ) : (
                <InfoCard icon={ArrowRightLeft} tone="warn">
                    There are no unresolved Control Room tasks available to
                    transfer. Create a new H&S responsibility and record why.
                </InfoCard>
            )}

            {form.data.responsibility_choice === 'transfer_task' ? (
                <Field
                    label="Source Control Room task"
                    required
                    error={form.errors.source_control_room_task_id}
                >
                    <SelectInput
                        value={
                            form.data.source_control_room_task_id?.toString() ??
                            ''
                        }
                        onChange={(value) =>
                            form.setData(
                                'source_control_room_task_id',
                                Number(value) || null,
                            )
                        }
                        placeholder="Choose the task to transfer"
                        ariaLabel="Source Control Room task"
                        options={handover.unresolved_control_room_tasks.map(
                            (task) => ({
                                value: task.id.toString(),
                                label: `${task.reference} · ${task.title}`,
                            }),
                        )}
                    />
                </Field>
            ) : null}

            {form.data.responsibility_choice === 'new_responsibility' ? (
                <Field
                    label="Why is this new work?"
                    required
                    hint="At least 10 characters"
                    error={form.errors.new_responsibility_reason}
                >
                    <Textarea
                        aria-label="Why is this new work?"
                        rows={3}
                        value={form.data.new_responsibility_reason}
                        onChange={(event) =>
                            form.setData(
                                'new_responsibility_reason',
                                event.target.value,
                            )
                        }
                        placeholder="Explain why this recommendation is not already owned by an operational task."
                    />
                </Field>
            ) : null}

            <ReviewCard icon={UserRoundCheck} title="Final handover review">
                <ReviewRow
                    label="Owner"
                    value={selectedOwner?.name ?? 'Choose an owner'}
                />
                <ReviewRow
                    label="Due date"
                    value={form.data.due_date || 'Set a due date'}
                />
                <ReviewRow
                    label="Responsibility"
                    value={
                        selectedTask
                            ? `${selectedTask.reference} · ${selectedTask.title}`
                            : form.data.responsibility_choice ===
                                'new_responsibility'
                              ? form.data.new_responsibility_reason
                              : 'Choose the responsibility source'
                    }
                />
            </ReviewCard>

            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing || !canSubmit}>
                    {form.processing ? (
                        <Loader2 className="h-4 w-4 animate-spin" />
                    ) : null}
                    Create and hand over action
                </Button>
            </div>
        </form>
    );
}
