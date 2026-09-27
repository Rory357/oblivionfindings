import { Button } from '@/components/ui/button';
import {
    ArrowRight,
    BarChart3,
    CalendarDays,
    CheckCircle2,
    Clock,
    Flag,
    KeyRound,
    Route,
} from 'lucide-react';
import { useState } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import {
    attentionPriority,
    RETURN_LABELS,
    scheduleCounts,
    stageCounts,
} from './model';
import type { TransportRecord, WorkspaceView } from './types';
import { Empty, Panel, ShortRows } from './ui';
export function TransportOverview({
    records,
    section,
    onOpen,
    onDrill,
}: {
    records: TransportRecord[];
    section: string;
    onOpen: (row: TransportRecord) => void;
    onDrill: (
        view: WorkspaceView,
        filter?: string,
        stages?: string[],
        label?: string,
    ) => void;
}) {
    const [hour, setHour] = useState<number | null>(null);
    const stages = stageCounts(records),
        schedule = scheduleCounts(records);
    const attention = records.filter(
        (r) =>
            ['overdue', 'keys', 'items', 'handover', 'exceptions'].includes(
                r.return_stage,
            ) ||
            [
                'assessment',
                'information',
                'allocation',
                'decision',
                'returned',
            ].includes(r.stage),
    );
    const rows = [...attention].sort(
        (a, b) =>
            attentionPriority(a) - attentionPriority(b) ||
            a.start.localeCompare(b.start),
    );
    if (!records.length) return <Empty />;
    return (
        <>
            {section === 'summary' && (
                <>
                    <div className="tr-priorities">
                        {[
                            {
                                icon: Clock,
                                count: records.filter(
                                    (r) => r.return_stage === 'overdue',
                                ).length,
                                title: 'overdue returns',
                                caption: 'Confirm where the trip stands',
                                view: 'returns' as const,
                                filter: 'overdue',
                            },
                            {
                                icon: Flag,
                                count: records.filter(
                                    (r) => r.stage === 'decision',
                                ).length,
                                title: 'booking decisions',
                                caption: 'Review the vehicle and team',
                                view: 'requests' as const,
                                filter: 'decision',
                            },
                            {
                                icon: CheckCircle2,
                                count: records.filter(
                                    (r) => r.stage === 'returned',
                                ).length,
                                title: 'journeys to finish',
                                caption: 'Final passenger steps due',
                                view: 'journeys' as const,
                                filter: 'returned',
                            },
                        ].map((p) => (
                            // eslint-disable-next-line no-restricted-syntax -- This complete selector card or shared tab uses its canonical layout styles.
                            <button
                                key={p.title}
                                onClick={() => onDrill(p.view, p.filter)}
                            >
                                <p.icon className="size-5 text-primary" />
                                <span>
                                    <strong>
                                        {p.count} {p.title}
                                    </strong>
                                    <small>{p.caption}</small>
                                </span>
                                <ArrowRight className="size-4" />
                            </button>
                        ))}
                    </div>
                    <div className="tr-two-columns">
                        <Panel title="Where each request stands" icon={Route}>
                            <p className="tr-caption">
                                Every request appears once. Select a stage to
                                open its queue.
                            </p>
                            <div className="tr-stage-chart">
                                <div
                                    className="tr-donut"
                                    role="img"
                                    aria-label={stages
                                        .map((s) => `${s.label}: ${s.value}`)
                                        .join('. ')}
                                >
                                    <ResponsiveContainer
                                        width="100%"
                                        height="100%"
                                        initialDimension={{
                                            width: 240,
                                            height: 225,
                                        }}
                                    >
                                        <PieChart>
                                            <Pie
                                                data={stages.filter(
                                                    (s) => s.value,
                                                )}
                                                dataKey="value"
                                                innerRadius="69%"
                                                outerRadius="92%"
                                                paddingAngle={2}
                                                isAnimationActive={false}
                                            >
                                                {stages
                                                    .filter((s) => s.value)
                                                    .map((s) => (
                                                        <Cell
                                                            key={s.key}
                                                            fill={s.color}
                                                        />
                                                    ))}
                                            </Pie>
                                        </PieChart>
                                    </ResponsiveContainer>
                                    <div>
                                        <strong>{records.length}</strong>
                                        <span>requests</span>
                                    </div>
                                </div>
                                <div className="tr-legend">
                                    {stages.map((s) => (
                                        // eslint-disable-next-line no-restricted-syntax -- This complete selector card or shared tab uses its canonical layout styles.
                                        <button
                                            key={s.key}
                                            onClick={() =>
                                                onDrill(
                                                    s.view === 'planner'
                                                        ? 'requests'
                                                        : s.view,
                                                    'all',
                                                    s.stages,
                                                    s.label,
                                                )
                                            }
                                        >
                                            <i
                                                style={{ background: s.color }}
                                            />
                                            <span>{s.label}</span>
                                            <strong>{s.value}</strong>
                                            <ArrowRight className="size-3" />
                                        </button>
                                    ))}
                                </div>
                            </div>
                            <p className="tr-caption flex items-center gap-2">
                                <KeyRound className="size-4" />
                                Journey completion and vehicle return have
                                separate checks.
                            </p>
                        </Panel>
                        <Panel
                            title="Departures & returns by time"
                            icon={BarChart3}
                            action={
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => onDrill('calendar')}
                                >
                                    Calendar
                                    <ArrowRight className="size-4" />
                                </Button>
                            }
                        >
                            <p className="tr-caption">
                                Scheduled movements across the selected dates ·
                                Pacific/Auckland
                            </p>
                            <div className="tr-chart-key">
                                <span>
                                    <i className="bg-chart-1" />
                                    Scheduled departures
                                </span>
                                <span>
                                    <i className="bg-chart-2" />
                                    Expected returns
                                </span>
                            </div>
                            <div
                                className="tr-schedule-chart"
                                role="img"
                                aria-label="Scheduled departures and returns by hour. Exact values are available below."
                            >
                                <ResponsiveContainer
                                    width="100%"
                                    height="100%"
                                    initialDimension={{
                                        width: 450,
                                        height: 240,
                                    }}
                                >
                                    <BarChart
                                        data={schedule}
                                        margin={{
                                            left: -22,
                                            right: 8,
                                            top: 10,
                                        }}
                                        accessibilityLayer
                                    >
                                        <CartesianGrid
                                            vertical={false}
                                            stroke="var(--border)"
                                            strokeDasharray="3 4"
                                        />
                                        <XAxis
                                            dataKey="label"
                                            tick={{
                                                fontSize: 10,
                                                fill: 'var(--muted-foreground)',
                                            }}
                                            interval={2}
                                            axisLine={false}
                                            tickLine={false}
                                        />
                                        <YAxis
                                            allowDecimals={false}
                                            tick={{ fontSize: 11 }}
                                            axisLine={false}
                                            tickLine={false}
                                        />
                                        <Tooltip
                                            contentStyle={{
                                                background: 'var(--card)',
                                                borderColor: 'var(--border)',
                                                borderRadius: 8,
                                            }}
                                        />
                                        <Bar
                                            name="Scheduled departures"
                                            dataKey="departures"
                                            fill="var(--chart-1)"
                                            radius={[4, 4, 0, 0]}
                                            isAnimationActive={false}
                                        />
                                        <Bar
                                            name="Expected returns"
                                            dataKey="returns"
                                            fill="var(--chart-2)"
                                            radius={[4, 4, 0, 0]}
                                            isAnimationActive={false}
                                        />
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                            <details>
                                <summary>
                                    View the numbers and matching trips
                                </summary>
                                <div className="tr-hour-values">
                                    {schedule
                                        .filter(
                                            (h) => h.departures || h.returns,
                                        )
                                        .map((h) => (
                                            // eslint-disable-next-line no-restricted-syntax -- This complete selector card or shared tab uses its canonical layout styles.
                                            <button
                                                key={h.hour}
                                                onClick={() => setHour(h.hour)}
                                            >
                                                {h.label}
                                                <strong>
                                                    {h.departures} out ·{' '}
                                                    {h.returns} back
                                                </strong>
                                            </button>
                                        ))}
                                </div>
                            </details>
                        </Panel>
                    </div>
                    {hour !== null && (
                        <Panel
                            title={`Movements around ${hour % 12 || 12}${hour < 12 ? 'am' : 'pm'}`}
                            icon={Clock}
                            action={
                                <Button
                                    variant="ghost"
                                    onClick={() => setHour(null)}
                                >
                                    Close
                                </Button>
                            }
                        >
                            <ShortRows
                                rows={records.filter(
                                    (r) =>
                                        r.stage !== 'cancelled' &&
                                        !['cancelled', 'rejected'].includes(
                                            r.booking?.status || '',
                                        ) &&
                                        [
                                            r.booking?.start || r.start,
                                            r.booking?.end || r.end,
                                        ].some(
                                            (t) =>
                                                t &&
                                                Number(
                                                    new Intl.DateTimeFormat(
                                                        'en-NZ',
                                                        {
                                                            timeZone:
                                                                'Pacific/Auckland',
                                                            hourCycle: 'h23',
                                                            hour: '2-digit',
                                                        },
                                                    ).format(new Date(t)),
                                                ) === hour,
                                        ),
                                )}
                                onOpen={onOpen}
                            />
                        </Panel>
                    )}
                </>
            )}
            {(section === 'summary' || section === 'attention') && (
                <Panel
                    title={
                        section === 'summary'
                            ? 'Act next'
                            : 'Work needing attention'
                    }
                    icon={Flag}
                >
                    <p className="tr-caption">
                        Outstanding returns first, then decisions and planning.
                    </p>
                    <div className="tr-attention">
                        {(section === 'summary' ? rows.slice(0, 5) : rows).map(
                            (r) => (
                                // eslint-disable-next-line no-restricted-syntax -- This complete selector card or shared tab uses its canonical layout styles.
                                <button key={r.id} onClick={() => onOpen(r)}>
                                    <span className="tr-action-icon">
                                        <Flag className="size-4" />
                                    </span>
                                    <span>
                                        <strong>
                                            {!['not_due', 'complete'].includes(
                                                r.return_stage,
                                            )
                                                ? RETURN_LABELS[r.return_stage]
                                                : r.next_action}
                                        </strong>
                                        <small>
                                            {r.person} · {r.site.name}
                                        </small>
                                    </span>
                                    <span>
                                        <small>Next person</small>
                                        <strong>
                                            {!['not_due', 'complete'].includes(
                                                r.return_stage,
                                            )
                                                ? r.return_next_owner
                                                : r.next_owner}
                                        </strong>
                                    </span>
                                    <ArrowRight className="size-4" />
                                </button>
                            ),
                        )}
                        {!rows.length && (
                            <p className="tr-empty">
                                No outstanding actions in this scope.
                            </p>
                        )}
                    </div>
                </Panel>
            )}
            {section === 'departures' && (
                <Panel title="Departure board" icon={CalendarDays}>
                    <ShortRows
                        rows={records.filter(
                            (r) =>
                                !r.journey &&
                                !['completed', 'cancelled'].includes(r.stage),
                        )}
                        onOpen={onOpen}
                    />
                </Panel>
            )}
        </>
    );
}
