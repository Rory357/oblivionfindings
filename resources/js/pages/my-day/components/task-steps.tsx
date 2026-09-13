import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Plus, X } from 'lucide-react';
import { useState } from 'react';
import { taskRequest } from '../lib/task-api';
import type { MyDayShiftTask } from '../lib/types';

export function TaskSteps({
    task,
    onSaved,
    disabled = false,
}: {
    task: MyDayShiftTask;
    onSaved: (task: MyDayShiftTask) => void;
    disabled?: boolean;
}) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [adding, setAdding] = useState(false);
    const [draft, setDraft] = useState(() => ({
        id: crypto.randomUUID(),
        label: '',
    }));
    const steps = task.steps ?? [];
    const done = steps.filter((step) => step.is_completed).length;
    const readOnly = task.is_completed || task.can_complete === false;
    const change = async (input: Record<string, unknown>) => {
        if (busy || disabled) return;
        setBusy(true);
        setError('');
        try {
            const result = await taskRequest<{ task: MyDayShiftTask }>(
                `/my-day/tasks/${task.id}/steps`,
                'PUT',
                { ...input, expected_version: task.version ?? 0 },
            );
            onSaved(result.task);
            if (input.action === 'add') {
                setDraft({ id: crypto.randomUUID(), label: '' });
                setAdding(false);
            }
        } catch (cause) {
            setError(
                cause instanceof Error
                    ? cause.message
                    : 'Could not save this step. Please retry.',
            );
        } finally {
            setBusy(false);
        }
    };
    return (
        <section className="space-y-3" aria-label="Task steps">
            {steps.length > 0 && (
                <>
                    <div className="flex items-center justify-between gap-3">
                        <h3 className="font-semibold">Steps to complete</h3>
                        <span
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            {done} of {steps.length} done
                        </span>
                    </div>
                    <div
                        className="h-1.5 overflow-hidden rounded bg-muted"
                        role="progressbar"
                        aria-label="Steps completed"
                        aria-valuenow={done}
                        aria-valuemin={0}
                        aria-valuemax={steps.length}
                    >
                        <div
                            className="h-full bg-primary"
                            style={{ width: `${(done / steps.length) * 100}%` }}
                        />
                    </div>
                    <ol className="divide-y rounded-lg border px-3">
                        {steps.map((step, index) => (
                            <li
                                key={step.id}
                                className="flex min-h-12 items-center gap-3 py-2"
                            >
                                <Checkbox
                                    id={`step-${step.id}`}
                                    checked={step.is_completed}
                                    disabled={readOnly || busy || disabled}
                                    onCheckedChange={(checked) =>
                                        void change({
                                            id: step.id,
                                            action: 'complete',
                                            is_completed: checked === true,
                                        })
                                    }
                                />
                                <label
                                    htmlFor={`step-${step.id}`}
                                    className={`min-w-0 flex-1 cursor-pointer text-sm break-words ${step.is_completed ? 'text-muted-foreground line-through' : ''}`}
                                >
                                    {index + 1}. {step.label}
                                </label>
                                {!readOnly && !step.is_completed && (
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        disabled={busy || disabled}
                                        aria-label={`Remove step: ${step.label}`}
                                        onClick={() =>
                                            void change({
                                                id: step.id,
                                                action: 'remove',
                                            })
                                        }
                                    >
                                        <X className="size-4" />
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ol>
                    {!task.is_completed && (
                        <p className="text-sm text-muted-foreground">
                            {done === steps.length
                                ? 'All steps are done. You can now mark the task done.'
                                : 'Finish each step, then mark the task done.'}
                        </p>
                    )}
                </>
            )}
            {!readOnly &&
                steps.length < 20 &&
                (adding ? (
                    <form
                        className="flex gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            if (draft.label.trim())
                                void change({ ...draft, action: 'add' });
                        }}
                    >
                        <Input
                            autoFocus
                            aria-label="New step"
                            placeholder="What is the next step?"
                            maxLength={180}
                            value={draft.label}
                            disabled={busy || disabled}
                            onChange={(event) =>
                                setDraft({
                                    ...draft,
                                    label: event.target.value,
                                })
                            }
                        />
                        <Button
                            type="submit"
                            disabled={busy || disabled || !draft.label.trim()}
                        >
                            Add step
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            disabled={busy || disabled}
                            onClick={() => {
                                setAdding(false);
                                setDraft({
                                    id: crypto.randomUUID(),
                                    label: '',
                                });
                            }}
                        >
                            Cancel
                        </Button>
                    </form>
                ) : (
                    <Button
                        variant="outline"
                        disabled={busy || disabled}
                        onClick={() => setAdding(true)}
                    >
                        <Plus className="size-4" />
                        {steps.length ? 'Add step' : 'Add steps (optional)'}
                    </Button>
                ))}
            {error && (
                <p role="alert" className="text-sm text-status-critical">
                    {error}
                </p>
            )}
        </section>
    );
}
