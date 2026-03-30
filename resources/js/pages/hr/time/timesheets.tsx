import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import PageHeader from '@/components/page-header';
import { Head, router } from '@inertiajs/react';
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
import { FileText, CheckCircle2, XCircle, Send, ClipboardList } from 'lucide-react';
import { useState } from 'react';
import { Input } from '@/components/ui/input';
import { LaravelPagination } from '@/components/ui/laravel-pagination';

interface Timesheet {
    id: number;
    user_name: string;
    user_id: number;
    period_start: string;
    period_end: string;
    status: string;
    total_hours: number;
    submitted_at: string | null;
    approved_by: string | null;
    approved_at: string | null;
    rejection_reason: string | null;
}

interface Props {
    timesheets: {
        data: Timesheet[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    filters: {
        status?: string;
    };
    can: {
        manage?: boolean;
        approve?: boolean;
    };
}

const breadcrumbs = [
    { title: 'HR', href: '/hr' },
    { title: 'Time Tracking', href: '/hr/time' },
    { title: 'Timesheets', href: '/hr/time/timesheets' },
];

const statusConfig: Record<string, { className: string; label: string }> = {
    draft: { className: 'border-slate-500/30 text-slate-400 bg-slate-500/10', label: 'Draft' },
    submitted: { className: 'border-yellow-500/30 text-yellow-400 bg-yellow-500/10', label: 'Submitted' },
    approved: { className: 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10', label: 'Approved' },
    rejected: { className: 'border-red-500/30 text-red-400 bg-red-500/10', label: 'Rejected' },
};

function formatDate(dateStr: string): string {
    if (!dateStr) return '-';
    const d = new Date(dateStr);
    if (isNaN(d.getTime())) return dateStr;
    return d.toLocaleDateString('en-NZ', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function formatDateTime(dateStr: string | null): string {
    if (!dateStr) return '-';
    const d = new Date(dateStr);
    if (isNaN(d.getTime())) return dateStr;
    return d.toLocaleDateString('en-NZ', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

export default function TimesheetsIndex({ timesheets, filters, can }: Props) {
    const [rejectId, setRejectId] = useState<number | null>(null);
    const [rejectReason, setRejectReason] = useState('');
    const [processing, setProcessing] = useState<number | null>(null);
    const [confirmApproveId, setConfirmApproveId] = useState<number | null>(null);

    function updateFilter(key: string, value: string | null) {
        const newFilters = { ...filters, [key]: value };
        if (value === null || value === 'all') {
            delete newFilters[key as keyof typeof newFilters];
        }
        router.get('/hr/time/timesheets', newFilters, { preserveState: true, replace: true });
    }

    function handleSubmit(id: number) {
        setProcessing(id);
        router.post(`/hr/time/timesheets/${id}/submit`, {}, {
            preserveScroll: true,
            onFinish: () => setProcessing(null),
        });
    }

    function handleApprove(id: number) {
        setProcessing(id);
        setConfirmApproveId(null);
        router.post(`/hr/time/timesheets/${id}/approve`, {}, {
            preserveScroll: true,
            onFinish: () => setProcessing(null),
        });
    }

    function handleReject(id: number) {
        if (!rejectReason.trim()) return;
        setProcessing(id);
        router.post(`/hr/time/timesheets/${id}/reject`, { rejection_reason: rejectReason }, {
            preserveScroll: true,
            onFinish: () => setProcessing(null),
            onSuccess: () => {
                setRejectId(null);
                setRejectReason('');
            },
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Timesheets" />

            <PageShell>
                <PageHeader
                    title="Timesheets"
                    description="Review and manage weekly timesheets."
                    backHref="/hr/time"
                    backLabel="Back to Time Tracking"
                />

                {/* Filters */}
                <div className="flex flex-wrap items-center gap-2 rounded-lg border bg-card p-3">
                    <Select
                        value={filters.status ?? 'all'}
                        onValueChange={(v) => updateFilter('status', v === 'all' ? null : v)}
                    >
                        <SelectTrigger className="w-[140px]">
                            <SelectValue placeholder="All Statuses" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Statuses</SelectItem>
                            <SelectItem value="draft">Draft</SelectItem>
                            <SelectItem value="submitted">Submitted</SelectItem>
                            <SelectItem value="approved">Approved</SelectItem>
                            <SelectItem value="rejected">Rejected</SelectItem>
                        </SelectContent>
                    </Select>
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => router.get('/hr/time/timesheets', {}, { preserveState: true })}
                    >
                        Clear
                    </Button>
                </div>

                {/* Timesheets Table */}
                <Card>
                    <CardHeader>
                        <CardTitle>All Timesheets</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        {timesheets.data.length === 0 ? (
                            <div className="py-16 text-center text-muted-foreground">
                                <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-muted/50">
                                    <ClipboardList className="h-8 w-8 opacity-40" />
                                </div>
                                <p className="text-base font-medium">No timesheets found</p>
                                <p className="mt-1 text-sm">
                                    {filters.status
                                        ? `There are no timesheets with "${statusConfig[filters.status]?.label ?? filters.status}" status. Try clearing your filters.`
                                        : 'Timesheets will appear here once staff begin logging their hours.'}
                                </p>
                            </div>
                        ) : (
                            <div className="overflow-hidden rounded-xl border">
                                <table className="w-full text-sm">
                                    <thead className="border-b bg-slate-50/5">
                                        <tr>
                                            <th className="px-4 py-3 text-left font-medium">Staff</th>
                                            <th className="px-4 py-3 text-left font-medium">Period</th>
                                            <th className="px-4 py-3 text-right font-medium">Hours</th>
                                            <th className="px-4 py-3 text-left font-medium">Status</th>
                                            <th className="px-4 py-3 text-left font-medium">Submitted</th>
                                            <th className="px-4 py-3 text-right font-medium">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {timesheets.data.map((ts) => {
                                            const config = statusConfig[ts.status] || statusConfig.draft;
                                            return (
                                                <tr key={ts.id} className="border-b last:border-b-0 hover:bg-muted/50">
                                                    <td className="px-4 py-3 font-medium">{ts.user_name}</td>
                                                    <td className="px-4 py-3 text-muted-foreground">
                                                        {formatDate(ts.period_start)} &ndash; {formatDate(ts.period_end)}
                                                    </td>
                                                    <td className="px-4 py-3 text-right font-medium">{ts.total_hours}h</td>
                                                    <td className="px-4 py-3">
                                                        <Badge variant="outline" className={config.className}>
                                                            {config.label}
                                                        </Badge>
                                                    </td>
                                                    <td className="px-4 py-3 text-muted-foreground">
                                                        {formatDateTime(ts.submitted_at)}
                                                    </td>
                                                    <td className="px-4 py-3 text-right">
                                                        <div className="flex items-center justify-end gap-2">
                                                            {ts.status === 'draft' && (
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    disabled={processing === ts.id}
                                                                    onClick={() => handleSubmit(ts.id)}
                                                                >
                                                                    <Send className="mr-1 h-3 w-3" />
                                                                    {processing === ts.id ? 'Submitting...' : 'Submit'}
                                                                </Button>
                                                            )}
                                                            {can.approve && ts.status === 'submitted' && (
                                                                <>
                                                                    <Button
                                                                        variant="outline"
                                                                        size="sm"
                                                                        className="border-emerald-500/30 text-emerald-400 hover:bg-emerald-500/10"
                                                                        disabled={processing === ts.id}
                                                                        onClick={() => setConfirmApproveId(ts.id)}
                                                                    >
                                                                        <CheckCircle2 className="mr-1 h-3 w-3" />
                                                                        {processing === ts.id ? 'Approving...' : 'Approve'}
                                                                    </Button>
                                                                    {rejectId === ts.id ? (
                                                                        <div className="flex items-center gap-1">
                                                                            <Input
                                                                                placeholder="Reason..."
                                                                                value={rejectReason}
                                                                                onChange={(e) => setRejectReason(e.target.value)}
                                                                                className="h-8 w-[150px] text-xs"
                                                                            />
                                                                            <Button
                                                                                variant="destructive"
                                                                                size="sm"
                                                                                disabled={processing === ts.id || !rejectReason.trim()}
                                                                                onClick={() => handleReject(ts.id)}
                                                                            >
                                                                                {processing === ts.id ? 'Rejecting...' : 'Reject'}
                                                                            </Button>
                                                                            <Button
                                                                                variant="ghost"
                                                                                size="sm"
                                                                                disabled={processing === ts.id}
                                                                                onClick={() => { setRejectId(null); setRejectReason(''); }}
                                                                            >
                                                                                Cancel
                                                                            </Button>
                                                                        </div>
                                                                    ) : (
                                                                        <Button
                                                                            variant="outline"
                                                                            size="sm"
                                                                            className="border-red-500/30 text-red-400 hover:bg-red-500/10"
                                                                            disabled={processing === ts.id}
                                                                            onClick={() => setRejectId(ts.id)}
                                                                        >
                                                                            <XCircle className="mr-1 h-3 w-3" />
                                                                            Reject
                                                                        </Button>
                                                                    )}
                                                                </>
                                                            )}
                                                            {ts.rejection_reason && ts.status === 'rejected' && (
                                                                <span className="text-xs text-red-400" title={ts.rejection_reason}>
                                                                    Reason: {ts.rejection_reason.slice(0, 30)}...
                                                                </span>
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
                {timesheets.last_page > 1 && (
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            Showing {(timesheets.current_page - 1) * timesheets.per_page + 1} to{' '}
                            {Math.min(timesheets.current_page * timesheets.per_page, timesheets.total)} of{' '}
                            {timesheets.total} timesheets
                        </p>
                        <LaravelPagination links={timesheets.links} />
                    </div>
                )}
                {/* Approve Confirmation Dialog */}
                <AlertDialog open={confirmApproveId !== null} onOpenChange={(open) => { if (!open) setConfirmApproveId(null); }}>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>Approve Timesheet</AlertDialogTitle>
                            <AlertDialogDescription>
                                Are you sure you want to approve this timesheet? This will mark the hours as finalised and they may be forwarded to payroll.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                            <AlertDialogAction
                                onClick={() => confirmApproveId && handleApprove(confirmApproveId)}
                                className="bg-emerald-600 hover:bg-emerald-700"
                            >
                                Yes, Approve
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </PageShell>
        </AppLayout>
    );
}
