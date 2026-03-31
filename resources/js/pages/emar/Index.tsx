import { BarChart, DonutChart, OPS_COLORS, OpsStatCard } from '@/components/ops-stat-card';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    AlertCircle,
    AlertTriangle,
    ArrowRightLeft,
    ArrowRight,
    Award,
    Bell,
    CheckCircle,
    ClipboardCheck,
    Clock,
    FileText,
    Info,
    Lock,
    Package,
    Pill,
    Play,
    Shield,
    Syringe,
    TrendingUp,
    User,
    Users,
    XCircle,
} from 'lucide-react';
import {
    Area,
    AreaChart,
    CartesianGrid,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

type Stats = {
    totalToday: number;
    givenToday: number;
    refusedToday: number;
    withheldToday: number;
    missedToday: number;
    pendingToday: number;
    adminRate: number;
    prnToday: number;
    prnNearLimit: number;
    controlledCount: number;
    activeDiscrepancies: number;
    overdueReviews: number;
    expiringCompetencies: number;
    activeAlerts: number;
    lowStock: number;
    activeMedications: number;
    activeClients: number;
    roundsToday: number;
    roundsCompleted: number;
    givenTrend: number[];
};

type TrendDay = {
    date: string;
    given: number;
    refused: number;
    missed: number;
    total: number;
};

type OverdueMedication = {
    id: number;
    scheduled_for: string;
    client: { id: number; first_name: string; last_name: string } | null;
    medication: { id: number; name: string; dosage: string } | null;
};

type NextRound = {
    id: number;
    name: string;
    scheduled_time: string;
    round_date: string;
    assigned_to: { id: number; name: string } | null;
} | null;

type ClientStatus = {
    id: number;
    first_name: string;
    last_name: string;
    active_medications_count: number;
    given_today: number;
    pending_today: number;
    missed_today: number;
};

type RecentActivityItem = {
    id: number;
    status: string;
    administered_at: string | null;
    scheduled_for: string | null;
    client: { id: number; first_name: string; last_name: string } | null;
    medication: { id: number; name: string } | null;
    administered_by: { id: number; name: string } | null;
};

type DashboardAlert = {
    id: number;
    alert_type: string;
    severity: string;
    message: string;
    created_at: string;
    client: { id: number; first_name: string; last_name: string } | null;
    medication: { id: number; name: string } | null;
};

type Compliance = {
    competencyExpiring: number;
    competencyExpired: number;
    pendingReviews: number;
    overdueReviews: number;
};

type Props = {
    stats: Stats;
    trend: TrendDay[];
    overdueMedications: OverdueMedication[];
    nextRound: NextRound;
    clientStatuses: ClientStatus[];
    recentActivity: RecentActivityItem[];
    activeAlertsList: DashboardAlert[];
    compliance: Compliance;
};

function AlertCard({ icon: Icon, title, count, color, href }: { icon: any; title: string; count: number; color: string; href: string }) {
    if (count === 0) return null;
    return (
        <Link href={href} className="block">
            <Card className={`border-${color}-200 dark:border-${color}-800 transition-all hover:shadow-md hover:-translate-y-0.5`}>
                <CardContent className="flex items-center gap-3 p-3">
                    <div className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-${color}-100 dark:bg-${color}-900/40`}>
                        <Icon className={`h-4 w-4 text-${color}-600`} />
                    </div>
                    <div className="min-w-0 flex-1">
                        <p className="text-sm font-medium">{title}</p>
                    </div>
                    <Badge variant="destructive" className="text-xs">{count}</Badge>
                </CardContent>
            </Card>
        </Link>
    );
}

function formatTime(dateStr: string | null): string {
    if (!dateStr) return '--:--';
    const d = new Date(dateStr);
    return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function formatTimeAgo(dateStr: string | null): string {
    if (!dateStr) return '';
    const now = new Date();
    const then = new Date(dateStr);
    const diffMs = now.getTime() - then.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    const diffHrs = Math.floor(diffMins / 60);
    if (diffHrs < 24) return `${diffHrs}h ago`;
    return `${Math.floor(diffHrs / 24)}d ago`;
}

const severityIcon: Record<string, typeof Info> = {
    info: Info,
    warning: AlertTriangle,
    critical: AlertCircle,
};

const severityColor: Record<string, string> = {
    info: 'text-blue-500',
    warning: 'text-amber-500',
    critical: 'text-red-500',
};

const statusIcon: Record<string, typeof CheckCircle> = {
    given: CheckCircle,
    refused: XCircle,
    missed: AlertCircle,
    withheld: Shield,
    pending: Clock,
};

const statusColor: Record<string, string> = {
    given: 'text-emerald-500',
    refused: 'text-amber-500',
    missed: 'text-red-500',
    withheld: 'text-slate-500',
    pending: 'text-blue-500',
};

export default function EmarDashboard({ stats, trend, overdueMedications, nextRound, clientStatuses, recentActivity, activeAlertsList, compliance }: Props) {
    const donutSegments = [
        { label: 'Given', value: stats.givenToday, color: OPS_COLORS.success },
        { label: 'Refused', value: stats.refusedToday, color: OPS_COLORS.warning },
        { label: 'Withheld', value: stats.withheldToday, color: OPS_COLORS.neutral },
        { label: 'Missed', value: stats.missedToday, color: OPS_COLORS.danger },
        { label: 'Pending', value: stats.pendingToday, color: '#cbd5e1' },
    ];

    const barData = trend.map((d) => ({ label: d.date, value: d.given }));

    const totalAlerts = stats.activeDiscrepancies + stats.overdueReviews + stats.expiringCompetencies + stats.lowStock;

    return (
        <AppLayout>
            <Head title="eMAR Dashboard" />
            <PageHeader
                title="eMAR Dashboard"
                description="Electronic Medication Administration — real-time overview."
            />
            <PageShell>
                {/* ── KPI Cards ─────────────────────────────────────── */}
                <div className="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                    <OpsStatCard
                        label="Admin Rate"
                        value={`${stats.adminRate}%`}
                        icon={TrendingUp}
                        color="emerald"
                        subtitle={`${stats.givenToday} of ${stats.totalToday} today`}
                        trend={stats.givenTrend}
                    />
                    <OpsStatCard
                        label="Active Clients"
                        value={stats.activeClients}
                        icon={Users}
                        color="indigo"
                        subtitle={`${stats.activeMedications} active medications`}
                    />
                    <OpsStatCard
                        label="PRN Given Today"
                        value={stats.prnToday}
                        icon={Activity}
                        color="violet"
                        subtitle={stats.prnNearLimit > 0 ? `${stats.prnNearLimit} near limit` : 'No limits reached'}
                    />
                    <OpsStatCard
                        label="Controlled Drugs"
                        value={stats.controlledCount}
                        icon={Lock}
                        color="red"
                        subtitle={stats.activeDiscrepancies > 0 ? `${stats.activeDiscrepancies} discrepancies` : 'No discrepancies'}
                    />
                    <OpsStatCard
                        label="Rounds Today"
                        value={stats.roundsToday}
                        icon={Clock}
                        color="blue"
                        subtitle={`${stats.roundsCompleted} completed`}
                    />
                    <OpsStatCard
                        label="Active Alerts"
                        value={stats.activeAlerts + totalAlerts}
                        icon={AlertTriangle}
                        color={totalAlerts > 0 ? 'amber' : 'slate'}
                        subtitle={totalAlerts > 0 ? 'Attention needed' : 'All clear'}
                    />
                </div>

                {/* ── Overdue Medications Alert + Upcoming Round ────── */}
                <div className="mb-6 grid gap-4 lg:grid-cols-2">
                    {overdueMedications.length > 0 && (
                        <Card className="border-red-300 dark:border-red-800">
                            <CardHeader className="pb-2">
                                <CardTitle className="flex items-center gap-2 text-sm font-medium text-red-700 dark:text-red-400">
                                    <AlertCircle className="h-4 w-4" />
                                    Overdue Medications ({overdueMedications.length})
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                {overdueMedications.map((item) => (
                                    <div key={item.id} className="flex items-center justify-between rounded-lg border border-red-100 bg-red-50/50 p-2.5 dark:border-red-900/40 dark:bg-red-950/20">
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm font-medium">
                                                {item.client ? `${item.client.first_name} ${item.client.last_name}` : 'Unknown Client'}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {item.medication?.name} {item.medication?.dosage ? `(${item.medication.dosage})` : ''} — due at {formatTime(item.scheduled_for)}
                                            </p>
                                        </div>
                                        <Link href={`/emar/mar?client_id=${item.client?.id ?? ''}`}>
                                            <Button size="sm" variant="destructive" className="ml-2 h-7 text-xs">
                                                Record Now
                                            </Button>
                                        </Link>
                                    </div>
                                ))}
                            </CardContent>
                        </Card>
                    )}

                    <Card className={overdueMedications.length === 0 ? 'lg:col-span-2 max-w-md' : ''}>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                <Play className="h-4 w-4 text-blue-500" />
                                Upcoming Round
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {nextRound ? (
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="text-sm font-medium">{nextRound.name}</p>
                                        <p className="text-xs text-muted-foreground">
                                            Scheduled for {nextRound.scheduled_time}
                                            {nextRound.assigned_to ? ` — assigned to ${nextRound.assigned_to.name}` : ''}
                                        </p>
                                    </div>
                                    <Button
                                        size="sm"
                                        className="ml-2"
                                        onClick={() => router.post(`/emar/rounds/${nextRound.id}/start`)}
                                    >
                                        Start Round
                                    </Button>
                                </div>
                            ) : (
                                <div className="flex flex-col items-center py-4 text-center">
                                    <CheckCircle className="mb-2 h-6 w-6 text-emerald-500" />
                                    <p className="text-sm text-muted-foreground">No rounds pending today</p>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* ── Charts Row ─────────────────────────────────────── */}
                <div className="mb-6 grid gap-4 lg:grid-cols-3">
                    {/* 7-Day Trend Area Chart */}
                    <Card className="lg:col-span-2">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">7-Day Administration Trend</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ResponsiveContainer width="100%" height={220}>
                                <AreaChart data={trend} margin={{ top: 5, right: 10, left: -20, bottom: 0 }}>
                                    <defs>
                                        <linearGradient id="gradGiven" x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="5%" stopColor={OPS_COLORS.success} stopOpacity={0.3} />
                                            <stop offset="95%" stopColor={OPS_COLORS.success} stopOpacity={0} />
                                        </linearGradient>
                                        <linearGradient id="gradMissed" x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="5%" stopColor={OPS_COLORS.danger} stopOpacity={0.3} />
                                            <stop offset="95%" stopColor={OPS_COLORS.danger} stopOpacity={0} />
                                        </linearGradient>
                                    </defs>
                                    <CartesianGrid strokeDasharray="3 3" className="stroke-muted/30" />
                                    <XAxis dataKey="date" tick={{ fontSize: 11 }} className="text-muted-foreground" />
                                    <YAxis tick={{ fontSize: 11 }} className="text-muted-foreground" />
                                    <Tooltip
                                        contentStyle={{ borderRadius: 8, fontSize: 12, border: '1px solid hsl(var(--border))' }}
                                        labelStyle={{ fontWeight: 600 }}
                                    />
                                    <Area type="monotone" dataKey="given" stroke={OPS_COLORS.success} fill="url(#gradGiven)" strokeWidth={2} name="Given" />
                                    <Area type="monotone" dataKey="refused" stroke={OPS_COLORS.warning} fill="none" strokeWidth={1.5} strokeDasharray="4 4" name="Refused" />
                                    <Area type="monotone" dataKey="missed" stroke={OPS_COLORS.danger} fill="url(#gradMissed)" strokeWidth={1.5} name="Missed" />
                                </AreaChart>
                            </ResponsiveContainer>
                        </CardContent>
                    </Card>

                    {/* Today's Outcomes Donut */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Today's Outcomes</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col items-center">
                            <DonutChart
                                segments={donutSegments}
                                size={160}
                                strokeWidth={20}
                                centerValue={stats.totalToday}
                                centerLabel="Total"
                            />
                            <div className="mt-4 grid w-full grid-cols-2 gap-x-4 gap-y-1 text-xs">
                                {donutSegments.filter(s => s.value > 0).map((s) => (
                                    <div key={s.label} className="flex items-center gap-2">
                                        <span className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: s.color }} />
                                        <span className="text-muted-foreground">{s.label}</span>
                                        <span className="ml-auto font-medium">{s.value}</span>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* ── Bar Chart + Alerts Row ─────────────────────────── */}
                <div className="mb-6 grid gap-4 lg:grid-cols-2">
                    {/* Daily Given Bar Chart */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Doses Given (Last 7 Days)</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <BarChart data={barData} height={140} barColor={OPS_COLORS.primary} />
                        </CardContent>
                    </Card>

                    {/* Alerts & Attention */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                <AlertTriangle className="h-4 w-4 text-amber-500" />
                                Needs Attention
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            <AlertCard icon={Shield} title="CD Discrepancies" count={stats.activeDiscrepancies} color="red" href="/emar/controlled" />
                            <AlertCard icon={Clock} title="Overdue Reviews" count={stats.overdueReviews} color="amber" href="/emar/reviews" />
                            <AlertCard icon={Award} title="Expiring Competencies" count={stats.expiringCompetencies} color="orange" href="/emar/competency" />
                            <AlertCard icon={Package} title="Low Stock Items" count={stats.lowStock} color="cyan" href="/emar/stock" />
                            {stats.prnNearLimit > 0 && (
                                <AlertCard icon={Activity} title="PRN Near Daily Limit" count={stats.prnNearLimit} color="violet" href="/emar/prn" />
                            )}
                            {totalAlerts === 0 && stats.prnNearLimit === 0 && (
                                <div className="flex flex-col items-center py-6 text-center">
                                    <CheckCircle className="mb-2 h-8 w-8 text-emerald-500" />
                                    <p className="text-sm font-medium text-emerald-700 dark:text-emerald-400">All Clear</p>
                                    <p className="text-xs text-muted-foreground">No outstanding issues.</p>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* ── Client Status Grid ───────────────────────────── */}
                {clientStatuses.length > 0 && (
                    <Card className="mb-6">
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                <Users className="h-4 w-4 text-indigo-500" />
                                Client Status — Today
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {clientStatuses.map((cs) => {
                                    const allDone = cs.pending_today === 0 && cs.missed_today === 0 && cs.given_today > 0;
                                    const hasMissed = cs.missed_today > 0;
                                    const borderClass = hasMissed
                                        ? 'border-red-300 dark:border-red-800'
                                        : allDone
                                          ? 'border-emerald-300 dark:border-emerald-800'
                                          : 'border-amber-300 dark:border-amber-800';
                                    return (
                                        <Link
                                            key={cs.id}
                                            href={`/emar/mar?client_id=${cs.id}`}
                                            className={`group block rounded-lg border p-3 transition-all hover:shadow-md hover:-translate-y-0.5 ${borderClass}`}
                                        >
                                            <div className="flex items-center gap-2 mb-2">
                                                <div className="flex h-7 w-7 items-center justify-center rounded-full bg-indigo-100 dark:bg-indigo-900/40">
                                                    <User className="h-3.5 w-3.5 text-indigo-600" />
                                                </div>
                                                <p className="text-sm font-medium truncate">{cs.first_name} {cs.last_name}</p>
                                            </div>
                                            <div className="flex items-center gap-1.5 text-xs">
                                                <Badge variant="secondary" className="text-[10px] px-1.5 py-0">
                                                    {cs.active_medications_count} meds
                                                </Badge>
                                                {cs.given_today > 0 && (
                                                    <Badge className="bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300 text-[10px] px-1.5 py-0">
                                                        {cs.given_today} given
                                                    </Badge>
                                                )}
                                                {cs.pending_today > 0 && (
                                                    <Badge className="bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300 text-[10px] px-1.5 py-0">
                                                        {cs.pending_today} pending
                                                    </Badge>
                                                )}
                                                {cs.missed_today > 0 && (
                                                    <Badge className="bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300 text-[10px] px-1.5 py-0">
                                                        {cs.missed_today} missed
                                                    </Badge>
                                                )}
                                            </div>
                                        </Link>
                                    );
                                })}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* ── Active Alerts Panel + Recent Activity Feed ────── */}
                <div className="mb-6 grid gap-4 lg:grid-cols-2">
                    {/* Active Alerts Panel */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                <Bell className="h-4 w-4 text-amber-500" />
                                Active Alerts
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {activeAlertsList.length > 0 ? (
                                <div className="space-y-2">
                                    {activeAlertsList.map((alert) => {
                                        const SeverityIcon = severityIcon[alert.severity] ?? Info;
                                        const iconColor = severityColor[alert.severity] ?? 'text-slate-500';
                                        return (
                                            <div key={alert.id} className="flex items-start gap-3 rounded-lg border p-2.5">
                                                <SeverityIcon className={`mt-0.5 h-4 w-4 shrink-0 ${iconColor}`} />
                                                <div className="min-w-0 flex-1">
                                                    <p className="text-sm font-medium">
                                                        {alert.client ? `${alert.client.first_name} ${alert.client.last_name}` : ''}
                                                        {alert.medication ? ` — ${alert.medication.name}` : ''}
                                                    </p>
                                                    <p className="text-xs text-muted-foreground">{alert.message}</p>
                                                    <p className="mt-0.5 text-[10px] text-muted-foreground/60">{formatTimeAgo(alert.created_at)}</p>
                                                </div>
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    className="h-7 text-xs"
                                                    onClick={() => router.post(`/emar/alerts/${alert.id}/dismiss`)}
                                                >
                                                    Dismiss
                                                </Button>
                                            </div>
                                        );
                                    })}
                                </div>
                            ) : (
                                <div className="flex flex-col items-center py-6 text-center">
                                    <CheckCircle className="mb-2 h-6 w-6 text-emerald-500" />
                                    <p className="text-sm text-muted-foreground">No active alerts</p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Recent Activity Feed */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm font-medium">
                                <Activity className="h-4 w-4 text-blue-500" />
                                Recent Activity
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {recentActivity.length > 0 ? (
                                <div className="max-h-[320px] space-y-1.5 overflow-y-auto pr-1">
                                    {recentActivity.map((entry) => {
                                        const StatusIcon = statusIcon[entry.status] ?? Clock;
                                        const iconClr = statusColor[entry.status] ?? 'text-slate-400';
                                        return (
                                            <div key={entry.id} className="flex items-center gap-2.5 rounded-md px-2 py-1.5 hover:bg-muted/50">
                                                <StatusIcon className={`h-3.5 w-3.5 shrink-0 ${iconClr}`} />
                                                <div className="min-w-0 flex-1 text-xs">
                                                    <span className="font-medium">{entry.medication?.name ?? 'Unknown'}</span>
                                                    <span className="text-muted-foreground"> for </span>
                                                    <span className="font-medium">
                                                        {entry.client ? `${entry.client.first_name} ${entry.client.last_name}` : 'Unknown'}
                                                    </span>
                                                    {entry.administered_by && (
                                                        <span className="text-muted-foreground"> by {entry.administered_by.name}</span>
                                                    )}
                                                </div>
                                                <span className="shrink-0 text-[10px] text-muted-foreground/60">
                                                    {formatTimeAgo(entry.administered_at ?? entry.scheduled_for)}
                                                </span>
                                            </div>
                                        );
                                    })}
                                </div>
                            ) : (
                                <div className="flex flex-col items-center py-6 text-center">
                                    <Clock className="mb-2 h-6 w-6 text-slate-400" />
                                    <p className="text-sm text-muted-foreground">No recent activity</p>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* ── Compliance Snapshot ──────────────────────────── */}
                <Card className="mb-6">
                    <CardHeader className="pb-2">
                        <CardTitle className="flex items-center gap-2 text-sm font-medium">
                            <ClipboardCheck className="h-4 w-4 text-indigo-500" />
                            Compliance Snapshot
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <Link href="/emar/competency" className="group block">
                                <div className="rounded-lg border border-amber-200 bg-amber-50/50 p-3 transition-all hover:shadow-sm dark:border-amber-800 dark:bg-amber-950/20">
                                    <p className="text-2xl font-bold text-amber-700 dark:text-amber-400">{compliance.competencyExpiring}</p>
                                    <p className="text-xs text-muted-foreground">Competency Expiring (30d)</p>
                                </div>
                            </Link>
                            <Link href="/emar/competency" className="group block">
                                <div className="rounded-lg border border-red-200 bg-red-50/50 p-3 transition-all hover:shadow-sm dark:border-red-800 dark:bg-red-950/20">
                                    <p className="text-2xl font-bold text-red-700 dark:text-red-400">{compliance.competencyExpired}</p>
                                    <p className="text-xs text-muted-foreground">Competency Expired</p>
                                </div>
                            </Link>
                            <Link href="/emar/reviews" className="group block">
                                <div className="rounded-lg border border-blue-200 bg-blue-50/50 p-3 transition-all hover:shadow-sm dark:border-blue-800 dark:bg-blue-950/20">
                                    <p className="text-2xl font-bold text-blue-700 dark:text-blue-400">{compliance.pendingReviews}</p>
                                    <p className="text-xs text-muted-foreground">Pending Reviews</p>
                                </div>
                            </Link>
                            <Link href="/emar/reviews" className="group block">
                                <div className="rounded-lg border border-red-200 bg-red-50/50 p-3 transition-all hover:shadow-sm dark:border-red-800 dark:bg-red-950/20">
                                    <p className="text-2xl font-bold text-red-700 dark:text-red-400">{compliance.overdueReviews}</p>
                                    <p className="text-xs text-muted-foreground">Overdue Reviews</p>
                                </div>
                            </Link>
                        </div>
                    </CardContent>
                </Card>

                {/* ── Quick Actions ─────────────────────────────────── */}
                <Card className="mb-6">
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm font-medium">Quick Actions</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            <Button
                                className="flex h-auto items-center gap-3 rounded-lg border bg-primary/5 p-3 text-left font-medium text-primary shadow-none hover:bg-primary/10"
                                onClick={() => router.post('/emar/rounds/generate', { date: new Date().toISOString().split('T')[0] })}
                            >
                                <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary/10">
                                    <Clock className="h-4 w-4" />
                                </div>
                                <span className="text-sm">Generate Today's Rounds</span>
                            </Button>
                            {[
                                { label: 'Record Administration', href: '/emar/mar', icon: Syringe, color: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300' },
                                { label: 'PRN Review', href: '/emar/prn', icon: ClipboardCheck, color: 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300' },
                                { label: 'Stock Check', href: '/emar/stock', icon: Package, color: 'bg-cyan-100 text-cyan-700 dark:bg-cyan-900/40 dark:text-cyan-300' },
                                { label: 'New Prescription', href: '/emar/prescriptions', icon: FileText, color: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300' },
                                { label: 'Handover', href: '/emar/handovers', icon: ArrowRightLeft, color: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' },
                            ].map((item) => {
                                const Icon = item.icon;
                                return (
                                    <Link
                                        key={item.href}
                                        href={item.href}
                                        className="group flex items-center gap-3 rounded-lg border p-3 transition-all hover:bg-muted/50 hover:shadow-sm"
                                    >
                                        <div className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${item.color}`}>
                                            <Icon className="h-4 w-4" />
                                        </div>
                                        <span className="text-sm font-medium">{item.label}</span>
                                    </Link>
                                );
                            })}
                        </div>
                    </CardContent>
                </Card>

                {/* ── Quick Access ───────────────────────────────────── */}
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm font-medium">Quick Access</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                            {[
                                { title: 'Daily Overview', href: '/emar/daily', icon: Activity, color: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300' },
                                { title: 'MAR Charts', href: '/emar/mar', icon: Pill, color: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300' },
                                { title: 'Controlled Drugs', href: '/emar/controlled', icon: Lock, color: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300' },
                                { title: 'Reports', href: '/emar/reports', icon: TrendingUp, color: 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300' },
                            ].map((item) => {
                                const Icon = item.icon;
                                return (
                                    <Link key={item.href} href={item.href} className="group flex items-center gap-3 rounded-lg border p-3 transition-all hover:bg-muted/50 hover:shadow-sm">
                                        <div className={`flex h-8 w-8 items-center justify-center rounded-lg ${item.color}`}>
                                            <Icon className="h-4 w-4" />
                                        </div>
                                        <span className="text-sm font-medium">{item.title}</span>
                                        <ArrowRight className="ml-auto h-4 w-4 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" />
                                    </Link>
                                );
                            })}
                        </div>
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
