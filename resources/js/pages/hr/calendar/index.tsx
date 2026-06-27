/* eslint-disable no-restricted-syntax -- This hub uses a few bespoke on-surface
 * controls (the segmented view switch, layer-toggle rows in the popover, the
 * renewals list rows) that are intentional raw <button>/<div> layout cases, not
 * shadcn <Button>/<Card>. Colours stay token-based throughout. */
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { CalendarHero, type UpNextEntry } from '@/components/hr/calendar/calendar-hero';
import {
    EventWizardDialog,
    type CalendarEventInitial,
} from '@/components/hr/calendar/event-wizard-dialog';
import { ICalSubscribeDialog } from '@/components/hr/calendar/ical-subscribe-dialog';
import { HrTabs } from '@/components/hr';
import { CalendarView } from '@/components/calendar/calendar-view';
import {
    CALENDAR_LAYERS,
    DEFAULT_ACTIVE_LAYERS,
    LAYER_META,
    type CalendarLayer,
    type CalendarLayerFeed,
} from '@/lib/calendar/layer-feed';
import AppLayout from '@/layouts/app-layout';
import dayGridPlugin from '@fullcalendar/daygrid';
import interactionPlugin from '@fullcalendar/interaction';
import listPlugin from '@fullcalendar/list';
import timeGridPlugin from '@fullcalendar/timegrid';
import type {
    EventClickArg,
    EventInput,
    EventSourceFuncArg,
} from '@fullcalendar/core';
import type FullCalendar from '@fullcalendar/react';
import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarClock,
    CalendarDays,
    Layers,
    Search,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

type BreadcrumbItem = { title: string; href: string };

type IdName = { id: number; name: string };

interface HeroStats {
    eventsThisWeek: number;
    onLeaveToday: number;
    coverageGapsToday: number;
    renewalsSoon: number;
}

interface Props {
    sites: IdName[];
    departments: IdName[];
    teams: string[];
    stats: HeroStats;
    upNext: UpNextEntry[];
    ical: { url: string | null };
    can: { manage: boolean; manageRecurring: boolean; seeSensitive: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Calendar', href: '/hr/calendar' },
];

type FcView = 'dayGridMonth' | 'timeGridWeek' | 'timeGridDay' | 'listWeek';

const VIEW_PARAM: Record<string, FcView> = {
    month: 'dayGridMonth',
    week: 'timeGridWeek',
    day: 'timeGridDay',
    agenda: 'listWeek',
};
const PARAM_FOR_VIEW: Record<FcView, string> = {
    dayGridMonth: 'month',
    timeGridWeek: 'week',
    timeGridDay: 'day',
    listWeek: 'agenda',
};

const LAYERS_STORAGE_KEY = 'hrCalendar.layers';

/** One-time page-scoped styling for layer-specific event treatments. */
const PAGE_STYLE_ID = 'hr-calendar-layer-styles';
const PAGE_STYLES = `
.hrcal-pending { opacity: 0.85; border-style: dashed !important; border-width: 1.5px !important; }
.hrcal-pending .fc-event-title { font-style: italic; }
.hrcal-redacted .fc-event-title::after { content: ' · private'; opacity: 0.6; font-size: 0.85em; }
`;

function useLayerStyles() {
    useEffect(() => {
        if (document.getElementById(PAGE_STYLE_ID)) return;
        const el = document.createElement('style');
        el.id = PAGE_STYLE_ID;
        el.textContent = PAGE_STYLES;
        document.head.appendChild(el);
    }, []);
}

function readInitialLayers(): CalendarLayer[] {
    if (typeof window === 'undefined') return [...DEFAULT_ACTIVE_LAYERS];
    const fromUrl = new URLSearchParams(window.location.search).get('layers');
    const source = fromUrl ?? window.localStorage.getItem(LAYERS_STORAGE_KEY);
    if (!source) return [...DEFAULT_ACTIVE_LAYERS];
    const parsed = source.split(',').filter((l): l is CalendarLayer =>
        (CALENDAR_LAYERS as readonly string[]).includes(l),
    );
    return parsed.length ? parsed : [...DEFAULT_ACTIVE_LAYERS];
}

function readInitialView(): FcView {
    if (typeof window === 'undefined') return 'dayGridMonth';
    const v = new URLSearchParams(window.location.search).get('view');
    return (v && VIEW_PARAM[v]) || 'dayGridMonth';
}

/** Map one feed row to a FullCalendar event, applying per-layer styling. */
function toFcEvent(e: CalendarLayerFeed): EventInput {
    const ext = { ...e.extendedProps, layer: e.layer, deepLink: e.deepLink };
    const base: EventInput = {
        id: e.id,
        title: e.title,
        start: e.start,
        end: e.end,
        allDay: e.allDay,
        editable: e.editable,
        extendedProps: ext,
    };

    if (e.layer === 'holiday') {
        return {
            ...base,
            display: 'background',
            backgroundColor: 'color-mix(in oklch, var(--status-warning) 16%, transparent)',
        };
    }
    if (e.extendedProps.gap) {
        return {
            ...base,
            display: 'background',
            backgroundColor: 'color-mix(in oklch, var(--status-critical) 14%, transparent)',
        };
    }

    const color = `var(--${e.color})`;
    const classNames: string[] = [];
    if (e.extendedProps.pending) classNames.push('hrcal-pending');
    if (e.extendedProps.redacted) classNames.push('hrcal-redacted');
    return {
        ...base,
        backgroundColor: color,
        borderColor: color,
        textColor: 'var(--primary-foreground)',
        classNames,
    };
}

export default function CalendarIndex({
    sites,
    departments,
    teams,
    stats,
    upNext,
    ical,
    can,
}: Props) {
    useLayerStyles();
    const calendarRef = useRef<FullCalendar>(null);

    const [tab, setTab] = useState<'calendar' | 'renewals'>('calendar');
    const [activeLayers, setActiveLayers] = useState<CalendarLayer[]>(readInitialLayers);
    const [view, setView] = useState<FcView>(readInitialView);
    const [siteFilter, setSiteFilter] = useState<string>('all');
    const [deptFilter, setDeptFilter] = useState<string>('all');
    const [teamFilter, setTeamFilter] = useState<string>('all');
    const [search, setSearch] = useState('');
    const [counts, setCounts] = useState<Record<string, number>>({});
    const [feedError, setFeedError] = useState(false);
    const [renewals, setRenewals] = useState<CalendarLayerFeed[] | null>(null);
    const [wizardOpen, setWizardOpen] = useState(false);
    const [editingEvent, setEditingEvent] = useState<CalendarEventInitial | null>(null);
    const [createDate, setCreateDate] = useState<string | null>(null);
    const [subscribeOpen, setSubscribeOpen] = useState(false);

    // Keep the live filter/layer state in a ref so the FullCalendar event source
    // (registered once) always reads the current values without re-registering.
    // Synced in an effect (never written during render).
    const filtersRef = useRef({ activeLayers, siteFilter, deptFilter, teamFilter, search });
    useEffect(() => {
        filtersRef.current = { activeLayers, siteFilter, deptFilter, teamFilter, search };
    });

    // Persist layer choice to localStorage + ?layers=
    useEffect(() => {
        window.localStorage.setItem(LAYERS_STORAGE_KEY, activeLayers.join(','));
        const url = new URL(window.location.href);
        url.searchParams.set('layers', activeLayers.join(','));
        window.history.replaceState(window.history.state, '', url.toString());
        calendarRef.current?.getApi().refetchEvents();
    }, [activeLayers]);

    // Persist view to ?view=
    useEffect(() => {
        const url = new URL(window.location.href);
        url.searchParams.set('view', PARAM_FOR_VIEW[view]);
        window.history.replaceState(window.history.state, '', url.toString());
    }, [view]);

    const buildFeedUrl = useCallback((from: string, to: string, layers: string) => {
        const { siteFilter: s, deptFilter: d, teamFilter: t } = filtersRef.current;
        const params = new URLSearchParams({ from, to, layers });
        if (s !== 'all') params.set('site', s);
        if (d !== 'all') params.set('department', d);
        if (t !== 'all') params.set('team', t);
        return `/hr/calendar/feed?${params.toString()}`;
    }, []);

    const fetchEvents = useCallback(
        (
            info: EventSourceFuncArg,
            success: (e: EventInput[]) => void,
            failure: (error: Error) => void,
        ) => {
            const { activeLayers: layers, search: q } = filtersRef.current;
            if (layers.length === 0) {
                setCounts({});
                success([]);
                return;
            }
            fetch(buildFeedUrl(info.startStr.slice(0, 10), info.endStr.slice(0, 10), layers.join(',')), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            })
                .then((r) => {
                    if (!r.ok) throw new Error(String(r.status));
                    return r.json();
                })
                .then((data: { events: CalendarLayerFeed[] }) => {
                    setFeedError(false);
                    const tally: Record<string, number> = {};
                    for (const e of data.events) tally[e.layer] = (tally[e.layer] ?? 0) + 1;
                    setCounts(tally);
                    const filtered = q.trim()
                        ? data.events.filter((e) =>
                              e.title.toLowerCase().includes(q.trim().toLowerCase()),
                          )
                        : data.events;
                    success(filtered.map(toFcEvent));
                })
                .catch((err: Error) => {
                    setFeedError(true);
                    failure(err);
                });
        },
        [buildFeedUrl],
    );

    // Refetch when filters/search change.
    useEffect(() => {
        calendarRef.current?.getApi().refetchEvents();
    }, [siteFilter, deptFilter, teamFilter, search]);

    // Keep FullCalendar's view in sync with our state.
    useEffect(() => {
        const api = calendarRef.current?.getApi();
        if (api && api.view.type !== view) api.changeView(view);
    }, [view]);

    // Load the renewals list when that tab opens.
    useEffect(() => {
        if (tab !== 'renewals' || renewals !== null) return;
        const from = new Date().toISOString().slice(0, 10);
        const to = new Date(Date.now() + 90 * 86_400_000).toISOString().slice(0, 10);
        fetch(`/hr/calendar/feed?from=${from}&to=${to}&layers=compliance`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((d: { events: CalendarLayerFeed[] }) =>
                setRenewals(
                    [...d.events].sort((a, b) => a.start.localeCompare(b.start)),
                ),
            )
            .catch(() => setRenewals([]));
    }, [tab, renewals]);

    const toggleLayer = (layer: CalendarLayer) => {
        setActiveLayers((prev) =>
            prev.includes(layer) ? prev.filter((l) => l !== layer) : [...prev, layer],
        );
    };

    const goToday = () => calendarRef.current?.getApi().today();

    const openCreate = (date?: string | null) => {
        setEditingEvent(null);
        setCreateDate(date ?? null);
        setWizardOpen(true);
    };

    const refetch = () => calendarRef.current?.getApi().refetchEvents();

    const handleEventClick = (info: EventClickArg) => {
        const props = info.event.extendedProps as Record<string, unknown> & {
            deepLink?: string;
            layer?: CalendarLayer;
        };
        // HR events open the wizard (manager only); read-only layers deep-link.
        if (props.layer === 'event') {
            if (!can.manage) return;
            setEditingEvent({
                id: Number(props.eventId),
                title: info.event.title,
                description: (props.description as string) ?? null,
                event_type: (props.category as string) ?? 'company',
                starts_at: (props.startRaw as string) ?? info.event.startStr,
                ends_at: (props.endRaw as string) ?? info.event.endStr ?? info.event.startStr,
                is_all_day: !!props.isAllDay,
                location: (props.location as string) ?? null,
                department_id: (props.departmentId as number) ?? null,
                site_id: (props.siteId as number) ?? null,
            });
            setCreateDate(null);
            setWizardOpen(true);
            return;
        }
        if (props.deepLink) router.visit(props.deepLink);
    };

    const onUpNext = (entry: UpNextEntry) => {
        if (entry.deepLink) {
            router.visit(entry.deepLink);
            return;
        }
        const api = calendarRef.current?.getApi();
        if (api) {
            api.gotoDate(entry.start);
            setView('timeGridDay');
        }
    };

    const calendarTabs = useMemo(
        () => [
            { id: 'calendar', label: 'Calendar', icon: CalendarDays, tone: 'primary' as const },
            {
                id: 'renewals',
                label: 'Renewals',
                icon: CalendarClock,
                tone: 'warning' as const,
                badge: stats.renewalsSoon || undefined,
            },
        ],
        [stats.renewalsSoon],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Calendar" />

            <PageShell>
                <CalendarHero
                    stats={stats}
                    upNext={upNext}
                    canManage={can.manage}
                    siteCount={sites.length}
                    needs={[
                        ...(stats.coverageGapsToday > 0
                            ? [
                                  {
                                      key: 'gaps',
                                      label: `${stats.coverageGapsToday} coverage gap${stats.coverageGapsToday === 1 ? '' : 's'} today`,
                                      onClick: () => {
                                          if (!activeLayers.includes('shift')) toggleLayer('shift');
                                          setView('timeGridDay');
                                          goToday();
                                      },
                                  },
                              ]
                            : []),
                        ...(stats.renewalsSoon > 0
                            ? [
                                  {
                                      key: 'renewals',
                                      label: `${stats.renewalsSoon} renewal${stats.renewalsSoon === 1 ? '' : 's'} due soon`,
                                      onClick: () => setTab('renewals'),
                                  },
                              ]
                            : []),
                    ]}
                    handlers={{
                        // Subscribe (iCal) lands in Pass 6; "Manage layers" is the toolbar
                        // Layers popover, so it isn't duplicated as a hero action.
                        onNewEvent: can.manage ? () => openCreate() : undefined,
                        onToday: () => {
                            setTab('calendar');
                            goToday();
                        },
                        onSubscribe: () => setSubscribeOpen(true),
                        onStatEvents: () => {
                            setTab('calendar');
                            setView('timeGridWeek');
                        },
                        onStatLeave: () => {
                            setTab('calendar');
                            if (!activeLayers.includes('leave')) toggleLayer('leave');
                            setView('listWeek');
                        },
                        onStatGaps: () => {
                            setTab('calendar');
                            if (!activeLayers.includes('shift')) toggleLayer('shift');
                            setView('timeGridDay');
                            goToday();
                        },
                        onStatRenewals: () => setTab('renewals'),
                        onUpNext,
                    }}
                />

                <div className="mt-6">
                    <HrTabs
                        value={tab}
                        onChange={(id) => setTab(id as 'calendar' | 'renewals')}
                        items={calendarTabs}
                        ariaLabel="Calendar views"
                        className="mb-5"
                    />
                </div>

                {tab === 'calendar' ? (
                    <div className="space-y-4">
                        {/* Filter + layer bar */}
                        <div className="flex flex-wrap items-center gap-2">
                            <div className="relative">
                                <Search className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Search events…"
                                    className="h-9 w-[200px] pl-8"
                                />
                            </div>

                            <FilterSelect
                                value={siteFilter}
                                onChange={setSiteFilter}
                                allLabel="All sites"
                                options={sites.map((s) => ({ value: String(s.id), label: s.name }))}
                            />
                            <FilterSelect
                                value={deptFilter}
                                onChange={setDeptFilter}
                                allLabel="All departments"
                                options={departments.map((d) => ({ value: String(d.id), label: d.name }))}
                            />
                            {teams.length > 0 ? (
                                <FilterSelect
                                    value={teamFilter}
                                    onChange={setTeamFilter}
                                    allLabel="All teams"
                                    options={teams.map((t) => ({ value: t, label: t }))}
                                />
                            ) : null}

                            <div className="ml-auto flex items-center gap-2">
                                <ViewSwitch view={view} onChange={setView} />
                                <LayerPopover
                                    activeLayers={activeLayers}
                                    counts={counts}
                                    onToggle={toggleLayer}
                                />
                            </div>
                        </div>

                        {/* Legend */}
                        <Legend activeLayers={activeLayers} counts={counts} />

                        <Card>
                            <CardContent className="p-4">
                                {feedError ? (
                                    <ErrorState
                                        title="Couldn't load the calendar"
                                        message="The calendar feed failed to load."
                                        onRetry={() => {
                                            setFeedError(false);
                                            calendarRef.current?.getApi().refetchEvents();
                                        }}
                                    />
                                ) : null}
                                <div className={feedError ? 'hidden' : undefined}>
                                    <CalendarView
                                        calendarRef={calendarRef}
                                        plugins={[
                                            dayGridPlugin,
                                            timeGridPlugin,
                                            listPlugin,
                                            interactionPlugin,
                                        ]}
                                        initialView={view}
                                        events={fetchEvents}
                                        eventClick={handleEventClick}
                                        selectable={can.manage}
                                        selectMirror={can.manage}
                                        select={
                                            can.manage
                                                ? (arg: { startStr: string }) => {
                                                      openCreate(arg.startStr.slice(0, 10));
                                                      calendarRef.current?.getApi().unselect();
                                                  }
                                                : undefined
                                        }
                                        datesSet={(arg) => {
                                            if (arg.view.type !== view) {
                                                setView(arg.view.type as FcView);
                                            }
                                        }}
                                        headerToolbar={{
                                            left: 'prev,next today',
                                            center: 'title',
                                            right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek',
                                        }}
                                        buttonText={{
                                            today: 'Today',
                                            month: 'Month',
                                            week: 'Week',
                                            day: 'Day',
                                            list: 'Agenda',
                                        }}
                                        eventDisplay="block"
                                        dayMaxEvents={4}
                                        nowIndicator
                                    />
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                ) : (
                    <RenewalsTab renewals={renewals} />
                )}

                {can.manage ? (
                    <EventWizardDialog
                        open={wizardOpen}
                        onClose={() => setWizardOpen(false)}
                        onSaved={refetch}
                        sites={sites}
                        departments={departments}
                        initial={editingEvent}
                        defaultDate={createDate}
                    />
                ) : null}

                <ICalSubscribeDialog
                    open={subscribeOpen}
                    onClose={() => setSubscribeOpen(false)}
                    url={ical.url}
                />
            </PageShell>
        </AppLayout>
    );
}

/* ─────────────────────────── sub-components ─────────────────────────── */

function FilterSelect({
    value,
    onChange,
    allLabel,
    options,
}: {
    value: string;
    onChange: (v: string) => void;
    allLabel: string;
    options: { value: string; label: string }[];
}) {
    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger className="h-9 w-[160px]">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value="all">{allLabel}</SelectItem>
                {options.map((o) => (
                    <SelectItem key={o.value} value={o.value}>
                        {o.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

function ViewSwitch({ view, onChange }: { view: FcView; onChange: (v: FcView) => void }) {
    const opts: { v: FcView; label: string }[] = [
        { v: 'dayGridMonth', label: 'Month' },
        { v: 'timeGridWeek', label: 'Week' },
        { v: 'timeGridDay', label: 'Day' },
        { v: 'listWeek', label: 'Agenda' },
    ];
    return (
        <div className="inline-flex rounded-lg border border-border bg-card p-0.5">
            {opts.map((o) => (
                <button
                    key={o.v}
                    type="button"
                    onClick={() => onChange(o.v)}
                    aria-pressed={view === o.v}
                    className={
                        'h-7 rounded-md px-2.5 text-[12px] font-semibold transition-colors ' +
                        (view === o.v
                            ? 'bg-primary text-primary-foreground'
                            : 'text-muted-foreground hover:text-foreground')
                    }
                >
                    {o.label}
                </button>
            ))}
        </div>
    );
}

function LayerSwatch({ token }: { token: string }) {
    return (
        <span
            className="h-3 w-3 flex-none rounded-[4px]"
            style={{ background: `var(--${token})` }}
        />
    );
}

function LayerPopover({
    activeLayers,
    counts,
    onToggle,
}: {
    activeLayers: CalendarLayer[];
    counts: Record<string, number>;
    onToggle: (l: CalendarLayer) => void;
}) {
    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button variant="outline" size="sm" className="h-9 gap-1.5">
                    <Layers className="h-4 w-4" />
                    Layers
                    <span className="ml-0.5 rounded bg-muted px-1.5 text-[11px] font-semibold tabular-nums">
                        {activeLayers.length}
                    </span>
                </Button>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-64 p-2">
                <p className="px-2 pb-1.5 pt-1 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">
                    Layers
                </p>
                {CALENDAR_LAYERS.map((layer) => {
                    const meta = LAYER_META[layer];
                    const active = activeLayers.includes(layer);
                    return (
                        <button
                            key={layer}
                            type="button"
                            onClick={() => onToggle(layer)}
                            className="flex w-full items-center gap-2.5 rounded-md px-2 py-1.5 text-left text-sm transition-colors hover:bg-accent"
                        >
                            <input
                                type="checkbox"
                                checked={active}
                                readOnly
                                className="pointer-events-none rounded border-border"
                            />
                            <LayerSwatch token={meta.color} />
                            <span className="flex-1">{meta.label}</span>
                            <span className="text-[11px] font-semibold tabular-nums text-muted-foreground">
                                {counts[layer] ?? 0}
                            </span>
                        </button>
                    );
                })}
            </PopoverContent>
        </Popover>
    );
}

function Legend({
    activeLayers,
    counts,
}: {
    activeLayers: CalendarLayer[];
    counts: Record<string, number>;
}) {
    return (
        <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5">
            {CALENDAR_LAYERS.filter((l) => activeLayers.includes(l)).map((layer) => {
                const meta = LAYER_META[layer];
                return (
                    <div key={layer} className="flex items-center gap-1.5 text-xs text-muted-foreground">
                        <LayerSwatch token={meta.color} />
                        <span>{meta.label}</span>
                        <span className="font-semibold tabular-nums">{counts[layer] ?? 0}</span>
                    </div>
                );
            })}
        </div>
    );
}

function RenewalsTab({ renewals }: { renewals: CalendarLayerFeed[] | null }) {
    if (renewals === null) {
        return (
            <Card>
                <CardContent className="p-6 text-sm text-muted-foreground">Loading renewals…</CardContent>
            </Card>
        );
    }
    if (renewals.length === 0) {
        return (
            <EmptyState
                icon={CalendarClock}
                title="No renewals due"
                description="Nothing expires in the next 90 days. Compliance items appear here as their renewal dates approach."
            />
        );
    }
    return (
        <Card>
            <CardContent className="divide-y divide-border p-0">
                {renewals.map((r) => {
                    const critical = r.extendedProps.urgency === 'critical';
                    return (
                        <button
                            key={r.id}
                            type="button"
                            onClick={() => router.visit(r.deepLink ?? '/hr/compliance')}
                            className="flex w-full items-center gap-3 px-5 py-3 text-left transition-colors hover:bg-accent"
                        >
                            <AlertTriangle
                                className={
                                    'h-4 w-4 flex-none ' +
                                    (critical ? 'text-status-critical' : 'text-status-warning')
                                }
                            />
                            <span className="min-w-0 flex-1 truncate text-sm font-medium">{r.title}</span>
                            <span className="flex-none text-xs font-semibold tabular-nums text-muted-foreground">
                                {new Date(r.start).toLocaleDateString('en-NZ', {
                                    day: 'numeric',
                                    month: 'short',
                                    year: 'numeric',
                                })}
                            </span>
                        </button>
                    );
                })}
            </CardContent>
        </Card>
    );
}
