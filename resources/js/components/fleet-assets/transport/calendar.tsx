import type { MenuItem } from '@/components/lists/entity-menu';
import { Button } from '@/components/ui/button';
import type { CalendarItem } from '@/lib/calendar/recur';
import { toDatetimeLocal } from '@/lib/datetime';
import {
    AgendaView,
    CalendarContextMenu,
    CalendarSourcePills,
    CalendarUIProvider,
    DayView,
    JumpToDate,
    MonthView,
    TimelineView,
    TodayRail,
    WeekView,
    decorate,
    periodLabel,
    viewRange,
    type CalView,
    type Decorated,
    type SourceDef,
} from '@/pages/sites/calendar/_parts';
import {
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    FileText,
    Plus,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import {
    calendarLocal,
    canRescheduleTransport,
    proposedCalendarWindow,
    sourceCalendarWindow,
} from './calendar-actions';
import { RescheduleDialog } from './reschedule-dialog';
import type { TransportRecord } from './types';
import { Notice } from './ui';
export type TransportCalendarItem = CalendarItem & {
    kind: string;
    recordId: number | null;
    vehicle_id: number;
    vehicle_name: string;
    statusLabel?: string;
};
export const dayKey = (date: Date) =>
    `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
export function calendarPeriod(view: CalView, date: Date) {
    const range = viewRange(view, date);
    return {
        from: dayKey(range.start),
        to: dayKey(new Date(range.end.getTime() - 1)),
    };
}
const sources: SourceDef[] = [
    {
        key: 'transport',
        label: 'Transport bookings',
        short: 'Transport',
        group: 'Fleet',
        icon: 'Truck',
        origin: 'auto',
        note: 'The same Fleet reservation linked to this request',
    },
    {
        key: 'fleet',
        label: 'Other Fleet activity',
        short: 'Other Fleet activity',
        group: 'Fleet',
        icon: 'Wrench',
        origin: 'auto',
        note: 'Source-owned reservations and availability',
    },
];
export function TransportCalendar({
    events,
    records,
    view,
    day,
    onDay,
    onOpen,
    recordActions,
    onSaved,
    onRequest,
    onDayView,
    onApprovals,
}: {
    events: TransportCalendarItem[];
    records: TransportRecord[];
    view: CalView;
    day: string;
    onDay: (day: string) => void;
    onOpen: (row: TransportRecord) => void;
    recordActions: (row: TransportRecord) => MenuItem[];
    onSaved: () => void;
    onRequest: (start?: string) => void;
    onDayView: (day: string) => void;
    onApprovals: () => void;
}) {
    const [enabled, setEnabled] = useState(new Set(['transport']));
    const [menu, setMenu] = useState<{
        x: number;
        y: number;
        opener: HTMLElement;
        date?: Date;
        hour?: number;
        entry?: Decorated;
    } | null>(null);
    const [proposal, setProposal] = useState<{
        row: TransportRecord;
        start: string;
        end: string;
        startOffset: string;
        endOffset: string;
    } | null>(null);
    const opening = useRef(false);
    const navDate = useMemo(() => new Date(`${day}T12:00:00`), [day]);
    const today = new Date(`${toDatetimeLocal(new Date().toISOString())}:00`);
    const byBooking = new Map(
        records.filter((r) => r.booking).map((r) => [r.booking!.id, r]),
    );
    const entries = events.map((event) => {
        const row =
            event.kind === 'booking'
                ? byBooking.get(event.recordId || 0)
                : null;
        // Calendar views use local wall Date objects; convert source UTC through Auckland first.
        return {
            ...decorate({
                ...event,
                start: toDatetimeLocal(event.start),
                end: event.end ? toDatetimeLocal(event.end) : null,
                source: row ? 'transport' : 'fleet',
                editable: !!row && canRescheduleTransport(row),
                title: row
                    ? `${row.person} · ${event.vehicle_name}`
                    : `${event.vehicle_name} · ${event.title}`,
            }),
            transport: row,
            statusLabel: event.statusLabel,
        };
    });
    const visible = entries.filter((e) => enabled.has(e.source));
    const select = (entry: Decorated) => {
        const found = entries.find((e) => e.id === entry.id);
        if (found?.transport) onOpen(found.transport);
        else if (entry.link) window.location.assign(entry.link);
    };
    const reschedule = async (
        entry: Decorated,
        start?: Date,
        end?: Date,
        mode?: 'move' | 'resize',
    ) => {
        const previous = entries.find((e) => e.id === entry.id)?.transport;
        if (!previous || !canRescheduleTransport(previous) || opening.current)
            return;
        opening.current = true;
        try {
            const response = await fetch(previous.links.request, {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            });
            if (!response.ok)
                throw new Error(
                    'This transport is no longer available. Refresh the calendar.',
                );
            const current: TransportRecord = await response.json();
            if (
                !canRescheduleTransport(current) ||
                current.booking?.version !== previous.booking?.version ||
                current.version !== previous.version
            )
                throw new Error(
                    'This plan changed. Refresh the calendar and review the latest booking before moving it.',
                );
            const window = start
                ? proposedCalendarWindow(
                      current.booking!.start,
                      current.booking!.end,
                      start,
                      end,
                      mode,
                  )
                : sourceCalendarWindow(
                      current.booking!.start,
                      current.booking!.end,
                  );
            setProposal({ row: current, ...window });
        } catch (error) {
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'The current booking could not be loaded.',
            );
        } finally {
            opening.current = false;
        }
    };
    const menuEntry = menu?.entry
        ? entries.find((e) => e.id === menu.entry!.id)
        : null;
    const createAt = (date: Date, hour = 9) => {
        const start = new Date(date);
        start.setHours(Math.floor(hour), Math.round((hour % 1) * 60), 0, 0);
        onRequest(calendarLocal(start));
    };
    const move = (direction: number) => {
        const date = new Date(navDate);
        if (['month', 'agenda', 'timeline'].includes(view))
            date.setMonth(date.getMonth() + direction, 1);
        else
            date.setDate(date.getDate() + direction * (view === 'day' ? 1 : 7));
        onDay(dayKey(date));
    };
    return (
        <div
            onKeyDownCapture={(event) => {
                if (
                    event.key !== 'ContextMenu' &&
                    !(event.shiftKey && event.key === 'F10')
                )
                    return;
                const target = (
                    event.target as HTMLElement
                ).closest<HTMLElement>(
                    '[role="gridcell"], [role="button"], button',
                );
                if (!target || target.closest('[role="dialog"], [role="menu"]'))
                    return;
                event.preventDefault();
                const rect = target.getBoundingClientRect();
                target.dispatchEvent(
                    new MouseEvent('contextmenu', {
                        bubbles: true,
                        cancelable: true,
                        clientX: rect.left + 16,
                        clientY: rect.top + 20,
                    }),
                );
            }}
        >
            <CalendarUIProvider
                value={{
                    colorBy: 'source',
                    density: 'comfortable',
                    srcByKey: Object.fromEntries(
                        sources.map((s) => [s.key, s]),
                    ),
                    onSelect: select,
                    onEntryContext: (entry, event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        setMenu({
                            x: event.clientX,
                            y: event.clientY,
                            opener: event.currentTarget as HTMLElement,
                            entry,
                        });
                    },
                    onContext: (event, date, hour) => {
                        event.preventDefault();
                        event.stopPropagation();
                        setMenu({
                            x: event.clientX,
                            y: event.clientY,
                            opener: event.currentTarget as HTMLElement,
                            date,
                            hour,
                        });
                    },
                    onMove: (entry, start, end, mode) =>
                        void reschedule(entry, start, end, mode),
                    onCreateAt: createAt,
                    onMore: (date) => onDayView(dayKey(date)),
                }}
            >
                <div className="tr-calendar-toolbar">
                    <div className="flex items-center gap-2">
                        <Button
                            variant="outline"
                            size="icon"
                            aria-label="Previous calendar period"
                            onClick={() => move(-1)}
                        >
                            <ChevronLeft />
                        </Button>
                        <JumpToDate
                            view={view}
                            navDate={navDate}
                            onPick={(date) => onDay(dayKey(date))}
                        />
                        <Button
                            variant="outline"
                            size="icon"
                            aria-label="Next calendar period"
                            onClick={() => move(1)}
                        >
                            <ChevronRight />
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() => onDay(dayKey(today))}
                        >
                            Today
                        </Button>
                    </div>
                    <strong className="text-sm">
                        {periodLabel(view, navDate)}
                    </strong>
                    <Button variant="outline" onClick={() => createAt(navDate)}>
                        <Plus className="size-4" />
                        New entry
                    </Button>
                </div>
                <CalendarSourcePills
                    sources={sources}
                    enabled={enabled}
                    counts={Object.fromEntries(
                        sources.map((s) => [
                            s.key,
                            entries.filter((e) => e.source === s.key).length,
                        ]),
                    )}
                    onToggle={(key) =>
                        setEnabled((current) => {
                            const next = new Set(current);
                            if (next.has(key)) next.delete(key);
                            else next.add(key);
                            return next;
                        })
                    }
                />
                <div className="flex gap-4">
                    <div className="tr-calendar-surface min-w-0 flex-1">
                        {view === 'month' ? (
                            <MonthView events={visible} navDate={navDate} />
                        ) : view === 'week' ? (
                            <WeekView events={visible} navDate={navDate} />
                        ) : view === 'day' ? (
                            <DayView events={visible} navDate={navDate} />
                        ) : view === 'timeline' ? (
                            <TimelineView
                                events={visible}
                                navDate={navDate}
                                sources={sources}
                            />
                        ) : (
                            <AgendaView
                                events={visible}
                                navDate={navDate}
                                sourcesOff={!enabled.size}
                                filtersActive={false}
                            />
                        )}
                    </div>
                    <TodayRail
                        events={visible}
                        today={today}
                        onSelect={select}
                        onApprovals={onApprovals}
                        onJumpToday={() => onDay(dayKey(today))}
                        viewingToday={dayKey(navDate) === dayKey(today)}
                    />
                </div>
                <Notice>
                    Right-click a date, time or entry for actions, or press
                    Shift+F10. Drag an editable booking to another date in
                    Month, or move/resize it in Week and Day. Review and save
                    the proposed time before the Fleet booking changes. Started
                    journeys and other Fleet activity open their source records.
                </Notice>
            </CalendarUIProvider>
            {menu && (
                <CalendarContextMenu
                    x={menu.x}
                    y={menu.y}
                    returnFocus={menu.opener}
                    chip={menu.entry ? 'Actions' : 'Add'}
                    chipIcon={menu.entry ? CalendarDays : Plus}
                    heading={menu.entry?.title || dayKey(menu.date!)}
                    subheading={
                        menu.entry
                            ? menuEntry?.statusLabel
                            : `${String(menu.hour ?? 9).padStart(2, '0')}:00 · Pacific/Auckland`
                    }
                    ariaLabel={
                        menu.entry
                            ? 'Transport calendar entry actions'
                            : 'Add to the transport calendar'
                    }
                    onClose={() => setMenu(null)}
                    sections={[
                        {
                            key: 'actions',
                            items: menu.entry
                                ? [
                                      ...(menuEntry?.transport &&
                                      canRescheduleTransport(
                                          menuEntry.transport,
                                      )
                                          ? [
                                                {
                                                    key: 'reschedule',
                                                    label: 'Reschedule transport',
                                                    icon: CalendarDays,
                                                    onSelect: () =>
                                                        void reschedule(
                                                            menu.entry!,
                                                        ),
                                                },
                                            ]
                                          : []),
                                      ...(menuEntry?.transport
                                          ? recordActions(menuEntry.transport)
                                                .filter(
                                                    (item) =>
                                                        item.label &&
                                                        item.onClick,
                                                )
                                                .map((item, i) => ({
                                                    key: String(i),
                                                    label: item.label!,
                                                    destructive: item.danger,
                                                    leading: item.icon ? (
                                                        <item.icon className="size-4" />
                                                    ) : undefined,
                                                    onSelect: item.onClick!,
                                                }))
                                          : [
                                                {
                                                    key: 'source',
                                                    label: 'Open Fleet source record',
                                                    icon: FileText,
                                                    disabled: !menu.entry.link,
                                                    onSelect: () =>
                                                        select(menu.entry!),
                                                },
                                            ]),
                                  ]
                                : [
                                      {
                                          key: 'request',
                                          label: 'Request transport at this time',
                                          icon: Plus,
                                          onSelect: () =>
                                              createAt(menu.date!, menu.hour),
                                      },
                                      {
                                          key: 'day',
                                          label: 'View this day',
                                          icon: CalendarDays,
                                          onSelect: () =>
                                              onDayView(dayKey(menu.date!)),
                                      },
                                  ],
                        },
                    ]}
                />
            )}
            {proposal && (
                <RescheduleDialog
                    key={`${proposal.row.booking!.version}-${proposal.start}-${proposal.end}`}
                    {...proposal}
                    onClose={() => setProposal(null)}
                    onSaved={onSaved}
                    onUndo={async (start, end) => {
                        try {
                            const response = await fetch(
                                proposal.row.links.request,
                                {
                                    headers: { Accept: 'application/json' },
                                    cache: 'no-store',
                                },
                            );
                            if (!response.ok)
                                throw new Error(
                                    'The saved record could not load. Reopen it before changing the time.',
                                );
                            const current: TransportRecord =
                                await response.json();
                            if (
                                !canRescheduleTransport(current) ||
                                Date.parse(current.booking!.start) !==
                                    Date.parse(start) ||
                                Date.parse(current.booking!.end) !==
                                    Date.parse(end)
                            )
                                throw new Error(
                                    'The booking has changed since your save. Reopen its current record before rescheduling.',
                                );
                            setProposal({
                                row: current,
                                ...sourceCalendarWindow(
                                    proposal.row.booking!.start,
                                    proposal.row.booking!.end,
                                ),
                            });
                        } catch (error) {
                            toast.error(
                                error instanceof Error
                                    ? error.message
                                    : 'The saved booking could not be loaded.',
                            );
                        }
                    }}
                />
            )}
        </div>
    );
}
