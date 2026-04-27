import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

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
}: {
    tasks: ShiftTaskListItem[];
    maxVisible?: number;
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
        setItems((current) =>
            current.map((item) =>
                item.id === task.id
                    ? {
                          ...item,
                          is_completed: nextState,
                          completed_at: nextState
                              ? new Date().toISOString()
                              : null,
                      }
                    : item,
            ),
        );

        router.post(
            `/my-tasks/shift-task/${task.id}/complete`,
            {},
            {
                preserveScroll: true,
                onError: () => setItems(previous),
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
                <button
                    type="button"
                    className="text-sm font-medium text-primary underline-offset-4 hover:underline"
                    onClick={() => setShowAll((value) => !value)}
                >
                    {showAll
                        ? 'Show fewer tasks'
                        : `Show all ${items.length} tasks`}
                </button>
            ) : null}
        </div>
    );
}
