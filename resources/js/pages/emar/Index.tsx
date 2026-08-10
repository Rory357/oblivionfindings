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
    AlertTriangle,
    ArrowRightLeft,
    ArrowRight,
    Award,
    CheckCircle,
    ClipboardCheck,
    Clock,
    FileText,
    Lock,
    Package,
    Pill,
    Shield,
    Syringe,
    TrendingUp,
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

type Props = {
    stats: Stats;
    trend: TrendDay[];
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

export default function EmarDashboard({ stats, trend }: Props) {
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
                                <span className="text-sm">Start Round</span>
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
