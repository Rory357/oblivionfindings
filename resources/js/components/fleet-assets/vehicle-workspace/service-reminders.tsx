import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { formatDateTime, toDatetimeLocal } from '@/lib/datetime';
import {
    ArrowUpRight,
    Bell,
    CalendarDays,
    Check,
    CheckCircle2,
    Clock3,
    History,
    Loader2,
    Pencil,
    Play,
    Plus,
    UserRound,
    Wrench,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { CataloguePicker, formatChoice, PersonPicker } from './choice-picker';
import {
    ObligationActionDialog,
    obligationDue,
    ObligationHistoryDialog,
} from './obligation-reminders';
import {
    useVehicleCollectionView,
    VehicleCollectionToggle,
    VehicleRecordCollection,
} from './record-collection';
import { isJsonObject, useVehicleRecordCommand } from './record-command';
import { ScheduleDialog } from './service-schedules';
import { openWorkOrder, SourceRecordDialog } from './studio-kit';
import type {
    ObligationReminder,
    ReminderSourceType,
    ServiceSchedule,
    VehicleReminder,
    VehicleWorkspace,
} from './types';
import {
    fieldProps,
    RecordDialog,
    WizardField,
    WizardSuccess,
    WorkspaceWizard,
} from './wizard-kit';
import { type WorkspaceLocation } from './workspace-model';

const STATE: Record<
    VehicleReminder['state'],
    { label: string; variant: StatusVariant }
> = {
    scheduled: { label: 'Scheduled', variant: 'info' },
    acknowledged: { label: 'Acknowledged', variant: 'info' },
    completed: { label: 'Completed', variant: 'success' },
    paused: { label: 'Paused', variant: 'neutral' },
};

type Filter = 'all' | 'open' | 'completed' | 'paused';
export type ReminderAction = 'acknowledge' | 'complete' | 'pause' | 'resume';

type SourceOption = {
    value: string;
    type: ReminderSourceType;
    id: number | null;
    label: string;
};

function sourceOptions(workspace: VehicleWorkspace): SourceOption[] {
    return [
        {
            value: 'vehicle',
            type: 'vehicle',
            id: null,
            label: `${workspace.vehicle.name} (general vehicle follow-up)`,
        },
        ...workspace.documents.map((set) => ({
            value: `document_set:${set.id}`,
            type: 'document_set' as const,
            id: set.id,
            label: `Document · ${set.category}`,
        })),
        ...workspace.schedules.map((schedule) => ({
            value: `service_schedule:${schedule.id}`,
            type: 'service_schedule' as const,
            id: schedule.id,
            label: `Service · ${schedule.name}`,
        })),
        ...workspace.compliance
            .filter((record) => record.record_id !== null)
            .map((record) => ({
                value: `compliance_record:${record.record_id}`,
                type: 'compliance_record' as const,
                id: record.record_id,
                label: `Compliance · ${record.label}`,
            })),
        ...workspace.work.open.map((order) => ({
            value: `work_order:${order.id}`,
            type: 'work_order' as const,
            id: order.id,
            label: `Work · ${[order.reference, order.title].filter(Boolean).join(' · ')}`,
        })),
    ];
}

export function RemindersPanel({
    workspace,
    onNavigate,
    onChanged,
}: {
    workspace: VehicleWorkspace;
    onNavigate: (location: WorkspaceLocation) => void;
    onChanged: () => void;
}) {
    const { view, setView } = useVehicleCollectionView('reminders');
    const [filter, setFilter] = useState<Filter>('all');
    const [query, setQuery] = useState('');
    const [editing, setEditing] = useState<{
        reminder: VehicleReminder | null;
        source?: string;
    } | null>(null);
    const [acting, setActing] = useState<{
        reminder: VehicleReminder;
        action: ReminderAction;
    } | null>(null);
    const [activity, setActivity] = useState<VehicleReminder | null>(null);
    const [obligationHistory, setObligationHistory] =
        useState<ObligationReminder | null>(null);
    const [obligationAction, setObligationAction] = useState<{
        reminder: ObligationReminder;
        action: 'acknowledge' | 'retry';
    } | null>(null);
    const [policy, setPolicy] = useState<ObligationReminder | null>(null);
    const [schedule, setSchedule] = useState<ServiceSchedule | null>(null);
    const { can, vehicle } = workspace;
    const obligations = workspace.obligation_reminders;
    const openFollowUps = workspace.reminders.filter((reminder) =>
        ['scheduled', 'acknowledged'].includes(reminder.state),
    );
    const failed = obligations.filter(
        (reminder) => reminder.state === 'failed',
    ).length;
    const rows = useMemo(() => {
        const needle = query.trim().toLowerCase();
        return workspace.reminders.filter((reminder) => {
            if (
                filter === 'open' &&
                !['scheduled', 'acknowledged'].includes(reminder.state)
            )
                return false;
            if (filter === 'completed' && reminder.state !== 'completed')
                return false;
            if (filter === 'paused' && reminder.state !== 'paused')
                return false;
            if (!needle) return true;
            return [reminder.title, reminder.owner?.name, reminder.source.label]
                .filter(Boolean)
                .some((text) => String(text).toLowerCase().includes(needle));
        });
    }, [workspace.reminders, filter, query]);
    const openSource = (type: ReminderSourceType | null, id: number | null) => {
        if (type === 'document_set')
            onNavigate({ tab: 'overview', view: 'documents' });
        else if (type === 'work_order' && id && workspace.work.can_view)
            openWorkOrder(id, vehicle.id, {
                tab: 'service',
                view: 'reminders',
            });
        else if (type === 'service_schedule')
            onNavigate({ tab: 'service', view: 'schedules' });
        else if (type === 'compliance_record')
            onNavigate({ tab: 'service', view: 'evidence' });
        else onNavigate({ tab: 'overview', view: 'details' });
    };
    const calendarDay = (iso: string | null) =>
        onNavigate(
            iso
                ? { tab: 'calendar', date: toDatetimeLocal(iso).slice(0, 10) }
                : { tab: 'calendar' },
        );

    return (
        <div className="activity-studio">
            <div className="activity-title">
                <div>
                    <span className="studio-eyebrow">TIMELY FOLLOW-UP</span>
                    <h2 className="text-section-title">Reminders</h2>
                    <p className="muted">
                        One place for vehicle follow-ups and obligation
                        reminders.
                    </p>
                </div>
                <div className="library-actions">
                    <Button
                        variant="outline"
                        onClick={() => onNavigate({ tab: 'calendar' })}
                    >
                        <CalendarDays className="size-4" />
                        Open calendar
                    </Button>
                    {can.manage && (
                        <Button onClick={() => setEditing({ reminder: null })}>
                            <Plus className="size-4" />
                            Add reminder
                        </Button>
                    )}
                </div>
            </div>
            <div className="reminder-summary">
                <div>
                    <span className="feature-icon">
                        <Bell className="size-5" />
                    </span>
                    <strong>{openFollowUps.length}</strong>
                    <span>Open follow-ups</span>
                </div>
                <div>
                    <span className="feature-icon">
                        <Wrench className="size-5" />
                    </span>
                    <strong>{obligations.length}</strong>
                    <span>Linked obligations</span>
                </div>
                <div>
                    <span className="feature-icon warning">
                        <Clock3 className="size-5" />
                    </span>
                    <strong>{failed}</strong>
                    <span>Delivery needs attention</span>
                </div>
            </div>
            <section className="studio-card reminder-workspace">
                <div className="activity-toolbar">
                    <div>
                        <h3 className="text-section-title">
                            Vehicle follow-ups
                        </h3>
                        <small className="muted">
                            Dates appear on the vehicle calendar without
                            blocking bookings.
                        </small>
                    </div>
                    <Input
                        aria-label="Search reminders"
                        placeholder="Title, owner or source…"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                    />
                    <Select
                        value={filter}
                        onValueChange={(value) => setFilter(value as Filter)}
                    >
                        <SelectTrigger
                            className="w-44"
                            aria-label="Reminder filter"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All reminders</SelectItem>
                            <SelectItem value="open">Scheduled</SelectItem>
                            <SelectItem value="completed">Completed</SelectItem>
                            <SelectItem value="paused">Paused</SelectItem>
                        </SelectContent>
                    </Select>
                    <VehicleCollectionToggle
                        label="Reminders"
                        view={view}
                        onChange={setView}
                    />
                </div>
                <VehicleRecordCollection
                    label="Vehicle follow-ups"
                    view={view}
                    columns={[
                        { label: 'Due / repeat' },
                        { label: 'Owner & source', width: '1.2fr' },
                        { label: 'Status / calendar', width: '1.1fr' },
                    ]}
                    empty={{
                        title: workspace.reminders.length
                            ? 'No matching reminders'
                            : 'No follow-ups yet',
                        description:
                            'Add a mileage check, document follow-up or workshop call with an owner and due date.',
                        action: can.manage ? (
                            <Button
                                variant="outline"
                                onClick={() => setEditing({ reminder: null })}
                            >
                                Create reminder
                            </Button>
                        ) : undefined,
                    }}
                    records={rows.map((reminder) => {
                        const owner =
                            reminder.owner?.name ?? 'Owner unavailable';
                        const open = ['scheduled', 'acknowledged'].includes(
                            reminder.state,
                        );
                        return {
                            id: reminder.id,
                            name: reminder.title,
                            subline: reminder.action_text ?? undefined,
                            icon: Bell,
                            onOpen: () => setActivity(reminder),
                            fields: [
                                <>
                                    <strong>
                                        {reminder.due_at
                                            ? formatDateTime(reminder.due_at)
                                            : 'No time set'}
                                    </strong>
                                    <small>
                                        {reminder.repeat_months
                                            ? `Every ${formatChoice('repeat_months', String(reminder.repeat_months))}`
                                            : 'One-off'}
                                    </small>
                                </>,
                                <>
                                    <span>{owner}</span>
                                    {/* eslint-disable-next-line no-restricted-syntax -- The design's inline source link. */}
                                    <button
                                        type="button"
                                        className="inline-evidence"
                                        onClick={() =>
                                            openSource(
                                                reminder.source.type,
                                                reminder.source.id,
                                            )
                                        }
                                    >
                                        {reminder.source.label}
                                        <ArrowUpRight className="size-[13px]" />
                                    </button>
                                </>,
                                <>
                                    <StatusBadge
                                        variant={STATE[reminder.state].variant}
                                    >
                                        {STATE[reminder.state].label}
                                    </StatusBadge>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            calendarDay(reminder.due_at)
                                        }
                                    >
                                        <CalendarDays className="size-[14px]" />
                                        View in calendar
                                    </Button>
                                </>,
                            ],
                            footer: {
                                personName: reminder.owner?.name,
                                primary: owner,
                                secondary: 'Follow-up owner',
                            },
                            actions: [
                                {
                                    label: 'View reminder & activity',
                                    icon: Bell,
                                    onClick: () => setActivity(reminder),
                                },
                                {
                                    label: 'View in calendar',
                                    icon: CalendarDays,
                                    onClick: () => calendarDay(reminder.due_at),
                                },
                                ...(can.manage && reminder.state !== 'completed'
                                    ? [
                                          {
                                              label: 'Edit / reschedule',
                                              icon: Pencil,
                                              onClick: () =>
                                                  setEditing({ reminder }),
                                          },
                                      ]
                                    : []),
                                ...(can.manage && reminder.state === 'scheduled'
                                    ? [
                                          {
                                              label: 'Acknowledge',
                                              icon: Check,
                                              onClick: () =>
                                                  setActing({
                                                      reminder,
                                                      action: 'acknowledge',
                                                  }),
                                          },
                                      ]
                                    : []),
                                ...(can.manage && open
                                    ? [
                                          {
                                              label: 'Complete follow-up',
                                              icon: CheckCircle2,
                                              onClick: () =>
                                                  setActing({
                                                      reminder,
                                                      action: 'complete',
                                                  }),
                                          },
                                          {
                                              label: 'Pause reminder',
                                              icon: Clock3,
                                              onClick: () =>
                                                  setActing({
                                                      reminder,
                                                      action: 'pause',
                                                  }),
                                          },
                                      ]
                                    : []),
                                ...(can.manage && reminder.state === 'paused'
                                    ? [
                                          {
                                              label: 'Resume reminder',
                                              icon: Play,
                                              onClick: () =>
                                                  setActing({
                                                      reminder,
                                                      action: 'resume',
                                                  }),
                                          },
                                      ]
                                    : []),
                            ],
                        };
                    })}
                />
            </section>
            <section className="studio-card reminder-obligations">
                <div className="studio-section-heading">
                    <div>
                        <span className="studio-eyebrow">
                            FROM SERVICE &amp; COMPLIANCE
                        </span>
                        <h3 className="text-section-title">
                            Obligation reminders
                        </h3>
                    </div>
                    <StatusBadge variant={failed ? 'critical' : 'info'}>
                        {failed
                            ? `${failed} delivery failed`
                            : 'Delivery history available'}
                    </StatusBadge>
                </div>
                <VehicleRecordCollection
                    label="Obligation reminders"
                    view={view}
                    columns={[
                        { label: 'Due / owner', width: '1.3fr' },
                        { label: 'Delivery status' },
                        { label: 'Next action', width: '1.1fr' },
                    ]}
                    empty={{
                        title: 'No dated obligations',
                        description:
                            'Service schedules and applicable registration, WoF, CoF and RUC records appear here with their reminders.',
                    }}
                    records={obligations.map((reminder) => {
                        const owner =
                            reminder.owner?.name ?? 'No owner recorded';
                        const isFailed = reminder.state === 'failed';
                        const nextAction = isFailed
                            ? reminder.can.retry
                                ? 'retry'
                                : null
                            : reminder.can.acknowledge
                              ? 'acknowledge'
                              : null;
                        const scheduleRecord =
                            reminder.source_type === 'service_schedule'
                                ? workspace.schedules.find(
                                      (item) => item.id === reminder.source_id,
                                  )
                                : undefined;
                        return {
                            id: reminder.key,
                            name: reminder.name,
                            subline: `Reminder ${reminder.lead} before`,
                            icon: Bell,
                            tone: isFailed ? 'critical' : undefined,
                            onOpen: () => setObligationHistory(reminder),
                            fields: [
                                <>
                                    <strong>{obligationDue(reminder)}</strong>
                                    <small>{owner}</small>
                                </>,
                                <StatusBadge
                                    key="status"
                                    variant={
                                        isFailed
                                            ? 'critical'
                                            : reminder.state === 'sent'
                                              ? 'info'
                                              : reminder.state ===
                                                  'acknowledged'
                                                ? 'success'
                                                : 'neutral'
                                    }
                                >
                                    {reminder.status_label}
                                </StatusBadge>,
                                nextAction ? (
                                    <Button
                                        key="next"
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            setObligationAction({
                                                reminder,
                                                action: nextAction,
                                            })
                                        }
                                    >
                                        {nextAction === 'retry'
                                            ? 'Retry delivery'
                                            : 'Acknowledge'}
                                    </Button>
                                ) : (
                                    <small key="next">
                                        {reminder.state === 'acknowledged'
                                            ? `Acknowledged${reminder.acknowledged_by ? ` by ${reminder.acknowledged_by}` : ''}`
                                            : 'No action needed yet'}
                                    </small>
                                ),
                            ],
                            footer: {
                                personName: reminder.owner?.name,
                                primary: owner,
                                secondary: 'Obligation owner',
                            },
                            actions: [
                                {
                                    label: 'Delivery history',
                                    icon: History,
                                    onClick: () =>
                                        setObligationHistory(reminder),
                                },
                                ...(reminder.due_on
                                    ? [
                                          {
                                              label: 'View in calendar',
                                              icon: CalendarDays,
                                              onClick: () =>
                                                  onNavigate({
                                                      tab: 'calendar',
                                                      date: reminder.due_on!,
                                                  }),
                                          },
                                      ]
                                    : []),
                                {
                                    label: 'Open source obligation',
                                    icon: ArrowUpRight,
                                    onClick: () =>
                                        openSource(
                                            reminder.source_type,
                                            reminder.source_id,
                                        ),
                                },
                                ...(can.manage
                                    ? [
                                          {
                                              label: 'Add linked follow-up',
                                              icon: Bell,
                                              onClick: () =>
                                                  setEditing({
                                                      reminder: null,
                                                      source: reminder.key,
                                                  }),
                                          },
                                      ]
                                    : []),
                                ...(scheduleRecord && can.manage_schedules
                                    ? [
                                          {
                                              label: 'Manage reminder plan',
                                              icon: CalendarDays,
                                              onClick: () =>
                                                  setSchedule(scheduleRecord),
                                          },
                                      ]
                                    : reminder.source_type ===
                                        'compliance_record'
                                      ? [
                                            {
                                                label: 'Reminder plan',
                                                icon: CalendarDays,
                                                onClick: () =>
                                                    setPolicy(reminder),
                                            },
                                        ]
                                      : []),
                                ...(nextAction
                                    ? [
                                          {
                                              label:
                                                  nextAction === 'retry'
                                                      ? 'Retry delivery'
                                                      : 'Acknowledge',
                                              icon: Bell,
                                              onClick: () =>
                                                  setObligationAction({
                                                      reminder,
                                                      action: nextAction,
                                                  }),
                                          },
                                      ]
                                    : []),
                            ],
                        };
                    })}
                />
                <p className="studio-footnote">
                    Acknowledgement does not complete the service or compliance
                    obligation. Owners are told in the app when a due point
                    comes within its lead time; a failed delivery is tried again
                    each morning until it goes through or is acknowledged.
                </p>
            </section>

            {editing && (
                <ReminderDialog
                    workspace={workspace}
                    reminder={editing.reminder}
                    presetSource={editing.source}
                    onClose={() => setEditing(null)}
                    onSaved={onChanged}
                />
            )}
            {acting && (
                <ReminderActionDialog
                    vehicleId={vehicle.id}
                    reminder={acting.reminder}
                    action={acting.action}
                    onClose={() => setActing(null)}
                    onSaved={onChanged}
                />
            )}
            <ReminderActivityDialog
                reminder={activity}
                onClose={() => setActivity(null)}
            />
            {obligationHistory && (
                <ObligationHistoryDialog
                    vehicle={vehicle}
                    reminder={obligationHistory}
                    onClose={() => setObligationHistory(null)}
                />
            )}
            {obligationAction && (
                <ObligationActionDialog
                    vehicleId={vehicle.id}
                    reminder={obligationAction.reminder}
                    action={obligationAction.action}
                    onClose={() => setObligationAction(null)}
                    onSaved={onChanged}
                />
            )}
            {policy && (
                <SourceRecordDialog
                    title="Compliance reminder plan"
                    description={`${policy.name} · ${[vehicle.asset_tag, vehicle.name].filter(Boolean).join(' · ')}`}
                    rows={[
                        [
                            'Owner',
                            policy.owner?.name ??
                                'No responsible person recorded',
                        ],
                        ['Lead time', `${policy.lead} before the due point`],
                        [
                            'Delivery',
                            'In-app notification to the owner, each morning at 7 am',
                        ],
                        [
                            'Change the owner',
                            "Record the vehicle's responsible person in Vehicle details.",
                        ],
                    ]}
                    onClose={() => setPolicy(null)}
                />
            )}
            {schedule && (
                <ScheduleDialog
                    workspace={workspace}
                    schedule={schedule}
                    onClose={() => setSchedule(null)}
                    onSaved={onChanged}
                />
            )}
        </div>
    );
}

const STEPS = [
    {
        key: 'source',
        label: 'Reminder & source',
        blurb: 'What to follow up and why',
        icon: Bell,
    },
    {
        key: 'timing',
        label: 'Timing & responsibility',
        blurb: 'When, and who owns it',
        icon: UserRound,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm the reminder',
        icon: Check,
    },
];

function tomorrowAtNine(): string {
    const local = toDatetimeLocal(
        new Date(Date.now() + 24 * 60 * 60 * 1000).toISOString(),
    );
    return `${local.slice(0, 10)}T09:00`;
}

export function ReminderDialog({
    workspace,
    reminder,
    presetSource,
    presetDueLocal,
    onClose,
    onSaved,
}: {
    workspace: VehicleWorkspace;
    reminder: VehicleReminder | null;
    presetSource?: string;
    /** A calendar slot (Auckland wall time) for a new reminder. */
    presetDueLocal?: string;
    onClose: () => void;
    onSaved: () => void;
}) {
    const vehicle = workspace.vehicle;
    const sources = useMemo(() => sourceOptions(workspace), [workspace]);
    const [initial] = useState(() => ({
        title: reminder?.title ?? '',
        source:
            presetSource ??
            (reminder?.source.type && reminder.source.type !== 'vehicle'
                ? `${reminder.source.type}:${reminder.source.id}`
                : 'vehicle'),
        action_text: reminder?.action_text ?? '',
        remind_local: reminder?.due_at
            ? toDatetimeLocal(reminder.due_at)
            : (presetDueLocal ?? tomorrowAtNine()),
        owner_user_id: reminder?.owner?.id ?? vehicle.responsible?.id ?? null,
        backup_user_id: reminder?.backup?.id ?? null,
        repeat_months: String(reminder?.repeat_months ?? 0),
        reason: '',
    }));
    const [form, setForm] = useState(initial);
    const [step, setStep] = useState(0);
    const [localErrors, setLocalErrors] = useState<Record<string, string>>({});
    const [saved, setSaved] = useState(false);
    const command = useVehicleRecordCommand(isJsonObject);
    const errors = { ...command.errors, ...localErrors };
    const update = <K extends keyof typeof form>(
        key: K,
        value: (typeof form)[K],
    ) => {
        setForm((old) => ({ ...old, [key]: value }));
        setLocalErrors((old) => {
            const next = { ...old };
            delete next[key as string];
            return next;
        });
        command.clearError(key as string);
    };
    useEffect(() => {
        const field = Object.keys(command.errors)[0];
        if (field)
            setStep(
                ['title', 'source_id', 'source_type', 'action_text'].includes(
                    field,
                )
                    ? 0
                    : 1,
            );
    }, [command.errors]);

    const validateStep = (at: number): boolean => {
        const found: Record<string, string> = {};
        if (at === 0) {
            if (!form.title.trim()) found.title = 'Choose the reminder title.';
            if (!form.action_text.trim())
                found.action_text = 'Record the action to take.';
        }
        if (at === 1) {
            if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(form.remind_local))
                found.remind_local = 'Choose when to remind.';
            if (!form.owner_user_id) found.owner_user_id = 'Choose the owner.';
            if (
                form.backup_user_id &&
                form.backup_user_id === form.owner_user_id
            )
                found.backup_user_id =
                    'Choose a different person as the backup owner.';
            if (reminder && !form.reason.trim())
                found.reason = 'Record the reason for this change.';
        }
        setLocalErrors(found);
        return Object.keys(found).length === 0;
    };

    const submit = async () => {
        if (!command.uncertain) {
            for (const at of [0, 1]) {
                if (!validateStep(at)) {
                    setStep(at);
                    return;
                }
            }
        }
        const source =
            sources.find((option) => option.value === form.source) ??
            sources[0];
        const body = {
            title: form.title.trim(),
            action_text: form.action_text.trim(),
            source_type: source.type,
            source_id: source.id,
            remind_local: form.remind_local,
            owner_user_id: form.owner_user_id,
            backup_user_id: form.backup_user_id,
            repeat_months: Number(form.repeat_months || 0),
            ...(reminder
                ? {
                      reason: form.reason.trim(),
                      expected_version: reminder.lock_version,
                  }
                : {}),
        };
        const result = reminder
            ? await command.submit(
                  `/fleet-assets/vehicles/${vehicle.id}/reminders/${reminder.id}`,
                  body,
                  { method: 'PUT' },
              )
            : await command.submit(
                  `/fleet-assets/vehicles/${vehicle.id}/reminders`,
                  body,
              );
        if (result) {
            setSaved(true);
            onSaved();
        }
    };
    const owner = workspace.people.find(
        (person) => person.id === form.owner_user_id,
    );
    const backup = workspace.people.find(
        (person) => person.id === form.backup_user_id,
    );

    return (
        <WorkspaceWizard
            title={
                reminder ? 'Manage vehicle reminder' : 'Add vehicle reminder'
            }
            description={`${vehicle.name}: a reminder is a prompt for its owner; it never reserves the vehicle.`}
            railIcon={Bell}
            railSub={vehicle.registration_number ?? vehicle.asset_tag ?? ''}
            steps={STEPS}
            step={step}
            setStep={setStep}
            pct={Math.round(
                ([
                    !!form.title,
                    !!form.action_text.trim(),
                    !!form.remind_local,
                    !!form.owner_user_id,
                ].filter(Boolean).length /
                    4) *
                    100,
            )}
            context={{
                name: vehicle.name,
                detail: [vehicle.registration_number, vehicle.site?.name]
                    .filter(Boolean)
                    .join(' · '),
            }}
            command={command}
            dirty={JSON.stringify(form) !== JSON.stringify(initial)}
            saved={saved}
            submitLabel={reminder ? 'Save reminder' : 'Create reminder'}
            onValidateStep={validateStep}
            onSubmit={submit}
            onClose={onClose}
            onReload={onClose}
            errorKey={JSON.stringify(errors)}
            success={
                <WizardSuccess
                    title={reminder ? 'Reminder saved' : 'Reminder created'}
                    blurb="It shows on this vehicle, in All Tasks for its owner and on the site calendar."
                    onClose={onClose}
                />
            }
        >
            {step === 0 && (
                <div className="space-y-5">
                    <p className="text-subtle">
                        Link the follow-up to the vehicle or the record it is
                        about.
                    </p>
                    <WizardField
                        id="title"
                        label="Reminder title"
                        error={errors.title}
                    >
                        <CataloguePicker
                            id="title"
                            kind="reminder_title"
                            label="Reminder title"
                            value={form.title}
                            onChange={(value) => update('title', value)}
                            invalid={!!errors.title}
                        />
                    </WizardField>
                    <WizardField
                        id="source"
                        label="Linked record"
                        error={errors.source_id ?? errors.source_type}
                    >
                        <Select
                            value={form.source}
                            onValueChange={(value) => update('source', value)}
                        >
                            <SelectTrigger
                                {...fieldProps('source', errors.source_id)}
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {sources.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </WizardField>
                    <WizardField
                        id="action_text"
                        label="Action to take"
                        error={errors.action_text}
                    >
                        <Textarea
                            {...fieldProps('action_text', errors.action_text)}
                            rows={3}
                            maxLength={2000}
                            value={form.action_text}
                            onChange={(event) =>
                                update('action_text', event.target.value)
                            }
                        />
                    </WizardField>
                </div>
            )}
            {step === 1 && (
                <div className="space-y-5">
                    <DateTimeField
                        id="remind_local"
                        label="Remind at"
                        value={form.remind_local}
                        onChange={(value) => update('remind_local', value)}
                        error={errors.remind_local}
                        hint="Pacific/Auckland time · an in-app task for the owner"
                    />
                    <div className="vehicle-wizard-fields">
                        <WizardField
                            id="owner_user_id"
                            label="Owner"
                            error={errors.owner_user_id}
                        >
                            <PersonPicker
                                id="owner_user_id"
                                label="Owner"
                                value={form.owner_user_id}
                                people={workspace.people}
                                onChange={(value) =>
                                    update('owner_user_id', value)
                                }
                                invalid={!!errors.owner_user_id}
                            />
                        </WizardField>
                        <WizardField
                            id="backup_user_id"
                            label="Backup owner"
                            optional
                            error={errors.backup_user_id}
                        >
                            <PersonPicker
                                id="backup_user_id"
                                label="Backup owner"
                                value={form.backup_user_id}
                                people={workspace.people}
                                onChange={(value) =>
                                    update('backup_user_id', value)
                                }
                                invalid={!!errors.backup_user_id}
                            />
                        </WizardField>
                    </div>
                    <WizardField
                        id="repeat_months"
                        label="Repeat every"
                        hint="One-off, or a whole number of calendar months."
                        error={errors.repeat_months}
                    >
                        <CataloguePicker
                            id="repeat_months"
                            kind="repeat_months"
                            label="Repeat every"
                            value={form.repeat_months}
                            onChange={(value) => update('repeat_months', value)}
                        />
                    </WizardField>
                    {reminder && (
                        <WizardField
                            id="reason"
                            label="Reason for change"
                            error={errors.reason}
                        >
                            <Textarea
                                {...fieldProps('reason', errors.reason)}
                                rows={2}
                                maxLength={2000}
                                value={form.reason}
                                onChange={(event) =>
                                    update('reason', event.target.value)
                                }
                            />
                        </WizardField>
                    )}
                </div>
            )}
            {step === 2 && (
                <div className="grid gap-4">
                    <ReviewCard
                        icon={Bell}
                        title="Reminder & source"
                        onEdit={() => setStep(0)}
                    >
                        <ReviewRow
                            label="Title"
                            value={form.title || undefined}
                        />
                        <ReviewRow
                            label="Linked record"
                            value={
                                sources.find(
                                    (option) => option.value === form.source,
                                )?.label
                            }
                        />
                        <ReviewRow
                            label="Action"
                            value={form.action_text || undefined}
                        />
                    </ReviewCard>
                    <ReviewCard
                        icon={UserRound}
                        title="Timing & responsibility"
                        onEdit={() => setStep(1)}
                    >
                        <ReviewRow
                            label="Remind at"
                            value={
                                form.remind_local
                                    ? `${form.remind_local.replace('T', ' ')} · Pacific/Auckland`
                                    : undefined
                            }
                        />
                        <ReviewRow label="Owner" value={owner?.name} />
                        <ReviewRow label="Backup owner" value={backup?.name} />
                        <ReviewRow
                            label="Repeat"
                            value={formatChoice(
                                'repeat_months',
                                form.repeat_months || '0',
                            )}
                        />
                    </ReviewCard>
                </div>
            )}
        </WorkspaceWizard>
    );
}

const ACTION_COPY: Record<
    ReminderAction,
    { title: string; button: string; field: string }
> = {
    acknowledge: {
        title: 'Acknowledge vehicle reminder',
        button: 'Acknowledge reminder',
        field: 'Owner note',
    },
    complete: {
        title: 'Complete reminder follow-up',
        button: 'Record follow-up',
        field: 'Outcome and next action',
    },
    pause: {
        title: 'Pause vehicle reminder',
        button: 'Pause reminder',
        field: 'Reason for pausing',
    },
    resume: {
        title: 'Resume vehicle reminder',
        button: 'Resume reminder',
        field: 'Reason for resuming',
    },
};

export function ReminderActionDialog({
    vehicleId,
    reminder,
    action,
    onClose,
    onSaved,
}: {
    vehicleId: number;
    reminder: VehicleReminder;
    action: ReminderAction;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [note, setNote] = useState('');
    const [error, setError] = useState('');
    const command = useVehicleRecordCommand(isJsonObject);
    const copy = ACTION_COPY[action];
    const save = async () => {
        if (!note.trim()) {
            setError(
                action === 'complete'
                    ? 'Record the outcome and next action.'
                    : 'Record a reason or owner note.',
            );
            return;
        }
        const result = await command.submit(
            `/fleet-assets/vehicles/${vehicleId}/reminders/${reminder.id}/${action}`,
            {
                note: note.trim(),
                expected_version: reminder.lock_version,
            },
        );
        if (result) {
            onSaved();
            onClose();
        }
    };

    return (
        <Dialog
            open
            onOpenChange={(next) => !next && !command.processing && onClose()}
        >
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{copy.title}</DialogTitle>
                    <DialogDescription>
                        {reminder.title} · This does not complete linked work,
                        evidence or a renewal.
                        {action === 'complete' && reminder.repeat_months > 0
                            ? ' A repeating reminder moves on to its next date.'
                            : ''}
                    </DialogDescription>
                </DialogHeader>
                {command.message && (
                    <p
                        role="alert"
                        className="rounded-lg bg-status-warning-bg p-3 text-sm text-status-warning"
                    >
                        {command.errors.note ?? command.message}
                    </p>
                )}
                <WizardField
                    id="reminder-note"
                    label={copy.field}
                    error={error}
                >
                    <Textarea
                        {...fieldProps('reminder-note', error)}
                        rows={3}
                        maxLength={2000}
                        value={note}
                        onChange={(event) => {
                            setNote(event.target.value);
                            setError('');
                        }}
                    />
                </WizardField>
                <DialogFooter>
                    <Button
                        variant="outline"
                        disabled={command.processing}
                        onClick={onClose}
                    >
                        Cancel
                    </Button>
                    <Button
                        disabled={command.processing || command.requiresReload}
                        onClick={save}
                    >
                        {command.processing && (
                            <Loader2 className="size-4 animate-spin" />
                        )}
                        {command.uncertain ? 'Retry' : copy.button}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

const EVENT_LABELS: Record<string, string> = {
    created: 'Created',
    updated: 'Changed',
    acknowledge: 'Acknowledged',
    complete: 'Follow-up recorded',
    pause: 'Paused',
    resume: 'Resumed',
};

export function ReminderActivityDialog({
    reminder,
    onClose,
}: {
    reminder: VehicleReminder | null;
    onClose: () => void;
}) {
    return (
        <Dialog
            open={reminder !== null}
            onOpenChange={(next) => !next && onClose()}
        >
            <DialogContent className="max-w-xl">
                <DialogHeader>
                    <DialogTitle>{reminder?.title}</DialogTitle>
                    <DialogDescription>
                        {reminder?.source.label} ·{' '}
                        {reminder?.owner?.name ?? 'Owner unavailable'}
                        {reminder?.backup
                            ? ` · backup ${reminder.backup.name}`
                            : ''}
                    </DialogDescription>
                </DialogHeader>
                {reminder?.action_text && (
                    <p className="text-sm">{reminder.action_text}</p>
                )}
                <ol className="scrollbar-pretty grid max-h-[50vh] gap-2 overflow-y-auto">
                    {reminder?.events.map((event) => (
                        <li
                            key={event.id}
                            className="rounded-lg border p-3 text-sm"
                        >
                            <div className="flex flex-wrap justify-between gap-2">
                                <strong>
                                    {EVENT_LABELS[event.action] ?? event.action}
                                </strong>
                                <span className="text-caption">
                                    {event.actor ?? 'Someone'} ·{' '}
                                    {event.occurred_at
                                        ? formatDateTime(event.occurred_at)
                                        : ''}
                                </span>
                            </div>
                            {event.note && <p className="mt-1">{event.note}</p>}
                        </li>
                    ))}
                </ol>
            </DialogContent>
        </Dialog>
    );
}

/** Move a follow-up later. The linked service, due date or restriction is unchanged. */
export function SnoozeReminderDialog({
    vehicleId,
    reminder,
    onClose,
    onSaved,
}: {
    vehicleId: number;
    reminder: VehicleReminder;
    onClose: () => void;
    onSaved: () => void;
}) {
    const now = toDatetimeLocal(new Date().toISOString());
    const current = reminder.due_at ? toDatetimeLocal(reminder.due_at) : now;
    const base = current > now ? current : now;
    const [at, setAt] = useState(() => {
        const next = new Date(`${base}:00Z`);
        next.setUTCHours(next.getUTCHours() + 1);
        return next.toISOString().slice(0, 16);
    });
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const command = useVehicleRecordCommand(isJsonObject);
    const save = async () => {
        const found: Record<string, string> = {};
        if (at <= now || at <= current)
            found.remind_local =
                'Choose a time after the current reminder and after now.';
        if (!note.trim()) found.note = 'Record the reason for snoozing.';
        setErrors(found);
        if (Object.keys(found).length) return;
        const result = await command.submit(
            `/fleet-assets/vehicles/${vehicleId}/reminders/${reminder.id}/snooze`,
            {
                note: note.trim(),
                remind_local: at,
                expected_version: reminder.lock_version,
            },
        );
        if (result) {
            onSaved();
            onClose();
        }
    };
    const error = (key: string) => errors[key] ?? command.errors[key];

    return (
        <RecordDialog
            title="Snooze vehicle reminder"
            description={`${reminder.title} · The linked service, compliance due date and vehicle restriction stay unchanged.`}
            command={command}
            submitLabel="Snooze reminder"
            onSubmit={save}
            onClose={onClose}
        >
            <DateTimeField
                id="remind_local"
                label="Remind me at"
                value={at}
                onChange={(value) => {
                    setAt(value);
                    setErrors({});
                }}
                error={error('remind_local')}
            />
            <WizardField
                id="snooze-note"
                label="Reason for snoozing"
                error={error('note')}
            >
                <Textarea
                    {...fieldProps('snooze-note', error('note'))}
                    rows={2}
                    maxLength={2000}
                    value={note}
                    onChange={(event) => {
                        setNote(event.target.value);
                        setErrors({});
                    }}
                />
            </WizardField>
        </RecordDialog>
    );
}
