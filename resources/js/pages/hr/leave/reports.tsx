import {
    LeaveHubHero,
    LeaveHubTabs,
    leaveTypeMeta,
    type HubHero,
} from '@/components/hr';
import { PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Download } from 'lucide-react';

/* Inline-SVG charts — recharts (ResponsiveContainer + fixed PieChart alike)
 * collapses to a flat line on first paint inside this card layout and only
 * fills in after a window resize. Raw SVG paints immediately and matches the
 * hero's donut treatment. */

function TypeDonut({ data }: { data: Array<{ type: string; value: number }> }) {
    const segments = data.filter((d) => d.value > 0);
    const total = segments.reduce((a, d) => a + d.value, 0) || 1;
    const r = 42;
    const c = 2 * Math.PI * r;
    let acc = 0;
    return (
        <svg
            aria-hidden="true"
            width={132}
            height={132}
            viewBox="0 0 132 132"
            className="flex-none"
        >
            <g transform="rotate(-90 66 66)">
                <circle
                    cx={66}
                    cy={66}
                    r={r}
                    fill="none"
                    stroke="var(--muted)"
                    strokeWidth={13}
                />
                {segments.map((d) => {
                    const len = (d.value / total) * c;
                    const seg = (
                        <circle
                            key={d.type}
                            cx={66}
                            cy={66}
                            r={r}
                            fill="none"
                            stroke={leaveTypeMeta(d.type).color}
                            strokeWidth={13}
                            strokeDasharray={`${len.toFixed(2)} ${(c - len).toFixed(2)}`}
                            strokeDashoffset={(-acc).toFixed(2)}
                        />
                    );
                    acc += len;
                    return seg;
                })}
            </g>
            <text
                x={66}
                y={72}
                textAnchor="middle"
                fontSize={20}
                style={{ fill: 'var(--foreground)', fontWeight: 800 }}
            >
                {segments.reduce((a, d) => a + d.value, 0)}
            </text>
        </svg>
    );
}

function AbsenceArea({
    monthly,
}: {
    monthly: Array<{ label: string; count: number }>;
}) {
    const w = 320;
    const h = 120;
    const max = Math.max(...monthly.map((m) => m.count), 1);
    const n = Math.max(1, monthly.length - 1);
    const pts = monthly.map((m, i) => {
        const x = (i / n) * w;
        const y = h - (m.count / max) * (h - 8) - 2;
        return [x, y] as const;
    });
    const line = pts
        .map(([x, y]) => `${x.toFixed(1)},${y.toFixed(1)}`)
        .join(' ');
    const area = `0,${h} ${line} ${w},${h}`;
    return (
        <div>
            <svg
                role="img"
                aria-label={`Monthly absence trend: ${monthly
                    .map((m) => `${m.label} ${m.count}`)
                    .join(', ')}`}
                viewBox={`0 0 ${w} ${h}`}
                preserveAspectRatio="none"
                width="100%"
                height={130}
            >
                <defs>
                    <linearGradient id="absFill" x1="0" y1="0" x2="0" y2="1">
                        <stop
                            offset="0%"
                            stopColor="var(--status-critical)"
                            stopOpacity={0.3}
                        />
                        <stop
                            offset="100%"
                            stopColor="var(--status-critical)"
                            stopOpacity={0.02}
                        />
                    </linearGradient>
                </defs>
                <polygon points={area} fill="url(#absFill)" />
                <polyline
                    points={line}
                    fill="none"
                    stroke="var(--status-critical)"
                    strokeWidth={1.5}
                    vectorEffect="non-scaling-stroke"
                />
            </svg>
            <div className="mt-1 flex justify-between text-[10px] text-muted-foreground">
                {monthly.map((m) => (
                    <span key={m.label}>{m.label.slice(0, 1)}</span>
                ))}
            </div>
        </div>
    );
}

interface MonthlyData {
    month: number;
    label: string;
    count: number;
    total_hours: number;
}

interface Absentee {
    user_id: number;
    name: string;
    occurrences: number;
    total_hours: number;
}

interface BradfordEmployee {
    user_id: number;
    name: string;
    spells: number;
    days: number;
    factor: number;
    risk_level: string;
}

interface UtilizationEmployee {
    user_id: number;
    name: string;
    total_entitlement: number;
    total_used: number;
    total_remaining: number;
    overall_pct: number;
}

interface Props {
    absenteeism: {
        monthly: MonthlyData[];
        top_absentees: Absentee[];
        year: number;
    };
    bradfordFactor: { employees: BradfordEmployee[]; year: number };
    utilization: { employees: UtilizationEmployee[]; year: number };
    typeBreakdown: Array<{ type: string; value: number }>;
    year: number;
    hero: HubHero;
    can: { manage: boolean; approve?: boolean; create?: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Leave & Rosters', href: '/hr/leave' },
    { title: 'Reports', href: '/hr/leave/reports' },
];

/** Bradford score → bar/text colour (frequency² × days flags disruptive absence). */
function bradfordTone(score: number) {
    if (score >= 500) return 'var(--status-critical)';
    if (score >= 200) return 'var(--status-warning)';
    return 'var(--status-success)';
}

function utilisationTone(pct: number) {
    if (pct >= 90) return 'var(--status-critical)';
    if (pct >= 70) return 'var(--status-warning)';
    return 'var(--status-success)';
}

export default function LeaveReports({
    absenteeism,
    bradfordFactor,
    utilization,
    typeBreakdown,
    year,
    hero,
    can,
}: Props) {
    const yearOptions: number[] = [];
    const currentYear = new Date().getFullYear();
    for (let y = currentYear - 3; y <= currentYear; y++) yearOptions.push(y);

    const maxFactor = Math.max(
        ...bradfordFactor.employees.map((e) => e.factor),
        1,
    );
    const typeTotal = typeBreakdown.reduce((a, t) => a + t.value, 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Leave Reports" />

            <PageLayout hero={<LeaveHubHero hero={hero} can={can} />}>
                <LeaveHubTabs active="reports" />

                {/* toolbar */}
                <div className="flex flex-wrap items-center gap-2">
                    <Select
                        value={String(year)}
                        onValueChange={(val) =>
                            router.get(
                                '/hr/leave/reports',
                                { year: val },
                                { preserveState: true },
                            )
                        }
                    >
                        <SelectTrigger className="h-9 w-32">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {yearOptions.map((y) => (
                                <SelectItem key={y} value={String(y)}>
                                    {y}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {can.manage && (
                        <div className="ml-auto flex items-center gap-2">
                            <Button asChild variant="outline" size="sm">
                                <a
                                    href={`/hr/leave/reports/export?format=csv&year=${year}`}
                                >
                                    <Download className="mr-1.5 h-4 w-4" /> CSV
                                </a>
                            </Button>
                            <Button asChild variant="outline" size="sm">
                                <a
                                    href={`/hr/leave/reports/export?format=xls&year=${year}`}
                                >
                                    Excel
                                </a>
                            </Button>
                            <Button asChild variant="outline" size="sm">
                                <a
                                    href={`/hr/leave/reports/export?format=pdf&year=${year}`}
                                >
                                    PDF
                                </a>
                            </Button>
                        </div>
                    )}
                </div>

                {/* row 1: absence rate · leave by type */}
                <div className="grid gap-3.5 lg:grid-cols-[1.4fr_1fr]">
                    <Card>
                        <CardContent className="p-4">
                            <div className="flex items-baseline justify-between">
                                <span className="text-[13.5px] font-bold">
                                    Absence rate
                                </span>
                                <span className="text-[22px] font-extrabold text-status-critical tabular-nums">
                                    {hero.absence_rate}%
                                </span>
                            </div>
                            <p className="mb-2 text-[11.5px] text-muted-foreground">
                                Sick leave as % of scheduled hours · {year}
                            </p>
                            {absenteeism.monthly.length > 0 ? (
                                <AbsenceArea monthly={absenteeism.monthly} />
                            ) : (
                                <div className="flex h-[160px] items-center justify-center text-sm text-muted-foreground">
                                    No sick leave recorded
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardContent className="p-4">
                            <span className="text-[13.5px] font-bold">
                                Leave by type
                            </span>
                            {typeTotal > 0 ? (
                                <div className="mt-2 flex items-center gap-4">
                                    {/* Inline SVG donut — recharts collapses on
                                        first paint inside this layout; raw SVG
                                        always renders. */}
                                    <TypeDonut data={typeBreakdown} />
                                    <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                                        {typeBreakdown.map((t) => (
                                            <div
                                                key={t.type}
                                                className="flex items-center gap-2 text-[12px]"
                                            >
                                                <span
                                                    className="h-2.5 w-2.5 flex-none rounded-[3px]"
                                                    style={{
                                                        background:
                                                            leaveTypeMeta(
                                                                t.type,
                                                            ).color,
                                                    }}
                                                />
                                                <span className="flex-1 truncate">
                                                    {
                                                        leaveTypeMeta(t.type)
                                                            .label
                                                    }
                                                </span>
                                                <span className="font-bold tabular-nums">
                                                    {t.value}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            ) : (
                                <div className="flex h-[132px] items-center justify-center text-sm text-muted-foreground">
                                    No leave recorded
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* row 2: Bradford · utilisation */}
                <div className="grid gap-3.5 lg:grid-cols-2">
                    <Card>
                        <CardContent className="p-4">
                            <span className="text-[13.5px] font-bold">
                                Bradford Factor
                            </span>
                            <p className="mb-2.5 text-[11.5px] text-muted-foreground">
                                Frequency² × days — flags disruptive short
                                absences. Top 8 shown; export for the full list.
                            </p>
                            {bradfordFactor.employees.length === 0 ? (
                                <p className="py-6 text-center text-sm text-muted-foreground">
                                    No data for this period
                                </p>
                            ) : (
                                <div className="flex flex-col gap-2.5">
                                    {bradfordFactor.employees
                                        .slice(0, 8)
                                        .map((b) => (
                                            <div
                                                key={b.user_id}
                                                className="flex items-center gap-2.5"
                                            >
                                                <span className="w-28 flex-none truncate text-[12.5px] font-semibold">
                                                    {b.name}
                                                </span>
                                                <div className="h-2.5 flex-1 overflow-hidden rounded-full bg-muted">
                                                    <div
                                                        className="h-full rounded-full"
                                                        style={{
                                                            width: `${(b.factor / maxFactor) * 100}%`,
                                                            background:
                                                                bradfordTone(
                                                                    b.factor,
                                                                ),
                                                        }}
                                                    />
                                                </div>
                                                <span
                                                    className="w-9 text-right text-[12.5px] font-extrabold tabular-nums"
                                                    style={{
                                                        color: bradfordTone(
                                                            b.factor,
                                                        ),
                                                    }}
                                                >
                                                    {b.factor}
                                                </span>
                                            </div>
                                        ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardContent className="p-4">
                            <span className="text-[13.5px] font-bold">
                                Annual leave utilisation
                            </span>
                            <p className="mb-2.5 text-[11.5px] text-muted-foreground">
                                Taken vs entitlement, by staff member. Top 8
                                shown; export for the full list.
                            </p>
                            {utilization.employees.length === 0 ? (
                                <p className="py-6 text-center text-sm text-muted-foreground">
                                    No data for this period
                                </p>
                            ) : (
                                <div className="flex flex-col gap-3">
                                    {utilization.employees
                                        .slice(0, 8)
                                        .map((u) => (
                                            <div key={u.user_id}>
                                                <div className="mb-1 flex justify-between text-[12.5px]">
                                                    <span className="truncate font-semibold">
                                                        {u.name}
                                                    </span>
                                                    <span className="font-bold text-muted-foreground tabular-nums">
                                                        {u.overall_pct}%
                                                    </span>
                                                </div>
                                                <div className="h-2.5 overflow-hidden rounded-full bg-muted">
                                                    <div
                                                        className="h-full rounded-full"
                                                        style={{
                                                            width: `${Math.min(100, u.overall_pct)}%`,
                                                            background:
                                                                utilisationTone(
                                                                    u.overall_pct,
                                                                ),
                                                        }}
                                                    />
                                                </div>
                                            </div>
                                        ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* top absentees */}
                {absenteeism.top_absentees.length > 0 ? (
                    <Card>
                        <CardContent className="p-4">
                            <span className="text-[13.5px] font-bold">
                                Top absentees · sick leave
                            </span>
                            <div className="mt-2.5 flex flex-col divide-y divide-border">
                                {absenteeism.top_absentees.map((a) => (
                                    <div
                                        key={a.user_id}
                                        className="flex items-center justify-between py-2 text-[13px]"
                                    >
                                        <span className="font-semibold">
                                            {a.name}
                                        </span>
                                        <span className="text-muted-foreground tabular-nums">
                                            {a.occurrences} spells ·{' '}
                                            {a.total_hours}h
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                ) : null}
            </PageLayout>
        </AppLayout>
    );
}
