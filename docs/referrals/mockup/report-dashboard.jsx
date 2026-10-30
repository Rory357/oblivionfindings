import { DonutChart } from '@/components/charts/ops-charts';
import { HalfMoonGauge } from '@/components/fleet-charts';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { toDateInput } from '@/lib/datetime';
import { ArrowUpRight } from 'lucide-react';
import {
    CartesianGrid,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { date, stages } from './data';
import { Button } from './ui';

const palette = [
    'var(--chart-1)',
    'var(--chart-2)',
    'var(--chart-3)',
    'var(--chart-4)',
    'var(--chart-5)',
];
function ChartPanel({ title, description, children, action, className = '' }) {
    return (
        <Card className={'referral-report-panel ' + className}>
            <CardHeader>
                <div className="report-panel-heading">
                    <CardTitle className="text-section-title">
                        <h2>{title}</h2>
                    </CardTitle>
                    {action}
                </div>
                <p className="text-subtle">{description}</p>
            </CardHeader>
            <CardContent>{children}</CardContent>
        </Card>
    );
}
function Distribution({
    rows,
    total,
    onOpen,
    label,
    scope = 'the selected received-date cohort',
}) {
    return (
        <div className="report-distribution" aria-label={label}>
            {rows.map(({ name, records }, i) => (
                <button
                    key={name}
                    className="report-bar-button frontline-focus"
                    onClick={() =>
                        onOpen([
                            name,
                            records,
                            `${label}. Referrals in ${scope}.`,
                        ])
                    }
                    aria-label={`${label}: ${name}, ${records.length} referrals`}
                >
                    <span className="report-bar-label">
                        <span>{name}</span>
                        <strong>{records.length}</strong>
                        <ArrowUpRight aria-hidden="true" size={14} />
                    </span>
                    <span className="report-bar-track" aria-hidden="true">
                        <span
                            style={{
                                width: `${total ? (records.length / total) * 100 : 0}%`,
                                background: palette[i % palette.length],
                            }}
                        />
                    </span>
                </button>
            ))}
        </div>
    );
}
const partition = (records, key) =>
    [...new Set(records.map((r) => r[key] || 'Not recorded'))]
        .map((name) => ({
            name,
            records: records.filter((r) => (r[key] || 'Not recorded') === name),
        }))
        .sort((a, b) => b.records.length - a.records.length);

export function CurrentWorkload({ records, groups, openGroup }) {
    return (
        <div className="report-halves">
            <ChartPanel
                title="All current work needing attention"
                description="All permitted open referrals, including those received before the selected report period. Site and search filters still apply."
            >
                {groups.map((g) => (
                    <button
                        key={g[0]}
                        className="report-measure-button frontline-focus"
                        onClick={() => openGroup(g)}
                    >
                        <span>{g[0]}</span>
                        <strong>{g[1].length}</strong>
                        <ArrowUpRight size={14} />
                    </button>
                ))}
            </ChartPanel>
            <ChartPanel
                title="Current workload by coordinator"
                description="Every matching open referral, regardless of when it arrived."
            >
                <Distribution
                    rows={partition(records, 'owner')}
                    total={records.length}
                    onOpen={openGroup}
                    label="Current coordinator workload"
                    scope="all current open work"
                />
            </ChartPanel>
        </div>
    );
}

// Equal date buckets use NZ calendar dates, including empty periods; no synthetic trend values.
function receivedTrend(records, from, to) {
    const start = Date.parse(from + 'T00:00:00Z'),
        end = Date.parse(to + 'T00:00:00Z');
    const days = Math.round((end - start) / 86400000) + 1,
        width = Math.max(1, Math.ceil(days / 8));
    const result = [];
    for (let offset = 0; offset < days; offset += width) {
        const lo = new Date(start + offset * 86400000)
            .toISOString()
            .slice(0, 10);
        const hi = new Date(
            Math.min(end, start + (offset + width - 1) * 86400000),
        )
            .toISOString()
            .slice(0, 10);
        const matches = records.filter((r) => {
            const d = toDateInput(r.createdAt);
            return d >= lo && d <= hi;
        });
        result.push({
            label: date(lo).replace(/ \d{4}$/, ''),
            range: lo === hi ? date(lo) : `${date(lo)} – ${date(hi)}`,
            received: matches.length,
            contacted: matches.filter((r) =>
                r.notes.some(
                    (n) =>
                        n.successful &&
                        Number.isFinite(
                            new Date(n.occurredAt || n.at).getTime(),
                        ),
                ),
            ).length,
            records: matches,
        });
    }
    return result;
}

export function ReferralReportOverview({
    cohort,
    active,
    from,
    to,
    canFunding,
    groups,
    openGroup,
    showDetails,
}) {
    const group = (name) => groups.find((g) => g[0] === name);
    const trend = receivedTrend(cohort, from, to);
    const sources = partition(cohort, 'source');
    const stageRows = [
        ...new Set([...stages, ...cohort.map((r) => r.stage)]),
    ].map((name) => ({
        name,
        records: cohort.filter((r) => r.stage === name),
    }));
    const attention = [
        'Overdue work',
        'Unassigned for more than one day',
        'No stage progress for 14 days',
        ...(canFunding ? ['Funding needs review'] : []),
    ]
        .map(group)
        .filter(Boolean);

    return (
        <div className="report-dashboard">
            <div className="report-insight-grid">
                <ChartPanel
                    title="Referral intake & contact"
                    description="Received referrals, and how many now have a successful contact recorded."
                    action={
                        <Button
                            variant="ghost"
                            className="frontline-hit"
                            onClick={() =>
                                openGroup(group('Received in period'))
                            }
                        >
                            View referrals <ArrowUpRight size={14} />
                        </Button>
                    }
                >
                    <div className="report-chart-legend">
                        <span>
                            <i style={{ background: palette[0] }} />
                            Received
                        </span>
                        <span>
                            <i className="report-legend-dashed" />
                            Contact recorded
                        </span>
                    </div>
                    <div
                        className="report-trend-chart"
                        role="img"
                        aria-label="Line graph of referrals by received date and their current contact status. Exact values are available in View chart data."
                    >
                        <ResponsiveContainer width="100%" height="100%">
                            <LineChart
                                data={trend}
                                margin={{
                                    top: 15,
                                    right: 15,
                                    left: -25,
                                    bottom: 5,
                                }}
                                accessibilityLayer
                            >
                                <CartesianGrid
                                    vertical={false}
                                    stroke="var(--border)"
                                    strokeDasharray="3 3"
                                />
                                <XAxis
                                    dataKey="label"
                                    tick={{
                                        fill: 'var(--muted-foreground)',
                                        fontSize: 11,
                                    }}
                                    tickLine={false}
                                    axisLine={false}
                                    minTickGap={18}
                                />
                                <YAxis
                                    allowDecimals={false}
                                    tick={{
                                        fill: 'var(--muted-foreground)',
                                        fontSize: 11,
                                    }}
                                    tickLine={false}
                                    axisLine={false}
                                />
                                <Tooltip
                                    contentStyle={{
                                        background: 'var(--popover)',
                                        border: '1px solid var(--border)',
                                        borderRadius: 'var(--radius-md)',
                                        color: 'var(--popover-foreground)',
                                    }}
                                    labelFormatter={(_, items) =>
                                        items[0]?.payload.range
                                    }
                                    itemStyle={{
                                        color: 'var(--popover-foreground)',
                                    }}
                                />
                                <Line
                                    name="Received"
                                    dataKey="received"
                                    type="linear"
                                    stroke={palette[0]}
                                    strokeWidth={3}
                                    dot={{ r: 3 }}
                                    isAnimationActive={false}
                                />
                                <Line
                                    name="Contact recorded"
                                    dataKey="contacted"
                                    type="linear"
                                    stroke={palette[1]}
                                    strokeDasharray="5 4"
                                    strokeWidth={2}
                                    dot={{ r: 3 }}
                                    isAnimationActive={false}
                                />
                            </LineChart>
                        </ResponsiveContainer>
                    </div>
                    <details className="report-chart-data">
                        <summary className="frontline-hit">
                            View chart data
                        </summary>
                        <div className="report-data-scroll">
                            <table>
                                <caption className="text-caption">
                                    Grouped by received date; contact status is
                                    current.
                                </caption>
                                <thead>
                                    <tr>
                                        <th>Received</th>
                                        <th>Referrals</th>
                                        <th>Contact recorded</th>
                                        <th>Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {trend.map((row) => (
                                        <tr key={row.range}>
                                            <th>{row.range}</th>
                                            <td>{row.received}</td>
                                            <td>{row.contacted}</td>
                                            <td>
                                                <Button
                                                    variant="link"
                                                    onClick={() =>
                                                        openGroup([
                                                            row.range,
                                                            row.records,
                                                            'Referrals received in this date bucket. Contact status is current.',
                                                        ])
                                                    }
                                                >
                                                    View
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </details>
                </ChartPanel>
                <ChartPanel
                    title="Supported living handover"
                    description="Conversion and service commencement remain separate milestones."
                >
                    <div className="report-gauge">
                        <HalfMoonGauge
                            value={
                                (group('Converted')[1].length / cohort.length) *
                                100
                            }
                            label="Linked to a supported person"
                            sublabel={`${group('Converted')[1].length} of ${cohort.length} received referrals`}
                            size={200}
                            color="var(--chart-1)"
                        />
                    </div>
                    <div className="report-outcome-links">
                        {[
                            'Converted',
                            'Service commenced',
                            'Closed with another provider',
                        ].map((name) => (
                            <button
                                className="report-measure-button frontline-focus"
                                key={name}
                                onClick={() => openGroup(group(name))}
                            >
                                <span>{name}</span>
                                <strong>{group(name)[1].length}</strong>
                                <ArrowUpRight size={14} />
                            </button>
                        ))}
                    </div>
                </ChartPanel>
            </div>
            <div className="report-halves">
                <ChartPanel
                    title="Where referrals are now"
                    description="Current stage of the received cohort. Select a stage to inspect it."
                >
                    <Distribution
                        rows={stageRows}
                        total={cohort.length}
                        onOpen={openGroup}
                        label="Current stage"
                    />
                </ChartPanel>
                <ChartPanel
                    title="Where referrals come from"
                    description="Recorded intake channel, including website and phone enquiries."
                >
                    <div className="report-source-chart">
                        <div aria-hidden="true">
                            <DonutChart
                                segments={sources.map((s, i) => ({
                                    label: s.name,
                                    value: s.records.length,
                                    color: palette[i % palette.length],
                                }))}
                                centerValue={cohort.length}
                                centerLabel="referrals"
                                size={160}
                            />
                        </div>
                        <Distribution
                            rows={sources}
                            total={cohort.length}
                            onOpen={openGroup}
                            label="Referral source"
                        />
                    </div>
                    <p className="text-caption report-chart-note">
                        Channel totals include open, closed and converted
                        referrals.
                    </p>
                </ChartPanel>
            </div>
            <div className="report-halves">
                <ChartPanel
                    title="Needs attention in this received cohort"
                    description="Excludes referrals received outside this period. Use Current workload for the entire open backlog. A person can appear in more than one measure."
                    action={
                        <Button variant="link" onClick={showDetails}>
                            All measures <ArrowUpRight size={14} />
                        </Button>
                    }
                >
                    <div className="report-outcome-links">
                        {attention.map((g) => (
                            <button
                                key={g[0]}
                                className="report-measure-button frontline-focus"
                                onClick={() => openGroup(g)}
                            >
                                <span>{g[0]}</span>
                                <strong>{g[1].length}</strong>
                                <ArrowUpRight size={14} />
                            </button>
                        ))}
                    </div>
                </ChartPanel>
                <ChartPanel
                    title="Open work by coordinator"
                    description="Current owner of open referrals in this cohort."
                >
                    <Distribution
                        rows={partition(active, 'owner')}
                        total={active.length}
                        onOpen={openGroup}
                        label="Coordinator workload"
                    />
                    {!active.length && (
                        <p className="text-subtle">
                            No open referrals in this cohort.
                        </p>
                    )}
                </ChartPanel>
            </div>
        </div>
    );
}

export function ReferralReportBreakdowns({ cohort, openGroup }) {
    return (
        <div className="report-halves">
            <ChartPanel
                title="Referrals by receiving site"
                description="Referral counts in the current received-date cohort."
            >
                <Distribution
                    rows={partition(cohort, 'site')}
                    total={cohort.length}
                    onOpen={openGroup}
                    label="Receiving site"
                />
            </ChartPanel>
            <ChartPanel
                title="Referrals by intake channel"
                description="Compare recorded sources and open the matching records."
            >
                <Distribution
                    rows={partition(cohort, 'source')}
                    total={cohort.length}
                    onOpen={openGroup}
                    label="Intake channel"
                />
            </ChartPanel>
        </div>
    );
}
