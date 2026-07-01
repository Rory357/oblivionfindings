import { PerformanceTabs } from '@/components/hr';
import {
    PerformanceSatelliteHero,
    SatelliteHeroAction,
} from '@/components/hr/performance/performance-hero';
import { PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle, Plus, ShieldAlert, XCircle } from 'lucide-react';
import { Cell, Pie, PieChart, Tooltip } from 'recharts';

interface Pip {
    id: number;
    title: string;
    status: string;
    start_date: string;
    end_date: string;
    outcome: string | null;
    employee: { id: number; name: string };
    manager: { id: number; name: string };
}

interface Props {
    pips: { data: Pip[]; links: any[] };
    stats?: {
        active: number;
        completed: number;
        cancelled: number;
        total: number;
    };
    filters: { status: string | null };
    can: { manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Performance', href: '/hr/performance' },
    { title: 'PIPs', href: '/hr/performance/pips' },
];

const statusColors: Record<string, string> = {
    active: 'bg-status-info-bg text-status-info',
    in_progress: 'bg-status-warning-bg text-status-warning',
    completed: 'bg-status-success-bg text-status-success',
    cancelled: 'bg-muted text-foreground',
};

const outcomeColors: Record<string, string> = {
    successful: 'bg-status-success-bg text-status-success',
    unsuccessful: 'bg-status-critical-bg text-status-critical',
    extended: 'bg-status-warning-bg text-status-warning',
};

const PIP_COLORS = {
    active: '#ef4444',
    completed: '#10b981',
    cancelled: '#94a3b8',
};

const formatDate = (value?: string | null) => {
    if (!value) return '-';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          });
};

export default function PipIndex({ pips, stats, filters, can }: Props) {
    const onFilter = (next: Partial<typeof filters>) => {
        router.get(
            '/hr/performance/pips',
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true },
        );
    };

    const pieData = stats
        ? [
              { name: 'Active', value: stats.active, color: PIP_COLORS.active },
              {
                  name: 'Completed',
                  value: stats.completed,
                  color: PIP_COLORS.completed,
              },
              {
                  name: 'Cancelled',
                  value: stats.cancelled,
                  color: PIP_COLORS.cancelled,
              },
          ].filter((d) => d.value > 0)
        : [];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Performance Improvement Plans" />

            <PageLayout
                hero={
                    <PerformanceSatelliteHero
                        icon={ShieldAlert}
                        title="Performance improvement plans"
                        description="Manage and track employee improvement plans."
                        stats={
                            stats
                                ? [
                                      { label: 'Active', value: stats.active, amber: stats.active > 0 },
                                      { label: 'Completed', value: stats.completed },
                                      { label: 'Cancelled', value: stats.cancelled },
                                      { label: 'Total', value: stats.total },
                                  ]
                                : []
                        }
                        actions={
                            can.manage ? (
                                <SatelliteHeroAction
                                    icon={Plus}
                                    label="New PIP"
                                    primary
                                    href="/hr/performance/pips/create"
                                />
                            ) : undefined
                        }
                    />
                }
            >
                <PerformanceTabs active="pips" />

                {/* KPI Cards + Chart */}
                {stats && (
                    <div className="grid gap-4 lg:grid-cols-5">
                        <Card className="border-l-4 border-l-red-500 bg-status-critical-bg">
                            <CardContent className="p-4">
                                <div className="flex items-center justify-between">
                                    <p className="text-xs font-medium text-status-critical">
                                        Active
                                    </p>
                                    <div className="rounded-full bg-status-critical-bg p-1.5">
                                        <AlertTriangle className="h-4 w-4 text-status-critical" />
                                    </div>
                                </div>
                                <span className="mt-1.5 block text-2xl font-bold text-status-critical">
                                    {stats.active}
                                </span>
                            </CardContent>
                        </Card>
                        <Card className="border-l-4 border-l-emerald-500 bg-status-success-bg">
                            <CardContent className="p-4">
                                <div className="flex items-center justify-between">
                                    <p className="text-xs font-medium text-status-success">
                                        Completed
                                    </p>
                                    <div className="rounded-full bg-status-success-bg p-1.5">
                                        <CheckCircle className="h-4 w-4 text-status-success" />
                                    </div>
                                </div>
                                <span className="mt-1.5 block text-2xl font-bold text-status-success">
                                    {stats.completed}
                                </span>
                            </CardContent>
                        </Card>
                        <Card className="border-l-4 border-l-orange-400 bg-status-warning-bg">
                            <CardContent className="p-4">
                                <div className="flex items-center justify-between">
                                    <p className="text-xs font-medium text-status-warning">
                                        Cancelled
                                    </p>
                                    <div className="rounded-full bg-status-warning-bg p-1.5">
                                        <XCircle className="h-4 w-4 text-status-warning" />
                                    </div>
                                </div>
                                <span className="mt-1.5 block text-2xl font-bold text-status-warning">
                                    {stats.cancelled}
                                </span>
                            </CardContent>
                        </Card>
                        {pieData.length > 0 && (
                            <Card className="lg:col-span-2">
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm font-medium">
                                        Status Breakdown
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="flex items-center gap-4">
                                        <div
                                            className="flex-shrink-0"
                                            style={{
                                                width: 120,
                                                height: 120,
                                                minWidth: 120,
                                            }}
                                        >
                                            <PieChart width={120} height={120}>
                                                <Pie
                                                    data={pieData}
                                                    dataKey="value"
                                                    nameKey="name"
                                                    cx="50%"
                                                    cy="50%"
                                                    outerRadius={55}
                                                    innerRadius={35}
                                                    paddingAngle={2}
                                                >
                                                    {pieData.map(
                                                        (entry, idx) => (
                                                            <Cell
                                                                key={idx}
                                                                fill={
                                                                    entry.color
                                                                }
                                                            />
                                                        ),
                                                    )}
                                                </Pie>
                                                <Tooltip />
                                            </PieChart>
                                        </div>
                                        <div className="space-y-1.5 text-sm">
                                            {pieData.map((d) => (
                                                <div
                                                    key={d.name}
                                                    className="flex items-center gap-2"
                                                >
                                                    <div
                                                        className="h-2.5 w-2.5 rounded-full"
                                                        style={{
                                                            backgroundColor:
                                                                d.color,
                                                        }}
                                                    />
                                                    <span className="text-muted-foreground">
                                                        {d.name}:{' '}
                                                        <span className="font-medium">
                                                            {d.value}
                                                        </span>
                                                    </span>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        )}
                    </div>
                )}

                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm font-medium">
                            Filters
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="max-w-xs">
                            <Select
                                value={filters.status || 'all'}
                                onValueChange={(val) =>
                                    onFilter({
                                        status: val === 'all' ? null : val,
                                    })
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="All Statuses" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All Statuses
                                    </SelectItem>
                                    <SelectItem value="active">
                                        Active
                                    </SelectItem>
                                    <SelectItem value="in_progress">
                                        In Progress
                                    </SelectItem>
                                    <SelectItem value="completed">
                                        Completed
                                    </SelectItem>
                                    <SelectItem value="cancelled">
                                        Cancelled
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Title</TableHead>
                                    <TableHead>Employee</TableHead>
                                    <TableHead>Manager</TableHead>
                                    <TableHead>Period</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Outcome</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {pips.data.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={6}
                                            className="py-8 text-center text-muted-foreground"
                                        >
                                            No PIPs found
                                        </TableCell>
                                    </TableRow>
                                )}
                                {pips.data.map((pip) => (
                                    <TableRow key={pip.id}>
                                        <TableCell>
                                            <Link
                                                href={`/hr/performance/pips/${pip.id}`}
                                                className="font-medium text-status-info hover:underline"
                                            >
                                                {pip.title}
                                            </Link>
                                        </TableCell>
                                        <TableCell>
                                            {pip.employee?.name ?? '-'}
                                        </TableCell>
                                        <TableCell>
                                            {pip.manager?.name ?? '-'}
                                        </TableCell>
                                        <TableCell className="text-sm text-muted-foreground">
                                            {formatDate(pip.start_date)} -{' '}
                                            {formatDate(pip.end_date)}
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                className={
                                                    statusColors[pip.status] ||
                                                    'bg-muted'
                                                }
                                                variant="outline"
                                            >
                                                {pip.status.replace('_', ' ')}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>
                                            {pip.outcome ? (
                                                <Badge
                                                    className={
                                                        outcomeColors[
                                                            pip.outcome
                                                        ] || 'bg-muted'
                                                    }
                                                    variant="outline"
                                                >
                                                    {pip.outcome}
                                                </Badge>
                                            ) : (
                                                '-'
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
