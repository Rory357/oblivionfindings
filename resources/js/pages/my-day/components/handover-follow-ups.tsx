import { Button } from '@/components/ui/button';
import { useState } from 'react';
import { taskRequest } from '../lib/task-api';
import type { MyDayHandover, MyDayShiftTask } from '../lib/types';

export function HandoverFollowUps({
    handover,
    onSaved,
    onOpen,
}: {
    handover: MyDayHandover;
    onSaved?: (task: MyDayShiftTask) => void;
    onOpen?: (id: number) => void;
}) {
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState('');
    const [added, setAdded] = useState<Record<string, number>>({});
    const add = async (key: string) => {
        if (busy) return;
        setBusy(key);
        setError('');
        try {
            const result = await taskRequest<{ task: MyDayShiftTask }>(
                `/my-day/handovers/${handover.id}/follow-ups`,
                'POST',
                { item_key: key },
            );
            setAdded((current) => ({ ...current, [key]: result.task.id }));
            onSaved?.(result.task);
        } catch (cause) {
            setError(
                cause instanceof Error
                    ? cause.message
                    : 'Could not add this follow-up. Retry to check the saved result.',
            );
        } finally {
            setBusy(null);
        }
    };
    if (!handover.follow_ups?.length) return null;
    return (
        <section
            aria-label="Handover follow-ups"
            className="mt-4 space-y-3 border-t pt-4"
        >
            <h3 className="font-semibold">What needs following up?</h3>
            <p className="text-sm text-muted-foreground">
                Add a follow-up to your shift to take responsibility. Reading
                this handover does not complete the work.
            </p>
            <ul className="divide-y rounded-lg border px-3">
                {handover.follow_ups.map((item) => {
                    const id = item.task_id ?? added[item.key];
                    return (
                        <li
                            key={item.key}
                            className="flex items-center justify-between gap-3 py-3"
                        >
                            <div className="min-w-0">
                                <p className="text-sm break-words">
                                    {item.label}
                                </p>
                                {id && (
                                    <p
                                        role="status"
                                        className="mt-1 text-xs text-muted-foreground"
                                    >
                                        {item.is_completed
                                            ? 'Follow-up completed'
                                            : 'Added to your shift'}
                                    </p>
                                )}
                                {!id && !item.can_add && (
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {item.source_completed
                                            ? 'The original task has already been completed.'
                                            : 'This shift is not currently accepting new tasks.'}
                                    </p>
                                )}
                            </div>
                            {id ? (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => onOpen?.(id)}
                                    disabled={!onOpen}
                                >
                                    Open task
                                </Button>
                            ) : (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={!item.can_add || !!busy}
                                    onClick={() => void add(item.key)}
                                >
                                    {busy === item.key
                                        ? 'Adding…'
                                        : 'Add to my shift'}
                                </Button>
                            )}
                        </li>
                    );
                })}
            </ul>
            {error && (
                <p role="alert" className="text-sm text-status-critical">
                    {error}
                </p>
            )}
        </section>
    );
}
