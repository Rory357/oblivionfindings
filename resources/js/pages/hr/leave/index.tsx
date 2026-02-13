import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import PageHeader from '@/components/page-header';
import { Head, Link, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { CalendarDays, Plus, Clock, CheckCircle2, XCircle } from 'lucide-react';

type LeaveRequest = {
    id: number;
    staff_name: string;
    staff_id: number;
    leave_type: string;
    start_date: string;
    end_date: string;
    hours: number;
    status: 'pending' | 'approved' | 'declined' | 'cancelled';
    reason?: string | null;
    reviewed_by?: string | null;
};

type PaginatedRequests = {
    data: LeaveRequest[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
};

type Props = {
    requests: PaginatedRequests;
    filters: {
        status?: string;
        leave_type?: string;
    };
    can: {
        approve?: boolean;
        manage?: boolean;
        create?: boolean;
    };
};

const breadcrumbs = [
    { title: 'HR', href: '/hr' },
    { title: 'Leave', href: '/hr/leave' },
];

const statusConfig: Record<string, { className: string; label: string }> = {
    pending: {
        className: 'border-yellow-500/30 text-yellow-400 bg-yellow-500/10',
        label: 'Pending',
    },
    approved: {
        className: 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10',
        label: 'Approved',
    },
    declined: {
        className: 'border-red-500/30 text-red-400 bg-red-500/10',
        label: 'Declined',
    },
    cancelled: {
        className: 'border-slate-500/30 text-slate-400',
        label: 'Cancelled',
    },
};

export default function LeaveIndex({ requests, filters, can }: Props) {
    const pendingRequests = requests.data.filter((r) => r.status === 'pending');
    const allRequests = requests.data;

    const updateFilter = (key: string, value: string | null) => {
        const newFilters = { ...filters, [key]: value };
        if (value === null || value === 'all') {
            delete newFilters[key as keyof typeof newFilters];
        }
        router.get('/hr/leave', newFilters, { preserveState: true, replace: true });
    };

    function handleApprove(requestId: number) {
        router.post(`/hr/leave/${requestId}/approve`, {}, { preserveScroll: true });
    }

    function handleDecline(requestId: number) {
        router.post(`/hr/leave/${requestId}/decline`, {}, { preserveScroll: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Leave Requests" />

            <PageShell>
                <PageHeader
                    title="Leave Requests"
                    description="Manage leave requests and approvals for all staff."
                    actions={
                        can.create ? (
                            <Button asChild>
                                <Link href="/hr/leave/create">
                                    <Plus className="h-4 w-4 mr-2" />
                                    New Request
                                </Link>
                            </Button>
                        ) : undefined
                    }
                />

                {/* Pending Approval Section */}
                {can.approve && pendingRequests.length > 0 && (
                    <Card className="border-yellow-500/20 bg-yellow-500/5">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Clock className="h-5 w-5 text-yellow-400" />
                                Pending Approval ({pendingRequests.length})
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-hidden rounded-xl border">
                                <table className="w-full text-sm">
                                    <thead className="border-b bg-slate-50/5">
                                        <tr>
                                            <th className="px-4 py-3 text-left font-medium">Staff Name</th>
                                            <th className="px-4 py-3 text-left font-medium">Leave Type</th>
                                            <th className="px-4 py-3 text-left font-medium">Dates</th>
                                            <th className="px-4 py-3 text-left font-medium">Hours</th>
                                            <th className="px-4 py-3 text-right font-medium">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {pendingRequests.map((request) => (
                                            <tr key={request.id} className="border-b last:border-b-0 hover:bg-muted/50">
                                                <td className="px-4 py-3 font-medium">{request.staff_name}</td>
                                                <td className="px-4 py-3 text-muted-foreground">{request.leave_type}</td>
                                                <td className="px-4 py-3 text-muted-foreground">
                                                    {request.start_date} - {request.end_date}
                                                </td>
                                                <td className="px-4 py-3 text-muted-foreground">{request.hours}h</td>
                                                <td className="px-4 py-3 text-right">
                                                    <div className="flex items-center justify-end gap-2">
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            className="border-emerald-500/30 text-emerald-400 hover:bg-emerald-500/10"
                                                            onClick={() => handleApprove(request.id)}
                                                        >
                                                            <CheckCircle2 className="h-3 w-3 mr-1" />
                                                            Approve
                                                        </Button>
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            className="border-red-500/30 text-red-400 hover:bg-red-500/10"
                                                            onClick={() => handleDecline(request.id)}
                                                        >
                                                            <XCircle className="h-3 w-3 mr-1" />
                                                            Decline
                                                        </Button>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Filters */}
                <div className="flex flex-wrap items-center gap-2 p-3 rounded-lg border bg-card">
                    <Select
                        value={filters.status ?? 'all'}
                        onValueChange={(v) => updateFilter('status', v === 'all' ? null : v)}
                    >
                        <SelectTrigger className="w-[140px]">
                            <SelectValue placeholder="All Statuses" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Statuses</SelectItem>
                            <SelectItem value="pending">Pending</SelectItem>
                            <SelectItem value="approved">Approved</SelectItem>
                            <SelectItem value="declined">Declined</SelectItem>
                            <SelectItem value="cancelled">Cancelled</SelectItem>
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.leave_type ?? 'all'}
                        onValueChange={(v) => updateFilter('leave_type', v === 'all' ? null : v)}
                    >
                        <SelectTrigger className="w-[160px]">
                            <SelectValue placeholder="All Leave Types" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Leave Types</SelectItem>
                            <SelectItem value="annual">Annual</SelectItem>
                            <SelectItem value="sick">Sick</SelectItem>
                            <SelectItem value="bereavement">Bereavement</SelectItem>
                            <SelectItem value="parental">Parental</SelectItem>
                            <SelectItem value="other">Other</SelectItem>
                        </SelectContent>
                    </Select>

                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => router.get('/hr/leave', {}, { preserveState: true })}
                    >
                        Clear Filters
                    </Button>
                </div>

                {/* All Requests Table */}
                <Card>
                    <CardHeader>
                        <CardTitle>All Requests</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        {allRequests.length === 0 ? (
                            <div className="text-center py-12 text-muted-foreground">
                                <CalendarDays className="h-12 w-12 mx-auto mb-3 opacity-50" />
                                <p>No leave requests found.</p>
                            </div>
                        ) : (
                            <div className="overflow-hidden rounded-xl border">
                                <table className="w-full text-sm">
                                    <thead className="border-b bg-slate-50/5">
                                        <tr>
                                            <th className="px-4 py-3 text-left font-medium">Staff Name</th>
                                            <th className="px-4 py-3 text-left font-medium">Leave Type</th>
                                            <th className="px-4 py-3 text-left font-medium">Dates</th>
                                            <th className="px-4 py-3 text-left font-medium">Hours</th>
                                            <th className="px-4 py-3 text-left font-medium">Status</th>
                                            <th className="px-4 py-3 text-right font-medium">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {allRequests.map((request) => {
                                            const config = statusConfig[request.status] || statusConfig.pending;
                                            return (
                                                <tr key={request.id} className="border-b last:border-b-0 hover:bg-muted/50">
                                                    <td className="px-4 py-3 font-medium">{request.staff_name}</td>
                                                    <td className="px-4 py-3 text-muted-foreground">{request.leave_type}</td>
                                                    <td className="px-4 py-3 text-muted-foreground">
                                                        {request.start_date} - {request.end_date}
                                                    </td>
                                                    <td className="px-4 py-3 text-muted-foreground">{request.hours}h</td>
                                                    <td className="px-4 py-3">
                                                        <Badge variant="outline" className={config.className}>
                                                            {config.label}
                                                        </Badge>
                                                    </td>
                                                    <td className="px-4 py-3 text-right">
                                                        <div className="flex items-center justify-end gap-2">
                                                            <Button variant="ghost" size="sm" asChild>
                                                                <Link href={`/hr/leave/${request.id}`}>View</Link>
                                                            </Button>
                                                            {can.approve && request.status === 'pending' && (
                                                                <>
                                                                    <Button
                                                                        variant="outline"
                                                                        size="sm"
                                                                        className="border-emerald-500/30 text-emerald-400 hover:bg-emerald-500/10"
                                                                        onClick={() => handleApprove(request.id)}
                                                                    >
                                                                        Approve
                                                                    </Button>
                                                                    <Button
                                                                        variant="outline"
                                                                        size="sm"
                                                                        className="border-red-500/30 text-red-400 hover:bg-red-500/10"
                                                                        onClick={() => handleDecline(request.id)}
                                                                    >
                                                                        Decline
                                                                    </Button>
                                                                </>
                                                            )}
                                                        </div>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Pagination */}
                {requests.last_page > 1 && (
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            Showing {(requests.current_page - 1) * requests.per_page + 1} to{' '}
                            {Math.min(requests.current_page * requests.per_page, requests.total)} of{' '}
                            {requests.total} results
                        </p>
                        <div className="flex items-center gap-1">
                            {requests.links.map((link, i) => (
                                <Button
                                    key={i}
                                    variant={link.active ? 'default' : 'outline'}
                                    size="sm"
                                    disabled={!link.url}
                                    onClick={() => link.url && router.get(link.url)}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
