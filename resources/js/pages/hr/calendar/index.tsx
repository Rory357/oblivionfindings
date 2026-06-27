/* eslint-disable no-restricted-syntax -- This hub uses a few bespoke on-surface
 * controls (the segmented view switch, layer-toggle rows in the popover, the
 * renewals list rows) that are intentional raw <button>/<div> layout cases, not
 * shadcn <Button>/<Card>. Colours stay token-based throughout. */
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
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
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { CalendarHero, type UpNextEntry } from '@/components/hr/calendar/calendar-hero';
import {
    EventWizardDialog,
    type CalendarEventInitial,
    type EventCategoryOption,
} from '@/components/hr/calendar/event-wizard-dialog';
import { ICalSubscribeDialog } from '@/components/hr/calendar/ical-subscribe-dialog';
import {
    CalendarDetailPopover,
    type EventDetail,
} from '@/components/hr/calendar/calendar-detail-popover';
import { CalendarYearPicker } from '@/components/hr/calendar/calendar-year-picker';
import { type PersonOption } from '@/components/hr/people-picker';
import {
    ShiftContextMenu,
    type ShiftCtxState,
} from '@/components/rostering/shift-context-menu';
import { HrTabs } from '@/components/hr';
import { CalendarView } from '@/components/calendar/calendar-view';
import {
    CALENDAR_LAYERS,
    LAYER_DISPLAY_ORDER,
    DEFAULT_ACTIVE_LAYERS,
    LAYER_META,
    type CalendarLayer,
    type CalendarLayerFeed,
} from '@/lib/calendar/layer-feed';
import { cn } from '@/lib/utils';
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
    CalendarPlus,
    Copy,
    ExternalLink,
    Layers,
    ListChecks,
    Pencil,
    Search,
    Trash2,
} from 'lucide-react';
import { toast } from 'sonner';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { createPortal } from 'react-dom';

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
    categories: EventCategoryOption[];
    staff: PersonOption[];
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
        // Only standalone HR events are drag/resize editable; recurring
        // occurrences are edited through the scope prompt, not by dragging.
        editable: e.editable && !e.extendedProps.recurring,
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
    categories,
    staff,
    stats,
    upNext,
    ical,
    can,
}: Props) {
    useLayerStyles();
    const calendarRef = useRef<FullCalendar>(null);

    const [tab, setTab] = useState<'calendar' | 'agenda' | 'renewals'>('calendar');
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
    const [scopePrompt, setScopePrompt] = useState<EventClickArg | null>(null);
    const [loading, setLoading] = useState(false);
    const [detail, setDetail] = useState<EventDetail | null>(null);
    const [ctxMenu, setCtxMenu] = useState<ShiftCtxState | null>(null);
    const [quickAdd, setQuickAdd] = useState<{ date: string; x: number; y: number } | null>(null);
    const [yearPickerOpen, setYearPickerOpen] = useState(false);
    const [deleteTarget, setDeleteTarget] = useState<{ id: number; title: string } | null>(null);
    const clickedInfoRef = useRef<EventClickArg | null>(null);
    const searchRef = useRef<HTMLInputElement>(null);

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
            setLoading(true);
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
                    setLoading(false);
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
                    setLoading(false);
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

    // Keyboard shortcuts: / search · n new · t today · 1-4 views · ←/→ period · Esc.
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            const el = e.target as HTMLElement | null;
            const typing =
                el?.tagName === 'INPUT' || el?.tagName === 'TEXTAREA' || el?.isContentEditable;
            if (e.key === 'Escape') {
                setDetail(null);
                setCtxMenu(null);
                setQuickAdd(null);
                return;
            }
            if (typing || tab !== 'calendar') return;
            const api = calendarRef.current?.getApi();
            switch (e.key) {
                case '/':
                    e.preventDefault();
                    searchRef.current?.focus();
                    break;
                case 'n':
                    if (can.manage) openCreate();
                    break;
                case 't':
                    api?.today();
                    break;
                case '1':
                    setView('dayGridMonth');
                    break;
                case '2':
                    setView('timeGridWeek');
                    break;
                case '3':
                    setView('timeGridDay');
                    break;
                case '4':
                    setView('listWeek');
                    break;
                case 'ArrowLeft':
                    api?.prev();
                    break;
                case 'ArrowRight':
                    api?.next();
                    break;
                default:
                    break;
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [tab, can.manage]);

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

    const buildInitial = (
        info: EventClickArg,
        scope: 'all' | 'this' | 'following',
    ): CalendarEventInitial => {
        const props = info.event.extendedProps as Record<string, unknown>;
        return {
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
            rrule: (props.rrule as string) ?? null,
            recurrence_until: (props.recurrenceUntil as string) ?? null,
            audience_type: (props.audienceType as 'org' | 'site' | 'department' | 'people') ?? 'org',
            audience_user_ids: (props.attendeeUserIds as number[]) ?? [],
            reminders: (props.reminders as { offset_minutes: number; channel: string }[]) ?? [],
            attachments: (props.attachments as CalendarEventInitial['attachments']) ?? [],
            scope,
            occurrence_date: (props.occurrenceDate as string) ?? null,
        };
    };

    const openEdit = (initial: CalendarEventInitial) => {
        setEditingEvent(initial);
        setCreateDate(null);
        setWizardOpen(true);
    };

    const detailFromInfo = (info: EventClickArg, x: number, y: number): EventDetail => {
        const props = info.event.extendedProps as Record<string, unknown>;
        return {
            x,
            y,
            id: info.event.id,
            title: info.event.title,
            start: info.event.startStr || null,
            end: info.event.endStr || null,
            allDay: info.event.allDay,
            layer: (props.layer as CalendarLayer) ?? 'event',
            deepLink: (props.deepLink as string) ?? null,
            props,
        };
    };

    // Left-click any entry → detail popover (read any layer; deep-link or edit).
    const handleEventClick = (info: EventClickArg) => {
        clickedInfoRef.current = info;
        const e = info.jsEvent as MouseEvent;
        setCtxMenu(null);
        setDetail(detailFromInfo(info, e?.clientX ?? 200, e?.clientY ?? 200));
    };

    const editFromInfo = (info: EventClickArg) => {
        const props = info.event.extendedProps as Record<string, unknown>;
        if (props.layer !== 'event' || !can.manage) {
            if (props.deepLink) router.visit(props.deepLink as string);
            return;
        }
        if (props.rrule && can.manageRecurring) {
            setScopePrompt(info);
        } else {
            openEdit(buildInitial(info, 'all'));
        }
    };

    const duplicateFromInfo = (info: EventClickArg) => {
        const init = buildInitial(info, 'all');
        router.post(
            '/hr/calendar/events',
            {
                title: `${init.title} (copy)`,
                description: init.description,
                event_type: init.event_type,
                starts_at: init.starts_at,
                ends_at: init.ends_at,
                is_all_day: init.is_all_day,
                location: init.location,
                department_id: init.department_id,
                site_id: init.site_id,
            },
            { preserveScroll: true, preserveState: true, onSuccess: () => refetch() },
        );
    };

    const deleteFromInfo = (info: EventClickArg) => {
        const id = Number((info.event.extendedProps as Record<string, unknown>).eventId);
        if (id) setDeleteTarget({ id, title: info.event.title });
    };

    const confirmDelete = () => {
        if (!deleteTarget) return;
        router.delete(`/hr/calendar/events/${deleteTarget.id}`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => refetch(),
        });
        setDeleteTarget(null);
    };

    // Drag-move / resize a standalone HR event → optimistic PUT, revert on fail.
    const handleEventMutate = (info: { event: { id: string; startStr: string; endStr: string; extendedProps: Record<string, unknown> }; revert: () => void }) => {
        const id = Number(info.event.extendedProps.eventId);
        if (!id) {
            info.revert();
            return;
        }
        router.put(
            `/hr/calendar/events/${id}`,
            {
                starts_at: info.event.startStr,
                ends_at: info.event.endStr || info.event.startStr,
                scope: 'all',
            },
            {
                preserveScroll: true,
                preserveState: true,
                onError: () => {
                    info.revert();
                    toast.error('Could not move the event');
                },
            },
        );
    };

    const buildEntryMenu = (info: EventClickArg, x: number, y: number) => {
        const props = info.event.extendedProps as Record<string, unknown>;
        const layer = (props.layer as CalendarLayer) ?? 'event';
        const meta = LAYER_META[layer];
        const items =
            layer === 'event' && can.manage
                ? [
                      { icon: <Pencil className="h-3.5 w-3.5" />, label: 'Edit', kbd: '↵', onClick: () => editFromInfo(info) },
                      { icon: <Copy className="h-3.5 w-3.5" />, label: 'Duplicate', onClick: () => duplicateFromInfo(info) },
                      { sep: true as const },
                      { icon: <Trash2 className="h-3.5 w-3.5" />, label: 'Delete', tone: 'critical' as const, onClick: () => deleteFromInfo(info) },
                  ]
                : props.deepLink
                  ? [{ icon: <ExternalLink className="h-3.5 w-3.5" />, label: 'Open in ' + meta.label.split(' ')[0], onClick: () => router.visit(props.deepLink as string) }]
                  : [];
        if (items.length === 0) return;
        setDetail(null);
        setCtxMenu({ x, y, tag: meta.label.split(' ')[0].toUpperCase().slice(0, 4), meta: info.event.title, items });
    };

    const buildDayMenu = (dateStr: string, x: number, y: number) => {
        if (!can.manage) return;
        setCtxMenu({
            x,
            y,
            tag: 'DAY',
            meta: new Date(dateStr).toLocaleDateString('en-NZ', { weekday: 'short', day: 'numeric', month: 'short' }),
            items: [
                { icon: <CalendarPlus className="h-3.5 w-3.5" />, label: 'New event here', onClick: () => openCreate(dateStr) },
            ],
        });
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

    const totalCount = useMemo(
        () => Object.values(counts).reduce((a, b) => a + b, 0),
        [counts],
    );
    const calendarTabs = useMemo(
        () => [
            {
                id: 'calendar',
                label: 'Calendar',
                icon: CalendarDays,
                tone: 'primary' as const,
                badge: counts.event || undefined,
            },
            {
                id: 'agenda',
                label: 'Agenda',
                icon: ListChecks,
                tone: 'info' as const,
                badge: totalCount || undefined,
            },
            {
                id: 'renewals',
                label: 'Renewals',
                icon: CalendarClock,
                tone: 'warning' as const,
                badge: stats.renewalsSoon || undefined,
            },
        ],
        [counts.event, totalCount, stats.renewalsSoon],
    );

    // Switching to the Agenda tab forces the list view; back to Calendar restores a grid.
    const handleTabChange = (next: string) => {
        setTab(next as 'calendar' | 'agenda' | 'renewals');
        if (next === 'agenda') setView('listWeek');
        else if (next === 'calendar' && view === 'listWeek') setView('dayGridMonth');
    };

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
                        onChange={handleTabChange}
                        items={calendarTabs}
                        ariaLabel="Calendar views"
                        className="mb-5"
                        trailing={
                            <span className="hidden text-[11px] text-muted-foreground lg:inline">
                                Right-click a tab to pin / set default
                            </span>
                        }
                    />
                </div>

                {tab === 'calendar' || tab === 'agenda' ? (
                    <div className="flex flex-col gap-4 lg:flex-row lg:items-start">
                        {/* Left: persistent layer rail — one grid replacing four calendars */}
                        <LayerRail
                            activeLayers={activeLayers}
                            counts={counts}
                            onToggle={toggleLayer}
                        />

                        {/* Right: filters + legend + calendar */}
                        <div className="min-w-0 flex-1 space-y-3">
                        {/* Filter bar */}
                        <div className="flex flex-wrap items-center gap-2">
                            <div className="relative">
                                <Search className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    ref={searchRef}
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Search events…  ( / )"
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

                            <div className="ml-auto">
                                <ViewSwitch view={view} onChange={setView} />
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
                                <div className={feedError ? 'hidden' : 'relative'}>
                                    {loading ? (
                                        <div className="pointer-events-none absolute inset-0 z-10 grid grid-rows-6 gap-1 rounded-xl bg-card/60 p-2 backdrop-blur-[1px]">
                                            {Array.from({ length: 6 }).map((_, r) => (
                                                <div key={r} className="grid grid-cols-7 gap-1">
                                                    {Array.from({ length: 7 }).map((_, c) => (
                                                        <div key={c} className="animate-pulse rounded-lg bg-muted/50" />
                                                    ))}
                                                </div>
                                            ))}
                                        </div>
                                    ) : null}
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
                                                ? (arg: { startStr: string; jsEvent: MouseEvent | null }) => {
                                                      setQuickAdd({
                                                          date: arg.startStr.slice(0, 10),
                                                          x: arg.jsEvent?.clientX ?? 240,
                                                          y: arg.jsEvent?.clientY ?? 240,
                                                      });
                                                  }
                                                : undefined
                                        }
                                        editable={can.manage}
                                        eventDrop={handleEventMutate}
                                        eventResize={handleEventMutate}
                                        eventDidMount={(arg) => {
                                            // Right-click an entry → context menu; native title = hover preview.
                                            const props = arg.event.extendedProps as Record<string, unknown>;
                                            const when = arg.event.start
                                                ? arg.event.start.toLocaleString('en-NZ', { dateStyle: 'medium', timeStyle: arg.event.allDay ? undefined : 'short' })
                                                : '';
                                            const bits = [arg.event.title, when, props.location, props.person].filter(Boolean);
                                            arg.el.setAttribute('title', bits.join('\n'));
                                            arg.el.addEventListener('contextmenu', (e) => {
                                                e.preventDefault();
                                                buildEntryMenu(
                                                    { event: arg.event, jsEvent: e } as unknown as EventClickArg,
                                                    (e as MouseEvent).clientX,
                                                    (e as MouseEvent).clientY,
                                                );
                                            });
                                        }}
                                        dayCellDidMount={(arg) => {
                                            arg.el.addEventListener('contextmenu', (e) => {
                                                e.preventDefault();
                                                buildDayMenu(
                                                    `${arg.date.getFullYear()}-${String(arg.date.getMonth() + 1).padStart(2, '0')}-${String(arg.date.getDate()).padStart(2, '0')}`,
                                                    (e as MouseEvent).clientX,
                                                    (e as MouseEvent).clientY,
                                                );
                                            });
                                        }}
                                        datesSet={(arg) => {
                                            if (arg.view.type !== view) {
                                                setView(arg.view.type as FcView);
                                            }
                                            // Make the month/year title open the year picker (caret affordance).
                                            const titleEl = document.querySelector<HTMLElement>(
                                                '.fc-toolbar-title',
                                            );
                                            if (titleEl && !titleEl.dataset.jump) {
                                                titleEl.dataset.jump = '1';
                                                titleEl.style.cursor = 'pointer';
                                                titleEl.setAttribute('title', 'Jump to month / day');
                                                titleEl.addEventListener('click', () => setYearPickerOpen(true));
                                            }
                                        }}
                                        headerToolbar={{
                                            left: 'prev,next today',
                                            center: 'title',
                                            right: '',
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
                        categories={categories}
                        staff={staff}
                        initial={editingEvent}
                        defaultDate={createDate}
                    />
                ) : null}

                <ICalSubscribeDialog
                    open={subscribeOpen}
                    onClose={() => setSubscribeOpen(false)}
                    url={ical.url}
                />

                <Dialog open={!!scopePrompt} onOpenChange={(o) => !o && setScopePrompt(null)}>
                    <DialogContent className="max-w-md">
                        <DialogHeader>
                            <DialogTitle>Edit recurring event</DialogTitle>
                            <DialogDescription>
                                This event repeats. Choose which occurrences your changes apply to.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="flex flex-col gap-2">
                            {(
                                [
                                    { scope: 'this', label: 'This event only', hint: 'Edit just this occurrence' },
                                    { scope: 'following', label: 'This & following events', hint: 'Split the series from here' },
                                    { scope: 'all', label: 'All events', hint: 'Edit the whole series' },
                                ] as const
                            ).map((opt) => (
                                <button
                                    key={opt.scope}
                                    type="button"
                                    onClick={() => {
                                        if (scopePrompt) openEdit(buildInitial(scopePrompt, opt.scope));
                                        setScopePrompt(null);
                                    }}
                                    className="flex items-center justify-between rounded-lg border border-border px-4 py-3 text-left transition-colors hover:bg-accent"
                                >
                                    <span className="text-sm font-semibold">{opt.label}</span>
                                    <span className="text-xs text-muted-foreground">{opt.hint}</span>
                                </button>
                            ))}
                        </div>
                    </DialogContent>
                </Dialog>

                {detail ? (
                    <CalendarDetailPopover
                        detail={detail}
                        canManage={can.manage}
                        onClose={() => setDetail(null)}
                        onEdit={() => {
                            setDetail(null);
                            if (clickedInfoRef.current) editFromInfo(clickedInfoRef.current);
                        }}
                        onDuplicate={() => {
                            setDetail(null);
                            if (clickedInfoRef.current) duplicateFromInfo(clickedInfoRef.current);
                        }}
                        onDelete={() => {
                            setDetail(null);
                            if (clickedInfoRef.current) deleteFromInfo(clickedInfoRef.current);
                        }}
                        onDeepLink={(href) => {
                            setDetail(null);
                            router.visit(href);
                        }}
                    />
                ) : null}

                {ctxMenu ? <ShiftContextMenu ctx={ctxMenu} onClose={() => setCtxMenu(null)} /> : null}

                {quickAdd ? (
                    <QuickAddPopover
                        date={quickAdd.date}
                        x={quickAdd.x}
                        y={quickAdd.y}
                        onClose={() => {
                            setQuickAdd(null);
                            calendarRef.current?.getApi().unselect();
                        }}
                        onCreate={(title) => {
                            router.post(
                                '/hr/calendar/events',
                                {
                                    title,
                                    event_type: 'company',
                                    starts_at: `${quickAdd.date}T09:00`,
                                    ends_at: `${quickAdd.date}T10:00`,
                                    is_all_day: false,
                                },
                                {
                                    preserveScroll: true,
                                    preserveState: true,
                                    onSuccess: () => {
                                        refetch();
                                        toast.success('Event added');
                                    },
                                },
                            );
                            setQuickAdd(null);
                            calendarRef.current?.getApi().unselect();
                        }}
                        onMore={() => {
                            const d = quickAdd.date;
                            setQuickAdd(null);
                            calendarRef.current?.getApi().unselect();
                            openCreate(d);
                        }}
                    />
                ) : null}

                <CalendarYearPicker
                    open={yearPickerOpen}
                    initialYear={
                        calendarRef.current?.getApi().getDate().getFullYear() ?? new Date().getFullYear()
                    }
                    activeDate={null}
                    onClose={() => setYearPickerOpen(false)}
                    onPickMonth={(date) => {
                        setYearPickerOpen(false);
                        setTab('calendar');
                        setView('dayGridMonth');
                        calendarRef.current?.getApi().gotoDate(date);
                    }}
                    onPickDay={(date) => {
                        setYearPickerOpen(false);
                        setTab('calendar');
                        setView('timeGridDay');
                        calendarRef.current?.getApi().gotoDate(date);
                    }}
                />

                <AlertDialog open={!!deleteTarget} onOpenChange={(o) => !o && setDeleteTarget(null)}>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>Delete event?</AlertDialogTitle>
                            <AlertDialogDescription>
                                This permanently removes “{deleteTarget?.title}” from the calendar. This can't be undone.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Keep event</AlertDialogCancel>
                            <AlertDialogAction
                                onClick={confirmDelete}
                                className="bg-status-critical text-white hover:bg-status-critical/90"
                            >
                                Delete event
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
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

function QuickAddPopover({
    date,
    x,
    y,
    onClose,
    onCreate,
    onMore,
}: {
    date: string;
    x: number;
    y: number;
    onClose: () => void;
    onCreate: (title: string) => void;
    onMore: () => void;
}) {
    const [title, setTitle] = useState('');
    const ref = useRef<HTMLDivElement>(null);
    const [pos, setPos] = useState<{ left: number; top: number }>({ left: x, top: y });

    useLayoutEffect(() => {
        const el = ref.current;
        if (!el) return;
        const r = el.getBoundingClientRect();
        let left = x;
        let top = y;
        if (left + r.width > window.innerWidth - 8) left = window.innerWidth - r.width - 8;
        if (top + r.height > window.innerHeight - 8) top = window.innerHeight - r.height - 8;
        setPos({ left: Math.max(8, left), top: Math.max(8, top) });
    }, [x, y]);

    useEffect(() => {
        const onDown = (e: MouseEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node)) onClose();
        };
        window.addEventListener('mousedown', onDown);
        return () => window.removeEventListener('mousedown', onDown);
    }, [onClose]);

    const niceDate = new Date(date).toLocaleDateString('en-NZ', { weekday: 'short', day: 'numeric', month: 'short' });

    return createPortal(
        <div
            ref={ref}
            style={{ position: 'fixed', left: pos.left, top: pos.top, zIndex: 60 }}
            className="w-[280px] rounded-xl border border-border bg-popover p-3 shadow-[var(--shadow-float)]"
        >
            <div className="mb-1.5 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">
                New event · {niceDate}
            </div>
            <Input
                autoFocus
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                onKeyDown={(e) => {
                    if (e.key === 'Enter' && title.trim()) onCreate(title.trim());
                    if (e.key === 'Escape') onClose();
                }}
                placeholder="Add a title…"
                className="h-9"
            />
            <div className="mt-2.5 flex items-center justify-between">
                <button
                    type="button"
                    onClick={onMore}
                    className="text-[12px] font-semibold text-primary hover:underline"
                >
                    More options →
                </button>
                <Button size="sm" disabled={!title.trim()} onClick={() => onCreate(title.trim())}>
                    Add
                </Button>
            </div>
        </div>,
        document.body,
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

/** Per-layer source descriptor, mirroring the prototype's rail cards. */
const LAYER_SUBLABEL: Record<CalendarLayer, string> = {
    event: 'Editable here',
    leave: 'From Leave hub',
    shift: 'From Rostering',
    holiday: 'NZ statutory',
    compliance: 'Cert expiries',
    milestone: 'Birthdays, anniv.',
};

/**
 * The persistent left "LAYERS" rail — one grid replacing four calendars. Each
 * source is a toggle card (swatch-tinted when on) with its origin sublabel and a
 * live count; a read-only explainer notes which layers deep-link to their hub.
 */
function LayerRail({
    activeLayers,
    counts,
    onToggle,
}: {
    activeLayers: CalendarLayer[];
    counts: Record<string, number>;
    onToggle: (l: CalendarLayer) => void;
}) {
    return (
        <aside className="w-full flex-none rounded-2xl border border-border bg-card p-3 lg:sticky lg:top-4 lg:w-[240px]">
            <div className="mb-1 flex items-center justify-between px-1">
                <span className="text-[11px] font-bold uppercase tracking-[0.1em] text-muted-foreground">
                    Layers
                </span>
                <Layers className="h-3.5 w-3.5 text-muted-foreground" />
            </div>
            <p className="mb-2.5 px-1 text-[11.5px] leading-snug text-muted-foreground">
                One grid replacing four calendars. Toggle a source on or off.
            </p>

            <div className="flex flex-col gap-1.5">
                {LAYER_DISPLAY_ORDER.map((layer) => {
                    const meta = LAYER_META[layer];
                    const active = activeLayers.includes(layer);
                    return (
                        <button
                            key={layer}
                            type="button"
                            onClick={() => onToggle(layer)}
                            aria-pressed={active}
                            style={
                                active
                                    ? {
                                          background: `color-mix(in oklch, var(--${meta.color}) 12%, transparent)`,
                                          borderColor: `color-mix(in oklch, var(--${meta.color}) 45%, transparent)`,
                                      }
                                    : undefined
                            }
                            className={cn(
                                'flex items-center gap-2.5 rounded-xl border px-2.5 py-2 text-left transition-colors',
                                active ? '' : 'border-transparent hover:bg-muted/50',
                            )}
                        >
                            <input
                                type="checkbox"
                                checked={active}
                                readOnly
                                className="pointer-events-none rounded border-border"
                            />
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-[12.5px] font-semibold">{meta.label}</span>
                                <span className="block text-[10.5px] text-muted-foreground">{LAYER_SUBLABEL[layer]}</span>
                            </span>
                            <span className="text-[11px] font-bold tabular-nums text-muted-foreground">
                                {counts[layer] ?? 0}
                            </span>
                        </button>
                    );
                })}
            </div>

            <div className="mt-3 border-t border-border pt-2.5">
                <p className="px-1 text-[10px] font-bold uppercase tracking-[0.08em] text-muted-foreground">
                    Read-only layers
                </p>
                <p className="mt-1 px-1 text-[11px] leading-snug text-muted-foreground">
                    Leave, shifts &amp; renewals are view-only here — click one to open it in its home hub. Only HR events are editable on this page.
                </p>
            </div>
        </aside>
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
            {LAYER_DISPLAY_ORDER.filter((l) => activeLayers.includes(l)).map((layer) => {
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
