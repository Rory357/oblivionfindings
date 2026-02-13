import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Head, Link, router } from '@inertiajs/react';
import { Search, Plus, Briefcase } from 'lucide-react';

type BreadcrumbItem = { title: string; href: string };

type HrCase = {
    id: number;
    case_number: string;
    subject_user: { id: number; name: string };
    category: string;
    status: string;
    priority: string;
    opened_at: string;
    closed_at: string | null;
};

type Props = {
    cases: {
        data: HrCase[];
        links: any[];
    };
    filters: {
        status: string | null;
        category: string | null;
        q: string | null;
    };
    can: { manage: boolean };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Cases', href: '/hr/cases' },
];

const formatDate = (value?: string | null) => {
    if (!value) return '--';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? value : d.toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
};

const getStatusColor = (status: string) => {
    switch (status) {
        case 'open':
            return 'bg-blue-100 text-blue-800 border-blue-200';
        case 'under_investigation':
            return 'bg-purple-100 text-purple-800 border-purple-200';
        case 'pending_action':
            return 'bg-yellow-100 text-yellow-800 border-yellow-200';
        case 'resolved':
            return 'bg-green-100 text-green-800 border-green-200';
        case 'closed':
            return 'bg-slate-100 text-slate-800 border-slate-200';
        case 'appealed':
            return 'bg-orange-100 text-orange-800 border-orange-200';
        default:
            return 'bg-slate-100 text-slate-800 border-slate-200';
    }
};

const getCategoryColor = (category: string) => {
    switch (category) {
        case 'disciplinary':
            return 'bg-red-100 text-red-800 border-red-200';
        case 'grievance':
            return 'bg-orange-100 text-orange-800 border-orange-200';
        case 'capability':
            return 'bg-yellow-100 text-yellow-800 border-yellow-200';
        case 'absence':
            return 'bg-blue-100 text-blue-800 border-blue-200';
        case 'conduct':
            return 'bg-purple-100 text-purple-800 border-purple-200';
        case 'welfare':
            return 'bg-green-100 text-green-800 border-green-200';
        default:
            return 'bg-slate-100 text-slate-800 border-slate-200';
    }
};

const getPriorityColor = (priority: string) => {
    switch (priority) {
        case 'critical':
            return 'bg-red-100 text-red-800 border-red-200';
        case 'high':
            return 'bg-orange-100 text-orange-800 border-orange-200';
        case 'medium':
            return 'bg-yellow-100 text-yellow-800 border-yellow-200';
        case 'low':
            return 'bg-slate-100 text-slate-800 border-slate-200';
        default:
            return 'bg-slate-100 text-slate-800 border-slate-200';
    }
};

const categories = ['disciplinary', 'grievance', 'capability', 'absence', 'conduct', 'welfare'];
const statuses = ['open', 'under_investigation', 'pending_action', 'resolved', 'closed', 'appealed'];

export default function HrCasesIndex({ cases, filters, can }: Props) {
    const NONE = '__none__';

    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/hr/cases', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="HR Cases" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">HR Cases</h1>
                        <div className="mt-1 text-sm text-slate-500">
                            Manage disciplinary, grievance, and other HR cases
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {can.manage && (
                            <Link href="/hr/cases/create">
                                <Button size="sm">
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    Open Case
                                </Button>
                            </Link>
                        )}
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-4">
                        <div className="sm:col-span-2">
                            <Label className="text-xs text-slate-500">Search</Label>
                            <div className="relative">
                                <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-slate-400" />
                                <Input
                                    placeholder="Search by case number or subject..."
                                    value={filters.q || ''}
                                    onChange={(e) => onFilter({ q: e.target.value })}
                                    className="pl-9"
                                />
                            </div>
                        </div>

                        <div>
                            <Label className="text-xs text-slate-500">Status</Label>
                            <Select
                                value={filters.status ?? NONE}
                                onValueChange={(v) => onFilter({ status: v === NONE ? null : v })}
                            >
                                <SelectTrigger><SelectValue placeholder="All statuses" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>All Statuses</SelectItem>
                                    {statuses.map((s) => (
                                        <SelectItem key={s} value={s} className="capitalize">
                                            {s.replace(/_/g, ' ')}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div>
                            <Label className="text-xs text-slate-500">Category</Label>
                            <Select
                                value={filters.category ?? NONE}
                                onValueChange={(v) => onFilter({ category: v === NONE ? null : v })}
                            >
                                <SelectTrigger><SelectValue placeholder="All categories" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>All Categories</SelectItem>
                                    {categories.map((c) => (
                                        <SelectItem key={c} value={c} className="capitalize">
                                            {c.replace(/_/g, ' ')}
                                        </SelectItem>
                                    ))}
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
                                    <TableHead>Case Number</TableHead>
                                    <TableHead>Subject</TableHead>
                                    <TableHead>Category</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Priority</TableHead>
                                    <TableHead>Opened</TableHead>
                                    <TableHead>Closed</TableHead>
                                    <TableHead className="w-20"></TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {cases.data.map((hrCase) => (
                                    <TableRow key={hrCase.id}>
                                        <TableCell className="font-medium">
                                            <div className="flex items-center gap-2">
                                                <Briefcase className="h-4 w-4 text-slate-400" />
                                                {hrCase.case_number}
                                            </div>
                                        </TableCell>
                                        <TableCell>{hrCase.subject_user.name}</TableCell>
                                        <TableCell>
                                            <Badge className={getCategoryColor(hrCase.category)}>
                                                {hrCase.category.replace(/_/g, ' ')}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>
                                            <Badge className={getStatusColor(hrCase.status)}>
                                                {hrCase.status.replace(/_/g, ' ')}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>
                                            <Badge className={getPriorityColor(hrCase.priority)}>
                                                {hrCase.priority}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>{formatDate(hrCase.opened_at)}</TableCell>
                                        <TableCell>{formatDate(hrCase.closed_at)}</TableCell>
                                        <TableCell>
                                            <Link
                                                href={`/hr/cases/${hrCase.id}`}
                                                className="rounded-md border px-3 py-1.5 text-xs hover:bg-muted"
                                            >
                                                View
                                            </Link>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {!cases.data.length && (
                                    <TableRow>
                                        <TableCell colSpan={8} className="py-8 text-center text-sm text-slate-500">
                                            No HR cases found.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {cases?.links?.length ? (
                    <div className="flex flex-wrap gap-2">
                        {cases.links.map((l: any) => (
                            <button
                                key={l.label}
                                disabled={!l.url}
                                className={`rounded-md border px-3 py-2 text-xs ${l.active ? 'bg-muted' : 'hover:bg-muted'}`}
                                onClick={() => l.url && router.get(l.url, {}, { preserveState: true, preserveScroll: true })}
                                dangerouslySetInnerHTML={{ __html: l.label }}
                            />
                        ))}
                    </div>
                ) : null}
            </div>
        </AppLayout>
    );
}
