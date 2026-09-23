import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { formatDateTime, toDatetimeLocal } from '@/lib/datetime';
import {
    AgendaView,
    CalendarUIProvider,
    DayView,
    MO,
    MiniMonth,
    MonthView,
    TimelineView,
    TodayRail,
    WeekView,
    addDays,
    decorate,
    sameDay,
    startOfWeek,
    type Decorated,
    type Density,
    type SourceDef,
} from '@/pages/sites/calendar/_parts';
import {
    ArrowLeft,
    ArrowUpRight,
    BellPlus,
    CalendarClock,
    CalendarDays,
    CalendarPlus,
    Check,
    CheckCheck,
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    Clock,
    Clock3,
    Columns3,
    FileText,
    History,
    KeyRound,
    LayoutGrid,
    List,
    Lock,
    Route,
    Rows3,
    ShieldAlert,
    ShieldCheck,
    Upload,
    Wrench,
    X,
    type LucideIcon,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { AddEvidenceDialog } from './add-evidence-dialog';
import {
    AppointmentWizard,
    type PlannedAppointment,
} from './appointment-wizard';
import {
    BookingDecisionWizard,
    type BookingDecision,
} from './booking-decision-wizard';
import {
    BookingWizard,
    defaultBookingStart,
    type BookingWizardMode,
} from './booking-wizard';
import { BookingsStudio } from './bookings-studio';
import type {
    CustodyRow,
    VehicleCalendarItem,
    VehicleCalendarSummary,
} from './calendar-types';
import {
    ObligationActionDialog,
    ObligationHistoryDialog,
} from './obligation-reminders';
import {
    ReminderActionDialog,
    ReminderActivityDialog,
    ReminderDialog,
    SnoozeReminderDialog,
    type ReminderAction,
} from './service-reminders';
import { SourceRecordDialog, openWorkOrder } from './studio-kit';
import './studio.css';
import type {
    ObligationReminder,
    VehicleReminder,
    VehicleWorkspace,
} from './types';
import { WorkEvidenceDialog } from './work-evidence-dialog';
import type { WorkspaceLocation } from './workspace-model';

type View = 'month' | 'week' | 'day' | 'agenda' | 'timeline';

type CalendarAction = {
    label: string;
    icon: LucideIcon;
    run: () => void;
    disabled?: boolean;
    destructive?: boolean;
    reason?: string;
};

type Entry = Decorated & VehicleCalendarItem;

type DialogState =
    | { kind: 'booking'; mode: BookingWizardMode }
    | { kind: 'decision'; row: CustodyRow; decision: BookingDecision }
    | {
          kind: 'appointment';
          startLocal?: string;
          workOrderId?: number;
          appointment?: PlannedAppointment;
          presetType?: string;
      }
    | {
          kind: 'reminder';
          reminder: VehicleReminder | null;
          presetSource?: string;
          presetDueLocal?: string;
      }
    | {
          kind: 'reminder-action';
          reminder: VehicleReminder;
          action: ReminderAction;
      }
    | { kind: 'snooze'; reminder: VehicleReminder }
    | { kind: 'reminder-activity'; reminder: VehicleReminder }
    | { kind: 'record'; title: string; rows: Array<[string, string]> }
    | { kind: 'work-evidence'; workOrderId: number; label: string }
    | { kind: 'obligation-history'; reminder: ObligationReminder }
    | {
          kind: 'obligation-action';
          reminder: ObligationReminder;
          action: 'acknowledge' | 'retry';
      }
    | {
          kind: 'custody-evidence';
          source: 'booking' | 'unavailable_period';
          id: number;
          label: string;
      };

const SOURCES: SourceDef[] = [
    {
        key: 'event',
        label: 'Service appointments',
        short: 'Appointment',
        group: 'auto',
        icon: 'Wrench',
        origin: 'Maintenance',
        note: 'Provider appointment on a Maintenance work order',
    },
    {
        key: 'asset',
        label: 'Estimated work',
        short: 'Estimate',
        group: 'auto',
        icon: 'Wrench',
        origin: 'Maintenance',
        note: 'Planning estimate from a maintenance report',
    },
    {
        key: 'respite',
        label: 'Bookings & unavailable',
        short: 'Booking',
        group: 'auto',
        icon: 'Lock',
        origin: 'Vehicle bookings',
        note: 'Booking or unavailable period for this vehicle',
    },
    {
        key: 'compliance',
        label: 'Reminders',
        short: 'Reminder',
        group: 'auto',
        icon: 'ShieldCheck',
        origin: 'Compliance',
        note: 'Due reminder; it does not reserve the vehicle',
    },
    {
        key: 'damage',
        label: 'Restriction records',
        short: 'Restriction',
        group: 'auto',
        icon: 'AlertTriangle',
        origin: 'Maintenance',
        note: 'Maintenance restriction on vehicle use',
    },
];
const SOURCE_KEYS = SOURCES.map((source) => source.key);

const VIEW_ITEMS = [
    { key: 'month' as const, label: 'Month', icon: LayoutGrid },
    { key: 'week' as const, label: 'Week', icon: Columns3 },
    { key: 'day' as const, label: 'Day', icon: Clock },
    { key: 'agenda' as const, label: 'Agenda', icon: List },
    { key: 'timeline' as const, label: 'Timeline', icon: Rows3 },
];

const fullDate = (date: Date) =>
    date.toLocaleDateString('en-NZ', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
const shortDate = (iso: string) =>
    new Date(iso).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        timeZone: 'Pacific/Auckland',
    });
const dateKey = (date: Date) =>
    [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, '0'),
        String(date.getDate()).padStart(2, '0'),
    ].join('-');

/** 9 am on a day, or the next whole hour when that has already passed. */
function futureStart(day: Date): string {
    const start = `${dateKey(day)}T09:00`;
    const soonest = defaultBookingStart();
    return start > soonest ? start : soonest;
}

function decorateAll(items: VehicleCalendarItem[]): Entry[] {
    return items.map((item) => ({
        ...(decorate(item) as Decorated & VehicleCalendarItem),
        typeLabel: SOURCES.find((source) => source.key === item.source)?.short,
    }));
}

/** Load calendar items for a window, dropping stale responses. */
function useCalendarFeed(
    vehicleId: number,
    start: Date,
    end: Date,
    version: number,
) {
    const [items, setItems] = useState<Entry[]>([]);
    const [state, setState] = useState<'loading' | 'ready' | 'failed'>(
        'loading',
    );
    const startIso = start.toISOString();
    const endIso = end.toISOString();
    useEffect(() => {
        const controller = new AbortController();
        setState('loading');
        const params = new URLSearchParams({ start: startIso, end: endIso });
        fetch(`/fleet-assets/vehicles/${vehicleId}/calendar/events?${params}`, {
            signal: controller.signal,
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then(async (response) => {
                if (!response.ok) throw new Error(String(response.status));
                const data = (await response.json()) as {
                    events?: VehicleCalendarItem[];
                };
                setItems(decorateAll(data.events ?? []));
                setState('ready');
            })
            .catch((error: unknown) => {
                if ((error as Error)?.name === 'AbortError') return;
                setState('failed');
            });
        return () => controller.abort();
    }, [vehicleId, startIso, endIso, version]);
    return { items, state };
}

export function VehicleCalendar({
    workspace,
    focusDate,
    onBack,
    onNavigate,
    onChanged,
}: {
    workspace: VehicleWorkspace;
    focusDate?: string;
    onBack: () => void;
    onNavigate: (location: WorkspaceLocation) => void;
    onChanged: () => void;
}) {
    const vehicle = workspace.vehicle;
    const rightClick = useRef<{ x: number; y: number } | null>(null);
    const [eventMenu, setEventMenu] = useState<{
        x: number;
        y: number;
        entry: Entry;
    } | null>(null);
    const [creation, setCreation] = useState<{
        x: number;
        y: number;
        date: Date;
        hour?: number;
    } | null>(null);
    const [view, setView] = useState<View>('month');
    const [navDate, setNavDate] = useState(() =>
        focusDate ? new Date(`${focusDate}T12:00`) : new Date(),
    );
    const [query, setQuery] = useState('');
    const [density, setDensity] = useState<Density>('comfortable');
    const [enabled, setEnabled] = useState<string[]>(SOURCE_KEYS);
    const [jumpOpen, setJumpOpen] = useState(false);
    const [dialog, setDialog] = useState<DialogState | null>(null);
    const [version, setVersion] = useState(0);
    const [summary, setSummary] = useState<VehicleCalendarSummary | null>(null);
    const [summaryFailed, setSummaryFailed] = useState(false);
    const today = new Date();

    // The browsed window: the month grid with a week either side.
    const monthKey = `${navDate.getFullYear()}-${navDate.getMonth()}`;
    const windowStart = useMemo(
        () =>
            addDays(
                startOfWeek(
                    new Date(navDate.getFullYear(), navDate.getMonth(), 1),
                ),
                -7,
            ),
        // eslint-disable-next-line react-hooks/exhaustive-deps -- recompute per month only
        [monthKey],
    );
    const windowEnd = useMemo(() => addDays(windowStart, 56), [windowStart]);
    const feed = useCalendarFeed(vehicle.id, windowStart, windowEnd, version);
    // Today's schedule stays anchored to today, whatever period is browsed.
    const railStart = useMemo(
        () => addDays(new Date(new Date().toDateString()), -1),
        [],
    );
    const railEnd = useMemo(() => addDays(railStart, 60), [railStart]);
    const rail = useCalendarFeed(vehicle.id, railStart, railEnd, version);

    const loadSummary = useCallback(() => {
        fetch(`/fleet-assets/vehicles/${vehicle.id}/calendar/summary`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then(async (response) => {
                if (!response.ok) throw new Error(String(response.status));
                setSummary((await response.json()) as VehicleCalendarSummary);
                setSummaryFailed(false);
            })
            .catch(() => setSummaryFailed(true));
    }, [vehicle.id]);
    useEffect(() => {
        loadSummary();
    }, [loadSummary, version]);

    const changed = () => {
        setVersion((value) => value + 1);
        onChanged();
    };

    const visible = feed.items.filter(
        (entry) =>
            enabled.includes(entry.source) &&
            `${entry.title} ${entry.ref ?? ''}`
                .toLowerCase()
                .includes(query.toLowerCase()),
    );
    const railVisible = rail.items.filter(
        (entry) =>
            enabled.includes(entry.source) &&
            !['Returned', 'Completed', 'Released'].includes(
                entry.statusLabel ?? '',
            ),
    );
    const weekStart = startOfWeek(navDate);
    const weekEnd = addDays(weekStart, 6);
    const period =
        view === 'week'
            ? `${fullDate(weekStart)} – ${fullDate(weekEnd)}`
            : view === 'day'
              ? fullDate(navDate)
              : `${MO[navDate.getMonth()]} ${navDate.getFullYear()}`;
    const periodStart =
        view === 'week'
            ? weekStart
            : view === 'day'
              ? new Date(
                    navDate.getFullYear(),
                    navDate.getMonth(),
                    navDate.getDate(),
                )
              : new Date(navDate.getFullYear(), navDate.getMonth(), 1);
    const periodEnd =
        view === 'week'
            ? addDays(weekStart, 7)
            : view === 'day'
              ? addDays(periodStart, 1)
              : new Date(navDate.getFullYear(), navDate.getMonth() + 1, 1);
    const periodCount = visible.filter(
        (entry) =>
            entry._start < periodEnd &&
            (entry._end
                ? entry._end > periodStart
                : entry._start >= periodStart),
    ).length;
    const shift = (steps: number) =>
        setNavDate((date) =>
            view === 'week'
                ? addDays(date, steps * 7)
                : view === 'day'
                  ? addDays(date, steps)
                  : new Date(
                        date.getFullYear(),
                        date.getMonth() + steps,
                        Math.min(
                            date.getDate(),
                            new Date(
                                date.getFullYear(),
                                date.getMonth() + steps + 1,
                                0,
                            ).getDate(),
                        ),
                    ),
        );

    const nowLocal = toDatetimeLocal(new Date().toISOString());
    const reminderFor = (entry: Entry) =>
        workspace.reminders.find((reminder) => reminder.id === entry.recordId);
    const rowFor = (entry: Entry): CustodyRow | undefined =>
        summary?.bookings.find(
            (row) =>
                row.id === entry.recordId &&
                row.kind ===
                    (entry.kind === 'unavailable' ? 'unavailable' : 'booking'),
        );
    const openWork = (workOrderId: number | null) => {
        if (workOrderId)
            openWorkOrder(workOrderId, vehicle.id, { tab: 'calendar' });
    };
    const releaseRequirements = () =>
        setDialog({
            kind: 'record',
            title: 'Vehicle release requirements',
            rows: [
                [
                    'Original issue',
                    'Retain the failed check and its linked Maintenance record.',
                ],
                [
                    'Repair and retest',
                    'Record completed repair evidence and a passed retest.',
                ],
                [
                    'Readiness',
                    'Resolve overdue service, checks and compliance evidence.',
                ],
                [
                    'Authorisation',
                    'An independent authorised reviewer records the release decision. Completing work alone does not release the vehicle.',
                ],
            ],
        });
    const custodyRecord = (row: CustodyRow) =>
        setDialog({
            kind: 'record',
            title:
                row.kind === 'booking'
                    ? `Booking ${row.reference ?? `#${row.id}`}`
                    : 'Unavailable period',
            rows: custodyRows(row),
        });

    const openEntry = (entry: Entry) => {
        switch (entry.kind) {
            case 'restriction':
            case 'appointment':
            case 'estimate':
                openWork(entry.workOrderId);
                return;
            case 'booking':
            case 'unavailable': {
                const row = rowFor(entry);
                if (row) custodyRecord(row);
                return;
            }
            case 'busy':
                setDialog({
                    kind: 'record',
                    title: 'Busy',
                    rows: [
                        [
                            'Busy interval',
                            `${formatDateTime(entry.start ?? '')} – ${formatDateTime(entry.end ?? '')}`,
                        ],
                        ['Access', 'Booking details restricted'],
                    ],
                });
                return;
            case 'schedule':
                onNavigate({ tab: 'service', view: 'schedules' });
                return;
            case 'compliance':
                onNavigate({ tab: 'service', view: 'evidence' });
                return;
            case 'check':
                onNavigate({ tab: 'checks', view: 'recent' });
                return;
            case 'reminder': {
                const reminder = reminderFor(entry);
                if (reminder)
                    setDialog({ kind: 'reminder-activity', reminder });
                return;
            }
        }
    };

    const actionsFor = (entry: Entry): CalendarAction[] => {
        const can = summary?.can;
        if (!can) return [];
        if (entry.kind === 'reminder') {
            const reminder = reminderFor(entry);
            if (!reminder) return [];
            const open = ['scheduled', 'acknowledged'].includes(reminder.state);
            return [
                ...(can.add_reminder && open
                    ? [
                          {
                              label: 'Edit / reschedule reminder',
                              icon: CalendarClock,
                              run: () =>
                                  setDialog({ kind: 'reminder', reminder }),
                          },
                          {
                              label: 'Snooze reminder',
                              icon: Clock3,
                              run: () =>
                                  setDialog({ kind: 'snooze', reminder }),
                          },
                          {
                              label: 'Complete follow-up',
                              icon: CheckCheck,
                              run: () =>
                                  setDialog({
                                      kind: 'reminder-action',
                                      reminder,
                                      action: 'complete',
                                  }),
                          },
                      ]
                    : []),
                ...(reminder.source.type === 'document_set'
                    ? [
                          {
                              label: 'Open linked document',
                              icon: FileText,
                              run: () =>
                                  onNavigate({
                                      tab: 'overview',
                                      view: 'documents',
                                  }),
                          },
                      ]
                    : []),
                ...(reminder.source.type === 'work_order' && reminder.source.id
                    ? [
                          {
                              label: 'Open linked Maintenance',
                              icon: Wrench,
                              run: () => openWork(reminder.source.id),
                          },
                      ]
                    : []),
                {
                    label: 'View reminder activity',
                    icon: History,
                    run: () =>
                        setDialog({ kind: 'reminder-activity', reminder }),
                },
            ];
        }
        if (entry.kind === 'booking' || entry.kind === 'unavailable') {
            const row = rowFor(entry);
            if (!row) return [];
            if (row.kind === 'unavailable') {
                return [
                    ...(row.can.edit
                        ? [
                              {
                                  label: 'Change unavailable period',
                                  icon: CalendarClock,
                                  run: () =>
                                      setDialog({
                                          kind: 'booking',
                                          mode: { kind: 'change-block', row },
                                      }),
                              },
                          ]
                        : []),
                    ...(row.can.cancel
                        ? [
                              {
                                  label: 'Cancel unavailable period',
                                  icon: X,
                                  destructive: true,
                                  run: () =>
                                      setDialog({
                                          kind: 'decision',
                                          row,
                                          decision: 'cancel',
                                      }),
                              },
                          ]
                        : []),
                ];
            }
            const decide = (decision: BookingDecision) => () =>
                setDialog({ kind: 'decision', row, decision });
            const blocked = summary?.use_problem ?? '';
            return [
                ...(row.can.edit
                    ? [
                          {
                              label: 'Edit booking',
                              icon: CalendarClock,
                              run: () =>
                                  setDialog({
                                      kind: 'booking',
                                      mode: { kind: 'change', row },
                                  }),
                          },
                      ]
                    : []),
                ...(row.can.approve
                    ? [
                          {
                              label: 'Review & approve',
                              icon: Check,
                              run: decide('approve'),
                              disabled: !!blocked,
                              reason: blocked || undefined,
                          },
                      ]
                    : []),
                ...(row.can.checkout
                    ? [
                          {
                              label: 'Check out vehicle',
                              icon: KeyRound,
                              run: decide('out'),
                              disabled: !!blocked,
                              reason: blocked || undefined,
                          },
                      ]
                    : []),
                ...(row.can.return
                    ? [
                          {
                              label: 'Record vehicle return',
                              icon: KeyRound,
                              run: decide('return'),
                          },
                      ]
                    : []),
                ...(row.can.decline
                    ? [
                          {
                              label: 'Decline request',
                              icon: X,
                              destructive: true,
                              run: decide('decline'),
                          },
                      ]
                    : []),
                ...(row.can.cancel && row.status !== 'checked_out'
                    ? [
                          {
                              label: 'Cancel booking',
                              icon: X,
                              destructive: true,
                              run: decide('cancel'),
                          },
                      ]
                    : []),
            ];
        }
        if (
            (entry.kind === 'appointment' || entry.kind === 'estimate') &&
            entry.workOrderId
        ) {
            const workOrderId = entry.workOrderId;
            const openOrder = summary?.open_work.some(
                (work) => work.id === workOrderId,
            );
            // A planned appointment is managed; an estimate gets its first one.
            const planned =
                entry.kind === 'appointment' && entry.start && entry.meta?.open
                    ? {
                          start: entry.start,
                          end: entry.end,
                          provider: entry.meta.provider,
                          unavailable: entry.meta.unavailable,
                      }
                    : undefined;
            return can.schedule_service
                ? [
                      ...(openOrder && (planned || entry.kind === 'estimate')
                          ? [
                                {
                                    label: 'Reschedule / manage appointment',
                                    icon: CalendarClock,
                                    run: () =>
                                        setDialog({
                                            kind: 'appointment',
                                            workOrderId,
                                            appointment: planned,
                                            startLocal: planned
                                                ? undefined
                                                : futureStart(entry._start),
                                        }),
                                },
                            ]
                          : []),
                      {
                          label: 'Upload work evidence',
                          icon: Upload,
                          run: () =>
                              setDialog({
                                  kind: 'work-evidence',
                                  workOrderId,
                                  label: entry.ref ?? `Work #${workOrderId}`,
                              }),
                      },
                  ]
                : [];
        }
        if (entry.kind === 'restriction') {
            return [
                {
                    label: 'Open linked Maintenance',
                    icon: Wrench,
                    run: () => openWork(entry.workOrderId),
                },
                {
                    label: 'View release requirements',
                    icon: ClipboardCheck,
                    run: releaseRequirements,
                },
                ...(can.schedule_service
                    ? [
                          {
                              label: 'Review authorised release',
                              icon: ShieldCheck,
                              run: () => openWork(entry.workOrderId),
                          },
                      ]
                    : []),
            ];
        }
        if (
            entry.kind === 'schedule' ||
            entry.kind === 'compliance' ||
            entry.kind === 'check'
        ) {
            const source =
                entry.kind === 'schedule'
                    ? `service_schedule:${entry.recordId}`
                    : entry.kind === 'compliance'
                      ? `compliance_record:${entry.recordId}`
                      : 'vehicle';
            return [
                ...(can.schedule_service && entry.kind !== 'check'
                    ? [
                          {
                              label: 'Plan linked appointment',
                              icon: Wrench,
                              run: () =>
                                  setDialog({
                                      kind: 'appointment',
                                      startLocal: futureStart(entry._start),
                                      presetType: entry.title.replace(
                                          / due · reminder$/,
                                          '',
                                      ),
                                  }),
                          },
                      ]
                    : []),
                ...(can.add_reminder
                    ? [
                          {
                              label: 'Add follow-up reminder',
                              icon: BellPlus,
                              run: () =>
                                  setDialog({
                                      kind: 'reminder',
                                      reminder: null,
                                      presetSource: source,
                                  }),
                          },
                      ]
                    : []),
                ...obligationActions(source),
            ];
        }
        return [];
    };

    /** The obligation reminder behind a due date: acknowledge, retry and its history. */
    const obligationActions = (source: string): CalendarAction[] => {
        const reminder = workspace.obligation_reminders.find(
            (item) => item.key === source,
        );
        if (!reminder) return [];
        return [
            ...(reminder.can.acknowledge
                ? [
                      {
                          label: 'Acknowledge reminder',
                          icon: Check,
                          run: () =>
                              setDialog({
                                  kind: 'obligation-action',
                                  reminder,
                                  action: 'acknowledge',
                              }),
                      },
                  ]
                : []),
            ...(reminder.can.retry
                ? [
                      {
                          label: 'Retry delivery',
                          icon: BellPlus,
                          run: () =>
                              setDialog({
                                  kind: 'obligation-action',
                                  reminder,
                                  action: 'retry',
                              }),
                      },
                  ]
                : []),
            {
                label: 'View reminder activity',
                icon: History,
                run: () => setDialog({ kind: 'obligation-history', reminder }),
            },
        ];
    };

    const createActions = (start: string): CalendarAction[] => {
        const can = summary?.can;
        if (!can) return [];
        const past = start < nowLocal;
        return [
            ...(can.request
                ? [
                      {
                          label: 'Request vehicle booking',
                          icon: CalendarPlus,
                          run: () =>
                              setDialog({
                                  kind: 'booking',
                                  mode: { kind: 'request', startLocal: start },
                              }),
                          disabled: past,
                      },
                  ]
                : []),
            ...(can.schedule_service
                ? [
                      {
                          label: 'Schedule service or inspection',
                          icon: Wrench,
                          run: () =>
                              setDialog({
                                  kind: 'appointment',
                                  startLocal: start,
                              }),
                          disabled: past,
                      },
                  ]
                : []),
            ...(can.add_reminder
                ? [
                      {
                          label: 'Add reminder',
                          icon: BellPlus,
                          run: () =>
                              setDialog({
                                  kind: 'reminder',
                                  reminder: null,
                                  presetDueLocal: start,
                              }),
                          disabled: past,
                      },
                  ]
                : []),
            ...(can.mark_unavailable
                ? [
                      {
                          label: 'Mark vehicle unavailable',
                          icon: Lock,
                          run: () =>
                              setDialog({
                                  kind: 'booking',
                                  mode: { kind: 'block', startLocal: start },
                              }),
                          disabled: past,
                      },
                  ]
                : []),
        ];
    };

    const onSelect = (entry: Decorated) => {
        if (rightClick.current) {
            setEventMenu({ ...rightClick.current, entry: entry as Entry });
            rightClick.current = null;
        } else openEntry(entry as Entry);
    };
    const startFor = (date: Date, hour?: number) => {
        if (hour === undefined) {
            if (sameDay(date, today)) return defaultBookingStart();
            return `${dateKey(date)}T09:00`;
        }
        return `${dateKey(date)}T${String(Math.floor(hour)).padStart(2, '0')}:${String(Math.round((hour % 1) * 60)).padStart(2, '0')}`;
    };
    const creationStart = creation
        ? startFor(creation.date, creation.hour)
        : '';
    const creationActions = creation ? createActions(creationStart) : [];
    const entryActions = eventMenu ? actionsFor(eventMenu.entry) : [];
    const context = {
        colorBy: 'source' as const,
        density,
        srcByKey: Object.fromEntries(
            SOURCES.map((source) => [source.key, source]),
        ),
        onSelect,
        onContext: (event: React.MouseEvent, date: Date, hour?: number) => {
            event.preventDefault();
            rightClick.current = null;
            setCreation({ x: event.clientX, y: event.clientY, date, hour });
        },
        onCreateAt: summary?.can.request
            ? (date: Date, hour = 9) => {
                  const start = startFor(date, hour);
                  if (start < nowLocal) return;
                  setDialog({
                      kind: 'booking',
                      mode: { kind: 'request', startLocal: start },
                  });
              }
            : undefined,
        onMore: (date: Date) => {
            setNavDate(date);
            setView('day');
        },
    };
    const restriction = summary?.restriction ?? null;
    const readinessLabel = restriction
        ? 'Restricted'
        : (summary?.readiness_label ?? '…');

    return (
        <div
            className="vehicle-calendar"
            onContextMenuCapture={(event) => {
                rightClick.current = { x: event.clientX, y: event.clientY };
            }}
            onKeyDownCapture={() => {
                rightClick.current = null;
            }}
            onPointerDownCapture={(event) => {
                rightClick.current =
                    event.button === 2
                        ? { x: event.clientX, y: event.clientY }
                        : null;
                // Focus the entry first so closing its dialog restores focus.
                const target = (
                    event.target as HTMLElement
                ).closest<HTMLElement>('[role="button"][tabindex="0"]');
                target?.focus({ preventScroll: true });
            }}
        >
            {eventMenu && (
                <DropdownMenu
                    open
                    onOpenChange={(open) => {
                        if (!open) {
                            setEventMenu(null);
                            rightClick.current = null;
                        }
                    }}
                >
                    <DropdownMenuTrigger
                        style={{
                            position: 'fixed',
                            left: eventMenu.x,
                            top: eventMenu.y,
                            width: 1,
                            height: 1,
                        }}
                        aria-label="Calendar entry actions"
                    />
                    <DropdownMenuContent align="start">
                        <DropdownMenuLabel>
                            {eventMenu.entry.title}
                        </DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        {eventMenu.entry.kind !== 'busy' && (
                            <DropdownMenuItem
                                onSelect={() => {
                                    openEntry(eventMenu.entry);
                                    setEventMenu(null);
                                }}
                            >
                                <FileText className="size-[15px]" />
                                {eventMenu.entry.kind === 'restriction'
                                    ? 'View restriction reason'
                                    : ['appointment', 'estimate'].includes(
                                            eventMenu.entry.kind,
                                        )
                                      ? 'Open work order'
                                      : 'Open source record'}
                            </DropdownMenuItem>
                        )}
                        {entryActions
                            .filter((action) => !action.destructive)
                            .map((action) => (
                                <DropdownMenuItem
                                    key={action.label}
                                    disabled={action.disabled}
                                    onSelect={() => {
                                        action.run();
                                        setEventMenu(null);
                                    }}
                                >
                                    <action.icon className="size-[15px]" />
                                    <span>
                                        {action.label}
                                        {action.reason && (
                                            <small className="block text-xs text-muted-foreground">
                                                {action.reason}
                                            </small>
                                        )}
                                    </span>
                                </DropdownMenuItem>
                            ))}
                        {entryActions.some((action) => action.destructive) && (
                            <DropdownMenuSeparator />
                        )}
                        {entryActions
                            .filter((action) => action.destructive)
                            .map((action) => (
                                <DropdownMenuItem
                                    key={action.label}
                                    disabled={action.disabled}
                                    onSelect={() => {
                                        setEventMenu(null);
                                        action.run();
                                    }}
                                >
                                    <action.icon className="size-[15px]" />
                                    {action.label}
                                </DropdownMenuItem>
                            ))}
                        {eventMenu.entry.kind === 'busy' && (
                            <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">
                                Booking details are restricted.
                            </DropdownMenuLabel>
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
            )}
            {creation && (
                <DropdownMenu
                    open
                    onOpenChange={(open) => !open && setCreation(null)}
                >
                    <DropdownMenuTrigger
                        style={{
                            position: 'fixed',
                            left: creation.x,
                            top: creation.y,
                            width: 1,
                            height: 1,
                        }}
                        aria-label="Calendar creation menu"
                    />
                    <DropdownMenuContent align="start">
                        <DropdownMenuLabel>
                            {fullDate(creation.date)} · {vehicle.name}
                            <span className="block text-xs font-normal text-muted-foreground">
                                {creationStart.slice(11)} · Pacific/Auckland
                            </span>
                        </DropdownMenuLabel>
                        {creationActions.length > 0 && (
                            <>
                                <DropdownMenuSeparator />
                                {creationStart < nowLocal && (
                                    <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">
                                        Past time · choose a future slot to
                                        schedule
                                    </DropdownMenuLabel>
                                )}
                                {creationActions.map((action) => (
                                    <DropdownMenuItem
                                        key={action.label}
                                        disabled={action.disabled}
                                        onSelect={() => {
                                            setCreation(null);
                                            action.run();
                                        }}
                                    >
                                        <action.icon className="size-[15px]" />
                                        {action.label}
                                    </DropdownMenuItem>
                                ))}
                            </>
                        )}
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            onSelect={() => {
                                setNavDate(creation.date);
                                setView('day');
                                setCreation(null);
                            }}
                        >
                            <CalendarDays className="size-[15px]" />
                            View this day / availability
                            <ArrowUpRight className="size-[13px]" />
                        </DropdownMenuItem>
                        {dateKey(creation.date) <= dateKey(today) && (
                            <DropdownMenuItem
                                onSelect={() => {
                                    onNavigate({
                                        tab: 'trips',
                                        date: dateKey(creation.date),
                                    });
                                    setCreation(null);
                                }}
                            >
                                <Route className="size-[15px]" />
                                View trips on this date
                            </DropdownMenuItem>
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
            )}
            <PageHeader
                variant="profile"
                wrapTitle
                mark={
                    <div className="identity-mark">
                        {/* eslint-disable-next-line no-restricted-syntax -- The design's glass back chip beside the calendar ring. */}
                        <button
                            type="button"
                            className="hero-back"
                            aria-label="Back to vehicle"
                            onClick={onBack}
                        >
                            <ArrowLeft className="size-4" />
                        </button>
                        <div className="eh-mark-ring">
                            <CalendarDays className="size-[25px]" />
                        </div>
                    </div>
                }
                title="Vehicle calendar"
                titleChip={
                    <PageHeaderStatusChip variant="info">
                        {vehicle.name}
                    </PageHeaderStatusChip>
                }
                subline={[
                    vehicle.registration_number,
                    vehicle.asset_tag,
                    'Pacific/Auckland',
                ]
                    .filter(Boolean)
                    .join(' · ')}
                actions={
                    <>
                        {summary?.can.request && (
                            <PageHeaderGlassButton
                                onClick={() =>
                                    setDialog({
                                        kind: 'booking',
                                        mode: {
                                            kind: 'request',
                                            startLocal: defaultBookingStart(
                                                dateKey(navDate),
                                            ),
                                        },
                                    })
                                }
                            >
                                Request booking
                            </PageHeaderGlassButton>
                        )}
                        <PageHeaderSearch
                            value={query}
                            onChange={setQuery}
                            placeholder="Find an entry or reference…"
                        />
                        <PageHeaderGlassButton onClick={onBack}>
                            <ArrowLeft className="size-4" />
                            Vehicle profile
                        </PageHeaderGlassButton>
                    </>
                }
                meters={
                    <div className="meter-grid">
                        <PageHeaderMeterBlock
                            label="Viewing"
                            value={`${periodCount} ${periodCount === 1 ? 'entry' : 'entries'}`}
                            ariaLabel="View this period in the agenda"
                            onClick={() => setView('agenda')}
                        >
                            <div
                                className="calendar-date-anchor"
                                aria-live="polite"
                                aria-atomic="true"
                            >
                                <PageHeaderMeterBig>
                                    {navDate.getDate()}
                                </PageHeaderMeterBig>
                                <div>
                                    <strong>{MO[navDate.getMonth()]}</strong>
                                    <span>{navDate.getFullYear()}</span>
                                </div>
                            </div>
                            <PageHeaderMeterCaption>
                                {view === 'week'
                                    ? `${fullDate(weekStart)} – ${fullDate(weekEnd)}`
                                    : fullDate(navDate)}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Vehicle use"
                            ariaLabel="Review vehicle readiness"
                            onClick={onBack}
                            tone={restriction ? 'critical' : 'brand'}
                        >
                            <PageHeaderMeterBig>
                                {readinessLabel}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {restriction
                                    ? 'Active · no release recorded'
                                    : readinessLabel === 'Ready'
                                      ? 'Booking and checkout checks pass'
                                      : 'Booking and checkout still need assessment'}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Next appointment"
                            ariaLabel="View the next service appointment"
                            onClick={() => {
                                if (summary?.next_appointment)
                                    setNavDate(
                                        new Date(
                                            summary.next_appointment.start,
                                        ),
                                    );
                                setView('day');
                            }}
                        >
                            <PageHeaderMeterBig>
                                {summary?.next_appointment
                                    ? shortDate(summary.next_appointment.start)
                                    : 'None'}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {summary?.next_appointment?.title ??
                                    'No appointment planned'}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Due reminder"
                            ariaLabel="View the next due reminder"
                            onClick={() => {
                                if (summary?.next_due)
                                    setNavDate(
                                        new Date(summary.next_due.start),
                                    );
                                setView('day');
                            }}
                        >
                            <PageHeaderMeterBig>
                                {summary?.next_due
                                    ? shortDate(summary.next_due.start)
                                    : 'None'}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {summary?.next_due
                                    ? `${summary.next_due.title.replace(/ · reminder$/, '')} · does not reserve a day`
                                    : 'No due reminders'}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    </div>
                }
                filters={
                    <div className="calendar-controls">
                        <div className="calendar-period">
                            <PageHeaderGlassButton
                                aria-label="Previous period"
                                onClick={() => shift(-1)}
                            >
                                <ChevronLeft className="size-[17px]" />
                            </PageHeaderGlassButton>
                            <PageHeaderGlassButton
                                onClick={() => setNavDate(new Date())}
                            >
                                Today
                            </PageHeaderGlassButton>
                            <PageHeaderGlassButton
                                aria-label="Next period"
                                onClick={() => shift(1)}
                            >
                                <ChevronRight className="size-[17px]" />
                            </PageHeaderGlassButton>
                            <Popover open={jumpOpen} onOpenChange={setJumpOpen}>
                                <PopoverTrigger asChild>
                                    <PageHeaderGlassButton aria-label="Jump to date">
                                        <CalendarDays className="size-4" />
                                        {period}
                                    </PageHeaderGlassButton>
                                </PopoverTrigger>
                                <PopoverContent
                                    className="w-auto p-2"
                                    align="start"
                                >
                                    <MiniMonth
                                        selected={navDate}
                                        onSelect={(date) => {
                                            setNavDate(date);
                                            setJumpOpen(false);
                                        }}
                                    />
                                </PopoverContent>
                            </Popover>
                        </div>
                        <label className="calendar-density">
                            Display
                            <select
                                aria-label="Calendar display density"
                                value={density}
                                onChange={(event) =>
                                    setDensity(event.target.value as Density)
                                }
                            >
                                <option value="comfortable">Comfortable</option>
                                <option value="compact">Compact</option>
                            </select>
                        </label>
                    </div>
                }
                rail={
                    <PageHeaderRail
                        items={VIEW_ITEMS}
                        value={view}
                        onSelect={(key) => setView(key)}
                        ariaLabel="Calendar views"
                    />
                }
            />
            <div className="calendar-source-bar" aria-label="Calendar sources">
                {SOURCES.map((source) => (
                    // eslint-disable-next-line no-restricted-syntax -- Source filter pill from the approved calendar design.
                    <button
                        key={source.key}
                        type="button"
                        aria-pressed={enabled.includes(source.key)}
                        className="calendar-source-pill"
                        onClick={() =>
                            setEnabled((list) =>
                                list.includes(source.key)
                                    ? list.filter((key) => key !== source.key)
                                    : [...list, source.key],
                            )
                        }
                    >
                        <span
                            style={{ background: `var(--src-${source.key})` }}
                        />
                        {source.label}
                        <small>
                            {
                                feed.items.filter(
                                    (entry) => entry.source === source.key,
                                ).length
                            }
                        </small>
                    </button>
                ))}
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => {
                        setEnabled(SOURCE_KEYS);
                        setQuery('');
                    }}
                >
                    Reset filters
                </Button>
            </div>
            {restriction && (
                <div className="calendar-restriction" role="status">
                    <ShieldAlert className="size-[19px]" aria-hidden />
                    <div>
                        <strong>Vehicle use remains restricted</strong>
                        <span>
                            Since {formatDateTime(restriction.started_at)} · No
                            end or authorised release recorded. Calendar gaps do
                            not mean available.
                        </span>
                    </div>
                    <Button
                        variant="outline"
                        onClick={() => openWork(restriction.work_order_id)}
                    >
                        View restriction
                    </Button>
                </div>
            )}
            {(feed.state === 'failed' || summaryFailed) && (
                <p role="alert" className="mb-3 text-sm text-status-warning">
                    Some calendar entries could not be loaded. Reload the page
                    to try again.
                </p>
            )}
            <CalendarUIProvider value={context}>
                <div className="calendar-layout">
                    <div
                        className="calendar-view"
                        aria-busy={feed.state === 'loading'}
                    >
                        {view === 'month' && (
                            <MonthView events={visible} navDate={navDate} />
                        )}
                        {view === 'week' && (
                            <WeekView events={visible} navDate={navDate} />
                        )}
                        {view === 'day' && (
                            <DayView events={visible} navDate={navDate} />
                        )}
                        {view === 'agenda' && (
                            <AgendaView
                                events={visible}
                                navDate={navDate}
                                sourcesOff={!enabled.length}
                                filtersActive={
                                    enabled.length !== SOURCES.length || !!query
                                }
                            />
                        )}
                        {view === 'timeline' && (
                            <TimelineView
                                events={visible}
                                navDate={navDate}
                                sources={SOURCES}
                            />
                        )}
                    </div>
                    <TodayRail
                        events={railVisible}
                        today={today}
                        onSelect={onSelect}
                        onApprovals={() => setView('agenda')}
                        onJumpToday={() => setNavDate(new Date())}
                        viewingToday={sameDay(today, navDate)}
                    />
                </div>
            </CalendarUIProvider>
            <div className="calendar-footnote">
                <Lock className="size-[14px]" aria-hidden />
                <span>
                    Right-click a date/time for scheduling and reminders, or an
                    entry for its actions. Select an entry to open its source.
                    Reminders and advisory estimates do not reserve the vehicle.
                </span>
            </div>
            <div className="mt-5">
                <BookingsStudio
                    summary={summary}
                    onRequest={() =>
                        setDialog({
                            kind: 'booking',
                            mode: { kind: 'request' },
                        })
                    }
                    onBlock={() =>
                        setDialog({ kind: 'booking', mode: { kind: 'block' } })
                    }
                    onDecision={(row, decision) =>
                        setDialog({ kind: 'decision', row, decision })
                    }
                    onChangeTimes={(row) =>
                        setDialog({
                            kind: 'booking',
                            mode:
                                row.kind === 'booking'
                                    ? { kind: 'change', row }
                                    : { kind: 'change-block', row },
                        })
                    }
                    onRecord={custodyRecord}
                    onUpload={(row) =>
                        setDialog({
                            kind: 'custody-evidence',
                            source:
                                row.kind === 'booking'
                                    ? 'booking'
                                    : 'unavailable_period',
                            id: row.id,
                            label:
                                row.kind === 'booking'
                                    ? (row.reference ?? `Booking #${row.id}`)
                                    : 'Unavailable period',
                        })
                    }
                />
            </div>

            {dialog?.kind === 'booking' && summary && (
                <BookingWizard
                    vehicle={vehicle}
                    summary={summary}
                    mode={dialog.mode}
                    onClose={() => setDialog(null)}
                    onSaved={changed}
                />
            )}
            {dialog?.kind === 'decision' && summary && (
                <BookingDecisionWizard
                    workspace={workspace}
                    summary={summary}
                    row={dialog.row}
                    decision={dialog.decision}
                    onClose={() => setDialog(null)}
                    onSaved={changed}
                />
            )}
            {dialog?.kind === 'appointment' && summary && (
                <AppointmentWizard
                    vehicle={vehicle}
                    summary={summary}
                    startLocal={dialog.startLocal}
                    workOrderId={dialog.workOrderId}
                    appointment={dialog.appointment}
                    presetType={dialog.presetType}
                    onClose={() => setDialog(null)}
                    onSaved={changed}
                />
            )}
            {dialog?.kind === 'reminder' && (
                <ReminderDialog
                    workspace={workspace}
                    reminder={dialog.reminder}
                    presetSource={dialog.presetSource}
                    presetDueLocal={dialog.presetDueLocal}
                    onClose={() => setDialog(null)}
                    onSaved={changed}
                />
            )}
            {dialog?.kind === 'reminder-action' && (
                <ReminderActionDialog
                    vehicleId={vehicle.id}
                    reminder={dialog.reminder}
                    action={dialog.action}
                    onClose={() => setDialog(null)}
                    onSaved={changed}
                />
            )}
            {dialog?.kind === 'snooze' && (
                <SnoozeReminderDialog
                    vehicleId={vehicle.id}
                    reminder={dialog.reminder}
                    onClose={() => setDialog(null)}
                    onSaved={changed}
                />
            )}
            <ReminderActivityDialog
                reminder={
                    dialog?.kind === 'reminder-activity'
                        ? dialog.reminder
                        : null
                }
                onClose={() => setDialog(null)}
            />
            {dialog?.kind === 'record' && (
                <SourceRecordDialog
                    title={dialog.title}
                    description={`${[vehicle.asset_tag, vehicle.name].filter(Boolean).join(' · ')} · Source record`}
                    rows={dialog.rows}
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog?.kind === 'work-evidence' && (
                <WorkEvidenceDialog
                    workOrderId={dialog.workOrderId}
                    workLabel={dialog.label}
                    onClose={() => setDialog(null)}
                    onSaved={changed}
                />
            )}
            {dialog?.kind === 'obligation-history' && (
                <ObligationHistoryDialog
                    vehicle={vehicle}
                    reminder={dialog.reminder}
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog?.kind === 'obligation-action' && (
                <ObligationActionDialog
                    vehicleId={vehicle.id}
                    reminder={dialog.reminder}
                    action={dialog.action}
                    onClose={() => setDialog(null)}
                    onSaved={changed}
                />
            )}
            {dialog?.kind === 'custody-evidence' && (
                <AddEvidenceDialog
                    vehicle={vehicle}
                    title={`Upload evidence · ${dialog.label}`}
                    category={
                        dialog.source === 'booking'
                            ? 'Booking evidence'
                            : 'Unavailable period evidence'
                    }
                    sourceType={dialog.source}
                    sourceId={dialog.id}
                    onClose={() => setDialog(null)}
                    onSaved={changed}
                />
            )}
        </div>
    );
}

/** Rows for a booking or unavailable period's record & history. */
export function custodyRows(row: CustodyRow): Array<[string, string]> {
    const history = row.history
        .map(
            (entry) =>
                `${formatDateTime(entry.at)} · ${entry.label}${entry.actor ? ` by ${entry.actor}` : ''}${entry.reason ? `: ${entry.reason}` : ''}`,
        )
        .join('\n');
    if (row.kind === 'unavailable') {
        return [
            ['Status', row.status_label],
            [
                'Period',
                `${formatDateTime(row.starts_at)} – ${formatDateTime(row.ends_at)}`,
            ],
            ['Reason', row.purpose],
            ...(row.reference
                ? ([['Linked work', row.reference]] as Array<[string, string]>)
                : []),
            [
                'Evidence',
                row.files.map((file) => file.name).join(', ') || 'No files',
            ],
            ...(row.cancellation_reason
                ? ([['Cancellation reason', row.cancellation_reason]] as Array<
                      [string, string]
                  >)
                : []),
            ['History', history || 'No history recorded'],
        ];
    }
    return [
        ['Status', row.status_label],
        [
            'Pickup → return',
            `${formatDateTime(row.starts_at)} → ${formatDateTime(row.ends_at)}`,
        ],
        ['Requester', row.requester?.name ?? 'Not recorded'],
        ['Driver', row.driver?.name ?? 'Not recorded'],
        [
            'Approval route',
            row.approval_route === 'not_required'
                ? 'Approval not required'
                : 'Approval required',
        ],
        [
            'Reason / authority',
            row.approval_not_required_reason ?? 'Coordinator review',
        ],
        [
            'Evidence',
            row.files.map((file) => file.name).join(', ') ||
                row.approval_not_required_evidence ||
                'No files',
        ],
        ['Pickup & keys', row.pickup_arrangement ?? 'Not recorded'],
        [
            'Condition',
            [
                row.checkout_condition
                    ? `Out: ${row.checkout_condition}`
                    : null,
                row.condition_on_return
                    ? `Return: ${row.condition_on_return}`
                    : null,
            ]
                .filter(Boolean)
                .join(' · ') || 'Not recorded',
        ],
        [
            'Keys',
            row.keys
                .map(
                    (log) =>
                        `${log.action === 'returned' ? 'Returned' : 'Handed to'} ${log.holder ?? 'driver'}${log.at ? ` · ${formatDateTime(log.at)}` : ''}`,
                )
                .join('\n') || 'No key handover recorded',
        ],
        ...(row.rejection_reason
            ? ([['Decline reason', row.rejection_reason]] as Array<
                  [string, string]
              >)
            : []),
        ...(row.cancellation_reason
            ? ([['Cancellation reason', row.cancellation_reason]] as Array<
                  [string, string]
              >)
            : []),
        ['History', history || 'No history recorded'],
    ];
}
