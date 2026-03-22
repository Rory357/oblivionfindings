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
import { FileText, CheckCircle2, XCircle, Send } from 'lucide-react';
import { useState } from 'react';
import { Input } from '@/components/ui/input';

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

export default function TimesheetsIndex({ timesheets, filters, can }: Props) {
    const [rejectId, setRejectId] = useState<number | null>(null);
    const [rejectReason, setRejectReason] = useState('');

    function updateFilter(key: string, value: string | null) {
        const newFilters = { ...filters, [key]: value };
        if (value === null || value === 'all') {
            delete newFilters[key as keyof typeof newFilters];
        }
        router.get('/hr/time/timesheets', newFilters, { preserveState: true, replace: true });
    }

    function handleSubmit(id: number) {
        router.post(`/hr/time/timesheets/${id}/submit`, {}, { preserveScroll: true });
    }

    function handleApprove(id: number) {
        router.post(`/hr/time/timesheets/${id}/approve`, {}, { preserveScroll: true });
    }

    function handleReject(id: number) {
        if (!rejectReason.trim()) return;
        router.post(`/hr/time/timesheets/${id}/reject`, { rejection_reason: rejectReason }, {
            preserveScroll: true,
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
                            <div className="py-12 text-center text-muted-foreground">
                                <FileText className="mx-auto mb-3 h-12 w-12 opacity-50" />
                                <p>No timesheets found.</p>
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
                                                        {ts.period_start} - {ts.period_end}
                                                    </td>
                                                    <td className="px-4 py-3 text-right font-medium">{ts.total_hours}h</td>
                                                    <td className="px-4 py-3">
                                                        <Badge variant="outline" className={config.className}>
                                                            {config.label}
                                                        </Badge>
                                                    </td>
                                                    <td className="px-4 py-3 text-muted-foreground">
                                                        {ts.submitted_at ?? '-'}
                                                    </td>
                                                    <td className="px-4 py-3 text-right">
                                                        <div className="flex items-center justify-end gap-2">
                                                            {ts.status === 'draft' && (
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    onClick={() => handleSubmit(ts.id)}
                                                                >
                                                                    <Send className="mr-1 h-3 w-3" />
                                                                    Submit
                                                                </Button>
                                                            )}
                                                            {can.approve && ts.status === 'submitted' && (
                                                                <>
                                                                    <Button
                                                                        variant="outline"
                                                                        size="sm"
                                                                        className="border-emerald-500/30 text-emerald-400 hover:bg-emerald-500/10"
                                                                        onClick={() => handleApprove(ts.id)}
                                                                    >
                                                                        <CheckCircle2 className="mr-1 h-3 w-3" />
                                                                        Approve
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
                                                                                onClick={() => handleReject(ts.id)}
                                                                            >
                                                                                Reject
                                                                            </Button>
                                                                            <Button
                                                                                variant="ghost"
                                                                                size="sm"
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
                        <div className="flex items-center gap-1">
                            {timesheets.links.map((link, i) => (
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
