import { ListCaption } from '@/components/lists';
import { OPS_COLORS } from '@/components/ops-stat-card';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBar,
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
import { Badge } from '@/components/ui/badge';
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
    Eye,
    FileEdit,
    FileSignature,
    FileText,
    Layers,
    Pencil,
    Plus,
    Users,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

type Agreement = {
    id: number;
    title: string;
    reference_number: string | null;
    status: string;
    agreement_type: string;
    funding_body: string | null;
    funding_type: string | null;
    starts_at: string | null;
    ends_at: string | null;
    total_budget: number;
    budget_used: number;
    budget_remaining: number;
    budget_utilisation_percent: number;
    client: { id: number; first_name: string; last_name: string } | null;
    line_items_count: number;
};

const FUNDING_TYPE_LABELS: Record<string, string> = {
    if: 'IF',
    eif: 'EIF',
    flexible_disability: 'Flexible',
    residential: 'Residential',
    community_participation: 'Community',
    respite: 'Respite',
    day_services: 'Day Services',
    vocational: 'Vocational',
    other: 'Other',
};

type ClientOption = { id: number; first_name: string; last_name: string };

type Props = {
    agreements: {
        data: Agreement[];
        links: { url: string | null; label: string; active: boolean }[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: {
        q?: string | null;
        status?: string | null;
        agreement_type?: string | null;
        client_id?: string | null;
        funding_type?: string | null;
    };
    stats: {
        total: number;
        active: number;
        pending_approval: number;
        expiring_soon: number;
        total_budget: number;
        total_used: number;
        draft_count: number;
    };
    clients: ClientOption[];
};

type ViewKey = 'all' | 'active' | 'pending_approval' | 'draft';

const STATUS_BADGE: Record<string, { label: string; variant: StatusVariant }> =
    {
        active: { label: 'Active', variant: 'success' },
        draft: { label: 'Draft', variant: 'neutral' },
        pending_approval: { label: 'Pending approval', variant: 'warning' },
        under_review: { label: 'Under review', variant: 'info' },
        renewed: { label: 'Renewed', variant: 'success' },
        expired: { label: 'Expired', variant: 'neutral' },
        terminated: { label: 'Terminated', variant: 'critical' },
        suspended: { label: 'Suspended', variant: 'critical' },
    };

const TYPE_LABELS: Record<string, string> = {
    whaikaha: 'Whaikaha',
    carer_support: 'Carer Support',
    nasc: 'NASC',
    egl_if: 'EGL / IF',
    te_whatu_ora: 'Te Whatu Ora',
    msd: 'MSD',
    acc: 'ACC',
    oranga_tamariki: 'Oranga Tamariki',
    private: 'Private',
    charitable: 'Charitable',
    other: 'Other',
};

const FUNDING_TYPE_OPTIONS = [
    'IF',
    'EIF',
    'Flexible',
    'Residential',
    'Community Participation',
    'Respite',
    'Day Services',
    'Vocational',
];

function formatDate(d: string | null): string {
    if (!d) return '-';
    return new Date(d).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function formatCurrency(n: number): string {
    return new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(n);
}

export default function ServiceAgreementsIndex({
    agreements = {
        data: [],
        links: [],
        current_page: 1,
        last_page: 1,
        total: 0,
    },
    filters = {},
    stats = {} as any,
    clients = [],
}: Props) {
    const { labels } = usePage().props as any;
    const clientPlural: string = labels?.['client.plural'] ?? 'Clients';

    const s = {
        total: stats?.total ?? 0,
        active: stats?.active ?? 0,
        pending_approval: stats?.pending_approval ?? 0,
        expiring_soon: stats?.expiring_soon ?? 0,
        total_budget: stats?.total_budget ?? 0,
        total_used: stats?.total_used ?? 0,
        draft_count: stats?.draft_count ?? 0,
    };
    const remaining = s.total_budget - s.total_used;
    const utilisationPct =
        s.total_budget > 0
            ? Math.round((s.total_used / s.total_budget) * 100)
            : 0;

    const view: ViewKey = (
        ['active', 'pending_approval', 'draft'] as const
    ).includes(filters.status as any)
        ? (filters.status as ViewKey)
        : 'all';

    const [q, setQ] = useState(filters.q ?? '');

    const applyFilters = useCallback(
        (
            overrides: Partial<{
                status: string;
                q: string;
                agreement_type: string;
                client_id: string;
                funding_type: string;
            }>,
        ) => {
            const next = {
                status: overrides.status ?? filters.status ?? 'all',
                q: overrides.q ?? filters.q ?? '',
                agreement_type:
                    overrides.agreement_type ?? filters.agreement_type ?? 'all',
                client_id:
                    overrides.client_id ?? String(filters.client_id ?? 'all'),
                funding_type:
                    overrides.funding_type ?? filters.funding_type ?? 'all',
            };
            const params: Record<string, string> = {};
            if (next.status !== 'all') params.status = next.status;
            if (next.q.trim() !== '') params.q = next.q.trim();
            if (next.agreement_type !== 'all')
                params.agreement_type = next.agreement_type;
            if (next.client_id !== 'all') params.client_id = next.client_id;
            if (next.funding_type !== 'all')
                params.funding_type = next.funding_type;
            router.get('/operations/service-agreements', params, {
                preserveState: true,
                replace: true,
            });
        },
        [filters],
    );

    useEffect(() => {
        if ((filters.q ?? '') === q.trim()) return;
        const t = setTimeout(() => applyFilters({ q }), 400);
        return () => clearTimeout(t);
    }, [q, filters.q, applyFilters]);

    const railItems: PageHeaderRailItem<ViewKey>[] = [
        { key: 'all', label: 'All agreements', icon: Layers, count: s.total },
        {
            key: 'active',
            label: 'Active',
            icon: CheckCircle2,
            count: s.active,
        },
        {
            key: 'pending_approval',
            label: 'Pending approval',
            icon: Clock,
            count: s.pending_approval,
        },
        {
            key: 'draft',
            label: 'Drafts',
            icon: FileEdit,
            count: s.draft_count,
        },
    ];

    const currentViewLabel =
        (filters.status ?? 'all') !== 'all' &&
        !railItems.some((item) => item.key === filters.status)
            ? (STATUS_BADGE[filters.status as string]?.label ??
              String(filters.status))
            : (railItems.find((v) => v.key === view)?.label ??
              'All agreements');

    const hasNarrowing =
        (filters.q ?? '') !== '' ||
        (filters.agreement_type ?? 'all') !== 'all' ||
        String(filters.client_id ?? 'all') !== 'all' ||
        (filters.funding_type ?? 'all') !== 'all';

    const titleChip =
        s.pending_approval > 0 ? (
            <PageHeaderStatusChip variant="warning">
                {s.pending_approval} pending approval
            </PageHeaderStatusChip>
        ) : s.expiring_soon > 0 ? (
            <PageHeaderStatusChip variant="warning">
                {s.expiring_soon} expiring soon
            </PageHeaderStatusChip>
        ) : (
            <PageHeaderStatusChip
                variant={s.active > 0 ? 'success' : 'neutral'}
            >
                {s.active} active
            </PageHeaderStatusChip>
        );

    const statusOptions = [
        { value: 'all', label: 'All statuses' },
        ...Object.entries(STATUS_BADGE).map(([value, meta]) => ({
            value,
            label: meta.label,
        })),
    ];
    const typeOptions = [
        { value: 'all', label: 'All types' },
        ...Object.entries(TYPE_LABELS).map(([value, label]) => ({
            value,
            label,
        })),
    ];
    const clientOptions = [
        { value: 'all', label: `All ${clientPlural.toLowerCase()}` },
        ...clients.map((c) => ({
            value: String(c.id),
            label: `${c.first_name} ${c.last_name}`,
        })),
    ];
    const fundingOptions = [
        { value: 'all', label: 'All funding' },
        ...FUNDING_TYPE_OPTIONS.map((ft) => ({ value: ft, label: ft })),
    ];

    const header = (
        <PageHeader
            icon={FileSignature}
            title="Service agreements"
            titleChip={titleChip}
            subline={`Funding agreements and budgets · ${s.total} ${
                s.total === 1 ? 'agreement' : 'agreements'
            } · ${formatCurrency(s.total_budget)} contracted`}
            actions={
                <>
                    <PageHeaderSearch
                        value={q}
                        onChange={setQ}
                        placeholder="Search agreements…"
                    />
                    <PageHeaderPrimaryButton
                        icon={Plus}
                        onClick={() =>
                            router.visit(
                                '/operations/service-agreements/create',
                            )
                        }
                    >
                        New agreement
                    </PageHeaderPrimaryButton>
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Budget utilised"
                        tone={remaining < 0 ? 'warning' : 'brand'}
                        ariaLabel="View all agreements"
                        onClick={() => applyFilters({ status: 'all' })}
                    >
                        <PageHeaderMeterBig>
                            {formatCurrency(s.total_used)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterBar percent={utilisationPct} />
                        <PageHeaderMeterCaption>
                            {remaining >= 0
                                ? `${formatCurrency(remaining)} left of ${formatCurrency(s.total_budget)}`
                                : `${formatCurrency(-remaining)} over ${formatCurrency(s.total_budget)}`}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    {s.total > 0 ? (
                        <PageHeaderMeterBlock
                            label="Active"
                            ariaLabel="View active agreements"
                            onClick={() => applyFilters({ status: 'active' })}
                        >
                            <PageHeaderMeterDonut
                                percent={(s.active / s.total) * 100}
                                caption={
                                    <>
                                        {s.active} of {s.total}
                                        <br />
                                        in force
                                    </>
                                }
                            />
                        </PageHeaderMeterBlock>
                    ) : null}
                    <PageHeaderMeterBlock
                        label="Pending approval"
                        tone={s.pending_approval > 0 ? 'warning' : 'success'}
                        ariaLabel="View agreements pending approval"
                        onClick={() =>
                            applyFilters({ status: 'pending_approval' })
                        }
                    >
                        <PageHeaderMeterBig>
                            {s.pending_approval}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            waiting for sign-off
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Expiring soon"
                        tone={s.expiring_soon > 0 ? 'warning' : 'success'}
                        ariaLabel="View active agreements (expiring agreements are active)"
                        onClick={() => applyFilters({ status: 'active' })}
                    >
                        <PageHeaderMeterBig>
                            {s.expiring_soon}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            within the next 30 days
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Drafts"
                        ariaLabel="View draft agreements"
                        onClick={() => applyFilters({ status: 'draft' })}
                    >
                        <PageHeaderMeterBig>{s.draft_count}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            not yet submitted
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        icon={FileText}
                        label="All statuses"
                        value={filters.status ?? 'all'}
                        options={statusOptions}
                        onChange={(v) => applyFilters({ status: v })}
                    />
                    <PageHeaderFilterSelect
                        icon={FileSignature}
                        label="All types"
                        value={filters.agreement_type ?? 'all'}
                        options={typeOptions}
                        onChange={(v) => applyFilters({ agreement_type: v })}
                    />
                    <PageHeaderFilterSelect
                        icon={Users}
                        label={`All ${clientPlural.toLowerCase()}`}
                        value={String(filters.client_id ?? 'all')}
                        options={clientOptions}
                        onChange={(v) => applyFilters({ client_id: v })}
                    />
                    <PageHeaderFilterSelect
                        icon={Banknote}
                        label="All funding"
                        value={filters.funding_type ?? 'all'}
                        options={fundingOptions}
                        onChange={(v) => applyFilters({ funding_type: v })}
                    />
                </>
            }
            rail={
                <PageHeaderRail
                    items={railItems}
                    value={view}
                    onSelect={(key) => applyFilters({ status: key })}
                    ariaLabel="Agreement views"
                />
            }
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Operations', href: '/operations' },
                {
                    title: 'Service agreements',
                    href: '/operations/service-agreements',
                },
            ]}
        >
            <Head title="Service agreements" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title={currentViewLabel}
                        caption={`${agreements.data.length} of ${
                            agreements.total ?? agreements.data.length
                        } shown`}
                    />

                    <div className="space-y-2">
                        {agreements.data.length === 0 ? (
                            <EmptyState
                                icon={FileText}
                                title="No service agreements"
                                description={
                                    hasNarrowing || view !== 'all'
                                        ? 'Nothing matches this view or your filters.'
                                        : 'Create service agreements to track funding and budgets.'
                                }
                                action={
                                    hasNarrowing ||
                                    view !== 'all' ? undefined : (
                                        <Button
                                            size="sm"
                                            onClick={() =>
                                                router.visit(
                                                    '/operations/service-agreements/create',
                                                )
                                            }
                                        >
                                            <Plus className="h-3.5 w-3.5" />
                                            New agreement
                                        </Button>
                                    )
                                }
                            />
                        ) : (
                            agreements.data.map((ag) => {
                                const badge = STATUS_BADGE[ag.status] ?? {
                                    label: (ag.status ?? '').replace(/_/g, ' '),
                                    variant: 'neutral' as StatusVariant,
                                };
                                return (
                                    <Card
                                        key={ag.id}
                                        className="transition-all hover:border-border hover:shadow-sm"
                                    >
                                        <CardContent className="flex items-center gap-4 p-4">
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <Link
                                                        href={`/operations/service-agreements/${ag.id}`}
                                                        className="text-sm font-semibold hover:underline"
                                                    >
                                                        {ag.title}
                                                    </Link>
                                                    <StatusBadge
                                                        variant={badge.variant}
                                                    >
                                                        {badge.label}
                                                    </StatusBadge>
                                                    <Badge
                                                        variant="outline"
                                                        className="h-4 px-1.5 text-[9px]"
                                                    >
                                                        {TYPE_LABELS[
                                                            ag.agreement_type
                                                        ] ?? ag.agreement_type}
                                                    </Badge>
                                                    {ag.funding_type && (
                                                        <Badge
                                                            variant="outline"
                                                            className="h-4 border-primary bg-primary/10 px-1.5 text-[9px] text-primary"
                                                        >
                                                            {FUNDING_TYPE_LABELS[
                                                                ag.funding_type
                                                            ] ??
                                                                ag.funding_type}
                                                        </Badge>
                                                    )}
                                                    {ag.reference_number && (
                                                        <span className="text-[10px] text-muted-foreground">
                                                            #
                                                            {
                                                                ag.reference_number
                                                            }
                                                        </span>
                                                    )}
                                                </div>
                                                <div className="mt-1 flex items-center gap-3 text-xs text-muted-foreground">
                                                    {ag.client && (
                                                        <span>
                                                            {
                                                                ag.client
                                                                    .first_name
                                                            }{' '}
                                                            {
                                                                ag.client
                                                                    .last_name
                                                            }
                                                        </span>
                                                    )}
                                                    {ag.funding_body && (
                                                        <span>
                                                            {ag.funding_body}
                                                        </span>
                                                    )}
                                                    {ag.starts_at && (
                                                        <span className="flex items-center gap-1">
                                                            <CalendarDays className="h-3 w-3" />
                                                            {formatDate(
                                                                ag.starts_at,
                                                            )}{' '}
                                                            -{' '}
                                                            {formatDate(
                                                                ag.ends_at,
                                                            )}
                                                        </span>
                                                    )}
                                                    <span>
                                                        {ag.line_items_count ??
                                                            0}{' '}
                                                        line items
                                                    </span>
                                                </div>
                                                {/* Budget bar */}
                                                <div className="mt-2 flex items-center gap-3">
                                                    <div className="h-1.5 flex-1 rounded-full bg-muted">
                                                        <div
                                                            className="h-1.5 rounded-full transition-all"
                                                            style={{
                                                                width: `${Math.min(100, ag.budget_utilisation_percent ?? 0)}%`,
                                                                backgroundColor:
                                                                    (ag.budget_utilisation_percent ??
                                                                        0) > 90
                                                                        ? OPS_COLORS.danger
                                                                        : (ag.budget_utilisation_percent ??
                                                                                0) >
                                                                            70
                                                                          ? OPS_COLORS.warning
                                                                          : OPS_COLORS.success,
                                                            }}
                                                        />
                                                    </div>
                                                    <span className="text-[10px] font-medium tabular-nums">
                                                        {formatCurrency(
                                                            ag.budget_used ?? 0,
                                                        )}{' '}
                                                        /{' '}
                                                        {formatCurrency(
                                                            ag.total_budget ??
                                                                0,
                                                        )}{' '}
                                                        (
                                                        {ag.budget_utilisation_percent ??
                                                            0}
                                                        %)
                                                    </span>
                                                </div>
                                            </div>
                                            <div className="flex shrink-0 gap-1">
                                                <Button
                                                    asChild
                                                    size="sm"
                                                    variant="ghost"
                                                    className="h-7 w-7 p-0"
                                                >
                                                    <Link
                                                        href={`/operations/service-agreements/${ag.id}`}
                                                        aria-label={`View ${ag.title}`}
                                                    >
                                                        <Eye className="h-3.5 w-3.5" />
                                                    </Link>
                                                </Button>
                                                <Button
                                                    asChild
                                                    size="sm"
                                                    variant="ghost"
                                                    className="h-7 w-7 p-0"
                                                >
                                                    <Link
                                                        href={`/operations/service-agreements/${ag.id}/edit`}
                                                        aria-label={`Edit ${ag.title}`}
                                                    >
                                                        <Pencil className="h-3.5 w-3.5" />
                                                    </Link>
                                                </Button>
                                            </div>
                                        </CardContent>
                                    </Card>
                                );
                            })
                        )}
                    </div>

                    {(agreements.last_page ?? 1) > 1 && (
                        <div className="flex items-center justify-center gap-1">
                            {(agreements.links ?? []).map((link, i) => (
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
