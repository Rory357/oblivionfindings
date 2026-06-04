/**
 * Shared Site Calendar experience — one component for both the global all-sites
 * roll-up (/calendar) and a single house (/sites/{site}/calendar + the profile
 * Calendar tab). Renders the redesigned hero, toolbar, source legend and the five
 * views over the unified events feed (manual events + auto-derived obligations).
 */
import { PageHero, PageLayout } from '@/components/page';
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
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Switch } from '@/components/ui/switch';
import type { SharedData } from '@/types';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { createPortal } from 'react-dom';
import {
    Bell,
    CalendarDays,
    CalendarRange,
    CalendarClock,
    Check,
    CheckCircle2,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    Clock,
    Columns3,
    Copy,
    Download,
    ExternalLink,
    Filter,
    Home,
    Layers,
    LayoutGrid,
    List,
    MapPin,
    MoreHorizontal,
    Pencil,
    Plus,
    RefreshCw,
    Repeat,
    Rows3,
    Rss,
    Search,
    Trash2,
    X,
    CalendarPlus,
    DoorOpen,
    FileText,
    HardHat,
    Leaf,
    Lock,
    Truck,
    Users,
    Wrench,
    ZapOff,
    type LucideIcon,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import {
    downloadICS,
    findConflicts,
    googleLink,
    outlookLink,
    presetToRule,
    ruleToPreset,
    ruleToText,
    toRRULE,
    type CalendarItem,
    type ColorBy,
    type RecurPreset,
} from '@/lib/calendar/recur';
import {
    AgendaView,
    Avatar,
    CalendarUIProvider,
    DayView,
    MonthView,
    SourceDot,
    StatusBadge,
    TimelineView,
    TodayRail,
    WeekView,
    WD,
    useNow,
    addDays,
    decorate,
    endOfMonth,
    fmtTime,
    fmtTimeRange,
    MO,
    sameDay,
    startOfMonth,
    startOfWeek,
    type Decorated,
    type Density,
    type SourceDef,
} from './_parts';

type SiteLite = { id: number; name: string; type: string };
export type Person = { id: number; name: string };

export interface EventTypeOption {
    key: string;
    label: string;
    color: string;
    icon?: string | null;
    requires_approval?: boolean;
    site_types?: string[] | null;
}

export interface SiteCalendarProps {
    context: 'page' | 'profile';
    scope: 'global' | 'site';
    site?: SiteLite;
    sites?: SiteLite[];
    people?: Person[];
    sources?: SourceDef[];
    eventTypes?: EventTypeOption[];
    canCreate: boolean;
    canManage?: boolean;
    canApprove?: boolean;
    feedUrl?: string | null;
    /** Authoritative cross-range hero counts (page contexts only; the profile
     *  embed receives none and falls back to the in-view derivation). */
    pendingApprovalCount?: number;
    mineCount?: number;
    overdueCount?: number;
}

type CalView = 'month' | 'week' | 'day' | 'agenda' | 'timeline';

/** Seed for the create dialog when opened from the right-click QuickAdd menu. */
type CreateSeed = { date: Date; hour?: number; eventType?: string };

/** Fallback source taxonomy (mirrors CalendarSources::all()) for embeds that
 *  don't receive server props (e.g. the Site Profile Calendar tab). */
const DEFAULT_SOURCES: SourceDef[] = [
    { key: 'event', label: 'Event', short: 'Event', group: 'manual', icon: 'CalendarDays', origin: 'Calendar' },
    { key: 'inspection', label: 'Inspection', short: 'Inspection', group: 'auto', icon: 'ClipboardList', origin: 'Inspections' },
    { key: 'compliance', label: 'Compliance & certs', short: 'Compliance', group: 'auto', icon: 'ShieldCheck', origin: 'Compliance' },
    { key: 'credential', label: 'Credential expiry', short: 'Credential', group: 'auto', icon: 'KeyRound', origin: 'Credentials vault' },
    { key: 'checklist', label: 'Checklist run', short: 'Checklist', group: 'auto', icon: 'CheckSquare', origin: 'Checklists' },
    { key: 'hazard', label: 'Hazard review', short: 'Hazard', group: 'auto', icon: 'AlertTriangle', origin: 'Hazard register' },
    { key: 'vendor', label: 'Vendor / insurance', short: 'Vendor', group: 'auto', icon: 'Wrench', origin: 'Vendors' },
    { key: 'asset', label: 'Fleet / asset', short: 'Asset', group: 'auto', icon: 'Truck', origin: 'Assets register' },
    { key: 'meal', label: 'Meal plan', short: 'Meal', group: 'auto', icon: 'Utensils', origin: 'Meal planner' },
    { key: 'damage', label: 'Damage follow-up', short: 'Damage', group: 'auto', icon: 'Hammer', origin: 'Damages' },
    { key: 'emergency', label: 'Emergency plan', short: 'Emergency', group: 'auto', icon: 'Siren', origin: 'Emergency plans' },
];

const DEFAULT_EVENT_TYPES: EventTypeOption[] = [
    { key: 'general', label: 'General Event', color: '#6366f1' },
    { key: 'maintenance', label: 'Maintenance Schedule', color: '#f59e0b', requires_approval: true },
    { key: 'site_visit', label: 'Site Visit', color: '#10b981' },
    { key: 'inspection', label: 'Inspection', color: '#8b5cf6', requires_approval: true },
    { key: 'contractor_visit', label: 'Contractor Visit', color: '#06b6d4' },
];

const VIEWS: { key: CalView; label: string; icon: typeof CalendarDays }[] = [
    { key: 'month', label: 'Month', icon: CalendarDays },
    { key: 'week', label: 'Week', icon: CalendarRange },
    { key: 'day', label: 'Day', icon: CalendarClock },
    { key: 'agenda', label: 'Agenda', icon: List },
    { key: 'timeline', label: 'Timeline', icon: Columns3 },
];

const RECUR_PRESETS: RecurPreset[] = ['none', 'DAILY', 'WEEKLY', 'FORTNIGHTLY', 'MONTHLY', 'QUARTERLY'];

/**
 * Format an instant as a `datetime-local` value (the viewer's local wall-clock,
 * which for this NZ-only app is the business timezone). The backend stores true
 * UTC and converts this wall-clock from the business timezone on write, so the
 * create→read→edit round-trip stays drift-free — do not re-apply an offset here.
 */
function toLocalInput(date: Date): string {
    const adjusted = new Date(date.getTime() - date.getTimezoneOffset() * 60_000);
    return adjusted.toISOString().slice(0, 16);
}

/* datetime-local string helpers for the split Date / Start / End fields. */
const datePart = (dt: string): string => (dt || '').slice(0, 10);
const timePart = (dt: string): string => (dt || '').slice(11, 16);
const combine = (date: string, time: string): string => `${date}T${time || '00:00'}`;

/** Normalise an RRULE UNTIL ("20260131T000000Z" or ISO) to a `YYYY-MM-DD` input value. */
const untilToDateInput = (u?: string | null): string => {
    if (!u) return '';
    const m = u.match(/^(\d{4})-?(\d{2})-?(\d{2})/);
    return m ? `${m[1]}-${m[2]}-${m[3]}` : '';
};

/** Maps the seeded event-type `icon` strings (config: sites.default_event_types)
 *  to lucide components for the create-dialog type tiles. */
const TYPE_ICONS: Record<string, LucideIcon> = {
    calendar: CalendarDays,
    'calendar-days': CalendarDays,
    wrench: Wrench,
    'map-pin': MapPin,
    'clipboard-check': ClipboardCheck,
    'hard-hat': HardHat,
    leaf: Leaf,
    'zap-off': ZapOff,
    users: Users,
    'door-open': DoorOpen,
    truck: Truck,
    'file-text': FileText,
};

/** Short descriptions for the known event-type keys (the backend doesn't send
 *  these). Unknown keys simply render without a hint line. */
const TYPE_HINTS: Record<string, string> = {
    general: 'House meeting, activity, social',
    maintenance: 'Planned upkeep — needs approval',
    site_visit: 'Manager or external visit',
    inspection: 'Fire, legionella, H&S walkaround',
    contractor_visit: 'Booked trade / engineer',
    cleaning_grounds: 'Deep clean, lawn, garden',
    utilities_outage: 'Planned power / water outage',
    training_meeting: 'Staff training or supervision',
    room_booking: 'Reserve a communal space',
    vehicle_booking: 'Reserve a site vehicle',
    other: 'Custom entry',
};

/** Reminder presets (minutes before the entry) for the create dialog. */
const REMINDER_PRESETS: { minutes: number; label: string }[] = [
    { minutes: 0, label: 'At time' },
    { minutes: 10, label: '10 min' },
    { minutes: 30, label: '30 min' },
    { minutes: 60, label: '1 hour' },
    { minutes: 1440, label: '1 day' },
];

function TypeIcon({ icon, className = 'h-4 w-4' }: { icon?: string | null; className?: string }) {
    const C = (icon && TYPE_ICONS[icon]) || CalendarDays;
    return <C className={className} />;
}

function viewRange(view: CalView, navDate: Date): { start: Date; end: Date } {
    if (view === 'week') {
        const s = startOfWeek(navDate);
        return { start: s, end: addDays(s, 7) };
    }
    if (view === 'day') {
        const s = new Date(navDate);
        s.setHours(0, 0, 0, 0);
        return { start: s, end: addDays(s, 1) };
    }
    const s = startOfWeek(startOfMonth(navDate));
    return { start: s, end: addDays(s, 42) };
}

function periodLabel(view: CalView, navDate: Date): string {
    if (view === 'day') {
        return navDate.toLocaleDateString('en-NZ', { weekday: 'short', day: 'numeric', month: 'long', year: 'numeric' });
    }
    if (view === 'week') {
        const s = startOfWeek(navDate);
        const e = addDays(s, 6);
        return `${s.getDate()} ${MO[s.getMonth()].slice(0, 3)} – ${e.getDate()} ${MO[e.getMonth()].slice(0, 3)} ${e.getFullYear()}`;
    }
    return `${MO[navDate.getMonth()]} ${navDate.getFullYear()}`;
}

export default function SiteCalendar({
    context,
    scope,
    site,
    sites = [],
    people = [],
    sources = DEFAULT_SOURCES,
    eventTypes = DEFAULT_EVENT_TYPES,
    canCreate,
    canManage = false,
    canApprove = false,
    feedUrl,
    pendingApprovalCount: pendingApprovalCountProp,
    mineCount: mineCountProp,
    overdueCount: overdueCountProp,
}: SiteCalendarProps) {
    const [view, setView] = useState<CalView>('month');
    const [navDate, setNavDate] = useState(() => new Date());
    const [colorBy, setColorBy] = useState<ColorBy>('source');
    const [density, setDensity] = useState<Density>('comfortable');
    const [events, setEvents] = useState<Decorated[]>([]);
    const [railEvents, setRailEvents] = useState<Decorated[]>([]);
    const [loading, setLoading] = useState(true);
    const [enabledSources, setEnabledSources] = useState<Set<string>>(() => new Set(sources.map((s) => s.key)));
    const [houseFilter, setHouseFilter] = useState<number | 'all'>('all');
    const [selected, setSelected] = useState<Decorated | null>(null);
    const [createOpen, setCreateOpen] = useState(false);
    const [editEvent, setEditEvent] = useState<Decorated | null>(null);
    const [seed, setSeed] = useState<CreateSeed | null>(null);
    const [subscribeOpen, setSubscribeOpen] = useState(false);
    const [approvalsOpen, setApprovalsOpen] = useState(false);
    const [ctxMenu, setCtxMenu] = useState<{ x: number; y: number; date: Date; hour?: number } | null>(null);
    const [preview, setPreview] = useState<{ ev: Decorated; rect: DOMRect } | null>(null);
    const previewTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const page = usePage<SharedData>();
    const currentUserId = page.props.auth?.user?.id ?? null;
    // Ticks every minute so the rail's NOW marker + "happening now / up next"
    // advance live without a manual reload.
    const now = useNow();
    // Page contexts pass feedUrl explicitly; the profile-tab embed doesn't, so fall
    // back to the shared per-user subscribe URL.
    const effectiveFeedUrl = feedUrl ?? page.props.calendarFeedUrl ?? null;

    const srcByKey = useMemo(() => Object.fromEntries(sources.map((s) => [s.key, s])) as Record<string, SourceDef>, [sources]);
    const eventTypeByKey = useMemo(() => Object.fromEntries(eventTypes.map((t) => [t.key, t])), [eventTypes]);

    const fetchEvents = useCallback(async () => {
        setLoading(true);
        const { start, end } = viewRange(view, navDate);
        const params = new URLSearchParams({ start: start.toISOString(), end: end.toISOString() });
        const url =
            scope === 'global'
                ? `/calendar/items?${params}`
                : `/sites/${site?.id}/calendar/events?${params}`;
        try {
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!res.ok) {
                setEvents([]);
                return;
            }
            const data = await res.json();
            setEvents((data.events ?? []).map(decorate));
        } catch {
            setEvents([]);
        } finally {
            setLoading(false);
        }
    }, [view, navDate, scope, site?.id]);

    useEffect(() => {
        void fetchEvents();
    }, [fetchEvents]);

    // Today-anchored window for the right-hand rail — independent of the browsed
    // period, and reaching ~45 days back so overdue obligations surface.
    const fetchRail = useCallback(async () => {
        const start = addDays(new Date(), -45);
        start.setHours(0, 0, 0, 0);
        const end = addDays(new Date(), 30);
        const params = new URLSearchParams({ start: start.toISOString(), end: end.toISOString() });
        const url = scope === 'global' ? `/calendar/items?${params}` : `/sites/${site?.id}/calendar/events?${params}`;
        try {
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!res.ok) {
                setRailEvents([]);
                return;
            }
            const data = await res.json();
            setRailEvents((data.events ?? []).map(decorate));
        } catch {
            setRailEvents([]);
        }
    }, [scope, site?.id]);

    useEffect(() => {
        void fetchRail();
    }, [fetchRail]);

    const refresh = useCallback(() => {
        void fetchEvents();
        void fetchRail();
    }, [fetchEvents, fetchRail]);

    const visibleEvents = useMemo(
        () =>
            events.filter(
                (e) =>
                    enabledSources.has(e.source) &&
                    (scope !== 'global' || houseFilter === 'all' || e.site?.id === houseFilter),
            ),
        [events, enabledSources, scope, houseFilter],
    );

    const visibleRailEvents = useMemo(
        () =>
            railEvents.filter(
                (e) =>
                    enabledSources.has(e.source) &&
                    (scope !== 'global' || houseFilter === 'all' || e.site?.id === houseFilter),
            ),
        [railEvents, enabledSources, scope, houseFilter],
    );

    const viewingToday = useMemo(() => {
        const t = new Date();
        if (view === 'day') return sameDay(navDate, t);
        if (view === 'week') {
            const ws = startOfWeek(navDate);
            return t >= ws && t < addDays(ws, 7);
        }
        return navDate.getMonth() === t.getMonth() && navDate.getFullYear() === t.getFullYear();
    }, [view, navDate]);

    // Hero stats. "This month" and "Done" are inherently period stats, so they stay
    // in-view. "Overdue" / "To approve" / "Mine" prefer the authoritative server
    // counts (which see the whole accessible range), falling back to the in-view
    // derivation for embeds with no props — or whenever the user narrows the view by
    // house/source, so the stat keeps tracking what's on screen.
    const narrowed = houseFilter !== 'all' || enabledSources.size !== sources.length;

    const overdueDerived = useMemo(() => visibleEvents.filter((e) => e.status === 'overdue').length, [visibleEvents]);
    const overdueCount = !narrowed && overdueCountProp != null ? overdueCountProp : overdueDerived;

    const thisMonthCount = useMemo(() => {
        const ms = startOfMonth(navDate);
        const me = endOfMonth(navDate);
        me.setHours(23, 59, 59, 999);
        return visibleEvents.filter((e) => e._start >= ms && e._start <= me).length;
    }, [visibleEvents, navDate]);

    const pendingApprovals = useMemo(
        () => visibleEvents.filter((e) => e.group === 'manual' && e.approvalStatus === 'pending'),
        [visibleEvents],
    );
    const toApproveCount = !narrowed && pendingApprovalCountProp != null ? pendingApprovalCountProp : pendingApprovals.length;

    const mineDerived = useMemo(
        () =>
            currentUserId == null
                ? 0
                : visibleEvents.filter(
                      (e) => e.owner?.id === currentUserId || (e.attendeeIds?.includes(currentUserId) ?? false),
                  ).length,
        [visibleEvents, currentUserId],
    );
    const mineCount = !narrowed && mineCountProp != null ? mineCountProp : mineDerived;

    const doneCount = useMemo(
        () => visibleEvents.filter((e) => e.status === 'completed' || e.status === 'approved').length,
        [visibleEvents],
    );

    // Notifications bell — entries coming up in the next 7 days (today-anchored rail
    // feed) plus, for approvers, the pending-approval count.
    const upcoming = useMemo(() => {
        const horizon = addDays(now, 7);
        return visibleRailEvents
            .filter((e) => e._start >= now && e._start <= horizon && e.status !== 'cancelled')
            .sort((a, b) => a._start.getTime() - b._start.getTime())
            .slice(0, 8);
    }, [visibleRailEvents, now]);
    const notifyCount = upcoming.length + (canApprove ? toApproveCount : 0);

    const step = (dir: 1 | -1) => {
        setNavDate((d) => {
            if (view === 'day') return addDays(d, dir);
            if (view === 'week') return addDays(d, dir * 7);
            return new Date(d.getFullYear(), d.getMonth() + dir, 1);
        });
    };

    const toggleSource = (key: string) =>
        setEnabledSources((prev) => {
            const next = new Set(prev);
            if (next.has(key)) next.delete(key);
            else next.add(key);
            return next;
        });

    const createSiteId = scope === 'site' ? site?.id : houseFilter !== 'all' ? houseFilter : sites[0]?.id;

    // Drag-to-reschedule a manual entry. Repeating series are edited from the
    // detail panel (single-occurrence overrides) rather than dragged, for v1.
    const reschedule = useCallback(
        (ev: Decorated, start: Date, end?: Date) => {
            if (!ev.editable || !ev.site || ev.recurrence || ev.isOccurrence) return;
            let s = start;
            let e = end;
            if (!e) {
                s = new Date(start);
                s.setHours(ev._start.getHours(), ev._start.getMinutes(), 0, 0);
                e = ev._end ? new Date(s.getTime() + (ev._end.getTime() - ev._start.getTime())) : undefined;
            }
            router.put(
                `/sites/${ev.site.id}/calendar/events/${ev.seriesId ?? ev.id}`,
                { start_at: toLocalInput(s), end_at: e ? toLocalInput(e) : null },
                { preserveScroll: true, preserveState: true, onSuccess: refresh },
            );
        },
        [refresh],
    );

    const openCreate = useCallback((s: CreateSeed | null = null) => {
        setEditEvent(null);
        setSeed(s);
        setCreateOpen(true);
    }, []);

    const showPreview = useCallback((ev: Decorated, el: HTMLElement) => {
        if (previewTimer.current) clearTimeout(previewTimer.current);
        const rect = el.getBoundingClientRect();
        previewTimer.current = setTimeout(() => setPreview({ ev, rect }), 320);
    }, []);
    const hidePreview = useCallback(() => {
        if (previewTimer.current) clearTimeout(previewTimer.current);
        setPreview(null);
    }, []);

    const uiValue = useMemo(
        () => ({
            colorBy,
            density,
            srcByKey,
            onSelect: (ev: Decorated) => {
                hidePreview();
                setSelected(ev);
            },
            onCreateAt: canCreate ? (d: Date, hour?: number) => openCreate({ date: d, hour }) : undefined,
            onContext: canCreate
                ? (e: React.MouseEvent, d: Date, hour?: number) => {
                      e.preventDefault();
                      hidePreview();
                      setCtxMenu({ x: e.clientX, y: e.clientY, date: d, hour });
                  }
                : undefined,
            onPreview: showPreview,
            onPreviewEnd: hidePreview,
            onMove: canManage ? reschedule : undefined,
        }),
        [colorBy, density, srcByKey, canCreate, canManage, reschedule, openCreate, showPreview, hidePreview],
    );

    const ViewBody = (
        <CalendarUIProvider value={uiValue}>
            <div className="h-[calc(100vh-22rem)] min-h-[560px]">
                {view === 'month' && <MonthView events={visibleEvents} navDate={navDate} />}
                {view === 'week' && <WeekView events={visibleEvents} navDate={navDate} />}
                {view === 'day' && <DayView events={visibleEvents} navDate={navDate} />}
                {view === 'agenda' && <AgendaView events={visibleEvents} navDate={navDate} />}
                {view === 'timeline' && <TimelineView events={visibleEvents} navDate={navDate} sources={sources} />}
            </div>
        </CalendarUIProvider>
    );

    const toolbar = (
        <div className="flex flex-wrap items-center gap-2 rounded-xl border bg-card p-2">
            <div className="flex items-center gap-1">
                <Button variant="outline" size="icon" className="h-8 w-8" onClick={() => step(-1)} aria-label="Previous">
                    <ChevronLeft className="h-4 w-4" />
                </Button>
                <Button variant="outline" size="sm" onClick={() => setNavDate(new Date())}>
                    Today
                </Button>
                <Button variant="outline" size="icon" className="h-8 w-8" onClick={() => step(1)} aria-label="Next">
                    <ChevronRight className="h-4 w-4" />
                </Button>
            </div>
            <span className="tnum min-w-[150px] px-1 text-sm font-semibold">{periodLabel(view, navDate)}</span>

            <div className="ml-auto flex flex-wrap items-center gap-2">
                {scope === 'global' && sites.length > 0 && (
                    <select
                        value={houseFilter === 'all' ? 'all' : String(houseFilter)}
                        onChange={(e) => setHouseFilter(e.target.value === 'all' ? 'all' : Number(e.target.value))}
                        className="h-8 rounded-md border border-input bg-background px-2 text-[13px]"
                        aria-label="House"
                    >
                        <option value="all">All sites</option>
                        {sites.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.name}
                            </option>
                        ))}
                    </select>
                )}

                <div className="flex items-center rounded-md border bg-background p-0.5">
                    {VIEWS.map((v) => (
                        <button
                            key={v.key}
                            onClick={() => setView(v.key)}
                            className={`inline-flex h-7 items-center gap-1.5 rounded px-2 text-[13px] font-medium transition-colors ${view === v.key ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'}`}
                        >
                            <v.icon className="h-3.5 w-3.5" />
                            <span className="hidden sm:inline">{v.label}</span>
                        </button>
                    ))}
                </div>

                <select
                    value={colorBy}
                    onChange={(e) => setColorBy(e.target.value as ColorBy)}
                    className="h-8 rounded-md border border-input bg-background px-2 text-[13px]"
                    aria-label="Colour by"
                >
                    <option value="source">Colour: source</option>
                    <option value="status">Colour: status</option>
                    <option value="owner">Colour: owner</option>
                </select>

                <Button
                    variant="outline"
                    size="icon"
                    className="h-8 w-8"
                    onClick={() => setDensity((d) => (d === 'comfortable' ? 'compact' : 'comfortable'))}
                    aria-label="Toggle density"
                    title={density === 'comfortable' ? 'Comfortable' : 'Compact'}
                >
                    {density === 'comfortable' ? <Rows3 className="h-4 w-4" /> : <Columns3 className="h-4 w-4" />}
                </Button>

                <Button variant="outline" size="sm" onClick={() => setSubscribeOpen(true)}>
                    <Rss className="mr-1 h-4 w-4" />
                    <span className="hidden sm:inline">Subscribe</span>
                </Button>
            </div>
        </div>
    );

    const legend = (
        <div className="flex flex-wrap items-center gap-1.5">
            {sources.map((s) => {
                const on = enabledSources.has(s.key);
                return (
                    <button
                        key={s.key}
                        onClick={() => toggleSource(s.key)}
                        className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[12px] font-medium transition-opacity ${on ? '' : 'opacity-40'}`}
                        style={{ background: `var(--src-${s.key}-bg)`, borderColor: `var(--src-${s.key}-ln)`, color: `var(--src-${s.key})` }}
                        aria-pressed={on}
                    >
                        <span className="h-2 w-2 rounded-full" style={{ background: `var(--src-${s.key})` }} />
                        {s.short}
                    </button>
                );
            })}
        </div>
    );

    const content = (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                {legend}
                {canCreate && (
                    <span className="hidden items-center gap-1.5 rounded-full bg-muted px-2.5 py-1 text-[11.5px] font-medium text-muted-foreground md:inline-flex">
                        Right-click to add · drag to reschedule
                    </span>
                )}
            </div>
            <div className="flex gap-3">
                <div className="min-w-0 flex-1">{ViewBody}</div>
                {context === 'page' && (
                    <TodayRail
                        events={visibleRailEvents}
                        today={now}
                        onSelect={(ev) => {
                            hidePreview();
                            setSelected(ev);
                        }}
                        onApprovals={() => setApprovalsOpen(true)}
                        onJumpToday={() => setNavDate(new Date())}
                        viewingToday={viewingToday}
                    />
                )}
            </div>
            {loading && <p className="text-center text-sm text-muted-foreground">Loading…</p>}
        </div>
    );

    const allSourcesOn = enabledSources.size === sources.length;

    // Brand/--primary onDark actions, matching the Rostering banner.
    const heroActions = (
        <>
            {canCreate && (
                <Button
                    size="sm"
                    className="bg-primary-foreground text-primary hover:bg-primary-foreground/90"
                    onClick={() => openCreate()}
                >
                    <Plus className="mr-1 h-4 w-4" /> New entry
                </Button>
            )}
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        size="icon"
                        variant="outline"
                        aria-label={`Notifications${notifyCount ? ` (${notifyCount})` : ''}`}
                        className="relative border-primary-foreground/30 bg-transparent text-primary-foreground hover:bg-primary-foreground/10"
                    >
                        <Bell className="h-4 w-4" />
                        {notifyCount > 0 && (
                            <span className="absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-status-critical px-1 text-[10px] font-semibold text-white">
                                {notifyCount > 9 ? '9+' : notifyCount}
                            </span>
                        )}
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-72">
                    <DropdownMenuLabel>Notifications</DropdownMenuLabel>
                    {canApprove && toApproveCount > 0 && (
                        <DropdownMenuItem onSelect={() => setApprovalsOpen(true)}>
                            <ClipboardCheck className="mr-2 h-4 w-4 text-status-warning" />
                            {toApproveCount} awaiting approval
                        </DropdownMenuItem>
                    )}
                    <DropdownMenuSeparator />
                    <DropdownMenuLabel className="text-[11px] font-normal text-muted-foreground">
                        Upcoming · next 7 days
                    </DropdownMenuLabel>
                    {upcoming.length === 0 ? (
                        <div className="px-2 py-3 text-center text-[13px] text-muted-foreground">
                            Nothing in the next 7 days.
                        </div>
                    ) : (
                        upcoming.map((e) => (
                            <DropdownMenuItem
                                key={e.id}
                                onSelect={() => setSelected(e)}
                                className="flex-col items-start gap-0.5"
                            >
                                <span className="w-full truncate text-[13px] font-medium">{e.title}</span>
                                <span className="text-[11px] text-muted-foreground">
                                    {e._start.toLocaleDateString('en-NZ', { weekday: 'short', day: 'numeric', month: 'short' })}
                                    {!e.allDay ? ` · ${fmtTime(e._start)}` : ''}
                                </span>
                            </DropdownMenuItem>
                        ))
                    )}
                </DropdownMenuContent>
            </DropdownMenu>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        size="icon"
                        variant="outline"
                        aria-label="More options"
                        className="border-primary-foreground/30 bg-transparent text-primary-foreground hover:bg-primary-foreground/10"
                    >
                        <MoreHorizontal className="h-4 w-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-60">
                    <DropdownMenuItem onSelect={() => setSubscribeOpen(true)}>
                        <Rss className="mr-2 h-4 w-4" /> Add to your calendar
                    </DropdownMenuItem>
                    <DropdownMenuItem onSelect={() => downloadICS(visibleEvents, 'site-calendar.ics')}>
                        <Download className="mr-2 h-4 w-4" /> Export this period (.ics)
                    </DropdownMenuItem>
                    {canApprove && (
                        <>
                            <DropdownMenuSeparator />
                            <DropdownMenuLabel>Admin</DropdownMenuLabel>
                            <DropdownMenuItem onSelect={() => setApprovalsOpen(true)}>
                                <ClipboardCheck className="mr-2 h-4 w-4" /> Review approvals
                                {toApproveCount > 0 ? ` (${toApproveCount})` : ''}
                            </DropdownMenuItem>
                        </>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>
        </>
    );

    const filterPopover = (
        <Popover>
            <PopoverTrigger asChild>
                <Button
                    size="sm"
                    variant="outline"
                    className="border-primary-foreground/20 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20"
                >
                    <Filter className="mr-1 h-3.5 w-3.5" /> Filter
                    {!allSourcesOn && (
                        <span className="ml-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-primary-foreground px-1 text-[10px] font-bold text-primary tnum">
                            {enabledSources.size}
                        </span>
                    )}
                </Button>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-72">
                <div className="space-y-3">
                    <div>
                        <p className="mb-1.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Colour by</p>
                        <div className="grid grid-cols-3 gap-1">
                            {(['source', 'status', 'owner'] as ColorBy[]).map((c) => (
                                <button
                                    key={c}
                                    onClick={() => setColorBy(c)}
                                    className={`rounded-md px-2 py-1.5 text-[12px] font-medium capitalize transition-colors ${colorBy === c ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground hover:bg-accent'}`}
                                >
                                    {c}
                                </button>
                            ))}
                        </div>
                    </div>
                    <div>
                        <p className="mb-1.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Density</p>
                        <div className="grid grid-cols-2 gap-1">
                            {(['comfortable', 'compact'] as Density[]).map((d) => (
                                <button
                                    key={d}
                                    onClick={() => setDensity(d)}
                                    className={`rounded-md px-2 py-1.5 text-[12px] font-medium capitalize transition-colors ${density === d ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground hover:bg-accent'}`}
                                >
                                    {d}
                                </button>
                            ))}
                        </div>
                    </div>
                    <div>
                        <div className="mb-1.5 flex items-center justify-between">
                            <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Sources</p>
                            <button
                                onClick={() => setEnabledSources(allSourcesOn ? new Set() : new Set(sources.map((s) => s.key)))}
                                className="text-[11px] font-medium text-primary hover:underline"
                            >
                                {allSourcesOn ? 'Clear all' : 'Select all'}
                            </button>
                        </div>
                        <div className="max-h-44 space-y-0.5 overflow-y-auto">
                            {sources.map((s) => {
                                const on = enabledSources.has(s.key);
                                return (
                                    <button
                                        key={s.key}
                                        onClick={() => toggleSource(s.key)}
                                        className="flex w-full items-center gap-2 rounded-md px-1.5 py-1 text-left text-[13px] transition-colors hover:bg-accent/50"
                                    >
                                        <span
                                            className={`flex h-4 w-4 items-center justify-center rounded border ${on ? 'border-transparent' : 'border-border'}`}
                                            style={on ? { background: `var(--src-${s.key})` } : undefined}
                                        >
                                            {on && <Check className="h-3 w-3 text-primary-foreground" strokeWidth={3} />}
                                        </span>
                                        <SourceDot k={s.key} />
                                        <span className="flex-1 truncate">{s.label}</span>
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                </div>
            </PopoverContent>
        </Popover>
    );

    const heroFooter = (
        <div className="flex flex-col items-stretch gap-2 py-3 lg:flex-row lg:items-center lg:justify-between">
            <div className="flex flex-wrap items-center gap-1.5">
                {/* eslint-disable-next-line no-restricted-syntax -- segmented stepper on dark hero; not a shadcn Button. */}
                <button
                    type="button"
                    onClick={() => step(-1)}
                    aria-label="Previous period"
                    className="inline-flex items-center gap-1 rounded-md border border-primary-foreground/20 bg-primary-foreground/10 px-3 py-1.5 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/20"
                >
                    <ChevronLeft className="h-3.5 w-3.5" />
                    <span className="hidden sm:inline">Prev</span>
                </button>
                <span className="tnum inline-flex items-center gap-1.5 rounded-md border border-primary-foreground/35 bg-primary-foreground/20 px-3 py-1.5 text-xs font-semibold text-primary-foreground">
                    <CalendarDays className="h-3.5 w-3.5" />
                    {periodLabel(view, navDate)}
                </span>
                {/* eslint-disable-next-line no-restricted-syntax -- segmented stepper on dark hero; not a shadcn Button. */}
                <button
                    type="button"
                    onClick={() => step(1)}
                    aria-label="Next period"
                    className="inline-flex items-center gap-1 rounded-md border border-primary-foreground/20 bg-primary-foreground/10 px-3 py-1.5 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/20"
                >
                    <span className="hidden sm:inline">Next</span>
                    <ChevronRight className="h-3.5 w-3.5" />
                </button>
                {/* eslint-disable-next-line no-restricted-syntax -- segmented stepper on dark hero; not a shadcn Button. */}
                <button
                    type="button"
                    onClick={() => setNavDate(new Date())}
                    className="ml-0.5 inline-flex items-center gap-1 rounded-md border border-primary-foreground/20 bg-primary-foreground/10 px-3 py-1.5 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/20"
                >
                    Today
                </button>
            </div>

            <div className="flex flex-wrap items-center gap-2 lg:justify-end">
                <div className="flex items-center rounded-md border border-primary-foreground/20 bg-primary-foreground/10 p-0.5">
                    {VIEWS.map((v) => (
                        // eslint-disable-next-line no-restricted-syntax -- segmented onDark view switch; not a shadcn Button.
                        <button
                            key={v.key}
                            onClick={() => setView(v.key)}
                            title={v.label}
                            className={`inline-flex items-center gap-1.5 rounded px-2.5 py-1 text-xs font-semibold transition-colors ${view === v.key ? 'bg-primary-foreground text-primary' : 'text-primary-foreground/80 hover:bg-primary-foreground/15'}`}
                        >
                            <v.icon className="h-3.5 w-3.5" />
                            <span className="hidden lg:inline">{v.label}</span>
                        </button>
                    ))}
                </div>
                {filterPopover}
            </div>
        </div>
    );

    const overduePart = overdueCount ? `${overdueCount} overdue` : '';
    const pendingPart = toApproveCount ? `${toApproveCount} awaiting approval` : '';
    const attention = [overduePart, pendingPart].filter(Boolean).join(', ');
    const heroName = scope === 'global' ? 'Site Calendar' : (site?.name ?? 'Site Calendar');
    const heroDescription = `${thisMonthCount} dated ${thisMonthCount === 1 ? 'entry' : 'entries'} this period across ${scope === 'global' ? 'all sites' : heroName}${attention ? ` — ${attention} need attention.` : ' — all on track.'}`;
    const todayLabel = new Date().toLocaleDateString('en-NZ', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
    const heroTitle = (
        <span className="block">
            <span className="mb-2 flex flex-wrap items-center gap-2.5">
                <span className="flex items-center gap-2 text-[10.5px] font-semibold uppercase tracking-wider text-primary-foreground/80">
                    <span aria-hidden="true" className="relative inline-flex h-2 w-2">
                        <span className="absolute inset-0 inline-flex h-full w-full animate-ping rounded-full bg-status-success/70" />
                        <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success ring-2 ring-status-success/30" />
                    </span>
                    Live · synced just now
                </span>
                <span className="inline-flex items-center gap-1.5 rounded-full bg-primary-foreground/20 px-2.5 py-1 text-[11px] font-semibold tnum">
                    <CalendarDays className="h-3.5 w-3.5" /> Today · {todayLabel}
                </span>
            </span>
            {sites.length > 0 ? <HouseSelector scope={scope} site={site} sites={sites} /> : <span className="block">{heroName}</span>}
        </span>
    );

    const dialogs = (
        <>
            <EventDetailDialog
                event={selected}
                onClose={() => setSelected(null)}
                eventTypeByKey={eventTypeByKey}
                canManage={canManage}
                canApprove={canApprove}
                onEdit={(ev) => {
                    setSelected(null);
                    setEditEvent(ev);
                    setCreateOpen(true);
                }}
                onChanged={() => {
                    setSelected(null);
                    refresh();
                }}
            />
            {(canCreate || canManage) && (
                <CreateEventDialog
                    open={createOpen}
                    onOpenChange={(o) => {
                        setCreateOpen(o);
                        if (!o) {
                            setEditEvent(null);
                            setSeed(null);
                        }
                    }}
                    scope={scope}
                    sites={sites}
                    people={people}
                    defaultSiteId={createSiteId}
                    site={site}
                    eventTypes={eventTypes}
                    editEvent={editEvent}
                    seed={seed}
                    existingEvents={events}
                    onSaved={() => {
                        setCreateOpen(false);
                        setEditEvent(null);
                        setSeed(null);
                        refresh();
                    }}
                />
            )}
            <SubscribeDialog open={subscribeOpen} onOpenChange={setSubscribeOpen} feedUrl={effectiveFeedUrl} />
            {canApprove && (
                <ApprovalsPanel
                    open={approvalsOpen}
                    onOpenChange={setApprovalsOpen}
                    items={pendingApprovals}
                    eventTypeByKey={eventTypeByKey}
                    onOpenEvent={(ev) => {
                        setApprovalsOpen(false);
                        setSelected(ev);
                    }}
                    onChanged={refresh}
                />
            )}
            {ctxMenu && canCreate && (
                <QuickAddMenu
                    ctx={ctxMenu}
                    eventTypes={eventTypes}
                    siteName={scope === 'site' ? site?.name : sites.find((s) => s.id === createSiteId)?.name}
                    onPick={(typeKey) => {
                        openCreate({ date: ctxMenu.date, hour: ctxMenu.hour, eventType: typeKey });
                        setCtxMenu(null);
                    }}
                    onForm={() => {
                        openCreate({ date: ctxMenu.date, hour: ctxMenu.hour });
                        setCtxMenu(null);
                    }}
                    onClose={() => setCtxMenu(null)}
                />
            )}
            {preview && !selected && !createOpen && !subscribeOpen && !approvalsOpen && !ctxMenu && (
                <EventHoverCard ev={preview.ev} rect={preview.rect} srcByKey={srcByKey} eventTypeByKey={eventTypeByKey} />
            )}
        </>
    );

    if (context === 'profile') {
        return (
            <div className="space-y-3">
                {toolbar}
                {content}
                {dialogs}
            </div>
        );
    }

    return (
        <>
            <PageLayout
                hero={
                    <PageHero
                        category="ops"
                        icon={CalendarDays}
                        backHref={scope === 'site' && site ? `/sites/${site.id}` : undefined}
                        backLabel="Sites"
                        title={heroTitle}
                        description={heroDescription}
                        meta={[
                            {
                                icon: CalendarDays,
                                label: `${periodLabel(view, navDate)} · ${VIEWS.find((v) => v.key === view)?.label ?? ''} view`,
                            },
                            { icon: Layers, label: `${enabledSources.size} of ${sources.length} sources shown` },
                            { icon: CheckCircle2, label: `${doneCount} done / approved` },
                        ]}
                        stats={[
                            { label: 'This month', value: thisMonthCount },
                            { label: 'Overdue', value: overdueCount, tone: overdueCount > 0 ? 'critical' : undefined },
                            { label: 'To approve', value: toApproveCount, tone: toApproveCount > 0 ? 'warning' : undefined },
                            { label: 'Mine', value: mineCount },
                        ]}
                        actions={heroActions}
                        footer={heroFooter}
                    />
                }
            >
                {content}
            </PageLayout>
            {dialogs}
        </>
    );
}

/* ---- house / site selector (hero title) --------------------------------- */

function HouseSelector({ scope, site, sites }: { scope: 'global' | 'site'; site?: SiteLite; sites: SiteLite[] }) {
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const currentId: number | 'all' = scope === 'site' && site ? site.id : 'all';
    const currentLabel = scope === 'site' ? (site?.name ?? 'Site Calendar') : 'All sites';
    const list = sites.filter((s) => `${s.name} ${s.type ?? ''}`.toLowerCase().includes(q.trim().toLowerCase()));
    const go = (target: string) => {
        setOpen(false);
        router.visit(target);
    };
    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    className="group -mx-1.5 inline-flex items-center gap-2 rounded-lg px-1.5 py-0.5 text-left transition-colors hover:bg-primary-foreground/10"
                >
                    <span className="whitespace-nowrap">{currentLabel}</span>
                    <span
                        className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-primary-foreground/15 transition-transform ${open ? 'rotate-180' : ''}`}
                    >
                        <ChevronDown className="h-4 w-4" />
                    </span>
                </button>
            </PopoverTrigger>
            <PopoverContent align="start" className="w-[320px] p-0">
                <div className="border-b p-2">
                    <div className="relative">
                        <Search className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <input
                            autoFocus
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder={`Search ${sites.length} sites…`}
                            className="h-9 w-full rounded-md border bg-background pl-8 pr-2 text-[13px] outline-none focus:ring-2 focus:ring-ring"
                        />
                    </div>
                </div>
                <div className="max-h-[320px] overflow-y-auto p-1.5">
                    <button
                        type="button"
                        onClick={() => go('/calendar')}
                        className={`flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left transition-colors ${currentId === 'all' ? 'bg-primary/10' : 'hover:bg-accent/60'}`}
                    >
                        <span
                            className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${currentId === 'all' ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'}`}
                        >
                            <LayoutGrid className="h-4 w-4" />
                        </span>
                        <span className="min-w-0 flex-1">
                            <span className="block truncate text-[13.5px] font-semibold">All sites</span>
                            <span className="block truncate text-[11.5px] text-muted-foreground">Every house &amp; location</span>
                        </span>
                        {currentId === 'all' && <Check className="h-4 w-4 text-primary" />}
                    </button>
                    {list.map((s) => {
                        const active = s.id === currentId;
                        return (
                            <button
                                key={s.id}
                                type="button"
                                onClick={() => go(`/sites/${s.id}/calendar`)}
                                className={`flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left transition-colors ${active ? 'bg-primary/10' : 'hover:bg-accent/60'}`}
                            >
                                <span
                                    className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${active ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'}`}
                                >
                                    <Home className="h-4 w-4" />
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-[13.5px] font-semibold">{s.name}</span>
                                    <span className="block truncate text-[11.5px] capitalize text-muted-foreground">{(s.type ?? '').replace(/_/g, ' ')}</span>
                                </span>
                                {active && <Check className="h-4 w-4 text-primary" />}
                            </button>
                        );
                    })}
                    {list.length === 0 && <div className="px-3 py-6 text-center text-[13px] text-muted-foreground">No sites match.</div>}
                </div>
            </PopoverContent>
        </Popover>
    );
}

/* ---- detail dialog ------------------------------------------------------ */

function EventDetailDialog({
    event,
    onClose,
    eventTypeByKey,
    canManage,
    canApprove,
    onEdit,
    onChanged,
}: {
    event: Decorated | null;
    onClose: () => void;
    eventTypeByKey: Record<string, EventTypeOption>;
    canManage: boolean;
    canApprove: boolean;
    onEdit: (event: Decorated) => void;
    onChanged: () => void;
}) {
    const form = useForm({});
    if (!event) return null;
    const typeLabel = event.eventType ? eventTypeByKey[event.eventType]?.label ?? event.eventType : null;
    const when = event.allDay
        ? 'All day'
        : event._end
          ? fmtTimeRange(event._start, event._end)
          : fmtTimeRange(event._start, null);

    const base = event.site ? `/sites/${event.site.id}/calendar/events/${event.seriesId ?? event.id}` : null;
    const isPending = event.approvalStatus === 'pending';

    const remove = () => {
        if (base) form.delete(base, { preserveScroll: true, onSuccess: onChanged });
    };
    const approve = () => {
        if (base) form.post(`${base}/approve`, { preserveScroll: true, onSuccess: onChanged });
    };
    const reject = () => {
        if (base) form.post(`${base}/reject`, { preserveScroll: true, onSuccess: onChanged });
    };

    return (
        <Dialog open={!!event} onOpenChange={(o) => !o && onClose()}>
            <DialogContent style={{ maxWidth: 'min(92vw, 540px)' }}>
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <span className="h-3 w-3 shrink-0 rounded-full" style={{ background: `var(--src-${event.source})` }} />
                        {event.title}
                    </DialogTitle>
                    <DialogDescription>
                        {event._start.toLocaleDateString('en-NZ', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })} · {when}
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-3 text-sm">
                    <div className="flex flex-wrap items-center gap-2">
                        <StatusBadge status={event.status} />
                        {typeLabel && <span className="rounded-full bg-muted px-2 py-0.5 text-[12px] text-muted-foreground">{typeLabel}</span>}
                        {event.ref && <span className="tnum rounded-full bg-muted px-2 py-0.5 text-[12px] text-muted-foreground">{event.ref}</span>}
                    </div>
                    {event.desc && <p className="text-muted-foreground">{event.desc}</p>}
                    <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-[13px]">
                        {event.site && (
                            <>
                                <dt className="text-muted-foreground">Site</dt>
                                <dd>{event.site.name}</dd>
                            </>
                        )}
                        {event.room && (
                            <>
                                <dt className="text-muted-foreground">Room</dt>
                                <dd>{event.room}</dd>
                            </>
                        )}
                        {event.owner && (
                            <>
                                <dt className="text-muted-foreground">Owner</dt>
                                <dd className="flex items-center gap-1.5">
                                    <Avatar person={event.owner} size="h-5 w-5" /> {event.owner.name}
                                </dd>
                            </>
                        )}
                        {event.recurrence && (
                            <>
                                <dt className="text-muted-foreground">Repeats</dt>
                                <dd>{ruleToText(event.recurrence)}</dd>
                            </>
                        )}
                    </dl>

                    <div className="rounded-lg border bg-muted/30 p-2.5">
                        <p className="mb-1.5 text-[12px] font-medium text-muted-foreground">Add to your calendar</p>
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" size="xs" asChild>
                                <a href={googleLink(event)} target="_blank" rel="noopener noreferrer">
                                    <ExternalLink className="mr-1 h-3.5 w-3.5" /> Google
                                </a>
                            </Button>
                            <Button variant="outline" size="xs" asChild>
                                <a href={outlookLink(event)} target="_blank" rel="noopener noreferrer">
                                    <ExternalLink className="mr-1 h-3.5 w-3.5" /> Outlook
                                </a>
                            </Button>
                            <Button variant="outline" size="xs" onClick={() => downloadICS([event], `${event.ref ?? 'event'}.ics`)}>
                                <Download className="mr-1 h-3.5 w-3.5" /> .ics
                            </Button>
                        </div>
                    </div>
                </div>

                <DialogFooter className="flex-row flex-wrap items-center justify-between gap-2 sm:justify-between">
                    {!event.editable && event.link ? (
                        <Button variant="secondary" size="sm" asChild>
                            <Link href={event.link}>
                                <ExternalLink className="mr-1 h-3.5 w-3.5" /> Open record
                            </Link>
                        </Button>
                    ) : (
                        <span />
                    )}
                    <div className="flex flex-wrap items-center gap-2">
                        {isPending && canApprove && (
                            <>
                                <Button variant="outline" size="sm" onClick={reject} disabled={form.processing}>
                                    <X className="mr-1 h-3.5 w-3.5" /> Reject
                                </Button>
                                <Button size="sm" onClick={approve} disabled={form.processing}>
                                    <Check className="mr-1 h-3.5 w-3.5" /> Approve
                                </Button>
                            </>
                        )}
                        {event.editable && canManage && (
                            <>
                                <Button variant="outline" size="sm" onClick={() => onEdit(event)}>
                                    <Pencil className="mr-1 h-3.5 w-3.5" /> Edit
                                </Button>
                                <Button variant="destructive" size="sm" onClick={remove} disabled={form.processing}>
                                    <Trash2 className="mr-1 h-3.5 w-3.5" /> Delete
                                </Button>
                            </>
                        )}
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/* ---- create dialog (follows docs/POPUP_STYLE_GUIDE.md) ------------------ */

function CreateEventDialog({
    open,
    onOpenChange,
    scope,
    sites,
    people,
    defaultSiteId,
    site,
    eventTypes,
    editEvent,
    seed,
    existingEvents,
    onSaved,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    scope: 'global' | 'site';
    sites: SiteLite[];
    people: Person[];
    defaultSiteId?: number;
    site?: SiteLite;
    eventTypes: EventTypeOption[];
    editEvent?: Decorated | null;
    seed?: CreateSeed | null;
    existingEvents: Decorated[];
    onSaved: () => void;
}) {
    const [targetSite, setTargetSite] = useState<number | undefined>(defaultSiteId);
    const [preset, setPreset] = useState<RecurPreset>('none');
    // Recurrence end condition — recur.ts supports UNTIL/COUNT; the Repeats select
    // only picks the frequency, so the "Ends" control layers the end on top.
    const [recurEnd, setRecurEnd] = useState<{ mode: 'never' | 'until' | 'count'; until: string; count: number }>({
        mode: 'never',
        until: '',
        count: 10,
    });
    const form = useForm({
        event_type: eventTypes[0]?.key ?? 'general',
        title: '',
        description: '',
        room: '',
        start_at: '',
        end_at: '',
        all_day: false,
        owner_user_id: null as number | null,
        attendee_user_ids: [] as number[],
        reminder_minutes: [] as number[],
        recurrence_rule: '' as string,
    });

    useEffect(() => {
        if (!open) return;
        if (editEvent) {
            setTargetSite(editEvent.site?.id ?? defaultSiteId);
            const rule = editEvent.recurrence ?? null;
            setPreset(ruleToPreset(rule));
            setRecurEnd({
                mode: rule?.until ? 'until' : rule?.count ? 'count' : 'never',
                until: untilToDateInput(rule?.until),
                count: rule?.count ?? 10,
            });
            form.setData({
                event_type: editEvent.eventType ?? eventTypes[0]?.key ?? 'general',
                title: editEvent.title,
                description: editEvent.desc ?? '',
                room: editEvent.room ?? '',
                start_at: toLocalInput(editEvent._start),
                end_at: editEvent._end ? toLocalInput(editEvent._end) : '',
                all_day: !!editEvent.allDay,
                owner_user_id: editEvent.owner?.id ?? null,
                attendee_user_ids: editEvent.attendeeIds ?? [],
                reminder_minutes: editEvent.reminders ?? [],
                recurrence_rule: '',
            });
        } else {
            setTargetSite(defaultSiteId);
            setPreset('none');
            setRecurEnd({ mode: 'never', until: '', count: 10 });
            const start = seed?.date ? new Date(seed.date) : new Date();
            if (seed?.hour != null) {
                start.setHours(seed.hour, 0, 0, 0);
            } else {
                start.setMinutes(0, 0, 0);
                start.setHours(start.getHours() + 1);
            }
            const end = new Date(start.getTime() + 60 * 60_000);
            form.setData({
                event_type: seed?.eventType ?? eventTypes[0]?.key ?? 'general',
                title: '',
                description: '',
                room: '',
                start_at: toLocalInput(start),
                end_at: toLocalInput(end),
                all_day: false,
                owner_user_id: null,
                attendee_user_ids: [],
                reminder_minutes: [],
                recurrence_rule: '',
            });
        }
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, editEvent, defaultSiteId, seed]);

    const selectedType = eventTypes.find((t) => t.key === form.data.event_type);

    // Live conflict check against loaded events (same-room / vendor booking overlap).
    const conflicts = useMemo(() => {
        if (!form.data.start_at) return [];
        const draft: CalendarItem = {
            id: editEvent?.id ?? 'draft',
            seriesId: editEvent?.seriesId ?? null,
            source: 'event',
            group: 'manual',
            title: form.data.title || 'New event',
            start: form.data.start_at,
            end: form.data.end_at || null,
            allDay: form.data.all_day,
            status: 'scheduled',
            owner: null,
            room: form.data.room || null,
            ref: null,
            site: editEvent?.site ?? null,
            link: null,
            editable: true,
        };
        return findConflicts(draft, existingEvents);
    }, [form.data.start_at, form.data.end_at, form.data.title, form.data.all_day, form.data.room, editEvent, existingEvents]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!targetSite) return;
        const rule = presetToRule(preset);
        if (rule && recurEnd.mode === 'until' && recurEnd.until) {
            rule.until = recurEnd.until;
        } else if (rule && recurEnd.mode === 'count' && recurEnd.count > 0) {
            rule.count = recurEnd.count;
        }
        form.transform((data) => ({
            ...data,
            recurrence_rule: rule ? (toRRULE(rule) ?? '') : '',
            end_at: data.all_day ? '' : data.end_at,
        }));
        const opts = {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onSaved();
            },
        };
        if (editEvent) {
            form.put(`/sites/${targetSite}/calendar/events/${editEvent.seriesId ?? editEvent.id}`, opts);
        } else {
            form.post(`/sites/${targetSite}/calendar/events`, opts);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent style={{ maxWidth: 'min(92vw, 720px)' }}>
                {open && (
                    <>
                        <DialogHeader>
                            <DialogTitle className="flex items-center gap-2">
                                <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                    <CalendarPlus className="h-4 w-4" />
                                </span>
                                {editEvent ? 'Edit calendar entry' : 'New calendar entry'}
                            </DialogTitle>
                            <DialogDescription>
                                {editEvent ? 'Update the details below.' : 'Pick an entry type, then fill in the details.'}
                            </DialogDescription>
                        </DialogHeader>

                        <form onSubmit={submit} className="space-y-4">
                            {/* Type tile picker — icon, label, hint + approval lock */}
                            <div>
                                <Label className="mb-1.5 block">
                                    Entry type <span className="text-status-critical">*</span>
                                </Label>
                                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                    {eventTypes.map((t) => {
                                        const active = form.data.event_type === t.key;
                                        return (
                                            <button
                                                type="button"
                                                key={t.key}
                                                onClick={() => form.setData('event_type', t.key)}
                                                className={`group flex items-start gap-2 rounded-xl border bg-card/40 p-2.5 text-left transition-all hover:border-primary/50 hover:bg-card ${active ? 'border-primary bg-primary/10 ring-1 ring-primary/40' : 'border-border'}`}
                                                aria-pressed={active}
                                            >
                                                <span className="mt-0.5 shrink-0 rounded-lg p-1.5" style={{ background: `${t.color}1a`, color: t.color }}>
                                                    <TypeIcon icon={t.icon} className="h-4 w-4" />
                                                </span>
                                                <span className="min-w-0">
                                                    <span className="flex items-center gap-1 text-[13px] font-medium leading-tight">
                                                        <span className="truncate">{t.label}</span>
                                                        {t.requires_approval && <Lock className="h-2.5 w-2.5 shrink-0 text-muted-foreground" />}
                                                    </span>
                                                    {TYPE_HINTS[t.key] && <span className="mt-0.5 block truncate text-[11px] text-muted-foreground">{TYPE_HINTS[t.key]}</span>}
                                                </span>
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>

                            {/* Locked site (site scope) / picker (global) */}
                            {scope === 'site' && site ? (
                                <div className="flex items-start gap-3 rounded-xl border border-primary/40 bg-primary/10 p-3">
                                    <span className="mt-0.5 shrink-0 rounded-lg bg-background/60 p-1.5">
                                        <Home className="h-4 w-4 text-primary" />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="text-[13px] font-medium">{site.name}</span>
                                            <span className="rounded-full border px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">From site</span>
                                        </div>
                                        <p className="mt-0.5 text-[11.5px] text-muted-foreground">
                                            Locked to the calendar you opened.
                                            {selectedType?.requires_approval && !editEvent ? ' This type is submitted for approval.' : ''}
                                        </p>
                                    </div>
                                </div>
                            ) : (
                                <div>
                                    <Label htmlFor="cal-site" className="mb-1.5 block">
                                        Site <span className="text-status-critical">*</span>
                                    </Label>
                                    <select
                                        id="cal-site"
                                        value={targetSite ?? ''}
                                        onChange={(e) => setTargetSite(Number(e.target.value))}
                                        className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                        required
                                    >
                                        <option value="" disabled>
                                            Select a site…
                                        </option>
                                        {sites.map((s) => (
                                            <option key={s.id} value={s.id}>
                                                {s.name}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            )}

                            <div>
                                <Label htmlFor="cal-title" className="mb-1.5 block">
                                    Title <span className="text-status-critical">*</span>
                                </Label>
                                <Input
                                    id="cal-title"
                                    value={form.data.title}
                                    onChange={(e) => form.setData('title', e.target.value)}
                                    placeholder="e.g. Resident house meeting"
                                    required
                                />
                                {form.errors.title && <p className="mt-1 text-sm text-status-critical">{form.errors.title}</p>}
                            </div>

                            <div>
                                <Label htmlFor="cal-date" className="mb-1.5 block">
                                    Date <span className="text-status-critical">*</span>
                                </Label>
                                <Input
                                    id="cal-date"
                                    type="date"
                                    value={datePart(form.data.start_at)}
                                    onChange={(e) =>
                                        form.setData({
                                            ...form.data,
                                            start_at: combine(e.target.value, timePart(form.data.start_at) || '09:00'),
                                            end_at: form.data.end_at ? combine(e.target.value, timePart(form.data.end_at) || '10:00') : '',
                                        })
                                    }
                                    required
                                />
                                {form.errors.start_at && <p className="mt-1 text-sm text-status-critical">{form.errors.start_at}</p>}
                            </div>

                            <label className="flex items-center gap-2.5 text-sm font-medium">
                                <Switch checked={form.data.all_day} onCheckedChange={(v) => form.setData('all_day', v)} />
                                All-day entry
                            </label>

                            {!form.data.all_day && (
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <Label htmlFor="cal-start" className="mb-1.5 block">
                                            Start <span className="text-status-critical">*</span>
                                        </Label>
                                        <Input
                                            id="cal-start"
                                            type="time"
                                            value={timePart(form.data.start_at)}
                                            onChange={(e) => form.setData('start_at', combine(datePart(form.data.start_at), e.target.value))}
                                            required
                                        />
                                    </div>
                                    <div>
                                        <Label htmlFor="cal-end" className="mb-1.5 block">
                                            End
                                        </Label>
                                        <Input
                                            id="cal-end"
                                            type="time"
                                            value={form.data.end_at ? timePart(form.data.end_at) : ''}
                                            onChange={(e) => form.setData('end_at', e.target.value ? combine(datePart(form.data.start_at), e.target.value) : '')}
                                        />
                                        {form.errors.end_at && <p className="mt-1 text-sm text-status-critical">{form.errors.end_at}</p>}
                                    </div>
                                </div>
                            )}

                            <div>
                                <Label htmlFor="cal-owner" className="mb-1.5 block">
                                    Owner
                                </Label>
                                <select
                                    id="cal-owner"
                                    value={form.data.owner_user_id ?? ''}
                                    onChange={(e) => form.setData('owner_user_id', e.target.value ? Number(e.target.value) : null)}
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                >
                                    <option value="">Unassigned</option>
                                    {people.map((p) => (
                                        <option key={p.id} value={p.id}>
                                            {p.name}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div>
                                <Label htmlFor="cal-recur" className="mb-1.5 block">
                                    Repeats
                                </Label>
                                <select
                                    id="cal-recur"
                                    value={preset}
                                    onChange={(e) => setPreset(e.target.value as RecurPreset)}
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                >
                                    {RECUR_PRESETS.map((p) => (
                                        <option key={p} value={p}>
                                            {ruleToText(presetToRule(p))}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {preset !== 'none' && (
                                <div>
                                    <Label htmlFor="cal-recur-end" className="mb-1.5 block">
                                        Ends
                                    </Label>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <select
                                            id="cal-recur-end"
                                            value={recurEnd.mode}
                                            onChange={(e) =>
                                                setRecurEnd((s) => ({ ...s, mode: e.target.value as 'never' | 'until' | 'count' }))
                                            }
                                            className="rounded-md border border-input bg-background px-3 py-2 text-sm"
                                        >
                                            <option value="never">Never</option>
                                            <option value="until">On date</option>
                                            <option value="count">After…</option>
                                        </select>
                                        {recurEnd.mode === 'until' && (
                                            <Input
                                                type="date"
                                                aria-label="Repeat until date"
                                                value={recurEnd.until}
                                                min={datePart(form.data.start_at)}
                                                onChange={(e) => setRecurEnd((s) => ({ ...s, until: e.target.value }))}
                                                className="flex-1"
                                            />
                                        )}
                                        {recurEnd.mode === 'count' && (
                                            <div className="flex flex-1 items-center gap-2">
                                                <Input
                                                    type="number"
                                                    min={1}
                                                    max={365}
                                                    aria-label="Number of occurrences"
                                                    value={recurEnd.count}
                                                    onChange={(e) =>
                                                        setRecurEnd((s) => ({ ...s, count: Math.max(1, Number(e.target.value) || 1) }))
                                                    }
                                                    className="w-20"
                                                />
                                                <span className="text-sm text-muted-foreground">occurrences</span>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            )}

                            <div>
                                <Label htmlFor="cal-room" className="mb-1.5 block">
                                    Room / location <span className="font-normal text-muted-foreground">(optional)</span>
                                </Label>
                                <Input
                                    id="cal-room"
                                    value={form.data.room}
                                    onChange={(e) => form.setData('room', e.target.value)}
                                    placeholder="e.g. Lounge, Vehicle 2, Meeting room"
                                />
                            </div>

                            {people.length > 0 && (
                                <div>
                                    <Label className="mb-1.5 block">Attendees</Label>
                                    <div className="flex max-h-28 flex-wrap gap-1.5 overflow-y-auto rounded-md border border-input bg-background/40 p-2">
                                        {people.map((p) => {
                                            const on = form.data.attendee_user_ids.includes(p.id);
                                            return (
                                                <button
                                                    key={p.id}
                                                    type="button"
                                                    onClick={() =>
                                                        form.setData(
                                                            'attendee_user_ids',
                                                            on
                                                                ? form.data.attendee_user_ids.filter((id) => id !== p.id)
                                                                : [...form.data.attendee_user_ids, p.id],
                                                        )
                                                    }
                                                    className={`rounded-full border px-2.5 py-1 text-[12px] font-medium transition-colors ${on ? 'border-primary bg-primary/10 text-primary' : 'border-border text-muted-foreground hover:bg-accent'}`}
                                                >
                                                    {p.name}
                                                </button>
                                            );
                                        })}
                                    </div>
                                </div>
                            )}

                            <div>
                                <Label className="mb-1.5 block">Reminders</Label>
                                <div className="flex flex-wrap gap-1.5">
                                    {REMINDER_PRESETS.map((r) => {
                                        const on = form.data.reminder_minutes.includes(r.minutes);
                                        return (
                                            <button
                                                key={r.minutes}
                                                type="button"
                                                onClick={() =>
                                                    form.setData(
                                                        'reminder_minutes',
                                                        on
                                                            ? form.data.reminder_minutes.filter((m) => m !== r.minutes)
                                                            : [...form.data.reminder_minutes, r.minutes].sort((a, b) => a - b),
                                                    )
                                                }
                                                className={`rounded-full border px-2.5 py-1 text-[12px] font-medium transition-colors ${on ? 'border-primary bg-primary/10 text-primary' : 'border-border text-muted-foreground hover:bg-accent'}`}
                                            >
                                                {r.label}
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>

                            <div>
                                <Label htmlFor="cal-desc" className="mb-1.5 block">
                                    Description
                                </Label>
                                <Textarea id="cal-desc" rows={3} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} placeholder="Optional details" />
                            </div>

                            {selectedType?.requires_approval && !editEvent && (
                                <p className="rounded-md bg-status-warning-bg px-3 py-2 text-[13px] text-status-warning">
                                    This type requires approval — it will be submitted as pending.
                                </p>
                            )}

                            {conflicts.length > 0 && (
                                <p className="rounded-md bg-status-critical-bg px-3 py-2 text-[13px] text-status-critical">
                                    Possible clash with {conflicts.length} other {conflicts.length === 1 ? 'entry' : 'entries'} at this time
                                    {conflicts[0]?.room ? ` in ${conflicts[0].room}` : ''}.
                                </p>
                            )}

                            <DialogFooter>
                                <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={form.processing || !targetSite}>
                                    {form.processing
                                        ? 'Saving…'
                                        : editEvent
                                          ? 'Save changes'
                                          : selectedType?.requires_approval
                                            ? 'Submit for approval'
                                            : 'Create entry'}
                                </Button>
                            </DialogFooter>
                        </form>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}

/* ---- subscribe feed dialog ---------------------------------------------- */

function SubscribeDialog({
    open,
    onOpenChange,
    feedUrl,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    feedUrl?: string | null;
}) {
    const form = useForm({});
    const [copied, setCopied] = useState(false);
    const webcal = feedUrl ? feedUrl.replace(/^https?:/, 'webcal:') : null;

    const copy = async () => {
        if (!feedUrl) return;
        try {
            await navigator.clipboard.writeText(feedUrl);
            setCopied(true);
            setTimeout(() => setCopied(false), 1500);
        } catch {
            /* clipboard unavailable */
        }
    };

    const generate = () => form.post('/calendar/feed/reset', { preserveScroll: true, preserveState: true });

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent style={{ maxWidth: 'min(92vw, 540px)' }}>
                <DialogHeader>
                    <DialogTitle>Subscribe to this calendar</DialogTitle>
                    <DialogDescription>
                        Add a live, read-only feed of these entries to your own Google, Outlook or Apple calendar. Keep this link private to you.
                    </DialogDescription>
                </DialogHeader>

                {feedUrl ? (
                    <div className="space-y-3 text-sm">
                        <div className="flex items-center gap-2">
                            <Input readOnly value={feedUrl} onFocus={(e) => e.currentTarget.select()} />
                            <Button variant="outline" size="sm" onClick={copy}>
                                <Copy className="mr-1 h-3.5 w-3.5" />
                                {copied ? 'Copied' : 'Copy'}
                            </Button>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            {webcal && (
                                <Button variant="secondary" size="sm" asChild>
                                    <a href={webcal}>Subscribe in calendar app</a>
                                </Button>
                            )}
                            <Button variant="ghost" size="sm" onClick={generate} disabled={form.processing}>
                                <RefreshCw className="mr-1 h-3.5 w-3.5" /> Reset link
                            </Button>
                        </div>
                        <p className="text-[12px] text-muted-foreground">Resetting immediately invalidates the previous link.</p>
                    </div>
                ) : (
                    <div className="space-y-3 text-sm">
                        <p className="text-muted-foreground">Generate a private subscribe link to follow these entries from your personal calendar.</p>
                        <Button size="sm" onClick={generate} disabled={form.processing}>
                            <Rss className="mr-1 h-3.5 w-3.5" /> Generate subscribe link
                        </Button>
                    </div>
                )}

                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)}>
                        Close
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/* ---- right-click quick-add menu ----------------------------------------- */

function QuickAddMenu({
    ctx,
    eventTypes,
    siteName,
    onPick,
    onForm,
    onClose,
}: {
    ctx: { x: number; y: number; date: Date; hour?: number };
    eventTypes: EventTypeOption[];
    siteName?: string;
    onPick: (eventType: string) => void;
    onForm: () => void;
    onClose: () => void;
}) {
    const ref = useRef<HTMLDivElement | null>(null);
    const [pos, setPos] = useState({ top: ctx.y, left: ctx.x });

    useEffect(() => {
        const el = ref.current;
        if (!el) return;
        const r = el.getBoundingClientRect();
        let left = ctx.x;
        let top = ctx.y;
        if (left + r.width + 8 > window.innerWidth) left = window.innerWidth - r.width - 8;
        if (top + r.height + 8 > window.innerHeight) top = window.innerHeight - r.height - 8;
        setPos({ top: Math.max(8, top), left: Math.max(8, left) });
    }, [ctx]);

    useEffect(() => {
        const onDown = (e: MouseEvent) => {
            if (!ref.current?.contains(e.target as Node)) onClose();
        };
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        window.addEventListener('mousedown', onDown);
        window.addEventListener('keydown', onKey);
        return () => {
            window.removeEventListener('mousedown', onDown);
            window.removeEventListener('keydown', onKey);
        };
    }, [onClose]);

    const hourDate = ctx.hour != null ? new Date(ctx.date) : null;
    if (hourDate && ctx.hour != null) hourDate.setHours(ctx.hour, 0, 0, 0);
    const where = `${siteName ? `to ${siteName} · ` : ''}${WD[ctx.date.getDay()]} ${ctx.date.getDate()} ${MO[ctx.date.getMonth()].slice(0, 3)}${hourDate ? ` · ${fmtTime(hourDate)}` : ''}`;

    return createPortal(
        <div
            ref={ref}
            role="menu"
            style={{ top: pos.top, left: pos.left }}
            className="fixed z-[60] w-[286px] rounded-xl border bg-popover p-1.5 text-popover-foreground shadow-2xl"
        >
            <div className="mb-1 flex items-center gap-2 border-b px-2 py-1.5">
                <span className="flex items-center gap-1 rounded bg-primary/10 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-primary">
                    <Plus className="h-3 w-3" /> Add
                </span>
                <span className="truncate text-[11px] text-muted-foreground">{where}</span>
            </div>
            <ul className="max-h-[260px] space-y-px overflow-y-auto">
                {eventTypes.map((t) => (
                    <li key={t.key}>
                        <button
                            role="menuitem"
                            onClick={() => onPick(t.key)}
                            className="grid w-full grid-cols-[26px_1fr_auto] items-center gap-2.5 rounded-md px-2 py-1.5 text-left text-[12.5px] transition-colors hover:bg-accent"
                        >
                            <span
                                className="inline-flex h-[26px] w-[26px] items-center justify-center rounded-md"
                                style={{ background: `${t.color}1a` }}
                            >
                                <span className="h-2.5 w-2.5 rounded-full" style={{ background: t.color }} />
                            </span>
                            <span className="min-w-0 truncate font-medium text-foreground">{t.label}</span>
                            {t.requires_approval && (
                                <span className="rounded border border-status-warning/30 bg-status-warning-bg px-1 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-status-warning">
                                    Approval
                                </span>
                            )}
                        </button>
                    </li>
                ))}
            </ul>
            <div className="my-1 h-px bg-border/60" />
            <button
                onClick={onForm}
                className="flex w-full items-center gap-2.5 rounded-md px-2 py-1.5 text-left text-[12.5px] text-muted-foreground transition-colors hover:bg-accent"
            >
                <span className="inline-flex h-[26px] w-[26px] items-center justify-center rounded-md bg-muted">
                    <Pencil className="h-3.5 w-3.5" />
                </span>
                Open full form…
            </button>
        </div>,
        document.body,
    );
}

/* ---- hover preview card ------------------------------------------------- */

function EventHoverCard({
    ev,
    rect,
    srcByKey,
    eventTypeByKey,
}: {
    ev: Decorated;
    rect: DOMRect;
    srcByKey: Record<string, SourceDef>;
    eventTypeByKey: Record<string, EventTypeOption>;
}) {
    const W = 282;
    let left = rect.right + 10;
    if (left + W > window.innerWidth - 8) left = rect.left - W - 10;
    if (left < 8) left = 8;
    const top = Math.max(10, Math.min(rect.top - 4, window.innerHeight - 240));
    const src = srcByKey[ev.source];
    const typeLabel = ev.eventType ? (eventTypeByKey[ev.eventType]?.label ?? ev.eventType) : (src?.label ?? ev.source);
    const when = ev.allDay ? 'All day' : fmtTimeRange(ev._start, ev._end);

    return createPortal(
        <div
            className="pointer-events-none fixed z-[65] w-[282px] overflow-hidden rounded-xl border bg-popover text-popover-foreground shadow-2xl"
            style={{ top, left }}
        >
            <div className="h-1 w-full" style={{ background: `var(--src-${ev.source})` }} />
            <div className="space-y-2 p-3">
                <div className="flex items-center gap-1.5 text-[11px] font-medium" style={{ color: `var(--src-${ev.source})` }}>
                    <SourceDot k={ev.source} />
                    {typeLabel}
                    {ev.ref && <span className="tnum text-muted-foreground">· {ev.ref}</span>}
                </div>
                <p className="text-[14px] font-semibold leading-tight text-foreground">{ev.title}</p>
                <StatusBadge status={ev.status} />
                <dl className="space-y-1 text-[12px] text-muted-foreground">
                    <div className="flex items-center gap-1.5">
                        <Clock className="h-3.5 w-3.5 shrink-0" />
                        <span>
                            {ev._start.toLocaleDateString('en-NZ', { weekday: 'short', day: 'numeric', month: 'short' })} · {when}
                        </span>
                    </div>
                    {ev.room && (
                        <div className="flex items-center gap-1.5">
                            <MapPin className="h-3.5 w-3.5 shrink-0" />
                            <span className="truncate">{ev.room}</span>
                        </div>
                    )}
                    {ev.owner && (
                        <div className="flex items-center gap-1.5">
                            <Avatar person={ev.owner} size="h-4 w-4" />
                            <span className="truncate">{ev.owner.name}</span>
                        </div>
                    )}
                    {ev.recurrence && (
                        <div className="flex items-center gap-1.5">
                            <Repeat className="h-3.5 w-3.5 shrink-0" />
                            <span className="truncate">{ruleToText(ev.recurrence)}</span>
                        </div>
                    )}
                </dl>
                {ev.desc && <p className="line-clamp-2 text-[12px] text-foreground/80">{ev.desc}</p>}
                <p className="border-t pt-1.5 text-[10.5px] text-muted-foreground/70">
                    Click to open · {ev.group === 'manual' ? 'Manual entry' : 'Auto-synced obligation'}
                </p>
            </div>
        </div>,
        document.body,
    );
}

/* ---- approvals panel ---------------------------------------------------- */

function ApprovalsPanel({
    open,
    onOpenChange,
    items,
    eventTypeByKey,
    onOpenEvent,
    onChanged,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    items: Decorated[];
    eventTypeByKey: Record<string, EventTypeOption>;
    onOpenEvent: (ev: Decorated) => void;
    onChanged: () => void;
}) {
    const form = useForm({});
    const act = (ev: Decorated, action: 'approve' | 'reject') => {
        if (!ev.site) return;
        form.post(`/sites/${ev.site.id}/calendar/events/${ev.seriesId ?? ev.id}/${action}`, {
            preserveScroll: true,
            onSuccess: onChanged,
        });
    };
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent style={{ maxWidth: 'min(92vw, 560px)' }}>
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <span className="flex h-8 w-8 items-center justify-center rounded-xl bg-status-warning-bg text-status-warning">
                            <ClipboardCheck className="h-4 w-4" />
                        </span>
                        Awaiting approval
                    </DialogTitle>
                    <DialogDescription>
                        {items.length === 0
                            ? 'Nothing needs review right now.'
                            : `${items.length} ${items.length === 1 ? 'entry needs' : 'entries need'} review.`}
                    </DialogDescription>
                </DialogHeader>

                {items.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 py-10 text-muted-foreground">
                        <CheckCircle2 className="h-10 w-10 opacity-40" />
                        <p className="text-sm">You&apos;re all caught up.</p>
                    </div>
                ) : (
                    <div className="max-h-[60vh] space-y-2 overflow-y-auto">
                        {items.map((ev) => {
                            const typeLabel = ev.eventType ? (eventTypeByKey[ev.eventType]?.label ?? ev.eventType) : null;
                            return (
                                <div key={`${ev.id}-${ev.start}`} className="flex items-center gap-3 rounded-lg border bg-card/40 p-2.5">
                                    <span className="h-9 w-1.5 shrink-0 rounded-full" style={{ background: `var(--src-${ev.source})` }} />
                                    <button onClick={() => onOpenEvent(ev)} className="min-w-0 flex-1 text-left">
                                        <span className="block truncate text-sm font-medium">{ev.title}</span>
                                        <span className="block truncate text-[12px] text-muted-foreground">
                                            {ev._start.toLocaleDateString('en-NZ', { weekday: 'short', day: 'numeric', month: 'short' })}
                                            {!ev.allDay && ` · ${fmtTime(ev._start)}`}
                                            {typeLabel && ` · ${typeLabel}`}
                                        </span>
                                    </button>
                                    {ev.owner && <Avatar person={ev.owner} size="h-6 w-6" />}
                                    <div className="flex shrink-0 items-center gap-1.5">
                                        <Button variant="outline" size="xs" disabled={form.processing} onClick={() => act(ev, 'reject')}>
                                            <X className="mr-1 h-3.5 w-3.5" /> Reject
                                        </Button>
                                        <Button size="xs" disabled={form.processing} onClick={() => act(ev, 'approve')}>
                                            <Check className="mr-1 h-3.5 w-3.5" /> Approve
                                        </Button>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}

                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)}>
                        Close
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
