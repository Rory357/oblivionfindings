/* eslint-disable no-restricted-syntax -- report panels, tables, period/preset chips and the
   audit-pack cards are custom-layout bordered surfaces / chip buttons (not Card/Button); charts
   reuse OpsStatCard/DonutChart/recharts. All colours are semantic tokens. */
import { type CdMedication } from '@/components/emar/controlled/types';
import { MedsWizardDialog, SummaryRow } from '@/components/meds/wizard-shell';
import {
    DonutChart,
    OPS_COLORS,
    OpsStatCard,
} from '@/components/ops-stat-card';
import { PageHero, type PageHeroStat } from '@/components/page';
import {
    EntityFilter,
    ShiftContextMenu,
    TabStrip,
    type RosterTabItem,
    type ShiftCtxItem,
    type ShiftCtxState,
} from '@/components/rostering';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { ReportLossDialog } from '@/pages/emar/_cd-dialogs';
import { Head, router } from '@inertiajs/react';
import {
    Activity,
    AlertOctagon,
    AlertTriangle,
    Award,
    ClipboardCheck,
    Download,
    Eye,
    FileBarChart,
    FileText,
    Lock,
    Package,
    Pill,
    Printer,
    Search,
    Shield,
    User,
    X,
    XCircle,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    Area,
    AreaChart,
    Bar,
    BarChart,
    CartesianGrid,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

type ClientOption = { id: number; name: string };
type AdminSummary = {
    total: number;
    given: number;
    refused: number;
    withheld: number;
    missed: number;
    compliance_rate: number;
};
type DailyAdmin = {
    date: string;
    given: number;
    refused: number;
    missed: number;
    total: number;
};
type ClientBreakdownRow = {
    client_id: number;
    client_name: string;
    total: number;
    given: number;
    refused: number;
    withheld: number;
    missed: number;
    compliance: number;
};
type ReasonBreakdown = {
    codes: { code: string; class: string; count: number }[];
    by_class: { refusal: number; clinical: number; omission: number };
};
type ControlledDrugs = {
    administrations: number;
    destructions: number;
    discrepancies: number;
    byMedication: { medication: string; administrations: number }[];
};
type StaffComplianceData = {
    current: number;
    expiring: number;
    expired: number;
    list: {
        staff_name: string;
        assessment_date: string | null;
        expiry_date: string | null;
        status: string;
        days_until_expiry: number | null;
    }[];
};
type StockStatusData = {
    total: number;
    low: number;
    expiring: number;
    expired: number;
    active_medications: number;
    list: {
        medication: string;
        client: string;
        on_hand: number;
        reorder_level: number;
        expiry_date: string | null;
        status: string;
    }[];
};
type RoundCompletion = {
    summary: {
        total: number;
        completed: number;
        on_time_pct: number;
        late_pct: number;
        missed_pct: number;
    };
    daily: { date: string; total: number; completed: number; missed: number }[];
};
type ErrorSummaryData = {
    total: number;
    critical: number;
    open: number;
    resolved: number;
    byType: { type: string; count: number }[];
    list: {
        date: string;
        client: string;
        type: string;
        severity: string;
        status: string;
    }[];
};

type Props = {
    filters: {
        date_from: string;
        date_to: string;
        client_id: number | null;
        site_id: number | null;
        care_level?: string | null;
        report_type: string | null;
    };
    clients: ClientOption[];
    careLevels: string[];
    cdMedications: CdMedication[];
    reasonBreakdown: ReasonBreakdown;
    adminSummary: AdminSummary;
    dailyAdmin: DailyAdmin[];
    clientBreakdown: ClientBreakdownRow[];
    topPrnMeds: { medication: string; count: number }[];
    prnByClient: {
        client_name: string;
        medication: string;
        count: number;
        avg_per_day: number;
    }[];
    controlledDrugs: ControlledDrugs;
    staffCompliance: StaffComplianceData;
    stockStatus: StockStatusData;
    roundCompletion: RoundCompletion;
    errorSummary: ErrorSummaryData;
    sites: { id: number; name: string }[];
    active_site: { id: number; name: string } | null;
    site_brand_colour: string | null;
    can_view_controlled: boolean;
    can_record_controlled: boolean;
};

type Modal =
    | { type: 'cdloss' }
    | { type: 'drill'; row: ClientBreakdownRow }
    | null;

const PRESETS = [
    { id: '7', l: '7 days' },
    { id: '30', l: '30 days' },
    { id: '90', l: '90 days' },
    { id: 'month', l: 'This month' },
    { id: 'custom', l: 'Custom' },
];
const REASON_CLASS_CLS: Record<string, string> = {
    refusal: 'bg-status-warning',
    clinical: 'bg-primary',
    omission: 'bg-status-critical',
};
const fmtDate = (iso: string | null) =>
    iso
        ? new Date(iso).toLocaleDateString('en-NZ', {
              day: 'numeric',
              month: 'short',
          })
        : '—';

export default function Reports(props: Props) {
    const {
        filters,
        clients,
        careLevels,
        cdMedications,
        reasonBreakdown,
        adminSummary,
        dailyAdmin,
        clientBreakdown,
        topPrnMeds,
        prnByClient,
        controlledDrugs,
        staffCompliance,
        stockStatus,
        roundCompletion,
        errorSummary,
        sites,
        active_site: activeSite,
        site_brand_colour: brandColour,
        can_view_controlled: canViewControlled,
        can_record_controlled: canRecordControlled,
    } = props;
    const [tab, setTab] = useState('administration');
    const [modal, setModal] = useState<Modal>(null);
    const [search, setSearch] = useState('');
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);

    const periodLabel = `${fmtDate(filters.date_from)} – ${fmtDate(filters.date_to)}`;
    const activePreset = useMemo(() => {
        const days = Math.round(
            (new Date(filters.date_to).getTime() -
                new Date(filters.date_from).getTime()) /
                86400000,
        );
        return [7, 30, 90].includes(days) ? String(days) : 'custom';
    }, [filters.date_from, filters.date_to]);

    const reload = (patch: Record<string, string | number | null>) => {
        const params: Record<string, string> = {};
        const merged = {
            date_from: filters.date_from,
            date_to: filters.date_to,
            client_id: filters.client_id,
            site_id: filters.site_id,
            care_level: filters.care_level ?? null,
            ...patch,
        };
        Object.entries(merged).forEach(([k, v]) => {
            if (v !== null && v !== undefined && v !== '')
                params[k] = String(v);
        });
        router.get('/emar/reports', params, {
            preserveState: true,
            preserveScroll: true,
        });
    };
    const setPreset = (id: string) => {
        if (id === 'custom') return;
        const to = new Date();
        const from = new Date();
        if (id === 'month') from.setDate(1);
        else from.setDate(to.getDate() - Number(id));
        reload({
            date_from: from.toISOString().slice(0, 10),
            date_to: to.toISOString().slice(0, 10),
        });
    };
    const exportUrl = (type: string) => {
        const p = new URLSearchParams({
            report_type: type,
            date_from: filters.date_from,
            date_to: filters.date_to,
        });
        if (filters.client_id) p.set('client_id', String(filters.client_id));
        if (filters.site_id) p.set('site_id', String(filters.site_id));
        return `/emar/reports/export?${p.toString()}`;
    };
    // Per-client administration CSV (right-click "Export this client's CSV") — scoped to one row,
    // independent of the active client filter, but still honours the period + site window.
    const clientExportUrl = (clientId: number) => {
        const p = new URLSearchParams({
            report_type: 'administration',
            date_from: filters.date_from,
            date_to: filters.date_to,
            client_id: String(clientId),
        });
        if (filters.site_id) p.set('site_id', String(filters.site_id));
        return `/emar/reports/export?${p.toString()}`;
    };

    // Care level is a string enum; EntityFilter is id-keyed, so map string↔index.
    const careLevelOptions = useMemo(
        () => careLevels.map((c, i) => ({ id: i, name: c.replace(/_/g, ' ') })),
        [careLevels],
    );
    const careLevelValue = useMemo(() => {
        if (!filters.care_level) return null;
        const i = careLevels.indexOf(filters.care_level);
        return i >= 0 ? i : null;
    }, [careLevels, filters.care_level]);
    const onCareLevel = (id: number | null) =>
        reload({ care_level: id === null ? null : careLevels[id] });
    const hasActiveFilter = Boolean(
        filters.client_id || filters.care_level || filters.site_id,
    );

    // Read-only right-click menu for the Administration client-breakdown rows. Analytics table —
    // no mutations: drill, jump to client, open MAR chart, export this client's CSV.
    const openRowCtx = (e: React.MouseEvent, r: ClientBreakdownRow) => {
        e.preventDefault();
        const band =
            r.compliance >= 95
                ? 'success'
                : r.compliance >= 85
                  ? 'warning'
                  : 'critical';
        const items: ShiftCtxItem[] = [
            {
                icon: <Eye className="h-3.5 w-3.5" />,
                label: 'View breakdown',
                sub: 'Read-only drill-in',
                tone: 'primary',
                onClick: () => setModal({ type: 'drill', row: r }),
            },
            {
                icon: <User className="h-3.5 w-3.5" />,
                label: 'View client',
                onClick: () =>
                    router.visit(`/operations/clients/${r.client_id}?tab=mar`),
            },
            {
                icon: <FileText className="h-3.5 w-3.5" />,
                label: 'Open on MAR chart',
                onClick: () =>
                    router.visit(`/emar/mar?client_id=${r.client_id}`),
            },
            { sep: true },
            {
                icon: <Download className="h-3.5 w-3.5" />,
                label: "Export this client's CSV",
                onClick: () => {
                    window.location.href = clientExportUrl(r.client_id);
                },
            },
        ];
        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag: `${r.compliance}%`,
            tagBg: `var(--status-${band}-bg)`,
            tagColor: `var(--status-${band})`,
            meta: `${r.client_name} · ${r.total} dose${r.total === 1 ? '' : 's'}`,
            items,
        });
    };

    const TABS: RosterTabItem[] = [
        {
            id: 'administration',
            label: 'Administration',
            icon: Activity,
            tone: 'primary',
        },
        {
            id: 'reasons',
            label: 'Reason not given',
            icon: AlertTriangle,
            tone: 'warning',
            badge:
                reasonBreakdown.by_class.refusal +
                    reasonBreakdown.by_class.clinical +
                    reasonBreakdown.by_class.omission || undefined,
        },
        { id: 'prn', label: 'PRN usage', icon: Pill, tone: 'primary' },
        ...(canViewControlled
            ? [
                  {
                      id: 'controlled',
                      label: 'Controlled drugs',
                      icon: Lock,
                      tone: 'primary' as const,
                      badge: controlledDrugs.discrepancies || undefined,
                  },
              ]
            : []),
        {
            id: 'staff',
            label: 'Staff compliance',
            icon: Award,
            tone: 'primary',
            badge: staffCompliance.expired || undefined,
        },
        {
            id: 'stock',
            label: 'Stock',
            icon: Package,
            tone: 'warning',
            badge: stockStatus.low || undefined,
        },
        {
            id: 'rounds',
            label: 'Rounds',
            icon: ClipboardCheck,
            tone: 'primary',
        },
        {
            id: 'errors',
            label: 'Errors',
            icon: AlertOctagon,
            tone: 'critical',
            badge: errorSummary.open || undefined,
        },
        { id: 'audit', label: 'Audit tools', icon: Shield, tone: 'success' },
    ];

    const heroStats: PageHeroStat[] = [
        { label: 'Compliance', value: `${adminSummary.compliance_rate}%` },
        { label: 'Doses recorded', value: adminSummary.given },
        {
            label: 'Open errors',
            value: errorSummary.open,
            tone: errorSummary.open > 0 ? 'warning' : 'neutral',
        },
        ...(canViewControlled
            ? [
                  {
                      label: 'CD variances',
                      value: controlledDrugs.discrepancies,
                      tone:
                          controlledDrugs.discrepancies > 0
                              ? ('critical' as const)
                              : ('neutral' as const),
                  },
              ]
            : []),
    ];

    const errorRate =
        adminSummary.total > 0
            ? ((errorSummary.total / adminSummary.total) * 1000).toFixed(1)
            : '0';

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'eMAR', href: '/emar' },
                { title: 'Reports', href: '/emar/reports' },
            ]}
        >
            <Head title="eMAR - Reports" />
            <div className="flex flex-col gap-6 p-6">
                <PageHero
                    variant="hero"
                    category="ops"
                    brandColour={brandColour}
                    icon={FileBarChart}
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
                                Live reporting · refreshed
                            </span>
                            <span className="mt-1 block text-[26px] leading-tight font-bold">
                                Medication reporting for{' '}
                                {activeSite?.name ?? 'your services'} —{' '}
                                <span className="border-b-2 border-primary-foreground/40">
                                    {periodLabel}
                                </span>
                            </span>
                        </span>
                    }
                    description={`${adminSummary.total} doses recorded · ${adminSummary.compliance_rate}% compliance · ${adminSummary.missed} missed / ${adminSummary.refused} refused · ${canViewControlled ? `${controlledDrugs.discrepancies} CD variance${controlledDrugs.discrepancies === 1 ? '' : 's'} and ` : ''}${errorSummary.open} open error${errorSummary.open === 1 ? '' : 's'}.`}
                    stats={heroStats}
                    actions={
                        <>
                            <a
                                href={exportUrl(
                                    canViewControlled && tab === 'controlled'
                                        ? 'controlled'
                                        : tab === 'prn'
                                          ? 'prn'
                                          : tab === 'errors'
                                            ? 'errors'
                                            : tab === 'rounds'
                                              ? 'rounds'
                                              : 'administration',
                                )}
                            >
                                <Button className="bg-primary-foreground text-primary hover:bg-primary-foreground/90">
                                    <Download className="h-4 w-4" />
                                    Export
                                </Button>
                            </a>
                            <a href="/emar/pdf/mar-chart">
                                <Button
                                    variant="outline"
                                    className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20"
                                >
                                    <Printer className="h-4 w-4" />
                                    Print MAR &amp; CD register
                                </Button>
                            </a>
                        </>
                    }
                    footer={
                        <div className="flex flex-col gap-3 py-3 lg:flex-row lg:items-center lg:justify-between">
                            <div className="flex flex-wrap items-center gap-1.5">
                                {PRESETS.map((p) => (
                                    <button
                                        key={p.id}
                                        onClick={() => setPreset(p.id)}
                                        className={`rounded-full px-3 py-1 text-xs font-medium transition ${activePreset === p.id ? 'bg-primary-foreground text-primary' : 'border border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20'}`}
                                    >
                                        {p.l}
                                    </button>
                                ))}
                                {activePreset === 'custom' && (
                                    <span className="flex items-center gap-1.5 rounded-full bg-primary-foreground/10 px-2 py-1">
                                        <input
                                            type="date"
                                            value={filters.date_from}
                                            max={filters.date_to}
                                            onChange={(e) =>
                                                reload({
                                                    date_from: e.target.value,
                                                })
                                            }
                                            className="rounded bg-primary-foreground px-1.5 py-0.5 text-xs text-foreground"
                                        />
                                        <span className="text-xs text-primary-foreground">
                                            →
                                        </span>
                                        <input
                                            type="date"
                                            value={filters.date_to}
                                            min={filters.date_from}
                                            onChange={(e) =>
                                                reload({
                                                    date_to: e.target.value,
                                                })
                                            }
                                            className="rounded bg-primary-foreground px-1.5 py-0.5 text-xs text-foreground"
                                        />
                                    </span>
                                )}
                            </div>
                            <div className="flex flex-wrap items-center gap-2 lg:justify-end">
                                <div className="relative w-full max-w-xs lg:w-[240px]">
                                    <Search className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                    <input
                                        value={search}
                                        onChange={(e) =>
                                            setSearch(e.target.value)
                                        }
                                        placeholder="Search a table…"
                                        aria-label="Search reports"
                                        className="h-8 w-full rounded-full border-0 bg-primary-foreground pr-8 pl-9 text-[13px] text-foreground shadow-sm outline-none placeholder:text-muted-foreground/80 focus:ring-2 focus:ring-primary-foreground/50"
                                    />
                                    {search ? (
                                        <button
                                            type="button"
                                            aria-label="Clear search"
                                            onClick={() => setSearch('')}
                                            className="absolute top-1/2 right-2 grid h-5 w-5 -translate-y-1/2 place-items-center rounded-full text-muted-foreground hover:bg-muted"
                                        >
                                            <X className="h-3.5 w-3.5" />
                                        </button>
                                    ) : null}
                                </div>
                                {sites.length > 0 && (
                                    <EntityFilter
                                        label="Site"
                                        allLabel="All sites"
                                        items={sites}
                                        value={filters.site_id}
                                        onChange={(id) =>
                                            reload({ site_id: id })
                                        }
                                        onDark
                                    />
                                )}
                                <EntityFilter
                                    label="Client"
                                    allLabel="All clients"
                                    items={clients}
                                    value={filters.client_id}
                                    onChange={(id) => reload({ client_id: id })}
                                    onDark
                                />
                                {careLevels.length > 0 && (
                                    <EntityFilter
                                        label="Care level"
                                        allLabel="All care levels"
                                        items={careLevelOptions}
                                        value={careLevelValue}
                                        onChange={onCareLevel}
                                        onDark
                                        pluralLabel="care levels"
                                    />
                                )}
                                {hasActiveFilter && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            reload({
                                                client_id: null,
                                                care_level: null,
                                                site_id: null,
                                            })
                                        }
                                        className="h-8 rounded-full border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20"
                                    >
                                        <X className="h-3.5 w-3.5" />
                                        Clear filters
                                    </Button>
                                )}
                            </div>
                        </div>
                    }
                />

                <TabStrip
                    value={tab}
                    onChange={setTab}
                    items={TABS}
                    ariaLabel="Report views"
                />

                {tab === 'administration' && (
                    <Panel
                        title="Administration summary"
                        subtitle="Doses recorded across the period"
                        exportHref={exportUrl('administration')}
                    >
                        <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
                            <OpsStatCard
                                label="Given"
                                value={adminSummary.given}
                                icon={Activity}
                                color="emerald"
                            />
                            <OpsStatCard
                                label="Refused"
                                value={adminSummary.refused}
                                icon={XCircle}
                                color="amber"
                            />
                            <OpsStatCard
                                label="Withheld"
                                value={adminSummary.withheld}
                                icon={Shield}
                                color="amber"
                            />
                            <OpsStatCard
                                label="Missed"
                                value={adminSummary.missed}
                                icon={AlertTriangle}
                                color="red"
                            />
                            <OpsStatCard
                                label="Compliance"
                                value={`${adminSummary.compliance_rate}%`}
                                icon={Award}
                                color="indigo"
                            />
                        </div>
                        <ChartCard title="Daily administration">
                            <ResponsiveContainer width="100%" height={260}>
                                <AreaChart
                                    data={dailyAdmin}
                                    margin={{
                                        top: 5,
                                        right: 10,
                                        left: -18,
                                        bottom: 0,
                                    }}
                                >
                                    <CartesianGrid
                                        strokeDasharray="3 3"
                                        stroke="var(--border)"
                                    />
                                    <XAxis
                                        dataKey="date"
                                        tick={{ fontSize: 11 }}
                                        stroke="var(--muted-foreground)"
                                    />
                                    <YAxis
                                        tick={{ fontSize: 11 }}
                                        stroke="var(--muted-foreground)"
                                    />
                                    <Tooltip />
                                    <Area
                                        type="monotone"
                                        dataKey="given"
                                        stroke={OPS_COLORS.success}
                                        fill={OPS_COLORS.success}
                                        fillOpacity={0.15}
                                        strokeWidth={2}
                                    />
                                    <Area
                                        type="monotone"
                                        dataKey="refused"
                                        stroke={OPS_COLORS.warning}
                                        fill="none"
                                        strokeWidth={2}
                                    />
                                    <Area
                                        type="monotone"
                                        dataKey="missed"
                                        stroke={OPS_COLORS.danger}
                                        fill="none"
                                        strokeWidth={2}
                                    />
                                </AreaChart>
                            </ResponsiveContainer>
                        </ChartCard>
                        <SimpleTable
                            head={[
                                'Resident',
                                'Total',
                                'Given',
                                'Refused',
                                'Missed',
                                'Compliance',
                                '',
                            ]}
                            empty={
                                clientBreakdown.length === 0
                                    ? 'No administrations in this period.'
                                    : null
                            }
                        >
                            {clientBreakdown.map((r) => (
                                <tr
                                    key={r.client_id}
                                    className="cursor-pointer border-b last:border-b-0 hover:bg-muted/30"
                                    onClick={() =>
                                        setModal({ type: 'drill', row: r })
                                    }
                                    onContextMenu={(e) => openRowCtx(e, r)}
                                >
                                    <td className="px-4 py-2.5 font-medium">
                                        {r.client_name}
                                    </td>
                                    <td className="px-4 py-2.5 tabular-nums">
                                        {r.total}
                                    </td>
                                    <td className="px-4 py-2.5 text-status-success tabular-nums">
                                        {r.given}
                                    </td>
                                    <td className="px-4 py-2.5 text-status-warning tabular-nums">
                                        {r.refused}
                                    </td>
                                    <td className="px-4 py-2.5 text-status-critical tabular-nums">
                                        {r.missed}
                                    </td>
                                    <td className="px-4 py-2.5 tabular-nums">
                                        {r.compliance}%
                                    </td>
                                    <td className="px-4 py-2.5 text-right text-xs text-primary">
                                        View ›
                                    </td>
                                </tr>
                            ))}
                        </SimpleTable>
                    </Panel>
                )}

                {tab === 'reasons' && (
                    <Panel
                        title="Reason not given"
                        subtitle="Coded reasons for refused, withheld and missed doses (Ngā Paerewa)"
                    >
                        <div className="grid gap-4 lg:grid-cols-[1fr_240px]">
                            <div className="rounded-2xl border bg-card p-4 shadow-sm">
                                {reasonBreakdown.codes.length === 0 ? (
                                    <div className="py-8 text-center text-sm text-muted-foreground">
                                        No refusals, withholds or omissions in
                                        this period.
                                    </div>
                                ) : (
                                    <div className="flex flex-col gap-2">
                                        {reasonBreakdown.codes.map((c, i) => (
                                            <div
                                                key={i}
                                                className="flex items-center gap-3"
                                            >
                                                <span
                                                    className={`flex h-6 w-6 items-center justify-center rounded text-[10px] font-bold text-white ${REASON_CLASS_CLS[c.class]}`}
                                                >
                                                    {c.code.slice(0, 2)}
                                                </span>
                                                <span className="flex-1 text-sm capitalize">
                                                    {c.code}{' '}
                                                    <span className="text-xs text-muted-foreground">
                                                        · {c.class}
                                                    </span>
                                                </span>
                                                <span className="text-sm tabular-nums">
                                                    {c.count}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                            <div className="flex flex-col items-center justify-center rounded-2xl border bg-card p-4 shadow-sm">
                                <DonutChart
                                    segments={[
                                        {
                                            label: 'Refusals',
                                            value: reasonBreakdown.by_class
                                                .refusal,
                                            color: OPS_COLORS.warning,
                                        },
                                        {
                                            label: 'Clinical',
                                            value: reasonBreakdown.by_class
                                                .clinical,
                                            color: OPS_COLORS.primary,
                                        },
                                        {
                                            label: 'Omissions',
                                            value: reasonBreakdown.by_class
                                                .omission,
                                            color: OPS_COLORS.danger,
                                        },
                                    ]}
                                    centerLabel="Not given"
                                    centerValue={
                                        reasonBreakdown.by_class.refusal +
                                        reasonBreakdown.by_class.clinical +
                                        reasonBreakdown.by_class.omission
                                    }
                                />
                            </div>
                        </div>
                    </Panel>
                )}

                {tab === 'prn' && (
                    <Panel
                        title="PRN usage"
                        subtitle="As-needed medication usage"
                        exportHref={exportUrl('prn')}
                    >
                        <BarList
                            rows={topPrnMeds.map((m) => ({
                                label: m.medication,
                                value: m.count,
                            }))}
                            empty="No PRN administrations."
                        />
                        <SimpleTable
                            head={[
                                'Resident',
                                'Medication',
                                'Count',
                                'Avg / day',
                            ]}
                            empty={
                                prnByClient.length === 0
                                    ? 'No PRN by client.'
                                    : null
                            }
                        >
                            {prnByClient.map((r, i) => (
                                <tr
                                    key={i}
                                    className="border-b last:border-b-0"
                                >
                                    <td className="px-4 py-2.5">
                                        {r.client_name}
                                    </td>
                                    <td className="px-4 py-2.5">
                                        {r.medication}
                                    </td>
                                    <td className="px-4 py-2.5 tabular-nums">
                                        {r.count}
                                    </td>
                                    <td className="px-4 py-2.5 tabular-nums">
                                        {r.avg_per_day}
                                    </td>
                                </tr>
                            ))}
                        </SimpleTable>
                    </Panel>
                )}

                {canViewControlled && tab === 'controlled' && (
                    <Panel
                        title="Controlled drugs"
                        subtitle="CD administrations, destructions and variances"
                        exportHref={exportUrl('controlled')}
                        action={
                            canRecordControlled ? (
                                <Button
                                    size="sm"
                                    variant="destructive"
                                    onClick={() => setModal({ type: 'cdloss' })}
                                >
                                    Report CD loss
                                </Button>
                            ) : undefined
                        }
                    >
                        <div className="grid gap-3 sm:grid-cols-3">
                            <OpsStatCard
                                label="Administrations"
                                value={controlledDrugs.administrations}
                                icon={Lock}
                                color="indigo"
                            />
                            <OpsStatCard
                                label="Destructions"
                                value={controlledDrugs.destructions}
                                icon={Package}
                                color="amber"
                            />
                            <OpsStatCard
                                label="Variances"
                                value={controlledDrugs.discrepancies}
                                icon={AlertTriangle}
                                color={
                                    controlledDrugs.discrepancies > 0
                                        ? 'red'
                                        : 'slate'
                                }
                            />
                        </div>
                        <BarList
                            rows={controlledDrugs.byMedication.map((m) => ({
                                label: m.medication,
                                value: m.administrations,
                            }))}
                            empty="No controlled-drug administrations."
                        />
                    </Panel>
                )}

                {tab === 'staff' && (
                    <Panel
                        title="Staff compliance"
                        subtitle="Medication competency status"
                    >
                        <div className="grid gap-4 lg:grid-cols-[240px_1fr]">
                            <div className="flex flex-col items-center justify-center rounded-2xl border bg-card p-4 shadow-sm">
                                <DonutChart
                                    segments={[
                                        {
                                            label: 'Current',
                                            value: staffCompliance.current,
                                            color: OPS_COLORS.success,
                                        },
                                        {
                                            label: 'Expiring',
                                            value: staffCompliance.expiring,
                                            color: OPS_COLORS.warning,
                                        },
                                        {
                                            label: 'Expired',
                                            value: staffCompliance.expired,
                                            color: OPS_COLORS.danger,
                                        },
                                    ]}
                                    centerLabel="Assessed"
                                    centerValue={
                                        staffCompliance.current +
                                        staffCompliance.expiring +
                                        staffCompliance.expired
                                    }
                                />
                            </div>
                            <SimpleTable
                                head={[
                                    'Staff',
                                    'Assessed',
                                    'Expiry',
                                    'Status',
                                    'Days left',
                                ]}
                                empty={
                                    staffCompliance.list.length === 0
                                        ? 'No assessments.'
                                        : null
                                }
                            >
                                {staffCompliance.list.map((s, i) => (
                                    <tr
                                        key={i}
                                        className="border-b last:border-b-0"
                                    >
                                        <td className="px-4 py-2.5 font-medium">
                                            {s.staff_name}
                                        </td>
                                        <td className="px-4 py-2.5">
                                            {fmtDate(s.assessment_date)}
                                        </td>
                                        <td className="px-4 py-2.5">
                                            {fmtDate(s.expiry_date)}
                                        </td>
                                        <td className="px-4 py-2.5">
                                            <StatusPill s={s.status} />
                                        </td>
                                        <td
                                            className={`px-4 py-2.5 tabular-nums ${(s.days_until_expiry ?? 0) < 0 ? 'text-status-critical' : ''}`}
                                        >
                                            {s.days_until_expiry ?? '—'}
                                        </td>
                                    </tr>
                                ))}
                            </SimpleTable>
                        </div>
                    </Panel>
                )}

                {tab === 'stock' && (
                    <Panel
                        title="Stock status"
                        subtitle="Point-in-time inventory"
                    >
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <OpsStatCard
                                label="Tracked"
                                value={stockStatus.total}
                                icon={Package}
                                color="indigo"
                            />
                            <OpsStatCard
                                label="Low"
                                value={stockStatus.low}
                                icon={AlertTriangle}
                                color="amber"
                            />
                            <OpsStatCard
                                label="Expiring 30d"
                                value={stockStatus.expiring}
                                icon={Shield}
                                color="amber"
                            />
                            <OpsStatCard
                                label="Expired"
                                value={stockStatus.expired}
                                icon={XCircle}
                                color="red"
                            />
                        </div>
                        <SimpleTable
                            head={[
                                'Medication',
                                'Resident',
                                'On hand',
                                'Reorder',
                                'Expiry',
                                'Status',
                            ]}
                            empty={
                                stockStatus.list.length === 0
                                    ? 'No stock records.'
                                    : null
                            }
                        >
                            {stockStatus.list.map((s, i) => (
                                <tr
                                    key={i}
                                    className="border-b last:border-b-0"
                                >
                                    <td className="px-4 py-2.5 font-medium">
                                        {s.medication}
                                    </td>
                                    <td className="px-4 py-2.5">{s.client}</td>
                                    <td className="px-4 py-2.5 tabular-nums">
                                        {s.on_hand}
                                    </td>
                                    <td className="px-4 py-2.5 tabular-nums">
                                        {s.reorder_level}
                                    </td>
                                    <td className="px-4 py-2.5">
                                        {fmtDate(s.expiry_date)}
                                    </td>
                                    <td className="px-4 py-2.5">
                                        <StatusPill s={s.status} />
                                    </td>
                                </tr>
                            ))}
                        </SimpleTable>
                    </Panel>
                )}

                {tab === 'rounds' && (
                    <Panel
                        title="Round completion"
                        subtitle="Medication rounds across the period"
                        exportHref={exportUrl('rounds')}
                    >
                        <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
                            <OpsStatCard
                                label="Total"
                                value={roundCompletion.summary.total}
                                icon={ClipboardCheck}
                                color="indigo"
                            />
                            <OpsStatCard
                                label="Completed"
                                value={roundCompletion.summary.completed}
                                icon={Activity}
                                color="emerald"
                            />
                            <OpsStatCard
                                label="On time"
                                value={`${roundCompletion.summary.on_time_pct}%`}
                                icon={Award}
                                color="emerald"
                            />
                            <OpsStatCard
                                label="Late"
                                value={`${roundCompletion.summary.late_pct}%`}
                                icon={AlertTriangle}
                                color="amber"
                            />
                            <OpsStatCard
                                label="Missed"
                                value={`${roundCompletion.summary.missed_pct}%`}
                                icon={XCircle}
                                color="red"
                            />
                        </div>
                        <ChartCard title="Daily completion">
                            <ResponsiveContainer width="100%" height={260}>
                                <BarChart
                                    data={roundCompletion.daily}
                                    margin={{
                                        top: 5,
                                        right: 10,
                                        left: -18,
                                        bottom: 0,
                                    }}
                                >
                                    <CartesianGrid
                                        strokeDasharray="3 3"
                                        stroke="var(--border)"
                                    />
                                    <XAxis
                                        dataKey="date"
                                        tick={{ fontSize: 11 }}
                                        stroke="var(--muted-foreground)"
                                    />
                                    <YAxis
                                        tick={{ fontSize: 11 }}
                                        stroke="var(--muted-foreground)"
                                    />
                                    <Tooltip />
                                    <Bar
                                        dataKey="completed"
                                        fill={OPS_COLORS.success}
                                        radius={[3, 3, 0, 0]}
                                    />
                                    <Bar
                                        dataKey="missed"
                                        fill={OPS_COLORS.danger}
                                        radius={[3, 3, 0, 0]}
                                    />
                                </BarChart>
                            </ResponsiveContainer>
                        </ChartCard>
                    </Panel>
                )}

                {tab === 'errors' && (
                    <Panel
                        title="Medication errors"
                        subtitle="Reported errors and near misses"
                        exportHref={exportUrl('errors')}
                    >
                        <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
                            <OpsStatCard
                                label="Total"
                                value={errorSummary.total}
                                icon={AlertOctagon}
                                color="indigo"
                            />
                            <OpsStatCard
                                label="Critical"
                                value={errorSummary.critical}
                                icon={AlertTriangle}
                                color="red"
                            />
                            <OpsStatCard
                                label="Open"
                                value={errorSummary.open}
                                icon={XCircle}
                                color="amber"
                            />
                            <OpsStatCard
                                label="Resolved"
                                value={errorSummary.resolved}
                                icon={Award}
                                color="emerald"
                            />
                            <OpsStatCard
                                label="Per 1,000 doses"
                                value={errorRate}
                                icon={Activity}
                                color="indigo"
                            />
                        </div>
                        <div className="grid gap-4 lg:grid-cols-[240px_1fr]">
                            <div className="flex flex-col items-center justify-center rounded-2xl border bg-card p-4 shadow-sm">
                                <DonutChart
                                    segments={errorSummary.byType.map(
                                        (t, i) => ({
                                            label: t.type,
                                            value: t.count,
                                            color: [
                                                OPS_COLORS.primary,
                                                OPS_COLORS.warning,
                                                OPS_COLORS.danger,
                                                OPS_COLORS.success,
                                                OPS_COLORS.accent,
                                            ][i % 5],
                                        }),
                                    )}
                                    centerLabel="Errors"
                                    centerValue={errorSummary.total}
                                />
                            </div>
                            <SimpleTable
                                head={[
                                    'Date',
                                    'Resident',
                                    'Type',
                                    'Severity',
                                    'Status',
                                ]}
                                empty={
                                    errorSummary.list.length === 0
                                        ? 'No errors in this period.'
                                        : null
                                }
                            >
                                {errorSummary.list.map((e, i) => (
                                    <tr
                                        key={i}
                                        className="border-b last:border-b-0"
                                    >
                                        <td className="px-4 py-2.5">
                                            {fmtDate(e.date)}
                                        </td>
                                        <td className="px-4 py-2.5">
                                            {e.client}
                                        </td>
                                        <td className="px-4 py-2.5 capitalize">
                                            {e.type.replace(/_/g, ' ')}
                                        </td>
                                        <td className="px-4 py-2.5">
                                            <StatusPill s={e.severity} />
                                        </td>
                                        <td className="px-4 py-2.5">
                                            <StatusPill s={e.status} />
                                        </td>
                                    </tr>
                                ))}
                            </SimpleTable>
                        </div>
                    </Panel>
                )}

                {tab === 'audit' && (
                    <Panel
                        title="Audit tools"
                        subtitle="Inspection-ready report packs (CSV & PDF)"
                    >
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <PackCard
                                icon={FileText}
                                title="Administration CSV"
                                desc="Every dose with reason code, witness and observations."
                                href={exportUrl('administration')}
                            />
                            {canViewControlled && (
                                <PackCard
                                    icon={Lock}
                                    title="Controlled-drug CSV"
                                    desc="CD administrations with witness and dose."
                                    href={exportUrl('controlled')}
                                />
                            )}
                            <PackCard
                                icon={AlertOctagon}
                                title="Errors CSV"
                                desc="Reported medication errors and near misses."
                                href={exportUrl('errors')}
                            />
                            <PackCard
                                icon={FileBarChart}
                                title="MAR chart PDF"
                                desc="Printable MAR chart for the period."
                                href="/emar/pdf/mar-chart"
                            />
                            {canViewControlled && (
                                <PackCard
                                    icon={Lock}
                                    title="CD register PDF"
                                    desc="Controlled-drug register for inspectors."
                                    href="/emar/pdf/controlled-register"
                                />
                            )}
                            <PackCard
                                icon={ClipboardCheck}
                                title="Round sheet PDF"
                                desc="Round administration sheet."
                                href="/emar/pdf/round-sheet"
                            />
                            <PackCard
                                icon={FileText}
                                title="MAR CSV export"
                                desc="Full MAR export (all residents)."
                                href="/emar/reports/export-mar"
                            />
                            {canViewControlled && (
                                <PackCard
                                    icon={AlertTriangle}
                                    title="CD discrepancies CSV"
                                    desc="Controlled-drug variances to reconcile."
                                    href="/emar/reports/export-controlled-discrepancies"
                                />
                            )}
                        </div>
                    </Panel>
                )}
            </div>

            {canViewControlled &&
                canRecordControlled &&
                modal?.type === 'cdloss' && (
                    <ReportLossDialog
                        medications={cdMedications}
                        onClose={() => setModal(null)}
                    />
                )}
            {modal?.type === 'drill' && (
                <DrillDialog row={modal.row} onClose={() => setModal(null)} />
            )}
            {ctx && <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} />}
        </AppLayout>
    );
}

function Panel({
    title,
    subtitle,
    exportHref,
    action,
    children,
}: {
    title: string;
    subtitle: string;
    exportHref?: string;
    action?: React.ReactNode;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 className="text-lg font-bold">{title}</h2>
                    <p className="text-[13px] text-muted-foreground">
                        {subtitle}
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    {action}
                    {exportHref && (
                        <a href={exportHref}>
                            <Button size="sm" variant="outline">
                                <Download className="h-3.5 w-3.5" />
                                Export CSV
                            </Button>
                        </a>
                    )}
                </div>
            </div>
            {children}
        </div>
    );
}
function ChartCard({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <div className="rounded-2xl border bg-card p-4 shadow-sm">
            <div className="mb-3 text-sm font-semibold">{title}</div>
            {children}
        </div>
    );
}
function SimpleTable({
    head,
    empty,
    children,
}: {
    head: string[];
    empty: string | null;
    children: React.ReactNode;
}) {
    return (
        <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
            {empty ? (
                <div className="px-5 py-10 text-center text-sm text-muted-foreground">
                    {empty}
                </div>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[640px] text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-left text-[11px] tracking-wide text-muted-foreground uppercase">
                                {head.map((h, i) => (
                                    <th key={i} className="px-4 py-2.5">
                                        {h}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>{children}</tbody>
                    </table>
                </div>
            )}
        </div>
    );
}
function BarList({
    rows,
    empty,
}: {
    rows: { label: string; value: number }[];
    empty: string;
}) {
    const max = Math.max(1, ...rows.map((r) => r.value));
    return (
        <div className="rounded-2xl border bg-card p-4 shadow-sm">
            {rows.length === 0 ? (
                <div className="py-6 text-center text-sm text-muted-foreground">
                    {empty}
                </div>
            ) : (
                <div className="flex flex-col gap-2">
                    {rows.map((r, i) => (
                        <div key={i} className="flex items-center gap-3">
                            <span className="w-44 shrink-0 truncate text-sm">
                                {r.label}
                            </span>
                            <div className="h-2.5 flex-1 overflow-hidden rounded-full bg-muted">
                                <div
                                    className="h-full rounded-full bg-primary"
                                    style={{
                                        width: `${(r.value / max) * 100}%`,
                                    }}
                                />
                            </div>
                            <span className="w-8 text-right text-sm text-muted-foreground tabular-nums">
                                {r.value}
                            </span>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
function StatusPill({ s }: { s: string }) {
    const cls = ['expired', 'critical', 'open', 'failed'].includes(s)
        ? 'bg-status-critical-bg text-status-critical'
        : ['expiring', 'low', 'moderate', 'major'].includes(s)
          ? 'bg-status-warning-bg text-status-warning'
          : ['current', 'ok', 'resolved', 'closed'].includes(s)
            ? 'bg-status-success-bg text-status-success'
            : 'bg-muted text-muted-foreground';
    return (
        <span
            className={`rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize ${cls}`}
        >
            {s.replace(/_/g, ' ')}
        </span>
    );
}
function PackCard({
    icon: Icon,
    title,
    desc,
    href,
}: {
    icon: typeof FileText;
    title: string;
    desc: string;
    href: string;
}) {
    return (
        <a
            href={href}
            className="flex flex-col gap-2 rounded-2xl border bg-card p-4 shadow-sm transition hover:border-primary/40"
        >
            <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-accent text-primary">
                <Icon className="h-5 w-5" />
            </span>
            <div className="text-sm font-semibold">{title}</div>
            <div className="text-xs text-muted-foreground">{desc}</div>
            <span className="mt-1 inline-flex items-center gap-1 text-xs font-medium text-primary">
                <Download className="h-3 w-3" />
                Generate
            </span>
        </a>
    );
}
function DrillDialog({
    row,
    onClose,
}: {
    row: ClientBreakdownRow;
    onClose: () => void;
}) {
    return (
        <MedsWizardDialog
            open
            onClose={onClose}
            title={`${row.client_name} · administration`}
            description="Read-only summary for the selected period."
            railIcon={Activity}
            railTitle="Drill-in"
            railSubtitle={row.client_name}
            steps={[
                {
                    key: 'd',
                    label: 'Summary',
                    blurb: 'Read-only',
                    icon: Activity,
                },
            ]}
            stepIndex={0}
            onStepClick={() => {}}
            footer={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Close
                    </Button>
                    <Button
                        variant="outline"
                        onClick={() =>
                            router.visit(
                                `/operations/clients/${row.client_id}?tab=mar`,
                            )
                        }
                    >
                        <User className="h-4 w-4" />
                        View client
                    </Button>
                    <a href={`/emar/mar?client_id=${row.client_id}`}>
                        <Button>Open MAR chart</Button>
                    </a>
                </>
            }
        >
            {/* TODO(G-reasons): enrich with top refusal/withhold reason codes once clientBreakdown carries
                per-client reason data — see docs/REPORTS_GAP_ANALYSIS.md (front-end-only scope today). */}
            <div className="rounded-lg border px-4">
                <SummaryRow label="Total doses" value={row.total} />
                <SummaryRow label="Given" value={row.given} />
                <SummaryRow label="Refused" value={row.refused} />
                <SummaryRow label="Withheld" value={row.withheld} />
                <SummaryRow label="Missed" value={row.missed} />
                <SummaryRow label="Compliance" value={`${row.compliance}%`} />
            </div>
        </MedsWizardDialog>
    );
}
