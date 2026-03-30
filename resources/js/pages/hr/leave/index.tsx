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
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { AlertTriangle, CalendarDays, Plus, Clock, CheckCircle2, XCircle, Loader2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';

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
    approval_due_at?: string | null;
    is_overdue?: boolean;
    due_within_24h?: boolean;
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
        sla?: string | null;
    };
    sla: {
        pending_total: number;
        overdue_count: number;
        due_within_24h_count: number;
        oldest_pending_hours: number;
        avg_decision_hours_30d: number;
        pending_by_type: Record<string, number>;
    };
    pendingAging: Array<{
        id: number;
        staff_name: string;
        leave_type: string;
        submitted_at: string | null;
        approval_due_at: string | null;
        hours_waiting: number;
    }>;
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

type StatusVariant = 'default' | 'secondary' | 'destructive' | 'outline';

const statusConfig: Record<string, { variant: StatusVariant; className: string; label: string }> = {
    pending: {
        variant: 'outline',
        className: 'border-yellow-500/30 text-yellow-400 bg-yellow-500/10',
        label: 'Pending',
    },
    approved: {
        variant: 'outline',
        className: 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10',
        label: 'Approved',
    },
    declined: {
        variant: 'destructive',
        className: '',
        label: 'Declined',
    },
    cancelled: {
        variant: 'secondary',
        className: '',
        label: 'Cancelled',
    },
};

function StatusBadge({ status }: { status: string }) {
    const config = statusConfig[status] || statusConfig.pending;
    return (
        <Badge variant={config.variant} className={config.className || undefined}>
            {config.label}
        </Badge>
    );
}

function SlaBadge({ request }: { request: LeaveRequest }) {
    if (request.is_overdue) {
        return (
            <Badge variant="destructive" className="ml-2 gap-1">
                <AlertTriangle className="h-3 w-3" />
                Overdue
            </Badge>
        );
    }
    if (request.due_within_24h) {
        return (
            <Badge variant="outline" className="ml-2 gap-1 border-amber-500/30 text-amber-400 bg-amber-500/10">
                <Clock className="h-3 w-3" />
                Due in 24h
            </Badge>
        );
    }
    return null;
}

export default function LeaveIndex({ requests, filters, sla, pendingAging, can }: Props) {
    const pendingRequests = requests.data.filter((r) => r.status === 'pending');
    const allRequests = requests.data;
    const [selectedRequestIds, setSelectedRequestIds] = useState<number[]>([]);
    const [declineDialogOpen, setDeclineDialogOpen] = useState(false);
    const [declineTarget, setDeclineTarget] = useState<{ type: 'single'; id: number } | { type: 'bulk' } | null>(null);
    const [declineNotes, setDeclineNotes] = useState('');
    const [processing, setProcessing] = useState(false);
    const [bulkApproveDialogOpen, setBulkApproveDialogOpen] = useState(false);
    const selectedPendingIds = useMemo(
        () => selectedRequestIds.filter((id) => pendingRequests.some((request) => request.id === id)),
        [selectedRequestIds, pendingRequests],
    );

    const updateFilter = (key: string, value: string | null) => {
        const newFilters = { ...filters, [key]: value };
        if (value === null || value === 'all') {
            delete newFilters[key as keyof typeof newFilters];
        }
        router.get('/hr/leave', newFilters, { preserveState: true, replace: true });
    };

    function handleApprove(requestId: number) {
        setProcessing(true);
        router.post(`/hr/leave/${requestId}/approve`, {}, {
            preserveScroll: true,
            onFinish: () => setProcessing(false),
        });
    }

    function handleDecline(requestId: number) {
        setDeclineTarget({ type: 'single', id: requestId });
        setDeclineNotes('');
        setDeclineDialogOpen(true);
    }

    function toggleRequestSelection(requestId: number, checked: boolean) {
        setSelectedRequestIds((current) => {
            if (checked) {
                return current.includes(requestId) ? current : [...current, requestId];
            }
            return current.filter((id) => id !== requestId);
        });
    }

    function toggleSelectAllPending(checked: boolean) {
        setSelectedRequestIds(checked ? pendingRequests.map((request) => request.id) : []);
    }

    function handleBulkApprove() {
        if (selectedPendingIds.length === 0) return;
        setBulkApproveDialogOpen(true);
    }

    function confirmBulkApprove() {
        setProcessing(true);
        setBulkApproveDialogOpen(false);
        router.post('/hr/leave/bulk-approve', {
            request_ids: selectedPendingIds,
        }, {
            preserveScroll: true,
            onFinish: () => setProcessing(false),
            onSuccess: () => setSelectedRequestIds([]),
        });
    }

    function handleBulkDecline() {
        if (selectedPendingIds.length === 0) return;
        setDeclineTarget({ type: 'bulk' });
        setDeclineNotes('');
        setDeclineDialogOpen(true);
    }

    function submitDecline() {
        if (!declineNotes.trim() || !declineTarget) return;

        setProcessing(true);
        if (declineTarget.type === 'single') {
            router.post(`/hr/leave/${declineTarget.id}/decline`, { review_notes: declineNotes.trim() }, {
                preserveScroll: true,
                onFinish: () => setProcessing(false),
                onSuccess: () => setDeclineDialogOpen(false),
            });
        } else {
            router.post('/hr/leave/bulk-decline', {
                request_ids: selectedPendingIds,
                review_notes: declineNotes.trim(),
            }, {
                preserveScroll: true,
                onFinish: () => setProcessing(false),
                onSuccess: () => {
                    setSelectedRequestIds([]);
                    setDeclineDialogOpen(false);
                },
            });
        }
    }

    function extendSlaByHours(requestId: number, hours: number) {
        router.post(`/hr/leave/${requestId}/sla-due`, { hours }, { preserveScroll: true });
    }

    function escalateNow() {
        router.post('/hr/leave/escalate-now', {}, { preserveScroll: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Leave Requests" />

            <PageShell>
                <PageHeader
                    title="Leave Requests"
                    description="Manage leave requests and approvals for all staff."
                    actions={
                        <div className="flex items-center gap-2">
                            <Button variant="outline" asChild>
                                <Link href="/hr/leave/balances">Balances</Link>
                            </Button>
                            {can.approve && (
                                <Button variant="outline" onClick={escalateNow}>
                                    Escalate Overdue Now
                                </Button>
                            )}
                            {can.create && (
                                <Button asChild>
                                    <Link href="/hr/leave/create">
                                        <Plus className="h-4 w-4 mr-2" />
                                        New Request
                                    </Link>
                                </Button>
                            )}
                        </div>
                    }
                />

                <div className="grid gap-4 md:grid-cols-4">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground">Pending Queue</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-semibold">{sla.pending_total}</p>
                        </CardContent>
                    </Card>
                    <Card className={sla.overdue_count > 0 ? 'border-red-500/40' : ''}>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground">Overdue</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className={`text-2xl font-semibold ${sla.overdue_count > 0 ? 'text-red-500' : ''}`}>
                                {sla.overdue_count}
                            </p>
                        </CardContent>
                    </Card>
                    <Card className={sla.due_within_24h_count > 0 ? 'border-amber-500/40' : ''}>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground">Due in 24h</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className={`text-2xl font-semibold ${sla.due_within_24h_count > 0 ? 'text-amber-500' : ''}`}>
                                {sla.due_within_24h_count}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm text-muted-foreground">Avg Decision (30d)</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-semibold">{sla.avg_decision_hours_30d.toFixed(1)}h</p>
                        </CardContent>
                    </Card>
                </div>

                {/* Pending Approval Section */}
                {can.approve && pendingRequests.length > 0 && (
                    <Card className="border-yellow-500/20 bg-yellow-500/5">
                        <CardHeader>
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <CardTitle className="flex items-center gap-2">
                                    <Clock className="h-5 w-5 text-yellow-400" />
                                    Pending Approval ({pendingRequests.length})
                                </CardTitle>
                                <div className="flex items-center gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={handleBulkApprove}
                                        disabled={selectedPendingIds.length === 0 || processing}
                                    >
                                        {processing ? <Loader2 className="h-3 w-3 mr-1 animate-spin" /> : null}
                                        Approve Selected ({selectedPendingIds.length})
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="border-red-500/30 text-red-400 hover:bg-red-500/10"
                                        onClick={handleBulkDecline}
                                        disabled={selectedPendingIds.length === 0 || processing}
                                    >
                                        Decline Selected
                                    </Button>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-hidden rounded-xl border">
                                <table className="w-full text-sm">
                                    <thead className="border-b bg-slate-50/5">
                                        <tr>
                                            <th className="px-4 py-3 text-left font-medium">
                                                <input
                                                    type="checkbox"
                                                    checked={pendingRequests.length > 0 && selectedPendingIds.length === pendingRequests.length}
                                                    onChange={(event) => toggleSelectAllPending(event.target.checked)}
                                                />
                                            </th>
                                            <th className="px-4 py-3 text-left font-medium">Staff Name</th>
                                            <th className="px-4 py-3 text-left font-medium">Leave Type</th>
                                            <th className="px-4 py-3 text-left font-medium">Dates</th>
                                            <th className="px-4 py-3 text-left font-medium">Hours</th>
                                            <th className="px-4 py-3 text-left font-medium">SLA Due</th>
                                            <th className="px-4 py-3 text-right font-medium">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {pendingRequests.map((request) => (
                                            <tr key={request.id} className="border-b last:border-b-0 hover:bg-muted/50">
                                                <td className="px-4 py-3">
                                                    <input
                                                        type="checkbox"
                                                        checked={selectedPendingIds.includes(request.id)}
                                                        onChange={(event) => toggleRequestSelection(request.id, event.target.checked)}
                                                    />
                                                </td>
                                                <td className="px-4 py-3 font-medium">{request.staff_name}</td>
                                                <td className="px-4 py-3 text-muted-foreground">{request.leave_type}</td>
                                                <td className="px-4 py-3 text-muted-foreground">
                                                    {request.start_date} - {request.end_date}
                                                </td>
                                                <td className="px-4 py-3 text-muted-foreground">{request.hours}h</td>
                                                <td className="px-4 py-3 text-muted-foreground">
                                                    {request.approval_due_at || '-'}
                                                    <SlaBadge request={request} />
                                                </td>
                                                <td className="px-4 py-3 text-right">
                                                    <div className="flex items-center justify-end gap-2">
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            className="border-emerald-500/30 text-emerald-400 hover:bg-emerald-500/10"
                                                            onClick={() => handleApprove(request.id)}
                                                            disabled={processing}
                                                        >
                                                            <CheckCircle2 className="h-3 w-3 mr-1" />
                                                            Approve
                                                        </Button>
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            className="border-red-500/30 text-red-400 hover:bg-red-500/10"
                                                            onClick={() => handleDecline(request.id)}
                                                            disabled={processing}
                                                        >
                                                            <XCircle className="h-3 w-3 mr-1" />
                                                            Decline
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() => extendSlaByHours(request.id, 24)}
                                                            disabled={processing}
                                                        >
                                                            +24h SLA
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

                    <Select
                        value={filters.sla ?? 'all'}
                        onValueChange={(v) => updateFilter('sla', v === 'all' ? null : v)}
                    >
                        <SelectTrigger className="w-[170px]">
                            <SelectValue placeholder="All SLA Windows" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All SLA Windows</SelectItem>
                            <SelectItem value="overdue">Overdue only</SelectItem>
                            <SelectItem value="due_24h">Due within 24h</SelectItem>
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
                                        {allRequests.map((request) => (
                                                <tr key={request.id} className="border-b last:border-b-0 hover:bg-muted/50">
                                                    <td className="px-4 py-3 font-medium">{request.staff_name}</td>
                                                    <td className="px-4 py-3 text-muted-foreground">{request.leave_type}</td>
                                                    <td className="px-4 py-3 text-muted-foreground">
                                                        {request.start_date} - {request.end_date}
                                                        <SlaBadge request={request} />
                                                    </td>
                                                    <td className="px-4 py-3 text-muted-foreground">{request.hours}h</td>
                                                    <td className="px-4 py-3">
                                                        <StatusBadge status={request.status} />
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
                                                                        disabled={processing}
                                                                    >
                                                                        Approve
                                                                    </Button>
                                                                    <Button
                                                                        variant="outline"
                                                                        size="sm"
                                                                        className="border-red-500/30 text-red-400 hover:bg-red-500/10"
                                                                        onClick={() => handleDecline(request.id)}
                                                                        disabled={processing}
                                                                    >
                                                                        Decline
                                                                    </Button>
                                                                </>
                                                            )}
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {pendingAging.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Longest Waiting Pending Requests</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {pendingAging.map((row) => (
                                <div key={row.id} className="flex items-center justify-between rounded-md border p-2 text-sm">
                                    <div>
                                        <p className="font-medium">{row.staff_name}</p>
                                        <p className="text-xs text-muted-foreground capitalize">
                                            {row.leave_type.replace('_', ' ')} · submitted {row.submitted_at || '-'}
                                        </p>
                                    </div>
                                    <div className="text-right">
                                        <p className="font-medium">{row.hours_waiting.toFixed(1)}h waiting</p>
                                        <p className="text-xs text-muted-foreground">
                                            Due: {row.approval_due_at || '-'}
                                        </p>
                                    </div>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                )}

                {/* Pagination */}
                {requests.total > 0 && (
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            Showing {(requests.current_page - 1) * requests.per_page + 1} to{' '}
                            {Math.min(requests.current_page * requests.per_page, requests.total)} of{' '}
                            {requests.total} {requests.total === 1 ? 'result' : 'results'}
                        </p>
                        {requests.last_page > 1 && (
                            <LaravelPagination links={requests.links} />
                        )}
                    </div>
                )}
            </PageShell>

            {/* Bulk Approve Confirmation */}
            <AlertDialog open={bulkApproveDialogOpen} onOpenChange={setBulkApproveDialogOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Approve {selectedPendingIds.length} Leave Request{selectedPendingIds.length === 1 ? '' : 's'}?</AlertDialogTitle>
                        <AlertDialogDescription>
                            This will approve {selectedPendingIds.length} pending leave{' '}
                            {selectedPendingIds.length === 1 ? 'request' : 'requests'}. This action cannot be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={confirmBulkApprove}>
                            Approve {selectedPendingIds.length} Request{selectedPendingIds.length === 1 ? '' : 's'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {/* Decline Dialog */}
            <Dialog open={declineDialogOpen} onOpenChange={setDeclineDialogOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {declineTarget?.type === 'bulk'
                                ? `Decline ${selectedPendingIds.length} Leave Request(s)`
                                : 'Decline Leave Request'}
                        </DialogTitle>
                    </DialogHeader>
                    <div className="space-y-2">
                        <Label htmlFor="decline-notes">Reason for declining (required)</Label>
                        <Textarea
                            id="decline-notes"
                            value={declineNotes}
                            onChange={(e) => setDeclineNotes(e.target.value)}
                            placeholder="Enter the reason for declining this request..."
                            rows={3}
                        />
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDeclineDialogOpen(false)} disabled={processing}>Cancel</Button>
                        <Button variant="destructive" onClick={submitDecline} disabled={!declineNotes.trim() || processing}>
                            {processing ? <Loader2 className="h-4 w-4 mr-2 animate-spin" /> : null}
                            Decline
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
