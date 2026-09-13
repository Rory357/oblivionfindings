import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { WizardShell, WizardStepPane } from '@/components/wizard/shell';
import { formatDateTime } from '@/lib/datetime';
import { router, useForm } from '@inertiajs/react';
import {
    CalendarClock,
    Pause,
    Pencil,
    Play,
    Plus,
    Trash2,
} from 'lucide-react';
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

/** W20: recurring maintenance plans that create one routed ticket per due occurrence. */
export function ItRecurrencePlans({
    plans,
    sites,
    services,
    agents,
}: {
    plans: RecurrencePlanRow[];
    sites: Option[];
    services: Option[];
    agents: Option[];
}) {
    const [editing, setEditing] = useState<RecurrencePlanRow | null>(null);
    const [creating, setCreating] = useState(false);
    const [retiring, setRetiring] = useState<RecurrencePlanRow | null>(null);

    const setStatus = (plan: RecurrencePlanRow, status: string) =>
        router.post(
            `/it/setup/recurrence-plans/${plan.id}/status`,
            { status, lock_version: plan.lock_version },
            { preserveScroll: true },
        );

    return (
        <section aria-label="Recurring tickets" className="space-y-4">
            <div className="flex items-center justify-between gap-3">
                <p className="text-sm text-muted-foreground">
                    Scheduled maintenance creates one routed ticket per due
                    occurrence. Missed runs are recorded and never storm the
                    queue.
                </p>
                <Button onClick={() => setCreating(true)}>
                    <Plus className="h-4 w-4" /> New plan
                </Button>
            </div>

            {plans.length === 0 ? (
                <EmptyState
                    icon={CalendarClock}
                    title="No recurring plans yet"
                    description="Schedule repeating maintenance — certificate renewals, backup checks, printer servicing — and each due date creates exactly one routed ticket."
                    action={
                        <Button onClick={() => setCreating(true)}>
                            <Plus className="h-4 w-4" /> New plan
                        </Button>
                    }
                />
            ) : (
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    {plans.map((plan) => (
                        <article
                            key={plan.id}
                            className="flex flex-col gap-2 rounded-xl border border-border bg-card p-4"
                        >
                            <div className="flex items-start justify-between gap-2">
                                <h3 className="text-sm font-semibold">
                                    {plan.name}
                                </h3>
                                <StatusBadge
                                    variant={
                                        plan.status === 'active'
                                            ? 'success'
                                            : plan.status === 'paused'
                                              ? 'warning'
                                              : 'neutral'
                                    }
                                    label={
                                        plan.status.charAt(0).toUpperCase() +
                                        plan.status.slice(1)
                                    }
                                />
                            </div>
                            <p className="text-sm text-muted-foreground">
                                {describeCron(plan.cron_expression)}
                                {plan.next_due_at
                                    ? ` · next ${formatDateTime(plan.next_due_at)}`
                                    : ''}
                            </p>
                            <p className="line-clamp-2 text-sm">
                                {plan.ticket_template.title}
                            </p>
                            <p className="mt-auto text-xs text-muted-foreground">
                                {plan.owner
                                    ? `Owned by ${plan.owner.name}`
                                    : 'No owner'}
                                {` · ${plan.run_count} run${plan.run_count === 1 ? '' : 's'}`}
                            </p>
                            {plan.status !== 'retired' && (
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setEditing(plan)}
                                    >
                                        <Pencil className="h-3.5 w-3.5" /> Edit
                                    </Button>
                                    {plan.status === 'active' ? (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                setStatus(plan, 'paused')
                                            }
                                        >
                                            <Pause className="h-3.5 w-3.5" />{' '}
                                            Pause
                                        </Button>
                                    ) : (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                setStatus(plan, 'active')
                                            }
                                        >
                                            <Play className="h-3.5 w-3.5" />{' '}
                                            Resume
                                        </Button>
                                    )}
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => setRetiring(plan)}
                                    >
                                        <Trash2 className="h-3.5 w-3.5" />{' '}
                                        Retire
                                    </Button>
                                </div>
                            )}
                        </article>
                    ))}
                </div>
            )}

            {(creating || editing) && (
                <RecurrencePlanDialog
                    plan={editing}
                    sites={sites}
                    services={services}
                    agents={agents}
                    onClose={() => {
                        setCreating(false);
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
        </section>
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
        starts_on:
            plan?.starts_on ?? new Date().toISOString().slice(0, 10),
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
            <p role="alert" className="text-sm font-normal text-status-critical">
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
