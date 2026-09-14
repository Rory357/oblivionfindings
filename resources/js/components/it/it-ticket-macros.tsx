import { ConfirmDialog } from '@/components/confirm-dialog';
import { ItAutomationRegister } from '@/components/it/it-automation-register';
import { EntityChip, EntityStatusChip } from '@/components/lists/entity-cells';
import type { MenuItem } from '@/components/lists/entity-menu';
import type { EntityTableColumn } from '@/components/lists/entity-table';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { WizardShell, WizardStepPane } from '@/components/wizard/shell';
import { router, useForm } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    Pencil,
    Plus,
    Trash2,
    Wand2,
} from 'lucide-react';
import { useState, type FormEvent } from 'react';

interface Option {
    id: number;
    name: string;
}

export type MacroAction = {
    type:
        | 'set_status'
        | 'set_waiting'
        | 'set_priority'
        | 'assign_to_me'
        | 'assign_user'
        | 'set_queue'
        | 'add_reply';
    status?: string;
    waiting_party?: string;
    waiting_reason?: string;
    priority?: string;
    reason?: string;
    user_id?: number;
    queue_id?: number;
    template_id?: number;
    internal?: boolean;
    routing_reason?: string;
};

export interface MacroRow {
    id: number;
    name: string;
    description: string | null;
    actions: MacroAction[];
    is_active: boolean;
    lock_version: number;
}

export type MacroLookups = {
    agents: Option[];
    queues: Option[];
    templates: Option[];
};

const ACTION_LABELS: Record<MacroAction['type'], string> = {
    set_status: 'Set status',
    set_waiting: 'Mark waiting',
    set_priority: 'Override priority',
    assign_to_me: 'Assign to me',
    assign_user: 'Assign a technician',
    set_queue: 'Move to queue',
    add_reply: 'Insert reply template',
};

export function summariseMacroAction(
    action: MacroAction,
    lookups: MacroLookups,
): string {
    switch (action.type) {
        case 'set_status':
            return `Set status to ${String(action.status).replace('_', ' ')}`;
        case 'set_waiting':
            return `Mark waiting on the ${action.waiting_party}`;
        case 'set_priority':
            return `Override priority to ${action.priority}`;
        case 'assign_to_me':
            return 'Assign to the applying technician';
        case 'assign_user':
            return `Assign to ${lookups.agents.find((agent) => agent.id === action.user_id)?.name ?? 'a technician'}`;
        case 'set_queue':
            return `Move to ${lookups.queues.find((queue) => queue.id === action.queue_id)?.name ?? 'a queue'}`;
        case 'add_reply':
            return `Reply with "${lookups.templates.find((template) => template.id === action.template_id)?.name ?? 'a template'}"`;
    }
}

/** W18 macros on the shared register contract. */
export function ItTicketMacros({
    macros,
    total,
    agents,
    queues,
    templates,
    layout,
    creating,
    onCreatingChange,
}: {
    macros: MacroRow[];
    total: number;
    agents: Option[];
    queues: Option[];
    templates: Option[];
    layout: 'cards' | 'table';
    creating: boolean;
    onCreatingChange: (open: boolean) => void;
}) {
    const [editing, setEditing] = useState<MacroRow | null>(null);
    const [archiving, setArchiving] = useState<MacroRow | null>(null);
    const lookups = { agents, queues, templates };

    const setActive = (macro: MacroRow, active: boolean) =>
        router.post(
            `/it/setup/macros/${macro.id}/active`,
            { active, lock_version: macro.lock_version },
            { preserveScroll: true },
        );

    const actionsFor = (macro: MacroRow): MenuItem[] => [
        { label: 'Edit', icon: Pencil, onClick: () => setEditing(macro) },
        macro.is_active
            ? {
                  label: 'Archive',
                  icon: Archive,
                  onClick: () => setArchiving(macro),
              }
            : {
                  label: 'Restore',
                  icon: ArchiveRestore,
                  onClick: () => setActive(macro, true),
              },
    ];

    const state = (macro: MacroRow) => (
        <EntityStatusChip variant={macro.is_active ? 'success' : 'neutral'}>
            {macro.is_active ? 'Active' : 'Archived'}
        </EntityStatusChip>
    );
    const summary = (macro: MacroRow) =>
        macro.actions
            .map((action) => summariseMacroAction(action, lookups))
            .join(' · ');

    const columns: EntityTableColumn<MacroRow>[] = [
        { key: 'state', label: 'Status', width: '110px', cell: state },
        {
            key: 'actions',
            label: 'Actions, in order',
            width: '1.6fr',
            cell: (macro) => (
                <span className="text-xs text-muted-foreground">
                    {summary(macro)}
                </span>
            ),
        },
        {
            key: 'count',
            label: 'Steps',
            width: '80px',
            cell: (macro) => <EntityChip>{macro.actions.length}</EntityChip>,
        },
    ];

    return (
        <>
            <ItAutomationRegister
                title="Macros"
                rows={macros}
                total={total}
                layout={layout}
                icon={Wand2}
                subline={(macro) => macro.description || summary(macro)}
                chips={(macro) => (
                    <>
                        {state(macro)}
                        {macro.actions.map((action, index) => (
                            <EntityChip key={index}>
                                {summariseMacroAction(action, lookups)}
                            </EntityChip>
                        ))}
                    </>
                )}
                meridian={(macro) => (macro.is_active ? 'success' : 'warning')}
                muted={(macro) => !macro.is_active}
                footer={(macro) => ({
                    primary: `${macro.actions.length} ${macro.actions.length === 1 ? 'step' : 'steps'}`,
                    secondary: 'Previewed before every apply',
                })}
                columns={columns}
                actionsFor={actionsFor}
                onOpen={setEditing}
                emptyCopy="No macros yet. Use the header action to bundle the routine — assign to me, escalate, send the standard reply — into one governed action."
            />

            {(creating || editing) && (
                <MacroDialog
                    macro={editing}
                    agents={agents}
                    queues={queues}
                    templates={templates}
                    onClose={() => {
                        onCreatingChange(false);
                        setEditing(null);
                    }}
                />
            )}

            <ConfirmDialog
                open={archiving !== null}
                title="Archive macro?"
                description="Technicians can no longer apply it. It can be restored later."
                confirmText="Archive macro"
                onClose={() => setArchiving(null)}
                onConfirm={() => {
                    if (archiving) setActive(archiving, false);
                    setArchiving(null);
                }}
            />
        </>
    );
}

function defaultAction(type: MacroAction['type']): MacroAction {
    switch (type) {
        case 'set_status':
            return { type, status: 'in_progress' };
        case 'set_waiting':
            return { type, waiting_party: 'requester', waiting_reason: '' };
        case 'set_priority':
            return { type, priority: 'high', reason: '' };
        case 'assign_to_me':
            return { type };
        case 'assign_user':
            return { type, user_id: undefined };
        case 'set_queue':
            return { type, queue_id: undefined };
        case 'add_reply':
            return { type, template_id: undefined, internal: false };
    }
}

function actionComplete(action: MacroAction): boolean {
    switch (action.type) {
        case 'set_priority':
            return (action.reason ?? '').trim() !== '';
        case 'assign_user':
            return typeof action.user_id === 'number';
        case 'set_queue':
            return typeof action.queue_id === 'number';
        case 'add_reply':
            return typeof action.template_id === 'number';
        default:
            return true;
    }
}

function MacroDialog({
    macro,
    agents,
    queues,
    templates,
    onClose,
}: {
    macro: MacroRow | null;
    agents: Option[];
    queues: Option[];
    templates: Option[];
    onClose: () => void;
}) {
    const form = useForm({
        name: macro?.name ?? '',
        description: macro?.description ?? '',
        actions: (macro?.actions ?? [
            defaultAction('assign_to_me'),
        ]) as MacroAction[],
        ...(macro ? { lock_version: macro.lock_version } : {}),
    });
    const errors = form.errors as Record<string, string>;

    const setAction = (index: number, next: MacroAction) =>
        form.setData(
            'actions',
            form.data.actions.map((action, position) =>
                position === index ? next : action,
            ),
        );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onClose() };
        if (macro) {
            form.patch(`/it/setup/macros/${macro.id}`, options);
        } else {
            form.post('/it/setup/macros', options);
        }
    };

    const ready =
        form.data.name.trim() !== '' &&
        form.data.actions.length > 0 &&
        form.data.actions.every(actionComplete);

    return (
        <WizardShell
            open
            onClose={onClose}
            title={macro ? 'Edit macro' : 'New macro'}
            description="An ordered set of governed ticket actions."
            railIcon={Wand2}
            railTitle="Ticket macro"
            railSub="Preview before every apply"
            steps={[
                {
                    key: 'macro',
                    label: 'Macro',
                    blurb: 'Name and ordered actions',
                    icon: Wand2,
                },
            ]}
            stepIndex={0}
            onStepClick={() => undefined}
            pct={100}
            footerEnd={
                <>
                    <Button variant="ghost" onClick={onClose} type="button">
                        Cancel
                    </Button>
                    <Button
                        onClick={submit}
                        disabled={form.processing || !ready}
                    >
                        {macro ? 'Save changes' : 'Create macro'}
                    </Button>
                </>
            }
        >
            <WizardStepPane>
                <form onSubmit={submit} className="grid gap-3.5">
                    <label className="grid gap-1.5 text-sm font-medium">
                        Name
                        <Input
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            maxLength={120}
                        />
                        {errors.name && (
                            <p
                                role="alert"
                                className="text-sm font-normal text-status-critical"
                            >
                                {errors.name}
                            </p>
                        )}
                    </label>
                    <label className="grid gap-1.5 text-sm font-medium">
                        Description (optional)
                        <Input
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                            maxLength={500}
                        />
                    </label>
                    <div className="space-y-2.5">
                        <p className="text-sm font-medium">Actions, in order</p>
                        {form.data.actions.map((action, index) => (
                            <div
                                key={index}
                                className="grid gap-2.5 rounded-xl border border-border p-3"
                            >
                                <div className="flex items-center gap-2">
                                    <Select
                                        value={action.type}
                                        onValueChange={(value) =>
                                            setAction(
                                                index,
                                                defaultAction(
                                                    value as MacroAction['type'],
                                                ),
                                            )
                                        }
                                    >
                                        <SelectTrigger
                                            aria-label={`Action ${index + 1} type`}
                                            className="w-56"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {Object.entries(ACTION_LABELS).map(
                                                ([value, label]) => (
                                                    <SelectItem
                                                        key={value}
                                                        value={value}
                                                    >
                                                        {label}
                                                    </SelectItem>
                                                ),
                                            )}
                                        </SelectContent>
                                    </Select>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label={`Remove action ${index + 1}`}
                                        disabled={
                                            form.data.actions.length === 1
                                        }
                                        onClick={() =>
                                            form.setData(
                                                'actions',
                                                form.data.actions.filter(
                                                    (_, position) =>
                                                        position !== index,
                                                ),
                                            )
                                        }
                                    >
                                        <Trash2 className="h-4 w-4" />
                                    </Button>
                                </div>
                                {action.type === 'set_status' && (
                                    <Select
                                        value={action.status}
                                        onValueChange={(value) =>
                                            setAction(index, {
                                                ...action,
                                                status: value,
                                            })
                                        }
                                    >
                                        <SelectTrigger aria-label="Status">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="open">
                                                Open
                                            </SelectItem>
                                            <SelectItem value="in_progress">
                                                In progress
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                )}
                                {action.type === 'set_waiting' && (
                                    <div className="grid gap-2.5 sm:grid-cols-2">
                                        <Select
                                            value={action.waiting_party}
                                            onValueChange={(value) =>
                                                setAction(index, {
                                                    ...action,
                                                    waiting_party: value,
                                                })
                                            }
                                        >
                                            <SelectTrigger aria-label="Waiting on">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="requester">
                                                    Waiting on requester
                                                </SelectItem>
                                                <SelectItem value="vendor">
                                                    Waiting on vendor
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <Input
                                            aria-label="Waiting reason"
                                            placeholder="Reason (optional)"
                                            value={action.waiting_reason ?? ''}
                                            onChange={(event) =>
                                                setAction(index, {
                                                    ...action,
                                                    waiting_reason:
                                                        event.target.value,
                                                })
                                            }
                                        />
                                    </div>
                                )}
                                {action.type === 'set_priority' && (
                                    <div className="grid gap-2.5 sm:grid-cols-2">
                                        <Select
                                            value={action.priority}
                                            onValueChange={(value) =>
                                                setAction(index, {
                                                    ...action,
                                                    priority: value,
                                                })
                                            }
                                        >
                                            <SelectTrigger aria-label="Priority">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="low">
                                                    P4 · Low
                                                </SelectItem>
                                                <SelectItem value="normal">
                                                    P3 · Medium
                                                </SelectItem>
                                                <SelectItem value="high">
                                                    P2 · High
                                                </SelectItem>
                                                <SelectItem value="urgent">
                                                    P1 · Critical
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <Input
                                            aria-label="Priority reason"
                                            placeholder="Why override? (required)"
                                            value={action.reason ?? ''}
                                            onChange={(event) =>
                                                setAction(index, {
                                                    ...action,
                                                    reason: event.target.value,
                                                })
                                            }
                                        />
                                    </div>
                                )}
                                {action.type === 'assign_user' && (
                                    <Select
                                        value={
                                            action.user_id
                                                ? String(action.user_id)
                                                : undefined
                                        }
                                        onValueChange={(value) =>
                                            setAction(index, {
                                                ...action,
                                                user_id: Number(value),
                                            })
                                        }
                                    >
                                        <SelectTrigger aria-label="Technician">
                                            <SelectValue placeholder="Choose a technician" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {agents.map((agent) => (
                                                <SelectItem
                                                    key={agent.id}
                                                    value={String(agent.id)}
                                                >
                                                    {agent.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                )}
                                {action.type === 'set_queue' && (
                                    <Select
                                        value={
                                            action.queue_id
                                                ? String(action.queue_id)
                                                : undefined
                                        }
                                        onValueChange={(value) =>
                                            setAction(index, {
                                                ...action,
                                                queue_id: Number(value),
                                            })
                                        }
                                    >
                                        <SelectTrigger aria-label="Queue">
                                            <SelectValue placeholder="Choose a queue" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {queues.map((queue) => (
                                                <SelectItem
                                                    key={queue.id}
                                                    value={String(queue.id)}
                                                >
                                                    {queue.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                )}
                                {action.type === 'add_reply' && (
                                    <div className="grid gap-2.5 sm:grid-cols-2">
                                        <Select
                                            value={
                                                action.template_id
                                                    ? String(action.template_id)
                                                    : undefined
                                            }
                                            onValueChange={(value) =>
                                                setAction(index, {
                                                    ...action,
                                                    template_id: Number(value),
                                                })
                                            }
                                        >
                                            <SelectTrigger aria-label="Reply template">
                                                <SelectValue placeholder="Choose a template" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {templates.map((template) => (
                                                    <SelectItem
                                                        key={template.id}
                                                        value={String(
                                                            template.id,
                                                        )}
                                                    >
                                                        {template.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <label className="flex items-center gap-2 text-sm">
                                            <Checkbox
                                                checked={
                                                    action.internal ?? false
                                                }
                                                onCheckedChange={(checked) =>
                                                    setAction(index, {
                                                        ...action,
                                                        internal:
                                                            checked === true,
                                                    })
                                                }
                                            />
                                            Post as an internal note
                                        </label>
                                    </div>
                                )}
                            </div>
                        ))}
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={form.data.actions.length >= 10}
                            onClick={() =>
                                form.setData('actions', [
                                    ...form.data.actions,
                                    defaultAction('add_reply'),
                                ])
                            }
                        >
                            <Plus className="h-3.5 w-3.5" /> Add action
                        </Button>
                        {errors.actions && (
                            <p
                                role="alert"
                                className="text-sm text-status-critical"
                            >
                                {errors.actions}
                            </p>
                        )}
                        {errors.lock_version && (
                            <p
                                role="alert"
                                className="text-sm text-status-critical"
                            >
                                {errors.lock_version}
                            </p>
                        )}
                    </div>
                </form>
            </WizardStepPane>
        </WizardShell>
    );
}
