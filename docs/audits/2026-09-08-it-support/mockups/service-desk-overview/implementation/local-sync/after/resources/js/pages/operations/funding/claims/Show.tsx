import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import {
    CalendarDays,
    CheckCircle2,
    FileCheck,
    RefreshCw,
    Send,
} from 'lucide-react';
import { useMemo, useState } from 'react';

type ClaimItem = {
    id: number;
    description: string;
    quantity: number;
    unit_price: number;
    total_amount: number;
    service_date: string;
    funding_contract_reference?: string | null;
};

type Claim = {
    id: number;
    claim_reference?: string | null;
    status: string;
    gl_posting_status: string;
    total_amount: number;
    period_start?: string | null;
    period_end?: string | null;
    submitted_at?: string | null;
    approved_at?: string | null;
    client?: { id: number; first_name: string; last_name: string } | null;
    service_agreement?: {
        id: number;
        title: string;
        reference_number?: string | null;
    } | null;
    submitter?: { id: number; name: string } | null;
    approver?: { id: number; name: string } | null;
    items: ClaimItem[];
};

type Props = {
    claim: Claim;
    can_retry_posting: boolean;
};

const STATUS_BADGE: Record<string, { label: string; variant: StatusVariant }> =
    {
        draft: { label: 'Draft', variant: 'neutral' },
        submitted: { label: 'Submitted', variant: 'info' },
        approved: { label: 'Approved', variant: 'info' },
        rejected: { label: 'Rejected', variant: 'critical' },
        paid: { label: 'Paid', variant: 'success' },
    };

function formatDate(value?: string | null): string {
    if (!value) return '-';

    return new Date(value).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

export default function FundingClaimShow({ claim, can_retry_posting }: Props) {
    const nzd = new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
    });

    const [search, setSearch] = useState('');

    const title = claim.claim_reference || `Funding claim #${claim.id}`;
    const badge = STATUS_BADGE[claim.status] ?? {
        label: claim.status,
        variant: 'neutral' as StatusVariant,
    };

    const sublineParts = [
        claim.client
            ? `${claim.client.first_name} ${claim.client.last_name}`
            : null,
        claim.service_agreement?.title ?? null,
        `${formatDate(claim.period_start)} – ${formatDate(claim.period_end)}`,
        `${claim.items.length} ${claim.items.length === 1 ? 'item' : 'items'} · ${nzd.format(claim.total_amount ?? 0)}`,
    ].filter((part): part is string => !!part);

    const shownItems = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return claim.items;
        return claim.items.filter((item) =>
            `${item.description} ${item.funding_contract_reference ?? ''}`
                .toLowerCase()
                .includes(q),
        );
    }, [claim.items, search]);

    const header = (
        <PageHeader
            variant="profile"
            backHref="/operations/funding/claims"
            icon={FileCheck}
            title={title}
            titleChip={
                <PageHeaderStatusChip variant={badge.variant}>
                    {badge.label}
                </PageHeaderStatusChip>
            }
            subline={sublineParts.join(' · ')}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search claim items…"
                    />
                    {can_retry_posting &&
                        claim.status === 'submitted' &&
                        claim.gl_posting_status === 'failed' && (
                            <PageHeaderGlassButton
                                icon={RefreshCw}
                                onClick={() =>
                                    router.post(
                                        `/operations/funding/claims/${claim.id}/retry-posting`,
                                    )
                                }
                            >
                                Retry GL posting
                            </PageHeaderGlassButton>
                        )}
                    {claim.status === 'draft' && (
                        <PageHeaderPrimaryButton
                            icon={Send}
                            onClick={() =>
                                router.post(
                                    `/operations/funding/claims/${claim.id}/submit`,
                                )
                            }
                        >
                            Submit claim
                        </PageHeaderPrimaryButton>
                    )}
                    {claim.status === 'submitted' &&
                        claim.gl_posting_status === 'posted' && (
                            <PageHeaderPrimaryButton
                                icon={CheckCircle2}
                                onClick={() =>
                                    router.post(
                                        `/operations/funding/claims/${claim.id}/approve`,
                                    )
                                }
                            >
                                Approve claim
                            </PageHeaderPrimaryButton>
                        )}
                </>
            }
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Operations', href: '/operations' },
                { title: 'Funding', href: '/operations/funding' },
                { title: 'Claims', href: '/operations/funding/claims' },
                {
                    title,
                    href: `/operations/funding/claims/${claim.id}`,
                },
            ]}
        >
            <Head title={title} />

            <PageLayout hero={header}>
                <div className="grid gap-4 md:grid-cols-3">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">
                                Client
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm font-semibold">
                                {claim.client
                                    ? `${claim.client.first_name} ${claim.client.last_name}`
                                    : '-'}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">
                                Agreement
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm font-semibold">
                                {claim.service_agreement?.title ?? '-'}
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">
                                Claim Status
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <StatusBadge variant={badge.variant}>
                                {badge.label}
                            </StatusBadge>
                            <p className="mt-2 text-sm font-semibold text-status-success">
                                {nzd.format(claim.total_amount ?? 0)}
                            </p>
                            <p className="mt-2 text-xs text-muted-foreground">
                                General Ledger:{' '}
                                {claim.gl_posting_status.replaceAll('_', ' ')}
                            </p>
                            {claim.gl_posting_status === 'failed' && (
                                <p className="mt-1 text-xs text-status-critical">
                                    Posting failed. Retry when the finance
                                    service is available.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <Card className="mt-4">
                    <CardContent className="grid gap-3 p-4 md:grid-cols-4">
                        <div>
                            <p className="text-xs tracking-wide text-muted-foreground uppercase">
                                Claim Window
                            </p>
                            <p className="mt-1 text-sm font-medium">
                                {formatDate(claim.period_start)} -{' '}
                                {formatDate(claim.period_end)}
                            </p>
                        </div>
                        <div>
                            <p className="text-xs tracking-wide text-muted-foreground uppercase">
                                Submitted
                            </p>
                            <p className="mt-1 text-sm font-medium">
                                {formatDate(claim.submitted_at)}
                            </p>
                        </div>
                        <div>
                            <p className="text-xs tracking-wide text-muted-foreground uppercase">
                                Approved
                            </p>
                            <p className="mt-1 text-sm font-medium">
                                {formatDate(claim.approved_at)}
                            </p>
                        </div>
                        <div>
                            <p className="text-xs tracking-wide text-muted-foreground uppercase">
                                Raised By
                            </p>
                            <p className="mt-1 text-sm font-medium">
                                {claim.submitter?.name ?? '-'}
                            </p>
                        </div>
                    </CardContent>
                </Card>

                <Card className="mt-4">
                    <CardHeader className="flex flex-row items-baseline justify-between">
                        <CardTitle className="text-base">Claim Items</CardTitle>
                        {search.trim() !== '' && (
                            <span className="text-xs text-muted-foreground">
                                {shownItems.length} of {claim.items.length}{' '}
                                match
                            </span>
                        )}
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {shownItems.length === 0 ? (
                            <div className="rounded-md border border-dashed p-4 text-center text-xs text-muted-foreground">
                                {search.trim() !== ''
                                    ? 'No items match your search.'
                                    : 'No items on this claim.'}
                            </div>
                        ) : (
                            shownItems.map((item) => (
                                <div
                                    key={item.id}
                                    className="grid gap-3 rounded-lg border p-4 md:grid-cols-[1.5fr,0.8fr,0.8fr,1fr]"
                                >
                                    <div>
                                        <p className="text-sm font-semibold">
                                            {item.description}
                                        </p>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            <span className="inline-flex items-center gap-1">
                                                <CalendarDays className="h-3 w-3" />
                                                {formatDate(item.service_date)}
                                            </span>
                                            {item.funding_contract_reference && (
                                                <span>
                                                    {' '}
                                                    •{' '}
                                                    {
                                                        item.funding_contract_reference
                                                    }
                                                </span>
                                            )}
                                        </p>
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        Qty {item.quantity}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {nzd.format(item.unit_price)}
                                    </p>
                                    <p className="text-sm font-semibold text-status-success">
                                        {nzd.format(item.total_amount)}
                                    </p>
                                </div>
                            ))
                        )}
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
