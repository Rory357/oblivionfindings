import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type BreadcrumbItem } from '@/types';
import { LaravelPagination } from '@/components/ui/laravel-pagination';

interface DriverRecord {
    id: number;
    user: { id: number; name: string };
    licence_class: string;
    licence_number: string;
    licence_expiry?: string | null;
    licence_expires_at?: string | null;
    status: 'eligible' | 'pending_review' | 'suspended' | 'expired';
    approved_at: string | null;
    suspended_at: string | null;
}

interface Props {
    records: {
        data: DriverRecord[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    summary: {
        total: number;
        eligible: number;
        expiring: number;
        pending: number;
        suspended: number;
    };
    filters: { status: string | null; q: string };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Driver Eligibility', href: '/hr/compliance/drivers' },
];

const statusConfig: Record<string, { className: string; label: string }> = {
    eligible: {
        className: 'border-status-success/30 text-status-success bg-status-success',
        label: 'Eligible',
    },
    pending_review: {
        className: 'border-status-warning/30 text-status-warning bg-status-warning',
        label: 'Pending Review',
    },
    suspended: {
        className: 'border-status-critical/30 text-status-critical bg-status-critical',
        label: 'Suspended',
    },
    expired: {
        className: 'border-border/30 text-muted-foreground bg-muted-foreground/80/10',
        label: 'Expired',
    },
};

export default function DriversIndex({ records, summary, filters }: Props) {
    function applyFilter(key: string, value: string | null) {
        router.get('/hr/compliance/drivers', { ...filters, [key]: value || undefined }, { preserveState: true, replace: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Driver Eligibility" />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold">Driver Eligibility Register</h1>
                </div>

                {/* Summary Cards */}
                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-5">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Total</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold">{summary.total}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Eligible</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold text-status-success">{summary.eligible}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Pending</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold text-status-warning">{summary.pending}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Suspended</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold text-status-critical">{summary.suspended}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Expiring</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold text-muted-foreground">{summary.expiring}</p>
                        </CardContent>
                    </Card>
                </div>

                {/* Filters */}
                <div className="flex flex-wrap items-center gap-3">
                    <Input
                        placeholder="Search by name or licence..."
                        defaultValue={filters.q}
                        className="w-64"
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') applyFilter('q', (e.target as HTMLInputElement).value);
                        }}
                    />
                    <Select value={filters.status || '__none__'} onValueChange={(v) => applyFilter('status', v === '__none__' ? null : v)}>
                        <SelectTrigger className="w-40">
                            <SelectValue placeholder="All Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__none__">All Status</SelectItem>
                            <SelectItem value="eligible">Eligible</SelectItem>
                            <SelectItem value="pending_review">Pending Review</SelectItem>
                            <SelectItem value="expiring">Expiring</SelectItem>
                            <SelectItem value="suspended">Suspended</SelectItem>
                            <SelectItem value="expired">Expired</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                {/* Table */}
                <Card>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">Name</th>
                                    <th className="px-4 py-3 text-left font-medium">Licence Class</th>
                                    <th className="px-4 py-3 text-left font-medium">Licence Number</th>
                                    <th className="px-4 py-3 text-left font-medium">Expiry</th>
                                    <th className="px-4 py-3 text-left font-medium">Status</th>
                                    <th className="px-4 py-3 text-left font-medium">Approved</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {records.data.map((record) => {
                                    const config = statusConfig[record.status] || statusConfig.pending;
                                    return (
                                        <tr key={record.id} className="hover:bg-muted/30">
                                            <td className="px-4 py-3 font-medium">{record.user.name}</td>
                                            <td className="px-4 py-3">{record.licence_class}</td>
                                            <td className="px-4 py-3 text-muted-foreground">{record.licence_number}</td>
                                            <td className="px-4 py-3 text-muted-foreground">{record.licence_expires_at || record.licence_expiry || '-'}</td>
                                            <td className="px-4 py-3">
                                                <Badge variant="outline" className={config.className}>
                                                    {config.label}
                                                </Badge>
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {record.approved_at || '\u2014'}
                                            </td>
                                        </tr>
                                    );
                                })}
                                {records.data.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="px-4 py-8 text-center text-muted-foreground">
                                            No driver eligibility records found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                {/* Pagination */}
                {records.last_page > 1 && (
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            Showing {(records.current_page - 1) * records.per_page + 1} to{' '}
                            {Math.min(records.current_page * records.per_page, records.total)} of{' '}
                            {records.total} results
                        </p>
                        <LaravelPagination links={records.links} />
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
