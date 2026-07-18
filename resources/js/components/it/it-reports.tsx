/* §L Reports tab (agents) — fetches the server-computed analytics from
 * it.reports.data (never full tables) and renders the KPI row, the created-vs-
 * resolved trend, priority/category donuts, SLA/CSAT readouts and the people +
 * provisioning panels. A range picker drives the from/to params; skeletons
 * while loading; a taught empty state for a young tenant. Colours are design
 * tokens only (no raw hex). */
import { Button } from '@/components/ui/button';
import axios from 'axios';
import {
    Activity,
    ArrowRight,
    BarChart3,
    Clock,
    Download,
    Inbox,
    Server,
    Star,
    TriangleAlert,
    Users,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import {
    Area,
    AreaChart,
    CartesianGrid,
    Cell,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { toast } from 'sonner';

interface Named {
    name: string;
    value: number;
}

interface DrillMetric {
    count: number;
    href: string;
}

interface RateMetric {
    resolved: number;
    rate: number | null;
    href: string;
    reopened?: number;
    first_contact?: number;
}

interface ReportData {
    range: { from: string; to: string; days: number };
    kpis: {
        open: number;
        unassigned: number;
        breaching: number;
        breached: number;
        resolved: number;
        avg_first_response_mins: number | null;
        avg_resolution_mins: number | null;
        sla_compliance: number | null;
        sla_met: number;
        sla_measured: number;
        csat_avg: number | null;
        csat_response_rate: number | null;
    };
    trend: { date: string; created: number; resolved: number }[];
    by_priority: Named[];
    by_category: Named[];
    top_requesters: { name: string; count: number }[];
    agent_workload: { name: string; open: number }[];
    provisioning: {
        raised: number;
        fulfilled: number;
        avg_days: number | null;
    };
    backlog_age: Record<string, DrillMetric>;
    reopen_rate: RateMetric;
    first_contact_resolution: RateMetric;
    channels: Record<string, DrillMetric>;
    major_incidents: {
        declared: number;
        restored: number;
        open: number;
        avg_restore_minutes?: number | null;
        href?: string;
    };
    change_success: {
        successful: number;
        failed: number;
        inconclusive: number;
        success_rate?: number | null;
        href?: string;
    };
    recurring_problems: {
        total: number;
        known_errors: number;
        root_causes: number;
        href?: string;
    };
    automation_outcomes: {
        succeeded: number;
        failed: number;
        skipped: number;
        href?: string;
    };
    service_reliability: Array<{
        service_id: number;
        service: string;
        status: string;
        tickets: number;
        open: number;
        sla_breaches: number;
        href: string;
    }>;
    device_reliability: {
        affected_devices: number;
        open_incidents: number;
        recovered: number;
        href?: string;
    };
    quality: Record<string, DrillMetric>;
}

const RANGES = [
    { days: 7, label: '7 days' },
    { days: 30, label: '30 days' },
    { days: 90, label: '90 days' },
];

const PRIORITY_COLOR: Record<string, string> = {
    urgent: 'var(--status-critical)',
    high: 'var(--status-warning)',
    normal: 'var(--status-info)',
    low: 'var(--muted-foreground)',
};
const CATEGORY_PALETTE = [
    'var(--chart-1)',
    'var(--chart-2)',
    'var(--chart-3)',
    'var(--chart-4)',
];

const label = (raw: string) =>
    raw.replace(/[_-]/g, ' ').replace(/^\w/, (c) => c.toUpperCase());

const fmtMins = (n: number | null): string => {
    if (n === null) return '—';
    if (n < 60) return `${n}m`;
    const h = Math.floor(n / 60);
    const m = n % 60;
    if (h < 48) return m ? `${h}h ${m}m` : `${h}h`;
    return `${Math.round(h / 24)}d`;
};
const pct = (n: number | null): string => (n === null ? '—' : `${n}%`);

const shortDate = (iso: string) =>
    new Date(iso).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
    });

const TOOLTIP_STYLE = {
    background: 'var(--card)',
    border: '1px solid var(--border)',
    borderRadius: 10,
    fontSize: 12,
} as const;

export function ItReports() {
    const [days, setDays] = useState(30);
    const [data, setData] = useState<ReportData | null>(null);
    const [loading, setLoading] = useState(true);

    const load = useCallback(() => {
        setLoading(true);
        const to = new Date();
        const from = new Date();
        from.setDate(from.getDate() - (days - 1));
        const fmt = (d: Date) => d.toISOString().slice(0, 10);
        axios
            .get<ReportData>('/it/reports/data', {
                params: { from: fmt(from), to: fmt(to) },
            })
            .then((r) => setData(r.data))
            .catch(() => toast.error('Could not load the reports.'))
            .finally(() => setLoading(false));
    }, [days]);

    useEffect(() => {
        load();
    }, [load]);

    /** CSV download URL for a card, carrying the active window. */
    const exportUrl = (card: string) => {
        const to = new Date();
        const from = new Date();
        from.setDate(from.getDate() - (days - 1));
        const fmt = (d: Date) => d.toISOString().slice(0, 10);
        const params = new URLSearchParams({
            card,
            from: fmt(from),
            to: fmt(to),
        });
        return `/it/reports/export?${params.toString()}`;
    };

    const k = data?.kpis;
    const hasAnything =
        !!data &&
        (data.kpis.open > 0 ||
            data.kpis.resolved > 0 ||
            data.provisioning.raised > 0 ||
            data.major_incidents.declared > 0 ||
            data.automation_outcomes.succeeded > 0 ||
            data.automation_outcomes.failed > 0 ||
            data.service_reliability.some((service) => service.tickets > 0) ||
            data.trend.some((t) => t.created > 0 || t.resolved > 0));

    return (
        <div className="flex flex-col gap-4">
            {/* Range picker */}
            <div className="flex flex-wrap items-center gap-2">
                <BarChart3 className="h-4 w-4 text-muted-foreground" />
                <p className="text-[12.5px] text-muted-foreground">
                    Helpdesk analytics —{' '}
                    {data
                        ? `${shortDate(data.range.from)} → ${shortDate(data.range.to)}`
                        : 'loading…'}
                </p>
                <div className="ml-auto flex items-center gap-2">
                    <div className="inline-flex gap-1 rounded-lg bg-muted p-1">
                        {RANGES.map((r) => (
                            <Button
                                key={r.days}
                                size="sm"
                                variant={days === r.days ? 'default' : 'ghost'}
                                className="h-7"
                                onClick={() => setDays(r.days)}
                                aria-pressed={days === r.days}
                            >
                                {r.label}
                            </Button>
                        ))}
                    </div>
                    {data && hasAnything ? (
                        <Button
                            asChild
                            size="sm"
                            variant="outline"
                            className="h-7"
                        >
                            <a href={exportUrl('summary')}>
                                <Download className="h-3.5 w-3.5" /> Export
                            </a>
                        </Button>
                    ) : null}
                </div>
            </div>

            {loading && !data ? (
                <div className="flex flex-col gap-4" aria-hidden>
                    <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                        {[0, 1, 2, 3, 4, 5, 6, 7].map((i) => (
                            <div
                                key={i}
                                className="h-20 animate-pulse rounded-2xl bg-muted motion-reduce:animate-none"
                            />
                        ))}
                    </div>
                    <div className="h-64 animate-pulse rounded-2xl bg-muted motion-reduce:animate-none" />
                </div>
            ) : !hasAnything ? (
                <div className="flex flex-col items-center rounded-2xl border border-dashed border-border bg-card px-6 py-16 text-center">
                    <BarChart3 className="h-8 w-8 text-muted-foreground" />
                    <h3 className="mt-3 text-[15px] font-bold">
                        No data to report yet
                    </h3>
                    <p className="mt-1 max-w-sm text-[12.5px] text-muted-foreground">
                        Once tickets are raised and resolved, this tab tracks
                        volume, SLA compliance, response times and satisfaction
                        over the window you choose.
                    </p>
                </div>
            ) : (
                data &&
                k && (
                    <>
                        {/* KPI row */}
                        <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                            <Stat
                                icon={Inbox}
                                label="Open"
                                value={String(k.open)}
                                sub={`${k.unassigned} unassigned`}
                            />
                            <Stat
                                icon={TriangleAlert}
                                label="SLA at risk / breached"
                                value={`${k.breaching} / ${k.breached}`}
                                tone={
                                    k.breached > 0
                                        ? 'critical'
                                        : k.breaching > 0
                                          ? 'warning'
                                          : 'neutral'
                                }
                            />
                            <Stat
                                icon={Clock}
                                label="Avg first response"
                                value={fmtMins(k.avg_first_response_mins)}
                            />
                            <Stat
                                icon={Clock}
                                label="Avg resolution"
                                value={fmtMins(k.avg_resolution_mins)}
                            />
                            <Stat
                                icon={BarChart3}
                                label="SLA compliance"
                                value={pct(k.sla_compliance)}
                                sub={
                                    k.sla_measured > 0
                                        ? `${k.sla_met} of ${k.sla_measured} within SLA`
                                        : `${k.resolved} resolved`
                                }
                                tone={
                                    k.sla_compliance === null
                                        ? 'neutral'
                                        : k.sla_compliance >= 90
                                          ? 'success'
                                          : k.sla_compliance >= 70
                                            ? 'warning'
                                            : 'critical'
                                }
                            />
                            <Stat
                                icon={Star}
                                label="CSAT average"
                                value={
                                    k.csat_avg === null
                                        ? '—'
                                        : `${k.csat_avg.toFixed(2)} / 5`
                                }
                                sub={
                                    k.csat_response_rate === null
                                        ? undefined
                                        : `${pct(k.csat_response_rate)} responded`
                                }
                            />
                            <Stat
                                icon={Server}
                                label="Provisioning raised"
                                value={String(data.provisioning.raised)}
                            />
                            <Stat
                                icon={Server}
                                label="Fulfilled"
                                value={String(data.provisioning.fulfilled)}
                                sub={
                                    data.provisioning.avg_days === null
                                        ? undefined
                                        : `avg ${data.provisioning.avg_days}d`
                                }
                            />
                        </div>

                        {/* Trend — created vs resolved */}
                        <Card
                            title="Created vs resolved"
                            exportHref={exportUrl('trend')}
                        >
                            <ResponsiveContainer width="100%" height={240}>
                                <AreaChart
                                    data={data.trend}
                                    margin={{
                                        top: 8,
                                        right: 8,
                                        bottom: 0,
                                        left: -18,
                                    }}
                                >
                                    <CartesianGrid
                                        strokeDasharray="3 3"
                                        stroke="var(--border)"
                                        vertical={false}
                                    />
                                    <XAxis
                                        dataKey="date"
                                        tick={{
                                            fontSize: 10,
                                            fill: 'var(--muted-foreground)',
                                        }}
                                        tickFormatter={shortDate}
                                        interval={Math.max(
                                            0,
                                            Math.floor(data.trend.length / 6),
                                        )}
                                        stroke="var(--border)"
                                    />
                                    <YAxis
                                        allowDecimals={false}
                                        tick={{
                                            fontSize: 10,
                                            fill: 'var(--muted-foreground)',
                                        }}
                                        stroke="var(--border)"
                                    />
                                    <Tooltip
                                        contentStyle={TOOLTIP_STYLE}
                                        labelFormatter={(v) =>
                                            shortDate(String(v))
                                        }
                                    />
                                    <Area
                                        type="monotone"
                                        dataKey="created"
                                        name="Created"
                                        stroke="var(--primary)"
                                        fill="var(--primary)"
                                        fillOpacity={0.15}
                                        strokeWidth={2}
                                    />
                                    <Area
                                        type="monotone"
                                        dataKey="resolved"
                                        name="Resolved"
                                        stroke="var(--status-success)"
                                        fill="var(--status-success)"
                                        fillOpacity={0.15}
                                        strokeWidth={2}
                                    />
                                </AreaChart>
                            </ResponsiveContainer>
                            <Legend
                                items={[
                                    {
                                        name: 'Created',
                                        color: 'var(--primary)',
                                    },
                                    {
                                        name: 'Resolved',
                                        color: 'var(--status-success)',
                                    },
                                ]}
                            />
                        </Card>

                        {/* Donuts + readouts */}
                        <div className="grid gap-3 lg:grid-cols-2">
                            <Card
                                title="Open by priority"
                                exportHref={exportUrl('by_priority')}
                            >
                                <Donut
                                    data={data.by_priority}
                                    colorFor={(n) =>
                                        PRIORITY_COLOR[n] ??
                                        'var(--muted-foreground)'
                                    }
                                />
                            </Card>
                            <Card
                                title="Open by category"
                                exportHref={exportUrl('by_category')}
                            >
                                <Donut
                                    data={data.by_category}
                                    colorFor={(n, i) =>
                                        CATEGORY_PALETTE[
                                            i % CATEGORY_PALETTE.length
                                        ]
                                    }
                                />
                            </Card>
                            <Card
                                title="Top requesters"
                                icon={Users}
                                exportHref={exportUrl('top_requesters')}
                            >
                                <BarList
                                    rows={data.top_requesters.map((r) => ({
                                        name: r.name,
                                        value: r.count,
                                    }))}
                                />
                            </Card>
                            <Card
                                title="Agent workload (open)"
                                icon={Users}
                                exportHref={exportUrl('agent_workload')}
                            >
                                <BarList
                                    rows={data.agent_workload.map((r) => ({
                                        name: r.name,
                                        value: r.open,
                                    }))}
                                />
                            </Card>
                        </div>

                        <ReportSection
                            title="Demand & ageing"
                            blurb="Where work is arriving from and how long it has been waiting. Every row opens the matching queue."
                        >
                            <Card title="Open backlog age" icon={Clock}>
                                <MetricLinks
                                    rows={Object.entries(data.backlog_age).map(
                                        ([name, metric]) => ({
                                            name,
                                            value: metric.count,
                                            href: metric.href,
                                        }),
                                    )}
                                />
                            </Card>
                            <Card title="Contact channels" icon={Inbox}>
                                <MetricLinks
                                    rows={Object.entries(data.channels).map(
                                        ([name, metric]) => ({
                                            name,
                                            value: metric.count,
                                            href: metric.href,
                                        }),
                                    )}
                                />
                            </Card>
                        </ReportSection>

                        <ReportSection
                            title="Quality & outcomes"
                            blurb="Resolution quality, operational outcomes and the automation that keeps the desk moving."
                        >
                            <OutcomeCard
                                title="Resolution quality"
                                rows={[
                                    {
                                        name: 'Reopened tickets',
                                        value: pct(data.reopen_rate.rate),
                                        sub: `${data.reopen_rate.reopened ?? 0} of ${data.reopen_rate.resolved}`,
                                        href: data.reopen_rate.href,
                                    },
                                    {
                                        name: 'First-contact resolution',
                                        value: pct(
                                            data.first_contact_resolution.rate,
                                        ),
                                        sub: `${data.first_contact_resolution.first_contact ?? 0} of ${data.first_contact_resolution.resolved}`,
                                        href: data.first_contact_resolution
                                            .href,
                                    },
                                ]}
                            />
                            <OutcomeCard
                                title="Operational outcomes"
                                rows={[
                                    {
                                        name: 'Major incidents',
                                        value: String(
                                            data.major_incidents.declared,
                                        ),
                                        sub: `${data.major_incidents.open} open · ${data.major_incidents.restored} restored`,
                                        href: data.major_incidents.href,
                                    },
                                    {
                                        name: 'Successful changes',
                                        value: pct(
                                            data.change_success.success_rate ??
                                                null,
                                        ),
                                        sub: `${data.change_success.successful} successful · ${data.change_success.failed} failed`,
                                        href: data.change_success.href,
                                    },
                                    {
                                        name: 'Recurring problems',
                                        value: String(
                                            data.recurring_problems.total,
                                        ),
                                        sub: `${data.recurring_problems.root_causes} root causes · ${data.recurring_problems.known_errors} known errors`,
                                        href: data.recurring_problems.href,
                                    },
                                    {
                                        name: 'Automation runs',
                                        value: String(
                                            data.automation_outcomes.succeeded,
                                        ),
                                        sub: `${data.automation_outcomes.failed} failed · ${data.automation_outcomes.skipped} skipped`,
                                        href: data.automation_outcomes.href,
                                    },
                                ]}
                            />
                        </ReportSection>

                        <ReportSection
                            title="Service & device reliability"
                            blurb="Ticket demand joined to the services and monitored devices that caused it."
                        >
                            <Card title="Services" icon={Activity}>
                                <MetricLinks
                                    empty="No service-linked tickets in this window."
                                    rows={data.service_reliability.map(
                                        (service) => ({
                                            name: service.service,
                                            value: service.tickets,
                                            detail: `${service.open} open · ${service.sla_breaches} SLA breaches · ${label(service.status)}`,
                                            href: service.href,
                                        }),
                                    )}
                                />
                            </Card>
                            <OutcomeCard
                                title="Monitored devices"
                                rows={[
                                    {
                                        name: 'Affected devices',
                                        value: String(
                                            data.device_reliability
                                                .affected_devices,
                                        ),
                                        sub: `${data.device_reliability.open_incidents} open incidents · ${data.device_reliability.recovered} recovered`,
                                        href: data.device_reliability.href,
                                    },
                                ]}
                            />
                        </ReportSection>

                        <ReportSection
                            title="Data quality"
                            blurb="Open work that is missing routing or ownership data. Clear these gaps to keep reports trustworthy."
                        >
                            <Card title="Needs attention" icon={TriangleAlert}>
                                <MetricLinks
                                    rows={Object.entries(data.quality).map(
                                        ([name, metric]) => ({
                                            name,
                                            value: metric.count,
                                            href: metric.href,
                                        }),
                                    )}
                                />
                            </Card>
                        </ReportSection>
                    </>
                )
            )}
        </div>
    );
}

function ReportSection({
    title,
    blurb,
    children,
}: {
    title: string;
    blurb: string;
    children: React.ReactNode;
}) {
    return (
        <section
            className="space-y-2"
            aria-labelledby={`report-${title.toLowerCase().replace(/[^a-z]+/g, '-')}`}
        >
            <div>
                <h3
                    id={`report-${title.toLowerCase().replace(/[^a-z]+/g, '-')}`}
                    className="text-[14px] font-bold"
                >
                    {title}
                </h3>
                <p className="text-[12px] text-muted-foreground">{blurb}</p>
            </div>
            <div className="grid gap-3 lg:grid-cols-2">{children}</div>
        </section>
    );
}

function MetricLinks({
    rows,
    empty = 'Nothing needs attention.',
}: {
    rows: Array<{ name: string; value: number; detail?: string; href: string }>;
    empty?: string;
}) {
    if (!rows.length) {
        return (
            <p className="py-6 text-center text-[12.5px] text-muted-foreground">
                {empty}
            </p>
        );
    }

    return (
        <div className="divide-y divide-border/60">
            {rows.map((row) => (
                <a
                    key={`${row.name}-${row.href}`}
                    href={row.href}
                    className="group flex items-center gap-3 py-2.5 text-[12.5px] first:pt-1 last:pb-1 focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none"
                >
                    <span className="min-w-0 flex-1">
                        <span className="block font-semibold">
                            {label(row.name)}
                        </span>
                        {row.detail ? (
                            <span className="block truncate text-[11.5px] text-muted-foreground">
                                {row.detail}
                            </span>
                        ) : null}
                    </span>
                    <span className="font-bold tabular-nums">{row.value}</span>
                    <ArrowRight className="h-3.5 w-3.5 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
                </a>
            ))}
        </div>
    );
}

function OutcomeCard({
    title,
    rows,
}: {
    title: string;
    rows: Array<{ name: string; value: string; sub: string; href?: string }>;
}) {
    return (
        <Card title={title} icon={Activity}>
            <div className="grid gap-2 sm:grid-cols-2">
                {rows.map((row) => {
                    const content = (
                        <>
                            <span className="text-[11.5px] font-semibold text-muted-foreground">
                                {row.name}
                            </span>
                            <span className="mt-1 block text-[20px] leading-none font-bold">
                                {row.value}
                            </span>
                            <span className="mt-1 block text-[11px] text-muted-foreground">
                                {row.sub}
                            </span>
                        </>
                    );

                    return row.href ? (
                        <a
                            key={row.name}
                            href={row.href}
                            className="rounded-xl bg-muted/45 p-3 transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none"
                        >
                            {content}
                        </a>
                    ) : (
                        <div
                            key={row.name}
                            className="rounded-xl bg-muted/45 p-3"
                        >
                            {content}
                        </div>
                    );
                })}
            </div>
        </Card>
    );
}

function Stat({
    icon: Icon,
    label: l,
    value,
    sub,
    tone = 'neutral',
}: {
    icon: React.ComponentType<{ className?: string }>;
    label: string;
    value: string;
    sub?: string;
    tone?: 'neutral' | 'success' | 'warning' | 'critical';
}) {
    const toneClass =
        tone === 'critical'
            ? 'text-status-critical'
            : tone === 'warning'
              ? 'text-status-warning'
              : tone === 'success'
                ? 'text-status-success'
                : 'text-foreground';
    return (
        <div className="rounded-2xl border border-border bg-card px-4 py-3">
            <div className="flex items-center gap-1.5 text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                <Icon className="h-3.5 w-3.5" />
                <span className="min-w-0 truncate">{l}</span>
            </div>
            <div
                className={`mt-1 text-[22px] leading-none font-bold ${toneClass}`}
            >
                {value}
            </div>
            {sub ? (
                <div className="mt-1 text-[11.5px] text-muted-foreground">
                    {sub}
                </div>
            ) : null}
        </div>
    );
}

function Card({
    title,
    icon: Icon,
    exportHref,
    children,
}: {
    title: string;
    icon?: React.ComponentType<{ className?: string }>;
    exportHref?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="rounded-2xl border border-border bg-card px-4 py-3.5">
            <div className="mb-2 flex items-center gap-1.5 text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                {Icon ? <Icon className="h-3.5 w-3.5" /> : null}
                {title}
                {exportHref ? (
                    <a
                        href={exportHref}
                        title="Download this card as CSV"
                        className="ml-auto inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[10px] font-semibold text-muted-foreground normal-case hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none"
                    >
                        <Download className="h-3 w-3" /> CSV
                    </a>
                ) : null}
            </div>
            {children}
        </div>
    );
}

function Legend({ items }: { items: { name: string; color: string }[] }) {
    return (
        <div className="mt-2 flex flex-wrap items-center gap-3">
            {items.map((it) => (
                <span
                    key={it.name}
                    className="inline-flex items-center gap-1.5 text-[11.5px] text-muted-foreground"
                >
                    <span
                        className="h-2.5 w-2.5 rounded-sm"
                        style={{ background: it.color }}
                    />
                    {it.name}
                </span>
            ))}
        </div>
    );
}

function Donut({
    data,
    colorFor,
}: {
    data: Named[];
    colorFor: (name: string, i: number) => string;
}) {
    const total = data.reduce((s, d) => s + d.value, 0);
    if (total === 0) {
        return (
            <p className="py-10 text-center text-[12.5px] text-muted-foreground">
                No open tickets.
            </p>
        );
    }
    return (
        <div className="flex items-center gap-4">
            <ResponsiveContainer width="50%" height={160}>
                <PieChart>
                    <Pie
                        data={data}
                        dataKey="value"
                        nameKey="name"
                        innerRadius={40}
                        outerRadius={64}
                        paddingAngle={2}
                        strokeWidth={0}
                    >
                        {data.map((d, i) => (
                            <Cell key={d.name} fill={colorFor(d.name, i)} />
                        ))}
                    </Pie>
                    <Tooltip contentStyle={TOOLTIP_STYLE} />
                </PieChart>
            </ResponsiveContainer>
            <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                {data.map((d, i) => (
                    <div
                        key={d.name}
                        className="flex items-center gap-2 text-[12.5px]"
                    >
                        <span
                            className="h-2.5 w-2.5 flex-none rounded-sm"
                            style={{ background: colorFor(d.name, i) }}
                        />
                        <span className="min-w-0 flex-1 truncate">
                            {label(d.name)}
                        </span>
                        <span className="font-semibold">{d.value}</span>
                        <span className="w-10 text-right text-muted-foreground">
                            {total ? Math.round((d.value / total) * 100) : 0}%
                        </span>
                    </div>
                ))}
            </div>
        </div>
    );
}

function BarList({ rows }: { rows: { name: string; value: number }[] }) {
    if (!rows.length) {
        return (
            <p className="py-10 text-center text-[12.5px] text-muted-foreground">
                Nothing to show yet.
            </p>
        );
    }
    const max = Math.max(...rows.map((r) => r.value), 1);
    return (
        <div className="flex flex-col gap-2">
            {rows.map((r) => (
                <div key={r.name} className="flex items-center gap-2.5">
                    <span className="w-28 flex-none truncate text-[12.5px]">
                        {r.name}
                    </span>
                    <div className="h-3 flex-1 overflow-hidden rounded-full bg-muted">
                        <div
                            className="h-full rounded-full bg-primary"
                            style={{
                                width: `${Math.round((r.value / max) * 100)}%`,
                            }}
                        />
                    </div>
                    <span className="w-6 flex-none text-right text-[12px] font-semibold">
                        {r.value}
                    </span>
                </div>
            ))}
        </div>
    );
}
