import { ConfirmDialog } from '@/components/confirm-dialog';
import { ItAutomationRegister } from '@/components/it/it-automation-register';
import { EntityChip, EntityStatusChip } from '@/components/lists/entity-cells';
import type { MenuItem } from '@/components/lists/entity-menu';
import type { EntityTableColumn } from '@/components/lists/entity-table';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { WizardShell, WizardStepPane } from '@/components/wizard/shell';
import { formatDateTime } from '@/lib/datetime';
import { router, useForm } from '@inertiajs/react';
import { CalendarClock, Pause, Pencil, Play, Trash2 } from 'lucide-react';
import { useState, type FormEvent } from 'react';

interface Option {
    id: number;
    name: string;
}

export interface RecurrencePlanRow {
    id: number;
    name: string;
    cron_expression: string;
    timezone: string;
    starts_on: string | null;
    ends_on: string | null;
    exception_dates: string[];
    owner: Option | null;
    ticket_template: {
        title: string;
        description?: string | null;
        site_id: number;
        category?: string;
        work_type?: string;
        priority?: string;
        it_service_id?: number | null;
    };
    status: 'active' | 'paused' | 'retired';
    next_due_at: string | null;
    run_count: number;
    lock_version: number;
}

const WEEKDAYS = [
    { value: '1', label: 'Monday' },
    { value: '2', label: 'Tuesday' },
    { value: '3', label: 'Wednesday' },
    { value: '4', label: 'Thursday' },
    { value: '5', label: 'Friday' },
    { value: '6', label: 'Saturday' },
    { value: '0', label: 'Sunday' },
] as const;

/** Human summary of the supported cron shapes; raw cron otherwise. */
export function describeCron(expression: string): string {
    const parts = expression.trim().split(/\s+/);
    if (parts.length !== 5) return expression;
    const [minute, hour, dayOfMonth, month, dayOfWeek] = parts;
    const numeric = (value: string) => /^\d+$/.test(value);
    if (!numeric(minute) || !numeric(hour) || month !== '*') return expression;
    const time = `${hour.padStart(2, '0')}:${minute.padStart(2, '0')}`;
    if (dayOfMonth === '*' && dayOfWeek === '*') return `Daily at ${time}`;
    if (dayOfMonth === '*' && numeric(dayOfWeek)) {
        const day = WEEKDAYS.find((entry) => entry.value === dayOfWeek);
        return day ? `Every ${day.label} at ${time}` : expression;
    }
    if (numeric(dayOfMonth) && dayOfWeek === '*') {
        return `Monthly on day ${dayOfMonth} at ${time}`;
    }

    return expression;
}

/** W20 recurrence plans on the shared register contract. */
export function ItRecurrencePlans({
    plans,
    total,
    sites,
    services,
    agents,
    layout,
    creating,
    onCreatingChange,
}: {
    plans: RecurrencePlanRow[];
    total: number;
    sites: Option[];
    services: Option[];
    agents: Option[];
    layout: 'cards' | 'table';
    creating: boolean;
    onCreatingChange: (open: boolean) => void;
}) {
    const [editing, setEditing] = useState<RecurrencePlanRow | null>(null);
    const [retiring, setRetiring] = useState<RecurrencePlanRow | null>(null);

    const setStatus = (plan: RecurrencePlanRow, status: string) =>
        router.post(
            `/it/setup/recurrence-plans/${plan.id}/status`,
            { status, lock_version: plan.lock_version },
            { preserveScroll: true },
        );

    const actionsFor = (plan: RecurrencePlanRow): MenuItem[] =>
        plan.status === 'retired'
            ? []
            : [
                  {
                      label: 'Edit',
                      icon: Pencil,
                      onClick: () => setEditing(plan),
                  },
                  plan.status === 'active'
                      ? {
                            label: 'Pause',
                            icon: Pause,
                            onClick: () => setStatus(plan, 'paused'),
                        }
                      : {
                            label: 'Resume',
                            icon: Play,
                            onClick: () => setStatus(plan, 'active'),
                        },
                  {
                      label: 'Retire',
                      icon: Trash2,
                      onClick: () => setRetiring(plan),
                  },
              ];

    const state = (plan: RecurrencePlanRow) => (
        <EntityStatusChip
            variant={
                plan.status === 'active'
                    ? 'success'
                    : plan.status === 'paused'
                      ? 'warning'
                      : 'neutral'
            }
        >
            {plan.status.charAt(0).toUpperCase() + plan.status.slice(1)}
        </EntityStatusChip>
    );
    const schedule = (plan: RecurrencePlanRow) =>
        `${describeCron(plan.cron_expression)}${plan.next_due_at ? ` · next ${formatDateTime(plan.next_due_at)}` : ''}`;

    const columns: EntityTableColumn<RecurrencePlanRow>[] = [
        { key: 'state', label: 'Status', width: '110px', cell: state },
        {
            key: 'schedule',
            label: 'Schedule',
            width: '1.4fr',
            cell: (plan) => (
                <span className="text-xs text-muted-foreground">
                    {schedule(plan)}
                </span>
            ),
        },
        {
            key: 'owner',
            label: 'Owner',
            width: '1fr',
            cell: (plan) => (
                <span className="text-xs text-muted-foreground">
                    {plan.owner?.name ?? '—'}
                </span>
            ),
        },
        {
            key: 'runs',
            label: 'Runs',
            width: '80px',
            cell: (plan) => <EntityChip>{plan.run_count}</EntityChip>,
        },
    ];

    return (
        <>
            <ItAutomationRegister
                title="Recurring plans"
                rows={plans}
                total={total}
                layout={layout}
                icon={CalendarClock}
                subline={(plan) => plan.ticket_template.title}
                chips={(plan) => (
                    <>
                        {state(plan)}
                        <EntityChip>{schedule(plan)}</EntityChip>
                        <EntityChip>
                            {plan.run_count}{' '}
                            {plan.run_count === 1 ? 'run' : 'runs'}
                        </EntityChip>
                    </>
                )}
                meridian={(plan) =>
                    plan.status === 'active' ? 'success' : 'warning'
                }
                muted={(plan) => plan.status === 'retired'}
                footer={(plan) => ({
                    personName: plan.owner?.name,
                    primary: plan.owner?.name ?? 'No owner',
                    secondary: 'Plan owner',
                })}
                columns={columns}
                actionsFor={actionsFor}
                onOpen={(plan) => {
                    if (plan.status !== 'retired') setEditing(plan);
                }}
                emptyCopy="No recurring plans yet. Use the header action to schedule repeating maintenance — each due date creates exactly one routed ticket."
            />

            {(creating || editing) && (
                <RecurrencePlanDialog
                    plan={editing}
                    sites={sites}
                    services={services}
                    agents={agents}
                    onClose={() => {
                        onCreatingChange(false);
                        setEditing(null);
                    }}
                />
            )}

            <ConfirmDialog
                open={retiring !== null}
                title="Retire recurrence plan?"
                description="No further occurrences will be created. Existing tickets and run history are retained, and a retired plan cannot be resumed."
                confirmText="Retire plan"
                onClose={() => setRetiring(null)}
                onConfirm={() => {
                    if (retiring) setStatus(retiring, 'retired');
                    setRetiring(null);
                }}
            />
        </>
    );
}

type Frequency = 'daily' | 'weekly' | 'monthly' | 'custom';

function cronFor(
    frequency: Frequency,
    time: string,
    weekday: string,
    monthDay: string,
    custom: string,
): string {
    if (frequency === 'custom') return custom;
    const [hour = '9', minute = '0'] = time.split(':');
    const h = String(Number(hour));
    const m = String(Number(minute));
    if (frequency === 'daily') return `${m} ${h} * * *`;
    if (frequency === 'weekly') return `${m} ${h} * * ${weekday}`;

    return `${m} ${h} ${monthDay} * *`;
}

function frequencyOf(expression: string): {
    frequency: Frequency;
    time: string;
    weekday: string;
    monthDay: string;
} {
    const fallback = {
        frequency: 'custom' as Frequency,
        time: '09:00',
        weekday: '1',
        monthDay: '1',
    };
    const parts = expression.trim().split(/\s+/);
    if (parts.length !== 5) return fallback;
    const [minute, hour, dayOfMonth, month, dayOfWeek] = parts;
    if (!/^\d+$/.test(minute) || !/^\d+$/.test(hour) || month !== '*') {
        return fallback;
    }
    const time = `${hour.padStart(2, '0')}:${minute.padStart(2, '0')}`;
    if (dayOfMonth === '*' && dayOfWeek === '*') {
        return { ...fallback, frequency: 'daily', time };
    }
    if (dayOfMonth === '*' && /^\d+$/.test(dayOfWeek)) {
        return { ...fallback, frequency: 'weekly', time, weekday: dayOfWeek };
    }
    if (/^\d+$/.test(dayOfMonth) && dayOfWeek === '*') {
        return {
            ...fallback,
            frequency: 'monthly',
            time,
            monthDay: dayOfMonth,
        };
    }

    return fallback;
}

function RecurrencePlanDialog({
    plan,
    sites,
    services,
    agents,
    onClose,
}: {
    plan: RecurrencePlanRow | null;
    sites: Option[];
    services: Option[];
    agents: Option[];
    onClose: () => void;
}) {
    const initial = frequencyOf(plan?.cron_expression ?? '0 9 * * *');
    const [frequency, setFrequency] = useState<Frequency>(
        plan ? initial.frequency : 'weekly',
    );
    const [time, setTime] = useState(initial.time);
    const [weekday, setWeekday] = useState(initial.weekday);
    const [monthDay, setMonthDay] = useState(initial.monthDay);
    const [custom, setCustom] = useState(plan?.cron_expression ?? '');

    const form = useForm({
        name: plan?.name ?? '',
        cron_expression: plan?.cron_expression ?? '0 9 * * 1',
        starts_on: plan?.starts_on ?? new Date().toISOString().slice(0, 10),
        ends_on: plan?.ends_on ?? '',
        owner_user_id: plan?.owner?.id ? String(plan.owner.id) : '',
        ticket_template: {
            title: plan?.ticket_template.title ?? '',
            description: plan?.ticket_template.description ?? '',
            site_id: plan?.ticket_template.site_id
                ? String(plan.ticket_template.site_id)
                : '',
            category: plan?.ticket_template.category ?? 'other',
            work_type: plan?.ticket_template.work_type ?? 'task',
            priority: plan?.ticket_template.priority ?? 'normal',
            it_service_id: plan?.ticket_template.it_service_id
                ? String(plan.ticket_template.it_service_id)
                : '',
        },
        ...(plan ? { lock_version: plan.lock_version } : {}),
    });
    const errors = form.errors as Record<string, string>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            cron_expression: cronFor(
                frequency,
                time,
                weekday,
                monthDay,
                custom,
            ),
            ends_on: data.ends_on || null,
            owner_user_id: data.owner_user_id
                ? Number(data.owner_user_id)
                : null,
            ticket_template: {
                ...data.ticket_template,
                site_id: Number(data.ticket_template.site_id),
                it_service_id: data.ticket_template.it_service_id
                    ? Number(data.ticket_template.it_service_id)
                    : null,
            },
        }));
        const options = { preserveScroll: true, onSuccess: () => onClose() };
        if (plan) {
            form.patch(`/it/setup/recurrence-plans/${plan.id}`, options);
        } else {
            form.post('/it/setup/recurrence-plans', options);
        }
    };

    const fieldError = (key: string) =>
        errors[key] ? (
            <p
                role="alert"
                className="text-sm font-normal text-status-critical"
            >
                {errors[key]}
            </p>
        ) : null;

    return (
        <WizardShell
            open
            onClose={onClose}
            title={plan ? 'Edit recurrence plan' : 'New recurrence plan'}
            description="Repeating maintenance that creates one routed ticket per occurrence."
            railIcon={CalendarClock}
            railTitle="Recurring ticket"
            railSub="One ticket per due occurrence"
            steps={[
                {
                    key: 'plan',
                    label: 'Plan',
                    blurb: 'Schedule and ticket template',
                    icon: CalendarClock,
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
                        disabled={
                            form.processing ||
                            form.data.name.trim() === '' ||
                            form.data.ticket_template.title.trim() === '' ||
                            form.data.ticket_template.site_id === ''
                        }
                    >
                        {plan ? 'Save changes' : 'Create plan'}
                    </Button>
                </>
            }
        >
            <WizardStepPane>
                <form onSubmit={submit} className="grid gap-3.5">
                    <label className="grid gap-1.5 text-sm font-medium">
                        Plan name
                        <Input
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            maxLength={120}
                        />
                        {fieldError('name')}
                    </label>
                    <div className="grid gap-3.5 sm:grid-cols-3">
                        <label className="grid gap-1.5 text-sm font-medium">
                            Repeats
                            <Select
                                value={frequency}
                                onValueChange={(value) =>
                                    setFrequency(value as Frequency)
                                }
                            >
                                <SelectTrigger aria-label="Repeats">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="daily">Daily</SelectItem>
                                    <SelectItem value="weekly">
                                        Weekly
                                    </SelectItem>
                                    <SelectItem value="monthly">
                                        Monthly
                                    </SelectItem>
                                    <SelectItem value="custom">
                                        Custom (cron)
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </label>
                        {frequency === 'weekly' && (
                            <label className="grid gap-1.5 text-sm font-medium">
                                On
                                <Select
                                    value={weekday}
                                    onValueChange={setWeekday}
                                >
                                    <SelectTrigger aria-label="Weekday">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {WEEKDAYS.map((entry) => (
                                            <SelectItem
                                                key={entry.value}
                                                value={entry.value}
                                            >
                                                {entry.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </label>
                        )}
                        {frequency === 'monthly' && (
                            <label className="grid gap-1.5 text-sm font-medium">
                                Day of month
                                <Input
                                    type="number"
                                    min={1}
                                    max={28}
                                    value={monthDay}
                                    onChange={(event) =>
                                        setMonthDay(event.target.value)
                                    }
                                />
                            </label>
                        )}
                        {frequency !== 'custom' && (
                            <label className="grid gap-1.5 text-sm font-medium">
                                At
                                <Input
                                    type="time"
                                    value={time}
                                    onChange={(event) =>
                                        setTime(event.target.value)
                                    }
                                />
                            </label>
                        )}
                        {frequency === 'custom' && (
                            <label className="grid gap-1.5 text-sm font-medium sm:col-span-2">
                                Cron expression
                                <Input
                                    value={custom}
                                    onChange={(event) =>
                                        setCustom(event.target.value)
                                    }
                                    placeholder="0 9 * * 1"
                                />
                            </label>
                        )}
                    </div>
                    {fieldError('cron_expression')}
                    <div className="grid gap-3.5 sm:grid-cols-3">
                        <label className="grid gap-1.5 text-sm font-medium">
                            Starts
                            <Input
                                type="date"
                                value={form.data.starts_on}
                                onChange={(event) =>
                                    form.setData(
                                        'starts_on',
                                        event.target.value,
                                    )
                                }
                            />
                        </label>
                        <label className="grid gap-1.5 text-sm font-medium">
                            Ends (optional)
                            <Input
                                type="date"
                                value={form.data.ends_on}
                                onChange={(event) =>
                                    form.setData('ends_on', event.target.value)
                                }
                            />
                        </label>
                        <label className="grid gap-1.5 text-sm font-medium">
                            Owner
                            <Select
                                value={form.data.owner_user_id || undefined}
                                onValueChange={(value) =>
                                    form.setData('owner_user_id', value)
                                }
                            >
                                <SelectTrigger aria-label="Owner">
                                    <SelectValue placeholder="Me" />
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
                        </label>
                    </div>
                    <fieldset className="grid gap-3.5 rounded-xl border border-border p-3.5">
                        <legend className="px-1 text-sm font-semibold">
                            Ticket template
                        </legend>
                        <label className="grid gap-1.5 text-sm font-medium">
                            Ticket title
                            <Input
                                value={form.data.ticket_template.title}
                                onChange={(event) =>
                                    form.setData('ticket_template', {
                                        ...form.data.ticket_template,
                                        title: event.target.value,
                                    })
                                }
                                maxLength={255}
                            />
                            {fieldError('ticket_template.title')}
                        </label>
                        <label className="grid gap-1.5 text-sm font-medium">
                            Instructions (optional)
                            <Textarea
                                rows={3}
                                value={
                                    form.data.ticket_template.description ?? ''
                                }
                                onChange={(event) =>
                                    form.setData('ticket_template', {
                                        ...form.data.ticket_template,
                                        description: event.target.value,
                                    })
                                }
                            />
                        </label>
                        <div className="grid gap-3.5 sm:grid-cols-2">
                            <label className="grid gap-1.5 text-sm font-medium">
                                Site
                                <Select
                                    value={
                                        form.data.ticket_template.site_id ||
                                        undefined
                                    }
                                    onValueChange={(value) =>
                                        form.setData('ticket_template', {
                                            ...form.data.ticket_template,
                                            site_id: value,
                                        })
                                    }
                                >
                                    <SelectTrigger aria-label="Site">
                                        <SelectValue placeholder="Choose the Site" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {sites.map((site) => (
                                            <SelectItem
                                                key={site.id}
                                                value={String(site.id)}
                                            >
                                                {site.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {fieldError('ticket_template.site_id')}
                            </label>
                            <label className="grid gap-1.5 text-sm font-medium">
                                Affected service (optional)
                                <Select
                                    value={
                                        form.data.ticket_template
                                            .it_service_id || undefined
                                    }
                                    onValueChange={(value) =>
                                        form.setData('ticket_template', {
                                            ...form.data.ticket_template,
                                            it_service_id: value,
                                        })
                                    }
                                >
                                    <SelectTrigger aria-label="Affected service">
                                        <SelectValue placeholder="No service" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {services.map((service) => (
                                            <SelectItem
                                                key={service.id}
                                                value={String(service.id)}
                                            >
                                                {service.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </label>
                            <label className="grid gap-1.5 text-sm font-medium">
                                Work type
                                <Select
                                    value={form.data.ticket_template.work_type}
                                    onValueChange={(value) =>
                                        form.setData('ticket_template', {
                                            ...form.data.ticket_template,
                                            work_type: value,
                                        })
                                    }
                                >
                                    <SelectTrigger aria-label="Work type">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="task">
                                            Task
                                        </SelectItem>
                                        <SelectItem value="incident">
                                            Incident
                                        </SelectItem>
                                        <SelectItem value="service_request">
                                            Service request
                                        </SelectItem>
                                        <SelectItem value="change">
                                            Change
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </label>
                            <label className="grid gap-1.5 text-sm font-medium">
                                Priority
                                <Select
                                    value={form.data.ticket_template.priority}
                                    onValueChange={(value) =>
                                        form.setData('ticket_template', {
                                            ...form.data.ticket_template,
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
                            </label>
                        </div>
                    </fieldset>
                    {fieldError('lock_version')}
                </form>
            </WizardStepPane>
        </WizardShell>
    );
}
