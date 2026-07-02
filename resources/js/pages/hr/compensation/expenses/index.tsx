import { CompensationHero, CompensationTabs, type CompensationHeroStats } from '@/components/hr';
import { ExpenseClaimDialog } from '@/components/hr/expense-claim-dialog';
import { StatusBadge } from '@/components/hr/status-badge';
import { PageLayout } from '@/components/page';
import {
    AlertDialog,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle, Eye, Plus, Send, XCircle } from 'lucide-react';
import { useState } from 'react';

type ExpenseClaim = {
    id: number;
    claim_number: string;
    title: string;
    staff_name: string;
    status: string;
    total_amount: number;
    currency: string;
    items_count: number;
    submitted_at: string | null;
    created_at: string;
};

type Props = {
    claims: {
        data: ExpenseClaim[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    filters: { status: string | null; q: string };
    stats: CompensationHeroStats;
    // `approve` is surfaced once the backend adds it (see backendNeeded); until
    // then we fall back to `manage` so managers still see the inline actions.
    can: { create: boolean; manage: boolean; approve?: boolean };
    // Read-only IRD mileage rate (NZD/km) + the category list, surfaced for the
    // New-claim dialog. Both are optional so the page renders before the
    // controller passes them (see backendNeeded) — the dialog falls back safely.
    mileageRatePerKm?: number;
    categories?: string[];
    employees?: { id: number; name: string }[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Expenses', href: '/hr/compensation/expenses' },
];

const formatCurrency = (amount: number, currency = 'NZD') => {
    return new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency,
    }).format(amount);
};

// Statuses that count as "decided" for the segmented lens.
const DECIDED_STATUSES = ['approved', 'rejected', 'paid', 'declined'];

// Three top-level lenses. Awaiting maps to the single `submitted` status; All
// clears the filter; Decided asks the backend for the decided set (whereIn).
type Lens = 'awaiting' | 'all' | 'decided';
const LENSES: { key: Lens; label: string; status: string | null }[] = [
    { key: 'awaiting', label: 'Awaiting', status: 'submitted' },
    { key: 'all', label: 'All', status: null },
    { key: 'decided', label: 'Decided', status: 'decided' },
];

const activeLens = (status: string | null): Lens => {
    if (!status) return 'all';
    if (status === 'submitted') return 'awaiting';
    if (status === 'decided' || DECIDED_STATUSES.includes(status)) {
        return 'decided';
    }
    // draft (and any other single status) → no lens is highlighted; the
    // granular chips below carry that selection instead.
    return 'all';
};

export default function ExpenseIndex({
    claims,
    filters,
    stats,
    can,
    mileageRatePerKm = 0,
    categories,
    employees = [],
}: Props) {
    // Managers see the inline approval actions; prefer the dedicated `approve`
    // grant when the backend provides it, otherwise fall back to `manage`.
    const canDecide = can.approve ?? can.manage;

    const [claimOpen, setClaimOpen] = useState(false);
    const [rejectTarget, setRejectTarget] = useState<ExpenseClaim | null>(null);
    const [rejectionReason, setRejectionReason] = useState('');
    const [busyId, setBusyId] = useState<number | null>(null);
    const [selected, setSelected] = useState<number[]>([]);

    // Only submitted (awaiting) claims are bulk-approvable.
    const pendingIds = claims.data
        .filter((c) => c.status === 'submitted')
        .map((c) => c.id);
    const toggleOne = (id: number) =>
        setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));
    const toggleAll = () =>
        setSelected((s) => (s.length === pendingIds.length ? [] : [...pendingIds]));
    const bulkApprove = () => {
        if (!selected.length) return;
        router.post(
            '/hr/compensation/expenses/bulk-approve',
            { claim_ids: selected },
            { preserveScroll: true, onSuccess: () => setSelected([]) },
        );
    };

    const onFilter = (next: Partial<typeof filters>) => {
        router.get(
            '/hr/compensation/expenses',
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true },
        );
    };

    const lens = activeLens(filters.status);

    const approve = (claim: ExpenseClaim) => {
        setBusyId(claim.id);
        router.post(
            `/hr/compensation/expenses/${claim.id}/approve`,
            {},
            {
                preserveScroll: true,
                onFinish: () => setBusyId(null),
            },
        );
    };

    // Rejected claims can be resubmitted by their owner (or a manager) —
    // the backend clears the prior decision and re-queues the claim.
    const resubmit = (claim: ExpenseClaim) => {
        setBusyId(claim.id);
        router.post(
            `/hr/compensation/expenses/${claim.id}/submit`,
            {},
            {
                preserveScroll: true,
                onFinish: () => setBusyId(null),
            },
        );
    };

    const confirmReject = () => {
        if (!rejectTarget || !rejectionReason.trim()) return;
        const id = rejectTarget.id;
        setBusyId(id);
        router.post(
            `/hr/compensation/expenses/${id}/reject`,
            { rejection_reason: rejectionReason.trim() },
            {
                preserveScroll: true,
                onFinish: () => setBusyId(null),
                onSuccess: () => {
                    setRejectTarget(null);
                    setRejectionReason('');
                },
            },
        );
    };

    // Base 7 cols + Employee (manage) + leading checkbox (canDecide).
    const colSpan = 7 + (can.manage ? 1 : 0) + (canDecide ? 1 : 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Expense Claims" />

            <PageLayout hero={<CompensationHero stats={stats} />}>
                <CompensationTabs active="expenses" />

                {can.create ? (
                    <div className="flex items-center justify-end gap-2">
                        <Button asChild size="sm" variant="outline">
                            <Link href="/hr/compensation/expenses/create">
                                Full form
                            </Link>
                        </Button>
                        <Button size="sm" onClick={() => setClaimOpen(true)}>
                            <Plus className="mr-1.5 h-4 w-4" />
                            New claim
                        </Button>
                    </div>
                ) : null}

                {/* Segmented lens + granular status chips + search */}
                <div className="flex flex-wrap items-center gap-3">
                    {/* Awaiting / All / Decided */}
                    <div className="inline-flex rounded-lg border border-border bg-muted/40 p-0.5">
                        {LENSES.map((l) => (
                            // eslint-disable-next-line no-restricted-syntax -- segmented-control lens toggle, styled like the wizard Segmented primitive.
                            <button
                                key={l.key}
                                type="button"
                                onClick={() => onFilter({ status: l.status })}
                                className={
                                    'rounded-md px-3 py-1.5 text-sm font-medium transition-colors ' +
                                    (lens === l.key
                                        ? 'bg-background text-foreground shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground')
                                }
                                aria-pressed={lens === l.key}
                            >
                                {l.label}
                            </button>
                        ))}
                    </div>

                    {/* Granular status chips — keep every status reachable */}
                    <div className="flex flex-wrap gap-2">
                        {['draft', 'approved', 'rejected', 'paid'].map((s) => (
                            <Button
                                key={s}
                                variant={
                                    filters.status === s ? 'default' : 'outline'
                                }
                                size="sm"
                                aria-pressed={filters.status === s}
                                onClick={() => onFilter({ status: s })}
                            >
                                <span className="capitalize">{s}</span>
                            </Button>
                        ))}
                    </div>

                    {can.manage && (
                        <Input
                            placeholder="Search by name..."
                            value={filters.q || ''}
                            onChange={(e) => onFilter({ q: e.target.value })}
                            className="ml-auto w-56"
                        />
                    )}
                </div>

                {/* Bulk-approve bar — only when awaiting claims are selected */}
                {canDecide && selected.length > 0 ? (
                    <div className="flex items-center justify-between rounded-lg border border-primary/30 bg-primary/10 px-4 py-2.5">
                        <span className="text-sm font-medium">
                            {selected.length} claim{selected.length === 1 ? '' : 's'} selected
                        </span>
                        <div className="flex items-center gap-2">
                            <Button size="sm" variant="ghost" onClick={() => setSelected([])}>
                                Clear
                            </Button>
                            <Button size="sm" onClick={bulkApprove}>
                                <CheckCircle className="mr-1.5 h-4 w-4" />
                                Approve selected
                            </Button>
                        </div>
                    </div>
                ) : null}

                {/* Claims Table */}
                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    {canDecide && (
                                        <TableHead className="w-px">
                                            <Checkbox
                                                aria-label="Select all awaiting claims"
                                                checked={
                                                    pendingIds.length > 0 &&
                                                    selected.length === pendingIds.length
                                                }
                                                disabled={pendingIds.length === 0}
                                                onCheckedChange={toggleAll}
                                            />
                                        </TableHead>
                                    )}
                                    <TableHead>Claim #</TableHead>
                                    <TableHead>Title</TableHead>
                                    {can.manage && (
                                        <TableHead>Employee</TableHead>
                                    )}
                                    <TableHead>Items</TableHead>
                                    <TableHead className="text-right">
                                        Amount
                                    </TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Submitted</TableHead>
                                    <TableHead className="w-px text-right">
                                        Actions
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {claims.data.map((claim) => {
                                    const isPending =
                                        claim.status === 'submitted';
                                    const busy = busyId === claim.id;
                                    return (
                                        <TableRow key={claim.id}>
                                            {canDecide && (
                                                <TableCell className="w-px">
                                                    {isPending ? (
                                                        <Checkbox
                                                            aria-label={`Select claim ${claim.claim_number}`}
                                                            checked={selected.includes(claim.id)}
                                                            onCheckedChange={() => toggleOne(claim.id)}
                                                        />
                                                    ) : null}
                                                </TableCell>
                                            )}
                                            <TableCell className="font-mono text-sm">
                                                {claim.claim_number}
                                            </TableCell>
                                            <TableCell className="font-medium">
                                                {claim.title}
                                            </TableCell>
                                            {can.manage && (
                                                <TableCell className="text-muted-foreground">
                                                    {claim.staff_name}
                                                </TableCell>
                                            )}
                                            <TableCell>
                                                {claim.items_count}
                                            </TableCell>
                                            <TableCell className="text-right font-medium">
                                                {formatCurrency(
                                                    claim.total_amount,
                                                    claim.currency,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <StatusBadge
                                                    status={claim.status}
                                                />
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {claim.submitted_at || '-'}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex items-center justify-end gap-1.5">
                                                    {canDecide &&
                                                        isPending && (
                                                            <>
                                                                <Button
                                                                    size="sm"
                                                                    variant="outline"
                                                                    disabled={
                                                                        busy
                                                                    }
                                                                    onClick={() =>
                                                                        approve(
                                                                            claim,
                                                                        )
                                                                    }
                                                                    className="border-status-success/40 text-status-success hover:bg-status-success-bg"
                                                                >
                                                                    <CheckCircle className="mr-1 h-3.5 w-3.5" />
                                                                    Approve
                                                                </Button>
                                                                <Button
                                                                    size="sm"
                                                                    variant="outline"
                                                                    disabled={
                                                                        busy
                                                                    }
                                                                    onClick={() => {
                                                                        setRejectionReason(
                                                                            '',
                                                                        );
                                                                        setRejectTarget(
                                                                            claim,
                                                                        );
                                                                    }}
                                                                    className="border-status-critical/40 text-status-critical hover:bg-status-critical-bg"
                                                                >
                                                                    <XCircle className="mr-1 h-3.5 w-3.5" />
                                                                    Reject
                                                                </Button>
                                                            </>
                                                        )}
                                                    {claim.status ===
                                                        'rejected' && (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            disabled={busy}
                                                            onClick={() =>
                                                                resubmit(claim)
                                                            }
                                                        >
                                                            <Send className="mr-1 h-3.5 w-3.5" />
                                                            Resubmit
                                                        </Button>
                                                    )}
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={`/hr/compensation/expenses/${claim.id}`}
                                                            aria-label={`View claim ${claim.claim_number}`}
                                                        >
                                                            <Eye className="h-3.5 w-3.5" />
                                                        </Link>
                                                    </Button>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                                {claims.data.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={colSpan}
                                            className="py-8 text-center text-muted-foreground"
                                        >
                                            No expense claims found.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {/* Pagination */}
                {claims.links?.length > 3 && (
                    <LaravelPagination links={claims.links} />
                )}
            </PageLayout>

            {/* New claim — unified 3-step wizard (basics → items + mileage → review) */}
            {can.create ? (
                <ExpenseClaimDialog
                    open={claimOpen}
                    onClose={() => setClaimOpen(false)}
                    mileageRatePerKm={mileageRatePerKm}
                    categories={categories}
                    employees={employees}
                    canFileOnBehalf={can.manage}
                />
            ) : null}

            {/* Reject — reason required */}
            <AlertDialog
                open={rejectTarget !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setRejectTarget(null);
                        setRejectionReason('');
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Reject expense claim</AlertDialogTitle>
                        <AlertDialogDescription>
                            {rejectTarget
                                ? `Provide a reason for rejecting ${rejectTarget.claim_number} (${rejectTarget.title}). The claimant will see this.`
                                : ''}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <div className="space-y-2">
                        <Label htmlFor="reject-reason">Rejection reason</Label>
                        <Textarea
                            id="reject-reason"
                            rows={3}
                            value={rejectionReason}
                            onChange={(e) =>
                                setRejectionReason(e.target.value)
                            }
                            placeholder="e.g. Missing receipt for the accommodation line."
                        />
                    </div>
                    <AlertDialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => {
                                setRejectTarget(null);
                                setRejectionReason('');
                            }}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={
                                !rejectionReason.trim() ||
                                busyId === rejectTarget?.id
                            }
                            onClick={confirmReject}
                        >
                            Confirm rejection
                        </Button>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AppLayout>
    );
}
