import { ListCaption } from '@/components/lists';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    ArrowRight,
    ArrowUpRight,
    BatteryLow,
    Bell,
    Building2,
    ChartNoAxesCombined,
    Clock3,
    Info,
    LockKeyhole,
    MapPin,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    Line,
    LineChart,
    Pie,
    PieChart,
    ReferenceArea,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { DeviceEvidence } from './device-evidence';
import {
    ageBand,
    batteryBand,
    batterySeries,
    clockTime,
    distribution,
    hourlySamples,
    observationGaps,
    time,
    type History,
    type Person,
    type ResponseAlert,
} from './model';

const positionColours = [
    'var(--chart-1)',
    'var(--chart-4)',
    'var(--muted-foreground)',
];
const batteryColours = [
    'var(--chart-4)',
    'var(--chart-2)',
    'var(--chart-1)',
    'var(--muted-foreground)',
];
const tick = { fill: 'var(--muted-foreground)', fontSize: 11 };
const tooltipStyle = {
    background: 'var(--popover)',
    border: '1px solid var(--border)',
    color: 'var(--popover-foreground)',
    borderRadius: 8,
};
const pct = (n: number, total: number) =>
    total ? Math.round((n / total) * 1000) / 10 : 0;
type Bucket = { key: string; name: string; value: number };
function ChartPanel({
    title,
    caption,
    children,
    footer,
}: {
    title: string;
    caption: string;
    children: ReactNode;
    footer?: ReactNode;
}) {
    return (
        <Card className="pl-ins-chart-panel">
            <header className="pl-ins-panel-head">
                <div>
                    <h3 className="text-section-title">{title}</h3>
                    <p className="text-caption">{caption}</p>
                </div>
            </header>
            <div className="pl-ins-panel-body">{children}</div>
            {footer && <footer className="pl-ins-panel-foot">{footer}</footer>}
        </Card>
    );
}
function DataTable({
    caption,
    headers,
    rows,
}: {
    caption: string;
    headers: string[];
    rows: ReactNode[][];
}) {
    return (
        <div className="overflow-x-auto">
            <Table>
                <caption className="sr-only">{caption}</caption>
                <TableHeader>
                    <TableRow>
                        {headers.map((h) => (
                            <TableHead key={h}>{h}</TableHead>
                        ))}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {rows.map((r, i) => (
                        <TableRow key={i}>
                            {r.map((v, j) => (
                                <TableCell key={j}>{v}</TableCell>
                            ))}
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
function LegendButton({
    row,
    total,
    colour,
    onClick,
}: {
    row: Bucket;
    total?: number;
    colour: string;
    onClick: () => void;
}) {
    return (
        <Button
            variant="ghost"
            className="pl-ins-legend-button"
            onClick={onClick}
            aria-label={`${row.name}: ${row.value}${total == null ? '' : ` of ${total}`}. View matching records`}
        >
            <span className="pl-ins-swatch" style={{ background: colour }} />
            <span className="grow">{row.name}</span>
            <strong>{row.value}</strong>
            {total != null && <small>{pct(row.value, total)}%</small>}
            <ArrowUpRight className="size-3" />
        </Button>
    );
}
function CountChart({
    rows,
    colours,
    horizontal = false,
    unit = 'People',
    onSelect,
}: {
    rows: Bucket[];
    colours: string[];
    horizontal?: boolean;
    unit?: string;
    onSelect: (key: string) => void;
}) {
    return (
        <ResponsiveContainer width="100%" height={180}>
            <BarChart
                data={rows}
                layout={horizontal ? 'vertical' : 'horizontal'}
                margin={{
                    top: 10,
                    right: 20,
                    left: horizontal ? 15 : 0,
                    bottom: horizontal ? 20 : 0,
                }}
            >
                <CartesianGrid
                    stroke="var(--border)"
                    vertical={horizontal}
                    horizontal={!horizontal}
                    strokeDasharray="3 3"
                />
                {horizontal ? (
                    <>
                        <XAxis
                            type="number"
                            allowDecimals={false}
                            tick={tick}
                            axisLine={false}
                            tickLine={false}
                            label={{
                                value: unit,
                                position: 'insideBottom',
                                offset: -10,
                                ...tick,
                            }}
                        />
                        <YAxis
                            type="category"
                            dataKey="name"
                            width={108}
                            tick={tick}
                            axisLine={false}
                            tickLine={false}
                        />
                    </>
                ) : (
                    <>
                        <XAxis
                            dataKey="name"
                            tick={tick}
                            axisLine={false}
                            tickLine={false}
                        />
                        <YAxis
                            allowDecimals={false}
                            width={34}
                            tick={tick}
                            axisLine={false}
                            tickLine={false}
                            label={{
                                value: 'People',
                                angle: -90,
                                position: 'insideLeft',
                                ...tick,
                            }}
                        />
                    </>
                )}
                <Tooltip
                    contentStyle={tooltipStyle}
                    cursor={{ fill: 'var(--muted)' }}
                />
                <Bar
                    dataKey="value"
                    name={unit}
                    radius={horizontal ? [0, 5, 5, 0] : [5, 5, 0, 0]}
                    maxBarSize={horizontal ? 22 : 52}
                    isAnimationActive={false}
                    onClick={(r) => onSelect((r as unknown as Bucket).key)}
                    cursor="pointer"
                >
                    {rows.map((r, i) => (
                        <Cell key={r.key} fill={colours[i % colours.length]} />
                    ))}
                </Bar>
            </BarChart>
        </ResponsiveContainer>
    );
}
export default function Analytics({
    people,
    alerts,
    canReadAlerts,
    history,
    data,
    drill,
    openAlerts,
    historyControls,
    onHistory,
    onReport,
    checkedAt,
}: {
    people: Person[];
    alerts: ResponseAlert[];
    canReadAlerts: boolean;
    history: History | null;
    data: boolean;
    drill: (cohort: string) => void;
    openAlerts: (status: string) => void;
    historyControls: ReactNode;
    onHistory: () => void;
    onReport: () => void;
    checkedAt: string;
}) {
    const [explain, setExplain] = useState(false);
    const [focus, setFocus] = useState<'overview' | 'history'>('overview');
    const ageRows = (kind: 'position' | 'battery') =>
        distribution(
            people,
            ['recent', 'hour', 'day', 'older', 'unknown'],
            (p) =>
                ageBand(
                    kind === 'position' ? p.position?.timestamp : p.batteryAt,
                    checkedAt,
                ),
        ).map((row, i) => ({
            ...row,
            name: [
                '≤15 minutes',
                '15–60 minutes',
                '1–24 hours',
                'Over 24 hours',
                'Not reported',
            ][i],
        }));
    const positions = distribution(
        people,
        ['recent', 'stale', 'unknown'],
        (p) => p.positionState,
    ).map((r, i) => ({
        ...r,
        name: ['Recent position', 'Stale position', 'Position unavailable'][i],
    }));
    const batteries = distribution(
        people,
        ['low', 'medium', 'high', 'unknown'],
        batteryBand,
    ).map((r, i) => ({
        ...r,
        name: ['≤20%', '21–50%', '>50%', 'Not supplied'][i],
    }));
    const sites = [...new Set(people.map((p) => p.site.id))].map((id) => ({
        key: String(id),
        name: people.find((p) => p.site.id === id)!.site.name,
        total: people.filter((p) => p.site.id === id).length,
        recent: people.filter(
            (p) => p.site.id === id && p.positionState === 'recent',
        ).length,
        stale: people.filter(
            (p) => p.site.id === id && p.positionState === 'stale',
        ).length,
        unknown: people.filter(
            (p) => p.site.id === id && p.positionState === 'unknown',
        ).length,
    }));
    const responses = distribution(
        alerts,
        [...new Set(alerts.map((a) => a.status))],
        (a) => a.status,
    ).map((r) => ({
        ...r,
        name: r.name.charAt(0).toUpperCase() + r.name.slice(1),
    }));
    const samples = history?.samples ?? [],
        series = batterySeries(samples),
        gaps = observationGaps(samples),
        hours = hourlySamples(samples, history?.window);
    const observedHours = hours.filter((h) => h.count > 0).length;
    const recentPercent = pct(positions[0].value, people.length);
    const missing = [
        {
            key: 'source-choice',
            name: 'Choose a source',
            value: people.filter(
                (p) =>
                    p.positionState === 'unknown' &&
                    (p.sourceChoiceRequired ?? p.sources.length > 1),
            ).length,
        },
        {
            key: 'no-source',
            name: 'No source supplied',
            value: people.filter(
                (p) => p.positionState === 'unknown' && !p.sources.length,
            ).length,
        },
        {
            key: 'no-position',
            name: 'No permitted position supplied',
            value: people.filter(
                (p) =>
                    p.positionState === 'unknown' &&
                    p.sources.length > 0 &&
                    !(p.sourceChoiceRequired ?? p.sources.length > 1),
            ).length,
        },
    ];
    return (
        <div className="pl-ins-stack">
            <div className="flex gap-2" aria-label="Analytics focus">
                <Button
                    variant={focus === 'overview' ? 'default' : 'outline'}
                    aria-pressed={focus === 'overview'}
                    onClick={() => setFocus('overview')}
                >
                    Population snapshot
                </Button>
                <Button
                    variant={focus === 'history' ? 'default' : 'outline'}
                    aria-pressed={focus === 'history'}
                    onClick={() => setFocus('history')}
                >
                    Person history
                </Button>
            </div>
            <div className="pl-ins-analytics-intro">
                <div>
                    <b>{people.length} permitted people</b>
                    <p className="text-caption">
                        Current source snapshot · access filtered before
                        aggregation
                    </p>
                </div>
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => setExplain(true)}
                >
                    <Info className="size-4" />
                    How these numbers work
                </Button>
            </div>
            {!people.length ? (
                <Card className="p-5">
                    <EmptyState
                        icon={ChartNoAxesCombined}
                        title="No permitted records match these filters"
                        description="Charts need records in the selected population. Hidden records never contribute to a total."
                    />
                </Card>
            ) : (
                <>
                    {focus === 'overview' && (
                        <>
                            <div className="pl-ins-priority-strip">
                                <Button
                                    variant="ghost"
                                    onClick={() => drill('position:not-recent')}
                                >
                                    <MapPin className="size-5" />
                                    <span>
                                        <b>
                                            {positions[1].value +
                                                positions[2].value}{' '}
                                            without a recent position
                                        </b>
                                        <small>
                                            {positions[1].value} stale ·{' '}
                                            {positions[2].value} unavailable
                                        </small>
                                    </span>
                                    <ArrowRight className="size-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    onClick={() => drill('battery:low')}
                                >
                                    <BatteryLow className="size-5" />
                                    <span>
                                        <b>{batteries[0].value} low battery</b>
                                        <small>
                                            Latest reported sample ≤20%
                                        </small>
                                    </span>
                                    <ArrowRight className="size-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    disabled={!canReadAlerts}
                                    onClick={() => openAlerts('all')}
                                >
                                    <Bell className="size-5" />
                                    <span>
                                        <b>
                                            {canReadAlerts
                                                ? `${alerts.length} awaiting response completion`
                                                : 'Response access required'}
                                        </b>
                                        <small>
                                            {canReadAlerts
                                                ? `${alerts.filter((a) => !a.owner).length} unassigned · Control Room owns actions`
                                                : 'Counts withheld'}
                                        </small>
                                    </span>
                                    {canReadAlerts && (
                                        <ArrowRight className="size-4" />
                                    )}
                                </Button>
                            </div>
                            <div className="pl-ins-chart-grid pl-ins-top-charts">
                                <ChartPanel
                                    title="Position availability"
                                    caption="People · latest permitted position"
                                    footer={`${recentPercent}% have a position within the source freshness window. Stale coordinates remain historical.`}
                                >
                                    {data ? (
                                        <DataTable
                                            caption="Position availability"
                                            headers={[
                                                'Position state',
                                                'People',
                                                'Share',
                                            ]}
                                            rows={positions.map((r) => [
                                                <Button
                                                    key={r.key}
                                                    variant="link"
                                                    onClick={() =>
                                                        drill(
                                                            `position:${r.key}`,
                                                        )
                                                    }
                                                >
                                                    {r.name}
                                                </Button>,
                                                r.value,
                                                `${pct(r.value, people.length)}%`,
                                            ])}
                                        />
                                    ) : (
                                        <>
                                            <div className="pl-ins-donut">
                                                <ResponsiveContainer
                                                    width="100%"
                                                    height={210}
                                                >
                                                    <PieChart>
                                                        <Pie
                                                            data={positions}
                                                            dataKey="value"
                                                            nameKey="name"
                                                            innerRadius={65}
                                                            outerRadius={90}
                                                            paddingAngle={3}
                                                            startAngle={90}
                                                            endAngle={-270}
                                                            isAnimationActive={
                                                                false
                                                            }
                                                            onClick={(r) =>
                                                                drill(
                                                                    `position:${(r as Bucket).key}`,
                                                                )
                                                            }
                                                        >
                                                            {positions.map(
                                                                (r, i) => (
                                                                    <Cell
                                                                        key={
                                                                            r.key
                                                                        }
                                                                        fill={
                                                                            positionColours[
                                                                                i
                                                                            ]
                                                                        }
                                                                        stroke="var(--card)"
                                                                        cursor="pointer"
                                                                    />
                                                                ),
                                                            )}
                                                        </Pie>
                                                        <Tooltip
                                                            contentStyle={
                                                                tooltipStyle
                                                            }
                                                            formatter={(v) => [
                                                                `${v} people`,
                                                                'Count',
                                                            ]}
                                                        />
                                                    </PieChart>
                                                </ResponsiveContainer>
                                                <div className="pl-ins-donut-value">
                                                    <b>{recentPercent}%</b>
                                                    <span>recent position</span>
                                                </div>
                                            </div>
                                            <div>
                                                {positions.map((r, i) => (
                                                    <LegendButton
                                                        key={r.key}
                                                        row={r}
                                                        total={people.length}
                                                        colour={
                                                            positionColours[i]
                                                        }
                                                        onClick={() =>
                                                            drill(
                                                                `position:${r.key}`,
                                                            )
                                                        }
                                                    />
                                                ))}
                                            </div>
                                        </>
                                    )}
                                </ChartPanel>
                                <ChartPanel
                                    title="Position coverage by site"
                                    caption="People · same snapshot and selected filters"
                                    footer={
                                        <div className="pl-ins-legend-inline">
                                            {positions.map((r, i) => (
                                                <span key={r.key}>
                                                    <i
                                                        style={{
                                                            background:
                                                                positionColours[
                                                                    i
                                                                ],
                                                        }}
                                                    />
                                                    {r.name}
                                                </span>
                                            ))}
                                        </div>
                                    }
                                >
                                    {data ? (
                                        <DataTable
                                            caption="Position coverage by site"
                                            headers={[
                                                'Site',
                                                'Recent',
                                                'Stale',
                                                'Unavailable',
                                                'Total',
                                            ]}
                                            rows={sites.map((s) => [
                                                <Button
                                                    key={s.key}
                                                    variant="link"
                                                    onClick={() =>
                                                        drill(`site:${s.key}`)
                                                    }
                                                >
                                                    {s.name}
                                                </Button>,
                                                s.recent,
                                                s.stale,
                                                s.unknown,
                                                s.total,
                                            ])}
                                        />
                                    ) : (
                                        <>
                                            <ResponsiveContainer
                                                width="100%"
                                                height={230}
                                            >
                                                <BarChart
                                                    data={sites}
                                                    margin={{
                                                        top: 10,
                                                        right: 15,
                                                        bottom: 24,
                                                        left: 0,
                                                    }}
                                                >
                                                    <CartesianGrid
                                                        stroke="var(--border)"
                                                        vertical={false}
                                                        strokeDasharray="3 3"
                                                    />
                                                    <XAxis
                                                        dataKey="name"
                                                        tick={tick}
                                                        axisLine={false}
                                                        tickLine={false}
                                                    />
                                                    <YAxis
                                                        allowDecimals={false}
                                                        width={34}
                                                        tick={tick}
                                                        axisLine={false}
                                                        tickLine={false}
                                                        label={{
                                                            value: 'People',
                                                            angle: -90,
                                                            position:
                                                                'insideLeft',
                                                            ...tick,
                                                        }}
                                                    />
                                                    <Tooltip
                                                        contentStyle={
                                                            tooltipStyle
                                                        }
                                                        cursor={{
                                                            fill: 'var(--muted)',
                                                        }}
                                                    />
                                                    {positions.map((r, i) => (
                                                        <Bar
                                                            key={r.key}
                                                            dataKey={r.key}
                                                            name={r.name}
                                                            stackId="positions"
                                                            fill={
                                                                positionColours[
                                                                    i
                                                                ]
                                                            }
                                                            maxBarSize={70}
                                                            isAnimationActive={
                                                                false
                                                            }
                                                            onClick={(s) =>
                                                                drill(
                                                                    `position:${r.key}|site:${(s as unknown as { key: string }).key}`,
                                                                )
                                                            }
                                                            cursor="pointer"
                                                        />
                                                    ))}
                                                </BarChart>
                                            </ResponsiveContainer>
                                            <div className="pl-ins-site-actions">
                                                {sites.map((s) => (
                                                    <Button
                                                        key={s.key}
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            drill(
                                                                `site:${s.key}`,
                                                            )
                                                        }
                                                    >
                                                        <Building2 className="size-3" />
                                                        {s.name} · {s.total}
                                                        <ArrowUpRight className="size-3" />
                                                    </Button>
                                                ))}
                                            </div>
                                        </>
                                    )}
                                </ChartPanel>
                            </div>
                            <div className="pl-ins-chart-grid">
                                <ChartPanel
                                    title="Battery reporting"
                                    caption="People · latest reported battery by band"
                                    footer="Not supplied includes unavailable and unsupported sources. Battery sample time is shown on each person."
                                >
                                    {data ? (
                                        <DataTable
                                            caption="Battery reporting"
                                            headers={[
                                                'Band',
                                                'People',
                                                'Share',
                                            ]}
                                            rows={batteries.map((r) => [
                                                <Button
                                                    key={r.key}
                                                    variant="link"
                                                    onClick={() =>
                                                        drill(
                                                            `battery:${r.key}`,
                                                        )
                                                    }
                                                >
                                                    {r.name}
                                                </Button>,
                                                r.value,
                                                `${pct(r.value, people.length)}%`,
                                            ])}
                                        />
                                    ) : (
                                        <>
                                            <CountChart
                                                rows={batteries}
                                                colours={batteryColours}
                                                onSelect={(k) =>
                                                    drill(`battery:${k}`)
                                                }
                                            />
                                            <div className="pl-ins-small-legend">
                                                {batteries.map((r, i) => (
                                                    <LegendButton
                                                        key={r.key}
                                                        row={r}
                                                        colour={
                                                            batteryColours[i]
                                                        }
                                                        onClick={() =>
                                                            drill(
                                                                `battery:${r.key}`,
                                                            )
                                                        }
                                                    />
                                                ))}
                                            </div>
                                        </>
                                    )}
                                </ChartPanel>
                                <ChartPanel
                                    title="Control Room responses"
                                    caption="Original active source records · selected people"
                                    footer={
                                        canReadAlerts
                                            ? 'Responses are records, not people. Completed and information-only records are outside this active-response feed.'
                                            : 'Response permission is independent of viewing a person.'
                                    }
                                >
                                    {!canReadAlerts ? (
                                        <EmptyState
                                            icon={LockKeyhole}
                                            title="Response access required"
                                            description="No response counts or identities are included for this role."
                                        />
                                    ) : !responses.length ? (
                                        <EmptyState
                                            icon={Bell}
                                            title="No active source responses"
                                            description="No readable active Control Room responses in this view. This is not a wellbeing assessment."
                                        />
                                    ) : data ? (
                                        <DataTable
                                            caption="Control Room response states"
                                            headers={['State', 'Records']}
                                            rows={responses.map((r) => [
                                                <Button
                                                    key={r.key}
                                                    variant="link"
                                                    onClick={() =>
                                                        openAlerts(r.key)
                                                    }
                                                >
                                                    {r.name}
                                                </Button>,
                                                r.value,
                                            ])}
                                        />
                                    ) : (
                                        <>
                                            <CountChart
                                                rows={responses}
                                                unit="Response records"
                                                colours={['var(--chart-2)']}
                                                horizontal
                                                onSelect={openAlerts}
                                            />
                                            <div className="pl-ins-small-legend">
                                                {responses.map((r) => (
                                                    <LegendButton
                                                        key={r.key}
                                                        row={r}
                                                        colour="var(--chart-2)"
                                                        onClick={() =>
                                                            openAlerts(r.key)
                                                        }
                                                    />
                                                ))}
                                            </div>
                                        </>
                                    )}
                                </ChartPanel>
                            </div>
                            <details className="pl-ins-breakdown">
                                <summary>
                                    Charging, movement & missing-position
                                    breakdowns
                                </summary>
                                <div className="pl-ins-chart-grid">
                                    <ChartPanel
                                        title="Power & movement observations"
                                        caption="Latest reports; unknown is retained in the total"
                                    >
                                        <div className="pl-ins-small-legend">
                                            {(
                                                ['power', 'motion'] as const
                                            ).flatMap((kind) =>
                                                distribution(
                                                    people,
                                                    kind === 'power'
                                                        ? [
                                                              'charging',
                                                              'full',
                                                              'not_charging',
                                                              'external',
                                                              'unknown',
                                                          ]
                                                        : [
                                                              'moving',
                                                              'stationary',
                                                              'unknown',
                                                          ],
                                                    (p) => p[kind],
                                                ).map((r) => (
                                                    <LegendButton
                                                        key={`${kind}:${r.key}`}
                                                        row={{
                                                            ...r,
                                                            name:
                                                                r.key ===
                                                                'unknown'
                                                                    ? `${kind === 'power' ? 'Power' : 'Movement'} not supplied`
                                                                    : r.name,
                                                        }}
                                                        colour="var(--chart-1)"
                                                        onClick={() =>
                                                            drill(
                                                                `${kind}:${r.key}`,
                                                            )
                                                        }
                                                    />
                                                )),
                                            )}
                                        </div>
                                        <p className="text-caption mt-3">
                                            Power and movement are separate
                                            dimensions. These counts do not
                                            describe activity duration.
                                        </p>
                                    </ChartPanel>
                                    <ChartPanel
                                        title="Why a position is unavailable"
                                        caption="People · supplied source states kept separate"
                                    >
                                        {missing.map((r) => (
                                            <LegendButton
                                                key={r.key}
                                                row={r}
                                                colour="var(--muted-foreground)"
                                                onClick={() =>
                                                    drill(`missing:${r.key}`)
                                                }
                                            />
                                        ))}
                                        <p className="text-caption mt-3">
                                            An absent coordinate does not
                                            identify a device fault. Ended or
                                            unauthorised sessions are excluded
                                            from this population.
                                        </p>
                                    </ChartPanel>
                                </div>
                            </details>
                            <div className="pl-ins-chart-grid">
                                {(['position', 'battery'] as const).map(
                                    (kind) => (
                                        <ChartPanel
                                            key={kind}
                                            title={
                                                kind === 'position'
                                                    ? 'Position observation age'
                                                    : 'Battery observation age'
                                            }
                                            caption={`Age at ${time(checkedAt)} · ${people.length} permitted people`}
                                            footer={
                                                <p className="text-caption">
                                                    Each source keeps its own
                                                    time. Contact does not
                                                    refresh an older reading.
                                                    Select an age band to
                                                    inspect the exact matching
                                                    people.
                                                </p>
                                            }
                                        >
                                            {data ? (
                                                <DataTable
                                                    caption={`${kind} age`}
                                                    headers={[
                                                        'Observation age',
                                                        'People',
                                                        'Share',
                                                    ]}
                                                    rows={ageRows(kind).map(
                                                        (row) => [
                                                            row.name,
                                                            row.value,
                                                            `${pct(row.value, people.length)}%`,
                                                        ],
                                                    )}
                                                />
                                            ) : (
                                                <CountChart
                                                    rows={ageRows(kind)}
                                                    colours={[
                                                        'var(--chart-1)',
                                                        'var(--chart-2)',
                                                        'var(--chart-4)',
                                                        'var(--chart-5)',
                                                        'var(--muted-foreground)',
                                                    ]}
                                                    horizontal
                                                    onSelect={(value) =>
                                                        drill(
                                                            `${kind}Age:${value}`,
                                                        )
                                                    }
                                                />
                                            )}
                                            <div className="pl-ins-small-legend">
                                                {ageRows(kind).map((row, i) => (
                                                    <LegendButton
                                                        key={row.key}
                                                        row={row}
                                                        total={people.length}
                                                        colour={
                                                            [
                                                                'var(--chart-1)',
                                                                'var(--chart-2)',
                                                                'var(--chart-4)',
                                                                'var(--chart-5)',
                                                                'var(--muted-foreground)',
                                                            ][i]
                                                        }
                                                        onClick={() =>
                                                            drill(
                                                                `${kind}Age:${row.key}`,
                                                            )
                                                        }
                                                    />
                                                ))}
                                            </div>
                                        </ChartPanel>
                                    ),
                                )}
                            </div>
                        </>
                    )}
                    {focus === 'history' && (
                        <>
                            <ListCaption
                                title="Recorded evidence over time"
                                caption="Selected day · authorised source window"
                            />
                            {historyControls}
                            {!history || history.needsSource ? (
                                <Card className="p-5">
                                    <EmptyState
                                        icon={Clock3}
                                        title={
                                            history?.needsSource
                                                ? 'Choose a location source'
                                                : 'Retained history unavailable'
                                        }
                                        description="Choose a permitted client and source to inspect their observations. Staff history remains in its authorised safety session."
                                    />
                                </Card>
                            ) : (
                                <>
                                    <div className="pl-ins-chart-grid pl-ins-history-grid">
                                        <ChartPanel
                                            title="Recorded battery trend"
                                            caption={`${history.name} · ${history.source?.reference ?? 'Selected source'} · Pacific/Auckland`}
                                            footer={
                                                <div className="pl-ins-foot-actions">
                                                    <span>
                                                        {samples.length}{' '}
                                                        original samples ·{' '}
                                                        {gaps.length} reporting{' '}
                                                        {gaps.length === 1
                                                            ? 'gap'
                                                            : 'gaps'}
                                                    </span>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={onHistory}
                                                    >
                                                        Open history
                                                        <ArrowUpRight className="size-3" />
                                                    </Button>
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={onReport}
                                                    >
                                                        Day / outing report
                                                    </Button>
                                                </div>
                                            }
                                        >
                                            {data ? (
                                                <DataTable
                                                    caption="Original battery samples"
                                                    headers={[
                                                        'Recorded time',
                                                        'Battery',
                                                        'Original source',
                                                    ]}
                                                    rows={samples.map((s) => [
                                                        time(s.at),
                                                        s.battery == null
                                                            ? 'Not supplied'
                                                            : `${s.battery}%`,
                                                        history.source
                                                            ?.reference,
                                                    ])}
                                                />
                                            ) : !samples.length ? (
                                                <EmptyState
                                                    icon={Clock3}
                                                    title="No retained device observations"
                                                    description="No battery samples were supplied within this authorised selection."
                                                />
                                            ) : (
                                                <ResponsiveContainer
                                                    width="100%"
                                                    height={265}
                                                >
                                                    <LineChart
                                                        data={series}
                                                        margin={{
                                                            top: 25,
                                                            right: 20,
                                                            left: 0,
                                                            bottom: 30,
                                                        }}
                                                    >
                                                        <CartesianGrid
                                                            stroke="var(--border)"
                                                            vertical={false}
                                                            strokeDasharray="3 3"
                                                        />
                                                        <XAxis
                                                            dataKey="at"
                                                            type="number"
                                                            domain={[
                                                                'dataMin',
                                                                'dataMax',
                                                            ]}
                                                            tickFormatter={
                                                                clockTime
                                                            }
                                                            tick={tick}
                                                            tickLine={false}
                                                            axisLine={false}
                                                            label={{
                                                                value: 'Recorded time · Pacific/Auckland',
                                                                position:
                                                                    'insideBottom',
                                                                offset: -20,
                                                                ...tick,
                                                            }}
                                                        />
                                                        <YAxis
                                                            domain={[0, 100]}
                                                            ticks={[
                                                                0, 25, 50, 75,
                                                                100,
                                                            ]}
                                                            width={40}
                                                            tick={tick}
                                                            axisLine={false}
                                                            tickLine={false}
                                                            label={{
                                                                value: 'Battery (%)',
                                                                angle: -90,
                                                                position:
                                                                    'insideLeft',
                                                                ...tick,
                                                            }}
                                                        />
                                                        {gaps.map((g) => (
                                                            <ReferenceArea
                                                                key={g.from}
                                                                x1={g.from}
                                                                x2={g.to}
                                                                fill="var(--muted)"
                                                                fillOpacity={
                                                                    0.7
                                                                }
                                                                label={{
                                                                    value: 'Reporting gap',
                                                                    ...tick,
                                                                }}
                                                            />
                                                        ))}
                                                        <Tooltip
                                                            labelFormatter={(
                                                                v,
                                                            ) =>
                                                                time(Number(v))
                                                            }
                                                            formatter={(v) => [
                                                                `${v}%`,
                                                                'Recorded battery',
                                                            ]}
                                                            contentStyle={
                                                                tooltipStyle
                                                            }
                                                        />
                                                        <Line
                                                            dataKey="battery"
                                                            type="linear"
                                                            stroke="var(--chart-1)"
                                                            strokeWidth={2.5}
                                                            connectNulls={false}
                                                            dot={{
                                                                r: 5,
                                                                fill: 'var(--chart-1)',
                                                                stroke: 'var(--card)',
                                                                strokeWidth: 2,
                                                            }}
                                                            activeDot={{ r: 7 }}
                                                            isAnimationActive={
                                                                false
                                                            }
                                                        />
                                                    </LineChart>
                                                </ResponsiveContainer>
                                            )}
                                        </ChartPanel>
                                        <ChartPanel
                                            title="Reporting coverage"
                                            caption="Retained device samples per hourly bucket"
                                            footer={`${observedHours} of ${hours.length} authorised hourly buckets contain a sample (${pct(observedHours, hours.length)}%). Partial hours count; this is not continuous uptime.`}
                                        >
                                            {data ? (
                                                <DataTable
                                                    caption="Reporting coverage"
                                                    headers={[
                                                        'From · Pacific/Auckland',
                                                        'Samples',
                                                        'Bucket',
                                                    ]}
                                                    rows={hours.map((h) => [
                                                        time(h.at),
                                                        h.count,
                                                        h.partial
                                                            ? 'Partial hour'
                                                            : 'Hour',
                                                    ])}
                                                />
                                            ) : (
                                                <ResponsiveContainer
                                                    width="100%"
                                                    height={210}
                                                >
                                                    <BarChart
                                                        data={hours}
                                                        margin={{
                                                            top: 15,
                                                            right: 15,
                                                            left: 0,
                                                            bottom: 0,
                                                        }}
                                                    >
                                                        <CartesianGrid
                                                            stroke="var(--border)"
                                                            vertical={false}
                                                            strokeDasharray="3 3"
                                                        />
                                                        <XAxis
                                                            dataKey="at"
                                                            tickFormatter={
                                                                clockTime
                                                            }
                                                            tick={tick}
                                                            axisLine={false}
                                                            tickLine={false}
                                                        />
                                                        <YAxis
                                                            allowDecimals={
                                                                false
                                                            }
                                                            width={35}
                                                            tick={tick}
                                                            axisLine={false}
                                                            tickLine={false}
                                                            label={{
                                                                value: 'Samples',
                                                                angle: -90,
                                                                position:
                                                                    'insideLeft',
                                                                ...tick,
                                                            }}
                                                        />
                                                        <Tooltip
                                                            labelFormatter={(
                                                                v,
                                                            ) =>
                                                                time(Number(v))
                                                            }
                                                            contentStyle={
                                                                tooltipStyle
                                                            }
                                                            cursor={{
                                                                fill: 'var(--muted)',
                                                            }}
                                                        />
                                                        <Bar
                                                            dataKey="count"
                                                            name="Retained samples"
                                                            fill="var(--chart-2)"
                                                            radius={[
                                                                5, 5, 0, 0,
                                                            ]}
                                                            maxBarSize={40}
                                                            isAnimationActive={
                                                                false
                                                            }
                                                        />
                                                    </BarChart>
                                                </ResponsiveContainer>
                                            )}
                                            <p className="pl-ins-gap-label">
                                                <Clock3 className="size-4 shrink-0" />
                                                {
                                                    hours.filter(
                                                        (h) => !h.count,
                                                    ).length
                                                }{' '}
                                                hourly buckets without a sample.
                                                Missing hours are not inferred.
                                            </p>
                                        </ChartPanel>
                                    </div>
                                    <details className="pl-ins-breakdown">
                                        <summary>
                                            Original power & movement
                                            observations
                                        </summary>
                                        <DeviceEvidence samples={samples} />
                                    </details>
                                    {history.truncated && (
                                        <p
                                            role="status"
                                            className="text-caption text-status-warning"
                                        >
                                            The source reached its 500-sample
                                            limit. This is a partial view.
                                        </p>
                                    )}
                                </>
                            )}
                        </>
                    )}
                </>
            )}
            <p className="text-caption">
                Source observations · access filtered before aggregation · no
                wellbeing, attendance, productivity or continuous tracking
                inference.
            </p>
            <Dialog open={explain} onOpenChange={setExplain}>
                <DialogContent
                    className="max-h-[90vh] overflow-y-auto"
                    style={{
                        width: 'min(92vw, 720px)',
                        maxWidth: 'min(92vw, 720px)',
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>How these numbers work</DialogTitle>
                        <DialogDescription>
                            Every chart uses the current permitted population
                            and selected filters.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3 text-sm">
                        <p>
                            Each person contributes once to position, battery
                            and site totals. Missing readings stay in the total.
                            A battery value of zero is a reported reading.
                        </p>
                        <p>
                            Position freshness uses the recorded position time.
                            Recent device contact does not make an older
                            position current.
                        </p>
                        <p>
                            Control Room counts independently readable active
                            response records, not people. Their original owner
                            and lifecycle remain in Control Room.
                        </p>
                        <p>
                            History uses the selected source and its authorised
                            retained window. Reporting gaps remain open; sample
                            coverage does not prove continuous monitoring.
                        </p>
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setExplain(false)}
                        >
                            Close
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
