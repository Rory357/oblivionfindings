/* eslint-disable no-restricted-syntax -- The command-center hero, stat cluster,
 * ack donut, toolbar and bulk bar are bespoke on-page surfaces (raw
 * <button>/<input>) styled with semantic design tokens only. */
import {
    useAnnouncementContextMenu,
    type AnnouncementCtxItem,
} from '@/components/hr/announcement-context-menu';
import {
    AnnouncementWizard,
    type AnnouncementSegments,
    type AnnouncementWizardInitial,
} from '@/components/hr/announcement-wizard';
import { HrTabs, type HrTabItem } from '@/components/hr/hr-tabs';
import PageShell from '@/components/page-shell';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import {
    AlertCircle,
    AlertTriangle,
    Archive,
    BarChart3,
    BellRing,
    CalendarClock,
    CalendarDays,
    CheckCheck,
    Copy,
    FileDown,
    Info,
    LayoutGrid,
    LayoutList,
    Link as LinkIcon,
    List,
    MapPin,
    Megaphone,
    MoreHorizontal,
    Pencil,
    Pin,
    PinOff,
    Plus,
    Search,
    Send,
    SlidersHorizontal,
    Star,
    Trash2,
    User,
    Users,
} from 'lucide-react';
import { useEffect, useRef, useState, type CSSProperties } from 'react';
import { toast } from 'sonner';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

type Priority = 'low' | 'normal' | 'high' | 'urgent';

type AnnouncementCard = {
    id: number;
    title: string;
    excerpt: string;
    priority: Priority;
    status: string;
    is_pinned: boolean;
    requires_acknowledgement: boolean;
    audience: string;
    audience_size: number;
    acknowledged_count: number;
    ack_pct: number;
    attachments_count: number;
    reactions_count: number;
    replies_count: number;
    creator: { id: number; name: string } | null;
    published_at: string | null;
    expires_at: string | null;
};

type RosterRow = {
    id: number;
    name: string;
    role: string;
    site: string;
    status: 'acknowledged' | 'reminded' | 'outstanding';
    acknowledged_at: string | null;
};

type TrackingData = {
    id: number;
    title: string;
    priority: Priority;
    audience: string;
    ack_deadline: string | null;
    published_at: string | null;
    total: number;
    acknowledged: number;
    outstanding: number;
    ack_pct: number;
    by_site: { name: string; pct: number }[];
    by_role: { name: string; pct: number }[];
    roster: RosterRow[];
};

type TrackingListItem = {
    id: number;
    title: string;
    priority: Priority;
    audience: string;
    acknowledged_count: number;
    audience_size: number;
    ack_pct: number;
    ack_deadline: string | null;
    published_at: string | null;
};

type ScheduledItem = {
    id: number;
    title: string;
    status: string;
    sends_at: string | null;
    audience: string;
    recurrence: string | null;
    can_publish: boolean;
};

type Insights = {
    kpis: {
        avg_ack_rate: number;
        avg_time_to_ack_hours: number;
        reminders_30d: number;
        outstanding: number;
    };
    trend: { label: string; pct: number }[];
    top_unacknowledged: { id: number; title: string; outstanding: number }[];
};

type Summary = {
    live: number;
    pinned: number;
    scheduled: number;
    requires_ack: number;
    requires_ack_pct: number;
    outstanding_reminders: number;
    ack_health: {
        pct: number;
        acknowledged: number;
        outstanding: number;
        required_notices: number;
        below_target: number;
        scheduled_soon: number;
    };
    needs_you: {
        type: string;
        announcement_id: number | null;
        label: string;
    }[];
};

type Filters = {
    search: string | null;
    priority: string | null;
    status: string | null;
    audience: string | null;
    sort: string | null;
};

type Props = {
    tab: string;
    filters: Filters;
    priorities: { value: string; label: string }[];
    audiences: { value: string; label: string }[];
    statuses: { value: string; label: string }[];
    segments: AnnouncementSegments;
    summary: Summary;
    tabCounts: {
        all: number;
        pinned: number;
        tracking: number;
        scheduled: number;
    };
    can: { manage: boolean };
    announcements?: {
        data: AnnouncementCard[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    scheduled?: ScheduledItem[];
    trackingList?: TrackingListItem[];
    tracking?: TrackingData | null;
    insights?: Insights;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Announcements', href: '/hr/announcements' },
];

const HERO_STYLE: CSSProperties = {
    ['--hr-amber' as string]: 'oklch(0.86 0.13 90)',
    background:
        'linear-gradient(120deg, color-mix(in oklch, var(--primary) 72%, black 22%), var(--primary) 60%, color-mix(in oklch, var(--primary) 92%, white 6%))',
    boxShadow:
        '0 28px 64px -30px color-mix(in oklch, var(--primary) 86%, black)',
};

const PRIORITY_META: Record<
    Priority,
    { label: string; variant: StatusVariant; icon: typeof Info }
> = {
    low: { label: 'Low', variant: 'neutral', icon: Info },
    normal: { label: 'Normal', variant: 'info', icon: Info },
    high: { label: 'High', variant: 'warning', icon: AlertTriangle },
    urgent: { label: 'Urgent', variant: 'critical', icon: AlertCircle },
};

const STATUS_VARIANT: Record<string, StatusVariant> = {
    published: 'success',
    scheduled: 'info',
    draft: 'neutral',
    archived: 'neutral',
};

function fmtDate(value?: string | null): string {
    if (!value) return '—';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          });
}

function fmtDateTime(value?: string | null): string {
    if (!value) return '—';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleString('en-NZ', {
              day: '2-digit',
              month: 'short',
              hour: '2-digit',
              minute: '2-digit',
          });
}

function ackBar(pct: number): string {
    return pct >= 85
        ? 'var(--status-success)'
        : pct >= 60
          ? 'var(--hr-amber)'
          : 'var(--status-critical)';
}

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function AnnouncementsIndex(props: Props) {
    const { tab, filters, summary, tabCounts, can, segments } = props;
    const [search, setSearch] = useState(filters.search ?? '');
    const [selected, setSelected] = useState<number[]>([]);
    const [wizardOpen, setWizardOpen] = useState(false);
    const [editing, setEditing] = useState<{
        id: number;
        initial: AnnouncementWizardInitial;
    } | null>(null);
    const searchRef = useRef<HTMLInputElement>(null);
    const { open: openCtx, element: ctxElement } = useAnnouncementContextMenu();
    const [defaultTab, setDefaultTab] = useState<string>(
        () =>
            (typeof window !== 'undefined' &&
                localStorage.getItem('hr.announcements.defaultTab')) ||
            'all',
    );

    // Honour the saved default view on a bare landing (no ?tab=).
    useEffect(() => {
        if (typeof window === 'undefined') return;
        const hasTab = new URLSearchParams(window.location.search).has('tab');
        const canOpenDefault =
            can.manage || ['all', 'pinned'].includes(defaultTab);
        if (
            !hasTab &&
            canOpenDefault &&
            defaultTab !== 'all' &&
            defaultTab !== tab
        ) {
            router.get(
                '/hr/announcements',
                { tab: defaultTab },
                { preserveScroll: true, replace: true },
            );
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const setAsDefault = (id: string) => {
        localStorage.setItem('hr.announcements.defaultTab', id);
        setDefaultTab(id);
        toast.success(`"${id}" is now your default view`);
    };

    const go = (
        next: Partial<Filters & { tab: string; announcement: number }>,
    ) => {
        router.get(
            '/hr/announcements',
            { tab, ...filters, search: search || undefined, ...next },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const openComposer = () => {
        setEditing(null);
        setWizardOpen(true);
    };

    const openEditor = (
        card: { id: number } & Partial<AnnouncementWizardInitial>,
    ) => {
        setEditing({ id: card.id, initial: card });
        setWizardOpen(true);
    };

    // Keyboard: / focuses search, n opens composer.
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            const target = e.target as HTMLElement;
            if (
                target.tagName === 'INPUT' ||
                target.tagName === 'TEXTAREA' ||
                target.isContentEditable
            )
                return;
            if (e.key === '/') {
                e.preventDefault();
                searchRef.current?.focus();
            } else if (e.key === 'n' && can.manage) {
                e.preventDefault();
                openComposer();
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [can.manage]);

    const post = (url: string, body: Record<string, unknown>, msg?: string) => {
        router.post(url, body as Parameters<typeof router.post>[1], {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => msg && toast.success(msg),
        });
    };

    const managerTabs: HrTabItem[] = [
        {
            id: 'tracking',
            label: 'Tracking',
            icon: CheckCheck,
            tone: 'info',
            badge: tabCounts.tracking || undefined,
        },
        {
            id: 'scheduled',
            label: 'Scheduled',
            icon: CalendarClock,
            tone: 'violet',
            badge: tabCounts.scheduled || undefined,
        },
        {
            id: 'insights',
            label: 'Insights',
            icon: BarChart3,
            tone: 'success',
        },
    ];
    const tabs: HrTabItem[] = [
        {
            id: 'all',
            label: 'All',
            icon: LayoutList,
            tone: 'primary',
            badge: tabCounts.all || undefined,
        },
        {
            id: 'pinned',
            label: 'Pinned',
            icon: Pin,
            tone: 'warning',
            badge: tabCounts.pinned || undefined,
        },
        ...(can.manage ? managerTabs : []),
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Announcements" />
            <PageShell>
                {/* ── HERO ── */}
                <div
                    style={HERO_STYLE}
                    className="relative overflow-hidden rounded-3xl text-primary-foreground"
                >
                    <div className="pointer-events-none absolute inset-0 overflow-hidden">
                        <div className="absolute -top-24 right-[22%] h-64 w-64 rounded-full bg-white/[0.05]" />
                        <div className="absolute -right-10 -bottom-28 h-72 w-72 rounded-full bg-white/[0.04]" />
                    </div>

                    <div className="relative flex flex-wrap items-start justify-between gap-7 p-8 pb-6">
                        <div className="min-w-0 flex-1">
                            <div className="flex items-center gap-3.5">
                                <span className="grid h-14 w-14 place-items-center rounded-2xl border border-white/20 bg-white/15">
                                    <Megaphone className="h-6 w-6" />
                                </span>
                                <div>
                                    <h1 className="text-2xl font-bold tracking-tight">
                                        Company communications
                                    </h1>
                                    <p className="mt-1 flex flex-wrap items-center gap-2.5 text-[12.5px] font-medium text-white/80">
                                        <span className="inline-flex items-center gap-1.5">
                                            <CalendarDays className="h-3.5 w-3.5" />
                                            {new Date().toLocaleDateString(
                                                'en-NZ',
                                                {
                                                    weekday: 'long',
                                                    day: 'numeric',
                                                    month: 'long',
                                                    year: 'numeric',
                                                },
                                            )}
                                        </span>
                                        <span className="opacity-40">·</span>
                                        <span className="inline-flex items-center gap-1.5">
                                            <MapPin className="h-3.5 w-3.5" />
                                            {can.manage
                                                ? 'All sites'
                                                : 'Relevant to you'}
                                        </span>
                                    </p>
                                </div>
                            </div>

                            {/* stat cluster */}
                            <div className="mt-5 -ml-2 flex flex-wrap">
                                <HeroStat
                                    label="Live"
                                    value={summary.live}
                                    onClick={() =>
                                        go({ tab: 'all', status: undefined })
                                    }
                                />
                                <HeroStat
                                    label="Pinned"
                                    value={summary.pinned}
                                    onClick={() => go({ tab: 'pinned' })}
                                />
                                {can.manage && (
                                    <>
                                        <HeroStat
                                            label="Requires ack"
                                            value={summary.requires_ack}
                                            suffix={
                                                summary.requires_ack
                                                    ? `· ${summary.requires_ack_pct}%`
                                                    : undefined
                                            }
                                            onClick={() =>
                                                go({ tab: 'tracking' })
                                            }
                                        />
                                        <HeroStat
                                            label="Scheduled"
                                            value={summary.scheduled}
                                            onClick={() =>
                                                go({ tab: 'scheduled' })
                                            }
                                        />
                                        <HeroStat
                                            label="You owe reminders"
                                            value={
                                                summary.outstanding_reminders
                                            }
                                            amber
                                            onClick={() =>
                                                go({ tab: 'tracking' })
                                            }
                                        />
                                    </>
                                )}
                            </div>

                            {/* quick actions */}
                            {can.manage && (
                                <div className="mt-5 flex flex-wrap gap-3 text-[12.5px] font-semibold">
                                    <button
                                        onClick={openComposer}
                                        className="inline-flex items-center gap-1.5 rounded-xl bg-white/15 px-3.5 py-2 hover:bg-white/25"
                                    >
                                        <Plus className="h-4 w-4" /> New
                                        announcement
                                    </button>
                                    <button
                                        onClick={openComposer}
                                        className="inline-flex items-center gap-1.5 rounded-xl px-1 py-2 text-white/90 hover:text-white"
                                    >
                                        <CalendarClock className="h-4 w-4" />{' '}
                                        Schedule
                                    </button>
                                    <button
                                        onClick={() => go({ tab: 'tracking' })}
                                        className="inline-flex items-center gap-1.5 rounded-xl px-1 py-2 text-white/90 hover:text-white"
                                    >
                                        <FileDown className="h-4 w-4" />{' '}
                                        Acknowledgement report
                                    </button>
                                    <button
                                        onClick={() => go({ tab: 'tracking' })}
                                        className="inline-flex items-center gap-1.5 rounded-xl px-1 py-2 text-white/90 hover:text-white"
                                    >
                                        <BellRing className="h-4 w-4" /> Send
                                        reminders
                                    </button>
                                </div>
                            )}
                        </div>

                        {/* ack-health donut */}
                        {can.manage && (
                            <div className="w-[248px] shrink-0 rounded-2xl border border-white/12 bg-black/15 p-4">
                                <div className="text-[10px] font-bold tracking-wide text-white/55 uppercase">
                                    Acknowledgement health
                                </div>
                                <div className="mt-3.5 flex items-center gap-4">
                                    <div
                                        className="relative grid h-20 w-20 place-items-center rounded-full"
                                        style={{
                                            background: `conic-gradient(var(--hr-amber) 0% ${summary.ack_health.pct}%, rgba(255,255,255,.18) ${summary.ack_health.pct}% 100%)`,
                                        }}
                                    >
                                        <div
                                            className="absolute inset-2.5 grid place-items-center rounded-full"
                                            style={{
                                                background:
                                                    'color-mix(in oklch, var(--primary) 78%, black 20%)',
                                            }}
                                        >
                                            <span className="text-lg font-bold">
                                                {summary.ack_health.pct}%
                                            </span>
                                        </div>
                                    </div>
                                    <div className="text-xs leading-relaxed text-white/85">
                                        <div>
                                            <b>
                                                {
                                                    summary.ack_health
                                                        .acknowledged
                                                }
                                            </b>{' '}
                                            acknowledged
                                        </div>
                                        <div
                                            style={{ color: 'var(--hr-amber)' }}
                                        >
                                            <b>
                                                {summary.ack_health.outstanding}
                                            </b>{' '}
                                            outstanding
                                        </div>
                                        <div className="mt-0.5 text-white/60">
                                            across{' '}
                                            {
                                                summary.ack_health
                                                    .required_notices
                                            }{' '}
                                            required notices
                                        </div>
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>

                    {/* needs-you strip */}
                    {can.manage && summary.needs_you.length > 0 && (
                        <div className="relative flex flex-wrap items-center gap-3 border-t border-white/14 bg-black/10 px-6 py-2.5">
                            <span className="text-[10px] font-bold tracking-wide text-white/50 uppercase">
                                Needs you
                            </span>
                            {summary.needs_you.map((n, i) => (
                                <button
                                    key={i}
                                    onClick={() =>
                                        go({
                                            tab:
                                                n.type === 'scheduled_soon'
                                                    ? 'scheduled'
                                                    : 'tracking',
                                            announcement:
                                                n.announcement_id ?? undefined,
                                        })
                                    }
                                    className="inline-flex items-center gap-2 rounded-lg border border-white/20 bg-white/15 px-3 py-1.5 text-xs font-semibold hover:bg-white/25"
                                >
                                    <span
                                        className="h-1.5 w-1.5 rounded-full"
                                        style={{
                                            background: 'var(--hr-amber)',
                                        }}
                                    />
                                    {n.label}
                                </button>
                            ))}
                        </div>
                    )}
                </div>

                {/* ── TABS ── */}
                <div className="mt-4">
                    <HrTabs
                        value={tab}
                        onChange={(next) =>
                            router.get(
                                '/hr/announcements',
                                { tab: next },
                                { preserveScroll: true },
                            )
                        }
                        items={tabs}
                        decorations={{
                            [defaultTab]: (
                                <Star className="h-3 w-3 fill-current text-status-warning" />
                            ),
                        }}
                        onItemContextMenu={(id, e) =>
                            openCtx([
                                {
                                    kind: 'item',
                                    label: 'Set as default view',
                                    icon: Star,
                                    onSelect: () => setAsDefault(id),
                                },
                                {
                                    kind: 'item',
                                    label: 'Open',
                                    icon: LinkIcon,
                                    onSelect: () =>
                                        router.get(
                                            '/hr/announcements',
                                            { tab: id },
                                            { preserveScroll: true },
                                        ),
                                },
                            ])(e)
                        }
                    />
                </div>

                {/* ── PANELS ── */}
                <div className="mt-4">
                    {(tab === 'all' || tab === 'pinned') && (
                        <ListPanel
                            {...props}
                            search={search}
                            setSearch={setSearch}
                            searchRef={searchRef}
                            go={go}
                            selected={selected}
                            setSelected={setSelected}
                            openComposer={openComposer}
                            openEditor={openEditor}
                            openCtx={openCtx}
                            post={post}
                        />
                    )}
                    {tab === 'tracking' && (
                        <TrackingPanel
                            {...props}
                            go={go}
                            post={post}
                            openCtx={openCtx}
                        />
                    )}
                    {tab === 'scheduled' && (
                        <ScheduledPanel
                            {...props}
                            openComposer={openComposer}
                            openEditor={openEditor}
                            post={post}
                            openCtx={openCtx}
                        />
                    )}
                    {tab === 'insights' && (
                        <InsightsPanel {...props} go={go} post={post} />
                    )}
                </div>
            </PageShell>

            {ctxElement}

            {can.manage && (
                <AnnouncementWizard
                    open={wizardOpen}
                    onClose={() => setWizardOpen(false)}
                    segments={segments}
                    announcementId={editing?.id}
                    initial={editing?.initial}
                    onSuccess={() =>
                        router.reload({
                            only: [
                                'announcements',
                                'summary',
                                'tabCounts',
                                'scheduled',
                                'trackingList',
                                'tracking',
                                'insights',
                            ],
                        })
                    }
                />
            )}
        </AppLayout>
    );
}

/* ------------------------------------------------------------------ */
/*  Hero stat                                                          */
/* ------------------------------------------------------------------ */

function HeroStat({
    label,
    value,
    suffix,
    amber,
    onClick,
}: {
    label: string;
    value: number;
    suffix?: string;
    amber?: boolean;
    onClick?: () => void;
}) {
    return (
        <button
            onClick={onClick}
            className="flex flex-col items-start gap-0.5 rounded-xl px-3.5 py-2 text-left hover:bg-white/10"
        >
            <span className="text-[10px] font-bold tracking-wide text-white/60 uppercase">
                {label}
            </span>
            <span
                className="text-[22px] font-bold tabular-nums"
                style={amber ? { color: 'var(--hr-amber)' } : undefined}
            >
                {value}
                {suffix ? (
                    <span className="ml-1 text-sm font-semibold text-white/65">
                        {suffix}
                    </span>
                ) : null}
            </span>
        </button>
    );
}

/* ------------------------------------------------------------------ */
/*  All / Pinned list                                                 */
/* ------------------------------------------------------------------ */

type ListPanelProps = Props & {
    search: string;
    setSearch: (v: string) => void;
    searchRef: React.RefObject<HTMLInputElement | null>;
    go: (
        next: Partial<Filters & { tab: string; announcement: number }>,
    ) => void;
    selected: number[];
    setSelected: (v: number[]) => void;
    openComposer: () => void;
    openEditor: (
        card: { id: number } & Partial<AnnouncementWizardInitial>,
    ) => void;
    openCtx: (items: AnnouncementCtxItem[]) => (e: React.MouseEvent) => void;
    post: (url: string, body: Record<string, unknown>, msg?: string) => void;
};

function ListPanel(props: ListPanelProps) {
    const {
        announcements,
        filters,
        priorities,
        statuses,
        can,
        search,
        setSearch,
        searchRef,
        go,
        selected,
        setSelected,
        openComposer,
        openEditor,
        openCtx,
        post,
    } = props;
    const data = announcements?.data ?? [];
    const [density, setDensity] = useState<'cards' | 'table'>(
        () =>
            (typeof window !== 'undefined' &&
                (localStorage.getItem('hr.announcements.density') as
                    | 'cards'
                    | 'table')) ||
            'cards',
    );
    const changeDensity = (d: 'cards' | 'table') => {
        setDensity(d);
        if (typeof window !== 'undefined')
            localStorage.setItem('hr.announcements.density', d);
    };

    const toggleSelect = (id: number) =>
        setSelected(
            selected.includes(id)
                ? selected.filter((s) => s !== id)
                : [...selected, id],
        );

    const bulk = (action: string, msg: string) => {
        post('/hr/announcements/bulk', { action, ids: selected }, msg);
        setSelected([]);
    };

    const cardMenu = (a: AnnouncementCard): AnnouncementCtxItem[] => [
        {
            kind: 'item',
            label: 'Open',
            icon: LinkIcon,
            onSelect: () => router.visit(`/hr/announcements/${a.id}`),
        },
        ...(can.manage
            ? ([
                  {
                      kind: 'item',
                      label: 'Edit',
                      icon: Pencil,
                      kbd: 'E',
                      onSelect: () => openEditor(toInitial(a)),
                  },
                  {
                      kind: 'item',
                      label: a.is_pinned ? 'Unpin' : 'Pin',
                      icon: a.is_pinned ? PinOff : Pin,
                      kbd: 'P',
                      onSelect: () =>
                          post(
                              '/hr/announcements/bulk',
                              {
                                  action: a.is_pinned ? 'unpin' : 'pin',
                                  ids: [a.id],
                              },
                              a.is_pinned ? 'Unpinned' : 'Pinned',
                          ),
                  },
                  {
                      kind: 'item',
                      label: 'Send reminder',
                      icon: BellRing,
                      onSelect: () =>
                          post(
                              `/hr/announcements/${a.id}/remind`,
                              {},
                              'Reminders sent',
                          ),
                  },
                  {
                      kind: 'item',
                      label: 'Acknowledgement report',
                      icon: FileDown,
                      onSelect: () =>
                          window.open(
                              `/hr/announcements/${a.id}/tracking/export`,
                              '_blank',
                          ),
                  },
                  { kind: 'divider' as const },
                  {
                      kind: 'item',
                      label: 'Copy link',
                      icon: Copy,
                      onSelect: () => {
                          navigator.clipboard?.writeText(
                              `${window.location.origin}/hr/announcements/${a.id}`,
                          );
                          toast.success('Link copied');
                      },
                  },
                  a.status === 'archived'
                      ? {
                            kind: 'item' as const,
                            label: 'Restore',
                            icon: Archive,
                            onSelect: () =>
                                post(
                                    `/hr/announcements/${a.id}/restore`,
                                    {},
                                    'Restored',
                                ),
                        }
                      : {
                            kind: 'item' as const,
                            label: 'Archive',
                            icon: Archive,
                            tone: 'critical' as const,
                            onSelect: () =>
                                post(
                                    '/hr/announcements/bulk',
                                    { action: 'archive', ids: [a.id] },
                                    'Archived',
                                ),
                        },
              ] as AnnouncementCtxItem[])
            : []),
    ];

    return (
        <div className="flex flex-col gap-3.5">
            {/* toolbar */}
            <div className="flex flex-wrap items-center gap-2.5">
                <div className="relative max-w-sm flex-1">
                    <Search className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <input
                        ref={searchRef}
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        onKeyDown={(e) =>
                            e.key === 'Enter' &&
                            go({ search: search || undefined })
                        }
                        placeholder="Search title & content…  ( / )"
                        className="h-9 w-full rounded-lg border border-border bg-card pr-3 pl-9 text-sm outline-none focus-visible:ring-2 focus-visible:ring-primary"
                    />
                </div>
                <FilterSelect
                    icon={SlidersHorizontal}
                    value={filters.priority ?? ''}
                    placeholder="Priority"
                    options={priorities}
                    onChange={(v) => go({ priority: v || undefined })}
                />
                <FilterSelect
                    value={filters.status ?? ''}
                    placeholder="Status"
                    options={statuses}
                    onChange={(v) => go({ status: v || undefined })}
                />
                <select
                    value={filters.sort ?? 'newest'}
                    onChange={(e) => go({ sort: e.target.value })}
                    className="h-9 rounded-lg border border-border bg-card px-3 text-[12.5px] font-semibold outline-none"
                >
                    <option value="newest">Sort: Newest</option>
                    <option value="oldest">Oldest</option>
                    <option value="priority">Priority</option>
                    <option value="title">Title A–Z</option>
                </select>
                <div className="ml-auto inline-flex h-9 overflow-hidden rounded-lg border border-border">
                    <button
                        onClick={() => changeDensity('cards')}
                        aria-label="Card view"
                        className={`inline-flex items-center px-2.5 ${density === 'cards' ? 'bg-accent text-primary' : 'bg-card text-muted-foreground'}`}
                    >
                        <LayoutGrid className="h-4 w-4" />
                    </button>
                    <button
                        onClick={() => changeDensity('table')}
                        aria-label="Table view"
                        className={`inline-flex items-center border-l border-border px-2.5 ${density === 'table' ? 'bg-accent text-primary' : 'bg-card text-muted-foreground'}`}
                    >
                        <List className="h-4 w-4" />
                    </button>
                </div>
                {can.manage && (
                    <button
                        onClick={openComposer}
                        className="inline-flex h-9 items-center gap-1.5 rounded-lg bg-primary px-4 text-[12.5px] font-semibold text-primary-foreground hover:bg-primary/90"
                    >
                        <Plus className="h-4 w-4" /> New
                    </button>
                )}
            </div>

            {/* bulk bar */}
            {selected.length > 0 && can.manage && (
                <div className="flex flex-wrap items-center gap-3 rounded-xl border border-primary/30 bg-status-info-bg px-4 py-2.5">
                    <span className="text-sm font-semibold text-primary">
                        {selected.length} selected
                    </span>
                    <span className="h-4 w-px bg-border" />
                    <BulkBtn
                        icon={Pin}
                        label="Pin"
                        onClick={() => bulk('pin', 'Pinned')}
                    />
                    <BulkBtn
                        icon={BellRing}
                        label="Remind"
                        onClick={() => bulk('remind', 'Reminders sent')}
                    />
                    <BulkBtn
                        icon={FileDown}
                        label="Export"
                        onClick={() => {
                            window.open('/hr/announcements/export', '_blank');
                        }}
                    />
                    <BulkBtn
                        icon={Archive}
                        label="Archive"
                        critical
                        onClick={() => bulk('archive', 'Archived')}
                    />
                    <button
                        onClick={() => setSelected([])}
                        className="ml-auto text-xs text-muted-foreground hover:text-foreground"
                    >
                        Clear
                    </button>
                </div>
            )}

            {/* cards */}
            {data.length === 0 ? (
                <EmptyState
                    icon={Megaphone}
                    heading="No announcements"
                    description={
                        filters.search
                            ? 'No announcements match your search.'
                            : 'Compose your first announcement to get started.'
                    }
                />
            ) : density === 'table' ? (
                <div className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
                    <table className="w-full border-collapse text-[12.5px]">
                        <thead>
                            <tr className="text-left text-[10.5px] tracking-wide text-muted-foreground uppercase">
                                {can.manage && (
                                    <th className="w-8 px-3 py-2.5" />
                                )}
                                <th className="px-4 py-2.5 font-semibold">
                                    Title
                                </th>
                                <th className="px-3 py-2.5 font-semibold">
                                    Priority
                                </th>
                                <th className="px-3 py-2.5 font-semibold">
                                    Status
                                </th>
                                <th className="px-3 py-2.5 font-semibold">
                                    Audience
                                </th>
                                <th className="px-3 py-2.5 font-semibold">
                                    Ack
                                </th>
                                <th className="px-4 py-2.5" />
                            </tr>
                        </thead>
                        <tbody>
                            {data.map((a) => {
                                const pm =
                                    PRIORITY_META[a.priority] ??
                                    PRIORITY_META.normal;
                                const sel = selected.includes(a.id);
                                return (
                                    <tr
                                        key={a.id}
                                        className="border-t border-border"
                                        onContextMenu={openCtx(cardMenu(a))}
                                    >
                                        {can.manage && (
                                            <td className="px-3 py-2.5">
                                                <button
                                                    onClick={() =>
                                                        toggleSelect(a.id)
                                                    }
                                                    aria-label={
                                                        sel
                                                            ? 'Deselect'
                                                            : 'Select'
                                                    }
                                                    className={`grid h-4 w-4 place-items-center rounded border ${sel ? 'border-primary bg-primary text-primary-foreground' : 'border-border'}`}
                                                >
                                                    {sel && (
                                                        <CheckCheck className="h-2.5 w-2.5" />
                                                    )}
                                                </button>
                                            </td>
                                        )}
                                        <td className="px-4 py-2.5">
                                            <button
                                                onClick={() =>
                                                    router.visit(
                                                        `/hr/announcements/${a.id}`,
                                                    )
                                                }
                                                className="flex items-center gap-1.5 font-semibold hover:underline"
                                            >
                                                {a.is_pinned && (
                                                    <Pin
                                                        className="h-3 w-3"
                                                        style={{
                                                            color: 'var(--hr-amber)',
                                                        }}
                                                    />
                                                )}
                                                {a.title}
                                            </button>
                                        </td>
                                        <td className="px-3 py-2.5">
                                            <StatusBadge
                                                variant={pm.variant}
                                                size="sm"
                                            >
                                                {pm.label}
                                            </StatusBadge>
                                        </td>
                                        <td className="px-3 py-2.5">
                                            <StatusBadge
                                                variant={
                                                    STATUS_VARIANT[a.status] ??
                                                    'neutral'
                                                }
                                                size="sm"
                                            >
                                                {a.status}
                                            </StatusBadge>
                                        </td>
                                        <td className="px-3 py-2.5 text-muted-foreground">
                                            {a.audience} — {a.audience_size}
                                        </td>
                                        <td className="px-3 py-2.5 tabular-nums">
                                            {a.requires_acknowledgement
                                                ? `${a.ack_pct}%`
                                                : '—'}
                                        </td>
                                        <td className="px-4 py-2.5 text-right">
                                            <button
                                                onClick={openCtx(cardMenu(a))}
                                                aria-label="Actions"
                                                className="grid h-7 w-7 place-items-center rounded-lg border border-border bg-card text-muted-foreground hover:bg-accent"
                                            >
                                                <MoreHorizontal className="h-4 w-4" />
                                            </button>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            ) : (
                data.map((a) => {
                    const pm =
                        PRIORITY_META[a.priority] ?? PRIORITY_META.normal;
                    const PIcon = pm.icon;
                    const sel = selected.includes(a.id);
                    return (
                        <div
                            key={a.id}
                            onContextMenu={openCtx(cardMenu(a))}
                            className="rounded-2xl border bg-card p-5 shadow-sm transition-shadow hover:shadow-md"
                            style={{
                                borderColor: a.is_pinned
                                    ? 'color-mix(in oklch, var(--hr-amber) 35%, var(--border))'
                                    : undefined,
                            }}
                        >
                            <div className="flex items-start gap-3.5">
                                {can.manage && (
                                    <button
                                        onClick={() => toggleSelect(a.id)}
                                        aria-label={sel ? 'Deselect' : 'Select'}
                                        className={`mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-md border ${sel ? 'border-primary bg-primary text-primary-foreground' : 'border-border bg-card'}`}
                                    >
                                        {sel && (
                                            <CheckCheck className="h-3 w-3" />
                                        )}
                                    </button>
                                )}
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        {a.is_pinned && (
                                            <Pin
                                                className="h-3.5 w-3.5"
                                                style={{
                                                    color: 'var(--hr-amber)',
                                                }}
                                            />
                                        )}
                                        <button
                                            onClick={() =>
                                                router.visit(
                                                    `/hr/announcements/${a.id}`,
                                                )
                                            }
                                            className="text-[15px] font-bold tracking-tight hover:underline"
                                        >
                                            {a.title}
                                        </button>
                                        <StatusBadge
                                            variant={pm.variant}
                                            size="sm"
                                        >
                                            <PIcon className="h-3 w-3" />{' '}
                                            {pm.label}
                                        </StatusBadge>
                                        <StatusBadge
                                            variant={
                                                STATUS_VARIANT[a.status] ??
                                                'neutral'
                                            }
                                            size="sm"
                                        >
                                            {a.status}
                                        </StatusBadge>
                                        {a.requires_acknowledgement && (
                                            <span className="inline-flex items-center gap-1 rounded-full border border-border px-2 py-0.5 text-[10px] font-semibold text-muted-foreground">
                                                <CheckCheck className="h-3 w-3" />{' '}
                                                Requires ack
                                            </span>
                                        )}
                                    </div>
                                    <p className="mt-2 line-clamp-2 text-[13px] leading-relaxed text-muted-foreground">
                                        {a.excerpt}
                                    </p>
                                    <div className="mt-2.5 flex flex-wrap items-center gap-3.5 text-[11.5px] text-muted-foreground">
                                        <span className="inline-flex items-center gap-1.5">
                                            <User className="h-3 w-3" />
                                            {a.creator?.name ?? 'Unknown'}
                                        </span>
                                        <span className="inline-flex items-center gap-1.5">
                                            <Users className="h-3 w-3" />
                                            {a.audience} — {a.audience_size}
                                        </span>
                                        <span className="inline-flex items-center gap-1.5">
                                            <CalendarDays className="h-3 w-3" />
                                            {fmtDate(a.published_at)}
                                        </span>
                                        {a.attachments_count > 0 && (
                                            <span>
                                                📎 {a.attachments_count}
                                            </span>
                                        )}
                                        {a.replies_count > 0 && (
                                            <span>💬 {a.replies_count}</span>
                                        )}
                                        {a.reactions_count > 0 && (
                                            <span>❤ {a.reactions_count}</span>
                                        )}
                                    </div>
                                    {a.requires_acknowledgement && (
                                        <div className="mt-3 flex max-w-lg items-center gap-3">
                                            <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                                                <div
                                                    className="h-full rounded-full"
                                                    style={{
                                                        width: `${a.ack_pct}%`,
                                                        background: ackBar(
                                                            a.ack_pct,
                                                        ),
                                                    }}
                                                />
                                            </div>
                                            <span className="text-[11.5px] font-semibold whitespace-nowrap tabular-nums">
                                                {a.acknowledged_count} of{' '}
                                                {a.audience_size} · {a.ack_pct}%
                                            </span>
                                        </div>
                                    )}
                                </div>
                                <div className="flex shrink-0 flex-col items-end gap-2">
                                    <button
                                        onClick={openCtx(cardMenu(a))}
                                        aria-label="Actions"
                                        className="grid h-8 w-8 place-items-center rounded-lg border border-border bg-card text-muted-foreground hover:bg-accent"
                                    >
                                        <MoreHorizontal className="h-4 w-4" />
                                    </button>
                                    <button
                                        onClick={() =>
                                            router.visit(
                                                `/hr/announcements/${a.id}`,
                                            )
                                        }
                                        className="rounded-lg border border-border bg-card px-3 py-1.5 text-xs font-semibold hover:bg-accent"
                                    >
                                        Open
                                    </button>
                                </div>
                            </div>
                        </div>
                    );
                })
            )}

            {announcements?.links && announcements.links.length > 3 && (
                <LaravelPagination
                    links={announcements.links}
                    className="mt-2"
                />
            )}
        </div>
    );
}

function toInitial(
    a: AnnouncementCard,
): { id: number } & Partial<AnnouncementWizardInitial> {
    return {
        id: a.id,
        title: a.title,
        content: a.excerpt,
        priority: a.priority,
        status: a.status,
        is_pinned: a.is_pinned,
        requires_acknowledgement: a.requires_acknowledgement,
        published_at: a.published_at,
        expires_at: a.expires_at,
    };
}

function FilterSelect({
    icon: Icon,
    value,
    placeholder,
    options,
    onChange,
}: {
    icon?: typeof Info;
    value: string;
    placeholder: string;
    options: { value: string; label: string }[];
    onChange: (v: string) => void;
}) {
    return (
        <div className="relative inline-flex items-center">
            {Icon && (
                <Icon className="pointer-events-none absolute left-2.5 h-3.5 w-3.5 text-muted-foreground" />
            )}
            <select
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className={`h-9 rounded-lg border border-border bg-card pr-3 text-[12.5px] font-semibold outline-none ${Icon ? 'pl-8' : 'pl-3'}`}
            >
                <option value="">{placeholder}</option>
                {options.map((o) => (
                    <option key={o.value} value={o.value}>
                        {o.label}
                    </option>
                ))}
            </select>
        </div>
    );
}

function BulkBtn({
    icon: Icon,
    label,
    critical,
    onClick,
}: {
    icon: typeof Info;
    label: string;
    critical?: boolean;
    onClick: () => void;
}) {
    return (
        <button
            onClick={onClick}
            className={`inline-flex items-center gap-1.5 text-[12.5px] font-semibold ${critical ? 'text-status-critical' : 'text-foreground'} hover:opacity-80`}
        >
            <Icon className="h-3.5 w-3.5" /> {label}
        </button>
    );
}

/* ------------------------------------------------------------------ */
/*  Tracking                                                          */
/* ------------------------------------------------------------------ */

function TrackingPanel(
    props: Props & {
        go: (
            next: Partial<Filters & { tab: string; announcement: number }>,
        ) => void;
        post: (
            url: string,
            body: Record<string, unknown>,
            msg?: string,
        ) => void;
        openCtx: (
            items: AnnouncementCtxItem[],
        ) => (e: React.MouseEvent) => void;
    },
) {
    const { trackingList = [], tracking, go, post, openCtx } = props;
    const [outstandingOnly, setOutstandingOnly] = useState(false);

    if (trackingList.length === 0) {
        return (
            <EmptyState
                icon={CheckCheck}
                heading="Nothing to track"
                description="Announcements that require acknowledgement will appear here."
            />
        );
    }

    const t = tracking;
    const roster = (t?.roster ?? []).filter(
        (r) => !outstandingOnly || r.status !== 'acknowledged',
    );

    const rosterStatusVariant: Record<RosterRow['status'], StatusVariant> = {
        acknowledged: 'success',
        outstanding: 'critical',
        reminded: 'warning',
    };
    const rosterStatusLabel: Record<RosterRow['status'], string> = {
        acknowledged: 'Acknowledged',
        outstanding: 'Not yet',
        reminded: 'Reminded',
    };

    return (
        <div className="grid gap-4 lg:grid-cols-[260px_1fr]">
            {/* selector rail */}
            <div className="flex flex-col gap-2">
                {trackingList.map((item) => {
                    const active = t?.id === item.id;
                    return (
                        <button
                            key={item.id}
                            onClick={() =>
                                go({ tab: 'tracking', announcement: item.id })
                            }
                            className={`rounded-xl border p-3 text-left transition-colors ${active ? 'border-primary bg-primary/10' : 'border-border bg-card hover:border-primary/50'}`}
                        >
                            <div className="truncate text-[13px] font-semibold">
                                {item.title}
                            </div>
                            <div className="mt-1 flex items-center gap-2">
                                <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                                    <div
                                        className="h-full rounded-full"
                                        style={{
                                            width: `${item.ack_pct}%`,
                                            background: ackBar(item.ack_pct),
                                        }}
                                    />
                                </div>
                                <span className="text-[11px] font-semibold tabular-nums">
                                    {item.ack_pct}%
                                </span>
                            </div>
                            <div className="mt-1 text-[11px] text-muted-foreground">
                                {item.acknowledged_count}/{item.audience_size} ·{' '}
                                {item.audience}
                            </div>
                        </button>
                    );
                })}
            </div>

            {/* detail */}
            {t && (
                <div className="flex flex-col gap-4">
                    <div className="rounded-2xl border border-border bg-card p-5 shadow-sm">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <div className="flex items-center gap-2">
                                    <StatusBadge
                                        variant={
                                            PRIORITY_META[t.priority]
                                                ?.variant ?? 'info'
                                        }
                                        size="sm"
                                    >
                                        {PRIORITY_META[t.priority]?.label}
                                    </StatusBadge>
                                    <h2 className="text-base font-bold">
                                        {t.title}
                                    </h2>
                                </div>
                                <p className="mt-1 text-[12.5px] text-muted-foreground">
                                    {t.audience} ·{' '}
                                    {t.ack_deadline
                                        ? `ack by ${fmtDate(t.ack_deadline)} · `
                                        : ''}
                                    published {fmtDate(t.published_at)}
                                </p>
                            </div>
                            <div className="flex gap-2">
                                <button
                                    onClick={() =>
                                        post(
                                            `/hr/announcements/${t.id}/remind`,
                                            {},
                                            'Reminders sent',
                                        )
                                    }
                                    className="inline-flex h-9 items-center gap-1.5 rounded-lg bg-primary px-3.5 text-[12.5px] font-semibold text-primary-foreground hover:bg-primary/90"
                                >
                                    <BellRing className="h-4 w-4" /> Remind
                                    outstanding
                                </button>
                                <button
                                    onClick={() =>
                                        window.open(
                                            `/hr/announcements/${t.id}/tracking/export`,
                                            '_blank',
                                        )
                                    }
                                    className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-border bg-card px-3.5 text-[12.5px] font-semibold hover:bg-accent"
                                >
                                    <FileDown className="h-4 w-4" /> Export CSV
                                </button>
                            </div>
                        </div>

                        <div className="mt-5 grid items-center gap-6 sm:grid-cols-[auto_1fr_1fr]">
                            <div className="flex items-center gap-4">
                                <div
                                    className="relative grid h-24 w-24 place-items-center rounded-full"
                                    style={{
                                        background: `conic-gradient(var(--status-success) 0% ${t.ack_pct}%, var(--muted) ${t.ack_pct}% 100%)`,
                                    }}
                                >
                                    <div className="absolute inset-3 grid place-items-center rounded-full bg-card">
                                        <span className="text-xl font-bold">
                                            {t.ack_pct}%
                                        </span>
                                        <span className="text-[10px] text-muted-foreground">
                                            acked
                                        </span>
                                    </div>
                                </div>
                                <div className="text-xs leading-relaxed">
                                    <div className="flex items-center gap-2">
                                        <span className="h-2 w-2 rounded-sm bg-status-success" />
                                        <b>{t.acknowledged}</b> acknowledged
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <span
                                            className="h-2 w-2 rounded-sm"
                                            style={{
                                                background: 'var(--hr-amber)',
                                            }}
                                        />
                                        <b>{t.outstanding}</b> outstanding
                                    </div>
                                    <div className="flex items-center gap-2 text-muted-foreground">
                                        <span className="h-2 w-2 rounded-sm bg-muted" />
                                        {t.total} recipients
                                    </div>
                                </div>
                            </div>
                            <Breakdown title="By site" rows={t.by_site} />
                            <Breakdown title="By role" rows={t.by_role} />
                        </div>
                    </div>

                    {/* roster */}
                    <div className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
                        <div className="flex items-center justify-between border-b border-border px-4 py-3">
                            <div className="text-[13px] font-bold">
                                Recipient roster
                            </div>
                            <button
                                onClick={() => setOutstandingOnly((v) => !v)}
                                className={`h-8 rounded-lg border px-3 text-[11.5px] font-semibold ${outstandingOnly ? 'border-primary bg-primary/10 text-primary' : 'border-border bg-card text-muted-foreground'}`}
                            >
                                Outstanding only
                            </button>
                        </div>
                        <table className="w-full border-collapse text-[12.5px]">
                            <thead>
                                <tr className="text-left text-[10.5px] tracking-wide text-muted-foreground uppercase">
                                    <th className="px-4 py-2.5 font-semibold">
                                        Name
                                    </th>
                                    <th className="px-3 py-2.5 font-semibold">
                                        Role
                                    </th>
                                    <th className="px-3 py-2.5 font-semibold">
                                        Site
                                    </th>
                                    <th className="px-3 py-2.5 font-semibold">
                                        Status
                                    </th>
                                    <th className="px-3 py-2.5 font-semibold">
                                        Acknowledged
                                    </th>
                                    <th className="px-4 py-2.5" />
                                </tr>
                            </thead>
                            <tbody>
                                {roster.map((p) => (
                                    <tr
                                        key={p.id}
                                        className="border-t border-border"
                                        onContextMenu={openCtx([
                                            {
                                                kind: 'item',
                                                label: 'Send reminder',
                                                icon: BellRing,
                                                onSelect: () =>
                                                    post(
                                                        `/hr/announcements/${t.id}/remind`,
                                                        { user_ids: [p.id] },
                                                        'Reminder sent',
                                                    ),
                                            },
                                            {
                                                kind: 'item',
                                                label: 'Mark acknowledged (override)',
                                                icon: CheckCheck,
                                                tone: 'success',
                                                onSelect: () =>
                                                    post(
                                                        `/hr/announcements/${t.id}/acknowledge-for`,
                                                        { user_id: p.id },
                                                        'Marked acknowledged',
                                                    ),
                                            },
                                        ])}
                                    >
                                        <td className="px-4 py-2.5">
                                            <div className="flex items-center gap-2.5">
                                                <span className="grid h-7 w-7 place-items-center rounded-full bg-accent text-[11px] font-bold text-primary">
                                                    {p.name
                                                        .split(' ')
                                                        .map((w) => w[0])
                                                        .slice(0, 2)
                                                        .join('')}
                                                </span>
                                                <span className="font-semibold">
                                                    {p.name}
                                                </span>
                                            </div>
                                        </td>
                                        <td className="px-3 py-2.5 text-muted-foreground">
                                            {p.role}
                                        </td>
                                        <td className="px-3 py-2.5 text-muted-foreground">
                                            {p.site}
                                        </td>
                                        <td className="px-3 py-2.5">
                                            <StatusBadge
                                                variant={
                                                    rosterStatusVariant[
                                                        p.status
                                                    ]
                                                }
                                                size="sm"
                                            >
                                                {rosterStatusLabel[p.status]}
                                            </StatusBadge>
                                        </td>
                                        <td className="px-3 py-2.5 text-muted-foreground tabular-nums">
                                            {p.acknowledged_at
                                                ? fmtDateTime(p.acknowledged_at)
                                                : '—'}
                                        </td>
                                        <td className="px-4 py-2.5 text-right">
                                            {p.status !== 'acknowledged' && (
                                                <button
                                                    onClick={() =>
                                                        post(
                                                            `/hr/announcements/${t.id}/remind`,
                                                            {
                                                                user_ids: [
                                                                    p.id,
                                                                ],
                                                            },
                                                            'Reminder sent',
                                                        )
                                                    }
                                                    className="inline-flex items-center gap-1.5 rounded-lg border border-border bg-card px-2.5 py-1 text-[11px] font-semibold hover:bg-accent"
                                                >
                                                    <BellRing className="h-3 w-3" />{' '}
                                                    Remind
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </div>
    );
}

function Breakdown({
    title,
    rows,
}: {
    title: string;
    rows: { name: string; pct: number }[];
}) {
    return (
        <div>
            <div className="mb-2 text-[10.5px] font-bold tracking-wide text-muted-foreground uppercase">
                {title}
            </div>
            {rows.length === 0 ? (
                <div className="text-[11px] text-muted-foreground">—</div>
            ) : (
                rows.map((r) => (
                    <div
                        key={r.name}
                        className="mb-2 flex items-center gap-2.5"
                    >
                        <span className="w-24 truncate text-[12px]">
                            {r.name}
                        </span>
                        <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                            <div
                                className="h-full rounded-full"
                                style={{
                                    width: `${r.pct}%`,
                                    background: ackBar(r.pct),
                                }}
                            />
                        </div>
                        <span className="w-9 text-right text-[11.5px] font-semibold tabular-nums">
                            {r.pct}%
                        </span>
                    </div>
                ))
            )}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Scheduled                                                         */
/* ------------------------------------------------------------------ */

function ScheduledPanel(
    props: Props & {
        openComposer: () => void;
        openEditor: (
            card: { id: number } & Partial<AnnouncementWizardInitial>,
        ) => void;
        post: (
            url: string,
            body: Record<string, unknown>,
            msg?: string,
        ) => void;
        openCtx: (
            items: AnnouncementCtxItem[],
        ) => (e: React.MouseEvent) => void;
    },
) {
    const {
        scheduled = [],
        can,
        openComposer,
        openEditor,
        post,
        openCtx,
    } = props;

    if (scheduled.length === 0) {
        return (
            <EmptyState
                icon={CalendarClock}
                heading="No drafts or scheduled notices"
                description="Schedule an announcement or save a draft to see it here."
                action={
                    can.manage ? (
                        <button
                            onClick={openComposer}
                            className="inline-flex h-9 items-center gap-1.5 rounded-lg bg-primary px-4 text-[12.5px] font-semibold text-primary-foreground hover:bg-primary/90"
                        >
                            <Plus className="h-4 w-4" /> New announcement
                        </button>
                    ) : undefined
                }
            />
        );
    }

    return (
        <div className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
            <div className="flex items-center justify-between border-b border-border px-4 py-3.5">
                <div className="text-[13px] font-bold">Drafts & scheduled</div>
                {can.manage && (
                    <button
                        onClick={openComposer}
                        className="inline-flex h-8 items-center gap-1.5 rounded-lg bg-primary px-3 text-xs font-semibold text-primary-foreground hover:bg-primary/90"
                    >
                        <Plus className="h-4 w-4" /> New draft
                    </button>
                )}
            </div>
            <table className="w-full border-collapse text-[12.5px]">
                <thead>
                    <tr className="text-left text-[10.5px] tracking-wide text-muted-foreground uppercase">
                        <th className="px-4 py-2.5 font-semibold">Title</th>
                        <th className="px-3 py-2.5 font-semibold">Status</th>
                        <th className="px-3 py-2.5 font-semibold">Sends</th>
                        <th className="px-3 py-2.5 font-semibold">Audience</th>
                        <th className="px-4 py-2.5 text-right font-semibold">
                            Actions
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {scheduled.map((s) => (
                        <tr
                            key={s.id}
                            className="border-t border-border"
                            onContextMenu={openCtx([
                                ...(s.can_publish
                                    ? [
                                          {
                                              kind: 'item' as const,
                                              label: 'Publish now',
                                              icon: Send,
                                              onSelect: () =>
                                                  post(
                                                      `/hr/announcements/${s.id}/publish`,
                                                      {},
                                                      'Published',
                                                  ),
                                          },
                                      ]
                                    : []),
                                {
                                    kind: 'item',
                                    label: 'Delete',
                                    icon: Trash2,
                                    tone: 'critical',
                                    onSelect: () =>
                                        post(
                                            '/hr/announcements/bulk',
                                            { action: 'delete', ids: [s.id] },
                                            'Deleted',
                                        ),
                                },
                            ])}
                        >
                            <td className="px-4 py-3">
                                <div className="flex items-center gap-2">
                                    <span className="font-semibold">
                                        {s.title}
                                    </span>
                                    {s.recurrence && (
                                        <StatusBadge variant="info" size="sm">
                                            🔁 {s.recurrence}
                                        </StatusBadge>
                                    )}
                                </div>
                            </td>
                            <td className="px-3 py-3">
                                <StatusBadge
                                    variant={
                                        STATUS_VARIANT[s.status] ?? 'neutral'
                                    }
                                    size="sm"
                                >
                                    {s.status}
                                </StatusBadge>
                            </td>
                            <td className="px-3 py-3 text-muted-foreground tabular-nums">
                                {fmtDateTime(s.sends_at)}
                            </td>
                            <td className="px-3 py-3 text-muted-foreground">
                                {s.audience}
                            </td>
                            <td className="px-4 py-3 text-right">
                                {can.manage && (
                                    <div className="inline-flex gap-1.5">
                                        <button
                                            onClick={() =>
                                                openEditor({ id: s.id })
                                            }
                                            className="rounded-lg border border-border bg-card px-2.5 py-1 text-[11px] font-semibold hover:bg-accent"
                                        >
                                            Edit
                                        </button>
                                        {s.can_publish && (
                                            <button
                                                onClick={() =>
                                                    post(
                                                        `/hr/announcements/${s.id}/publish`,
                                                        {},
                                                        'Published',
                                                    )
                                                }
                                                className="rounded-lg bg-primary px-2.5 py-1 text-[11px] font-semibold text-primary-foreground hover:bg-primary/90"
                                            >
                                                Publish now
                                            </button>
                                        )}
                                    </div>
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Insights                                                          */
/* ------------------------------------------------------------------ */

function InsightsPanel(
    props: Props & {
        go: (
            next: Partial<Filters & { tab: string; announcement: number }>,
        ) => void;
        post: (
            url: string,
            body: Record<string, unknown>,
            msg?: string,
        ) => void;
    },
) {
    const { insights, go, post } = props;
    if (!insights) return null;
    const { kpis, trend, top_unacknowledged } = insights;
    const maxPct = Math.max(100, ...trend.map((t) => t.pct));

    return (
        <div className="flex flex-col gap-4">
            <div className="grid gap-3.5 sm:grid-cols-2 lg:grid-cols-4">
                <KpiTile
                    icon={CheckCheck}
                    color="var(--status-success)"
                    label="Avg ack rate"
                    value={`${kpis.avg_ack_rate}%`}
                />
                <KpiTile
                    icon={CalendarClock}
                    color="var(--primary)"
                    label="Time to ack"
                    value={`${kpis.avg_time_to_ack_hours}h`}
                />
                <KpiTile
                    icon={BellRing}
                    color="var(--hr-amber)"
                    label="Reminders (30d)"
                    value={String(kpis.reminders_30d)}
                />
                <KpiTile
                    icon={Users}
                    color="var(--status-critical)"
                    label="Outstanding"
                    value={String(kpis.outstanding)}
                />
            </div>

            <div className="grid gap-3.5 lg:grid-cols-[3fr_2fr]">
                <div className="rounded-2xl border border-border bg-card p-5 shadow-sm">
                    <div className="text-[13px] font-bold">
                        Acknowledgement rate — recent notices
                    </div>
                    {trend.length === 0 ? (
                        <div className="mt-6 text-sm text-muted-foreground">
                            No required notices yet.
                        </div>
                    ) : (
                        <div className="mt-5 flex h-44 items-end gap-3">
                            {trend.map((b, i) => (
                                <div
                                    key={i}
                                    className="flex h-full flex-1 flex-col items-center justify-end gap-2"
                                >
                                    <span className="text-[11px] font-bold">
                                        {b.pct}%
                                    </span>
                                    <div
                                        className="w-full rounded-t-md"
                                        style={{
                                            height: `${(b.pct / maxPct) * 100}%`,
                                            background:
                                                'linear-gradient(180deg, var(--primary), color-mix(in oklch, var(--primary) 65%, var(--card)))',
                                        }}
                                    />
                                    <span className="text-[10px] text-muted-foreground">
                                        {b.label}
                                    </span>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                <div className="rounded-2xl border border-border bg-card p-5 shadow-sm">
                    <div className="text-[13px] font-bold">
                        Top unacknowledged
                    </div>
                    <div className="mt-3.5 flex flex-col gap-3">
                        {top_unacknowledged.length === 0 ? (
                            <div className="text-sm text-muted-foreground">
                                Everyone is caught up 🎉
                            </div>
                        ) : (
                            top_unacknowledged.map((u) => (
                                <div
                                    key={u.id}
                                    className="flex items-center gap-3"
                                >
                                    <button
                                        onClick={() =>
                                            go({
                                                tab: 'tracking',
                                                announcement: u.id,
                                            })
                                        }
                                        className="min-w-0 flex-1 text-left"
                                    >
                                        <div className="truncate text-[12.5px] font-semibold hover:underline">
                                            {u.title}
                                        </div>
                                        <div className="text-[11px] text-muted-foreground">
                                            {u.outstanding} outstanding
                                        </div>
                                    </button>
                                    <button
                                        onClick={() =>
                                            post(
                                                `/hr/announcements/${u.id}/remind`,
                                                {},
                                                'Reminders sent',
                                            )
                                        }
                                        className="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-border bg-card px-2.5 py-1 text-[11px] font-semibold hover:bg-accent"
                                    >
                                        <BellRing className="h-3 w-3" /> Remind
                                    </button>
                                </div>
                            ))
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}

function KpiTile({
    icon: Icon,
    color,
    label,
    value,
}: {
    icon: typeof Info;
    color: string;
    label: string;
    value: string;
}) {
    return (
        <div className="rounded-2xl border border-border bg-card p-4 shadow-sm">
            <div className="flex items-center gap-2 text-[11px] font-semibold text-muted-foreground">
                <Icon className="h-3.5 w-3.5" style={{ color }} /> {label}
            </div>
            <div className="mt-2 text-2xl font-bold tabular-nums">{value}</div>
        </div>
    );
}
