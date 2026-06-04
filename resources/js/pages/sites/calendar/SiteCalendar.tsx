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
import { Link, router, useForm } from '@inertiajs/react';
import {
    CalendarDays,
    CalendarRange,
    CalendarClock,
    Check,
    ChevronLeft,
    ChevronRight,
    Columns3,
    Copy,
    Download,
    ExternalLink,
    List,
    Pencil,
    Plus,
    RefreshCw,
    Rows3,
    Rss,
    Trash2,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react';
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
    StatusBadge,
    TimelineView,
    WeekView,
    addDays,
    decorate,
    fmtTimeRange,
    MO,
    startOfMonth,
    startOfWeek,
    type Decorated,
    type Density,
    type SourceDef,
} from './_parts';

type SiteLite = { id: number; name: string; type: string };

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
    sources?: SourceDef[];
    eventTypes?: EventTypeOption[];
    canCreate: boolean;
    canManage?: boolean;
    canApprove?: boolean;
    feedUrl?: string | null;
}

type CalView = 'month' | 'week' | 'day' | 'agenda' | 'timeline';

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
    { key: 'meal', label: 'Meal plan', short: 'Meal', group: 'auto', icon: 'Utensils', origin: 'Meal planner' },
    { key: 'damage', label: 'Damage follow-up', short: 'Damage', group: 'auto', icon: 'Hammer', origin: 'Damages' },
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
    sources = DEFAULT_SOURCES,
    eventTypes = DEFAULT_EVENT_TYPES,
    canCreate,
    canManage = false,
    canApprove = false,
    feedUrl,
}: SiteCalendarProps) {
    const [view, setView] = useState<CalView>('month');
    const [navDate, setNavDate] = useState(() => new Date());
    const [colorBy, setColorBy] = useState<ColorBy>('source');
    const [density, setDensity] = useState<Density>('comfortable');
    const [events, setEvents] = useState<Decorated[]>([]);
    const [loading, setLoading] = useState(true);
    const [enabledSources, setEnabledSources] = useState<Set<string>>(() => new Set(sources.map((s) => s.key)));
    const [houseFilter, setHouseFilter] = useState<number | 'all'>('all');
    const [selected, setSelected] = useState<Decorated | null>(null);
    const [createOpen, setCreateOpen] = useState(false);
    const [editEvent, setEditEvent] = useState<Decorated | null>(null);
    const [subscribeOpen, setSubscribeOpen] = useState(false);

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

    const visibleEvents = useMemo(
        () =>
            events.filter(
                (e) =>
                    enabledSources.has(e.source) &&
                    (scope !== 'global' || houseFilter === 'all' || e.site?.id === houseFilter),
            ),
        [events, enabledSources, scope, houseFilter],
    );

    const overdueCount = useMemo(() => visibleEvents.filter((e) => e.status === 'overdue').length, [visibleEvents]);

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
                { preserveScroll: true, preserveState: true, onSuccess: () => void fetchEvents() },
            );
        },
        [fetchEvents],
    );

    const uiValue = useMemo(
        () => ({
            colorBy,
            density,
            srcByKey,
            onSelect: (ev: Decorated) => setSelected(ev),
            onCreateAt: canCreate ? () => setCreateOpen(true) : undefined,
            onMove: canManage ? reschedule : undefined,
        }),
        [colorBy, density, srcByKey, canCreate, canManage, reschedule],
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
            {toolbar}
            {legend}
            {ViewBody}
            {loading && <p className="text-center text-sm text-muted-foreground">Loading…</p>}
        </div>
    );

    const heroActions = canCreate ? (
        <Button variant="secondary" size="sm" onClick={() => setCreateOpen(true)}>
            <Plus className="mr-1 h-4 w-4" /> New event
        </Button>
    ) : undefined;

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
                    void fetchEvents();
                }}
            />
            {(canCreate || canManage) && (
                <CreateEventDialog
                    open={createOpen}
                    onOpenChange={(o) => {
                        setCreateOpen(o);
                        if (!o) setEditEvent(null);
                    }}
                    scope={scope}
                    sites={sites}
                    defaultSiteId={createSiteId}
                    site={site}
                    eventTypes={eventTypes}
                    editEvent={editEvent}
                    existingEvents={events}
                    onSaved={() => {
                        setCreateOpen(false);
                        setEditEvent(null);
                        void fetchEvents();
                    }}
                />
            )}
            <SubscribeDialog open={subscribeOpen} onOpenChange={setSubscribeOpen} feedUrl={feedUrl} />
        </>
    );

    if (context === 'profile') {
        return (
            <div className="space-y-3">
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
                        icon={CalendarDays}
                        title="Site Calendar"
                        description={scope === 'global' ? 'All sites — obligations & events' : (site?.name ?? '')}
                        backHref={scope === 'site' && site ? `/sites/${site.id}` : undefined}
                        stats={[
                            { label: 'In view', value: visibleEvents.length },
                            { label: 'Overdue', value: overdueCount },
                        ]}
                        actions={heroActions}
                    />
                }
            >
                {content}
            </PageLayout>
            {dialogs}
        </>
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
    defaultSiteId,
    site,
    eventTypes,
    editEvent,
    existingEvents,
    onSaved,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    scope: 'global' | 'site';
    sites: SiteLite[];
    defaultSiteId?: number;
    site?: SiteLite;
    eventTypes: EventTypeOption[];
    editEvent?: Decorated | null;
    existingEvents: Decorated[];
    onSaved: () => void;
}) {
    const [targetSite, setTargetSite] = useState<number | undefined>(defaultSiteId);
    const [preset, setPreset] = useState<RecurPreset>('none');
    const form = useForm({
        event_type: eventTypes[0]?.key ?? 'general',
        title: '',
        description: '',
        start_at: '',
        end_at: '',
        recurrence_rule: '' as string,
    });

    useEffect(() => {
        if (!open) return;
        if (editEvent) {
            setTargetSite(editEvent.site?.id ?? defaultSiteId);
            setPreset(ruleToPreset(editEvent.recurrence ?? null));
            form.setData({
                event_type: editEvent.eventType ?? eventTypes[0]?.key ?? 'general',
                title: editEvent.title,
                description: editEvent.desc ?? '',
                start_at: toLocalInput(editEvent._start),
                end_at: editEvent._end ? toLocalInput(editEvent._end) : '',
                recurrence_rule: '',
            });
        } else {
            setTargetSite(defaultSiteId);
            setPreset('none');
            const start = new Date();
            start.setMinutes(0, 0, 0);
            start.setHours(start.getHours() + 1);
            const end = new Date(start.getTime() + 60 * 60_000);
            form.setData({
                event_type: eventTypes[0]?.key ?? 'general',
                title: '',
                description: '',
                start_at: toLocalInput(start),
                end_at: toLocalInput(end),
                recurrence_rule: '',
            });
        }
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, editEvent, defaultSiteId]);

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
            allDay: false,
            status: 'scheduled',
            owner: null,
            room: editEvent?.room ?? null,
            ref: null,
            site: editEvent?.site ?? null,
            link: null,
            editable: true,
        };
        return findConflicts(draft, existingEvents);
    }, [form.data.start_at, form.data.end_at, form.data.title, editEvent, existingEvents]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!targetSite) return;
        const rule = presetToRule(preset);
        form.transform((data) => ({ ...data, recurrence_rule: rule ? (toRRULE(rule) ?? '') : '' }));
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
                            <DialogTitle>{editEvent ? 'Edit event' : 'New calendar event'}</DialogTitle>
                            <DialogDescription>
                                {editEvent ? 'Update this calendar entry.' : 'Add a manual entry to the house calendar.'}
                            </DialogDescription>
                        </DialogHeader>

                        <form onSubmit={submit} className="space-y-4">
                            {/* Type tile picker */}
                            <div>
                                <Label className="mb-1.5 block">
                                    Type <span className="text-status-critical">*</span>
                                </Label>
                                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                    {eventTypes.map((t) => {
                                        const active = form.data.event_type === t.key;
                                        return (
                                            <button
                                                type="button"
                                                key={t.key}
                                                onClick={() => form.setData('event_type', t.key)}
                                                className={`flex items-center gap-2 rounded-lg border p-2.5 text-left text-[13px] transition-colors ${active ? 'border-primary bg-primary/10 ring-1 ring-primary' : 'hover:bg-accent/50'}`}
                                                aria-pressed={active}
                                            >
                                                <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: t.color }} />
                                                <span className="truncate font-medium">{t.label}</span>
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>

                            {/* Locked site (site scope) / picker (global) */}
                            {scope === 'site' && site ? (
                                <div className="rounded-lg border border-primary/30 bg-primary/5 p-2.5 text-[13px]">
                                    <span className="text-muted-foreground">Site</span>{' '}
                                    <span className="font-medium">{site.name}</span>
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

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label htmlFor="cal-start" className="mb-1.5 block">
                                        Start <span className="text-status-critical">*</span>
                                    </Label>
                                    <Input id="cal-start" type="datetime-local" value={form.data.start_at} onChange={(e) => form.setData('start_at', e.target.value)} required />
                                    {form.errors.start_at && <p className="mt-1 text-sm text-status-critical">{form.errors.start_at}</p>}
                                </div>
                                <div>
                                    <Label htmlFor="cal-end" className="mb-1.5 block">
                                        End
                                    </Label>
                                    <Input id="cal-end" type="datetime-local" value={form.data.end_at} onChange={(e) => form.setData('end_at', e.target.value)} />
                                    {form.errors.end_at && <p className="mt-1 text-sm text-status-critical">{form.errors.end_at}</p>}
                                </div>
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
                                    {form.processing ? 'Saving…' : editEvent ? 'Save changes' : 'Create event'}
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
