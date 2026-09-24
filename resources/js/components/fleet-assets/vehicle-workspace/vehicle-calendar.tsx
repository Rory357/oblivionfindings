import { PageLayout } from '@/components/page';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { formatDateTime, toDatetimeLocal } from '@/lib/datetime';
import {
    AgendaView,
    CalendarContextMenu,
    CalendarSourcePills,
    CalendarUIProvider,
    DayView,
    JumpToDate,
    MO,
    MonthView,
    TimelineView,
    TodayRail,
    WeekView,
    addDays,
    decorate,
    fmtTimeRange,
    periodLabel,
    sameDay,
    startOfWeek,
    useNow,
    type CalView,
    type CalendarMenuItem,
    type CalendarMenuSection,
    type Decorated,
    type Density,
    type SourceDef,
} from '@/pages/sites/calendar/_parts';
import {
    AlertTriangle,
    ArrowLeft,
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    Clock,
    Columns3,
    FileText,
    LayoutGrid,
    List,
    Lock,
    Plus,
    RefreshCw,
    Rows3,
    ShieldAlert,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
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
    UnavailableRow,
    VehicleCalendarItem,
    VehicleCalendarSummary,
} from './calendar-types';
import {
    ObligationActionDialog,
    ObligationHistoryDialog,
} from './obligation-reminders';
import { DocumentEditDialog } from './overview-documents';
import { fetchVehicleRecord, sendVehicleRecord } from './record-command';
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
    DocumentSet,
    ObligationReminder,
    VehicleReminder,
    VehicleWorkspace,
} from './types';
import {
    creationActions,
    dayNavigationActions,
    entryActions,
    openEntryLabel,
    type CalendarAction,
    type CalendarIntent,
    type LinkedSource,
} from './vehicle-calendar-actions';
import { WorkEvidenceDialog } from './work-evidence-dialog';
import {
    isComplianceKind,
    reasonDestination,
    type WorkspaceLocation,
} from './workspace-model';

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
          source?: LinkedSource;
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
    | {
          kind: 'record';
          title: string;
          rows: Array<[string, string]>;
          action?: { label: string; onClick: () => void };
      }
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
      }
    | { kind: 'document'; set: DocumentSet };

/** A right-click (or tap, or New entry) menu, placed at a point with focus returning to its opener. */
type MenuState =
    | {
          kind: 'create';
          x: number;
          y: number;
          date: Date;
          hour?: number;
          opener: HTMLElement | null;
      }
    | {
          kind: 'entry';
          x: number;
          y: number;
          entry: Entry;
          row: CustodyRow | null;
          rowState: 'ready' | 'loading' | 'missing';
          opener: HTMLElement | null;
      };

/** What Undo puts back after a reversible calendar change. */
type UndoPlan =
    | { kind: 'restore-period'; row: UnavailableRow }
    | { kind: 'revert-period'; row: UnavailableRow };

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

const ENTRY_CHIPS: Record<VehicleCalendarItem['kind'], string> = {
    restriction: 'Restriction',
    appointment: 'Appointment',
    estimate: 'Estimate',
    booking: 'Booking',
    busy: 'Busy',
    unavailable: 'Unavailable',
    schedule: 'Service due',
    compliance: 'Compliance due',
    check: 'Check due',
    reminder: 'Reminder',
};

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
const startOfDay = (date: Date) =>
    new Date(date.getFullYear(), date.getMonth(), date.getDate());

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

/**
 * Where a menu opens: the pointer, or (for Shift+F10 / the Menu key, which
 * report no pointer position, and for taps) just under the element.
 */
function menuPoint(event?: {
    clientX: number;
    clientY: number;
    currentTarget: EventTarget | null;
}): { x: number; y: number; opener: HTMLElement | null } {
    const element =
        event?.currentTarget instanceof HTMLElement
            ? event.currentTarget
            : document.activeElement instanceof HTMLElement
              ? document.activeElement
              : null;
    if (event && (event.clientX !== 0 || event.clientY !== 0))
        return { x: event.clientX, y: event.clientY, opener: element };
    const rect = element?.getBoundingClientRect();
    return {
        x: rect ? rect.left + 8 : window.innerWidth / 2,
        y: rect ? rect.bottom - 4 : window.innerHeight / 3,
        opener: element,
    };
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
    const now = useNow();
    const today = now;
    const nowLocal = toDatetimeLocal(now.toISOString());
    const [menu, setMenu] = useState<MenuState | null>(null);
    const [view, setView] = useState<CalView>('month');
    const [navDate, setNavDate] = useState(() =>
        focusDate ? new Date(`${focusDate}T12:00`) : new Date(),
    );
    const [query, setQuery] = useState('');
    const [density, setDensity] = useState<Density>('comfortable');
    const [enabled, setEnabled] = useState<Set<string>>(
        () => new Set(SOURCE_KEYS),
    );
    const [dialog, setDialog] = useState<DialogState | null>(null);
    const [version, setVersion] = useState(0);
    const [summary, setSummary] = useState<VehicleCalendarSummary | null>(null);
    const [summaryState, setSummaryState] = useState<
        'loading' | 'ready' | 'failed'
    >('loading');
    // Taps open an entry's actions (touch has no right-click).
    const lastPointer = useRef<string | null>(null);
    const undoPlan = useRef<UndoPlan | null>(null);

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
    // Today's rail keeps the Site Calendar's window (45 days back, 30 ahead),
    // anchored to today whatever period is browsed.
    const todayKey = dateKey(today);
    const railStart = useMemo(
        () => addDays(startOfDay(new Date(`${todayKey}T12:00`)), -45),
        [todayKey],
    );
    const railEnd = useMemo(() => addDays(railStart, 76), [railStart]);
    const rail = useCalendarFeed(vehicle.id, railStart, railEnd, version);

    const loadSummary = useCallback(() => {
        setSummaryState('loading');
        fetch(`/fleet-assets/vehicles/${vehicle.id}/calendar/summary`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then(async (response) => {
                if (!response.ok) throw new Error(String(response.status));
                setSummary((await response.json()) as VehicleCalendarSummary);
                setSummaryState('ready');
            })
            .catch(() => setSummaryState('failed'));
    }, [vehicle.id]);
    useEffect(() => {
        loadSummary();
    }, [loadSummary, version]);

    const reload = () => setVersion((value) => value + 1);
    const changed = () => {
        reload();
        onChanged();
    };

    const searched = (entry: Entry) =>
        `${entry.title} ${entry.ref ?? ''}`
            .toLowerCase()
            .includes(query.toLowerCase());
    const visible = feed.items.filter(
        (entry) => enabled.has(entry.source) && searched(entry),
    );
    const railVisible = rail.items.filter(
        (entry) =>
            enabled.has(entry.source) &&
            searched(entry) &&
            !['Returned', 'Completed', 'Released'].includes(
                entry.statusLabel ?? '',
            ),
    );
    const weekStart = startOfWeek(navDate);
    const weekEnd = addDays(weekStart, 6);
    const periodStart =
        view === 'week'
            ? weekStart
            : view === 'day'
              ? startOfDay(navDate)
              : new Date(navDate.getFullYear(), navDate.getMonth(), 1);
    const periodEnd =
        view === 'week'
            ? addDays(weekStart, 7)
            : view === 'day'
              ? addDays(periodStart, 1)
              : new Date(navDate.getFullYear(), navDate.getMonth() + 1, 1);
    const inPeriod = (entry: Entry) =>
        entry._start < periodEnd &&
        (entry._end ? entry._end > periodStart : entry._start >= periodStart);
    const periodCount = visible.filter(inPeriod).length;
    // Source pill counts describe the period being viewed.
    const sourceCounts = Object.fromEntries(
        SOURCES.map((source) => [
            source.key,
            feed.items.filter(
                (entry) => entry.source === source.key && inPeriod(entry),
            ).length,
        ]),
    );
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

    const reminderFor = (entry: Entry) =>
        workspace.reminders.find((reminder) => reminder.id === entry.recordId);
    const rowFor = (entry: Entry): CustodyRow | undefined =>
        summary?.bookings.find(
            (row) =>
                row.id === entry.recordId &&
                row.kind ===
                    (entry.kind === 'unavailable' ? 'unavailable' : 'booking'),
        );
    /** A booking or period outside the summary's recent list is loaded on demand. */
    const loadRow = useCallback(
        async (entry: Entry): Promise<CustodyRow | null> => {
            if (!entry.recordId) return null;
            const kind =
                entry.kind === 'unavailable' ? 'unavailable' : 'booking';
            const result = await fetchVehicleRecord(
                `/fleet-assets/vehicles/${vehicle.id}/calendar/records/${kind}/${entry.recordId}`,
            ).catch(() => null);
            return result && result.row ? (result.row as CustodyRow) : null;
        },
        [vehicle.id],
    );
    const openWork = (workOrderId: number | null, release = false) => {
        if (workOrderId)
            openWorkOrder(
                workOrderId,
                vehicle.id,
                { tab: 'calendar' },
                release,
            );
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
    /** The restriction record, shown in place (the mockup's "Restriction record"). */
    const restrictionRecord = (entry?: Entry) => {
        const restriction = summary?.restriction ?? null;
        const meta = entry?.meta ?? null;
        const active = entry ? entry.status === 'overdue' : !!restriction;
        const check = meta?.source_check ?? restriction?.source_check ?? null;
        setDialog({
            kind: 'record',
            title: 'Restriction record',
            rows: [
                [
                    'Reference',
                    meta?.work_reference ??
                        entry?.ref ??
                        restriction?.work_reference ??
                        'Not recorded',
                ],
                [
                    'Started',
                    entry?.start
                        ? formatDateTime(entry.start)
                        : restriction
                          ? formatDateTime(restriction.started_at)
                          : 'Not recorded',
                ],
                [
                    'End',
                    active
                        ? 'No release recorded'
                        : entry?.end
                          ? formatDateTime(entry.end)
                          : 'Released',
                ],
                ['Owner', meta?.owner ?? restriction?.owner ?? 'Not assigned'],
                [
                    'Source check',
                    check
                        ? `${check.label}${check.outcome ? ` · ${check.outcome}` : ''}`
                        : 'Not linked to a check',
                ],
                [
                    'Maintenance status',
                    meta?.work_status ??
                        restriction?.work_status ??
                        'Not recorded',
                ],
                ...(entry?.desc
                    ? ([['Reason', entry.desc]] as Array<[string, string]>)
                    : []),
            ],
            // The check that raised the hold opens in Checks & inspections.
            action: check
                ? {
                      label: 'Open check',
                      onClick: () => {
                          setDialog(null);
                          onNavigate({
                              tab: 'checks',
                              view: 'recent',
                              run: check.id,
                          });
                      },
                  }
                : undefined,
        });
    };
    const custodyRecord = (row: CustodyRow) =>
        setDialog({
            kind: 'record',
            title:
                row.kind === 'booking'
                    ? `Booking ${row.reference ?? `#${row.id}`}`
                    : 'Unavailable period',
            rows: custodyRows(row),
        });

    const openEntry = async (entry: Entry) => {
        switch (entry.kind) {
            case 'restriction':
                restrictionRecord(entry);
                return;
            case 'appointment':
            case 'estimate':
                openWork(entry.workOrderId);
                return;
            case 'booking':
            case 'unavailable': {
                const row = rowFor(entry) ?? (await loadRow(entry));
                if (row) custodyRecord(row);
                else
                    setDialog({
                        kind: 'record',
                        title:
                            entry.kind === 'booking'
                                ? 'Booking'
                                : 'Unavailable period',
                        rows: [
                            [
                                'Period',
                                `${formatDateTime(entry.start ?? '')} – ${formatDateTime(entry.end ?? '')}`,
                            ],
                            ['Status', entry.statusLabel],
                            [
                                'Record',
                                'The full record isn’t available to you.',
                            ],
                        ],
                    });
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
                        // A booking or unavailable period at another Site.
                        [
                            'Details',
                            'Shown to people who can book this vehicle at its Site',
                        ],
                    ],
                });
                return;
            case 'schedule':
                onNavigate({ tab: 'service', view: 'schedules' });
                return;
            case 'compliance': {
                // Entries are "compliance:<kind>": open that requirement's row.
                const kind = String(entry.id).split(':')[1];
                onNavigate({
                    tab: 'service',
                    view: 'evidence',
                    ...(isComplianceKind(kind) ? { focus: kind } : {}),
                });
                return;
            }
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

    const openEntryMenu = (
        entry: Entry,
        event?: {
            clientX: number;
            clientY: number;
            currentTarget: EventTarget | null;
        },
    ) => {
        const point = menuPoint(event);
        const known = rowFor(entry) ?? null;
        const needsRow =
            (entry.kind === 'booking' || entry.kind === 'unavailable') &&
            !known &&
            !!summary;
        setMenu({
            kind: 'entry',
            ...point,
            entry,
            row: known,
            rowState: needsRow ? 'loading' : known ? 'ready' : 'missing',
        });
        if (needsRow) {
            void loadRow(entry).then((row) =>
                setMenu((current) =>
                    current?.kind === 'entry' && current.entry.id === entry.id
                        ? {
                              ...current,
                              row,
                              rowState: row ? 'ready' : 'missing',
                          }
                        : current,
                ),
            );
        }
    };
    const openCreateMenu = (
        date: Date,
        hour: number | undefined,
        event?: {
            clientX: number;
            clientY: number;
            currentTarget: EventTarget | null;
        },
    ) => {
        setMenu({ kind: 'create', ...menuPoint(event), date, hour });
    };

    const startFor = (date: Date, hour?: number) => {
        if (hour === undefined) {
            if (sameDay(date, today)) return defaultBookingStart();
            return `${dateKey(date)}T09:00`;
        }
        return `${dateKey(date)}T${String(Math.floor(hour)).padStart(2, '0')}:${String(Math.round((hour % 1) * 60)).padStart(2, '0')}`;
    };

    /** The appointment already planned on a work order, from the loaded entries. */
    const plannedFor = (
        workOrderId: number,
    ): PlannedAppointment | undefined => {
        const planned = [...feed.items, ...rail.items].find(
            (item) =>
                item.kind === 'appointment' &&
                item.workOrderId === workOrderId &&
                item.meta?.open,
        );
        return planned?.start
            ? {
                  start: planned.start,
                  end: planned.end ?? null,
                  provider: planned.meta?.provider ?? null,
                  unavailable: planned.meta?.unavailable,
              }
            : undefined;
    };

    const tripsVisible = workspace.can.view_site_records;
    const perform = (intent: CalendarIntent, entry?: Entry, day?: Date) => {
        switch (intent.type) {
            case 'open-entry':
                if (entry) void openEntry(entry);
                return;
            case 'retry-summary':
                loadSummary();
                return;
            case 'request-booking':
                setDialog({
                    kind: 'booking',
                    mode: { kind: 'request', startLocal: intent.startLocal },
                });
                return;
            case 'schedule-service':
                setDialog({
                    kind: 'appointment',
                    startLocal: intent.startLocal,
                });
                return;
            case 'add-reminder':
                setDialog({
                    kind: 'reminder',
                    reminder: null,
                    presetDueLocal: intent.dueLocal,
                });
                return;
            case 'mark-unavailable':
                setDialog({
                    kind: 'booking',
                    mode: { kind: 'block', startLocal: intent.startLocal },
                });
                return;
            case 'view-day':
                if (day) setNavDate(day);
                setView('day');
                return;
            case 'view-trips':
                if (day) onNavigate({ tab: 'trips', date: dateKey(day) });
                return;
            case 'edit-reminder':
                setDialog({ kind: 'reminder', reminder: intent.reminder });
                return;
            case 'edit-document': {
                const set = workspace.documents.find(
                    (item) => item.id === intent.setId,
                );
                if (set) setDialog({ kind: 'document', set });
                else onNavigate({ tab: 'overview', view: 'documents' });
                return;
            }
            case 'snooze-reminder':
                setDialog({ kind: 'snooze', reminder: intent.reminder });
                return;
            case 'complete-reminder':
                setDialog({
                    kind: 'reminder-action',
                    reminder: intent.reminder,
                    action: 'complete',
                });
                return;
            case 'open-documents':
                onNavigate({ tab: 'overview', view: 'documents' });
                return;
            case 'open-work':
                openWork(intent.workOrderId, intent.release);
                return;
            case 'reminder-activity':
                setDialog({
                    kind: 'reminder-activity',
                    reminder: intent.reminder,
                });
                return;
            case 'custody-record':
                custodyRecord(intent.row);
                return;
            case 'edit-booking':
                setDialog({
                    kind: 'booking',
                    mode: { kind: 'change', row: intent.row },
                });
                return;
            case 'change-block':
                undoPlan.current = { kind: 'revert-period', row: intent.row };
                setDialog({
                    kind: 'booking',
                    mode: { kind: 'change-block', row: intent.row },
                });
                return;
            case 'decide':
                undoPlan.current =
                    intent.decision === 'cancel' &&
                    intent.row.kind === 'unavailable'
                        ? { kind: 'restore-period', row: intent.row }
                        : null;
                setDialog({
                    kind: 'decision',
                    row: intent.row,
                    decision: intent.decision,
                });
                return;
            case 'custody-evidence':
                setDialog({
                    kind: 'custody-evidence',
                    source:
                        intent.row.kind === 'booking'
                            ? 'booking'
                            : 'unavailable_period',
                    id: intent.row.id,
                    label:
                        intent.row.kind === 'booking'
                            ? (intent.row.reference ??
                              `Booking #${intent.row.id}`)
                            : 'Unavailable period',
                });
                return;
            case 'manage-appointment':
                setDialog({
                    kind: 'appointment',
                    workOrderId: intent.workOrderId,
                    appointment:
                        intent.appointment ?? plannedFor(intent.workOrderId),
                    startLocal: intent.startLocal,
                });
                return;
            case 'upload-work-evidence':
                setDialog({
                    kind: 'work-evidence',
                    workOrderId: intent.workOrderId,
                    label: intent.label,
                });
                return;
            case 'release-requirements':
                releaseRequirements();
                return;
            case 'plan-linked':
                setDialog({
                    kind: 'appointment',
                    startLocal: intent.startLocal,
                    presetType: intent.presetType,
                    source: intent.source,
                });
                return;
            case 'add-follow-up':
                setDialog({
                    kind: 'reminder',
                    reminder: null,
                    presetSource: intent.source,
                });
                return;
            case 'obligation':
                setDialog({
                    kind: 'obligation-action',
                    reminder: intent.reminder,
                    action: intent.action,
                });
                return;
            case 'obligation-history':
                setDialog({
                    kind: 'obligation-history',
                    reminder: intent.reminder,
                });
                return;
        }
    };

    /** After a reversible change is saved, offer Undo for a short while. */
    const offerUndo = () => {
        const plan = undoPlan.current;
        undoPlan.current = null;
        if (!plan) return;
        const current = async (): Promise<UnavailableRow> => {
            const result = await fetchVehicleRecord(
                `/fleet-assets/vehicles/${vehicle.id}/calendar/records/unavailable/${plan.row.id}`,
            );
            if (!result?.row)
                throw new Error(
                    'This period is no longer available to change.',
                );
            return result.row as UnavailableRow;
        };
        const undo = async () => {
            const row = await current();
            if (plan.kind === 'restore-period') {
                await sendVehicleRecord(
                    `/fleet-assets/vehicles/${vehicle.id}/unavailable-periods/${row.id}/restore`,
                    { expected_version: row.lock_version },
                );
            } else {
                await sendVehicleRecord(
                    `/fleet-assets/vehicles/${vehicle.id}/unavailable-periods/${row.id}`,
                    {
                        starts_local: toDatetimeLocal(plan.row.starts_at),
                        ends_local: toDatetimeLocal(plan.row.ends_at),
                        reason: plan.row.purpose,
                        change_reason: 'Undo: previous times put back',
                        expected_version: row.lock_version,
                    },
                    'PUT',
                );
            }
        };
        toast.success(
            plan.kind === 'restore-period'
                ? 'Unavailable period cancelled'
                : 'Unavailable period changed',
            {
                duration: 10000,
                action: {
                    label: 'Undo',
                    onClick: () => {
                        undo()
                            .then(() => {
                                changed();
                                toast.success(
                                    plan.kind === 'restore-period'
                                        ? 'Unavailable period restored'
                                        : 'Previous times put back',
                                );
                            })
                            .catch((error: Error) =>
                                toast.error(error.message),
                            );
                    },
                },
            },
        );
    };

    const toMenuItems = (
        actions: CalendarAction[],
        onPick: (action: CalendarAction) => void,
        showReasons = true,
    ): CalendarMenuItem[] =>
        actions.map((action) => ({
            key: action.key,
            label: action.label,
            icon: action.icon,
            disabled: action.disabled,
            destructive: action.destructive,
            detail: showReasons ? action.reason : undefined,
            onSelect: () => onPick(action),
        }));

    const menuSections = (current: MenuState): CalendarMenuSection[] => {
        if (current.kind === 'create') {
            const start = startFor(current.date, current.hour);
            const past = start < nowLocal;
            const create = creationActions(start, nowLocal, summary);
            return [
                {
                    key: 'create',
                    note:
                        past && create.some((action) => action.disabled)
                            ? 'Past time · choose a future slot to schedule'
                            : undefined,
                    items: toMenuItems(
                        create,
                        (action) =>
                            perform(action.intent, undefined, current.date),
                        !past,
                    ),
                },
                {
                    key: 'navigate',
                    items: toMenuItems(
                        dayNavigationActions(
                            dateKey(current.date),
                            dateKey(today),
                            tripsVisible,
                        ),
                        (action) =>
                            perform(action.intent, undefined, current.date),
                    ),
                },
            ];
        }
        const { entry } = current;
        const actions = entryActions(entry, {
            summary,
            reminders: workspace.reminders,
            obligations: workspace.obligation_reminders,
            row: current.row,
            vehicleId: vehicle.id,
            planStart: futureStart,
        });
        const pick = (action: CalendarAction) => perform(action.intent, entry);
        return [
            {
                key: 'open',
                items: [
                    {
                        key: 'open',
                        label: openEntryLabel(entry.kind),
                        icon: FileText,
                        onSelect: () => void openEntry(entry),
                    },
                ],
            },
            {
                key: 'actions',
                note:
                    current.rowState === 'loading'
                        ? 'Loading this record’s actions…'
                        : entry.kind === 'busy'
                          ? 'Booking details are restricted.'
                          : undefined,
                items: toMenuItems(
                    actions.filter((action) => !action.destructive),
                    pick,
                ),
            },
            {
                key: 'destructive',
                items: toMenuItems(
                    actions.filter((action) => action.destructive),
                    pick,
                ),
            },
        ];
    };

    const context = {
        colorBy: 'source' as const,
        density,
        srcByKey: Object.fromEntries(
            SOURCES.map((source) => [source.key, source]),
        ),
        onSelect: (entry: Decorated) => {
            // A tap opens the entry's actions; a click opens its record.
            if (lastPointer.current === 'touch') {
                lastPointer.current = null;
                openEntryMenu(entry as Entry);
                return;
            }
            void openEntry(entry as Entry);
        },
        onEntryContext: (entry: Decorated, event: React.MouseEvent) =>
            openEntryMenu(entry as Entry, event),
        onContext: (event: React.MouseEvent, date: Date, hour?: number) => {
            event.preventDefault();
            openCreateMenu(date, hour, event);
        },
        // A blank slot requests a booking; when that isn't possible (a past
        // time, or no booking permission) it opens the creation menu instead.
        onCreateAt: (date: Date, hour = 9) => {
            const start = startFor(date, hour);
            if (summary?.can.request && start >= nowLocal) {
                setDialog({
                    kind: 'booking',
                    mode: { kind: 'request', startLocal: start },
                });
                return;
            }
            openCreateMenu(date, hour);
        },
        onMore: (date: Date) => {
            setNavDate(date);
            setView('day');
        },
    };
    const restriction = summary?.restriction ?? null;
    const feedFailed = feed.state === 'failed' || rail.state === 'failed';
    const readinessLabel = restriction
        ? 'Restricted'
        : summaryState === 'ready'
          ? (summary?.readiness_label ?? '—')
          : '—';
    const viewingToday = sameDay(today, navDate);

    const header = (
        <PageHeader
            className="max-md:[&_button]:min-h-[44px] max-md:[&_button]:min-w-[44px]"
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
                    <PageHeaderPrimaryButton
                        icon={Plus}
                        aria-haspopup="menu"
                        onClick={(event) =>
                            openCreateMenu(
                                sameDay(navDate, today) ? today : navDate,
                                undefined,
                                {
                                    clientX: 0,
                                    clientY: 0,
                                    currentTarget: event.currentTarget,
                                },
                            )
                        }
                    >
                        New entry
                    </PageHeaderPrimaryButton>
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
                        className="min-w-[220px]!"
                        value={
                            feedFailed
                                ? 'Incomplete schedule'
                                : `${periodCount} ${periodCount === 1 ? 'entry' : 'entries'}`
                        }
                        ariaLabel="View this period in the agenda"
                        onClick={() => setView('agenda')}
                    >
                        <div
                            className="flex items-center gap-3 [&_.eh-meter-big]:text-3xl"
                            aria-live="polite"
                            aria-atomic="true"
                            data-testid="calendar-date-anchor"
                        >
                            <PageHeaderMeterBig>
                                {navDate.getDate()}
                            </PageHeaderMeterBig>
                            <div className="flex min-w-0 flex-col leading-tight">
                                <span className="text-section-title text-primary-foreground!">
                                    {MO[navDate.getMonth()]}
                                </span>
                                <span className="text-sm font-semibold tabular-nums">
                                    {navDate.getFullYear()}
                                </span>
                            </div>
                        </div>
                        <PageHeaderMeterCaption>
                            {view === 'week'
                                ? periodLabel(view, navDate)
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
                            {summaryState !== 'ready'
                                ? summaryState === 'failed'
                                    ? 'Readiness couldn’t load'
                                    : 'Checking readiness…'
                                : restriction
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
                                    new Date(summary.next_appointment.start),
                                );
                            setView('day');
                        }}
                    >
                        <PageHeaderMeterBig>
                            {summaryState !== 'ready'
                                ? '—'
                                : summary?.next_appointment
                                  ? shortDate(summary.next_appointment.start)
                                  : 'None'}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {summaryState !== 'ready'
                                ? 'Schedule incomplete'
                                : (summary?.next_appointment?.title ??
                                  'No appointment planned')}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Due reminder"
                        ariaLabel="View the next due reminder"
                        onClick={() => {
                            if (summary?.next_due)
                                setNavDate(new Date(summary.next_due.start));
                            setView('day');
                        }}
                    >
                        <PageHeaderMeterBig>
                            {summaryState !== 'ready'
                                ? '—'
                                : summary?.next_due
                                  ? shortDate(summary.next_due.start)
                                  : 'None'}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {summaryState !== 'ready'
                                ? 'Schedule incomplete'
                                : summary?.next_due
                                  ? `${summary.next_due.title.replace(/ · reminder$/, '')} · does not reserve a day`
                                  : 'No due reminders'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </div>
            }
            filters={
                <>
                    <PageHeaderFilterButton
                        icon={ChevronLeft}
                        aria-label="Previous period"
                        onClick={() => shift(-1)}
                    />
                    <JumpToDate
                        view={view}
                        navDate={navDate}
                        onPick={setNavDate}
                        pill
                    />
                    <PageHeaderFilterButton
                        icon={ChevronRight}
                        aria-label="Next period"
                        onClick={() => shift(1)}
                    />
                    <PageHeaderFilterButton
                        active={viewingToday}
                        onClick={() => setNavDate(new Date())}
                    >
                        Today
                    </PageHeaderFilterButton>
                    <PageHeaderFilterSelect
                        icon={Rows3}
                        label="Display"
                        value={density}
                        allValue="comfortable"
                        options={[
                            { value: 'comfortable', label: 'Comfortable' },
                            { value: 'compact', label: 'Compact' },
                        ]}
                        onChange={(value) => setDensity(value as Density)}
                    />
                </>
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
    );

    return (
        <div
            className="vehicle-calendar"
            onPointerDownCapture={(event) => {
                lastPointer.current = event.pointerType;
                // Focus the entry first so closing its dialog restores focus.
                const target = (
                    event.target as HTMLElement
                ).closest<HTMLElement>('[role="button"][tabindex="0"]');
                target?.focus({ preventScroll: true });
            }}
            onKeyDownCapture={() => {
                lastPointer.current = null;
            }}
        >
            {menu && (
                <CalendarContextMenu
                    key={`${menu.kind}-${menu.x}-${menu.y}`}
                    x={menu.x}
                    y={menu.y}
                    chip={
                        menu.kind === 'create'
                            ? 'Add'
                            : ENTRY_CHIPS[menu.entry.kind]
                    }
                    chipIcon={menu.kind === 'create' ? Plus : CalendarDays}
                    heading={
                        menu.kind === 'create'
                            ? `${fullDate(menu.date)} · ${vehicle.name}`
                            : menu.entry.title
                    }
                    subheading={
                        menu.kind === 'create'
                            ? `${startFor(menu.date, menu.hour).slice(11)} · Pacific/Auckland`
                            : [
                                  menu.entry.allDay
                                      ? 'All day'
                                      : fmtTimeRange(
                                            menu.entry._start,
                                            menu.entry._end,
                                        ),
                                  menu.entry.statusLabel,
                              ]
                                  .filter(Boolean)
                                  .join(' · ')
                    }
                    ariaLabel={
                        menu.kind === 'create'
                            ? 'Add to the vehicle calendar'
                            : 'Calendar entry actions'
                    }
                    sections={menuSections(menu)}
                    returnFocus={menu.opener}
                    onClose={() => setMenu(null)}
                />
            )}
            <PageLayout hero={header}>
                <div
                    className="calendar-source-bar"
                    aria-label="Calendar sources"
                >
                    <CalendarSourcePills
                        sources={SOURCES}
                        enabled={enabled}
                        counts={sourceCounts}
                        onToggle={(key) =>
                            setEnabled((current) => {
                                const next = new Set(current);
                                if (next.has(key)) next.delete(key);
                                else next.add(key);
                                return next;
                            })
                        }
                    />
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                            setEnabled(new Set(SOURCE_KEYS));
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
                                Since {formatDateTime(restriction.started_at)} ·
                                No end or authorised release recorded. Calendar
                                gaps do not mean available.
                            </span>
                        </div>
                        <Button
                            variant="outline"
                            onClick={() => restrictionRecord()}
                        >
                            View restriction
                        </Button>
                    </div>
                )}
                {(feedFailed || summaryState === 'failed') && (
                    <div
                        role="alert"
                        className="flex flex-wrap items-center gap-3 rounded-xl border border-status-critical/30 bg-status-critical-bg px-4 py-3 text-sm text-status-critical"
                    >
                        <AlertTriangle
                            className="size-4 shrink-0"
                            aria-hidden
                        />
                        <span className="min-w-0 flex-1">
                            {feedFailed
                                ? 'Some calendar entries couldn’t load, so this schedule may be incomplete.'
                                : 'This vehicle’s scheduling actions couldn’t load.'}
                        </span>
                        <Button variant="outline" size="sm" onClick={reload}>
                            <RefreshCw className="size-4" /> Retry
                        </Button>
                    </div>
                )}
                <CalendarUIProvider value={context}>
                    <div className="calendar-layout">
                        <div
                            className="calendar-view relative"
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
                                    sourcesOff={enabled.size === 0}
                                    filtersActive={
                                        enabled.size !== SOURCES.length ||
                                        !!query
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
                            {feed.state === 'loading' && (
                                <div className="pointer-events-none absolute inset-0 flex items-start justify-center pt-24">
                                    <span className="inline-flex items-center gap-2 rounded-full border bg-card px-3 py-1.5 text-[12.5px] font-medium text-muted-foreground shadow-sm">
                                        <RefreshCw
                                            className="size-3.5 animate-spin"
                                            aria-hidden
                                        />{' '}
                                        Loading…
                                    </span>
                                </div>
                            )}
                        </div>
                        <TodayRail
                            events={railVisible}
                            today={today}
                            onSelect={context.onSelect}
                            onApprovals={() => setView('agenda')}
                            onJumpToday={() => setNavDate(new Date())}
                            viewingToday={viewingToday}
                        />
                    </div>
                </CalendarUIProvider>
                <div className="calendar-footnote">
                    <Lock className="size-[14px]" aria-hidden />
                    <span>
                        Right-click (or Shift+F10) a date or time for scheduling
                        and reminders, or an entry for its actions; on touch,
                        use New entry or tap an entry. Reminders and advisory
                        estimates do not reserve the vehicle.
                    </span>
                </div>
                <BookingsStudio
                    summary={summary}
                    onResolveUseProblem={
                        summary?.use_problem_code
                            ? () =>
                                  onNavigate(
                                      reasonDestination({
                                          code: summary.use_problem_code ?? '',
                                          kind: summary.use_problem_kind,
                                          source_id:
                                              summary.use_problem_source_id ??
                                              null,
                                      }),
                                  )
                            : undefined
                    }
                    onRequest={() =>
                        setDialog({
                            kind: 'booking',
                            mode: { kind: 'request' },
                        })
                    }
                    onBlock={() =>
                        setDialog({ kind: 'booking', mode: { kind: 'block' } })
                    }
                    onAction={(action) => perform(action.intent)}
                />
            </PageLayout>

            {dialog?.kind === 'booking' && summary && (
                <BookingWizard
                    vehicle={vehicle}
                    summary={summary}
                    mode={dialog.mode}
                    onClose={() => {
                        undoPlan.current = null;
                        setDialog(null);
                    }}
                    onSaved={() => {
                        changed();
                        offerUndo();
                    }}
                />
            )}
            {dialog?.kind === 'decision' && summary && (
                <BookingDecisionWizard
                    workspace={workspace}
                    summary={summary}
                    row={dialog.row}
                    decision={dialog.decision}
                    onClose={() => {
                        undoPlan.current = null;
                        setDialog(null);
                    }}
                    onSaved={() => {
                        changed();
                        offerUndo();
                    }}
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
                    source={dialog.source}
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
                    action={dialog.action}
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
            {dialog?.kind === 'document' && (
                <DocumentEditDialog
                    workspace={workspace}
                    set={dialog.set}
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
