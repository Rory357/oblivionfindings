import PageShell from '@/components/page-shell';
import TimesheetAllocationsBreakdown, {
    type AllocationCandidate,
    type AllocationMethod,
    type ClientAllocation,
} from '@/components/timesheet-allocations-breakdown';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    CheckCircle2,
    ClipboardCheck,
    Clock3,
    Eye,
    FileClock,
    RotateCcw,
    XCircle,
} from 'lucide-react';
import { useMemo, useState } from 'react';

type PendingTimesheet = {
    id: number;
    status: string;
    work_date?: string | null;
    submitted_at?: string | null;
    duration_minutes?: number | null;
    hours?: number | null;
    total_hours?: number | null;
    client?: { id: number; first_name: string; last_name: string } | null;
    staff?: { id: number; name: string } | null;
    client_allocations?: ClientAllocation[];
    clients_candidates?: AllocationCandidate[];
    allocation_method?: AllocationMethod;
};

type Props = {
    timesheets: {
        data: PendingTimesheet[];
        links?: Array<{ url: string | null; label: string; active: boolean }>;
        current_page?: number;
        last_page?: number;
        total?: number;
    };
    filters?: Record<string, string | null>;
};

function formatDate(value?: string | null): string {
    if (!value) return '-';

    return new Date(value).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function formatHours(timesheet: PendingTimesheet): string {
    if (typeof timesheet.hours === 'number') {
        return `${timesheet.hours.toFixed(2)} hrs`;
    }

    if (typeof timesheet.duration_minutes === 'number') {
        return `${(timesheet.duration_minutes / 60).toFixed(2)} hrs`;
    }

    return '-';
}

export default function TimesheetApprovalsPage({ timesheets }: Props) {
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const [decisionNotes, setDecisionNotes] = useState('');

    const rows = timesheets?.data ?? [];
    const allSelected = rows.length > 0 && selectedIds.length === rows.length;
    const selectionLabel = useMemo(() => {
        if (selectedIds.length === 0) {
            return 'Select one or more submitted timesheets to review.';
        }

        return `${selectedIds.length} selected for action.`;
    }, [selectedIds.length]);

    const toggleAll = () => {
        setSelectedIds(
            allSelected ? [] : rows.map((timesheet) => timesheet.id),
        );
    };

    const toggleOne = (timesheetId: number) => {
        setSelectedIds((current) =>
            current.includes(timesheetId)
                ? current.filter((id) => id !== timesheetId)
                : [...current, timesheetId],
        );
    };

    const runBulkAction = (
        endpoint: string,
        payload: Record<string, unknown> = {},
        idsOverride?: number[],
    ) => {
        const ids = idsOverride ?? selectedIds;

        if (ids.length === 0) {
            return;
        }

        router.post(
            endpoint,
            { ids, ...payload },
            {
                preserveScroll: true,
                onSuccess: () => setSelectedIds([]),
            },
        );
    };

    return (
        <AppLayout>
            <Head title="Timesheet Approvals" />
            <PageHero
                icon={ClipboardCheck}
                title="Approval Queue"
                description="Review submitted timesheets without being redirected out of the approvals workflow."
                backHref="/operations/timesheets"
                backLabel="Back to timesheets"
                stats={[
                    { label: 'Awaiting review', value: rows.length },
                    { label: 'Selected', value: selectedIds.length },
                    {
                        label: 'Total hours',
                        value: rows
                            .reduce((sum, t) => {
                                if (typeof t.hours === 'number') return sum + t.hours;
                                if (typeof t.duration_minutes === 'number')
                                    return sum + t.duration_minutes / 60;
                                return sum;
                            }, 0)
                            .toFixed(1),
                    },
                ]}
            />
            <PageShell>
                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        size="sm"
                        data-test="approvals-bulk-approve"
                        onClick={() =>
                            runBulkAction(
                                '/operations/timesheets/bulk-approve',
                                {
                                    decision_notes:
                                        decisionNotes.trim() ||
                                        'Bulk approved from approval queue.',
                                },
                            )
                        }
                        disabled={selectedIds.length === 0}
                    >
                        <CheckCircle2 className="mr-1.5 h-3.5 w-3.5" />
                        Approve Selected
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        data-test="approvals-bulk-return"
                        onClick={() =>
                            runBulkAction(
                                '/operations/timesheets/bulk-return',
                                {
                                    returned_notes:
                                        decisionNotes.trim() ||
                                        'Returned from approval queue for follow-up.',
                                },
                            )
                        }
                        disabled={selectedIds.length === 0}
                    >
                        <RotateCcw className="mr-1.5 h-3.5 w-3.5" />
                        Return Selected
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        data-test="approvals-bulk-reject"
                        onClick={() =>
                            runBulkAction(
                                '/operations/timesheets/bulk-reject',
                                {
                                    decision_notes:
                                        decisionNotes.trim() ||
                                        'Rejected from approval queue.',
                                },
                            )
                        }
                        disabled={selectedIds.length === 0}
                    >
                        <XCircle className="mr-1.5 h-3.5 w-3.5" />
                        Reject Selected
                    </Button>
                    <span className="text-xs text-muted-foreground">
                        {selectionLabel}
                    </span>
                </div>
                <textarea
                    data-test="approvals-decision-notes"
                    value={decisionNotes}
                    onChange={(event) => setDecisionNotes(event.target.value)}
                    placeholder="Decision notes"
                    className="mt-3 min-h-20 w-full max-w-2xl rounded-md border border-input bg-background px-3 py-2 text-sm"
                />

                <div className="mt-4 space-y-2">
                    {rows.length === 0 && (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-16">
                                <FileClock className="mb-4 h-12 w-12 text-muted-foreground/30" />
                                <h2 className="text-lg font-semibold text-muted-foreground">
                                    No Submitted Timesheets
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground/80">
                                    New submissions will appear here for
                                    approval.
                                </p>
                            </CardContent>
                        </Card>
                    )}

                    {rows.length > 0 && (
                        <Card>
                            <CardContent className="p-0">
                                <div className="hidden items-center gap-3 border-b px-4 py-3 text-xs font-medium text-muted-foreground md:grid md:grid-cols-[40px,1.3fr,1fr,1fr,0.9fr,120px]">
                                    <label className="flex items-center justify-center">
                                        <input
                                            type="checkbox"
                                            checked={allSelected}
                                            onChange={toggleAll}
                                        />
                                    </label>
                                    <span>Client</span>
                                    <span>Staff</span>
                                    <span>Work Date</span>
                                    <span>Hours</span>
                                    <span>Actions</span>
                                </div>

                                {rows.map((timesheet) => (
                                    <div
                                        key={timesheet.id}
                                        data-test="approvals-row"
                                        className="border-b px-4 py-4 last:border-b-0"
                                    >
                                        <div className="grid gap-3 md:grid-cols-[40px,1.3fr,1fr,1fr,0.9fr,120px] md:items-center">
                                            <label className="flex items-center md:justify-center">
                                                <input
                                                    type="checkbox"
                                                    data-test="approvals-row-checkbox"
                                                    checked={selectedIds.includes(
                                                        timesheet.id,
                                                    )}
                                                    onChange={() =>
                                                        toggleOne(timesheet.id)
                                                    }
                                                />
                                            </label>
                                            <div>
                                                <p className="text-sm font-semibold">
                                                    {timesheet.client
                                                        ? `${timesheet.client.first_name} ${timesheet.client.last_name}`
                                                        : `Timesheet #${timesheet.id}`}
                                                </p>
                                                <div className="mt-1 flex items-center gap-2 text-xs text-muted-foreground">
                                                    <Badge
                                                        variant="secondary"
                                                        className="h-4 px-1.5 text-[9px] capitalize"
                                                    >
                                                        {timesheet.status}
                                                    </Badge>
                                                    <span className="inline-flex items-center gap-1">
                                                        <Clock3 className="h-3 w-3" />
                                                        Submitted{' '}
                                                        {formatDate(
                                                            timesheet.submitted_at,
                                                        )}
                                                    </span>
                                                </div>
                                            </div>
                                            <p className="text-sm text-muted-foreground">
                                                {timesheet.staff?.name ?? '-'}
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                {formatDate(timesheet.work_date)}
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                {formatHours(timesheet)}
                                            </p>
                                            <div className="flex items-center gap-2">
                                                <Button
                                                    asChild
                                                    size="sm"
                                                    variant="ghost"
                                                    className="h-8 w-8 p-0"
                                                >
                                                    <Link
                                                        href={`/operations/timesheets/${timesheet.id}/edit`}
                                                    >
                                                        <Eye className="h-3.5 w-3.5" />
                                                    </Link>
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    onClick={() =>
                                                        runBulkAction(
                                                            '/operations/timesheets/bulk-approve',
                                                            {},
                                                            [timesheet.id],
                                                        )
                                                    }
                                                    disabled={
                                                        selectedIds.length > 0 &&
                                                        !selectedIds.includes(
                                                            timesheet.id,
                                                        )
                                                    }
                                                >
                                                    Approve
                                                </Button>
                                            </div>
                                        </div>

                                        {/* Per-client allocation breakdown for
                                            the approver. Compact so the queue
                                            stays scannable; synthesised single-
                                            row timesheets render as a 1-line
                                            notice. */}
                                        {Array.isArray(
                                            timesheet.client_allocations,
                                        ) &&
                                        timesheet.client_allocations.length >
                                            0 ? (
                                            <div className="mt-3 md:ml-10">
                                                <TimesheetAllocationsBreakdown
                                                    allocations={
                                                        timesheet.client_allocations
                                                    }
                                                    candidates={
                                                        timesheet.clients_candidates ??
                                                        []
                                                    }
                                                    method={
                                                        timesheet.allocation_method ??
                                                        'single'
                                                    }
                                                    totalHours={Number(
                                                        timesheet.total_hours ??
                                                            timesheet.hours ??
                                                            0,
                                                    )}
                                                    compact
                                                />
                                            </div>
                                        ) : null}
                                    </div>
                                ))}
                            </CardContent>
                        </Card>
                    )}
                </div>
            </PageShell>
        </AppLayout>
    );
}
