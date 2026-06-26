import {
    ApprovalCard,
    LeaveAvatar,
    LeaveCalendarPane,
    LeaveDetailModal,
    LeaveHubHero,
    LeaveHubTabs,
    LeaveRequestDialog,
    LeaveSlaChip,
    LeaveStatusChip,
    LeaveTypeChip,
    useLeaveContextMenu,
    type HubHero,
    type LeaveCalendarFeed,
    type LeaveCtxItem,
    type LeaveStaff,
    type LeaveTypeOption,
} from '@/components/hr';
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
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
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
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, router } from '@inertiajs/react';
import {
    ArrowUpCircle,
    CalendarDays,
    CheckCircle2,
    Clock,
    ExternalLink,
    Link2,
    Loader2,
    Search,
    Wallet,
    X,
    XCircle,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

type RosterConflict = {
    has_conflict: boolean;
    count: number;
    shifts: Array<{
        site_id: number | null;
        site_name: string | null;
        date: string | null;
        am_pm: string;
    }>;
};

type BalanceImpact = {
    remaining_before: number;
    projected_after: number;
    insufficient: boolean;
} | null;

type LeaveRequest = {
    id: number;
    staff_name: string;
    staff_id: number;
    leave_type: string;
    period?: string;
    start_date: string;
    end_date: string;
    hours: number;
    status: 'pending' | 'approved' | 'declined' | 'cancelled';
    reason?: string | null;
    reason_restricted?: boolean;
    has_doc?: boolean;
    reviewed_by?: string | null;
    reviewed_at?: string | null;
    submitted_at?: string | null;
    hours_waiting?: number;
    approval_due_at?: string | null;
    is_overdue?: boolean;
    due_within_24h?: boolean;
    escalation_level?: number;
    escalated?: boolean;
    escalated_from?: string | null;
    roster_conflict?: RosterConflict;
    balance_impact?: BalanceImpact;
};

type InboxSegment = { count: number; items: LeaveRequest[] };
type Inbox = {
    awaiting_my_decision: InboxSegment;
    escalated_to_me: InboxSegment;
    all_pending: InboxSegment;
    recently_decided: InboxSegment;
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
    tab: 'overview' | 'approvals' | 'calendar';
    approvalInbox: Inbox;
    calendar?: LeaveCalendarFeed | null;
    hero: HubHero;
    filters: {
        status?: string;
        leave_type?: string;
        sla?: string | null;
        q?: string;
    };
    sla: {
        pending_total: number;
        overdue_count: number;
        due_within_24h_count: number;
        oldest_pending_hours: number;
        avg_decision_hours_30d: number;
        pending_by_type: Record<string, number>;
    };
    staff: LeaveStaff[];
    leaveTypes: LeaveTypeOption[];
    publicHolidays?: Record<string, string>;
    can: { approve?: boolean; manage?: boolean; create?: boolean };
};

/* ------------------------------------------------------------------ */
/*  Config                                                             */
/* ------------------------------------------------------------------ */

const breadcrumbs = [
    { title: 'HR', href: '/hr' },
    { title: 'Leave', href: '/hr/leave' },
];

const INBOX_SEGMENTS: Array<{ key: keyof Inbox; label: string }> = [
    { key: 'awaiting_my_decision', label: 'Awaiting my decision' },
    { key: 'escalated_to_me', label: 'Escalated to me' },
    { key: 'all_pending', label: 'All pending' },
    { key: 'recently_decided', label: 'Recently decided' },
];

const SEG_FROM_QUERY: Record<string, keyof Inbox> = {
    mine: 'awaiting_my_decision',
    escalated: 'escalated_to_me',
    all: 'all_pending',
    decided: 'recently_decided',
};

const STATUS_FILTERS = [
    { value: 'all', label: 'All' },
    { value: 'pending', label: 'Pending' },
    { value: 'approved', label: 'Approved' },
    { value: 'declined', label: 'Declined' },
    { value: 'cancelled', label: 'Cancelled' },
];

function initialSegment(): keyof Inbox {
    if (typeof window === 'undefined') return 'all_pending';
    const seg = new URLSearchParams(window.location.search).get('seg');
    return (seg && SEG_FROM_QUERY[seg]) || 'all_pending';
}

/* ------------------------------------------------------------------ */
/*  Component                                                          */
/* ------------------------------------------------------------------ */

export default function LeaveIndex({
    requests,
    tab,
    approvalInbox: inbox,
    calendar,
    hero,
    filters,
    sla,
    staff,
    leaveTypes,
    publicHolidays,
    can,
}: Props) {
    const { open: openCtx, element: ctxElement } = useLeaveContextMenu();
    const [requestOpen, setRequestOpen] = useState(false);
    const [detailRequest, setDetailRequest] = useState<LeaveRequest | null>(
        null,
    );
    const [segment, setSegment] = useState<keyof Inbox>(initialSegment);
    const [searchTerm, setSearchTerm] = useState(filters.q ?? '');
    const segmentItems = inbox[segment].items;
    const segmentIsPending = segment !== 'recently_decided';
    const pendingRequests = inbox.all_pending.items;
    const allRequests = requests.data;
    const [selectedRequestIds, setSelectedRequestIds] = useState<number[]>([]);
    const [declineDialogOpen, setDeclineDialogOpen] = useState(false);
    const [declineTarget, setDeclineTarget] = useState<
        { type: 'single'; id: number } | { type: 'bulk' } | null
    >(null);
    const [declineNotes, setDeclineNotes] = useState('');
    const [processing, setProcessing] = useState(false);
    const [bulkApproveDialogOpen, setBulkApproveDialogOpen] = useState(false);
    const selectedPendingIds = useMemo(
        () =>
            selectedRequestIds.filter((id) =>
                pendingRequests.some((request) => request.id === id),
            ),
        [selectedRequestIds, pendingRequests],
    );

    // Open the request wizard when arrived via the hero "Request leave" deep-link.
    useEffect(() => {
        if (
            can.create &&
            new URLSearchParams(window.location.search).get('new') === '1'
        ) {
            setRequestOpen(true);
        }
    }, [can.create]);

    const updateFilter = (key: string, value: string | null) => {
        const newFilters: Record<string, string | null> = {
            ...filters,
            tab,
            [key]: value,
        };
        if (value === null || value === 'all') delete newFilters[key];
        router.get('/hr/leave', newFilters, {
            preserveState: true,
            replace: true,
        });
    };

    function submitSearch(e: React.FormEvent) {
        e.preventDefault();
        updateFilter('q', searchTerm.trim() || null);
    }

    function handleApprove(requestId: number) {
        setProcessing(true);
        router.post(
            `/hr/leave/${requestId}/approve`,
            {},
            { preserveScroll: true, onFinish: () => setProcessing(false) },
        );
    }
    function handleDecline(requestId: number) {
        setDeclineTarget({ type: 'single', id: requestId });
        setDeclineNotes('');
        setDeclineDialogOpen(true);
    }
    function toggleRequestSelection(requestId: number, checked: boolean) {
        setSelectedRequestIds((current) =>
            checked
                ? current.includes(requestId)
                    ? current
                    : [...current, requestId]
                : current.filter((id) => id !== requestId),
        );
    }
    function handleBulkApprove() {
        if (selectedPendingIds.length > 0) setBulkApproveDialogOpen(true);
    }
    function confirmBulkApprove() {
        setProcessing(true);
        setBulkApproveDialogOpen(false);
        router.post(
            '/hr/leave/bulk-approve',
            { request_ids: selectedPendingIds },
            {
                preserveScroll: true,
                onFinish: () => setProcessing(false),
                onSuccess: () => setSelectedRequestIds([]),
            },
        );
    }
    function handleBulkDecline() {
        if (selectedPendingIds.length > 0) {
            setDeclineTarget({ type: 'bulk' });
            setDeclineNotes('');
            setDeclineDialogOpen(true);
        }
    }
    function submitDecline() {
        if (!declineNotes.trim() || !declineTarget) return;
        setProcessing(true);
        if (declineTarget.type === 'single') {
            router.post(
                `/hr/leave/${declineTarget.id}/decline`,
                { review_notes: declineNotes.trim() },
                {
                    preserveScroll: true,
                    onFinish: () => setProcessing(false),
                    onSuccess: () => setDeclineDialogOpen(false),
                },
            );
        } else {
            router.post(
                '/hr/leave/bulk-decline',
                {
                    request_ids: selectedPendingIds,
                    review_notes: declineNotes.trim(),
                },
                {
                    preserveScroll: true,
                    onFinish: () => setProcessing(false),
                    onSuccess: () => {
                        setSelectedRequestIds([]);
                        setDeclineDialogOpen(false);
                    },
                },
            );
        }
    }
    function openDetail(request: LeaveRequest) {
        setDetailRequest(request);
    }
    function extendSlaByHours(requestId: number, hours: number) {
        router.post(
            `/hr/leave/${requestId}/sla-due`,
            { hours },
            {
                preserveScroll: true,
                onSuccess: () => toast.success(`SLA extended by ${hours}h`),
            },
        );
    }
    function escalateNow() {
        router.post(
            '/hr/leave/escalate-now',
            {},
            {
                preserveScroll: true,
                onSuccess: () => toast.success('Overdue approvals escalated'),
            },
        );
    }
    function copyRequestLink(requestId: number) {
        const url = `${window.location.origin}/hr/leave/${requestId}`;
        void navigator.clipboard?.writeText(url);
        toast.success('Link copied');
    }

    // Right-click / ⋯ context menu for a request row or card (handover parity:
    // approve · decline · open detail · extend SLA · escalate · view balance ·
    // view on calendar · copy link).
    function requestMenu(r: LeaveRequest): LeaveCtxItem[] {
        const pending = r.status === 'pending';
        const canAct = !!can.approve && pending;
        const items: LeaveCtxItem[] = [];
        if (canAct) {
            items.push({
                kind: 'item',
                label: 'Approve',
                icon: CheckCircle2,
                tone: 'success',
                kbd: 'A',
                onSelect: () => handleApprove(r.id),
            });
            items.push({
                kind: 'item',
                label: 'Decline…',
                icon: XCircle,
                tone: 'critical',
                onSelect: () => handleDecline(r.id),
            });
            items.push({ kind: 'divider' });
        }
        items.push({
            kind: 'item',
            label: 'Open detail',
            icon: ExternalLink,
            onSelect: () => openDetail(r),
        });
        if (canAct) {
            items.push({
                kind: 'item',
                label: 'Extend SLA +24h',
                icon: Clock,
                onSelect: () => extendSlaByHours(r.id, 24),
            });
            items.push({
                kind: 'item',
                label: 'Escalate now',
                icon: ArrowUpCircle,
                onSelect: escalateNow,
            });
        }
        items.push({ kind: 'divider' });
        items.push({
            kind: 'item',
            label: 'View balance',
            icon: Wallet,
            onSelect: () =>
                router.visit(
                    `/hr/leave/balances?q=${encodeURIComponent(r.staff_name)}`,
                ),
        });
        items.push({
            kind: 'item',
            label: 'View on calendar',
            icon: CalendarDays,
            onSelect: () => router.visit('/hr/leave?tab=calendar'),
        });
        items.push({
            kind: 'item',
            label: 'Copy link',
            icon: Link2,
            onSelect: () => copyRequestLink(r.id),
        });
        return items;
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Leave Management" />

            <PageShell>
                <LeaveHubHero
                    hero={hero}
                    can={can}
                    onRequestLeave={() => setRequestOpen(true)}
                />

                <LeaveHubTabs active={tab} pendingCount={sla.pending_total} />

                <div className="space-y-4">
                    {/* ===== Overview — requests master list ===== */}
                    {tab === 'overview' && (
                        <div className="flex flex-col gap-3.5">
                            {/* filter toolbar */}
                            <div className="flex flex-wrap items-center gap-2.5">
                                <form
                                    onSubmit={submitSearch}
                                    className="relative flex items-center"
                                >
                                    <Search className="pointer-events-none absolute left-2.5 h-4 w-4 text-muted-foreground" />
                                    <input
                                        type="text"
                                        value={searchTerm}
                                        onChange={(e) =>
                                            setSearchTerm(e.target.value)
                                        }
                                        placeholder="Search staff or reason…"
                                        className="h-9 w-[240px] rounded-[10px] border border-border bg-card pr-3 pl-8 text-sm outline-none focus:border-primary"
                                    />
                                </form>
                                <div className="flex flex-wrap items-center gap-1.5">
                                    {STATUS_FILTERS.map((f) => {
                                        const active =
                                            (filters.status ?? 'all') ===
                                            f.value;
                                        return (
                                            // eslint-disable-next-line no-restricted-syntax -- filter chip: custom selector button, not a form Button
                                            <button
                                                key={f.value}
                                                type="button"
                                                onClick={() =>
                                                    updateFilter(
                                                        'status',
                                                        f.value === 'all'
                                                            ? null
                                                            : f.value,
                                                    )
                                                }
                                                className={cn(
                                                    'inline-flex items-center gap-1.5 rounded-[9px] border px-3 py-1.5 text-[12.5px] font-semibold transition-colors',
                                                    active
                                                        ? 'border-primary bg-primary/10 text-primary'
                                                        : 'border-border text-muted-foreground hover:bg-muted',
                                                )}
                                            >
                                                {f.label}
                                            </button>
                                        );
                                    })}
                                </div>
                                <div className="ml-auto flex items-center gap-2">
                                    <Select
                                        value={filters.leave_type ?? 'all'}
                                        onValueChange={(v) =>
                                            updateFilter(
                                                'leave_type',
                                                v === 'all' ? null : v,
                                            )
                                        }
                                    >
                                        <SelectTrigger className="h-9 w-[160px]">
                                            <SelectValue placeholder="All types" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">
                                                All types
                                            </SelectItem>
                                            {leaveTypes.map((t) => (
                                                <SelectItem
                                                    key={t.value}
                                                    value={t.value}
                                                >
                                                    {t.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {can.manage && (
                                        <Button
                                            asChild
                                            variant="outline"
                                            size="sm"
                                        >
                                            <a href="/hr/leave/export">
                                                Export
                                            </a>
                                        </Button>
                                    )}
                                    {can.create && (
                                        <Button
                                            size="sm"
                                            onClick={() => setRequestOpen(true)}
                                        >
                                            New request
                                        </Button>
                                    )}
                                </div>
                            </div>

                            {/* requests table */}
                            <div className="overflow-hidden rounded-[14px] border border-border bg-card">
                                <div className="grid grid-cols-[1.6fr_1fr_1.3fr_0.7fr_0.9fr_0.8fr] gap-2 border-b border-border bg-muted px-4 py-2.5 text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                                    <span>Staff</span>
                                    <span>Type</span>
                                    <span>Dates</span>
                                    <span>Hours</span>
                                    <span>Status</span>
                                    <span className="text-right">SLA</span>
                                </div>
                                {allRequests.length === 0 ? (
                                    <div className="flex flex-col items-center gap-2 py-14 text-center text-sm text-muted-foreground">
                                        <CalendarDays className="h-10 w-10 opacity-40" />
                                        No leave requests found.
                                    </div>
                                ) : (
                                    allRequests.map((r) => (
                                        // eslint-disable-next-line no-restricted-syntax -- dense clickable table row, not a form Button
                                        <div
                                            key={r.id}
                                            role="button"
                                            tabIndex={0}
                                            onClick={() => openDetail(r)}
                                            onKeyDown={(e) => {
                                                if (
                                                    e.key === 'Enter' ||
                                                    e.key === ' '
                                                ) {
                                                    e.preventDefault();
                                                    openDetail(r);
                                                }
                                            }}
                                            onContextMenu={openCtx(
                                                requestMenu(r),
                                            )}
                                            className="grid cursor-pointer grid-cols-[1.6fr_1fr_1.3fr_0.7fr_0.9fr_0.8fr] items-center gap-2 border-b border-border px-4 py-2.5 text-[13px] last:border-b-0 hover:bg-muted"
                                        >
                                            <div className="flex min-w-0 items-center gap-2.5">
                                                <LeaveAvatar
                                                    name={r.staff_name}
                                                    size={32}
                                                />
                                                <div className="min-w-0">
                                                    <div className="truncate font-bold">
                                                        {r.staff_name}
                                                    </div>
                                                </div>
                                            </div>
                                            <LeaveTypeChip
                                                type={r.leave_type}
                                            />
                                            <span className="text-muted-foreground">
                                                {r.start_date} – {r.end_date}
                                            </span>
                                            <span className="font-semibold">
                                                {r.hours}h
                                            </span>
                                            <LeaveStatusChip
                                                status={r.status}
                                            />
                                            <span className="flex justify-end">
                                                <LeaveSlaChip request={r} />
                                            </span>
                                        </div>
                                    ))
                                )}
                            </div>

                            <div className="flex items-center justify-between text-[11.5px] text-muted-foreground">
                                <span>
                                    Showing {allRequests.length} of{' '}
                                    {requests.total} · click a row to open
                                </span>
                                {requests.last_page > 1 && (
                                    <LaravelPagination links={requests.links} />
                                )}
                            </div>
                        </div>
                    )}

                    {/* ===== Approvals — segmented card queue ===== */}
                    {tab === 'approvals' && (
                        <div className="flex flex-col gap-3.5">
                            {/* segments */}
                            <div className="flex flex-wrap items-center gap-2">
                                <div className="inline-flex gap-0.5 rounded-[11px] border border-border bg-card p-0.5">
                                    {INBOX_SEGMENTS.map((s) => {
                                        const active = segment === s.key;
                                        return (
                                            // eslint-disable-next-line no-restricted-syntax -- segment selector chip, not a form Button
                                            <button
                                                key={s.key}
                                                type="button"
                                                onClick={() => {
                                                    setSegment(s.key);
                                                    setSelectedRequestIds([]);
                                                }}
                                                className={cn(
                                                    'inline-flex items-center gap-1.5 rounded-[8px] px-3 py-1.5 text-[12.5px] font-semibold transition-colors',
                                                    active
                                                        ? 'bg-primary text-primary-foreground'
                                                        : 'text-muted-foreground hover:bg-muted',
                                                )}
                                            >
                                                {s.label}
                                                <span
                                                    className={cn(
                                                        'inline-grid h-[18px] min-w-[18px] place-items-center rounded-full px-1.5 text-[10.5px] font-extrabold',
                                                        active
                                                            ? 'bg-primary-foreground/20'
                                                            : 'bg-muted',
                                                    )}
                                                >
                                                    {inbox[s.key].count}
                                                </span>
                                            </button>
                                        );
                                    })}
                                </div>
                                <span className="ml-auto text-[11.5px] font-semibold text-muted-foreground">
                                    Sorted by SLA urgency
                                </span>
                            </div>

                            {/* bulk bar */}
                            {can.approve &&
                                segmentIsPending &&
                                selectedPendingIds.length > 0 && (
                                    <div className="flex flex-wrap items-center gap-2.5 rounded-[13px] border border-primary/30 bg-accent px-3.5 py-2.5">
                                        <span className="text-[13px] font-bold text-accent-foreground">
                                            {selectedPendingIds.length} selected
                                        </span>
                                        <div className="ml-auto flex gap-2">
                                            <Button
                                                size="sm"
                                                onClick={handleBulkApprove}
                                                disabled={processing}
                                            >
                                                {processing && (
                                                    <Loader2 className="mr-1 h-3 w-3 animate-spin" />
                                                )}
                                                Approve selected
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                className="border-status-critical/30 text-status-critical hover:bg-status-critical-bg"
                                                onClick={handleBulkDecline}
                                                disabled={processing}
                                            >
                                                Decline selected
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    setSelectedRequestIds([])
                                                }
                                            >
                                                Clear
                                            </Button>
                                        </div>
                                    </div>
                                )}

                            {/* list */}
                            {segmentItems.length === 0 ? (
                                <div className="flex flex-col items-center gap-2.5 rounded-2xl border border-dashed border-border bg-card px-6 py-14 text-center">
                                    <div className="grid h-[54px] w-[54px] place-items-center rounded-full bg-status-success-bg text-status-success">
                                        <CheckCircle2 className="h-7 w-7" />
                                    </div>
                                    <div className="text-base font-extrabold">
                                        You&apos;re all caught up ✅
                                    </div>
                                    <div className="max-w-[340px] text-[13px] text-muted-foreground">
                                        Nothing waiting in this view. New
                                        requests land here the moment staff
                                        submit.
                                    </div>
                                </div>
                            ) : (
                                <div className="flex flex-col gap-2.5">
                                    {segmentItems.map((r) => (
                                        <ApprovalCard
                                            key={r.id}
                                            request={r}
                                            selectable={
                                                !!can.approve &&
                                                segmentIsPending
                                            }
                                            checked={selectedPendingIds.includes(
                                                r.id,
                                            )}
                                            onToggle={(checked) =>
                                                toggleRequestSelection(
                                                    r.id,
                                                    checked,
                                                )
                                            }
                                            onApprove={() =>
                                                handleApprove(r.id)
                                            }
                                            onDecline={() =>
                                                handleDecline(r.id)
                                            }
                                            onMore={openCtx(requestMenu(r))}
                                            onOpenDetail={() => openDetail(r)}
                                            processing={processing}
                                        />
                                    ))}
                                </div>
                            )}
                        </div>
                    )}

                    {/* ===== Calendar ===== */}
                    {tab === 'calendar' && (
                        <LeaveCalendarPane
                            calendar={calendar}
                            currentMonth={new Date().toISOString().slice(0, 7)}
                            onOpenEntry={(entry) =>
                                setDetailRequest({
                                    id: entry.id,
                                    staff_name: entry.user_name,
                                    staff_id: entry.user_id,
                                    leave_type: entry.leave_type,
                                    period: entry.period,
                                    start_date: entry.start,
                                    end_date: entry.end,
                                    hours: entry.hours ?? 0,
                                    status: entry.status as LeaveRequest['status'],
                                    reason: entry.reason,
                                    reason_restricted: entry.reason_restricted,
                                    submitted_at: entry.submitted_at,
                                    hours_waiting: entry.hours_waiting,
                                    is_overdue: entry.is_overdue,
                                    due_within_24h: entry.due_within_24h,
                                    roster_conflict: entry.roster_conflict,
                                    balance_impact: entry.balance_impact,
                                })
                            }
                        />
                    )}
                </div>
            </PageShell>

            {/* Bulk Approve Confirmation */}
            <AlertDialog
                open={bulkApproveDialogOpen}
                onOpenChange={setBulkApproveDialogOpen}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Approve {selectedPendingIds.length} Leave Request
                            {selectedPendingIds.length === 1 ? '' : 's'}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            This will approve {selectedPendingIds.length}{' '}
                            pending leave{' '}
                            {selectedPendingIds.length === 1
                                ? 'request'
                                : 'requests'}
                            . This action cannot be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={confirmBulkApprove}>
                            Approve {selectedPendingIds.length} Request
                            {selectedPendingIds.length === 1 ? '' : 's'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {/* Decline Dialog */}
            <Dialog
                open={declineDialogOpen}
                onOpenChange={setDeclineDialogOpen}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {declineTarget?.type === 'bulk'
                                ? `Decline ${selectedPendingIds.length} Leave Request(s)`
                                : 'Decline Leave Request'}
                        </DialogTitle>
                    </DialogHeader>
                    <div className="space-y-2">
                        <Label htmlFor="decline-notes">
                            Reason for declining (required)
                        </Label>
                        <Textarea
                            id="decline-notes"
                            value={declineNotes}
                            onChange={(e) => setDeclineNotes(e.target.value)}
                            placeholder="Enter the reason for declining this request..."
                            rows={3}
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setDeclineDialogOpen(false)}
                            disabled={processing}
                        >
                            <X className="mr-1 h-4 w-4" /> Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={submitDecline}
                            disabled={!declineNotes.trim() || processing}
                        >
                            {processing ? (
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                            ) : null}{' '}
                            Decline
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {can.create && (
                <LeaveRequestDialog
                    open={requestOpen}
                    onClose={() => setRequestOpen(false)}
                    staff={staff}
                    leaveTypes={leaveTypes}
                    holidays={publicHolidays}
                />
            )}

            <LeaveDetailModal
                request={detailRequest}
                can={can}
                processing={processing}
                onClose={() => setDetailRequest(null)}
                onApprove={(id) => {
                    setDetailRequest(null);
                    handleApprove(id);
                }}
                onDecline={(id) => {
                    setDetailRequest(null);
                    handleDecline(id);
                }}
                onExtendSla={(id) => extendSlaByHours(id, 24)}
                onEscalate={() => escalateNow()}
            />

            {ctxElement}
        </AppLayout>
    );
}
