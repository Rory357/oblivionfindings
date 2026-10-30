/* eslint-disable no-restricted-syntax -- history rows, date fields and category tabs use page-specific layouts with semantic tokens. */
/* DESIGN REVIEW: docs/emar-redesign/audit-design-review.md — design spec, intended look,
   deliberate deviations, and a fidelity checklist for reviewing this page's design. */
import { HistoricalAuditExportDialog } from '@/components/emar/historical-audit-export-dialog';
import {
    eventMeta,
    eventPrimaryLink,
    FLAG_META,
    MedicationEventDrawer,
    type AuditEvent,
} from '@/components/emar/medication-event-drawer';
import { EmarMeters } from '@/components/emar/workspace-navigation';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
} from '@/components/page/page-header';
import type { ShiftCtxItem, ShiftCtxState } from '@/components/rostering';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useEmarBreadcrumbs } from '@/hooks/use-emar-breadcrumbs';
import AppLayout from '@/layouts/app-layout';
import {
    formatDateLong,
    formatDateOnly,
    formatTime,
    toDateInput,
} from '@/lib/datetime';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertOctagon,
    AlertTriangle,
    CalendarDays,
    ClipboardCheck,
    Copy,
    Download,
    Eye,
    FileText,
    Fingerprint,
    History,
    Lock,
    MoreHorizontal,
    Package,
    Pill,
    Printer,
    ShieldAlert,
    Table,
    User,
    X,
    type LucideIcon,
} from 'lucide-react';
import {
    useEffect,
    useMemo,
    useState,
    type MouseEvent as ReactMouseEvent,
} from 'react';
import { toast } from 'sonner';
import {
    historyPeriodEnding,
    historyQuery,
    historyRange,
    type HistoryFilters,
} from './audit-history-period';
import { HistoryRecordMenu } from './audit-history-record-menu';

type Stats = {
    total: number;
    this_week: number;
    this_month: number;
    open_gaps: number;
};
type Props = {
    events: AuditEvent[];
    stats: Stats;
    filters: HistoryFilters;
    clients: { id: number; name: string }[];
    staff: { id: number; name: string }[];
    sites: { id: number; name: string }[];
    active_site: { id: number; name: string } | null;
    site_brand_colour: string | null;
    user_first_name: string | null;
    /** "Not available before …" when the period starts before the dose record. */
    omissions_notice?: string | null;
    history_notice?: string | null;
    can_export_history: boolean;
    export_purposes: Record<string, string>;
};

const CATEGORIES = [
    {
        id: 'all',
        label: 'All records',
        icon: History,
        tone: 'primary' as const,
    },
    { id: 'doses', label: 'Doses', icon: Pill, tone: 'success' as const },
    {
        id: 'controlled',
        label: 'Controlled',
        icon: Lock,
        tone: 'primary' as const,
    },
    {
        id: 'clinical',
        label: 'Clinical',
        icon: ClipboardCheck,
        tone: 'info' as const,
    },
    { id: 'stock', label: 'Stock', icon: Package, tone: 'warning' as const },
    {
        id: 'errors',
        label: 'Errors',
        icon: AlertOctagon,
        tone: 'critical' as const,
    },
];
const RANGES = [
    { value: 'all', label: 'All available history' },
    { value: '7', label: '7 days' },
    { value: '30', label: '30 days' },
    { value: '90', label: '90 days' },
    { value: 'custom', label: 'Custom period' },
];

// Right-click menu tag colours (token-based; mirrors ControlledDrugs' CTX_TAG).
const CTX_TAG: Record<
    'critical' | 'warning' | 'info' | 'success' | 'muted',
    { bg: string; color: string }
> = {
    critical: {
        bg: 'var(--status-critical-bg)',
        color: 'var(--status-critical)',
    },
    warning: { bg: 'var(--status-warning-bg)', color: 'var(--status-warning)' },
    info: { bg: 'var(--status-info-bg)', color: 'var(--status-info)' },
    success: { bg: 'var(--status-success-bg)', color: 'var(--status-success)' },
    muted: { bg: 'var(--muted)', color: 'var(--muted-foreground)' },
};
const ctxToneFor = (e: AuditEvent): keyof typeof CTX_TAG => {
    if (e.flags.length > 0) return 'critical';
    switch (e.category) {
        case 'doses':
            return 'success';
        case 'stock':
            return 'warning';
        case 'errors':
            return 'critical';
        case 'controlled':
        case 'clinical':
        default:
            return 'info';
    }
};

// Dismissible compliance alert strip (mirrors ControlledDrugs' per-session pattern).
type AuditAlert = {
    kind: string;
    tone: 'critical' | 'warning';
    icon: LucideIcon;
    message: string;
    onReview: () => void;
};
const DISMISSED_ALERTS_KEY = 'audit-dismissed-alerts';
function readDismissedAlerts(): string[] {
    if (typeof window === 'undefined') return [];
    try {
        const raw = window.sessionStorage.getItem(DISMISSED_ALERTS_KEY);
        return raw ? (JSON.parse(raw) as string[]) : [];
    } catch {
        return [];
    }
}
function persistDismissedAlerts(kinds: string[]): string[] {
    const unique = Array.from(new Set(kinds));
    if (typeof window !== 'undefined') {
        try {
            window.sessionStorage.setItem(
                DISMISSED_ALERTS_KEY,
                JSON.stringify(unique),
            );
        } catch {
            /* sessionStorage unavailable — dismissal stays in-memory only */
        }
    }
    return unique;
}
const fmtTime = formatTime;
const dayKey = (iso: string) => formatDateLong(iso);
export default function AuditLog({
    events,
    stats,
    filters,
    clients,
    staff,
    sites,
    active_site: activeSite,
    omissions_notice: omissionsNotice = null,
    history_notice: historyNotice = null,
    can_export_history: canExportHistory,
    export_purposes: exportPurposes,
}: Props) {
    const breadcrumbs = useEmarBreadcrumbs();
    const { url } = usePage();
    const params = new URLSearchParams(url.split('?')[1] ?? '');
    const [view, setView] = useState(params.get('history_view') ?? 'timeline');
    const [cat, setCat] = useState(params.get('history_category') ?? 'all');
    const [search, setSearch] = useState(params.get('history_search') ?? '');
    const [staffName, setStaffName] = useState(
        params.get('history_staff') ?? '',
    );
    const [source, setSource] = useState(params.get('history_source') ?? '');
    const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters.date_to ?? '');
    const clientId = filters.client_id ? String(filters.client_id) : '';
    const siteFilter = activeSite?.id ?? null;
    const range = historyRange(filters);
    const todayYmd = toDateInput(new Date());

    // Server props are authoritative after a visit, including browser back/forward.
    useEffect(() => {
        setDateFrom(filters.date_from ?? '');
        setDateTo(filters.date_to ?? '');
        const query = new URLSearchParams(url.split('?')[1] ?? '');
        setView(query.get('history_view') ?? 'timeline');
        setCat(query.get('history_category') ?? 'all');
        setSearch(query.get('history_search') ?? '');
        setStaffName(query.get('history_staff') ?? '');
        setSource(query.get('history_source') ?? '');
    }, [url, filters.date_from, filters.date_to]);

    const visitHistory = (
        changes: Partial<HistoryFilters> & { site_id?: number | null } = {},
        clearFacets = false,
    ) =>
        router.get(
            '/emar/reports/history',
            {
                ...historyQuery(url, filters, siteFilter, changes),
                history_view: view,
                history_category: clearFacets ? 'all' : cat,
                history_search: clearFacets ? '' : search,
                history_staff: clearFacets ? '' : staffName,
                history_source: clearFacets ? '' : source,
            },
            { preserveState: true, preserveScroll: true },
        );

    const onRange = (value: string) => {
        if (value === 'all') {
            visitHistory({ date_from: null, date_to: null });
        } else if (value === 'custom') {
            requestAnimationFrame(() =>
                document.getElementById('history-date-from')?.focus(),
            );
        } else {
            visitHistory(
                historyPeriodEnding(filters.date_to ?? todayYmd, Number(value)),
            );
        }
    };
    const periodLabel =
        filters.date_from && filters.date_to
            ? `${formatDateOnly(filters.date_from)} – ${formatDateOnly(filters.date_to)}`
            : filters.date_from
              ? `From ${formatDateOnly(filters.date_from)}`
              : filters.date_to
                ? `Up to ${formatDateOnly(filters.date_to)}`
                : 'All available history';
    const scopedHref = (
        path: '/emar/reports' | '/emar/reports/history/logs',
    ) => {
        // Both destination readers accept these scope/date fields. Neither has
        // an equivalent for the historical projection's event_types filter.
        const query = new URLSearchParams();
        if (path === '/emar/reports') query.set('view', 'exports');
        if (filters.client_id)
            query.set('client_id', String(filters.client_id));
        if (siteFilter) query.set('site_id', String(siteFilter));
        if (filters.date_from) query.set('date_from', filters.date_from);
        if (filters.date_to) query.set('date_to', filters.date_to);
        return `${path}?${query}`;
    };
    const [selected, setSelected] = useState<AuditEvent | null>(null);
    const [selectedSection, setSelectedSection] = useState<string | undefined>(
        undefined,
    );
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [exportUrl, setExportUrl] = useState<string | null>(null);

    // Open the read-only detail drawer; `section` focuses a panel (e.g. integrity).
    const openEvent = (e: AuditEvent, section?: string) => {
        setSelectedSection(section);
        setSelected(e);
    };

    // Read-only / navigational right-click menu for an event row (parity with PRN/CD).
    const openRowCtx = (ev: ReactMouseEvent, e: AuditEvent) => {
        ev.preventDefault();
        const link = eventPrimaryLink(e);
        const med =
            typeof e.details?.medication === 'string'
                ? e.details.medication
                : null;
        const items: ShiftCtxItem[] = [
            {
                icon: <Eye className="h-3.5 w-3.5" />,
                label: 'View record',
                sub: e.description,
                tone: 'primary',
                onClick: () => openEvent(e),
            },
            ...(e.client_id
                ? [
                      {
                          icon: <User className="h-3.5 w-3.5" />,
                          label: 'View client',
                          onClick: () =>
                              router.visit(
                                  `/operations/clients/${e.client_id}?tab=mar`,
                              ),
                      } satisfies ShiftCtxItem,
                  ]
                : []),
            ...(link
                ? [
                      {
                          icon: <FileText className="h-3.5 w-3.5" />,
                          label: `Open on ${link.label}`,
                          onClick: () => router.visit(link.href),
                      },
                  ]
                : []),
            {
                icon: <Fingerprint className="h-3.5 w-3.5" />,
                label: 'Check source record',
                sub: 'View the saved record behind this entry',
                onClick: () => openEvent(e, 'integrity'),
            },
            { sep: true },
            ...(canExportHistory && !e.id.startsWith('omission_')
                ? [
                      {
                          icon: <Download className="h-3.5 w-3.5" />,
                          label: 'Export this record',
                          sub: 'CSV',
                          onClick: () =>
                              setExportUrl(
                                  `/emar/audit/event/${encodeURIComponent(e.id)}/export`,
                              ),
                      } satisfies ShiftCtxItem,
                  ]
                : []),
            {
                icon: <Copy className="h-3.5 w-3.5" />,
                label: 'Copy record reference',
                onClick: () => {
                    void navigator.clipboard
                        ?.writeText(e.id)
                        .then(() => toast.success('Record reference copied'))
                        .catch(() => toast.error('Could not copy'));
                },
            },
        ];
        const t = CTX_TAG[ctxToneFor(e)];
        const meta = [e.client_name, med, fmtTime(e.timestamp)]
            .filter(Boolean)
            .join(' · ');
        setCtx({
            x: ev.clientX,
            y: ev.clientY,
            tag: eventMeta(e.event_type).label.toUpperCase(),
            tagBg: t.bg,
            tagColor: t.color,
            meta,
            items,
        });
    };

    // The server supplies the NZ period. Facets apply only to those loaded records.
    const windowEvents = useMemo(
        () =>
            events.filter((e) => {
                if (staffName && e.performed_by !== staffName) return false;
                if (source && e.source !== source) return false;
                const query = search.trim().toLowerCase();
                return (
                    !query ||
                    `${e.description} ${e.client_name} ${e.performed_by ?? ''}`
                        .toLowerCase()
                        .includes(query)
                );
            }),
        [events, staffName, source, search],
    );
    const filtered = useMemo(
        () => windowEvents.filter((e) => cat === 'all' || e.category === cat),
        [windowEvents, cat],
    );
    const gaps = useMemo(
        () => filtered.filter((e) => e.flags.length > 0),
        [filtered],
    );
    const rows = view === 'gaps' ? gaps : filtered;
    const hasFilters = !!(
        search ||
        clientId ||
        staffName ||
        source ||
        filters.date_from ||
        filters.date_to ||
        filters.event_types.length ||
        siteFilter ||
        cat !== 'all'
    );
    const clearFilters = () =>
        visitHistory(
            {
                client_id: null,
                site_id: null,
                date_from: null,
                date_to: null,
                event_types: [],
            },
            true,
        );
    const [dismissedAlerts, setDismissedAlerts] = useState<string[]>(() =>
        readDismissedAlerts(),
    );
    const dismissAlert = (kind: string) =>
        setDismissedAlerts((prev) => persistDismissedAlerts([...prev, kind]));
    const sources = useMemo(
        () => [...new Set(events.map((e) => e.source))].sort(),
        [events],
    );

    const windowOmissions = useMemo(
        () => windowEvents.filter((e) => e.flags.includes('omission')).length,
        [windowEvents],
    );
    const windowMissingWitness = useMemo(
        () =>
            windowEvents.filter((e) => e.flags.includes('missing_witness'))
                .length,
        [windowEvents],
    );
    // Device, network and edit-history metadata are deliberately omitted from
    // both the index and lazy integrity response.
    const allAlerts: AuditAlert[] = [];
    if (windowOmissions > 0) {
        allAlerts.push({
            kind: 'omission',
            tone: 'critical',
            icon: AlertTriangle,
            message: `${windowOmissions} MAR omission${windowOmissions === 1 ? '' : 's'} in these records. Open each record to review the outcome and reason.`,
            onReview: () => {
                setCat('doses');
                setView('gaps');
            },
        });
    }
    if (windowMissingWitness > 0) {
        allAlerts.push({
            kind: 'witness',
            tone: 'critical',
            icon: Lock,
            message: `${windowMissingWitness} controlled-drug ${windowMissingWitness === 1 ? 'entry is' : 'entries are'} missing a second signature.`,
            onReview: () => {
                setCat('controlled');
                setView('gaps');
            },
        });
    }
    const auditAlerts = allAlerts.filter(
        (a) => !dismissedAlerts.includes(a.kind),
    );

    const catCounts = useMemo(
        () =>
            Object.fromEntries(
                CATEGORIES.map((c) => [
                    c.id,
                    c.id === 'all'
                        ? windowEvents.length
                        : windowEvents.filter((e) => e.category === c.id)
                              .length,
                ]),
            ),
        [windowEvents],
    );

    const byDay = useMemo(() => {
        const groups = new Map<string, AuditEvent[]>();
        rows.forEach((e) => {
            const k = dayKey(e.timestamp);
            if (!groups.has(k)) groups.set(k, []);
            groups.get(k)!.push(e);
        });
        return [...groups.entries()];
    }, [rows]);

    const viewTabs = [
        { key: 'timeline', label: 'Timeline', icon: Activity },
        { key: 'table', label: 'Table', icon: Table },
        {
            key: 'gaps',
            label: 'Needs review',
            icon: ShieldAlert,
            count: gaps.length,
            alert: true,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Medication clinical history" />
            <div className="flex min-w-0 flex-col gap-5">
                <PageHeader
                    wrapTitle
                    icon={History}
                    title="Medication history"
                    subline="Dose outcomes, medicine changes, stock and controlled-drug records."
                    meters={
                        <EmarMeters
                            items={[
                                {
                                    label: 'Loaded records',
                                    value: windowEvents.length,
                                    caption:
                                        'Matching the current search and scope',
                                    onClick: () => {
                                        setCat('all');
                                        setView('table');
                                    },
                                },
                                {
                                    label: 'Needs review',
                                    value: gaps.length,
                                    caption:
                                        'Audit flags in the selected category',
                                    tone: gaps.length ? 'warning' : 'brand',
                                    onClick: () => setView('gaps'),
                                },
                                {
                                    label: 'Controlled records',
                                    value: catCounts.controlled ?? 0,
                                    caption: 'Visible controlled-drug events',
                                    onClick: () => {
                                        setCat('controlled');
                                        setView('table');
                                    },
                                },
                                {
                                    label: 'Witness gaps',
                                    value: windowMissingWitness,
                                    caption:
                                        'Missing a recorded second signature',
                                    tone: windowMissingWitness
                                        ? 'critical'
                                        : 'brand',
                                    onClick: () => {
                                        setCat('controlled');
                                        setView('gaps');
                                    },
                                },
                            ]}
                        />
                    }
                    actions={
                        <>
                            <PageHeaderSearch
                                value={search}
                                onChange={setSearch}
                                placeholder="Search loaded records"
                                ariaLabel="Search loaded history"
                            />
                            <PageHeaderGlassButton asChild icon={History}>
                                <a
                                    href={scopedHref(
                                        '/emar/reports/history/logs',
                                    )}
                                >
                                    Change log
                                </a>
                            </PageHeaderGlassButton>
                            <PageHeaderPrimaryButton asChild icon={Printer}>
                                <a href={scopedHref('/emar/reports')}>
                                    Print MAR &amp; CD register
                                </a>
                            </PageHeaderPrimaryButton>
                        </>
                    }
                    filters={
                        <>
                            <PageHeaderFilterSelect
                                label="All sites"
                                value={siteFilter ? String(siteFilter) : 'all'}
                                options={[
                                    { value: 'all', label: 'All sites' },
                                    ...sites.map((s) => ({
                                        value: String(s.id),
                                        label: s.name,
                                    })),
                                ]}
                                onChange={(id) =>
                                    visitHistory({
                                        site_id:
                                            id === 'all' ? null : Number(id),
                                    })
                                }
                            />
                            <PageHeaderFilterSelect
                                label="All clients"
                                value={clientId || 'all'}
                                options={[
                                    { value: 'all', label: 'All clients' },
                                    ...clients.map((c) => ({
                                        value: String(c.id),
                                        label: c.name,
                                    })),
                                ]}
                                onChange={(id) =>
                                    visitHistory({
                                        client_id:
                                            id === 'all' ? null : Number(id),
                                    })
                                }
                            />
                            <PageHeaderFilterSelect
                                label="Period"
                                value={range}
                                allValue="all"
                                options={RANGES}
                                onChange={onRange}
                            />
                            <Popover>
                                <PopoverTrigger asChild>
                                    <PageHeaderFilterButton icon={CalendarDays}>
                                        Custom dates
                                    </PageHeaderFilterButton>
                                </PopoverTrigger>
                                <PopoverContent
                                    align="end"
                                    className="w-[min(92vw,440px)]"
                                >
                                    <form
                                        className="flex min-w-0 flex-wrap items-end gap-3"
                                        onSubmit={(event) => {
                                            event.preventDefault();
                                            if (
                                                dateFrom &&
                                                dateTo &&
                                                dateFrom <= dateTo
                                            )
                                                visitHistory({
                                                    date_from: dateFrom,
                                                    date_to: dateTo,
                                                });
                                        }}
                                    >
                                        <label
                                            className="flex min-w-0 flex-1 flex-col gap-1 text-sm font-medium"
                                            htmlFor="history-date-from"
                                        >
                                            From (NZ date)
                                            <input
                                                id="history-date-from"
                                                type="date"
                                                required
                                                value={dateFrom}
                                                max={dateTo || undefined}
                                                onChange={(event) =>
                                                    setDateFrom(
                                                        event.target.value,
                                                    )
                                                }
                                                className="frontline-tap frontline-focus w-full min-w-0 rounded-md border bg-background px-3 py-2 text-sm"
                                            />
                                        </label>
                                        <label
                                            className="flex min-w-0 flex-1 flex-col gap-1 text-sm font-medium"
                                            htmlFor="history-date-to"
                                        >
                                            To (NZ date)
                                            <input
                                                id="history-date-to"
                                                type="date"
                                                required
                                                value={dateTo}
                                                min={dateFrom || undefined}
                                                onChange={(event) =>
                                                    setDateTo(
                                                        event.target.value,
                                                    )
                                                }
                                                className="frontline-tap frontline-focus w-full min-w-0 rounded-md border bg-background px-3 py-2 text-sm"
                                            />
                                        </label>
                                        <Button
                                            type="submit"
                                            className="frontline-tap frontline-focus"
                                        >
                                            Apply dates
                                        </Button>
                                    </form>
                                </PopoverContent>
                            </Popover>
                            {hasFilters && (
                                <PageHeaderFilterButton onClick={clearFilters}>
                                    Clear filters
                                </PageHeaderFilterButton>
                            )}
                        </>
                    }
                    rail={
                        <PageHeaderRail
                            ariaLabel="History views"
                            value={view}
                            onSelect={setView}
                            items={viewTabs}
                        />
                    }
                />
                <div className="flex flex-col gap-2 text-sm">
                    <p className="font-medium">
                        {periodLabel} · Pacific/Auckland
                    </p>
                    <p className="text-muted-foreground">
                        {stats.total} records in period · {stats.this_week} this
                        week · {stats.this_month} this month · {stats.open_gaps}{' '}
                        dose or witness flags
                    </p>
                    {historyNotice && (
                        <p role="status" className="text-status-warning">
                            {historyNotice}
                        </p>
                    )}
                    {omissionsNotice && (
                        <p className="text-muted-foreground">
                            MAR omissions: {omissionsNotice}
                        </p>
                    )}
                    <p className="text-muted-foreground">
                        Categories, search, staff and source filters cover the{' '}
                        {events.length} loaded records.
                    </p>
                    {filters.event_types.length > 0 && (
                        <p className="text-muted-foreground">
                            Record types:{' '}
                            {filters.event_types
                                .map((type) => eventMeta(type).label)
                                .join(', ')}
                            .
                        </p>
                    )}
                </div>

                {auditAlerts.length > 0 && (
                    <div className="flex flex-col gap-2">
                        {auditAlerts.map((a) => (
                            <AuditAlertRow
                                key={a.kind}
                                alert={a}
                                onDismiss={() => dismissAlert(a.kind)}
                            />
                        ))}
                    </div>
                )}

                <p className="text-sm text-muted-foreground" role="status">
                    {view === 'gaps'
                        ? `${rows.length} loaded record${rows.length === 1 ? '' : 's'} need review`
                        : `Showing ${rows.length} of ${events.length} loaded records`}
                </p>

                <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                    <div className="flex min-w-0 flex-col gap-3 border-b p-4">
                        <div
                            role="tablist"
                            aria-label="Record categories"
                            className="flex flex-wrap gap-2"
                            onKeyDown={(event) => {
                                const tabs = [
                                    ...event.currentTarget.querySelectorAll<HTMLButtonElement>(
                                        '[role="tab"]',
                                    ),
                                ];
                                const current = tabs.indexOf(
                                    document.activeElement as HTMLButtonElement,
                                );
                                let next: number | undefined;
                                if (event.key === 'ArrowRight')
                                    next = (current + 1) % tabs.length;
                                if (event.key === 'ArrowLeft')
                                    next =
                                        (current - 1 + tabs.length) %
                                        tabs.length;
                                if (event.key === 'Home') next = 0;
                                if (event.key === 'End') next = tabs.length - 1;
                                if (next !== undefined) {
                                    event.preventDefault();
                                    setCat(CATEGORIES[next].id);
                                    tabs[next]?.focus();
                                }
                            }}
                        >
                            {CATEGORIES.map((category) => {
                                const Icon = category.icon;
                                return (
                                    <Button
                                        key={category.id}
                                        type="button"
                                        role="tab"
                                        aria-selected={cat === category.id}
                                        variant={
                                            cat === category.id
                                                ? 'secondary'
                                                : 'ghost'
                                        }
                                        className="frontline-tap frontline-focus gap-2 whitespace-normal"
                                        onClick={() => setCat(category.id)}
                                    >
                                        <Icon className="size-4 shrink-0" />
                                        {category.label}{' '}
                                        <span className="tabular-nums">
                                            {catCounts[category.id]}
                                        </span>
                                    </Button>
                                );
                            })}
                        </div>
                        <div className="flex flex-wrap items-end gap-3">
                            <label className="flex min-w-0 flex-1 flex-col gap-1 text-sm">
                                Staff in loaded records
                                <select
                                    value={staffName}
                                    onChange={(event) =>
                                        setStaffName(event.target.value)
                                    }
                                    className="frontline-tap frontline-focus w-full min-w-0 rounded-md border bg-background px-3 py-2"
                                >
                                    <option value="">All staff</option>
                                    {staff.map((person) => (
                                        <option
                                            key={person.id}
                                            value={person.name}
                                        >
                                            {person.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="flex min-w-0 flex-1 flex-col gap-1 text-sm">
                                Source in loaded records
                                <select
                                    value={source}
                                    onChange={(event) =>
                                        setSource(event.target.value)
                                    }
                                    className="frontline-tap frontline-focus w-full min-w-0 rounded-md border bg-background px-3 py-2"
                                >
                                    <option value="">All sources</option>
                                    {sources.map((name) => (
                                        <option key={name} value={name}>
                                            {name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        </div>
                    </div>

                    {rows.length === 0 ? (
                        <div className="px-5 py-16 text-center text-sm text-muted-foreground">
                            {view === 'gaps'
                                ? 'No flagged records in the loaded results. This does not confirm that every dose is recorded.'
                                : 'No loaded records match these filters. Change the period or clear a filter.'}
                        </div>
                    ) : view === 'timeline' ? (
                        <div className="flex flex-col gap-4 p-4">
                            {byDay.map(([day, items]) => (
                                <div key={day}>
                                    <div className="mb-2 flex items-center gap-3">
                                        <span className="text-xs font-bold">
                                            {day}
                                        </span>
                                        <span className="h-px flex-1 bg-border" />
                                        <span className="text-xs text-muted-foreground">
                                            {items.length} records
                                        </span>
                                    </div>
                                    <div className="flex flex-col gap-2">
                                        {items.map((e) => (
                                            <TimelineRow
                                                key={e.id}
                                                e={e}
                                                onOpen={() => openEvent(e)}
                                                onCtx={(ev) =>
                                                    openRowCtx(ev, e)
                                                }
                                            />
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </div>
                    ) : view === 'table' ? (
                        <div className="min-w-0">
                            <div className="flex flex-col gap-2 p-4 md:hidden">
                                {rows.map((e) => (
                                    <TimelineRow
                                        key={e.id}
                                        e={e}
                                        onOpen={() => openEvent(e)}
                                        onCtx={(event) => openRowCtx(event, e)}
                                        showOutcome
                                    />
                                ))}
                            </div>
                            <div className="hidden overflow-x-auto md:block">
                                <table className="w-full min-w-[920px] text-sm">
                                    <thead>
                                        <tr className="bg-muted/50 text-left text-xs tracking-wide text-muted-foreground uppercase">
                                            <th className="px-4 py-2.5">
                                                NZ date / time
                                            </th>
                                            <th className="px-4 py-2.5">
                                                Record
                                            </th>
                                            <th className="px-4 py-2.5">
                                                Client
                                            </th>
                                            <th className="px-4 py-2.5">
                                                Outcome
                                            </th>
                                            <th className="px-4 py-2.5">
                                                Performed by
                                            </th>
                                            <th className="px-4 py-2.5">
                                                Witness
                                            </th>
                                            <th className="px-4 py-2.5">
                                                Source
                                            </th>
                                            <th className="px-4 py-2.5">
                                                <span className="sr-only">
                                                    Record actions
                                                </span>
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {rows.map((e) => {
                                            const m = eventMeta(e.event_type);
                                            const Icon = m.icon;
                                            return (
                                                <tr
                                                    key={e.id}
                                                    className="cursor-pointer border-b last:border-b-0 hover:bg-muted/30"
                                                    onContextMenu={(ev) =>
                                                        openRowCtx(ev, e)
                                                    }
                                                >
                                                    <td className="px-4 py-3 font-mono text-xs">
                                                        <button
                                                            type="button"
                                                            className="frontline-tap frontline-focus flex flex-col items-start text-left"
                                                            onClick={() =>
                                                                openEvent(e)
                                                            }
                                                        >
                                                            <span>
                                                                {formatDateLong(
                                                                    e.timestamp,
                                                                )}
                                                            </span>
                                                            <span>
                                                                {fmtTime(
                                                                    e.timestamp,
                                                                )}
                                                            </span>
                                                            <span className="text-primary">
                                                                View record
                                                            </span>
                                                        </button>
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <span className="inline-flex items-center gap-1.5">
                                                            <span
                                                                className={`flex h-5 w-5 items-center justify-center rounded ${m.cls}`}
                                                            >
                                                                <Icon className="h-3 w-3" />
                                                            </span>
                                                            {m.label}
                                                        </span>
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        {e.client_name}
                                                    </td>
                                                    <td className="px-4 py-3 text-muted-foreground">
                                                        {e.outcome ?? '—'}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        {e.performed_by ?? (
                                                            <span className="rounded-full bg-status-warning-bg px-1.5 py-0.5 text-xs font-semibold text-status-warning">
                                                                Not captured
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        {e.witness_required ? (
                                                            e.witness ? (
                                                                <span className="text-status-success">
                                                                    {e.witness}
                                                                </span>
                                                            ) : (
                                                                <span className="rounded-full bg-status-critical-bg px-1.5 py-0.5 text-xs font-semibold text-status-critical">
                                                                    Required —
                                                                    missing
                                                                </span>
                                                            )
                                                        ) : (
                                                            '—'
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <span className="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                                                            {e.source}
                                                        </span>
                                                    </td>
                                                    <td className="px-2 py-3">
                                                        <Button
                                                            variant="ghost"
                                                            className="frontline-tap frontline-focus"
                                                            aria-label={`Actions for ${e.description}`}
                                                            onClick={(event) =>
                                                                openRowCtx(
                                                                    event,
                                                                    e,
                                                                )
                                                            }
                                                        >
                                                            <MoreHorizontal className="size-4" />
                                                        </Button>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    ) : (
                        <div className="flex flex-col gap-4 p-4">
                            <div className="rounded-xl border border-status-critical/30 bg-status-critical-bg/50 px-4 py-3 text-sm text-status-critical">
                                Open each flagged record to review what happened
                                and decide whether follow-up is needed. Flags
                                may describe an earlier issue.
                            </div>
                            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                                {rows.map((e) => {
                                    const m = eventMeta(e.event_type);
                                    const Icon = m.icon;
                                    return (
                                        <div
                                            key={e.id}
                                            className="rounded-2xl border border-status-critical/30 bg-card p-4 shadow-sm"
                                            onContextMenu={(ev) =>
                                                openRowCtx(ev, e)
                                            }
                                        >
                                            <div className="flex items-center gap-2">
                                                <span
                                                    className={`flex h-8 w-8 items-center justify-center rounded-lg ${m.cls}`}
                                                >
                                                    <Icon className="h-4 w-4" />
                                                </span>
                                                <div className="text-sm font-semibold">
                                                    {e.flags
                                                        .map(
                                                            (f) =>
                                                                FLAG_META[f]
                                                                    ?.label ??
                                                                f,
                                                        )
                                                        .join(' · ')}
                                                </div>
                                            </div>
                                            <div className="mt-2 text-sm">
                                                {e.description}
                                            </div>
                                            <div className="mt-1 text-xs text-muted-foreground">
                                                {e.performed_by ??
                                                    'Staff not captured'}{' '}
                                                · {formatDateLong(e.timestamp)}{' '}
                                                · {fmtTime(e.timestamp)}
                                            </div>
                                            <div className="mt-3">
                                                <Button
                                                    className="frontline-tap frontline-focus"
                                                    variant="outline"
                                                    onClick={() => openEvent(e)}
                                                >
                                                    View record
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    className="frontline-tap frontline-focus"
                                                    aria-label={`Actions for ${e.description}`}
                                                    onClick={(event) =>
                                                        openRowCtx(event, e)
                                                    }
                                                >
                                                    <MoreHorizontal className="size-4" />
                                                </Button>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    )}
                </div>
            </div>

            {selected && (
                <MedicationEventDrawer
                    key={`${selected.id}-${selectedSection ?? 'what'}`}
                    event={selected}
                    initialSection={selectedSection}
                    onExport={
                        canExportHistory && !selected.id.startsWith('omission_')
                            ? () =>
                                  setExportUrl(
                                      `/emar/audit/event/${encodeURIComponent(selected.id)}/export`,
                                  )
                            : undefined
                    }
                    onClose={() => {
                        setSelected(null);
                        setSelectedSection(undefined);
                    }}
                />
            )}
            {ctx && (
                <HistoryRecordMenu ctx={ctx} onClose={() => setCtx(null)} />
            )}
            {exportUrl && (
                <HistoricalAuditExportDialog
                    url={exportUrl}
                    purposes={exportPurposes}
                    onClose={() => setExportUrl(null)}
                />
            )}
        </AppLayout>
    );
}

/** One row of the dismissible compliance alert strip — icon + message + Review jump + dismiss. */
function AuditAlertRow({
    alert,
    onDismiss,
}: {
    alert: AuditAlert;
    onDismiss: () => void;
}) {
    const Icon = alert.icon;
    const tone =
        alert.tone === 'critical'
            ? 'border-status-critical/30 bg-status-critical-bg/60 text-status-critical'
            : 'border-status-warning/30 bg-status-warning-bg/60 text-status-warning';
    return (
        <div
            className={`flex flex-wrap items-center justify-between gap-3 rounded-xl border px-4 py-3 ${tone}`}
        >
            <span className="flex items-center gap-2 text-sm font-medium">
                <Icon className="h-4 w-4 shrink-0" />
                {alert.message}
            </span>
            <span className="flex items-center gap-1.5">
                <Button
                    className="frontline-tap frontline-focus"
                    variant="outline"
                    onClick={alert.onReview}
                >
                    Review
                </Button>
                <button
                    type="button"
                    aria-label="Dismiss alert"
                    onClick={onDismiss}
                    className="frontline-tap frontline-focus grid size-11 place-items-center rounded-md hover:bg-foreground/10"
                >
                    <X className="h-4 w-4" />
                </button>
            </span>
        </div>
    );
}

function TimelineRow({
    e,
    onOpen,
    onCtx,
    showOutcome = false,
}: {
    e: AuditEvent;
    onOpen: () => void;
    onCtx: (ev: ReactMouseEvent) => void;
    showOutcome?: boolean;
}) {
    const m = eventMeta(e.event_type);
    const Icon = m.icon;
    const isGap = e.flags.length > 0;
    return (
        <div
            className={`flex min-w-0 items-center gap-1 rounded-xl border bg-card ${isGap ? 'border-dashed border-status-critical/50' : ''}`}
            onContextMenu={onCtx}
        >
            <button
                type="button"
                onClick={onOpen}
                className="frontline-tap frontline-focus flex min-w-0 flex-1 items-start gap-3 rounded-xl px-3 py-3 text-left hover:bg-muted/30"
            >
                <span
                    className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full ${m.cls}`}
                >
                    <Icon className="h-4 w-4" />
                </span>
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-1.5">
                        <span className="text-xs font-semibold">{m.label}</span>
                        <span className="font-mono text-xs text-muted-foreground">
                            {fmtTime(e.timestamp)}
                        </span>
                        <span className="rounded-full bg-muted px-1.5 py-0.5 text-xs text-muted-foreground">
                            {e.source}
                        </span>
                        {e.flags.map((f) => (
                            <span
                                key={f}
                                className={`rounded-full px-1.5 py-0.5 text-xs font-semibold ${FLAG_META[f]?.cls ?? ''}`}
                            >
                                {FLAG_META[f]?.label ?? f}
                            </span>
                        ))}
                    </div>
                    <div className="text-sm font-medium break-words">
                        {e.description}
                    </div>
                    <div className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                        <span className="font-medium">{e.client_name}</span> ·
                        {e.performed_by ?? (
                            <span className="text-status-warning">
                                Staff not captured
                            </span>
                        )}
                        {e.site_name ? ` · ${e.site_name}` : ''}
                    </div>
                    {showOutcome &&
                        (e.outcome || e.witness_required || e.witness) && (
                            <div className="mt-1 text-sm text-muted-foreground">
                                {e.outcome && <span>Outcome: {e.outcome}</span>}
                                {e.outcome && (e.witness_required || e.witness)
                                    ? ' · '
                                    : null}
                                {e.witness_required || e.witness
                                    ? `Witness: ${e.witness ?? 'Required — missing'}`
                                    : null}
                            </div>
                        )}
                </div>
            </button>
            <Button
                type="button"
                variant="ghost"
                className="frontline-tap frontline-focus mr-1 shrink-0"
                aria-label={`Actions for ${e.description}`}
                onClick={onCtx}
            >
                <MoreHorizontal className="size-4" />
            </Button>
        </div>
    );
}
