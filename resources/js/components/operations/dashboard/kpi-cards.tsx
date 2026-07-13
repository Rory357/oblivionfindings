import { Activity, Clock, MousePointer2, ShieldCheck, Timer, TrendingUp, Users } from 'lucide-react';

import { cn } from '@/lib/utils';

import { ContextMenu, useContextMenu, type CtxMenuDef } from './context-menu';

type CardWrapProps = {
    onContextMenu: (e: React.MouseEvent) => void;
    children: React.ReactNode;
    title: string;
    Icon: React.ComponentType<{ className?: string }>;
    iconClass?: string;
};

function KpiCardShell({ onContextMenu, children, title, Icon, iconClass }: CardWrapProps) {
    return (
        <a
            href="#"
            role="button"
            onClick={(e) => e.preventDefault()}
            onContextMenu={onContextMenu}
            className={cn(
                'block rounded-xl border bg-card p-3.5 transition-all duration-200',
                'hover:-translate-y-px hover:border-primary/40 hover:shadow-[0_6px_24px_-10px_rgba(76,29,149,.18)]',
            )}
            style={{ borderColor: 'var(--border)' }}
        >
            <div className="flex items-center justify-between">
                <div className="text-[11.5px] font-medium text-muted-foreground">{title}</div>
                <Icon className={cn('h-3.5 w-3.5 text-primary', iconClass)} />
            </div>
            {children}
        </a>
    );
}

const MENUS: Record<string, CtxMenuDef> = {
    'active-clients': {
        label: 'Active clients',
        items: [
            { icon: 'users', text: 'View all clients', shortcut: '↵', href: '/operations/clients' },
            { icon: 'user-plus', text: 'Add new client', shortcut: '⌘N', href: '/operations/clients/create' },
            { icon: 'user-check', text: 'Onboarding queue', href: '/operations/clients?status=onboarding' },
            { icon: 'pie-chart', text: 'Status breakdown' },
            { divider: true },
            { icon: 'bar-chart-3', text: 'Compare to last month' },
            { icon: 'calendar', text: 'View intake trends (12 wk)' },
            { divider: true },
            { icon: 'download', text: 'Export client list', shortcut: '⌘E' },
            { icon: 'pin', text: 'Pin to favourites' },
            { icon: 'settings-2', text: 'Configure widget' },
        ],
    },
    'hours-week': {
        label: 'Hours this week',
        items: [
            { icon: 'clock', text: 'View hours breakdown' },
            { icon: 'building-2', text: 'By site' },
            { icon: 'users', text: 'By staff member' },
            { icon: 'user', text: 'By client' },
            { divider: true },
            { icon: 'bar-chart-3', text: 'Compare to last week' },
            { icon: 'target', text: 'Set hours target…' },
            { icon: 'alert-triangle', text: 'View overtime alerts (3)' },
            { divider: true },
            { icon: 'download', text: 'Export hours report', shortcut: '⌘E' },
            { icon: 'pin', text: 'Pin to favourites' },
            { icon: 'settings-2', text: 'Configure widget' },
        ],
    },
    'clock-in': {
        label: 'Clock-in adherence',
        items: [
            { icon: 'timer', text: 'View today’s clock-ins' },
            { icon: 'alert-triangle', text: 'View late starts' },
            { icon: 'user-x', text: 'View no-shows', muted: true },
            { divider: true },
            { icon: 'send', text: 'Send reminder to late staff' },
            { icon: 'message-square', text: 'Message all on-shift' },
            { divider: true },
            { icon: 'sliders-horizontal', text: 'Adjust grace period…' },
            { icon: 'file-text', text: 'Open clock-in policy' },
            { divider: true },
            { icon: 'download', text: 'Export adherence report', shortcut: '⌘E' },
            { icon: 'settings-2', text: 'Configure widget' },
        ],
    },
    compliance: {
        label: 'Staff compliance',
        items: [
            { icon: 'shield-check', text: 'View compliance matrix' },
            { icon: 'alert-triangle', text: 'View expiring' },
            { icon: 'shield-alert', text: 'View expired', danger: true },
            { divider: true },
            { icon: 'send', text: 'Send renewal reminders' },
            { icon: 'graduation-cap', text: 'Assign training' },
            { icon: 'calendar-plus', text: 'Schedule audit' },
            { divider: true },
            { icon: 'target', text: 'Set compliance target…' },
            { icon: 'file-spreadsheet', text: 'Export for regulator', shortcut: '⌘E' },
            { divider: true },
            { icon: 'pin', text: 'Pin to favourites' },
            { icon: 'settings-2', text: 'Configure widget' },
        ],
    },
};

type Props = {
    metrics: {
        active_clients: { value: number; delta: number; new_mtd: number; onboarding: number; trend_12wk: number[] };
        hours_week: {
            value: number;
            delta_pct: number;
            prev_value: number;
            sparkline: number[];
            avg_shift: number;
            overtime_alerts: number;
        };
        clock_in: {
            adherence_pct: number;
            on_time: number;
            late: number;
            no_show: number;
            avg_late_sec: number;
            delta_pp: number;
        };
        compliance: {
            pct: number;
            current: number;
            expiring_30d: number;
            expired: number;
            target_pct: number;
            current_pct: number;
            expiring_pct: number;
            expired_pct: number;
        };
    };
};

export function KpiCards({ metrics }: Props) {
    const ctx = useContextMenu();

    // Active clients — 12-wk bars
    const trend = metrics.active_clients.trend_12wk.length > 0 ? metrics.active_clients.trend_12wk : new Array(12).fill(0);
    const trendMax = Math.max(...trend, 1);

    // Hours sparkline points
    const spark = metrics.hours_week.sparkline.length > 0 ? metrics.hours_week.sparkline : new Array(12).fill(0);
    const sparkMax = Math.max(...spark, 1);
    const sparkPts = spark.map((v, i) => {
        const x = (i / (spark.length - 1 || 1)) * 100;
        const y = 22 - (v / sparkMax) * 19;
        return `${x.toFixed(0)},${y.toFixed(1)}`;
    });
    const lineStr = sparkPts.join(' ');
    const fillStr = `M${sparkPts[0] ?? '0,22'} ${sparkPts.slice(1).map((p) => `L${p}`).join(' ')} L100,28 L0,28 Z`;
    const lastX = 100;
    const lastY = sparkMax > 0 ? 22 - ((spark[spark.length - 1] ?? 0) / sparkMax) * 19 : 22;

    const clockTotal = Math.max(1, metrics.clock_in.on_time + metrics.clock_in.late + metrics.clock_in.no_show);
    const onTimePct = (metrics.clock_in.on_time / clockTotal) * 100;
    const latePct = (metrics.clock_in.late / clockTotal) * 100;
    const noShowPct = (metrics.clock_in.no_show / clockTotal) * 100;

    return (
        <section>
            <div className="mb-2 flex items-center justify-between">
                <div className="flex items-center gap-2">
                    <Activity className="h-4 w-4 text-muted-foreground" />
                    <h2 className="text-[13px] font-semibold uppercase tracking-wider text-muted-foreground">
                        Operational metrics
                    </h2>
                </div>
                <div className="flex items-center gap-2 text-[11px] text-muted-foreground">
                    <span className="inline-flex items-center gap-1">
                                        <span className="h-1.5 w-1.5 rounded-full bg-status-success" /> Live
                    </span>
                    <span>·</span>
                    <span>vs last week</span>
                    <span className="hidden md:inline">·</span>
                    <span className="hidden items-center gap-1 md:inline-flex">
                        <MousePointer2 className="h-3 w-3" /> right-click for actions
                    </span>
                </div>
            </div>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {/* Active clients */}
                <KpiCardShell
                    title="Active clients"
                    Icon={Users}
                    onContextMenu={ctx.onContextMenu(MENUS['active-clients'])}
                >
                    <div className="mt-1 flex items-baseline gap-1.5">
                        <div className="text-2xl font-bold tabular-nums">{metrics.active_clients.value}</div>
                        <div
                            className="inline-flex items-center text-[11px] font-medium"
                            style={{ color: 'var(--status-success)' }}
                        >
                            <TrendingUp className="mr-0.5 h-3 w-3" />+{metrics.active_clients.delta}
                        </div>
                    </div>
                    <div className="mt-2 flex h-7 items-end gap-[2px]">
                        {trend.map((v, i) => {
                            const pct = 25 + (i / (trend.length - 1 || 1)) * 75;
                            return (
                                <div
                                    key={i}
                                    className="flex-1 rounded-sm"
                                    style={{
                                        height: `${Math.max(15, (v / trendMax) * 100)}%`,
                                        background: `color-mix(in oklch, var(--primary) ${pct}%, transparent)`,
                                    }}
                                />
                            );
                        })}
                    </div>
                    <div className="mt-1.5 flex items-center justify-between text-[10.5px] text-muted-foreground">
                        <span>12-wk · {metrics.active_clients.new_mtd} new MTD</span>
                        <span className="tabular-nums">{metrics.active_clients.onboarding} onboarding</span>
                    </div>
                </KpiCardShell>

                {/* Hours this week */}
                <KpiCardShell
                    title="Hours this week"
                    Icon={Clock}
                    onContextMenu={ctx.onContextMenu(MENUS['hours-week'])}
                >
                    <div className="mt-1 flex items-baseline gap-1.5">
                        <div className="text-2xl font-bold tabular-nums">
                            {metrics.hours_week.value.toLocaleString()}
                        </div>
                        <div
                            className="inline-flex items-center text-[11px] font-medium"
                            style={{ color: 'var(--status-success)' }}
                        >
                            <TrendingUp className="mr-0.5 h-3 w-3" />
                            {metrics.hours_week.delta_pct >= 0 ? '+' : ''}
                            {metrics.hours_week.delta_pct}%
                        </div>
                    </div>
                    <svg viewBox="0 0 100 28" className="mt-2 h-7 w-full" preserveAspectRatio="none">
                        <defs>
                            <linearGradient id="sparkFill" x1="0" x2="0" y1="0" y2="1">
                                <stop offset="0%" stopColor="var(--primary)" stopOpacity="0.25" />
                                <stop offset="100%" stopColor="var(--primary)" stopOpacity="0" />
                            </linearGradient>
                        </defs>
                        <path d={fillStr} fill="url(#sparkFill)" />
                        <polyline
                            fill="none"
                            stroke="var(--primary)"
                            strokeWidth="1.5"
                            strokeLinejoin="round"
                            strokeLinecap="round"
                            points={lineStr}
                        />
                        <circle cx={lastX} cy={lastY} r="1.6" fill="var(--primary)" />
                    </svg>
                    <div className="mt-1.5 flex items-center justify-between text-[10.5px] text-muted-foreground">
                        <span>vs {metrics.hours_week.prev_value.toLocaleString()} last wk</span>
                        <span className="tabular-nums">avg {metrics.hours_week.avg_shift}h/shift</span>
                    </div>
                </KpiCardShell>

                {/* Clock-in adherence */}
                <KpiCardShell
                    title="Clock-in adherence"
                    Icon={Timer}
                    onContextMenu={ctx.onContextMenu(MENUS['clock-in'])}
                >
                    <div className="mt-1 flex items-center gap-3">
                        <svg viewBox="0 0 36 36" className="h-14 w-14 shrink-0">
                            <circle cx="18" cy="18" r="15.9155" fill="none" stroke="var(--muted)" strokeWidth="3.5" />
                            <circle
                                cx="18"
                                cy="18"
                                r="15.9155"
                                fill="none"
                                stroke="var(--status-success)"
                                strokeWidth="3.5"
                                strokeDasharray={`${metrics.clock_in.adherence_pct} ${100 - metrics.clock_in.adherence_pct}`}
                                strokeDashoffset="25"
                                transform="rotate(-90 18 18)"
                                strokeLinecap="round"
                            />
                            <text
                                x="18"
                                y="18"
                                textAnchor="middle"
                                dominantBaseline="central"
                                fontSize="9"
                                fontWeight="700"
                                fill="var(--foreground)"
                            >
                                {metrics.clock_in.adherence_pct}
                                <tspan fontSize="5">%</tspan>
                            </text>
                        </svg>
                        <div className="min-w-0 flex-1 space-y-1.5">
                            <div className="flex items-center gap-1.5">
                                <span className="w-12 shrink-0 text-[9.5px] text-muted-foreground">On time</span>
                                <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                                    <div
                                        className="h-full rounded-full"
                                        style={{ width: `${onTimePct}%`, background: 'var(--status-success)' }}
                                    />
                                </div>
                                <span className="text-[10px] font-semibold tabular-nums">{metrics.clock_in.on_time}</span>
                            </div>
                            <div className="flex items-center gap-1.5">
                                <span className="w-12 shrink-0 text-[9.5px] text-muted-foreground">Late</span>
                                <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                                    <div
                                        className="h-full rounded-full"
                                        style={{ width: `${latePct}%`, background: 'var(--status-warning)' }}
                                    />
                                </div>
                                <span className="text-[10px] font-semibold tabular-nums">{metrics.clock_in.late}</span>
                            </div>
                            <div className="flex items-center gap-1.5">
                                <span className="w-12 shrink-0 text-[9.5px] text-muted-foreground">No show</span>
                                <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                                    <div
                                        className="h-full rounded-full"
                                        style={{ width: `${noShowPct}%`, background: 'var(--status-critical)' }}
                                    />
                                </div>
                                <span className="text-[10px] font-semibold tabular-nums">{metrics.clock_in.no_show}</span>
                            </div>
                        </div>
                    </div>
                    <div className="mt-2 flex items-center justify-between text-[10.5px] text-muted-foreground">
                        <span>
                            Avg {Math.floor(metrics.clock_in.avg_late_sec / 60)}m {metrics.clock_in.avg_late_sec % 60}s late
                        </span>
                        <span
                            className="inline-flex items-center gap-0.5"
                            style={{ color: 'var(--status-success)' }}
                        >
                            <TrendingUp className="h-2.5 w-2.5" />+{metrics.clock_in.delta_pp}pp
                        </span>
                    </div>
                </KpiCardShell>

                {/* Staff compliance */}
                <KpiCardShell
                    title="Staff compliance"
                    Icon={ShieldCheck}
                    iconClass="text-[color:var(--status-warning)]"
                    onContextMenu={ctx.onContextMenu(MENUS.compliance)}
                >
                    <div className="mt-1 flex items-baseline gap-1.5">
                        <div className="text-2xl font-bold tabular-nums">
                            {metrics.compliance.pct}
                            <span className="text-base">%</span>
                        </div>
                        <div
                            className="text-[11px] font-medium"
                            style={{ color: 'var(--status-warning)' }}
                        >
                            {metrics.compliance.expiring_30d} expiring 30d
                        </div>
                    </div>
                    <div className="relative mt-2">
                        <div className="relative flex h-3 overflow-hidden rounded-md bg-muted">
                            <div
                                className="h-full"
                                style={{
                                    width: `${metrics.compliance.current_pct}%`,
                                    background: 'var(--status-success)',
                                }}
                                title="Current"
                            />
                            <div
                                className="h-full"
                                style={{
                                    width: `${metrics.compliance.expiring_pct}%`,
                                    background: 'var(--status-warning)',
                                }}
                                title="Expiring"
                            />
                            <div
                                className="h-full"
                                style={{
                                    width: `${metrics.compliance.expired_pct}%`,
                                    background: 'var(--status-critical)',
                                }}
                                title="Expired"
                            />
                        </div>
                        <div
                            className="absolute -top-0.5 h-4 w-0.5 bg-foreground"
                            style={{ left: `${metrics.compliance.target_pct}%` }}
                        />
                        <div
                            className="absolute -top-3 -translate-x-1/2 text-[8.5px] font-bold tabular-nums"
                            style={{ left: `${metrics.compliance.target_pct}%` }}
                        >
                            {metrics.compliance.target_pct}
                        </div>
                    </div>
                    <div className="mt-2 flex items-center justify-between text-[10px] text-muted-foreground">
                        <span className="inline-flex items-center gap-1">
                            <span
                                className="h-1.5 w-1.5 rounded-full"
                                style={{ background: 'var(--status-success)' }}
                            />
                            Current {metrics.compliance.current}
                        </span>
                        <span className="inline-flex items-center gap-1">
                            <span
                                className="h-1.5 w-1.5 rounded-full"
                                style={{ background: 'var(--status-warning)' }}
                            />
                            Expiring {metrics.compliance.expiring_30d}
                        </span>
                        <span className="inline-flex items-center gap-1">
                            <span
                                className="h-1.5 w-1.5 rounded-full"
                                style={{ background: 'var(--status-critical)' }}
                            />
                            Expired {metrics.compliance.expired}
                        </span>
                    </div>
                </KpiCardShell>
            </div>
            <ContextMenu open={ctx.state.open} x={ctx.state.x} y={ctx.state.y} menu={ctx.state.menu} onClose={ctx.close} />
        </section>
    );
}
