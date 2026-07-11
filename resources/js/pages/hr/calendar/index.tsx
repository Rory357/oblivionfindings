/* eslint-disable no-restricted-syntax -- The calendar surface is a bespoke,
 * design-prototype-faithful hub: the underline tab strip, segmented view switch,
 * layer panel, toolbar selects and grid views are intentional native
 * <button>/<div>/<select> layout cases, not shadcn primitives. Colours stay
 * token-based throughout (no raw hex). */
import PageShell from '@/components/page-shell';
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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { CalendarHero, type UpNextEntry } from '@/components/hr/calendar/calendar-hero';
import {
    EventWizardDialog,
    type CalendarEventInitial,
    type EventCategoryOption,
} from '@/components/hr/calendar/event-wizard-dialog';
import { ICalSubscribeDialog } from '@/components/hr/calendar/ical-subscribe-dialog';
import { CalendarMonthGrid } from '@/components/hr/calendar/calendar-month-grid';
import { CalendarTimeGrid } from '@/components/hr/calendar/calendar-time-grid';
import { CalendarAgenda } from '@/components/hr/calendar/calendar-agenda';
import { CalendarRenewals } from '@/components/hr/calendar/calendar-renewals';
import {
    CalendarDetailPopover,
    type EventDetail,
} from '@/components/hr/calendar/calendar-detail-popover';
import { CalendarYearPicker } from '@/components/hr/calendar/calendar-year-picker';
import {
    addDays,
    colorVar,
    dayStart,
    fmtLong,
    layerLabel,
    secondaryFor,
    startOfWeek,
} from '@/components/hr/calendar/calendar-render';
import { type PersonOption } from '@/components/hr/people-picker';
import {
    ShiftContextMenu,
    type ShiftCtxState,
} from '@/components/rostering/shift-context-menu';
import {
    LAYER_DISPLAY_ORDER,
    DEFAULT_ACTIVE_LAYERS,
    LAYER_META,
    CALENDAR_LAYERS,
    type CalendarLayer,
    type CalendarLayerFeed,
} from '@/lib/calendar/layer-feed';
import AppLayout from '@/layouts/app-layout';
import type { EventClickArg } from '@fullcalendar/core';
import { Head, router } from '@inertiajs/react';
import {
    CalendarDays,
    Archive,
    ArchiveRestore,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Copy,
    ExternalLink,
    Eye,
    Layers as LayersIcon,
    Pencil,
    Pin,
    Plus,
    Search,
    Star,
    User as UserIcon,
    Users,
} from 'lucide-react';
import { toast } from 'sonner';
import {
    type CSSProperties,
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
type CalView = 'month' | 'week' | 'day';
type CalTab = 'calendar' | 'agenda' | 'renewals';

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
    archivedEvents: ArchivedEvent[];
    stats: HeroStats;
    upNext: UpNextEntry[];
    ical: { url: string | null };
    can: { manage: boolean; manageRecurring: boolean; seeSensitive: boolean };
}

type ArchivedEvent = {
    id: number;
    title: string;
    starts_at: string | null;
    archived_at: string | null;
    archived_by: string | null;
    archive_reason: string | null;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Calendar', href: '/hr/calendar' },
];

const LAYERS_KEY = 'hrCalendar.layers';
const PANEL_KEY = 'hrCalendar.panelOpen';
const PINNED_KEY = 'hrCalendar.pinned';
const DEFAULT_TAB_KEY = 'hrCalendar.defaultTab';

const PAGE_STYLE_ID = 'hr-calendar-layer-styles';
const PAGE_STYLES = `
.hrcal-add { opacity: .32; transition: opacity .15s ease, background .15s ease; }
.hrcal-add:hover { opacity: 1; background: var(--muted); }
.hrcal-agenda-row { transition: transform .12s ease, box-shadow .12s ease; }
.hrcal-agenda-row:hover { transform: translateY(-1px); box-shadow: 0 6px 18px -8px rgba(0,0,0,.18); }
.hrcal-renewal-row:hover { background: var(--muted); }
.hrcal-tab-hint { color: var(--muted-foreground); }
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

function load<T>(key: string, fallback: T): T {
    if (typeof window === 'undefined') return fallback;
    try {
        const v = window.localStorage.getItem(key);
        return v == null ? fallback : (JSON.parse(v) as T);
    } catch {
        return fallback;
    }
}

function readInitialLayers(): CalendarLayer[] {
    if (typeof window === 'undefined') return [...DEFAULT_ACTIVE_LAYERS];
    const fromUrl = new URLSearchParams(window.location.search).get('layers');
    const source = fromUrl ?? window.localStorage.getItem(LAYERS_KEY);
    if (!source) return [...DEFAULT_ACTIVE_LAYERS];
    const parsed = source
        .split(',')
        .filter((l): l is CalendarLayer => (CALENDAR_LAYERS as readonly string[]).includes(l));
    return parsed.length ? parsed : [...DEFAULT_ACTIVE_LAYERS];
}

function rangeFor(view: CalView, cursor: Date): { from: Date; to: Date } {
    if (view === 'month') {
        const first = new Date(cursor.getFullYear(), cursor.getMonth(), 1);
        const gs = startOfWeek(first);
        return { from: gs, to: addDays(gs, 41) };
    }
    if (view === 'week') {
        const gs = startOfWeek(cursor);
        return { from: gs, to: addDays(gs, 6) };
    }
    const ds = dayStart(cursor);
    return { from: ds, to: ds };
}

function iso(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

export default function CalendarIndex({
    sites,
    departments,
    teams,
    categories,
    staff,
    archivedEvents,
    stats,
    upNext,
    ical,
    can,
}: Props) {
    useLayerStyles();
    const today = useMemo(() => new Date(), []);

    const [tab, setTab] = useState<CalTab>(() => load<CalTab>(DEFAULT_TAB_KEY, 'calendar'));
    const [view, setView] = useState<CalView>('month');
    const [cursor, setCursor] = useState<Date>(() => new Date());
    const [activeLayers, setActiveLayers] = useState<CalendarLayer[]>(readInitialLayers);
    const [siteFilter, setSiteFilter] = useState('all');
    const [deptFilter, setDeptFilter] = useState('all');
    const [teamFilter, setTeamFilter] = useState('all');
    const [search, setSearch] = useState('');
    const [panelOpen, setPanelOpen] = useState<boolean>(() => load(PANEL_KEY, true));
    const [pinned, setPinned] = useState<Record<string, boolean>>(() =>
        load(PINNED_KEY, { calendar: false, agenda: false, renewals: false }),
    );

    const [events, setEvents] = useState<CalendarLayerFeed[]>([]);
    const [agendaEvents, setAgendaEvents] = useState<CalendarLayerFeed[] | null>(null);
    const [renewals, setRenewals] = useState<CalendarLayerFeed[] | null>(null);
    const [counts, setCounts] = useState<Record<string, number>>({});
    const [feedError, setFeedError] = useState(false);
    const [loading, setLoading] = useState(false);

    const [wizardOpen, setWizardOpen] = useState(false);
    const [editingEvent, setEditingEvent] = useState<CalendarEventInitial | null>(null);
    const [createDate, setCreateDate] = useState<string | null>(null);
    const [subscribeOpen, setSubscribeOpen] = useState(false);
    const [archiveHistoryOpen, setArchiveHistoryOpen] = useState(false);
    const [scopePrompt, setScopePrompt] = useState<EventClickArg | null>(null);
    const [detail, setDetail] = useState<EventDetail | null>(null);
    const [ctxMenu, setCtxMenu] = useState<ShiftCtxState | null>(null);
    const [quickAdd, setQuickAdd] = useState<{ date: string; x: number; y: number } | null>(null);
    const [hover, setHover] = useState<{ e: CalendarLayerFeed; x: number; y: number } | null>(null);
    const [yearPickerOpen, setYearPickerOpen] = useState(false);
    const [archiveTarget, setArchiveTarget] = useState<{ id: number; title: string } | null>(null);
    const [dayDetail, setDayDetail] = useState<{ label: string; events: CalendarLayerFeed[] } | null>(null);

    const clickedInfoRef = useRef<EventClickArg | null>(null);
    const searchRef = useRef<HTMLInputElement>(null);
    const filtersRef = useRef({ activeLayers, siteFilter, deptFilter, teamFilter });
    useEffect(() => {
        filtersRef.current = { activeLayers, siteFilter, deptFilter, teamFilter };
    });

    // Persist layer choice to localStorage + ?layers=
    useEffect(() => {
        window.localStorage.setItem(LAYERS_KEY, activeLayers.join(','));
        const url = new URL(window.location.href);
        url.searchParams.set('layers', activeLayers.join(','));
        window.history.replaceState(window.history.state, '', url.toString());
    }, [activeLayers]);
    useEffect(() => {
        window.localStorage.setItem(PANEL_KEY, JSON.stringify(panelOpen));
    }, [panelOpen]);

    const buildFeedUrl = useCallback((from: string, to: string, layers: string) => {
        const { siteFilter: s, deptFilter: d, teamFilter: t } = filtersRef.current;
        const params = new URLSearchParams({ from, to, layers });
        if (s !== 'all') params.set('site', s);
        if (d !== 'all') params.set('department', d);
        if (t !== 'all') params.set('team', t);
        return `/hr/calendar/feed?${params.toString()}`;
    }, []);

    const fetchJson = useCallback(
        async (url: string): Promise<CalendarLayerFeed[]> => {
            const res = await fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!res.ok) throw new Error(String(res.status));
            const data: { events: CalendarLayerFeed[] } = await res.json();
            return data.events;
        },
        [],
    );

    const fetchRange = useCallback(async () => {
        const { activeLayers: layers } = filtersRef.current;
        if (layers.length === 0) {
            setEvents([]);
            setCounts({});
            return;
        }
        const { from, to } = rangeFor(view, cursor);
        setLoading(true);
        try {
            const evs = await fetchJson(buildFeedUrl(iso(from), iso(to), layers.join(',')));
            setFeedError(false);
            const tally: Record<string, number> = {};
            for (const e of evs) tally[e.layer] = (tally[e.layer] ?? 0) + 1;
            setCounts(tally);
            setEvents(evs);
        } catch {
            setFeedError(true);
        } finally {
            setLoading(false);
        }
    }, [view, cursor, buildFeedUrl, fetchJson]);

    const fetchAgenda = useCallback(async () => {
        const { activeLayers: layers } = filtersRef.current;
        if (layers.length === 0) {
            setAgendaEvents([]);
            return;
        }
        const from = iso(today);
        const to = iso(addDays(today, 30));
        try {
            setAgendaEvents(await fetchJson(buildFeedUrl(from, to, layers.join(','))));
        } catch {
            setAgendaEvents([]);
        }
    }, [today, buildFeedUrl, fetchJson]);

    const fetchRenewals = useCallback(async () => {
        const from = iso(today);
        const to = iso(addDays(today, 90));
        try {
            const evs = await fetchJson(`/hr/calendar/feed?from=${from}&to=${to}&layers=compliance`);
            setRenewals(evs.sort((a, b) => a.start.localeCompare(b.start)));
        } catch {
            setRenewals([]);
        }
    }, [today, fetchJson]);

    // Calendar tab: (re)fetch the visible range on view / period / filter change.
    useEffect(() => {
        if (tab !== 'calendar') return;
        void fetchRange();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [tab, view, cursor, activeLayers, siteFilter, deptFilter, teamFilter]);

    // Agenda tab: fetch the next 30 days.
    useEffect(() => {
        if (tab !== 'agenda') return;
        void fetchAgenda();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [tab, activeLayers, siteFilter, deptFilter, teamFilter]);

    // Renewals tab: fetch compliance once on first open.
    useEffect(() => {
        if (tab !== 'renewals' || renewals !== null) return;
        void fetchRenewals();
    }, [tab, renewals, fetchRenewals]);

    const refetch = useCallback(() => {
        void fetchRange();
        void fetchAgenda();
    }, [fetchRange, fetchAgenda]);

    const go = useCallback(
        (dir: number) => {
            setCursor((c) => {
                if (view === 'month') return new Date(c.getFullYear(), c.getMonth() + dir, 1);
                if (view === 'week') return addDays(c, 7 * dir);
                return addDays(c, dir);
            });
        },
        [view],
    );

    // Keyboard: / search · n new · t today · 1-4 views · ←/→ period · Esc.
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            const el = e.target as HTMLElement | null;
            const typing =
                el?.tagName === 'INPUT' || el?.tagName === 'TEXTAREA' || el?.isContentEditable;
            if (e.key === 'Escape') {
                setHover(null);
                setDetail(null);
                setCtxMenu(null);
                setQuickAdd(null);
                setYearPickerOpen(false);
                return;
            }
            if (typing) return;
            switch (e.key) {
                case '/':
                    e.preventDefault();
                    searchRef.current?.focus();
                    break;
                case 'n':
                    if (can.manage) openCreate();
                    break;
                case 't':
                    setCursor(new Date());
                    break;
                case '1':
                    setTab('calendar');
                    setView('month');
                    break;
                case '2':
                    setTab('calendar');
                    setView('week');
                    break;
                case '3':
                    setTab('calendar');
                    setView('day');
                    break;
                case '4':
                    setTab('agenda');
                    break;
                case 'ArrowLeft':
                    if (tab === 'calendar') go(-1);
                    break;
                case 'ArrowRight':
                    if (tab === 'calendar') go(1);
                    break;
                default:
                    break;
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [tab, can.manage, go]);

    const toggleLayer = (layer: CalendarLayer) => {
        setActiveLayers((prev) =>
            prev.includes(layer) ? prev.filter((l) => l !== layer) : [...prev, layer],
        );
    };
    const ensureLayer = (layer: CalendarLayer) => {
        setActiveLayers((prev) => (prev.includes(layer) ? prev : [...prev, layer]));
    };

    const openCreate = (date?: string | null) => {
        setEditingEvent(null);
        setCreateDate(date ?? null);
        setWizardOpen(true);
    };

    /* ── feed → EventClickArg adapter (shared with the wizard/popover wiring) ── */
    const feedToInfo = (e: CalendarLayerFeed, x = 200, y = 200): EventClickArg =>
        ({
            event: {
                id: e.id,
                title: e.title,
                startStr: e.start,
                endStr: e.end,
                allDay: e.allDay,
                extendedProps: { ...e.extendedProps, layer: e.layer, deepLink: e.deepLink },
            },
            jsEvent: { clientX: x, clientY: y } as MouseEvent,
        }) as unknown as EventClickArg;

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
            audience_type: (props.audienceType as 'org' | 'site' | 'department' | 'team' | 'people') ?? 'org',
            audience_team: (props.audienceRef as string) ?? null,
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

    const handleEventClick = (info: EventClickArg) => {
        clickedInfoRef.current = info;
        const e = info.jsEvent as MouseEvent;
        setHover(null);
        setCtxMenu(null);
        setDetail(detailFromInfo(info, e?.clientX ?? 200, e?.clientY ?? 200));
    };

    const editFromInfo = (info: EventClickArg) => {
        const props = info.event.extendedProps as Record<string, unknown>;
        if (props.layer !== 'event' || !can.manage) {
            if (props.deepLink) router.visit(props.deepLink as string);
            return;
        }
        if (props.rrule && can.manageRecurring) setScopePrompt(info);
        else openEdit(buildInitial(info, 'all'));
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

    const archiveFromInfo = (info: EventClickArg) => {
        const id = Number((info.event.extendedProps as Record<string, unknown>).eventId);
        if (id) setArchiveTarget({ id, title: info.event.title });
    };

    const confirmArchive = () => {
        if (!archiveTarget) return;
        router.delete(`/hr/calendar/events/${archiveTarget.id}`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => refetch(),
        });
        setArchiveTarget(null);
    };

    const buildEntryMenu = (e: CalendarLayerFeed, x: number, y: number) => {
        const info = feedToInfo(e, x, y);
        const meta = LAYER_META[e.layer];
        const items =
            e.layer === 'event' && can.manage
                ? [
                      { icon: <Eye className="h-3.5 w-3.5" />, label: 'Open', onClick: () => handleEventClick(info) },
                      { icon: <Pencil className="h-3.5 w-3.5" />, label: 'Edit…', kbd: '↵', onClick: () => editFromInfo(info) },
                      { icon: <Copy className="h-3.5 w-3.5" />, label: 'Duplicate', onClick: () => duplicateFromInfo(info) },
                      { sep: true as const },
                      { icon: <Archive className="h-3.5 w-3.5" />, label: 'Archive', onClick: () => archiveFromInfo(info) },
                  ]
                : [
                      { icon: <Eye className="h-3.5 w-3.5" />, label: 'Open detail', onClick: () => handleEventClick(info) },
                      ...(e.deepLink
                          ? [{ icon: <ExternalLink className="h-3.5 w-3.5" />, label: 'Open in ' + meta.label.split(' ')[0], onClick: () => router.visit(e.deepLink as string) }]
                          : []),
                  ];
        setDetail(null);
        setCtxMenu({ x, y, tag: meta.label.split(' ')[0].toUpperCase().slice(0, 4), meta: e.title, items });
    };

    const openDayMenu = (date: Date, x: number, y: number) => {
        const items = [
            ...(can.manage
                ? [{ icon: <Plus className="h-3.5 w-3.5" />, label: 'New event here', kbd: 'N', onClick: () => openCreate(iso(date)) }]
                : []),
            { icon: <CalendarDays className="h-3.5 w-3.5" />, label: 'View this day', onClick: () => { setView('day'); setCursor(date); } },
            { icon: <UserIcon className="h-3.5 w-3.5" />, label: "Show who's off", onClick: () => { ensureLayer('leave'); toast.success('Leave layer focused'); } },
            { icon: <Users className="h-3.5 w-3.5" />, label: 'Show coverage', onClick: () => { ensureLayer('shift'); toast.success('Shifts layer focused'); } },
        ];
        setDetail(null);
        setCtxMenu({ x, y, tag: 'DAY', meta: fmtLong(date), items });
    };

    const openTabMenu = (key: CalTab, x: number, y: number) => {
        const items = [
            { icon: <Star className="h-3.5 w-3.5" />, label: 'Set as default view', onClick: () => { window.localStorage.setItem(DEFAULT_TAB_KEY, JSON.stringify(key)); toast.success('Default tab set'); } },
            { icon: <Eye className="h-3.5 w-3.5" />, label: 'Open', onClick: () => setTab(key) },
            {
                icon: <Pin className="h-3.5 w-3.5" />,
                label: pinned[key] ? 'Unpin' : 'Pin',
                onClick: () =>
                    setPinned((p) => {
                        const next = { ...p, [key]: !p[key] };
                        window.localStorage.setItem(PINNED_KEY, JSON.stringify(next));
                        return next;
                    }),
            },
        ];
        setCtxMenu({ x, y, tag: 'TAB', meta: 'Tab options', items });
    };

    const onUpNext = (entry: UpNextEntry) => {
        if (entry.deepLink) {
            router.visit(entry.deepLink);
            return;
        }
        const d = new Date(entry.start);
        if (!Number.isNaN(d.getTime())) {
            setTab('calendar');
            setView('month');
            setCursor(new Date(d.getFullYear(), d.getMonth(), 1));
        }
    };

    /* ── derived ── */
    const visibleEvents = useMemo(() => {
        const q = search.trim().toLowerCase();
        return q ? events.filter((e) => e.title.toLowerCase().includes(q)) : events;
    }, [events, search]);
    const visibleAgenda = useMemo(() => {
        const q = search.trim().toLowerCase();
        const base = agendaEvents ?? [];
        return q ? base.filter((e) => e.title.toLowerCase().includes(q)) : base;
    }, [agendaEvents, search]);

    const totalCount = useMemo(() => Object.values(counts).reduce((a, b) => a + b, 0), [counts]);
    const tabDefs: { key: CalTab; label: string; count: number }[] = [
        { key: 'calendar', label: 'Calendar', count: counts.event ?? 0 },
        { key: 'agenda', label: 'Agenda', count: totalCount },
        { key: 'renewals', label: 'Renewals', count: stats.renewalsSoon },
    ];

    const periodTitle = useMemo(() => {
        if (view === 'month')
            return cursor.toLocaleDateString('en-NZ', { month: 'long', year: 'numeric' });
        if (view === 'week') {
            const ws = startOfWeek(cursor);
            const we = addDays(ws, 6);
            return `${ws.getDate()} ${ws.toLocaleDateString('en-NZ', { month: 'short' })} – ${we.getDate()} ${we.toLocaleDateString('en-NZ', { month: 'short' })}`;
        }
        return fmtLong(cursor);
    }, [view, cursor]);

    const gridDays = useMemo(() => {
        if (view === 'day') return [dayStart(cursor)];
        const ws = startOfWeek(cursor);
        return Array.from({ length: 7 }, (_, i) => addDays(ws, i));
    }, [view, cursor]);

    const entryHandlers = {
        onEntryClick: (e: CalendarLayerFeed, x: number, y: number) => handleEventClick(feedToInfo(e, x, y)),
        onEntryCtx: (e: CalendarLayerFeed, x: number, y: number) => buildEntryMenu(e, x, y),
        onEntryHover: (e: CalendarLayerFeed, x: number, y: number) => setHover({ e, x, y }),
        onEntryHoverEnd: () => setHover(null),
    };

    const showShiftCoverage = activeLayers.includes('shift');

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
                            ? [{ key: 'gaps', label: `${stats.coverageGapsToday} coverage gap${stats.coverageGapsToday === 1 ? '' : 's'} today`, onClick: () => { ensureLayer('shift'); setTab('calendar'); setView('month'); setCursor(new Date()); } }]
                            : []),
                        ...(stats.renewalsSoon > 0
                            ? [{ key: 'renewals', label: `${stats.renewalsSoon} renewal${stats.renewalsSoon === 1 ? '' : 's'} due soon`, onClick: () => setTab('renewals') }]
                            : []),
                    ]}
                    handlers={{
                        onNewEvent: can.manage ? () => openCreate() : undefined,
                        onToday: () => { setTab('calendar'); setCursor(new Date()); },
                        onSubscribe: () => setSubscribeOpen(true),
                        onStatEvents: () => { setTab('calendar'); setView('week'); setCursor(new Date()); },
                        onStatLeave: () => { ensureLayer('leave'); setTab('agenda'); },
                        onStatGaps: () => { setTab('calendar'); ensureLayer('shift'); setCursor(new Date()); },
                        onStatRenewals: () => setTab('renewals'),
                        onUpNext,
                    }}
                />

                {/* ── tab strip ── */}
                <div className="mt-[22px] flex items-end gap-1 border-b border-border">
                    {tabDefs.map((t) => {
                        const active = tab === t.key;
                        return (
                            <button
                                key={t.key}
                                type="button"
                                onClick={() => setTab(t.key)}
                                onContextMenu={(e) => {
                                    e.preventDefault();
                                    openTabMenu(t.key, e.clientX, e.clientY);
                                }}
                                className="relative -mb-px inline-flex items-center gap-2 border-b-[2.5px] bg-transparent px-[15px] pb-3 pt-[11px] text-[13.5px] font-semibold"
                                style={{
                                    color: active ? 'var(--primary)' : 'var(--muted-foreground)',
                                    borderBottomColor: active ? 'var(--primary)' : 'transparent',
                                }}
                            >
                                {t.label}
                                {pinned[t.key] ? <Star className="h-3 w-3 fill-current opacity-70" /> : null}
                                <span
                                    className="inline-grid h-[19px] min-w-[19px] place-items-center rounded-full px-[5px] text-[11px] font-bold"
                                    style={{
                                        background: active ? 'var(--accent)' : 'var(--muted)',
                                        color: active ? 'var(--primary)' : 'var(--muted-foreground)',
                                    }}
                                >
                                    {t.count}
                                </span>
                            </button>
                        );
                    })}
                    <span className="hrcal-tab-hint ml-auto hidden pb-2 text-[11px] lg:inline">
                        Right-click a tab to set default / pin
                    </span>
                </div>

                {/* ── toolbar (calendar tab only) ── */}
                {tab === 'calendar' ? (
                    <div className="mt-4 flex flex-wrap items-center gap-3">
                        <div className="flex items-center gap-1">
                            <ToolbarIconButton ariaLabel="Previous" onClick={() => go(-1)}>
                                <ChevronLeft className="h-[17px] w-[17px]" />
                            </ToolbarIconButton>
                            <ToolbarIconButton ariaLabel="Next" onClick={() => go(1)}>
                                <ChevronRight className="h-[17px] w-[17px]" />
                            </ToolbarIconButton>
                            <button
                                type="button"
                                onClick={() => setCursor(new Date())}
                                className="ml-1 h-[34px] rounded-[9px] border border-border bg-card px-[13px] text-[12.5px] font-semibold text-foreground hover:bg-muted"
                            >
                                Today
                            </button>
                        </div>
                        <button
                            type="button"
                            onClick={() => setYearPickerOpen(true)}
                            className="inline-flex min-w-[180px] items-center gap-[7px] rounded-[9px] bg-transparent px-2 py-1 text-[19px] font-bold tracking-tight text-foreground hover:bg-muted"
                        >
                            {periodTitle}
                            <ChevronDown className="h-[15px] w-[15px] opacity-50" />
                        </button>

                        <div className="ml-auto flex flex-wrap items-center gap-[10px]">
                            {can.manage ? (
                                <button
                                    type="button"
                                    onClick={() => setArchiveHistoryOpen(true)}
                                    className="inline-flex h-[34px] items-center gap-1.5 rounded-[9px] border border-border bg-card px-3 text-[12.5px] font-semibold text-foreground hover:bg-muted"
                                >
                                    <ArchiveRestore className="h-4 w-4" />
                                    Archived events
                                    {archivedEvents.length > 0 ? (
                                        <span className="rounded-full bg-muted px-1.5 text-[11px] tabular-nums text-muted-foreground">
                                            {archivedEvents.length}
                                        </span>
                                    ) : null}
                                </button>
                            ) : null}
                            <div className="relative">
                                <Search className="pointer-events-none absolute left-[10px] top-1/2 h-[15px] w-[15px] -translate-y-1/2 text-muted-foreground" />
                                <input
                                    ref={searchRef}
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Search events…"
                                    className="h-[34px] w-[170px] rounded-[9px] border border-border bg-card pl-8 pr-[10px] text-[13px] text-foreground outline-none"
                                />
                            </div>
                            <NativeSelect value={siteFilter} onChange={setSiteFilter}>
                                <option value="all">All sites</option>
                                {sites.map((s) => (
                                    <option key={s.id} value={String(s.id)}>{s.name}</option>
                                ))}
                            </NativeSelect>
                            {departments.length > 0 ? (
                                <NativeSelect value={deptFilter} onChange={setDeptFilter}>
                                    <option value="all">All departments</option>
                                    {departments.map((d) => (
                                        <option key={d.id} value={String(d.id)}>{d.name}</option>
                                    ))}
                                </NativeSelect>
                            ) : null}
                            {teams.length > 0 ? (
                                <NativeSelect value={teamFilter} onChange={setTeamFilter}>
                                    <option value="all">All teams</option>
                                    {teams.map((t) => (
                                        <option key={t} value={t}>{t}</option>
                                    ))}
                                </NativeSelect>
                            ) : null}
                            <div className="inline-flex gap-[2px] rounded-[10px] bg-muted p-[3px]">
                                {(['month', 'week', 'day'] as CalView[]).map((v) => (
                                    <button
                                        key={v}
                                        type="button"
                                        onClick={() => setView(v)}
                                        className="rounded-lg px-[13px] py-[6px] text-[12.5px] font-semibold capitalize"
                                        style={
                                            view === v
                                                ? { background: 'var(--card)', color: 'var(--foreground)', boxShadow: '0 1px 2px rgba(0,0,0,.08)' }
                                                : { background: 'transparent', color: 'var(--muted-foreground)' }
                                        }
                                    >
                                        {v}
                                    </button>
                                ))}
                            </div>
                        </div>
                    </div>
                ) : null}

                {/* ── body ── */}
                <div className="mt-4 flex items-start gap-4">
                    {tab === 'calendar' && panelOpen ? (
                        <LayerPanel
                            activeLayers={activeLayers}
                            counts={counts}
                            onToggle={toggleLayer}
                            onHide={() => setPanelOpen(false)}
                        />
                    ) : null}

                    <div className="min-w-0 flex-1">
                        {tab === 'calendar' ? (
                            <Legend
                                activeLayers={activeLayers}
                                panelOpen={panelOpen}
                                onShowPanel={() => setPanelOpen(true)}
                            />
                        ) : null}

                        {feedError && tab === 'calendar' ? (
                            <div className="rounded-2xl border border-border bg-card p-6 text-center text-sm text-muted-foreground">
                                The calendar feed failed to load.{' '}
                                <button type="button" onClick={() => { setFeedError(false); void fetchRange(); }} className="font-semibold text-primary underline">
                                    Retry
                                </button>
                            </div>
                        ) : tab === 'calendar' && view === 'month' ? (
                            <CalendarMonthGrid
                                events={visibleEvents}
                                cursor={cursor}
                                today={today}
                                showCoverage={showShiftCoverage}
                                loading={loading}
                                handlers={{
                                    ...entryHandlers,
                                    onDayNum: (d) => { setView('day'); setCursor(d); },
                                    onDayMenu: openDayMenu,
                                    onAdd: (d, x, y) => setQuickAdd({ date: iso(d), x, y }),
                                    onMore: (d) => { setView('day'); setCursor(d); },
                                }}
                            />
                        ) : tab === 'calendar' ? (
                            <CalendarTimeGrid
                                days={gridDays}
                                events={visibleEvents}
                                today={today}
                                handlers={{
                                    ...entryHandlers,
                                    onCreate: (d, _hour, x, y) => setQuickAdd({ date: iso(d), x, y }),
                                }}
                            />
                        ) : tab === 'agenda' ? (
                            <CalendarAgenda
                                events={visibleAgenda}
                                today={today}
                                handlers={{
                                    onEntryClick: (e, x, y) => handleEventClick(feedToInfo(e, x, y)),
                                    onEntryCtx: (e, x, y) => buildEntryMenu(e, x, y),
                                    onDeepLink: (href) => router.visit(href),
                                }}
                            />
                        ) : (
                            <CalendarRenewals
                                renewals={renewals}
                                today={today}
                                onOpen={(href) => router.visit(href)}
                            />
                        )}
                    </div>
                </div>

                {/* ── overlays / dialogs ── */}
                {can.manage ? (
                    <EventWizardDialog
                        open={wizardOpen}
                        onClose={() => setWizardOpen(false)}
                        onSaved={refetch}
                        sites={sites}
                        departments={departments}
                        teams={teams}
                        categories={categories}
                        staff={staff}
                        initial={editingEvent}
                        defaultDate={createDate}
                    />
                ) : null}

                <ICalSubscribeDialog open={subscribeOpen} onClose={() => setSubscribeOpen(false)} url={ical.url} />

                <Dialog open={archiveHistoryOpen} onOpenChange={setArchiveHistoryOpen}>
                    <DialogContent className="max-w-lg">
                        <DialogHeader>
                            <DialogTitle>Archived events</DialogTitle>
                            <DialogDescription>
                                Archived events stay out of active calendars while their attendees, reminders, and attachments are retained.
                            </DialogDescription>
                        </DialogHeader>
                        {archivedEvents.length > 0 ? (
                            <ul className="max-h-[55vh] space-y-2 overflow-y-auto">
                                {archivedEvents.map((event) => (
                                    <li key={event.id} className="flex items-start justify-between gap-3 rounded-xl border border-border p-3">
                                        <span className="min-w-0">
                                            <span className="block truncate text-sm font-semibold text-foreground">{event.title}</span>
                                            <span className="mt-0.5 block text-xs text-muted-foreground">
                                                Archived {event.archived_at ? fmtLong(new Date(event.archived_at)) : 'previously'}
                                                {event.archived_by ? ` by ${event.archived_by}` : ''}
                                            </span>
                                            {event.archive_reason ? (
                                                <span className="mt-1 block text-xs text-muted-foreground">Reason: {event.archive_reason}</span>
                                            ) : null}
                                        </span>
                                        <button
                                            type="button"
                                            onClick={() => router.post(`/hr/calendar/events/${event.id}/restore`, {}, {
                                                preserveScroll: true,
                                                onSuccess: () => {
                                                    toast.success('Event restored');
                                                    setArchiveHistoryOpen(false);
                                                    refetch();
                                                },
                                            })}
                                            className="inline-flex min-h-11 shrink-0 items-center gap-1.5 rounded-lg border border-border px-3 text-xs font-semibold text-primary hover:bg-muted"
                                        >
                                            <ArchiveRestore className="h-3.5 w-3.5" /> Restore
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="rounded-xl border border-dashed border-border p-6 text-center text-sm text-muted-foreground">
                                No archived events.
                            </p>
                        )}
                    </DialogContent>
                </Dialog>

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
                        onEdit={() => { setDetail(null); if (clickedInfoRef.current) editFromInfo(clickedInfoRef.current); }}
                        onDuplicate={() => { setDetail(null); if (clickedInfoRef.current) duplicateFromInfo(clickedInfoRef.current); }}
                        onArchive={() => { setDetail(null); if (clickedInfoRef.current) archiveFromInfo(clickedInfoRef.current); }}
                        onDeepLink={(href) => { setDetail(null); router.visit(href); }}
                    />
                ) : null}

                {ctxMenu ? <ShiftContextMenu ctx={ctxMenu} onClose={() => setCtxMenu(null)} /> : null}

                {hover ? <HoverPreview e={hover.e} x={hover.x} y={hover.y} /> : null}

                {quickAdd ? (
                    <QuickAddPopover
                        date={quickAdd.date}
                        x={quickAdd.x}
                        y={quickAdd.y}
                        onClose={() => setQuickAdd(null)}
                        onCreate={(title) => {
                            router.post(
                                '/hr/calendar/events',
                                { title, event_type: 'company', starts_at: `${quickAdd.date}T09:00`, ends_at: `${quickAdd.date}T10:00`, is_all_day: false },
                                { preserveScroll: true, preserveState: true, onSuccess: () => { refetch(); toast.success('Event added'); } },
                            );
                            setQuickAdd(null);
                        }}
                        onMore={() => { const d = quickAdd.date; setQuickAdd(null); openCreate(d); }}
                    />
                ) : null}

                <CalendarYearPicker
                    open={yearPickerOpen}
                    initialYear={cursor.getFullYear()}
                    activeDate={null}
                    onClose={() => setYearPickerOpen(false)}
                    onPickMonth={(date) => {
                        setYearPickerOpen(false);
                        setTab('calendar');
                        setView('month');
                        const d = new Date(`${date}T00:00:00`);
                        setCursor(new Date(d.getFullYear(), d.getMonth(), 1));
                    }}
                    onPickDay={(date) => {
                        setYearPickerOpen(false);
                        setTab('calendar');
                        setView('day');
                        setCursor(new Date(`${date}T00:00:00`));
                    }}
                />

                <Dialog open={!!dayDetail} onOpenChange={(o) => !o && setDayDetail(null)}>
                    <DialogContent className="max-w-sm">
                        <DialogHeader>
                            <DialogTitle>{dayDetail?.label}</DialogTitle>
                            <DialogDescription>
                                {dayDetail?.events.length} item{dayDetail?.events.length === 1 ? '' : 's'} on this day.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="flex max-h-[60vh] flex-col gap-1.5 overflow-y-auto">
                            {dayDetail?.events.map((e) => (
                                <button
                                    key={e.id}
                                    type="button"
                                    onClick={(ev) => { const x = ev.clientX; const y = ev.clientY; setDayDetail(null); handleEventClick(feedToInfo(e, x, y)); }}
                                    className="flex items-center gap-2.5 rounded-lg border border-border px-3 py-2 text-left transition-colors hover:bg-accent"
                                    style={{ borderLeftWidth: 3, borderLeftColor: colorVar(e) }}
                                >
                                    <span className="h-2 w-2 flex-none rounded-full" style={{ background: colorVar(e) }} />
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-[13px] font-semibold">{e.title}</span>
                                        <span className="block text-[11px] text-muted-foreground">{layerLabel(e)}</span>
                                    </span>
                                </button>
                            ))}
                        </div>
                    </DialogContent>
                </Dialog>

                <AlertDialog open={!!archiveTarget} onOpenChange={(o) => !o && setArchiveTarget(null)}>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>Archive event?</AlertDialogTitle>
                            <AlertDialogDescription>
                                Archiving “{archiveTarget?.title}” removes it from active calendars but retains attendees, reminders, and attachments. It can be restored later.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Keep event</AlertDialogCancel>
                            <AlertDialogAction onClick={confirmArchive}>
                                Archive event
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </PageShell>
        </AppLayout>
    );
}

/* ─────────────────────────── sub-components ─────────────────────────── */

function ToolbarIconButton({
    ariaLabel,
    onClick,
    children,
}: {
    ariaLabel: string;
    onClick: () => void;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            aria-label={ariaLabel}
            onClick={onClick}
            className="grid h-[34px] w-[34px] place-items-center rounded-[9px] border border-border bg-card text-foreground hover:bg-muted"
        >
            {children}
        </button>
    );
}

function NativeSelect({
    value,
    onChange,
    children,
}: {
    value: string;
    onChange: (v: string) => void;
    children: React.ReactNode;
}) {
    return (
        <select
            value={value}
            onChange={(e) => onChange(e.target.value)}
            className="h-[34px] cursor-pointer appearance-none rounded-[9px] border border-border bg-card pl-[11px] pr-[28px] text-[12.5px] font-semibold text-foreground outline-none"
            style={{
                backgroundImage:
                    "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23888' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E\")",
                backgroundRepeat: 'no-repeat',
                backgroundPosition: 'right 10px center',
            }}
        >
            {children}
        </select>
    );
}

const LAYER_SUBLABEL: Record<CalendarLayer, string> = {
    event: 'Editable here',
    leave: 'From Leave hub',
    shift: 'From Rostering',
    holiday: 'NZ statutory',
    compliance: 'Cert expiries',
    milestone: 'Birthdays, anniv.',
};

function LayerPanel({
    activeLayers,
    counts,
    onToggle,
    onHide,
}: {
    activeLayers: CalendarLayer[];
    counts: Record<string, number>;
    onToggle: (l: CalendarLayer) => void;
    onHide: () => void;
}) {
    return (
        <aside
            className="sticky top-4 w-[248px] flex-none rounded-2xl border border-border bg-card p-3.5"
            style={{ boxShadow: '0 1px 3px rgba(0,0,0,.04)' }}
        >
            <div className="mb-1 flex items-center justify-between">
                <span className="text-[11px] font-bold uppercase tracking-[0.08em] text-muted-foreground">Layers</span>
                <button
                    type="button"
                    aria-label="Hide layers"
                    onClick={onHide}
                    className="grid h-6 w-6 place-items-center rounded-[7px] text-muted-foreground hover:bg-muted"
                >
                    <ChevronLeft className="h-3.5 w-3.5" />
                </button>
            </div>
            <p className="mb-2.5 text-[11.5px] leading-snug text-muted-foreground">
                One grid replacing four calendars. Toggle a source on or off.
            </p>
            <div className="flex flex-col gap-[3px]">
                {LAYER_DISPLAY_ORDER.map((layer) => {
                    const meta = LAYER_META[layer];
                    const on = activeLayers.includes(layer);
                    const sw = `var(--${meta.color})`;
                    return (
                        <button
                            key={layer}
                            type="button"
                            onClick={() => onToggle(layer)}
                            aria-pressed={on}
                            className="flex items-center gap-2.5 rounded-[10px] px-[9px] py-2 text-left"
                            style={{ background: on ? `color-mix(in oklch, ${sw} 8%, transparent)` : 'transparent' }}
                        >
                            <span
                                className="grid h-[18px] w-[18px] flex-none place-items-center rounded-[5px]"
                                style={{ border: `1.5px solid ${on ? sw : 'var(--border)'}`, background: on ? sw : 'transparent' }}
                            >
                                {on ? (
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="var(--primary-foreground)" strokeWidth="3.2" strokeLinecap="round" strokeLinejoin="round">
                                        <path d="M20 6 9 17l-5-5" />
                                    </svg>
                                ) : null}
                            </span>
                            <span className="min-w-0 flex-1 text-left">
                                <span className="block truncate text-[12.5px] font-semibold text-foreground">{meta.label}</span>
                                <span className="block text-[11px] text-muted-foreground">{LAYER_SUBLABEL[layer]}</span>
                            </span>
                            <span className="flex-none text-[11px] font-bold tabular-nums text-muted-foreground">
                                {counts[layer] ?? 0}
                            </span>
                        </button>
                    );
                })}
            </div>
            <div className="mt-3 border-t border-border pt-[11px]">
                <div className="mb-[7px] text-[10px] font-bold uppercase tracking-[0.08em] text-muted-foreground">
                    Read-only layers
                </div>
                <p className="text-[11px] leading-relaxed text-muted-foreground">
                    Leave, shifts &amp; renewals are <strong className="text-foreground">view-only</strong> here — click one to open it in its home hub. Only HR events are editable on this page.
                </p>
            </div>
        </aside>
    );
}

function Legend({
    activeLayers,
    panelOpen,
    onShowPanel,
}: {
    activeLayers: CalendarLayer[];
    panelOpen: boolean;
    onShowPanel: () => void;
}) {
    return (
        <div className="mb-3 flex flex-wrap items-center gap-x-[14px] gap-y-1.5 px-0.5">
            {LAYER_DISPLAY_ORDER.filter((l) => activeLayers.includes(l)).map((layer) => {
                const meta = LAYER_META[layer];
                return (
                    <span key={layer} className="inline-flex items-center gap-[7px] text-[11.5px] font-semibold text-muted-foreground">
                        <span className="h-[11px] w-[11px] rounded-[3px]" style={{ background: `var(--${meta.color})` }} />
                        {meta.label}
                    </span>
                );
            })}
            <span className="inline-flex items-center gap-[7px] text-[11.5px] font-semibold" style={{ color: 'var(--status-critical)' }}>
                <span className="h-[11px] w-[11px] rounded-[3px]" style={{ background: 'var(--status-critical)' }} />
                Coverage gap
            </span>
            {!panelOpen ? (
                <button type="button" onClick={onShowPanel} className="ml-auto inline-flex items-center gap-1.5 text-[11.5px] font-semibold text-primary">
                    <LayersIcon className="h-[13px] w-[13px]" />
                    Layers
                </button>
            ) : null}
        </div>
    );
}

function HoverPreview({ e, x, y }: { e: CalendarLayerFeed; x: number; y: number }) {
    const c = colorVar(e);
    const start = new Date(e.start);
    const end = e.end ? new Date(e.end) : start;
    const when = e.allDay
        ? 'All day'
        : `${start.toLocaleTimeString('en-NZ', { hour: 'numeric', minute: '2-digit', hour12: true })} – ${end.toLocaleTimeString('en-NZ', { hour: 'numeric', minute: '2-digit', hour12: true })}`;
    const sub = secondaryFor(e);
    const meta =
        e.layer === 'event'
            ? e.extendedProps.attendeeCount
                ? `${e.extendedProps.attendeeCount} invited`
                : ''
            : e.deepLink
              ? 'Read-only · opens in its hub'
              : '';
    const style: CSSProperties = {
        position: 'fixed',
        left: Math.min(x + 14, window.innerWidth - 250),
        top: Math.min(y + 14, window.innerHeight - 130),
        zIndex: 88,
        width: 236,
        pointerEvents: 'none',
        borderRadius: 12,
        border: '1px solid var(--border)',
        background: 'var(--popover)',
        padding: '11px 13px',
        boxShadow: '0 18px 44px -14px rgba(20,10,40,.4)',
    };
    return createPortal(
        <div style={style}>
            <div className="flex items-center gap-2">
                <span className="h-[9px] w-[9px] flex-none rounded-[3px]" style={{ background: c }} />
                <span className="min-w-0 flex-1 truncate text-[12.5px] font-bold text-foreground">{e.title}</span>
            </div>
            <div className="mt-1.5 text-[11.5px] text-muted-foreground">{layerLabel(e)}</div>
            <div className="text-[11.5px] font-semibold text-foreground">{when}</div>
            {sub ? <div className="text-[11.5px] text-muted-foreground">{sub}</div> : null}
            {meta ? <div className="mt-0.5 text-[11px] text-muted-foreground">{meta}</div> : null}
        </div>,
        document.body,
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

    const niceDate = new Date(`${date}T00:00:00`).toLocaleDateString('en-NZ', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    });

    return createPortal(
        <div
            ref={ref}
            style={{ position: 'fixed', left: pos.left, top: pos.top, zIndex: 97 }}
            className="w-[300px] overflow-hidden rounded-[15px] border border-border bg-card shadow-[var(--shadow-float)]"
        >
            <div className="border-b border-border bg-muted/40 px-4 py-3">
                <div className="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">New event</div>
                <div className="mt-0.5 text-[12.5px] font-semibold text-foreground">{niceDate}</div>
            </div>
            <div className="p-3.5">
                <input
                    autoFocus
                    value={title}
                    onChange={(e) => setTitle(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' && title.trim()) onCreate(title.trim());
                        if (e.key === 'Escape') onClose();
                    }}
                    placeholder="Add a title…"
                    className="h-9 w-full rounded-lg border border-border bg-card px-3 text-sm text-foreground outline-none focus:border-primary"
                />
                <div className="mt-2.5 flex items-center justify-between">
                    <button type="button" onClick={onMore} className="text-[12px] font-semibold text-primary hover:underline">
                        More options →
                    </button>
                    <button
                        type="button"
                        disabled={!title.trim()}
                        onClick={() => onCreate(title.trim())}
                        className="rounded-lg bg-primary px-3.5 py-1.5 text-[12.5px] font-bold text-primary-foreground disabled:opacity-50"
                    >
                        Add
                    </button>
                </div>
            </div>
        </div>,
        document.body,
    );
}
