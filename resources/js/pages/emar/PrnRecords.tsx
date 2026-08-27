/* eslint-disable no-restricted-syntax -- the register/near-limit/trends surfaces are
   custom-layout bordered panels (not Card/Button); all colours are semantic tokens. */
import {
    PrnDetailDialog,
    type PrnAdministration,
} from '@/components/emar/prn-detail-dialog';
import { PrnEffectivenessDialog } from '@/components/emar/prn-effectiveness-dialog';
import { PrnNearLimitDialog } from '@/components/emar/prn-near-limit-dialog';
import {
    DayPickerChip,
    addDays,
    parseYmd,
} from '@/components/meds/day-picker-chip';
import { PageHero, type PageHeroStat } from '@/components/page';
import {
    Donut,
    DonutLegend,
    EntityFilter,
    MicroStats,
    ShiftContextMenu,
    TabStrip,
    type MicroStat,
    type RosterTabItem,
    type ShiftCtxItem,
    type ShiftCtxState,
} from '@/components/rostering';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { PrnWizard } from '@/pages/meds/today/components/prn-wizard';
import type {
    ClientInfo,
    PrnFollowUp,
    PrnMedication,
} from '@/pages/meds/today/types';
import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    BarChart3,
    ChevronLeft,
    ChevronRight,
    Clock,
    Eye,
    FileText,
    Flag,
    History,
    Pill,
    Plus,
    Printer,
    RotateCcw,
    Search,
    Stethoscope,
    TrendingUp,
    User,
    X,
} from 'lucide-react';
import {
    useMemo,
    useState,
    type MouseEvent as ReactMouseEvent,
    type ReactNode,
} from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

type Props = {
    administrations: PrnAdministration[];
    pending_reviews: PrnFollowUp[];
    prn_medications: PrnMedication[];
    clients: ClientInfo[];
    witnesses: { id: number; name: string }[];
    board_user: { name: string; role_label: string | null };
    can: {
        record: boolean;
        record_controlled: boolean;
    };
    date: string;
    today: string;
    is_today: boolean;
    date_label: string;
    range: number;
    client_id: number | null;
    q: string | null;
    history: { data: PrnAdministration[]; meta: HistoryMeta };
    history_givers: { id: number; name: string }[];
    history_active: {
        med: number | null;
        eff: string | null;
        cd: boolean;
        esc: boolean;
        given_by: number | null;
    };
    sites: { id: number; name: string }[];
    active_site: { id: number; name: string } | null;
    site_brand_colour: string | null;
};

type HistoryMeta = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

const HISTORY_EFF: { id: string; label: string }[] = [
    { id: 'all', label: 'All' },
    { id: 'effective', label: 'Effective' },
    { id: 'partially_effective', label: 'Partial' },
    { id: 'not_effective', label: 'Not effective' },
    { id: 'review_due', label: 'Review due' },
];

type Modal =
    | { type: 'record'; initialMedId?: number | null }
    | { type: 'effect'; followUp: PrnFollowUp }
    | { type: 'detail'; admin: PrnAdministration }
    | { type: 'near'; med: PrnMedication }
    | null;

/** Map a register administration to the PrnFollowUp shape the effect wizard takes. */
function adminToFollowUp(a: PrnAdministration): PrnFollowUp {
    return {
        administration_id: a.id,
        client_id: a.client_id,
        medication_name: a.medication_name,
        is_controlled: a.controlled_drug,
        dose_given: a.dose_given,
        given_at: a.administered_at,
        given_time: a.given_time,
        check_at: null,
    };
}

/** Effectiveness → context-menu header tag colour (semantic token CSS vars). */
function effCtxTag(
    eff: string | null,
    label: string | null,
): { tag: string; tagBg: string; tagColor: string } {
    if (eff === 'effective')
        return {
            tag: label ?? 'Effective',
            tagBg: 'var(--status-success-bg)',
            tagColor: 'var(--status-success)',
        };
    if (eff === 'partially_effective')
        return {
            tag: label ?? 'Partial',
            tagBg: 'var(--status-warning-bg)',
            tagColor: 'var(--status-warning)',
        };
    if (eff === 'not_effective')
        return {
            tag: label ?? 'Not effective',
            tagBg: 'var(--status-critical-bg)',
            tagColor: 'var(--status-critical)',
        };
    return {
        tag: 'Review due',
        tagBg: 'var(--status-info-bg)',
        tagColor: 'var(--status-info)',
    };
}

function hue(id: number): number {
    return Math.round((id * 137.508) % 360);
}
function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((p) => p[0]!.toUpperCase())
        .join('');
}
function Avatar({ id, name }: { id: number; name: string }) {
    return (
        <span
            className="flex h-6 w-6 items-center justify-center rounded-full text-[10px] font-bold text-primary-foreground"
            style={{ backgroundColor: `oklch(0.52 0.16 ${hue(id)})` }}
        >
            {initials(name)}
        </span>
    );
}
function effTone(eff: string | null): string {
    if (eff === 'effective') return 'bg-status-success-bg text-status-success';
    if (eff === 'partially_effective')
        return 'bg-status-warning-bg text-status-warning';
    if (eff === 'not_effective')
        return 'bg-status-critical-bg text-status-critical';
    return 'bg-status-info-bg text-status-info'; // review due
}

const STATUS_FILTERS = [
    { id: 'all', label: 'All' },
    { id: 'review_due', label: 'Review due' },
    { id: 'effective', label: 'Effective' },
    { id: 'partially_effective', label: 'Partial' },
    { id: 'not_effective', label: 'Not effective' },
];

/** Bordered trends card with a title (no Card primitive — custom layout). */
function TrendCard({
    title,
    hint,
    children,
}: {
    title: string;
    hint?: string;
    children: ReactNode;
}) {
    return (
        <div className="rounded-2xl border bg-card p-5 shadow-sm">
            <div className="mb-3 flex items-baseline justify-between gap-2">
                <div className="text-[15px] font-bold">{title}</div>
                {hint ? (
                    <div className="text-xs text-muted-foreground">{hint}</div>
                ) : null}
            </div>
            {children}
        </div>
    );
}

/** Horizontal token bar list (med / indication / resident rankings). */
function TrendBars({
    items,
    max,
    barClass = 'bg-primary',
    empty,
}: {
    items: [string, number][];
    max: number;
    barClass?: string;
    empty: string;
}) {
    if (items.length === 0)
        return <p className="text-sm text-muted-foreground">{empty}</p>;
    return (
        <ul className="flex flex-col gap-2.5">
            {items.map(([name, count]) => (
                <li key={name} className="flex items-center gap-3 text-sm">
                    <span className="w-40 shrink-0 truncate">{name}</span>
                    <span className="h-2.5 flex-1 overflow-hidden rounded-full bg-muted">
                        <span
                            className={`block h-full rounded-full ${barClass}`}
                            style={{ width: `${(count / max) * 100}%` }}
                        />
                    </span>
                    <span className="w-8 text-right text-muted-foreground">
                        {count}
                    </span>
                </li>
            ))}
        </ul>
    );
}

export default function PrnRecords(props: Props) {
    const {
        administrations,
        pending_reviews: reviews,
        prn_medications: prnMeds,
        clients,
        witnesses,
        board_user: signer,
        date,
        today,
        is_today: isToday,
        range,
        sites,
        active_site: activeSite,
        site_brand_colour: brandColour,
    } = props;

    const [activeTab, setActiveTab] = useState('register');
    const [search, setSearch] = useState(props.q ?? '');
    const [statusFilter, setStatusFilter] = useState('all');
    const [siteFilter, setSiteFilter] = useState<number | null>(
        activeSite?.id ?? null,
    );
    const [clientFilter, setClientFilter] = useState<number | null>(
        props.client_id ?? null,
    );
    const [modal, setModal] = useState<Modal>(null);
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const canRecord = props.can.record;
    const canRecordControlled = props.can.record_controlled;
    const canRecordMedication = (isControlled: boolean) =>
        canRecord && (!isControlled || canRecordControlled);
    const canRecordFollowUp = (followUp: PrnFollowUp) =>
        canRecordMedication(Boolean(followUp.is_controlled));
    const recordablePrnMeds = useMemo(
        () =>
            canRecord
                ? prnMeds.filter(
                      (medication) =>
                          !medication.is_controlled || canRecordControlled,
                  )
                : [],
        [canRecord, canRecordControlled, prnMeds],
    );

    // History tab is server-filtered (paginated archive); these chips drive
    // router.get, unlike the register's client-side search/status chips.
    const history = props.history;
    const historyGivers = props.history_givers;
    const [medFilter, setMedFilter] = useState<number | null>(
        props.history_active.med,
    );
    const [effFilter, setEffFilter] = useState(
        props.history_active.eff ?? 'all',
    );
    const [cdOnly, setCdOnly] = useState(props.history_active.cd);
    const [escOnly, setEscOnly] = useState(props.history_active.esc);
    const [giverFilter, setGiverFilter] = useState<number | null>(
        props.history_active.given_by,
    );

    // The active History server-filter params; folded into every reload so the
    // History archive keeps composing with the hero date/site/client filters.
    const historyParams = (): Record<string, string | number | undefined> => ({
        q: search || undefined,
        history_med: medFilter ?? undefined,
        history_eff: effFilter !== 'all' ? effFilter : undefined,
        history_cd: cdOnly ? 1 : undefined,
        history_esc: escOnly ? 1 : undefined,
        history_given_by: giverFilter ?? undefined,
    });

    // Calendar + Site + Client round-trip to the server (the register is a
    // server-windowed query); the register's text search stays client-side over
    // the loaded rows. `over` keys set to undefined are dropped from the query.
    const reload = (over: Record<string, string | number | undefined>) => {
        const params: Record<string, string | number | undefined> = {
            ...(siteFilter ? { site_id: siteFilter } : {}),
            ...(clientFilter ? { client_id: clientFilter } : {}),
            ...(date !== today ? { date } : {}),
            ...(range && range !== 30 ? { range } : {}),
            ...historyParams(),
            ...over,
        };
        Object.keys(params).forEach(
            (k) => params[k] === undefined && delete params[k],
        );
        router.get('/emar/prn', params, {
            preserveState: true,
            preserveScroll: true,
        });
    };
    const goDate = (ymd: string) =>
        reload({ date: ymd === today ? undefined : ymd });
    const onSite = (id: number | null) => {
        setSiteFilter(id);
        reload({ site_id: id ?? undefined });
    };
    const onClient = (id: number | null) => {
        setClientFilter(id);
        reload({ client_id: id ?? undefined });
    };
    const stepLabel = (ymd: string) =>
        parseYmd(ymd).toLocaleDateString('en-NZ', {
            weekday: 'short',
            day: 'numeric',
        });

    // History chip changes reset to page 1; pagination keeps the active filters.
    const setHist = (over: Record<string, string | number | undefined>) =>
        reload({ history_page: 1, ...over });
    const goHistoryPage = (page: number) => reload({ history_page: page });

    const openRowCtx = (e: ReactMouseEvent, a: PrnAdministration) => {
        e.preventDefault();
        const t = effCtxTag(a.effectiveness, a.effectiveness_label);
        const reviewDue = !a.effectiveness;
        const canMutate = canRecordMedication(a.controlled_drug);
        const items: ShiftCtxItem[] = [
            {
                icon: <Eye className="h-3.5 w-3.5" />,
                label: 'View details',
                sub: `${a.medication_name ?? 'PRN'}${a.given_time ? ` · ${a.given_time}` : ''}`,
                tone: 'primary',
                onClick: () => setModal({ type: 'detail', admin: a }),
            },
            ...(reviewDue && canMutate
                ? [
                      {
                          icon: <Stethoscope className="h-3.5 w-3.5" />,
                          label: 'Record effectiveness',
                          sub: 'Did it help?',
                          onClick: () =>
                              setModal({
                                  type: 'effect',
                                  followUp: adminToFollowUp(a),
                              }),
                      } satisfies ShiftCtxItem,
                  ]
                : []),
            ...(canMutate
                ? [
                      {
                          icon: <RotateCcw className="h-3.5 w-3.5" />,
                          label: 'Re-record / correct dose',
                          onClick: () =>
                              setModal({
                                  type: 'record',
                                  initialMedId: a.client_medication_id,
                              }),
                      } satisfies ShiftCtxItem,
                  ]
                : []),
            { sep: true },
            {
                icon: <User className="h-3.5 w-3.5" />,
                label: 'View client',
                onClick: () =>
                    router.visit(`/operations/clients/${a.client_id}?tab=mar`),
            },
            ...(a.mar_url
                ? [
                      {
                          icon: <FileText className="h-3.5 w-3.5" />,
                          label: 'Open on MAR chart',
                          onClick: () => router.visit(a.mar_url!),
                      } satisfies ShiftCtxItem,
                  ]
                : []),
            {
                icon: <Printer className="h-3.5 w-3.5" />,
                label: 'Print PRN slip',
                onClick: () => window.print(),
            },
            { sep: true },
            {
                icon: <Flag className="h-3.5 w-3.5" />,
                label: 'Flag concern',
                sub: 'Raise an incident',
                tone: 'critical',
                onClick: () =>
                    router.visit(`/clients/${a.client_id}/incidents`),
            },
        ];
        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag: t.tag,
            tagBg: t.tagBg,
            tagColor: t.tagColor,
            meta: `${a.client_name} · ${a.medication_name ?? 'PRN'}${a.given_time ? ` · ${a.given_time}` : ''}`,
            items,
        });
    };

    const clientsMap = useMemo(
        () => new Map(clients.map((c) => [c.id, c])),
        [clients],
    );
    const nearLimit = useMemo(
        () => prnMeds.filter((m) => m.near_limit || m.over_limit),
        [prnMeds],
    );
    const medCountById = useMemo(
        () => new Map(prnMeds.map((m) => [m.id, m])),
        [prnMeds],
    );

    const register = useMemo(() => {
        const q = search.toLowerCase();
        return administrations.filter((a) => {
            if (statusFilter === 'review_due' && a.effectiveness) return false;
            if (
                ['effective', 'partially_effective', 'not_effective'].includes(
                    statusFilter,
                ) &&
                a.effectiveness !== statusFilter
            )
                return false;
            if (
                q &&
                !`${a.medication_name ?? ''} ${a.client_name}`
                    .toLowerCase()
                    .includes(q)
            )
                return false;
            return true;
        });
    }, [administrations, statusFilter, search]);

    const TABS: RosterTabItem[] = [
        {
            id: 'register',
            label: 'Register',
            icon: BarChart3,
            tone: 'primary',
            badge: administrations.length || undefined,
        },
        {
            id: 'reviews',
            label: 'Reviews due',
            icon: Clock,
            tone: 'warning',
            badge: reviews.length || undefined,
        },
        {
            id: 'near',
            label: 'Near limit',
            icon: AlertTriangle,
            tone: 'critical',
            badge: nearLimit.length || undefined,
        },
        { id: 'trends', label: 'Trends', icon: TrendingUp, tone: 'info' },
        {
            id: 'history',
            label: 'History',
            icon: History,
            tone: 'info',
            badge: history.meta.total || undefined,
        },
    ];

    const heroStats: PageHeroStat[] = [
        {
            label: 'Given',
            value: administrations.filter((a) => a.status === 'given').length,
            sub: 'last 30d',
        },
        {
            label: 'Reviews',
            value: reviews.length,
            sub: 'due now',
            tone: reviews.length > 0 ? 'warning' : 'neutral',
        },
        {
            label: 'Near limit',
            value: nearLimit.length,
            tone: nearLimit.length > 0 ? 'critical' : 'neutral',
        },
    ];

    const trends = useMemo(() => {
        const byMed = new Map<string, number>();
        const byInd = new Map<string, number>();
        const byClient = new Map<string, number>();
        const byDay = new Map<string, { count: number; ts: number }>();
        const byHour = Array.from({ length: 24 }, () => 0);
        let eff = 0;
        let partial = 0;
        let none = 0;
        let due = 0;
        let escalations = 0;

        for (const a of administrations) {
            byMed.set(
                a.medication_name ?? '—',
                (byMed.get(a.medication_name ?? '—') ?? 0) + 1,
            );
            const ind =
                (a.reason ?? a.indication ?? 'Unspecified')
                    .split(' (')[0]
                    .trim() || 'Unspecified';
            byInd.set(ind, (byInd.get(ind) ?? 0) + 1);
            byClient.set(a.client_name, (byClient.get(a.client_name) ?? 0) + 1);
            if (a.given_date) {
                const ts = a.administered_at
                    ? new Date(a.administered_at).getTime()
                    : 0;
                const cur = byDay.get(a.given_date);
                byDay.set(a.given_date, {
                    count: (cur?.count ?? 0) + 1,
                    ts: cur ? Math.min(cur.ts, ts) : ts,
                });
            }
            if (a.given_time) {
                const h = parseInt(a.given_time.split(':')[0]!, 10);
                if (!Number.isNaN(h) && h >= 0 && h < 24) byHour[h] += 1;
            }
            if (a.effectiveness === 'effective') eff += 1;
            else if (a.effectiveness === 'partially_effective') partial += 1;
            else if (a.effectiveness === 'not_effective') none += 1;
            else due += 1;
            if (a.effectiveness_detail?.escalation_needed) escalations += 1;
        }

        const top = (m: Map<string, number>, n: number) => {
            const ranked = [...m.entries()]
                .sort((x, y) => y[1] - x[1])
                .slice(0, n);
            return { ranked, max: ranked[0]?.[1] ?? 1 };
        };

        const days = [...byDay.entries()]
            .map(([label, v]) => ({ label, count: v.count, ts: v.ts }))
            .sort((x, y) => x.ts - y.ts)
            .map(({ label, count }) => ({ label, count }));
        const hours = byHour.map((count, h) => ({
            label: String(h).padStart(2, '0'),
            count,
        }));

        const reviewed = eff + partial + none;
        const effSegments = [
            {
                key: 'effective',
                label: 'Effective',
                value: eff,
                color: 'var(--status-success)',
            },
            {
                key: 'partial',
                label: 'Partial',
                value: partial,
                color: 'var(--status-warning)',
            },
            {
                key: 'none',
                label: 'Not effective',
                value: none,
                color: 'var(--status-critical)',
            },
            {
                key: 'due',
                label: 'Review due',
                value: due,
                color: 'var(--status-info)',
            },
        ].filter((s) => s.value > 0);

        return {
            total: administrations.length,
            meds: top(byMed, 8),
            indications: top(byInd, 6),
            residents: top(byClient, 6),
            days,
            hours,
            reviewed,
            effPct: reviewed ? Math.round((eff / reviewed) * 100) : 0,
            effSegments,
            escalations,
            nearCount: prnMeds.filter((m) => m.near_limit && !m.over_limit)
                .length,
            overCount: prnMeds.filter((m) => m.over_limit).length,
        };
    }, [administrations, prnMeds]);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'eMAR', href: '/emar' },
                { title: 'PRN Records', href: '/emar/prn' },
            ]}
        >
            <Head title="PRN Records" />
            <div className="flex flex-col gap-6 p-6">
                <PageHero
                    variant="hero"
                    category="ops"
                    brandColour={brandColour}
                    icon={Pill}
                    title={
                        <span>
                            <span className="flex items-center gap-2 text-[10.5px] font-semibold tracking-wide text-primary-foreground/80 uppercase">
                                <span
                                    aria-hidden
                                    className="relative inline-flex h-2 w-2"
                                >
                                    <span className="absolute inset-0 animate-ping rounded-full bg-status-success/70" />
                                    <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success" />
                                </span>
                                As-needed medication register
                            </span>
                            <span className="mt-1 block text-[26px] leading-tight font-bold">
                                PRN records for{' '}
                                <span className="border-b-2 border-primary-foreground/40">
                                    {activeSite?.name ?? 'your services'}
                                </span>
                            </span>
                        </span>
                    }
                    description="Record as-needed doses, track daily limits and complete effectiveness reviews — every action stays on this page."
                    stats={heroStats}
                    actions={
                        canRecord ? (
                            <Button
                                className="bg-primary-foreground text-primary hover:bg-primary-foreground/90"
                                onClick={() => setModal({ type: 'record' })}
                            >
                                <Plus className="h-4 w-4" />
                                Record PRN dose
                            </Button>
                        ) : null
                    }
                    footer={
                        <div className="flex flex-col items-stretch gap-2 py-3 md:flex-row md:items-center md:justify-between">
                            <div className="flex flex-wrap items-center gap-1.5">
                                {/* eslint-disable no-restricted-syntax -- segmented day-stepper on the dark hero; not a shadcn Button (rostering idiom). */}
                                <button
                                    type="button"
                                    className="inline-flex items-center gap-1 rounded-md border border-primary-foreground/20 bg-primary-foreground/10 px-3 py-1.5 text-xs font-semibold text-primary-foreground hover:bg-primary-foreground/20"
                                    onClick={() => goDate(addDays(date, -1))}
                                >
                                    <ChevronLeft className="h-3.5 w-3.5" />
                                    {stepLabel(addDays(date, -1))}
                                </button>
                                <DayPickerChip
                                    date={date}
                                    isToday={isToday}
                                    onPick={goDate}
                                    caption="Register, reviews and trends are for the selected day's lookback window."
                                />
                                <button
                                    type="button"
                                    className="inline-flex items-center gap-1 rounded-md border border-primary-foreground/20 bg-primary-foreground/10 px-3 py-1.5 text-xs font-semibold text-primary-foreground hover:bg-primary-foreground/20"
                                    onClick={() => goDate(addDays(date, 1))}
                                >
                                    {stepLabel(addDays(date, 1))}
                                    <ChevronRight className="h-3.5 w-3.5" />
                                </button>
                                {!isToday ? (
                                    <button
                                        type="button"
                                        className="inline-flex items-center gap-1 rounded-md border border-primary-foreground/35 bg-primary-foreground/20 px-3 py-1.5 text-xs font-semibold text-primary-foreground hover:bg-primary-foreground/30"
                                        onClick={() => goDate(today)}
                                    >
                                        Back to today
                                    </button>
                                ) : null}
                            </div>
                            <div className="flex flex-wrap items-center gap-2 md:ml-auto md:justify-end">
                                <div className="relative w-full max-w-xs md:w-[260px]">
                                    <Search className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                    {/* eslint-disable-next-line no-restricted-syntax -- white pill search on the dark hero per the design handoff. */}
                                    <input
                                        value={search}
                                        onChange={(e) =>
                                            setSearch(e.target.value)
                                        }
                                        onKeyDown={(e) => {
                                            if (
                                                e.key === 'Enter' &&
                                                activeTab === 'history'
                                            )
                                                setHist({});
                                        }}
                                        placeholder={
                                            activeTab === 'history'
                                                ? 'Search archive — press Enter…'
                                                : 'Search client or medication…'
                                        }
                                        aria-label="Search PRN records"
                                        className="h-8 w-full rounded-full border-0 bg-primary-foreground pr-3 pl-9 text-[13px] text-foreground shadow-sm outline-none placeholder:text-muted-foreground/80 focus:ring-2 focus:ring-primary-foreground/50"
                                    />
                                    {search ? (
                                        // eslint-disable-next-line no-restricted-syntax -- inline clear affordance inside the pill search input.
                                        <button
                                            type="button"
                                            aria-label="Clear search"
                                            onClick={() => {
                                                setSearch('');
                                                if (activeTab === 'history')
                                                    reload({
                                                        q: undefined,
                                                        history_page: 1,
                                                    });
                                            }}
                                            className="absolute top-1/2 right-2 grid h-5 w-5 -translate-y-1/2 place-items-center rounded-full text-muted-foreground hover:bg-muted"
                                        >
                                            <X className="h-3.5 w-3.5" />
                                        </button>
                                    ) : null}
                                </div>
                                {sites.length > 0 ? (
                                    <EntityFilter
                                        label="Site"
                                        allLabel="All sites"
                                        items={sites}
                                        value={siteFilter}
                                        onChange={onSite}
                                        onDark
                                    />
                                ) : null}
                                <EntityFilter
                                    label="Client"
                                    allLabel="All clients"
                                    items={clients.map((c) => ({
                                        id: c.id,
                                        name: c.name,
                                        description: c.site_name,
                                    }))}
                                    value={clientFilter}
                                    onChange={onClient}
                                    onDark
                                />
                            </div>
                        </div>
                    }
                />

                <TabStrip
                    value={activeTab}
                    onChange={setActiveTab}
                    items={TABS}
                    ariaLabel="PRN views"
                />

                {activeTab === 'register' && (
                    <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                        <div className="flex flex-wrap items-center gap-2.5 border-b p-3.5">
                            <span className="text-sm font-semibold">
                                PRN administration register
                            </span>
                            <div className="flex flex-wrap gap-1">
                                {STATUS_FILTERS.map((f) => (
                                    <Button
                                        key={f.id}
                                        size="sm"
                                        variant={
                                            statusFilter === f.id
                                                ? 'secondary'
                                                : 'ghost'
                                        }
                                        onClick={() => setStatusFilter(f.id)}
                                    >
                                        {f.label}
                                    </Button>
                                ))}
                            </div>
                            <span className="ml-auto text-xs text-muted-foreground">
                                {register.length} of {administrations.length}
                            </span>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[840px] text-sm">
                                <thead>
                                    <tr className="bg-muted text-left text-[11px] tracking-wide text-muted-foreground uppercase">
                                        <th className="px-4 py-2.5">
                                            Date / time
                                        </th>
                                        <th className="px-4 py-2.5">Client</th>
                                        <th className="px-4 py-2.5">
                                            Medication
                                        </th>
                                        <th className="px-4 py-2.5">
                                            Indication
                                        </th>
                                        <th className="px-4 py-2.5">Today</th>
                                        <th className="px-4 py-2.5">
                                            Effectiveness
                                        </th>
                                        <th className="px-4 py-2.5">
                                            Given by
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {register.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={7}
                                                className="px-4 py-12 text-center text-muted-foreground"
                                            >
                                                No PRN doses match these
                                                filters.
                                            </td>
                                        </tr>
                                    ) : (
                                        register.map((a) => {
                                            const med = medCountById.get(
                                                a.client_medication_id,
                                            );
                                            return (
                                                <tr
                                                    key={a.id}
                                                    className="cursor-pointer border-b last:border-b-0 hover:bg-muted/40"
                                                    onClick={() =>
                                                        setModal({
                                                            type: 'detail',
                                                            admin: a,
                                                        })
                                                    }
                                                    onContextMenu={(e) =>
                                                        openRowCtx(e, a)
                                                    }
                                                >
                                                    <td className="px-4 py-3">
                                                        <div className="font-medium">
                                                            {a.given_time}
                                                        </div>
                                                        <div className="text-xs text-muted-foreground">
                                                            {a.given_date}
                                                        </div>
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <span className="flex items-center gap-2">
                                                            <Avatar
                                                                id={a.client_id}
                                                                name={
                                                                    a.client_name
                                                                }
                                                            />
                                                            {a.client_name}
                                                        </span>
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <div className="flex items-center gap-1.5 font-medium">
                                                            {a.medication_name}
                                                            {a.controlled_drug && (
                                                                <span className="rounded bg-status-critical-bg px-1 py-0.5 text-[9px] font-bold text-status-critical">
                                                                    CD
                                                                </span>
                                                            )}
                                                        </div>
                                                        {a.dose_given && (
                                                            <div className="text-xs text-muted-foreground">
                                                                {a.dose_given}
                                                            </div>
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3 text-muted-foreground">
                                                        {a.reason ??
                                                            a.indication ??
                                                            '—'}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        {med &&
                                                        med.max_per_day ? (
                                                            <span
                                                                className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${med.over_limit ? 'bg-status-critical-bg text-status-critical' : med.near_limit ? 'bg-status-warning-bg text-status-warning' : 'bg-muted text-muted-foreground'}`}
                                                            >
                                                                {
                                                                    med.given_last_24h
                                                                }{' '}
                                                                of{' '}
                                                                {
                                                                    med.max_per_day
                                                                }
                                                            </span>
                                                        ) : (
                                                            <span className="text-muted-foreground">
                                                                —
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <span
                                                            className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${effTone(a.effectiveness)}`}
                                                        >
                                                            {a.effectiveness_label ??
                                                                'Review due'}
                                                        </span>
                                                    </td>
                                                    <td className="px-4 py-3 text-muted-foreground">
                                                        {a.given_by ?? '—'}
                                                    </td>
                                                </tr>
                                            );
                                        })
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                {activeTab === 'reviews' && (
                    <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                        <div className="flex flex-wrap items-center gap-2 border-b bg-status-warning-bg/40 px-5 py-3 text-sm font-medium text-status-warning">
                            <Clock className="h-4 w-4" />
                            Effectiveness reviews due ({reviews.length})
                            <span className="ml-auto text-xs font-normal text-muted-foreground">
                                Within 4h of the dose
                            </span>
                        </div>
                        {reviews.length === 0 ? (
                            <div className="px-5 py-10 text-center text-sm text-muted-foreground">
                                No effectiveness reviews due.
                            </div>
                        ) : (
                            <ul className="divide-y">
                                {reviews.map((r) => (
                                    <li
                                        key={r.administration_id}
                                        className="flex items-center justify-between px-5 py-3"
                                    >
                                        <span className="flex items-center gap-2 text-sm">
                                            <Avatar
                                                id={r.client_id}
                                                name={
                                                    clientsMap.get(r.client_id)
                                                        ?.name ?? '?'
                                                }
                                            />
                                            <span className="font-medium">
                                                {clientsMap.get(r.client_id)
                                                    ?.name ?? 'Resident'}
                                            </span>{' '}
                                            · {r.medication_name}{' '}
                                            <span className="text-muted-foreground">
                                                given {r.given_time}
                                            </span>
                                        </span>
                                        {canRecordFollowUp(r) ? (
                                            <Button
                                                size="sm"
                                                onClick={() =>
                                                    setModal({
                                                        type: 'effect',
                                                        followUp: r,
                                                    })
                                                }
                                            >
                                                Record effectiveness
                                            </Button>
                                        ) : null}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                )}

                {activeTab === 'near' &&
                    (nearLimit.length === 0 ? (
                        <div className="rounded-2xl border bg-card px-5 py-12 text-center text-sm text-muted-foreground">
                            No PRN medications approaching their daily limit.
                        </div>
                    ) : (
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                            {nearLimit.map((m) => {
                                const pct = m.max_per_day
                                    ? Math.min(
                                          100,
                                          Math.round(
                                              (m.given_last_24h /
                                                  m.max_per_day) *
                                                  100,
                                          ),
                                      )
                                    : 0;
                                return (
                                    <button
                                        key={m.id}
                                        type="button"
                                        onClick={() =>
                                            setModal({ type: 'near', med: m })
                                        }
                                        className="flex flex-col gap-2 rounded-2xl border bg-card p-4 text-left shadow-sm transition-colors hover:border-primary/50 hover:bg-muted/30 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                                    >
                                        <div className="flex items-center justify-between">
                                            <span className="font-semibold">
                                                {m.name}
                                            </span>
                                            <span
                                                className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${m.over_limit ? 'bg-status-critical-bg text-status-critical' : 'bg-status-warning-bg text-status-warning'}`}
                                            >
                                                {m.over_limit
                                                    ? 'At limit'
                                                    : `${m.remaining_today ?? 0} left`}
                                            </span>
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {m.client_name}
                                        </div>
                                        <div className="text-xs">
                                            {m.given_last_24h} of{' '}
                                            {m.max_per_day} doses ·{' '}
                                            {m.remaining_today ?? 0} remaining
                                        </div>
                                        <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                                            <div
                                                className={`h-full rounded-full ${m.over_limit ? 'bg-status-critical' : 'bg-status-warning'}`}
                                                style={{ width: `${pct}%` }}
                                            />
                                        </div>
                                        <div className="text-[11px] text-muted-foreground">
                                            {m.last_given_label
                                                ? `Last given ${m.last_given_label}`
                                                : 'None today'}
                                            {m.min_hours_between
                                                ? ` · min ${m.min_hours_between}h between`
                                                : ''}
                                        </div>
                                    </button>
                                );
                            })}
                        </div>
                    ))}

                {activeTab === 'trends' &&
                    (administrations.length === 0 ? (
                        <div className="rounded-2xl border bg-card px-5 py-12 text-center text-sm text-muted-foreground">
                            No PRN doses recorded in this window — adjust the
                            date, site or client filters above.
                        </div>
                    ) : (
                        <div className="flex flex-col gap-4">
                            <MicroStats
                                stats={
                                    [
                                        {
                                            label: 'Total PRN doses',
                                            value: trends.total,
                                            tone: 'info',
                                        },
                                        {
                                            label: 'Reviewed effective',
                                            value: trends.effPct,
                                            suffix: '%',
                                            tone: 'ok',
                                        },
                                        {
                                            label: 'Escalations raised',
                                            value: trends.escalations,
                                            tone:
                                                trends.escalations > 0
                                                    ? 'crit'
                                                    : 'ok',
                                        },
                                        {
                                            label: 'Near / over limit',
                                            value:
                                                trends.nearCount +
                                                trends.overCount,
                                            tone:
                                                trends.nearCount +
                                                    trends.overCount >
                                                0
                                                    ? 'warn'
                                                    : 'ok',
                                        },
                                    ] satisfies MicroStat[]
                                }
                            />
                            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                                <TrendCard
                                    title="Effectiveness distribution"
                                    hint={`${trends.reviewed} reviewed`}
                                >
                                    {trends.effSegments.length === 0 ? (
                                        <p className="py-6 text-sm text-muted-foreground">
                                            No effectiveness reviews recorded
                                            yet.
                                        </p>
                                    ) : (
                                        <div className="flex items-center gap-5">
                                            <Donut
                                                segments={trends.effSegments}
                                                centerValue={`${trends.effPct}%`}
                                                centerLabel="effective"
                                                ariaLabel="PRN effectiveness distribution"
                                            />
                                            <DonutLegend
                                                className="flex-1"
                                                segments={trends.effSegments}
                                                showPercent
                                                accentKeys={['effective']}
                                            />
                                        </div>
                                    )}
                                </TrendCard>
                                <TrendCard
                                    title="Doses per day"
                                    hint={`to ${props.date_label}`}
                                >
                                    <div className="h-[180px]">
                                        <ResponsiveContainer
                                            width="100%"
                                            height="100%"
                                        >
                                            <BarChart
                                                data={trends.days}
                                                margin={{
                                                    top: 6,
                                                    right: 6,
                                                    left: -24,
                                                    bottom: 0,
                                                }}
                                            >
                                                <CartesianGrid
                                                    strokeDasharray="3 3"
                                                    className="stroke-muted/30"
                                                    vertical={false}
                                                />
                                                <XAxis
                                                    dataKey="label"
                                                    tick={{ fontSize: 10 }}
                                                    interval="preserveStartEnd"
                                                />
                                                <YAxis
                                                    allowDecimals={false}
                                                    tick={{ fontSize: 10 }}
                                                />
                                                <Tooltip
                                                    contentStyle={{
                                                        borderRadius: 8,
                                                        fontSize: 12,
                                                    }}
                                                    cursor={{
                                                        fill: 'var(--muted)',
                                                        opacity: 0.4,
                                                    }}
                                                />
                                                <Bar
                                                    dataKey="count"
                                                    fill="var(--primary)"
                                                    radius={[3, 3, 0, 0]}
                                                    name="Doses"
                                                />
                                            </BarChart>
                                        </ResponsiveContainer>
                                    </div>
                                </TrendCard>
                                <TrendCard
                                    title="Time-of-day pattern"
                                    hint="when PRNs are given"
                                >
                                    <div className="h-[180px]">
                                        <ResponsiveContainer
                                            width="100%"
                                            height="100%"
                                        >
                                            <BarChart
                                                data={trends.hours}
                                                margin={{
                                                    top: 6,
                                                    right: 6,
                                                    left: -24,
                                                    bottom: 0,
                                                }}
                                            >
                                                <CartesianGrid
                                                    strokeDasharray="3 3"
                                                    className="stroke-muted/30"
                                                    vertical={false}
                                                />
                                                <XAxis
                                                    dataKey="label"
                                                    tick={{ fontSize: 9 }}
                                                    interval={2}
                                                />
                                                <YAxis
                                                    allowDecimals={false}
                                                    tick={{ fontSize: 10 }}
                                                />
                                                <Tooltip
                                                    contentStyle={{
                                                        borderRadius: 8,
                                                        fontSize: 12,
                                                    }}
                                                    cursor={{
                                                        fill: 'var(--muted)',
                                                        opacity: 0.4,
                                                    }}
                                                    labelFormatter={(l) =>
                                                        `${l}:00`
                                                    }
                                                />
                                                <Bar
                                                    dataKey="count"
                                                    fill="var(--status-info)"
                                                    radius={[3, 3, 0, 0]}
                                                    name="Doses"
                                                />
                                            </BarChart>
                                        </ResponsiveContainer>
                                    </div>
                                </TrendCard>
                                <TrendCard title="By indication">
                                    <TrendBars
                                        items={trends.indications.ranked}
                                        max={trends.indications.max}
                                        barClass="bg-status-info"
                                        empty="No indications recorded."
                                    />
                                </TrendCard>
                                <TrendCard title="Most-used PRN medications">
                                    <TrendBars
                                        items={trends.meds.ranked}
                                        max={trends.meds.max}
                                        barClass="bg-primary"
                                        empty="No PRN doses recorded."
                                    />
                                </TrendCard>
                                <TrendCard title="Top PRN residents">
                                    <TrendBars
                                        items={trends.residents.ranked}
                                        max={trends.residents.max}
                                        barClass="bg-status-warning"
                                        empty="No PRN doses recorded."
                                    />
                                </TrendCard>
                            </div>
                        </div>
                    ))}

                {activeTab === 'history' && (
                    <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                        <div className="flex flex-wrap items-center gap-2.5 border-b p-3.5">
                            <span className="text-sm font-semibold">
                                PRN history archive
                            </span>
                            <EntityFilter
                                label="Medication"
                                allLabel="All meds"
                                pluralLabel="meds"
                                items={prnMeds.map((m) => ({
                                    id: m.id,
                                    name: m.name,
                                    description: m.client_name,
                                }))}
                                value={medFilter}
                                onChange={(id) => {
                                    setMedFilter(id);
                                    setHist({ history_med: id ?? undefined });
                                }}
                            />
                            <EntityFilter
                                label="Given by"
                                allLabel="All staff"
                                pluralLabel="staff"
                                items={historyGivers}
                                value={giverFilter}
                                onChange={(id) => {
                                    setGiverFilter(id);
                                    setHist({
                                        history_given_by: id ?? undefined,
                                    });
                                }}
                            />
                            <div className="flex flex-wrap gap-1">
                                {HISTORY_EFF.map((f) => (
                                    <Button
                                        key={f.id}
                                        size="sm"
                                        variant={
                                            effFilter === f.id
                                                ? 'secondary'
                                                : 'ghost'
                                        }
                                        onClick={() => {
                                            setEffFilter(f.id);
                                            setHist({
                                                history_eff:
                                                    f.id !== 'all'
                                                        ? f.id
                                                        : undefined,
                                            });
                                        }}
                                    >
                                        {f.label}
                                    </Button>
                                ))}
                            </div>
                            <Button
                                size="sm"
                                variant={cdOnly ? 'secondary' : 'ghost'}
                                onClick={() => {
                                    const v = !cdOnly;
                                    setCdOnly(v);
                                    setHist({ history_cd: v ? 1 : undefined });
                                }}
                            >
                                CD only
                            </Button>
                            <Button
                                size="sm"
                                variant={escOnly ? 'secondary' : 'ghost'}
                                onClick={() => {
                                    const v = !escOnly;
                                    setEscOnly(v);
                                    setHist({ history_esc: v ? 1 : undefined });
                                }}
                            >
                                Escalations
                            </Button>
                            <span className="ml-auto text-xs text-muted-foreground">
                                {history.meta.total} total
                            </span>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[980px] text-sm">
                                <thead>
                                    <tr className="bg-muted text-left text-[11px] tracking-wide text-muted-foreground uppercase">
                                        <th className="px-4 py-2.5">
                                            Date / time
                                        </th>
                                        <th className="px-4 py-2.5">Client</th>
                                        <th className="px-4 py-2.5">
                                            Medication
                                        </th>
                                        <th className="px-4 py-2.5">
                                            Indication
                                        </th>
                                        <th className="px-4 py-2.5">Today</th>
                                        <th className="px-4 py-2.5">
                                            Effectiveness
                                        </th>
                                        <th className="px-4 py-2.5">
                                            Reviewed
                                        </th>
                                        <th className="px-4 py-2.5">Esc.</th>
                                        <th className="px-4 py-2.5">
                                            Given by
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {history.data.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={9}
                                                className="px-4 py-12 text-center text-muted-foreground"
                                            >
                                                No PRN doses match these
                                                filters.
                                            </td>
                                        </tr>
                                    ) : (
                                        history.data.map((a) => {
                                            const med = medCountById.get(
                                                a.client_medication_id,
                                            );
                                            return (
                                                <tr
                                                    key={a.id}
                                                    className="cursor-pointer border-b last:border-b-0 hover:bg-muted/40"
                                                    onClick={() =>
                                                        setModal({
                                                            type: 'detail',
                                                            admin: a,
                                                        })
                                                    }
                                                    onContextMenu={(e) =>
                                                        openRowCtx(e, a)
                                                    }
                                                >
                                                    <td className="px-4 py-3">
                                                        <div className="font-medium">
                                                            {a.given_time}
                                                        </div>
                                                        <div className="text-xs text-muted-foreground">
                                                            {a.given_date}
                                                        </div>
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <span className="flex items-center gap-2">
                                                            <Avatar
                                                                id={a.client_id}
                                                                name={
                                                                    a.client_name
                                                                }
                                                            />
                                                            {a.client_name}
                                                        </span>
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <div className="flex items-center gap-1.5 font-medium">
                                                            {a.medication_name}
                                                            {a.controlled_drug && (
                                                                <span className="rounded bg-status-critical-bg px-1 py-0.5 text-[9px] font-bold text-status-critical">
                                                                    CD
                                                                </span>
                                                            )}
                                                        </div>
                                                        {a.dose_given && (
                                                            <div className="text-xs text-muted-foreground">
                                                                {a.dose_given}
                                                            </div>
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3 text-muted-foreground">
                                                        {a.reason ??
                                                            a.indication ??
                                                            '—'}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        {med &&
                                                        med.max_per_day ? (
                                                            <span
                                                                className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${med.over_limit ? 'bg-status-critical-bg text-status-critical' : med.near_limit ? 'bg-status-warning-bg text-status-warning' : 'bg-muted text-muted-foreground'}`}
                                                            >
                                                                {
                                                                    med.given_last_24h
                                                                }{' '}
                                                                of{' '}
                                                                {
                                                                    med.max_per_day
                                                                }
                                                            </span>
                                                        ) : (
                                                            <span className="text-muted-foreground">
                                                                —
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <span
                                                            className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${effTone(a.effectiveness)}`}
                                                        >
                                                            {a.effectiveness_label ??
                                                                'Review due'}
                                                        </span>
                                                    </td>
                                                    <td className="px-4 py-3 text-muted-foreground">
                                                        {a.effectiveness_detail
                                                            ?.review_minutes_after !=
                                                        null
                                                            ? `${a.effectiveness_detail.review_minutes_after} min`
                                                            : '—'}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        {a.effectiveness_detail
                                                            ?.escalation_needed ? (
                                                            <span className="inline-flex items-center rounded-full bg-status-critical-bg px-1.5 py-0.5 text-status-critical">
                                                                <Flag className="h-3 w-3" />
                                                            </span>
                                                        ) : (
                                                            <span className="text-muted-foreground">
                                                                —
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3 text-muted-foreground">
                                                        {a.given_by ?? '—'}
                                                    </td>
                                                </tr>
                                            );
                                        })
                                    )}
                                </tbody>
                            </table>
                        </div>
                        <div className="flex flex-wrap items-center gap-3 border-t p-3.5 text-xs text-muted-foreground">
                            <span>
                                {history.meta.total === 0
                                    ? 'No results'
                                    : `Showing ${history.meta.from}–${history.meta.to} of ${history.meta.total}`}
                            </span>
                            <div className="ml-auto flex items-center gap-2">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={history.meta.current_page <= 1}
                                    onClick={() =>
                                        goHistoryPage(
                                            history.meta.current_page - 1,
                                        )
                                    }
                                >
                                    <ChevronLeft className="h-4 w-4" />
                                    Prev
                                </Button>
                                <span>
                                    Page {history.meta.current_page} of{' '}
                                    {history.meta.last_page}
                                </span>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={
                                        history.meta.current_page >=
                                        history.meta.last_page
                                    }
                                    onClick={() =>
                                        goHistoryPage(
                                            history.meta.current_page + 1,
                                        )
                                    }
                                >
                                    Next
                                    <ChevronRight className="h-4 w-4" />
                                </Button>
                            </div>
                        </div>
                    </div>
                )}
            </div>

            {canRecord && modal?.type === 'record' && (
                <PrnWizard
                    medications={recordablePrnMeds}
                    clients={clientsMap}
                    date={date}
                    witnesses={witnesses}
                    signedAs={{
                        name: signer.name,
                        role_label: signer.role_label,
                    }}
                    initialMedId={modal.initialMedId ?? null}
                    onClose={() => setModal(null)}
                />
            )}
            {modal?.type === 'effect' && canRecordFollowUp(modal.followUp) && (
                <PrnEffectivenessDialog
                    followUp={modal.followUp}
                    client={clientsMap.get(modal.followUp.client_id)}
                    onClose={() => setModal(null)}
                />
            )}
            {modal?.type === 'detail' && (
                <PrnDetailDialog
                    admin={modal.admin}
                    med={medCountById.get(modal.admin.client_medication_id)}
                    onClose={() => setModal(null)}
                    onRecordEffectiveness={
                        canRecordMedication(modal.admin.controlled_drug)
                            ? () =>
                                  setModal({
                                      type: 'effect',
                                      followUp: adminToFollowUp(modal.admin),
                                  })
                            : undefined
                    }
                    onReRecordDose={
                        canRecordMedication(modal.admin.controlled_drug)
                            ? () =>
                                  setModal({
                                      type: 'record',
                                      initialMedId:
                                          modal.admin.client_medication_id,
                                  })
                            : undefined
                    }
                />
            )}
            {modal?.type === 'near' && (
                <PrnNearLimitDialog
                    med={modal.med}
                    client={clientsMap.get(modal.med.client_id)}
                    onClose={() => setModal(null)}
                    onRecordDose={
                        canRecordMedication(modal.med.is_controlled)
                            ? () =>
                                  setModal({
                                      type: 'record',
                                      initialMedId: modal.med.id,
                                  })
                            : undefined
                    }
                    onRecordEffectiveness={
                        canRecordMedication(modal.med.is_controlled)
                            ? (followUp) =>
                                  setModal({ type: 'effect', followUp })
                            : undefined
                    }
                />
            )}
            {ctx && <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} />}
        </AppLayout>
    );
}
