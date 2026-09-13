import { ListCaption } from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    type PageHeaderRailItem,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Banknote,
    CalendarDays,
    CheckCircle2,
    Clock,
    FileCheck,
    FileEdit,
    FileText,
    Layers,
    Plus,
    Users,
    XCircle,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

const nzd = new Intl.NumberFormat('en-NZ', {
    style: 'currency',
    currency: 'NZD',
});

type ClaimRow = {
    id: number;
    claim_reference?: string | null;
    status: string;
    total_amount: number;
    period_start?: string | null;
    period_end?: string | null;
    items_count?: number;
    client?: { id: number; first_name: string; last_name: string } | null;
    service_agreement?: {
        id: number;
        title: string;
        reference_number?: string | null;
    } | null;
    submitter?: { id: number; name: string } | null;
};

type StatusSlice = { count: number; amount: number };

type Props = {
    claims: {
        data: ClaimRow[];
        links?: Array<{ url: string | null; label: string; active: boolean }>;
        last_page?: number;
        total?: number;
    };
    clients: Array<{ id: number; first_name: string; last_name: string }>;
    filters: {
        status?: string | null;
        client_id?: string | null;
        q?: string | null;
    };
    summary?: {
        total: number;
        total_amount: number;
        by_status: Record<string, StatusSlice>;
    };
};

type ViewKey = 'all' | 'draft' | 'submitted' | 'approved' | 'rejected' | 'paid';

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

export default function FundingClaimsIndex({
    claims,
    clients,
    filters,
    summary,
}: Props) {
    const { labels } = usePage().props as any;
    const clientPlural: string = labels?.['client.plural'] ?? 'Clients';
    const rows = claims?.data ?? [];

    const byStatus = summary?.by_status ?? {};
    const total = summary?.total ?? 0;
    const countOf = (status: string) => byStatus[status]?.count ?? 0;
    const amountOf = (status: string) => byStatus[status]?.amount ?? 0;

    const view: ViewKey = (
        ['draft', 'submitted', 'approved', 'rejected', 'paid'] as const
    ).includes(filters.status as any)
        ? (filters.status as ViewKey)
        : 'all';

    const [q, setQ] = useState(filters.q ?? '');

    const applyFilters = useCallback(
        (overrides: { view?: ViewKey; q?: string; client_id?: string }) => {
            const nextView = overrides.view ?? view;
            const nextQ = overrides.q ?? filters.q ?? '';
            const nextClient =
                overrides.client_id ?? String(filters.client_id ?? 'all');
            const params: Record<string, string> = {};
            if (nextView !== 'all') params.status = nextView;
            if (nextQ.trim() !== '') params.q = nextQ.trim();
            if (nextClient !== 'all') params.client_id = nextClient;
            router.get('/operations/funding/claims', params, {
                preserveState: true,
                replace: true,
            });
        },
        [view, filters.q, filters.client_id],
    );

    useEffect(() => {
        if ((filters.q ?? '') === q.trim()) return;
        const t = setTimeout(() => applyFilters({ q }), 400);
        return () => clearTimeout(t);
    }, [q, filters.q, applyFilters]);

    const railItems: PageHeaderRailItem<ViewKey>[] = [
        { key: 'all', label: 'All claims', icon: Layers, count: total },
        {
            key: 'draft',
            label: 'Drafts',
            icon: FileEdit,
            count: countOf('draft'),
        },
        {
            key: 'submitted',
            label: 'Submitted',
            icon: Clock,
            count: countOf('submitted'),
        },
        {
            key: 'approved',
            label: 'Approved',
            icon: CheckCircle2,
            count: countOf('approved'),
        },
        {
            key: 'rejected',
            label: 'Rejected',
            icon: XCircle,
            count: countOf('rejected'),
            alert: true,
        },
        { key: 'paid', label: 'Paid', icon: Banknote, count: countOf('paid') },
    ];

    const currentViewLabel =
        railItems.find((v) => v.key === view)?.label ?? 'All claims';
    const viewTotal = view === 'all' ? total : countOf(view);

    const hasNarrowing =
        (filters.q ?? '') !== '' ||
        String(filters.client_id ?? 'all') !== 'all';

    const clientOptions = [
        { value: 'all', label: `All ${clientPlural.toLowerCase()}` },
        ...clients.map((c) => ({
            value: String(c.id),
            label: `${c.first_name} ${c.last_name}`,
        })),
    ];

    const titleChip =
        countOf('submitted') > 0 ? (
            <PageHeaderStatusChip variant="warning">
                {countOf('submitted')} awaiting approval
            </PageHeaderStatusChip>
        ) : countOf('draft') > 0 ? (
            <PageHeaderStatusChip variant="info">
                {countOf('draft')} {countOf('draft') === 1 ? 'draft' : 'drafts'}
            </PageHeaderStatusChip>
        ) : (
            <PageHeaderStatusChip variant="success">
                Up to date
            </PageHeaderStatusChip>
        );

    const header = (
        <PageHeader
            icon={FileCheck}
            title="Funding claims"
            titleChip={titleChip}
            subline={`Claims against live service agreements · ${total} ${
                total === 1 ? 'claim' : 'claims'
            } · ${nzd.format(summary?.total_amount ?? 0)} claimed`}
            actions={
                <>
                    <PageHeaderSearch
                        value={q}
                        onChange={setQ}
                        placeholder={`Search claims and ${clientPlural.toLowerCase()}…`}
                    />
                    <PageHeaderPrimaryButton
                        icon={Plus}
                        onClick={() =>
                            router.visit('/operations/funding/claims/create')
                        }
                    >
                        New claim
                    </PageHeaderPrimaryButton>
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Total claims"
                        ariaLabel="View all claims"
                        onClick={() => applyFilters({ view: 'all' })}
                    >
                        <PageHeaderMeterBig>{total}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {nzd.format(summary?.total_amount ?? 0)} claimed
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Awaiting approval"
                        tone={countOf('submitted') > 0 ? 'warning' : 'success'}
                        ariaLabel="View submitted claims"
                        onClick={() => applyFilters({ view: 'submitted' })}
                    >
                        <PageHeaderMeterBig>
                            {countOf('submitted')}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {nzd.format(amountOf('submitted'))} submitted
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    {total > 0 ? (
                        <PageHeaderMeterBlock
                            label="Paid"
                            ariaLabel="View paid claims"
                            onClick={() => applyFilters({ view: 'paid' })}
                        >
                            <PageHeaderMeterDonut
                                percent={(countOf('paid') / total) * 100}
                                caption={
                                    <>
                                        {countOf('paid')} of {total}
                                        <br />
                                        paid
                                    </>
                                }
                            />
                        </PageHeaderMeterBlock>
                    ) : null}
                    <PageHeaderMeterBlock
                        label="Rejected"
                        tone={countOf('rejected') > 0 ? 'critical' : 'success'}
                        ariaLabel="View rejected claims"
                        onClick={() => applyFilters({ view: 'rejected' })}
                    >
                        <PageHeaderMeterBig>
                            {countOf('rejected')}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            need rework before resubmission
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <PageHeaderFilterSelect
                    icon={Users}
                    label={`All ${clientPlural.toLowerCase()}`}
                    value={String(filters.client_id ?? 'all')}
                    options={clientOptions}
                    onChange={(v) => applyFilters({ client_id: v })}
                />
            }
            rail={
                <PageHeaderRail
                    items={railItems}
                    value={view}
                    onSelect={(key) => applyFilters({ view: key })}
                    ariaLabel="Claim views"
                />
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
            ]}
        >
            <Head title="Funding claims" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title={currentViewLabel}
                        caption={`${rows.length} of ${viewTotal} shown`}
                    />

                    <div className="space-y-2">
                        {rows.length === 0 ? (
                            <EmptyState
                                icon={FileText}
                                title="No funding claims"
                                description={
                                    hasNarrowing || view !== 'all'
                                        ? 'Try a different view or clear your filters.'
                                        : 'Draft claims will appear here once a funding period is assembled.'
                                }
                            />
                        ) : (
                            rows.map((claim) => {
                                const badge = STATUS_BADGE[claim.status] ?? {
                                    label: claim.status,
                                    variant: 'neutral' as StatusVariant,
                                };
                                return (
                                    <Card key={claim.id}>
                                        <CardContent className="grid gap-3 p-4 md:grid-cols-[1.4fr,1fr,0.9fr,120px] md:items-center">
                                            <div>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <Link
                                                        href={`/operations/funding/claims/${claim.id}`}
                                                        className="text-sm font-semibold hover:underline"
                                                    >
                                                        {claim.claim_reference ||
                                                            `Claim #${claim.id}`}
                                                    </Link>
                                                    <StatusBadge
                                                        variant={badge.variant}
                                                    >
                                                        {badge.label}
                                                    </StatusBadge>
                                                </div>
                                                <div className="mt-1 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                                                    {claim.client && (
                                                        <span>
                                                            {
                                                                claim.client
                                                                    .first_name
                                                            }{' '}
                                                            {
                                                                claim.client
                                                                    .last_name
                                                            }
                                                        </span>
                                                    )}
                                                    <span className="inline-flex items-center gap-1">
                                                        <CalendarDays className="h-3 w-3" />
                                                        {formatDate(
                                                            claim.period_start,
                                                        )}{' '}
                                                        -{' '}
                                                        {formatDate(
                                                            claim.period_end,
                                                        )}
                                                    </span>
                                                    {claim.service_agreement
                                                        ?.title && (
                                                        <span>
                                                            {
                                                                claim
                                                                    .service_agreement
                                                                    .title
                                                            }
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                            <p className="text-sm text-muted-foreground">
                                                {claim.items_count ?? 0} items
                                            </p>
                                            <p className="text-sm font-semibold text-status-success">
                                                {nzd.format(
                                                    claim.total_amount ?? 0,
                                                )}
                                            </p>
                                            <div className="flex justify-end">
                                                <Button
                                                    asChild
                                                    size="sm"
                                                    variant="outline"
                                                >
                                                    <Link
                                                        href={`/operations/funding/claims/${claim.id}`}
                                                    >
                                                        Open
                                                    </Link>
                                                </Button>
                                            </div>
                                        </CardContent>
                                    </Card>
                                );
                            })
                        )}
                    </div>

                    {(claims.last_page ?? 1) > 1 && (
                        <div className="flex items-center justify-center gap-1">
                            {(claims.links ?? []).map((link, i) => (
                                <Button
                                    key={i}
                                    size="sm"
                                    variant={
                                        link.active ? 'default' : 'outline'
                                    }
                                    className="h-7 min-w-[28px] px-2 text-xs"
                                    disabled={!link.url}
                                    onClick={() =>
                                        link.url &&
                                        router.get(
                                            link.url,
                                            {},
                                            { preserveState: true },
                                        )
                                    }
                                    dangerouslySetInnerHTML={{
                                        __html: link.label,
                                    }}
                                />
                            ))}
                        </div>
                    )}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
