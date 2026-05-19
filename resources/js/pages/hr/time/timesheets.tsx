import PageShell from '@/components/page-shell';
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
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { PageHero } from '@/components/page';
import { useUndoableAction } from '@/hooks/use-undoable-action';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { CheckCircle2, ClipboardCheck, ClipboardList, Send, XCircle } from 'lucide-react';
import { useState } from 'react';

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
    { title: 'Timekeeping', href: '/hr/time' },
    { title: 'Period Timesheets', href: '/hr/time/timesheets' },
];

const statusConfig: Record<string, { className: string; label: string }> = {
    draft: {
        className:
            'border-border/30 text-muted-foreground bg-muted-foreground/80/10',
        label: 'Draft',
    },
    submitted: {
        className:
            'border-status-warning/30 text-status-warning bg-status-warning',
        label: 'Submitted',
    },
    approved: {
        className:
            'border-status-success/30 text-status-success bg-status-success',
        label: 'Approved',
    },
    returned: {
        className: 'border-status-info/30 text-status-info bg-status-info',
        label: 'Returned',
    },
    rejected: {
        className:
            'border-status-critical/30 text-status-critical bg-status-critical',
        label: 'Rejected',
    },
};

function formatDate(dateStr: string): string {
    if (!dateStr) return '-';
    const d = new Date(dateStr);
    if (isNaN(d.getTime())) return dateStr;
    return d.toLocaleDateString('en-NZ', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });
}

function formatDateTime(dateStr: string | null): string {
    if (!dateStr) return '-';
    const d = new Date(dateStr);
    if (isNaN(d.getTime())) return dateStr;
    return d.toLocaleDateString('en-NZ', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export default function TimesheetsIndex({ timesheets, filters, can }: Props) {
    const [rejectId, setRejectId] = useState<number | null>(null);
    const [rejectReason, setRejectReason] = useState('');
    const [processing, setProcessing] = useState<number | null>(null);
    const [confirmApproveId, setConfirmApproveId] = useState<number | null>(
        null,
    );

    function updateFilter(key: string, value: string | null) {
        const newFilters = { ...filters, [key]: value };
        if (value === null || value === 'all') {
            delete newFilters[key as keyof typeof newFilters];
        }
        router.get('/hr/time/timesheets', newFilters, {
            preserveState: true,
            replace: true,
        });
    }

    const { run: runUndoable } = useUndoableAction();

    function handleSubmit(id: number) {
        // PR 21 — delayed commit so an accidental tap on "Submit" in a
        // dense table gets a 5 s grace window. The POST only fires once
        // the timer elapses.
        setProcessing(id);
        runUndoable({
            message: 'Timesheet sending…',
            durationMs: 5000,
            onCommit: () => {
                router.post(
                    `/hr/time/timesheets/${id}/submit`,
                    {},
                    {
                        preserveScroll: true,
                        onFinish: () => setProcessing(null),
                    },
                );
            },
            onUndo: () => {
                setProcessing(null);
            },
            undoneMessage: 'Timesheet still in draft.',
        });
    }

    function handleApprove(id: number) {
        setProcessing(id);
        setConfirmApproveId(null);
        router.post(
            `/hr/time/timesheets/${id}/approve`,
            {},
            {
                preserveScroll: true,
                onFinish: () => setProcessing(null),
            },
        );
    }

    function handleReject(id: number) {
        if (!rejectReason.trim()) return;
        setProcessing(id);
        router.post(
            `/hr/time/timesheets/${id}/reject`,
            { rejection_reason: rejectReason },
            {
                preserveScroll: true,
                onFinish: () => setProcessing(null),
                onSuccess: () => {
                    setRejectId(null);
                    setRejectReason('');
                },
            },
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Period Timesheets" />

            <PageShell>
                <PageHero
                    icon={ClipboardCheck}
                    title="Period Timesheets"
                    description="Review and manage HR period timesheets."
                    stats={[
                        { label: 'Total', value: timesheets.total },
                        {
                            label: 'Submitted',
                            value: timesheets.data.filter((t) => t.status === 'submitted')
                                .length,
                        },
                        {
                            label: 'Approved',
                            value: timesheets.data.filter((t) => t.status === 'approved')
                                .length,
                        },
                    ]}
                />

                {/* Filters */}
                <Card className="flex flex-wrap items-center gap-2 p-3">
                    <Select
                        value={filters.status ?? 'all'}
                        onValueChange={(v) =>
                            updateFilter('status', v === 'all' ? null : v)
                        }
                    >
                        <SelectTrigger className="w-[140px]">
                            <SelectValue placeholder="All Statuses" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Statuses</SelectItem>
                            <SelectItem value="draft">Draft</SelectItem>
                            <SelectItem value="submitted">Submitted</SelectItem>
                            <SelectItem value="approved">Approved</SelectItem>
                            <SelectItem value="returned">Returned</SelectItem>
                            <SelectItem value="rejected">Rejected</SelectItem>
                        </SelectContent>
                    </Select>
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() =>
                            router.get(
                                '/hr/time/timesheets',
                                {},
                                { preserveState: true },
                            )
                        }
                    >
                        Clear
                    </Button>
                </Card>

                {/* Timesheets Table */}
                <Card>
                    <CardHeader>
                        <CardTitle>All Period Timesheets</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        {timesheets.data.length === 0 ? (
                            <div className="py-16 text-center text-muted-foreground">
                                <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-muted/50">
                                    <ClipboardList className="h-8 w-8 opacity-40" />
                                </div>
                                <p className="text-base font-medium">
                                    No timesheets found
                                </p>
                                <p className="mt-1 text-sm">
                                    {filters.status
                                        ? `There are no timesheets with "${statusConfig[filters.status]?.label ?? filters.status}" status. Try clearing your filters.`
                                        : 'Timesheets will appear here once staff begin logging their hours.'}
                                </p>
                            </div>
                        ) : (
                            <div className="overflow-hidden rounded-xl border">
                                <table className="w-full text-sm">
                                    <thead className="border-b bg-muted/5">
                                        <tr>
                                            <th className="px-4 py-3 text-left font-medium">
                                                Staff
                                            </th>
                                            <th className="px-4 py-3 text-left font-medium">
                                                Period
                                            </th>
                                            <th className="px-4 py-3 text-right font-medium">
                                                Hours
                                            </th>
                                            <th className="px-4 py-3 text-left font-medium">
                                                Status
                                            </th>
                                            <th className="px-4 py-3 text-left font-medium">
                                                Submitted
                                            </th>
                                            <th className="px-4 py-3 text-right font-medium">
                                                Actions
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {timesheets.data.map((ts) => {
                                            const config =
                                                statusConfig[ts.status] ||
                                                statusConfig.draft;
                                            return (
                                                <tr
                                                    key={ts.id}
                                                    className="border-b last:border-b-0 hover:bg-muted/50"
                                                >
                                                    <td className="px-4 py-3 font-medium">
                                                        {ts.user_name}
                                                    </td>
                                                    <td className="px-4 py-3 text-muted-foreground">
                                                        {formatDate(
                                                            ts.period_start,
                                                        )}{' '}
                                                        &ndash;{' '}
                                                        {formatDate(
                                                            ts.period_end,
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3 text-right font-medium">
                                                        {ts.total_hours}h
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <Badge
                                                            variant="outline"
                                                            className={
                                                                config.className
                                                            }
                                                        >
                                                            {config.label}
                                                        </Badge>
                                                    </td>
                                                    <td className="px-4 py-3 text-muted-foreground">
                                                        {formatDateTime(
                                                            ts.submitted_at,
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3 text-right">
                                                        <div className="flex items-center justify-end gap-2">
                                                            {ts.status ===
                                                                'draft' && (
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    disabled={
                                                                        processing ===
                                                                        ts.id
                                                                    }
                                                                    onClick={() =>
                                                                        handleSubmit(
                                                                            ts.id,
                                                                        )
                                                                    }
                                                                >
                                                                    <Send className="mr-1 h-3 w-3" />
                                                                    {processing ===
                                                                    ts.id
                                                                        ? 'Submitting...'
                                                                        : 'Submit'}
                                                                </Button>
                                                            )}
                                                            {can.approve &&
                                                                ts.status ===
                                                                    'submitted' && (
                                                                    <>
                                                                        <Button
                                                                            variant="outline"
                                                                            size="sm"
                                                                            className="border-status-success/30 text-status-success hover:bg-status-success"
                                                                            disabled={
                                                                                processing ===
                                                                                ts.id
                                                                            }
                                                                            onClick={() =>
                                                                                setConfirmApproveId(
                                                                                    ts.id,
                                                                                )
                                                                            }
                                                                        >
                                                                            <CheckCircle2 className="mr-1 h-3 w-3" />
                                                                            {processing ===
                                                                            ts.id
                                                                                ? 'Approving...'
                                                                                : 'Approve'}
                                                                        </Button>
                                                                        <Button
                                                                            variant="outline"
                                                                            size="sm"
                                                                            className="border-status-critical/30 text-status-critical hover:bg-status-critical"
                                                                            disabled={
                                                                                processing ===
                                                                                ts.id
                                                                            }
                                                                            onClick={() =>
                                                                                setRejectId(
                                                                                    ts.id,
                                                                                )
                                                                            }
                                                                        >
                                                                            <XCircle className="mr-1 h-3 w-3" />
                                                                            Reject
                                                                        </Button>
                                                                    </>
                                                                )}
                                                            {ts.rejection_reason &&
                                                                ts.status ===
                                                                    'rejected' && (
                                                                    <span
                                                                        className="text-xs text-status-critical"
                                                                        title={
                                                                            ts.rejection_reason
                                                                        }
                                                                    >
                                                                        Reason:{' '}
                                                                        {ts.rejection_reason.slice(
                                                                            0,
                                                                            30,
                                                                        )}
                                                                        ...
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
                            Showing{' '}
                            {(timesheets.current_page - 1) *
                                timesheets.per_page +
                                1}{' '}
                            to{' '}
                            {Math.min(
                                timesheets.current_page * timesheets.per_page,
                                timesheets.total,
                            )}{' '}
                            of {timesheets.total} timesheets
                        </p>
                        <LaravelPagination links={timesheets.links} />
                    </div>
                )}
                {/* Approve Confirmation Dialog */}
                <AlertDialog
                    open={confirmApproveId !== null}
                    onOpenChange={(open) => {
                        if (!open) setConfirmApproveId(null);
                    }}
                >
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>
                                Approve Timesheet
                            </AlertDialogTitle>
                            <AlertDialogDescription>
                                Are you sure you want to approve this timesheet?
                                This will mark the hours as finalised and they
                                may be forwarded to payroll.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                            <AlertDialogAction
                                onClick={() =>
                                    confirmApproveId &&
                                    handleApprove(confirmApproveId)
                                }
                                className="bg-status-success hover:bg-status-success"
                            >
                                Yes, Approve
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>

                {/* Reject Dialog */}
                <Dialog
                    open={rejectId !== null}
                    onOpenChange={(open) => {
                        if (!open) {
                            setRejectId(null);
                            setRejectReason('');
                        }
                    }}
                >
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Reject Timesheet</DialogTitle>
                            <DialogDescription>
                                {(() => {
                                    const ts = rejectId
                                        ? timesheets.data.find(
                                              (t) => t.id === rejectId,
                                          )
                                        : null;
                                    return ts
                                        ? `${ts.user_name} — ${formatDate(ts.period_start)} to ${formatDate(ts.period_end)} (${ts.total_hours}h)`
                                        : 'Provide a reason for rejecting this timesheet.';
                                })()}
                            </DialogDescription>
                        </DialogHeader>
                        <div className="space-y-2 py-2">
                            <Label>Reason for rejection (required)</Label>
                            <Textarea
                                rows={3}
                                value={rejectReason}
                                onChange={(e) =>
                                    setRejectReason(e.target.value)
                                }
                                placeholder="Explain why this timesheet is being rejected"
                            />
                        </div>
                        <DialogFooter>
                            <Button
                                variant="ghost"
                                onClick={() => {
                                    setRejectId(null);
                                    setRejectReason('');
                                }}
                            >
                                Cancel
                            </Button>
                            <Button
                                variant="destructive"
                                disabled={
                                    !rejectReason.trim() ||
                                    processing === rejectId
                                }
                                onClick={() =>
                                    rejectId && handleReject(rejectId)
                                }
                            >
                                {processing === rejectId
                                    ? 'Rejecting...'
                                    : 'Reject'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </PageShell>
        </AppLayout>
    );
}
