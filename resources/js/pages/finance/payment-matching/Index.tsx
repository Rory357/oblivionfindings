import {
    ConfirmDialog,
    FinanceSectionRail,
    formatMoney,
} from '@/components/finance';
import {
    EntityContextMenu,
    EntityTable,
    ListCaption,
    compactMenu,
    useEntityContextMenu,
    type EntityTableColumn,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { EmptyList, EmptySearch } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeftRight,
    CheckCircle2,
    Landmark,
    Settings,
    ThumbsDown,
    Zap,
} from 'lucide-react';
import { useCallback, useState } from 'react';

import { RejectMatchDialog } from './_dialogs';

interface BankTransaction {
    id: number;
    transaction_date: string;
    description: string;
    reference: string | null;
    amount: number;
    bank_account_name: string | null;
}

interface Matchable {
    id: number;
    type: string;
    type_label: string;
    url: string | null;
    number: string;
    amount_due: number;
    total_amount: number;
    vendor_name: string | null;
    due_date: string | null;
}

interface PaymentMatch {
    id: number;
    bank_transaction: BankTransaction | null;
    matchable: Matchable | null;
    confidence_score: number;
    match_reasons: string[];
    status: string;
    confirmed_by_name: string | null;
    confirmed_at: string | null;
    created_at: string;
}

interface PaginatedMatches {
    data: PaymentMatch[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface Filters {
    status: string;
    min_confidence: string;
}

interface Summary {
    total: number;
    suggested: number;
    confirmed: number;
    rejected: number;
    unreconciled_transactions: number;
}

interface Props {
    matches: PaginatedMatches;
    filters: Filters;
    summary: Summary;
}

const ALL = '__all';

const STATUS_OPTIONS = [
    { value: ALL, label: 'Any status' },
    { value: 'suggested', label: 'Suggested' },
    { value: 'all_confirmed', label: 'Confirmed' },
    { value: 'rejected', label: 'Rejected' },
];

const CONFIDENCE_OPTIONS = [
    { value: ALL, label: 'Any confidence' },
    { value: '80', label: '80% and above' },
    { value: '60', label: '60% and above' },
    { value: '40', label: '40% and above' },
];

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'Banking', href: '/finance/banking' },
    { title: 'Payment matching', href: '/finance/payment-matching' },
];

/**
 * The confidence score is a measured percentage, not a lifecycle state, so it
 * keeps its own graded pill rather than going through <StatusBadge>.
 */
const confidenceTone = (score: number): 'success' | 'warning' | 'critical' =>
    score >= 80 ? 'success' : score >= 50 ? 'warning' : 'critical';

export default function PaymentMatchingIndex({
    matches,
    filters,
    summary,
}: Props) {
    const [autoMatchOpen, setAutoMatchOpen] = useState(false);
    const [autoMatching, setAutoMatching] = useState(false);
    const [confirmTarget, setConfirmTarget] = useState<PaymentMatch | null>(
        null,
    );
    const [confirming, setConfirming] = useState(false);
    const [rejectTarget, setRejectTarget] = useState<PaymentMatch | null>(null);
    const ctxMenu = useEntityContextMenu<PaymentMatch>();

    const applyFilters = useCallback(
        (next: Partial<Filters>) => {
            router.get(
                '/finance/payment-matching',
                { ...filters, ...next, page: 1 },
                { preserveState: true, preserveScroll: true, replace: true },
            );
        },
        [filters],
    );

    const clearFilters = () =>
        router.get('/finance/payment-matching', {}, { preserveState: true });

    const hasFilters = Boolean(filters.status || filters.min_confidence);

    const runAutoMatch = () => {
        setAutoMatching(true);
        router.post(
            '/finance/payment-matching/match-all',
            {},
            {
                onFinish: () => {
                    setAutoMatching(false);
                    setAutoMatchOpen(false);
                },
            },
        );
    };

    const confirmMatch = () => {
        if (!confirmTarget) return;
        router.post(
            `/finance/payment-matching/${confirmTarget.id}/confirm`,
            {},
            {
                preserveScroll: true,
                onStart: () => setConfirming(true),
                onFinish: () => setConfirming(false),
                onSuccess: () => setConfirmTarget(null),
            },
        );
    };

    const matchLabel = (match: PaymentMatch) =>
        match.matchable
            ? `${match.matchable.type_label} ${match.matchable.number}`
            : 'This suggestion';

    const actionsFor = (match: PaymentMatch): MenuItem[] =>
        compactMenu([
            match.matchable?.url
                ? {
                      label: `Open ${match.matchable.type_label.toLowerCase()}`,
                      icon: ArrowLeftRight,
                      onClick: () => router.visit(match.matchable!.url!),
                  }
                : null,
            {
                label: 'Open bank transactions',
                icon: Landmark,
                onClick: () =>
                    router.visit('/finance/bank-transactions?status=unreconciled'),
            },
            match.status === 'suggested' ? { separator: true } : null,
            match.status === 'suggested'
                ? {
                      label: 'Confirm match',
                      icon: CheckCircle2,
                      onClick: () => setConfirmTarget(match),
                  }
                : null,
            match.status === 'suggested'
                ? {
                      label: 'Reject match',
                      icon: ThumbsDown,
                      danger: true,
                      onClick: () => setRejectTarget(match),
                  }
                : null,
        ]);

    const columns: EntityTableColumn<PaymentMatch>[] = [
        {
            key: 'amount',
            label: 'Paid',
            width: '0.9fr',
            align: 'right',
            cell: (match) =>
                match.bank_transaction ? (
                    <span className="font-medium tabular-nums">
                        {formatMoney(match.bank_transaction.amount)}
                    </span>
                ) : (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            key: 'matched',
            label: 'Matched to',
            width: '1.3fr',
            cell: (match) =>
                match.matchable ? (
                    <span className="flex min-w-0 flex-col">
                        {match.matchable.url ? (
                            <Link
                                href={match.matchable.url}
                                className="truncate font-medium text-primary underline-offset-4 hover:underline"
                            >
                                {match.matchable.type_label}{' '}
                                {match.matchable.number}
                            </Link>
                        ) : (
                            <span className="truncate font-medium">
                                {match.matchable.type_label}{' '}
                                {match.matchable.number}
                            </span>
                        )}
                        {match.matchable.vendor_name ? (
                            <span className="truncate text-[11px] text-muted-foreground">
                                {match.matchable.vendor_name}
                            </span>
                        ) : null}
                    </span>
                ) : (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            key: 'due',
            label: 'Amount due',
            width: '0.9fr',
            align: 'right',
            cell: (match) =>
                match.matchable ? (
                    <span className="tabular-nums">
                        {formatMoney(match.matchable.amount_due)}
                    </span>
                ) : (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            key: 'confidence',
            label: 'Confidence',
            width: '0.7fr',
            cell: (match) => (
                <StatusBadge
                    variant={confidenceTone(match.confidence_score)}
                    label={`${Math.round(match.confidence_score)}%`}
                />
            ),
        },
        {
            key: 'reasons',
            label: 'Why',
            width: '1.2fr',
            cell: (match) =>
                match.match_reasons.length ? (
                    <span className="truncate text-[11px] text-muted-foreground">
                        {match.match_reasons.join(' · ')}
                    </span>
                ) : (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            key: 'status',
            label: 'Status',
            width: '0.9fr',
            cell: (match) => (
                <span className="flex min-w-0 flex-col gap-0.5">
                    <StatusBadge status={match.status} />
                    {match.confirmed_by_name ? (
                        <span className="truncate text-[11px] text-muted-foreground">
                            by {match.confirmed_by_name}
                        </span>
                    ) : null}
                </span>
            ),
        },
    ];

    const header = (
        <PageHeader
            variant="index"
            icon={ArrowLeftRight}
            title="Payment matching"
            titleChip={
                <PageHeaderStatusChip
                    variant={summary.suggested > 0 ? 'warning' : 'success'}
                >
                    {summary.suggested > 0
                        ? `${summary.suggested} to review`
                        : 'Nothing to review'}
                </PageHeaderStatusChip>
            }
            subline={`Banking · ${summary.total} suggestions · ${summary.unreconciled_transactions} unreconciled transactions`}
            actions={
                <>
                    <PageHeaderGlassButton
                        icon={Settings}
                        onClick={() => router.visit('/finance/match-rules')}
                    >
                        Match rules
                    </PageHeaderGlassButton>
                    <PageHeaderPrimaryButton
                        icon={Zap}
                        onClick={() => setAutoMatchOpen(true)}
                    >
                        Run auto-match
                    </PageHeaderPrimaryButton>
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Suggestions"
                        href="/finance/payment-matching"
                        ariaLabel="View every suggestion"
                    >
                        <PageHeaderMeterBig>{summary.total}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Raised by matching so far
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Awaiting review"
                        tone={summary.suggested > 0 ? 'warning' : 'brand'}
                        href="/finance/payment-matching?status=suggested"
                        ariaLabel="View suggestions awaiting review"
                    >
                        <PageHeaderMeterBig>
                            {summary.suggested}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Confirm or reject to clear
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Confirmed"
                        tone="success"
                        href="/finance/payment-matching?status=all_confirmed"
                        ariaLabel="View confirmed matches"
                    >
                        <PageHeaderMeterBig>
                            {summary.confirmed}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Allocated against a bill or invoice
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Unreconciled"
                        tone={
                            summary.unreconciled_transactions > 0
                                ? 'warning'
                                : 'brand'
                        }
                        href="/finance/bank-transactions?status=unreconciled"
                        ariaLabel="View unreconciled bank transactions"
                    >
                        <PageHeaderMeterBig>
                            {summary.unreconciled_transactions}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Bank lines still to match
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Status"
                        value={filters.status || ALL}
                        allValue={ALL}
                        options={STATUS_OPTIONS}
                        onChange={(value) =>
                            applyFilters({
                                status: value === ALL ? '' : value,
                            })
                        }
                    />
                    <PageHeaderFilterSelect
                        label="Confidence"
                        value={filters.min_confidence || ALL}
                        allValue={ALL}
                        options={CONFIDENCE_OPTIONS}
                        onChange={(value) =>
                            applyFilters({
                                min_confidence: value === ALL ? '' : value,
                            })
                        }
                    />
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Payment matching" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Suggested matches"
                        caption={`${matches.data.length} of ${matches.total} shown`}
                    />

                    {matches.data.length === 0 ? (
                        hasFilters ? (
                            <EmptySearch
                                onClear={clearFilters}
                                title="No matches fit your filters"
                            />
                        ) : (
                            <EmptyList
                                icon={ArrowLeftRight}
                                itemName="match"
                                title="No matches yet"
                                description="Run auto-match to pair unreconciled bank transactions with open bills and invoices."
                                action={
                                    <Button
                                        size="sm"
                                        onClick={() => setAutoMatchOpen(true)}
                                    >
                                        Run auto-match
                                    </Button>
                                }
                            />
                        )
                    ) : (
                        <>
                            <EntityTable
                                rows={matches.data}
                                rowKey={(match) => match.id}
                                identityLabel="Bank transaction"
                                identity={(match) => ({
                                    icon: Landmark,
                                    name:
                                        match.bank_transaction?.description ??
                                        'Transaction removed',
                                    subline: match.bank_transaction
                                        ? [
                                              match.bank_transaction
                                                  .transaction_date,
                                              match.bank_transaction.reference
                                                  ? `Ref ${match.bank_transaction.reference}`
                                                  : null,
                                              match.bank_transaction
                                                  .bank_account_name,
                                          ]
                                              .filter(Boolean)
                                              .join(' · ')
                                        : undefined,
                                })}
                                columns={columns}
                                actionsFor={actionsFor}
                                onRowContextMenu={ctxMenu.open}
                                minWidth={1180}
                            />
                            <LaravelPagination
                                links={matches.links}
                                lastPage={matches.last_page}
                            />
                        </>
                    )}
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={ArrowLeftRight}
                    title={
                        ctxMenu.ctx.record.bank_transaction?.description ??
                        'Match'
                    }
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}

            <ConfirmDialog
                open={autoMatchOpen}
                onClose={() => setAutoMatchOpen(false)}
                title="Run auto-match?"
                description="This scores every unreconciled bank transaction against open bills and invoices. Anything above a rule's auto-confirm threshold is allocated straight away, which posts a payment to the ledger."
                confirmText="Run auto-match"
                variant="default"
                processing={autoMatching}
                onConfirm={runAutoMatch}
            />

            <ConfirmDialog
                open={!!confirmTarget}
                onClose={() => setConfirmTarget(null)}
                title="Confirm this match?"
                description={
                    <>
                        This allocates{' '}
                        <span className="font-medium text-foreground">
                            {confirmTarget?.bank_transaction
                                ? formatMoney(
                                      confirmTarget.bank_transaction.amount,
                                  )
                                : 'the payment'}
                        </span>{' '}
                        against{' '}
                        <span className="font-medium text-foreground">
                            {confirmTarget ? matchLabel(confirmTarget) : ''}
                        </span>{' '}
                        and posts the payment to the ledger.
                    </>
                }
                confirmText="Confirm match"
                variant="default"
                processing={confirming}
                onConfirm={confirmMatch}
            />

            <RejectMatchDialog
                open={!!rejectTarget}
                matchId={rejectTarget?.id ?? null}
                label={rejectTarget ? matchLabel(rejectTarget) : ''}
                onClose={() => setRejectTarget(null)}
            />
        </AppLayout>
    );
}
