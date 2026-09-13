import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';
import { Clock3 } from 'lucide-react';

export interface ShiftTaskListItem {
    follow_through?: 'accepted_help' | null;
    help?: { status: string; recipient_name: string } | null;
    steps?: { id: string; label: string; is_completed: boolean }[];
    id: number;
    label: string;
    scheduled_time?: string | null;
    scheduled_for?: string | null;
    is_completed: boolean;
    completed_at: string | null;
    version?: number;
    can_complete?: boolean;
}

export default function ShiftTaskList({
    tasks,
    maxVisible = 4,
    onTasksChange,
    submitOnToggle = true,
    onOpenTask,
}: {
    tasks: ShiftTaskListItem[];
    maxVisible?: number;
    onTasksChange?: (next: ShiftTaskListItem[]) => void;
    submitOnToggle?: boolean;
    onOpenTask?: (id: number) => void;
}) {
    const [items, setItems] = useState(tasks);
    const [showAll, setShowAll] = useState(false);
    const [pendingIds, setPendingIds] = useState<Record<number, true>>({});

    useEffect(() => setItems(tasks), [tasks]);

    if (items.length === 0) {
        return (
            <p className="rounded-lg border border-dashed bg-background/70 px-3 py-4 text-sm text-muted-foreground">
                No shift tasks are listed.
            </p>
        );
    }

    const visibleItems = showAll ? items : items.slice(0, maxVisible);

    const toggleTask = (task: ShiftTaskListItem) => {
        if (
            pendingIds[task.id] ||
            task.can_complete === false ||
            (!task.is_completed &&
                task.steps?.some((step) => !step.is_completed))
        )
            return;

        const previous = items;
        const nextState = !task.is_completed;
        setPendingIds((prev) => ({ ...prev, [task.id]: true }));
        const optimistic = items.map((item) =>
            item.id === task.id
                ? {
                      ...item,
                      is_completed: nextState,
                      completed_at: nextState ? new Date().toISOString() : null,
                  }
                : item,
        );
        setItems(optimistic);
        onTasksChange?.(optimistic);

        if (!submitOnToggle) {
            setPendingIds((prev) => {
                const next = { ...prev };
                delete next[task.id];
                return next;
            });
            return;
        }

        router.post(
            `/my-tasks/shift-task/${task.id}/complete`,
            { is_completed: nextState, expected_version: task.version ?? 0 },
            {
                preserveScroll: true,
                onError: () => {
                    setItems(previous);
                    onTasksChange?.(previous);
                },
                onFinish: () =>
                    setPendingIds((prev) => {
                        const next = { ...prev };
                        delete next[task.id];
                        return next;
                    }),
            },
        );
    };

    return (
        <div className="space-y-2">
            {visibleItems.map((task) => (
                <div
                    key={task.id}
                    className={cn(
                        'flex min-h-11 cursor-pointer items-start gap-3 rounded-lg border bg-background/80 px-3 py-2.5 text-sm',
                        task.is_completed && 'text-muted-foreground',
                    )}
                >
                    <Checkbox
                        aria-label={task.label}
                        checked={task.is_completed}
                        disabled={
                            !!pendingIds[task.id] ||
                            task.can_complete === false ||
                            (!task.is_completed &&
                                task.steps?.some((step) => !step.is_completed))
                        }
                        onCheckedChange={() => toggleTask(task)}
                        className="mt-0.5"
                    />
                    <span className="min-w-0 flex-1">
                        <span
                            className={cn(
                                'block leading-snug',
                                task.is_completed && 'line-through',
                            )}
                        >
                            {task.label}
                        </span>
                        {task.scheduled_time ? (
                            <span className="mt-1 inline-flex items-center gap-1 text-xs text-muted-foreground">
                                <Clock3 className="h-3 w-3" />
                                {task.scheduled_time}
                            </span>
                        ) : null}
                        {!!task.steps?.length && (
                            <span className="mt-1 block text-xs text-muted-foreground">
                                {
                                    task.steps.filter(
                                        (step) => step.is_completed,
                                    ).length
                                }{' '}
                                of {task.steps.length} steps done
                            </span>
                        )}
                        {!task.is_completed &&
                            task.follow_through === 'accepted_help' && (
                                <span className="mt-1 block text-sm text-primary">
                                    {task.help?.recipient_name} has accepted
                                    responsibility. Work remains open.
                                </span>
                            )}
                    </span>
                    {onOpenTask && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => onOpenTask(task.id)}
                        >
                            Open task
                        </Button>
                    )}
                </div>
            ))}

            {items.length > maxVisible ? (
                <Button
                    type="button"
                    variant="link"
                    className="h-auto p-0 text-sm font-medium text-foreground"
                    onClick={() => setShowAll((value) => !value)}
                >
                    {showAll
                        ? 'Show fewer tasks'
                        : `Show all ${items.length} tasks`}
                </Button>
            ) : null}
        </div>
    );
}
