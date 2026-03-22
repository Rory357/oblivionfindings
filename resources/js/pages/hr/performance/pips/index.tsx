import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import PageHeader from '@/components/page-header';
import { Head, Link, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Plus, ClipboardList } from 'lucide-react';
import { type BreadcrumbItem } from '@/types';

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
    filters: { status: string | null };
    can: { manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Performance', href: '/hr/performance' },
    { title: 'PIPs', href: '/hr/performance/pips' },
];

const statusColors: Record<string, string> = {
    active: 'bg-blue-100 text-blue-800',
    in_progress: 'bg-yellow-100 text-yellow-800',
    completed: 'bg-green-100 text-green-800',
    cancelled: 'bg-slate-100 text-slate-800',
};

const outcomeColors: Record<string, string> = {
    successful: 'bg-green-100 text-green-800',
    unsuccessful: 'bg-red-100 text-red-800',
    extended: 'bg-yellow-100 text-yellow-800',
};

const formatDate = (value?: string | null) => {
    if (!value) return '-';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

export default function PipIndex({ pips, filters, can }: Props) {
    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/hr/performance/pips', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Performance Improvement Plans" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Performance Improvement Plans</h1>
                        <div className="mt-1 text-sm text-slate-500">
                            Manage and track employee improvement plans
                        </div>
                    </div>

                    {can.manage && (
                        <Button size="sm" asChild>
                            <Link href="/hr/performance/pips/create">
                                <Plus className="mr-1.5 h-4 w-4" />
                                New PIP
                            </Link>
                        </Button>
                    )}
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="max-w-xs">
                            <Select
                                value={filters.status || 'all'}
                                onValueChange={(val) => onFilter({ status: val === 'all' ? null : val })}
                            >
                                <SelectTrigger><SelectValue placeholder="All Statuses" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Statuses</SelectItem>
                                    <SelectItem value="active">Active</SelectItem>
                                    <SelectItem value="in_progress">In Progress</SelectItem>
                                    <SelectItem value="completed">Completed</SelectItem>
                                    <SelectItem value="cancelled">Cancelled</SelectItem>
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
                                        <TableCell colSpan={6} className="text-center text-slate-400">No PIPs found</TableCell>
                                    </TableRow>
                                )}
                                {pips.data.map((pip) => (
                                    <TableRow key={pip.id}>
                                        <TableCell>
                                            <Link href={`/hr/performance/pips/${pip.id}`} className="font-medium text-blue-600 hover:underline">
                                                {pip.title}
                                            </Link>
                                        </TableCell>
                                        <TableCell>{pip.employee?.name ?? '-'}</TableCell>
                                        <TableCell>{pip.manager?.name ?? '-'}</TableCell>
                                        <TableCell className="text-sm text-slate-500">
                                            {formatDate(pip.start_date)} - {formatDate(pip.end_date)}
                                        </TableCell>
                                        <TableCell>
                                            <Badge className={statusColors[pip.status] || 'bg-slate-100'} variant="outline">
                                                {pip.status.replace('_', ' ')}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>
                                            {pip.outcome ? (
                                                <Badge className={outcomeColors[pip.outcome] || 'bg-slate-100'} variant="outline">
                                                    {pip.outcome}
                                                </Badge>
                                            ) : '-'}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
