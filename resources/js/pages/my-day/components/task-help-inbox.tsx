import { EntityContextMenu } from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { Button } from '@/components/ui/button';
import { HandHelping } from 'lucide-react';
import { useState } from 'react';
import type { MyDayHelpTask } from '../lib/types';

export function TaskHelpInbox({
    tasks,
    onOpen,
}: {
    tasks: MyDayHelpTask[];
    onOpen: (id: number) => void;
}) {
    const [context, setContext] = useState<{
        task: MyDayHelpTask;
        x: number;
        y: number;
    } | null>(null);
    const actions = (task: MyDayHelpTask) => [
        { label: 'Open request', onClick: () => onOpen(task.id) },
    ];
    if (!tasks.length) return null;
    return (
        <section aria-labelledby="help-inbox-title" className="space-y-3">
            <h2 id="help-inbox-title" className="text-section-title">
                Help requested from you
            </h2>
            <p className="text-sm text-muted-foreground">
                Review a request before accepting responsibility. Accepted work
                stays here until it is done.
            </p>
            <EntityTable
                rows={tasks}
                rowKey={(task) => task.id}
                identityLabel="Task and colleague"
                minWidth={560}
                identityWidth="minmax(220px,1fr)"
                identity={(task) => ({
                    name: task.label,
                    icon: HandHelping,
                    subline: `${task.person_name} · From ${task.requested_by_name}`,
                })}
                columns={[
                    {
                        key: 'status',
                        label: 'Status',
                        width: '160px',
                        cell: (task) =>
                            task.help?.status === 'accepted'
                                ? 'Accepted by you'
                                : 'Waiting for you',
                    },
                    {
                        key: 'open',
                        label: 'Action',
                        width: '130px',
                        cell: (task) => (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={(event) => {
                                    event.stopPropagation();
                                    onOpen(task.id);
                                }}
                            >
                                Open request
                            </Button>
                        ),
                    },
                ]}
                onOpen={(task) => onOpen(task.id)}
                actionsFor={actions}
                onRowContextMenu={(event, task) => {
                    event.preventDefault();
                    setContext({ task, x: event.clientX, y: event.clientY });
                }}
            />
            {context && (
                <EntityContextMenu
                    x={context.x}
                    y={context.y}
                    title={context.task.label}
                    items={actions(context.task)}
                    onClose={() => setContext(null)}
                />
            )}
        </section>
    );
}
