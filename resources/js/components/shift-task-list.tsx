import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';

export interface ShiftTaskListItem {
    id: number;
    label: string;
    is_completed: boolean;
    completed_at: string | null;
}

export default function ShiftTaskList({
    tasks,
    maxVisible = 4,
    onTasksChange,
    submitOnToggle = true,
}: {
    tasks: ShiftTaskListItem[];
    maxVisible?: number;
    onTasksChange?: (next: ShiftTaskListItem[]) => void;
    submitOnToggle?: boolean;
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
        if (pendingIds[task.id]) return;

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
            {},
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
                <label
                    key={task.id}
                    className={cn(
                        'flex min-h-11 cursor-pointer items-start gap-3 rounded-lg border bg-background/80 px-3 py-2.5 text-sm',
                        task.is_completed && 'text-muted-foreground',
                    )}
                >
                    <Checkbox
                        checked={task.is_completed}
                        disabled={!!pendingIds[task.id]}
                        onCheckedChange={() => toggleTask(task)}
                        className="mt-0.5"
                    />
                    <span
                        className={cn(
                            'leading-snug',
                            task.is_completed && 'line-through',
                        )}
                    >
                        {task.label}
                    </span>
                </label>
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
