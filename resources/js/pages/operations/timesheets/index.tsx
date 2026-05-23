import { PageHero } from '@/components/page';
import { OpsStatCard } from '@/components/ops-stat-card';
import PageShell from '@/components/page-shell';
import TimesheetReturnBanner from '@/components/timesheet-return-banner';
import { TimesheetStatusBadge } from '@/components/timesheet-status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyList } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import {
    bulkApprove as bulkApproveRoute,
    bulkReject as bulkRejectRoute,
    bulkReturn as bulkReturnRoute,
    create as createTimesheet,
    edit as editTimesheet,
    approvals as timesheetApprovals,
    index as timesheetsIndex,
} from '@/routes/operations/timesheets';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertCircle, CheckCircle2, Clock, FileText, Send } from 'lucide-react';
import React, { useMemo, useState } from 'react';

type Timesheet = {
    id: number;
    work_date: string;
    starts_at: string;
    ends_at: string;
    break_minutes: number;
    mileage_km?: number | null;
    sleepover?: boolean;
    on_call?: boolean;
    public_holiday?: boolean;
    status: string;
    returned_notes?: string | null;
    submitted_at?: string | null;
    client: { id: number; first_name: string; last_name: string };
    staff: { id: number; name: string };
    shift?: {
        id: number;
        shift_type?: string | null;
        location?: string | null;
        expected_break_minutes?: number | null;
        service_context?: { id: number; name: string } | null;
        status?: string;
    } | null;
};

type Props = {
    timesheets: { data: Timesheet[] };
    filters: {
        status?: string;
        from?: string;
        to?: string;
        client_id?: string | number;
        staff_id?: string | number;
        mode?: string | null;
    };
    approvalMode?: boolean;
    /**
     * True when the controller restricted the query to the viewer's own
     * timesheets (worker without `timesheets.manageAny`, list-mode). When set,
     * the page renders as "My Timesheets" — heading + description swap, and
     * the Staff column is hidden because every row would otherwise just say
     * the viewer's own name.
     */
    isOwnOnlyView?: boolean;
    clients?: Array<{ id: number; first_name: string; last_name: string }>;
    staff?: Array<{ id: number; name: string; email?: string }>;
    canApprove: boolean;
    canCreate: boolean;
};

const ANY = '__any__';

export const needsApprovalBadgeClassName =
    'border-status-warning/30 bg-status-warning-bg text-[10px] text-status-warning';

export default function TimesheetsIndex({
    timesheets,
    filters,
    approvalMode,
    isOwnOnlyView,
    clients = [],
    staff = [],
    canApprove,
    canCreate,
}: Props) {
    const { labels } = usePage().props as any;
    const timesheetPlural = labels?.['timesheet.plural'] ?? 'Timesheets';
    const isApprovalMode = !!approvalMode;
    const isWorkerView = !!isOwnOnlyView && !isApprovalMode;

    const [selected, setSelected] = useState<Record<number, boolean>>({});
    const selectedIds = useMemo(
        () =>
            Object.entries(selected)
                .filter(([, v]) => v)
                .map(([k]) => Number(k)),
        [selected],
    );
    const allSelected =
        timesheets.data.length > 0 &&
        timesheets.data.every((t) => selected[t.id]);

    const [decisionNotes, setDecisionNotes] = useState('');
    const [returnedNotes, setReturnedNotes] = useState('');
    const [bulkError, setBulkError] = useState<string | null>(null);
    const [bulkAction, setBulkAction] = useState<
        'approve' | 'return' | 'reject' | null
    >(null);
    const listHref = isApprovalMode
        ? timesheetApprovals.url()
        : timesheetsIndex.url();
    const listUrl = (query?: Record<string, any>) =>
        isApprovalMode
            ? timesheetApprovals.url({ query })
            : timesheetsIndex.url({ query });
    const filterQuery = (next: Partial<Props['filters']>) => {
        const query = { ...filters, ...next };
        if (isApprovalMode) {
            delete query.mode;
        }

        return query;
    };

    const toggleAll = () => {
        if (allSelected) {
            setSelected({});
            return;
        }
        const next: Record<number, boolean> = {};
        timesheets.data.forEach((t) => (next[t.id] = true));
        setSelected(next);
    };

    const bulkApprove = () => {
        if (selectedIds.length === 0) return;
        setBulkError(null);
        router.post(
            bulkApproveRoute.url(),
            { ids: selectedIds, decision_notes: decisionNotes || null },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSelected({});
                    setDecisionNotes('');
                    setBulkError(null);
                    setBulkAction(null);
                },
            },
        );
    };

    const bulkReturn = () => {
        if (selectedIds.length === 0) return;
        if (!returnedNotes.trim()) {
            setBulkError(
                'Return notes are required when returning timesheets.',
            );
            return;
        }
        setBulkError(null);
        router.post(
            bulkReturnRoute.url(),
            { ids: selectedIds, returned_notes: returnedNotes },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSelected({});
                    setReturnedNotes('');
                    setBulkError(null);
                    setBulkAction(null);
                },
            },
        );
    };

    const bulkReject = () => {
        if (selectedIds.length === 0) return;
        if (!decisionNotes.trim()) {
            setBulkError('Decision notes are required to reject timesheets.');
            return;
        }
        setBulkError(null);
        router.post(
            bulkRejectRoute.url(),
            { ids: selectedIds, decision_notes: decisionNotes },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSelected({});
                    setDecisionNotes('');
                    setBulkError(null);
                    setBulkAction(null);
                },
            },
        );
    };

    const stats = useMemo(() => {
        const data = timesheets.data;
        return {
            total: data.length,
            draft: data.filter((t) => t.status === 'draft').length,
            submitted: data.filter((t) => t.status === 'submitted').length,
            approved: data.filter((t) => t.status === 'approved').length,
        };
    }, [timesheets.data]);

    return (
        <AppLayout
            breadcrumbs={[
                {
                    title: isWorkerView ? 'My timesheets' : timesheetPlural,
                    href: listHref,
                },
            ]}
        >
            <Head
                title={
                    isApprovalMode
                        ? 'Timesheet Approvals'
                        : isWorkerView
                          ? 'My Timesheets'
                          : timesheetPlural
                }
            />

            <PageShell>
                <PageHero
                    title={
                        isApprovalMode
                            ? 'Timesheet Approvals'
                            : isWorkerView
                              ? 'My Timesheets'
                              : timesheetPlural
                    }
                    description={
                        isApprovalMode
                            ? 'Submitted timesheets waiting for a decision.'
                            : isWorkerView
                              ? 'Your work logs — drafts in progress, what you’ve submitted, and what your manager has approved or returned.'
                              : 'Work logs, approvals, and timesheet management.'
                    }
                    icon={<FileText className="h-7 w-7 text-white" />}
                    actions={
                        <div className="flex items-center gap-2">
                            {isApprovalMode ? (
                                <Button asChild>
                                    <Link href={timesheetsIndex.url()}>
                                        All timesheets
                                    </Link>
                                </Button>
                            ) : canApprove ? (
                                <Button asChild>
                                    <Link href={timesheetApprovals.url()}>
                                        Approval queue
                                    </Link>
                                </Button>
                            ) : null}
                            {/*
                              * Workers create today's timesheet via the
                              * /my-day "Today's timesheet" popup (find-or-
                              * creates a draft against their active shift,
                              * then opens the per-client allocation review).
                              * The legacy `/operations/timesheets/create`
                              * form stays available for managers + admins
                              * who need to mint a retroactive timesheet
                              * manually, but is hidden from the worker's
                              * own-list view so we don't ship two parallel
                              * create paths.
                              */}
                            {canCreate && !isApprovalMode && !isWorkerView ? (
                                <Button asChild>
                                    <Link href={createTimesheet.url()}>
                                        Create
                                    </Link>
                                </Button>
                            ) : null}
                        </div>
                    }
                />

                {!isApprovalMode ? (
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <OpsStatCard
                            label="Total"
                            value={stats.total}
                            icon={FileText}
                            color="indigo"
                        />
                        <OpsStatCard
                            label="Draft"
                            value={stats.draft}
                            icon={Clock}
                            color="slate"
                        />
                        <OpsStatCard
                            label="Submitted"
                            value={stats.submitted}
                            icon={Send}
                            color="amber"
                        />
                        <OpsStatCard
                            label="Approved"
                            value={stats.approved}
                            icon={CheckCircle2}
                            color="emerald"
                        />
                    </div>
                ) : (
                    <div className="grid gap-3 sm:grid-cols-3">
                        <OpsStatCard
                            label="Pending Approval"
                            value={stats.submitted}
                            icon={Send}
                            color="amber"
                        />
                        <OpsStatCard
                            label="Selected"
                            value={selectedIds.length}
                            icon={CheckCircle2}
                            color="indigo"
                        />
                        <OpsStatCard
                            label="Total Shown"
                            value={stats.total}
                            icon={FileText}
                            color="slate"
                        />
                    </div>
                )}

                {/* Filters */}
                <div className="rounded-lg border bg-card p-3 shadow-sm">
                    <div className="flex flex-wrap items-end gap-3">
                        {!isApprovalMode ? (
                        <div className="space-y-1">
                            <Label className="text-xs text-muted-foreground">
                                Status
                            </Label>
                            <Select
                                value={filters.status ?? ANY}
                                onValueChange={(v) =>
                                    router.get(
                                        listUrl(
                                            filterQuery({
                                                status:
                                                    v === ANY ? undefined : v,
                                            }),
                                        ),
                                        {},
                                        { preserveState: true, replace: true },
                                    )
                                }
                            >
                                <SelectTrigger className="mt-1 w-36">
                                    <SelectValue placeholder="All statuses" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>
                                        All statuses
                                    </SelectItem>
                                    <SelectItem value="draft">Draft</SelectItem>
                                    <SelectItem value="submitted">
                                        Submitted
                                    </SelectItem>
                                    <SelectItem value="returned">
                                        Returned
                                    </SelectItem>
                                    <SelectItem value="approved">
                                        Approved
                                    </SelectItem>
                                    <SelectItem value="rejected">
                                        Rejected
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        ) : null}
                        <div className="space-y-1">
                        <Label className="text-xs text-muted-foreground">
                            From
                        </Label>
                        <Input
                            type="date"
                            className="mt-1"
                            value={filters.from ?? ''}
                            onChange={(e) =>
                                router.get(
                                    listUrl(
                                        filterQuery({
                                            from: e.target.value || undefined,
                                        }),
                                    ),
                                    {},
                                    { preserveState: true, replace: true },
                                )
                            }
                        />
                        </div>
                        <div className="space-y-1">
                        <Label className="text-xs text-muted-foreground">
                            To
                        </Label>
                        <Input
                            type="date"
                            className="mt-1"
                            value={filters.to ?? ''}
                            onChange={(e) =>
                                router.get(
                                    listUrl(
                                        filterQuery({
                                            to: e.target.value || undefined,
                                        }),
                                    ),
                                    {},
                                    { preserveState: true, replace: true },
                                )
                            }
                        />
                        </div>
                        {isApprovalMode ? (
                        <>
                            <div className="space-y-1">
                                <Label className="text-xs text-muted-foreground">
                                    Client
                                </Label>
                                <Select
                                    value={
                                        filters.client_id
                                            ? String(filters.client_id)
                                            : ANY
                                    }
                                    onValueChange={(v) =>
                                        router.get(
                                            listUrl(
                                                filterQuery({
                                                    client_id:
                                                        v === ANY
                                                            ? undefined
                                                            : v,
                                                }),
                                            ),
                                            {},
                                            {
                                                preserveState: true,
                                                replace: true,
                                            },
                                        )
                                    }
                                >
                                    <SelectTrigger className="mt-1 w-44">
                                        <SelectValue placeholder="All clients" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ANY}>
                                            All clients
                                        </SelectItem>
                                        {clients.map((c) => (
                                            <SelectItem
                                                key={c.id}
                                                value={String(c.id)}
                                            >
                                                {c.first_name} {c.last_name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1">
                                <Label className="text-xs text-muted-foreground">
                                    Staff
                                </Label>
                                <Select
                                    value={
                                        filters.staff_id
                                            ? String(filters.staff_id)
                                            : ANY
                                    }
                                    onValueChange={(v) =>
                                        router.get(
                                            listUrl(
                                                filterQuery({
                                                    staff_id:
                                                        v === ANY
                                                            ? undefined
                                                            : v,
                                                }),
                                            ),
                                            {},
                                            {
                                                preserveState: true,
                                                replace: true,
                                            },
                                        )
                                    }
                                >
                                    <SelectTrigger className="mt-1 w-44">
                                        <SelectValue placeholder="All staff" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ANY}>
                                            All staff
                                        </SelectItem>
                                        {staff.map((u) => (
                                            <SelectItem
                                                key={u.id}
                                                value={String(u.id)}
                                            >
                                                {u.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </>
                        ) : null}
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                router.get(
                                    listUrl(),
                                    {},
                                    { preserveState: true, replace: true },
                                )
                            }
                        >
                            Clear
                        </Button>
                    </div>
                </div>

                {/* List — mobile cards, desktop table */}
                {timesheets.data.length > 0 ? (
                    <>
                        {/* Mobile: stacked cards */}
                        <ul className="space-y-2 md:hidden">
                            {timesheets.data.map((t) => {
                                const showReturnBanner =
                                    !isApprovalMode && t.status === 'returned';
                                const dateLabel = new Date(
                                    t.work_date,
                                ).toLocaleDateString('en-NZ', {
                                    day: 'numeric',
                                    month: 'short',
                                    year: 'numeric',
                                });
                                const startLabel = new Date(
                                    t.starts_at,
                                ).toLocaleTimeString('en-NZ', {
                                    hour: '2-digit',
                                    minute: '2-digit',
                                });
                                const endLabel = new Date(
                                    t.ends_at,
                                ).toLocaleTimeString('en-NZ', {
                                    hour: '2-digit',
                                    minute: '2-digit',
                                });
                                const tagBadges = (
                                    <>
                                        {t.sleepover ? (
                                            <Badge
                                                variant="outline"
                                                className="text-[10px]"
                                            >
                                                Sleepover
                                            </Badge>
                                        ) : null}
                                        {t.on_call ? (
                                            <Badge
                                                variant="outline"
                                                className="text-[10px]"
                                            >
                                                On-call
                                            </Badge>
                                        ) : null}
                                        {t.public_holiday ? (
                                            <Badge
                                                variant="outline"
                                                className="text-[10px]"
                                            >
                                                Public holiday
                                            </Badge>
                                        ) : null}
                                        {(t.mileage_km ?? 0) > 0 ? (
                                            <Badge
                                                variant="outline"
                                                className="text-[10px]"
                                            >
                                                {t.mileage_km}km
                                            </Badge>
                                        ) : null}
                                    </>
                                );
                                return (
                                    <li
                                        key={t.id}
                                        className="rounded-xl border bg-card p-3 text-sm"
                                    >
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="flex min-w-0 items-start gap-3">
                                                {isApprovalMode ? (
                                                    <input
                                                        type="checkbox"
                                                        className="mt-1 shrink-0"
                                                        checked={
                                                            !!selected[t.id]
                                                        }
                                                        onChange={(e) =>
                                                            setSelected(
                                                                (prev) => ({
                                                                    ...prev,
                                                                    [t.id]: e
                                                                        .target
                                                                        .checked,
                                                                }),
                                                            )
                                                        }
                                                        aria-label={`Select timesheet for ${t.client.first_name} ${t.client.last_name} on ${dateLabel}`}
                                                    />
                                                ) : null}
                                                <div className="min-w-0">
                                                    <div className="font-medium">
                                                        {dateLabel}
                                                    </div>
                                                    <div className="mt-0.5 text-xs text-muted-foreground">
                                                        {startLabel} –{' '}
                                                        {endLabel}
                                                        {t.break_minutes
                                                            ? ` · ${t.break_minutes}m break`
                                                            : ''}
                                                    </div>
                                                </div>
                                            </div>
                                            <div className="shrink-0">
                                                {isApprovalMode ? (
                                                    <span className="text-[11px] text-muted-foreground">
                                                        {t.submitted_at
                                                            ? new Date(
                                                                  t.submitted_at,
                                                              ).toLocaleDateString(
                                                                  'en-NZ',
                                                                  {
                                                                      day: 'numeric',
                                                                      month: 'short',
                                                                  },
                                                              )
                                                            : '—'}
                                                    </span>
                                                ) : (
                                                    <TimesheetStatusBadge
                                                        status={t.status}
                                                    />
                                                )}
                                            </div>
                                        </div>

                                        <dl className="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
                                            <dt className="text-muted-foreground">
                                                Client
                                            </dt>
                                            <dd className="min-w-0 truncate">
                                                <Link
                                                    className="underline"
                                                    href={`/operations/clients/${t.client.id}`}
                                                >
                                                    {t.client.first_name}{' '}
                                                    {t.client.last_name}
                                                </Link>
                                            </dd>
                                            {isWorkerView ? null : (
                                                <>
                                                    <dt className="text-muted-foreground">
                                                        Staff
                                                    </dt>
                                                    <dd className="min-w-0 truncate">
                                                        {t.staff?.name ?? '—'}
                                                    </dd>
                                                </>
                                            )}
                                            {t.shift ? (
                                                <>
                                                    <dt className="text-muted-foreground">
                                                        Shift
                                                    </dt>
                                                    <dd className="min-w-0 truncate">
                                                        {String(
                                                            t.shift
                                                                .shift_type ??
                                                                'standard',
                                                        ).replace('_', ' ')}
                                                        {t.shift.location
                                                            ? ` · ${t.shift.location}`
                                                            : ''}
                                                    </dd>
                                                </>
                                            ) : null}
                                        </dl>

                                        {!isApprovalMode &&
                                        (t.sleepover ||
                                            t.on_call ||
                                            t.public_holiday ||
                                            (t.mileage_km ?? 0) > 0) ? (
                                            <div className="mt-2 flex flex-wrap gap-1">
                                                {tagBadges}
                                            </div>
                                        ) : null}

                                        {showReturnBanner ? (
                                            <div className="mt-2">
                                                <TimesheetReturnBanner
                                                    timesheetId={t.id}
                                                    returnNote={
                                                        t.returned_notes
                                                    }
                                                    editHref={editTimesheet.url(
                                                        t.id,
                                                    )}
                                                />
                                            </div>
                                        ) : null}

                                        <div className="mt-3 flex items-center justify-between gap-2">
                                            {canApprove &&
                                            t.status === 'submitted' ? (
                                                <Badge
                                                    variant="outline"
                                                    className={needsApprovalBadgeClassName}
                                                >
                                                    Needs approval
                                                </Badge>
                                            ) : (
                                                <span />
                                            )}
                                            <Button
                                                asChild
                                                size="sm"
                                                variant="outline"
                                            >
                                                <Link
                                                    href={editTimesheet.url(
                                                        t.id,
                                                    )}
                                                >
                                                    View
                                                </Link>
                                            </Button>
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>

                        {/* Desktop: table */}
                        <div className="hidden rounded-xl border md:block">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/40">
                                    <tr>
                                        {isApprovalMode ? (
                                            <th className="w-10 p-3 text-left font-medium">
                                                <input
                                                    type="checkbox"
                                                    checked={allSelected}
                                                    onChange={toggleAll}
                                                />
                                            </th>
                                        ) : null}
                                        <th className="p-3 text-left font-medium">
                                            Date
                                        </th>
                                        <th className="p-3 text-left font-medium">
                                            Client
                                        </th>
                                        {isWorkerView ? null : (
                                            <th className="p-3 text-left font-medium">
                                                Staff
                                            </th>
                                        )}
                                        <th className="p-3 text-left font-medium">
                                            {isApprovalMode
                                                ? 'Submitted'
                                                : 'Status'}
                                        </th>
                                        <th className="p-3 text-right font-medium">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {timesheets.data.map((t) => {
                                        const showReturnBanner =
                                            !isApprovalMode &&
                                            t.status === 'returned';
                                        // Column counts:
                                        //   - approval mode: 6 (checkbox+date+client+staff+submitted+actions)
                                        //   - worker (own list): 4 (date+client+status+actions, no staff)
                                        //   - general manager list: 5 (date+client+staff+status+actions)
                                        const rowColspan = isApprovalMode
                                            ? 6
                                            : isWorkerView
                                              ? 4
                                              : 5;
                                        return (
                                            <React.Fragment key={t.id}>
                                                <tr className="border-t transition-colors hover:bg-muted/20">
                                                    {isApprovalMode ? (
                                                        <td className="p-3">
                                                            <input
                                                                type="checkbox"
                                                                checked={
                                                                    !!selected[
                                                                        t.id
                                                                    ]
                                                                }
                                                                onChange={(e) =>
                                                                    setSelected(
                                                                        (
                                                                            prev,
                                                                        ) => ({
                                                                            ...prev,
                                                                            [t.id]: e
                                                                                .target
                                                                                .checked,
                                                                        }),
                                                                    )
                                                                }
                                                            />
                                                        </td>
                                                    ) : null}
                                                    <td className="p-3">
                                                        <div className="font-medium">
                                                            {new Date(
                                                                t.work_date,
                                                            ).toLocaleDateString(
                                                                'en-NZ',
                                                                {
                                                                    day: 'numeric',
                                                                    month: 'short',
                                                                    year: 'numeric',
                                                                },
                                                            )}
                                                        </div>
                                                        <div className="text-xs text-muted-foreground">
                                                            {new Date(
                                                                t.starts_at,
                                                            ).toLocaleTimeString(
                                                                'en-NZ',
                                                                {
                                                                    hour: '2-digit',
                                                                    minute: '2-digit',
                                                                },
                                                            )}
                                                            {' – '}
                                                            {new Date(
                                                                t.ends_at,
                                                            ).toLocaleTimeString(
                                                                'en-NZ',
                                                                {
                                                                    hour: '2-digit',
                                                                    minute: '2-digit',
                                                                },
                                                            )}
                                                            {t.break_minutes
                                                                ? ` · ${t.break_minutes}m break`
                                                                : ''}
                                                        </div>
                                                        {t.shift ? (
                                                            <div className="mt-1 text-[11px] text-muted-foreground">
                                                                {String(
                                                                    t.shift
                                                                        .shift_type ??
                                                                        'standard',
                                                                ).replace(
                                                                    '_',
                                                                    ' ',
                                                                )}
                                                                {t.shift
                                                                    .service_context
                                                                    ?.name
                                                                    ? ` · ${t.shift.service_context.name}`
                                                                    : ''}
                                                                {t.shift
                                                                    .location
                                                                    ? ` · ${t.shift.location}`
                                                                    : ''}
                                                            </div>
                                                        ) : null}
                                                    </td>
                                                    <td className="p-3">
                                                        <Link
                                                            className="underline"
                                                            href={`/operations/clients/${t.client.id}`}
                                                        >
                                                            {
                                                                t.client
                                                                    .first_name
                                                            }{' '}
                                                            {t.client.last_name}
                                                        </Link>
                                                    </td>
                                                    {isWorkerView ? null : (
                                                        <td className="p-3">
                                                            {t.staff?.name ?? '—'}
                                                        </td>
                                                    )}
                                                    {isApprovalMode ? (
                                                        <td className="p-3 text-sm text-muted-foreground">
                                                            {t.submitted_at
                                                                ? new Date(
                                                                      t.submitted_at,
                                                                  ).toLocaleString()
                                                                : '—'}
                                                        </td>
                                                    ) : (
                                                        <td className="p-3">
                                                            <TimesheetStatusBadge
                                                                status={
                                                                    t.status
                                                                }
                                                            />
                                                            {t.sleepover ||
                                                            t.on_call ||
                                                            t.public_holiday ||
                                                            (t.mileage_km ??
                                                                0) > 0 ? (
                                                                <div className="mt-1 flex flex-wrap gap-1">
                                                                    {t.sleepover ? (
                                                                        <Badge
                                                                            variant="outline"
                                                                            className="text-[10px]"
                                                                        >
                                                                            Sleepover
                                                                        </Badge>
                                                                    ) : null}
                                                                    {t.on_call ? (
                                                                        <Badge
                                                                            variant="outline"
                                                                            className="text-[10px]"
                                                                        >
                                                                            On-call
                                                                        </Badge>
                                                                    ) : null}
                                                                    {t.public_holiday ? (
                                                                        <Badge
                                                                            variant="outline"
                                                                            className="text-[10px]"
                                                                        >
                                                                            Public
                                                                            holiday
                                                                        </Badge>
                                                                    ) : null}
                                                                    {(t.mileage_km ??
                                                                        0) >
                                                                    0 ? (
                                                                        <Badge
                                                                            variant="outline"
                                                                            className="text-[10px]"
                                                                        >
                                                                            {
                                                                                t.mileage_km
                                                                            }
                                                                            km
                                                                        </Badge>
                                                                    ) : null}
                                                                </div>
                                                            ) : null}
                                                        </td>
                                                    )}
                                                    <td className="p-3">
                                                        <div className="flex items-center justify-end gap-2">
                                                            <Link
                                                                href={editTimesheet.url(
                                                                    t.id,
                                                                )}
                                                            >
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    className="text-xs"
                                                                >
                                                                    View
                                                                </Button>
                                                            </Link>
                                                            {canApprove &&
                                                            t.status ===
                                                                'submitted' ? (
                                                                <Badge
                                                                    variant="outline"
                                                                    className={
                                                                        needsApprovalBadgeClassName
                                                                    }
                                                                >
                                                                    Needs
                                                                    approval
                                                                </Badge>
                                                            ) : null}
                                                        </div>
                                                    </td>
                                                </tr>
                                                {showReturnBanner ? (
                                                    <tr className="border-t bg-status-warning-bg">
                                                        <td
                                                            colSpan={rowColspan}
                                                            className="p-3"
                                                        >
                                                            <TimesheetReturnBanner
                                                                timesheetId={
                                                                    t.id
                                                                }
                                                                returnNote={
                                                                    t.returned_notes
                                                                }
                                                                editHref={editTimesheet.url(
                                                                    t.id,
                                                                )}
                                                            />
                                                        </td>
                                                    </tr>
                                                ) : null}
                                            </React.Fragment>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                        <div className="text-xs text-muted-foreground">
                            Showing {timesheets.data.length}{' '}
                            {timesheets.data.length === 1
                                ? 'timesheet'
                                : 'timesheets'}
                        </div>
                    </>
                ) : (
                    <EmptyList
                        title={
                            isApprovalMode
                                ? 'No timesheets pending'
                                : 'No timesheets found'
                        }
                        itemName="timesheet"
                        createHref={
                            canCreate && !isApprovalMode && !isWorkerView
                                ? createTimesheet.url()
                                : undefined
                        }
                        createLabel="Create timesheet"
                        description={
                            isApprovalMode
                                ? 'No submitted timesheets awaiting approval.'
                                : 'No timesheets found for the current filters.'
                        }
                    />
                )}

                {/* Sticky bulk action bar — only visible when rows are selected in approval mode */}
                {isApprovalMode && selectedIds.length > 0 ? (
                    <div className="sticky bottom-0 z-10 -mx-5 border-t bg-card/95 p-4 shadow-lg backdrop-blur-sm supports-[backdrop-filter]:bg-card/80 sm:-mx-8">
                        <div className="mx-auto space-y-3">
                            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <div className="text-sm">
                                    <span className="font-medium">
                                        Selected:
                                    </span>{' '}
                                    {selectedIds.length} of{' '}
                                    {timesheets.data.length}
                                </div>
                                {bulkAction === null ? (
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            size="sm"
                                            onClick={() =>
                                                setBulkAction('approve')
                                            }
                                        >
                                            Approve selected
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                setBulkAction('return')
                                            }
                                        >
                                            Return selected
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="destructive"
                                            onClick={() =>
                                                setBulkAction('reject')
                                            }
                                        >
                                            Reject selected
                                        </Button>
                                    </div>
                                ) : null}
                            </div>

                            {bulkError ? (
                                <div className="flex items-center gap-2 rounded-lg border border-status-critical/30 bg-status-critical-bg px-3 py-2 text-sm text-status-critical dark:border-status-critical/30 dark:bg-status-critical-bg dark:text-status-critical">
                                    <AlertCircle className="h-4 w-4 shrink-0" />
                                    {bulkError}
                                </div>
                            ) : null}

                            {bulkAction === 'approve' ? (
                                <div className="space-y-2">
                                    <div className="space-y-1">
                                        <Label className="text-xs text-muted-foreground">
                                            Decision notes (optional)
                                        </Label>
                                        <Textarea
                                            rows={2}
                                            value={decisionNotes}
                                            onChange={(e) => {
                                                setDecisionNotes(
                                                    e.target.value,
                                                );
                                                setBulkError(null);
                                            }}
                                            placeholder="Optional notes for this approval"
                                        />
                                    </div>
                                    <div className="flex gap-2">
                                        <Button size="sm" onClick={bulkApprove}>
                                            Confirm approval
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => {
                                                setBulkAction(null);
                                                setBulkError(null);
                                            }}
                                        >
                                            Cancel
                                        </Button>
                                    </div>
                                </div>
                            ) : null}

                            {bulkAction === 'reject' ? (
                                <div className="space-y-2">
                                    <div className="space-y-1">
                                        <Label className="text-xs text-muted-foreground">
                                            Rejection reason (required)
                                        </Label>
                                        <Textarea
                                            rows={2}
                                            value={decisionNotes}
                                            onChange={(e) => {
                                                setDecisionNotes(
                                                    e.target.value,
                                                );
                                                setBulkError(null);
                                            }}
                                            placeholder="Explain why these timesheets are being rejected"
                                        />
                                    </div>
                                    <div className="flex gap-2">
                                        <Button
                                            size="sm"
                                            variant="destructive"
                                            onClick={bulkReject}
                                        >
                                            Confirm rejection
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => {
                                                setBulkAction(null);
                                                setBulkError(null);
                                            }}
                                        >
                                            Cancel
                                        </Button>
                                    </div>
                                </div>
                            ) : null}

                            {bulkAction === 'return' ? (
                                <div className="space-y-2">
                                    <div className="space-y-1">
                                        <Label className="text-xs text-muted-foreground">
                                            Return notes — explain what needs
                                            changing (required)
                                        </Label>
                                        <Textarea
                                            rows={2}
                                            value={returnedNotes}
                                            onChange={(e) => {
                                                setReturnedNotes(
                                                    e.target.value,
                                                );
                                                setBulkError(null);
                                            }}
                                            placeholder="What needs to be corrected before resubmission?"
                                        />
                                    </div>
                                    <div className="flex gap-2">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={bulkReturn}
                                        >
                                            Confirm return
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => {
                                                setBulkAction(null);
                                                setBulkError(null);
                                            }}
                                        >
                                            Cancel
                                        </Button>
                                    </div>
                                </div>
                            ) : null}
                        </div>
                    </div>
                ) : null}
            </PageShell>
        </AppLayout>
    );
}
