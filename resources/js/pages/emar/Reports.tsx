import { DonutChart, OPS_COLORS, OpsStatCard } from '@/components/ops-stat-card';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { TabsRoot as Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Award,
    CheckCircle,
    Clock,
    Download,
    FileBarChart,
    FileText,
    Lock,
    Package,
    Pill,
    Shield,
    TrendingUp,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
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

// ─── Types ───────────────────────────────────────────────
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

type PrnMed = { medication: string; count: number };
type PrnClientRow = { client_name: string; medication: string; count: number; avg_per_day: number };

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
    summary: { total: number; completed: number; on_time_pct: number; late_pct: number; missed_pct: number };
    daily: { date: string; total: number; completed: number; missed: number }[];
};

type ErrorSummaryData = {
    total: number;
    critical: number;
    open: number;
    resolved: number;
    byType: { type: string; count: number }[];
    list: { date: string; client: string; type: string; severity: string; status: string }[];
};

type Props = {
    filters: { date_from: string; date_to: string; client_id: number | null; report_type: string | null };
    clients: ClientOption[];
    adminSummary: AdminSummary;
    dailyAdmin: DailyAdmin[];
    clientBreakdown: ClientBreakdownRow[];
    topPrnMeds: PrnMed[];
    prnByClient: PrnClientRow[];
    controlledDrugs: ControlledDrugs;
    staffCompliance: StaffComplianceData;
    stockStatus: StockStatusData;
    roundCompletion: RoundCompletion;
    errorSummary: ErrorSummaryData;
};

// ─── Colour palette for donut/pie charts ──────────────────
const CHART_COLORS = [
    OPS_COLORS.primary,
    OPS_COLORS.success,
    OPS_COLORS.warning,
    OPS_COLORS.danger,
    OPS_COLORS.accent,
    OPS_COLORS.purple,
    OPS_COLORS.neutral,
    '#f472b6',
    '#34d399',
    '#fbbf24',
];

const statusBadge = (status: string) => {
    const map: Record<string, { variant: 'default' | 'secondary' | 'destructive' | 'outline'; label: string }> = {
        current: { variant: 'default', label: 'Current' },
        expiring: { variant: 'secondary', label: 'Expiring' },
        expired: { variant: 'destructive', label: 'Expired' },
        failed: { variant: 'destructive', label: 'Failed' },
        ok: { variant: 'default', label: 'OK' },
        low: { variant: 'secondary', label: 'Low Stock' },
        open: { variant: 'destructive', label: 'Open' },
        resolved: { variant: 'default', label: 'Resolved' },
        critical: { variant: 'destructive', label: 'Critical' },
        moderate: { variant: 'secondary', label: 'Moderate' },
        minor: { variant: 'outline', label: 'Minor' },
    };
    const cfg = map[status] ?? { variant: 'outline' as const, label: status };
    return <Badge variant={cfg.variant}>{cfg.label}</Badge>;
};

// ─── Component ───────────────────────────────────────────
export default function EmarReports({
    filters,
    clients,
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
}: Props) {
    const [dateFrom, setDateFrom] = useState(filters.date_from);
    const [dateTo, setDateTo] = useState(filters.date_to);
    const [clientId, setClientId] = useState<string>(filters.client_id?.toString() ?? 'all');

    const applyFilters = () => {
        router.get(
            '/emar/reports',
            {
                date_from: dateFrom,
                date_to: dateTo,
                client_id: clientId !== 'all' ? clientId : undefined,
            },
            { preserveState: true, preserveScroll: true },
        );
    };

    const handleExport = (reportType: string) => {
        const params = new URLSearchParams({
            date_from: dateFrom,
            date_to: dateTo,
            report_type: reportType,
        });
        if (clientId !== 'all') params.set('client_id', clientId);
        window.open(`/emar/reports/export?${params.toString()}`, '_blank');
    };

    return (
        <AppLayout>
            <Head title="eMAR Reports" />
            <PageHero
                icon={FileBarChart}
                title="eMAR Reports"
                description="Comprehensive medication administration reporting and analytics."
                stats={[
                    { label: 'Given', value: adminSummary.given },
                    { label: 'Refused', value: adminSummary.refused },
                    { label: 'Missed', value: adminSummary.missed },
                    { label: 'Compliance', value: `${adminSummary.compliance_rate}%` },
                ]}
            />
            <PageShell>
                {/* ── Filters ────────────────────────────────────── */}
                <Card className="mb-6">
                    <CardContent className="flex flex-wrap items-end gap-3 p-4">
                        <div className="space-y-1">
                            <label className="text-xs font-medium text-muted-foreground">From</label>
                            <Input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} className="w-40" />
                        </div>
                        <div className="space-y-1">
                            <label className="text-xs font-medium text-muted-foreground">To</label>
                            <Input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} className="w-40" />
                        </div>
                        <div className="space-y-1">
                            <label className="text-xs font-medium text-muted-foreground">Client</label>
                            <Select value={clientId} onValueChange={setClientId}>
                                <SelectTrigger className="w-52">
                                    <SelectValue placeholder="All clients" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Clients</SelectItem>
                                    {clients.map((c) => (
                                        <SelectItem key={c.id} value={c.id.toString()}>
                                            {c.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <Button onClick={applyFilters} size="sm">
                            Apply Filters
                        </Button>
                    </CardContent>
                </Card>

                {/* ── Tabs ────────────────────────────────────────── */}
                <Tabs defaultValue="administration">
                    <TabsList className="mb-4 flex flex-wrap">
                        <TabsTrigger value="administration" className="gap-1.5">
                            <Pill className="h-3.5 w-3.5" />
                            Administration
                        </TabsTrigger>
                        <TabsTrigger value="prn" className="gap-1.5">
                            <Activity className="h-3.5 w-3.5" />
                            PRN Usage
                        </TabsTrigger>
                        <TabsTrigger value="controlled" className="gap-1.5">
                            <Lock className="h-3.5 w-3.5" />
                            Controlled Drugs
                        </TabsTrigger>
                        <TabsTrigger value="compliance" className="gap-1.5">
                            <Award className="h-3.5 w-3.5" />
                            Staff Compliance
                        </TabsTrigger>
                        <TabsTrigger value="stock" className="gap-1.5">
                            <Package className="h-3.5 w-3.5" />
                            Stock Status
                        </TabsTrigger>
                        <TabsTrigger value="rounds" className="gap-1.5">
                            <Clock className="h-3.5 w-3.5" />
                            Round Completion
                        </TabsTrigger>
                        <TabsTrigger value="errors" className="gap-1.5">
                            <AlertTriangle className="h-3.5 w-3.5" />
                            Error Summary
                        </TabsTrigger>
                    </TabsList>

                    {/* ── Tab 1: Administration Summary ──────────── */}
                    <TabsContent value="administration">
                        <div className="space-y-6">
                            <div className="flex items-center justify-between">
                                <h3 className="text-lg font-semibold">Administration Summary</h3>
                                <Button variant="outline" size="sm" onClick={() => handleExport('administration')}>
                                    <Download className="mr-1.5 h-3.5 w-3.5" />
                                    Export CSV
                                </Button>
                            </div>

                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                                <OpsStatCard label="Total Given" value={adminSummary.given} icon={CheckCircle} color="emerald" />
                                <OpsStatCard label="Total Refused" value={adminSummary.refused} icon={XCircle} color="amber" />
                                <OpsStatCard label="Total Withheld" value={adminSummary.withheld} icon={Shield} color="slate" />
                                <OpsStatCard label="Total Missed" value={adminSummary.missed} icon={AlertTriangle} color="red" />
                                <OpsStatCard
                                    label="Compliance Rate"
                                    value={`${adminSummary.compliance_rate}%`}
                                    icon={TrendingUp}
                                    color="indigo"
                                />
                            </div>

                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm font-medium">Daily Administration Trend</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <ResponsiveContainer width="100%" height={280}>
                                        <AreaChart data={dailyAdmin} margin={{ top: 5, right: 10, left: -20, bottom: 0 }}>
                                            <defs>
                                                <linearGradient id="rptGradGiven" x1="0" y1="0" x2="0" y2="1">
                                                    <stop offset="5%" stopColor={OPS_COLORS.success} stopOpacity={0.3} />
                                                    <stop offset="95%" stopColor={OPS_COLORS.success} stopOpacity={0} />
                                                </linearGradient>
                                                <linearGradient id="rptGradMissed" x1="0" y1="0" x2="0" y2="1">
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
                                            <Area
                                                type="monotone"
                                                dataKey="given"
                                                stroke={OPS_COLORS.success}
                                                fill="url(#rptGradGiven)"
                                                strokeWidth={2}
                                                name="Given"
                                            />
                                            <Area
                                                type="monotone"
                                                dataKey="refused"
                                                stroke={OPS_COLORS.warning}
                                                fill="none"
                                                strokeWidth={1.5}
                                                strokeDasharray="4 4"
                                                name="Refused"
                                            />
                                            <Area
                                                type="monotone"
                                                dataKey="missed"
                                                stroke={OPS_COLORS.danger}
                                                fill="url(#rptGradMissed)"
                                                strokeWidth={1.5}
                                                name="Missed"
                                            />
                                        </AreaChart>
                                    </ResponsiveContainer>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm font-medium">Breakdown by Client</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-sm">
                                            <thead>
                                                <tr className="border-b text-left text-xs font-medium text-muted-foreground">
                                                    <th className="pb-2 pr-4">Client</th>
                                                    <th className="pb-2 pr-4 text-right">Given</th>
                                                    <th className="pb-2 pr-4 text-right">Refused</th>
                                                    <th className="pb-2 pr-4 text-right">Withheld</th>
                                                    <th className="pb-2 pr-4 text-right">Missed</th>
                                                    <th className="pb-2 pr-4 text-right">Total</th>
                                                    <th className="pb-2 text-right">Compliance</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {clientBreakdown.map((row) => (
                                                    <tr key={row.client_id} className="border-b last:border-0">
                                                        <td className="py-2 pr-4 font-medium">{row.client_name}</td>
                                                        <td className="py-2 pr-4 text-right tabular-nums text-status-success">{row.given}</td>
                                                        <td className="py-2 pr-4 text-right tabular-nums text-status-warning">{row.refused}</td>
                                                        <td className="py-2 pr-4 text-right tabular-nums text-muted-foreground">{row.withheld}</td>
                                                        <td className="py-2 pr-4 text-right tabular-nums text-status-critical">{row.missed}</td>
                                                        <td className="py-2 pr-4 text-right tabular-nums font-medium">{row.total}</td>
                                                        <td className="py-2 text-right tabular-nums">
                                                            <Badge variant={row.compliance >= 90 ? 'default' : row.compliance >= 75 ? 'secondary' : 'destructive'}>
                                                                {row.compliance}%
                                                            </Badge>
                                                        </td>
                                                    </tr>
                                                ))}
                                                {clientBreakdown.length === 0 && (
                                                    <tr>
                                                        <td colSpan={7} className="py-8 text-center text-muted-foreground">
                                                            No administration data for the selected period.
                                                        </td>
                                                    </tr>
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>

                    {/* ── Tab 2: PRN Usage ────────────────────────── */}
                    <TabsContent value="prn">
                        <div className="space-y-6">
                            <div className="flex items-center justify-between">
                                <h3 className="text-lg font-semibold">PRN Usage</h3>
                                <Button variant="outline" size="sm" onClick={() => handleExport('prn')}>
                                    <Download className="mr-1.5 h-3.5 w-3.5" />
                                    Export CSV
                                </Button>
                            </div>

                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm font-medium">Top 10 PRN Medications by Usage</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {topPrnMeds.length > 0 ? (
                                        <ResponsiveContainer width="100%" height={300}>
                                            <BarChart data={topPrnMeds} layout="vertical" margin={{ top: 5, right: 30, left: 100, bottom: 5 }}>
                                                <CartesianGrid strokeDasharray="3 3" className="stroke-muted/30" />
                                                <XAxis type="number" tick={{ fontSize: 11 }} className="text-muted-foreground" />
                                                <YAxis
                                                    dataKey="medication"
                                                    type="category"
                                                    tick={{ fontSize: 11 }}
                                                    className="text-muted-foreground"
                                                    width={90}
                                                />
                                                <Tooltip
                                                    contentStyle={{ borderRadius: 8, fontSize: 12, border: '1px solid hsl(var(--border))' }}
                                                />
                                                <Bar dataKey="count" fill={OPS_COLORS.purple} radius={[0, 4, 4, 0]} name="Usage Count" />
                                            </BarChart>
                                        </ResponsiveContainer>
                                    ) : (
                                        <p className="py-8 text-center text-sm text-muted-foreground">No PRN data for the selected period.</p>
                                    )}
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm font-medium">PRN Usage by Client</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-sm">
                                            <thead>
                                                <tr className="border-b text-left text-xs font-medium text-muted-foreground">
                                                    <th className="pb-2 pr-4">Client</th>
                                                    <th className="pb-2 pr-4">Medication</th>
                                                    <th className="pb-2 pr-4 text-right">Usage Count</th>
                                                    <th className="pb-2 text-right">Avg/Day</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {prnByClient.map((row, i) => (
                                                    <tr key={i} className="border-b last:border-0">
                                                        <td className="py-2 pr-4 font-medium">{row.client_name}</td>
                                                        <td className="py-2 pr-4">{row.medication}</td>
                                                        <td className="py-2 pr-4 text-right tabular-nums">{row.count}</td>
                                                        <td className="py-2 text-right tabular-nums">{row.avg_per_day}</td>
                                                    </tr>
                                                ))}
                                                {prnByClient.length === 0 && (
                                                    <tr>
                                                        <td colSpan={4} className="py-8 text-center text-muted-foreground">
                                                            No PRN usage data for the selected period.
                                                        </td>
                                                    </tr>
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>

                    {/* ── Tab 3: Controlled Drugs ─────────────────── */}
                    <TabsContent value="controlled">
                        <div className="space-y-6">
                            <div className="flex items-center justify-between">
                                <h3 className="text-lg font-semibold">Controlled Drugs</h3>
                                <Button variant="outline" size="sm" onClick={() => handleExport('controlled')}>
                                    <Download className="mr-1.5 h-3.5 w-3.5" />
                                    Export CSV
                                </Button>
                            </div>

                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <OpsStatCard label="CD Administrations" value={controlledDrugs.administrations} icon={Pill} color="indigo" />
                                <OpsStatCard label="Destructions" value={controlledDrugs.destructions} icon={FileText} color="slate" />
                                <OpsStatCard label="Discrepancies" value={controlledDrugs.discrepancies} icon={AlertTriangle} color="red" />
                                <OpsStatCard label="Loss Reports" value={0} icon={Shield} color="amber" />
                            </div>

                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm font-medium">CD Administrations by Medication</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-sm">
                                            <thead>
                                                <tr className="border-b text-left text-xs font-medium text-muted-foreground">
                                                    <th className="pb-2 pr-4">Medication</th>
                                                    <th className="pb-2 text-right">Administrations</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {controlledDrugs.byMedication.map((row, i) => (
                                                    <tr key={i} className="border-b last:border-0">
                                                        <td className="py-2 pr-4 font-medium">{row.medication}</td>
                                                        <td className="py-2 text-right tabular-nums">{row.administrations}</td>
                                                    </tr>
                                                ))}
                                                {controlledDrugs.byMedication.length === 0 && (
                                                    <tr>
                                                        <td colSpan={2} className="py-8 text-center text-muted-foreground">
                                                            No controlled drug data for the selected period.
                                                        </td>
                                                    </tr>
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>

                    {/* ── Tab 4: Staff Compliance ─────────────────── */}
                    <TabsContent value="compliance">
                        <div className="space-y-6">
                            <h3 className="text-lg font-semibold">Staff Compliance</h3>

                            <div className="grid gap-4 lg:grid-cols-3">
                                <Card className="flex flex-col items-center justify-center p-6">
                                    <DonutChart
                                        segments={[
                                            { label: 'Current', value: staffCompliance.current, color: OPS_COLORS.success },
                                            { label: 'Expiring', value: staffCompliance.expiring, color: OPS_COLORS.warning },
                                            { label: 'Expired', value: staffCompliance.expired, color: OPS_COLORS.danger },
                                        ]}
                                        size={180}
                                        strokeWidth={22}
                                        centerValue={staffCompliance.current + staffCompliance.expiring + staffCompliance.expired}
                                        centerLabel="Total"
                                    />
                                    <div className="mt-4 grid w-full grid-cols-3 gap-2 text-center text-xs">
                                        <div>
                                            <span className="inline-block h-2.5 w-2.5 rounded-full" style={{ backgroundColor: OPS_COLORS.success }} />
                                            <p className="mt-1 font-medium">{staffCompliance.current}</p>
                                            <p className="text-muted-foreground">Current</p>
                                        </div>
                                        <div>
                                            <span className="inline-block h-2.5 w-2.5 rounded-full" style={{ backgroundColor: OPS_COLORS.warning }} />
                                            <p className="mt-1 font-medium">{staffCompliance.expiring}</p>
                                            <p className="text-muted-foreground">Expiring</p>
                                        </div>
                                        <div>
                                            <span className="inline-block h-2.5 w-2.5 rounded-full" style={{ backgroundColor: OPS_COLORS.danger }} />
                                            <p className="mt-1 font-medium">{staffCompliance.expired}</p>
                                            <p className="text-muted-foreground">Expired</p>
                                        </div>
                                    </div>
                                </Card>

                                <Card className="lg:col-span-2">
                                    <CardHeader className="pb-2">
                                        <CardTitle className="text-sm font-medium">Staff Competency Details</CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="overflow-x-auto">
                                            <table className="w-full text-sm">
                                                <thead>
                                                    <tr className="border-b text-left text-xs font-medium text-muted-foreground">
                                                        <th className="pb-2 pr-4">Staff Member</th>
                                                        <th className="pb-2 pr-4">Assessment Date</th>
                                                        <th className="pb-2 pr-4">Expiry Date</th>
                                                        <th className="pb-2 pr-4">Status</th>
                                                        <th className="pb-2 text-right">Days Until Expiry</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {staffCompliance.list.map((row, i) => (
                                                        <tr key={i} className="border-b last:border-0">
                                                            <td className="py-2 pr-4 font-medium">{row.staff_name}</td>
                                                            <td className="py-2 pr-4 tabular-nums">{row.assessment_date ?? '-'}</td>
                                                            <td className="py-2 pr-4 tabular-nums">{row.expiry_date ?? '-'}</td>
                                                            <td className="py-2 pr-4">{statusBadge(row.status)}</td>
                                                            <td className="py-2 text-right tabular-nums">
                                                                {row.days_until_expiry !== null ? row.days_until_expiry : '-'}
                                                            </td>
                                                        </tr>
                                                    ))}
                                                    {staffCompliance.list.length === 0 && (
                                                        <tr>
                                                            <td colSpan={5} className="py-8 text-center text-muted-foreground">
                                                                No competency assessment data available.
                                                            </td>
                                                        </tr>
                                                    )}
                                                </tbody>
                                            </table>
                                        </div>
                                    </CardContent>
                                </Card>
                            </div>
                        </div>
                    </TabsContent>

                    {/* ── Tab 5: Stock Status ─────────────────────── */}
                    <TabsContent value="stock">
                        <div className="space-y-6">
                            <h3 className="text-lg font-semibold">Stock Status</h3>

                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <OpsStatCard label="Total Items" value={stockStatus.total} icon={Package} color="indigo" />
                                <OpsStatCard label="Low Stock" value={stockStatus.low} icon={AlertTriangle} color="amber" />
                                <OpsStatCard label="Expiring Soon" value={stockStatus.expiring} icon={Clock} color="red" />
                                <OpsStatCard label="Expired" value={stockStatus.expired} icon={XCircle} color="slate" />
                            </div>

                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm font-medium">Stock Details</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-sm">
                                            <thead>
                                                <tr className="border-b text-left text-xs font-medium text-muted-foreground">
                                                    <th className="pb-2 pr-4">Medication</th>
                                                    <th className="pb-2 pr-4">Client</th>
                                                    <th className="pb-2 pr-4 text-right">On Hand</th>
                                                    <th className="pb-2 pr-4 text-right">Reorder Level</th>
                                                    <th className="pb-2 pr-4">Expiry Date</th>
                                                    <th className="pb-2">Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {stockStatus.list.map((row, i) => (
                                                    <tr key={i} className="border-b last:border-0">
                                                        <td className="py-2 pr-4 font-medium">{row.medication}</td>
                                                        <td className="py-2 pr-4">{row.client}</td>
                                                        <td className="py-2 pr-4 text-right tabular-nums">{row.on_hand}</td>
                                                        <td className="py-2 pr-4 text-right tabular-nums">{row.reorder_level}</td>
                                                        <td className="py-2 pr-4 tabular-nums">{row.expiry_date ?? '-'}</td>
                                                        <td className="py-2">{statusBadge(row.status)}</td>
                                                    </tr>
                                                ))}
                                                {stockStatus.list.length === 0 && (
                                                    <tr>
                                                        <td colSpan={6} className="py-8 text-center text-muted-foreground">
                                                            No stock data available.
                                                        </td>
                                                    </tr>
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>

                    {/* ── Tab 6: Round Completion ─────────────────── */}
                    <TabsContent value="rounds">
                        <div className="space-y-6">
                            <div className="flex items-center justify-between">
                                <h3 className="text-lg font-semibold">Round Completion</h3>
                                <Button variant="outline" size="sm" onClick={() => handleExport('rounds')}>
                                    <Download className="mr-1.5 h-3.5 w-3.5" />
                                    Export CSV
                                </Button>
                            </div>

                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                                <OpsStatCard label="Total Rounds" value={roundCompletion.summary.total} icon={Clock} color="indigo" />
                                <OpsStatCard label="Completed" value={roundCompletion.summary.completed} icon={CheckCircle} color="emerald" />
                                <OpsStatCard
                                    label="On Time"
                                    value={`${roundCompletion.summary.on_time_pct}%`}
                                    icon={TrendingUp}
                                    color="blue"
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

                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm font-medium">Daily Round Completion</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {roundCompletion.daily.length > 0 ? (
                                        <ResponsiveContainer width="100%" height={280}>
                                            <BarChart data={roundCompletion.daily} margin={{ top: 5, right: 10, left: -20, bottom: 0 }}>
                                                <CartesianGrid strokeDasharray="3 3" className="stroke-muted/30" />
                                                <XAxis dataKey="date" tick={{ fontSize: 11 }} className="text-muted-foreground" />
                                                <YAxis tick={{ fontSize: 11 }} className="text-muted-foreground" />
                                                <Tooltip
                                                    contentStyle={{ borderRadius: 8, fontSize: 12, border: '1px solid hsl(var(--border))' }}
                                                />
                                                <Bar dataKey="completed" fill={OPS_COLORS.success} radius={[4, 4, 0, 0]} name="Completed" />
                                                <Bar dataKey="missed" fill={OPS_COLORS.danger} radius={[4, 4, 0, 0]} name="Missed" />
                                            </BarChart>
                                        </ResponsiveContainer>
                                    ) : (
                                        <p className="py-8 text-center text-sm text-muted-foreground">
                                            No round data for the selected period.
                                        </p>
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>

                    {/* ── Tab 7: Error Summary ────────────────────── */}
                    <TabsContent value="errors">
                        <div className="space-y-6">
                            <div className="flex items-center justify-between">
                                <h3 className="text-lg font-semibold">Error Summary</h3>
                                <Button variant="outline" size="sm" onClick={() => handleExport('errors')}>
                                    <Download className="mr-1.5 h-3.5 w-3.5" />
                                    Export CSV
                                </Button>
                            </div>

                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <OpsStatCard label="Total Errors" value={errorSummary.total} icon={AlertTriangle} color="red" />
                                <OpsStatCard label="Critical" value={errorSummary.critical} icon={XCircle} color="red" />
                                <OpsStatCard label="Open" value={errorSummary.open} icon={Clock} color="amber" />
                                <OpsStatCard label="Resolved" value={errorSummary.resolved} icon={CheckCircle} color="emerald" />
                            </div>

                            <div className="grid gap-4 lg:grid-cols-3">
                                <Card className="flex flex-col items-center justify-center p-6">
                                    <CardTitle className="mb-4 text-sm font-medium">Errors by Type</CardTitle>
                                    {errorSummary.byType.length > 0 ? (
                                        <>
                                            <DonutChart
                                                segments={errorSummary.byType.map((t, i) => ({
                                                    label: t.type ?? 'Unknown',
                                                    value: t.count,
                                                    color: CHART_COLORS[i % CHART_COLORS.length],
                                                }))}
                                                size={180}
                                                strokeWidth={22}
                                                centerValue={errorSummary.total}
                                                centerLabel="Total"
                                            />
                                            <div className="mt-4 grid w-full grid-cols-2 gap-x-4 gap-y-1 text-xs">
                                                {errorSummary.byType.map((t, i) => (
                                                    <div key={i} className="flex items-center gap-2">
                                                        <span
                                                            className="h-2.5 w-2.5 rounded-full"
                                                            style={{ backgroundColor: CHART_COLORS[i % CHART_COLORS.length] }}
                                                        />
                                                        <span className="truncate text-muted-foreground">{t.type ?? 'Unknown'}</span>
                                                        <span className="ml-auto font-medium">{t.count}</span>
                                                    </div>
                                                ))}
                                            </div>
                                        </>
                                    ) : (
                                        <p className="py-4 text-center text-sm text-muted-foreground">No errors recorded.</p>
                                    )}
                                </Card>

                                <Card className="lg:col-span-2">
                                    <CardHeader className="pb-2">
                                        <CardTitle className="text-sm font-medium">Recent Errors</CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="overflow-x-auto">
                                            <table className="w-full text-sm">
                                                <thead>
                                                    <tr className="border-b text-left text-xs font-medium text-muted-foreground">
                                                        <th className="pb-2 pr-4">Date</th>
                                                        <th className="pb-2 pr-4">Client</th>
                                                        <th className="pb-2 pr-4">Type</th>
                                                        <th className="pb-2 pr-4">Severity</th>
                                                        <th className="pb-2">Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {errorSummary.list.map((row, i) => (
                                                        <tr key={i} className="border-b last:border-0">
                                                            <td className="py-2 pr-4 tabular-nums">{row.date}</td>
                                                            <td className="py-2 pr-4 font-medium">{row.client}</td>
                                                            <td className="py-2 pr-4">{row.type}</td>
                                                            <td className="py-2 pr-4">{statusBadge(row.severity)}</td>
                                                            <td className="py-2">{statusBadge(row.status)}</td>
                                                        </tr>
                                                    ))}
                                                    {errorSummary.list.length === 0 && (
                                                        <tr>
                                                            <td colSpan={5} className="py-8 text-center text-muted-foreground">
                                                                No errors for the selected period.
                                                            </td>
                                                        </tr>
                                                    )}
                                                </tbody>
                                            </table>
                                        </div>
                                    </CardContent>
                                </Card>
                            </div>
                        </div>
                    </TabsContent>
                </Tabs>
            </PageShell>
        </AppLayout>
    );
}
