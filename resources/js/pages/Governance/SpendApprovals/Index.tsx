import { GovernanceSectionRail } from '@/components/governance/GovernanceSectionRail';
import { useDialogDeepLink } from '@/components/governance/governance-dialog-deep-link';
import {
    EmptyValue,
    EntityChip,
    EntityContextMenu,
    EntityStatusChip,
    EntityTable,
    ListCaption,
    PersonCell,
    compactMenu,
    useEntityContextMenu,
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
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import AppLayout from '@/layouts/app-layout';
import { formatDateLong } from '@/lib/datetime';
import { formatNzd, governanceStatus, refSuffix } from '@/lib/governance-labels';
import { PageProps } from '@/types';
import { Head, router } from '@inertiajs/react';
import { ExternalLink, HandCoins, Info, Plus, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    SpendApprovalWizardDialog,
    whoApprovesText,
    type SpendApprovalFormOptions,
} from './_dialogs';

type Person = { id: number; name: string };

interface Approval {
    id: number;
    reference: string;
    title: string;
    category: string;
    amount: number;
    currency: string;
    status: string;
    requires_board: boolean;
    requested_by?: Person | number | null;
    requestedBy?: Person | null;
    decided_by?: Person | number | null;
    decidedBy?: Person | null;
    submitted_at: string | null;
    decided_at: string | null;
    created_at: string;
}

interface Props extends PageProps {
    approvals: {
        data: Approval[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: {
        status: string | null;
        category: string | null;
        search: string | null;
    };
    summary: {
        pending: number;
        waiting: number;
        drafts: number;
        approved_this_year: number;
        rejected_this_year: number;
        financial_year: string;
    };
    categories: Record<string, string>;
    thresholds: Record<string, number>;
    can_create?: boolean;
    form_options?: { sites: SpendApprovalFormOptions['sites'] } | null;
}

const ALL = '__all';

const STATUSES = [
    { value: ALL, label: 'Any status' },
    { value: 'submitted', label: 'Waiting for decision' },
    { value: 'draft', label: 'Drafts' },
    { value: 'approved', label: 'Approved' },
    { value: 'rejected', label: 'Not approved' },
    { value: 'expired', label: 'Expired' },
];

const personName = (
    camel: Person | null | undefined,
    snake: Person | number | null | undefined,
) => camel?.name ?? (snake && typeof snake === 'object' ? snake.name : null);

export default function SpendApprovalsIndex({
    approvals,
    filters,
    summary,
    categories,
    thresholds,
    can_create = false,
    form_options = null,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [createOpen, setCreateOpen] = useDialogDeepLink(
        'create',
        can_create && Boolean(form_options),
    );
    const ctxMenu = useEntityContextMenu<Approval>();

    useEffect(() => {
        setSearch(filters.search ?? '');
    }, [filters.search]);

    const go = (next: Partial<Props['filters']>) => {
        const merged = { ...filters, ...next };
        const query = Object.fromEntries(
            Object.entries(merged).filter(([, value]) => value),
        );
        router.get('/governance/spend-approvals', query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    useEffect(() => {
        const handle = setTimeout(() => {
            if ((filters.search ?? '') !== search) {
                go({ search: search.trim() || null });
            }
        }, 350);
        return () => clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const hasFilters = Boolean(
        filters.status || filters.category || filters.search,
    );
    const open = (approval: Approval) =>
        router.visit(`/governance/spend-approvals/${approval.id}`);

    const actionsFor = (approval: Approval): MenuItem[] =>
        compactMenu([
            {
                label: 'Open request',
                icon: ExternalLink,
                onClick: () => open(approval),
            },
        ]);

    const header = (
        <PageHeader
            icon={HandCoins}
            title="Spend approvals"
            subline="Permission for a single purchase or contract over the limit"
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search title or reference…"
                    />
                    <Popover>
                        <PopoverTrigger asChild>
                            <PageHeaderGlassButton icon={Info}>
                                Who approves what
                            </PageHeaderGlassButton>
                        </PopoverTrigger>
                        <PopoverContent align="end" className="w-96">
                            <p className="text-section-title">
                                Who approves what
                            </p>
                            <p className="text-subtle mt-1">
                                The limit depends on the kind of spend.
                            </p>
                            <ul className="mt-3 flex flex-col gap-2.5">
                                {Object.entries(thresholds).map(
                                    ([key, value]) => (
                                        <li key={key}>
                                            <p className="text-sm font-medium">
                                                {categories[key] ?? key}
                                            </p>
                                            <p className="text-caption">
                                                {whoApprovesText(value)}
                                            </p>
                                        </li>
                                    ),
                                )}
                            </ul>
                        </PopoverContent>
                    </Popover>
                    {can_create && form_options ? (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() => setCreateOpen(true)}
                        >
                            New request
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Waiting for decision"
                        href="/governance/spend-approvals?status=submitted"
                        tone={summary.waiting > 0 ? 'warning' : 'brand'}
                    >
                        <PageHeaderMeterBig>{summary.waiting}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Sent for a decision
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Drafts"
                        href="/governance/spend-approvals?status=draft"
                    >
                        <PageHeaderMeterBig>{summary.drafts}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Not sent for a decision yet
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Approved this year"
                        href="/governance/spend-approvals?status=approved"
                        tone={summary.approved_this_year > 0 ? 'success' : 'brand'}
                    >
                        <PageHeaderMeterBig>
                            {formatNzd(summary.approved_this_year)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Financial year {summary.financial_year}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Not approved this year"
                        href="/governance/spend-approvals?status=rejected"
                        tone={summary.rejected_this_year > 0 ? 'critical' : 'brand'}
                    >
                        <PageHeaderMeterBig>
                            {formatNzd(summary.rejected_this_year)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Financial year {summary.financial_year}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Status"
                        value={filters.status ?? ALL}
                        allValue={ALL}
                        options={
                            filters.status === 'pending'
                                ? [
                                      ...STATUSES,
                                      {
                                          value: 'pending',
                                          label: 'Drafts and waiting',
                                      },
                                  ]
                                : STATUSES
                        }
                        onChange={(value) =>
                            go({ status: value === ALL ? null : value })
                        }
                    />
                    <PageHeaderFilterSelect
                        label="Kind of spend"
                        value={filters.category ?? ALL}
                        allValue={ALL}
                        options={[
                            { value: ALL, label: 'Any kind' },
                            ...Object.entries(categories).map(
                                ([value, label]) => ({ value, label }),
                            ),
                        ]}
                        onChange={(value) =>
                            go({ category: value === ALL ? null : value })
                        }
                    />
                </>
            }
            rail={<GovernanceSectionRail />}
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                {
                    title: 'Spend approvals',
                    href: '/governance/spend-approvals',
                },
            ]}
        >
            <Head title="Spend approvals" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Spend requests"
                        caption={`${approvals.data.length} of ${approvals.total} shown`}
                    />

                    {approvals.data.length === 0 ? (
                        <EmptyState
                            icon={HandCoins}
                            title={
                                hasFilters
                                    ? 'No spend requests match your filters'
                                    : 'No spend requests yet'
                            }
                            description={
                                hasFilters
                                    ? 'Try clearing a filter or search term.'
                                    : 'Ask for permission here before a purchase or contract that is over the limit.'
                            }
                            action={
                                hasFilters ? (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setSearch('');
                                            go({
                                                status: null,
                                                category: null,
                                                search: null,
                                            });
                                        }}
                                    >
                                        <X className="h-3.5 w-3.5" />
                                        Clear filters
                                    </Button>
                                ) : can_create && form_options ? (
                                    <Button
                                        size="sm"
                                        onClick={() => setCreateOpen(true)}
                                    >
                                        <Plus className="h-3.5 w-3.5" />
                                        New request
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <EntityTable
                            rows={approvals.data}
                            rowKey={(approval) => approval.id}
                            identityLabel="Request"
                            identity={(approval) => ({
                                icon: HandCoins,
                                name: approval.title,
                                subline: `${categories[approval.category] ?? approval.category} · ${refSuffix(approval.reference)}`,
                            })}
                            hrefFor={(approval) =>
                                `/governance/spend-approvals/${approval.id}`
                            }
                            onOpen={open}
                            onRowContextMenu={ctxMenu.open}
                            actionsFor={actionsFor}
                            minWidth={1000}
                            columns={[
                                {
                                    key: 'status',
                                    label: 'Status',
                                    width: '1fr',
                                    cell: (approval) => {
                                        const chip = governanceStatus(
                                            'spend_status',
                                            approval.status,
                                        );
                                        return (
                                            <EntityStatusChip
                                                variant={chip.variant}
                                            >
                                                {chip.label}
                                            </EntityStatusChip>
                                        );
                                    },
                                },
                                {
                                    key: 'amount',
                                    label: 'Amount',
                                    width: '0.8fr',
                                    align: 'right',
                                    cell: (approval) => (
                                        <span className="font-semibold tabular-nums">
                                            {formatNzd(approval.amount)}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'approver',
                                    label: 'Who approves',
                                    width: '1fr',
                                    cell: (approval) => (
                                        <EntityChip>
                                            {approval.requires_board
                                                ? 'Board resolution'
                                                : 'Finance approver'}
                                        </EntityChip>
                                    ),
                                },
                                {
                                    key: 'requested_by',
                                    label: 'Requested by',
                                    width: '1fr',
                                    cell: (approval) => (
                                        <PersonCell
                                            name={personName(
                                                approval.requestedBy,
                                                approval.requested_by,
                                            )}
                                        />
                                    ),
                                },
                                {
                                    key: 'created',
                                    label: 'Created',
                                    width: '0.8fr',
                                    cell: (approval) =>
                                        formatDateLong(approval.created_at),
                                },
                                {
                                    key: 'decided',
                                    label: 'Decided',
                                    width: '1fr',
                                    cell: (approval) =>
                                        approval.decided_at ? (
                                            <span className="truncate">
                                                {formatDateLong(
                                                    approval.decided_at,
                                                )}
                                                {' · '}
                                                {personName(
                                                    approval.decidedBy,
                                                    approval.decided_by,
                                                ) ?? 'a former user'}
                                            </span>
                                        ) : (
                                            <EmptyValue />
                                        ),
                                },
                            ]}
                        />
                    )}

                    <LaravelPagination
                        links={approvals.links}
                        lastPage={approvals.last_page}
                        preserveScroll
                    />
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={HandCoins}
                    title={ctxMenu.ctx.record.title}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}

            {can_create && form_options ? (
                <SpendApprovalWizardDialog
                    isOpen={createOpen}
                    onClose={() => setCreateOpen(false)}
                    options={{
                        categories,
                        thresholds,
                        sites: form_options.sites,
                    }}
                />
            ) : null}
        </AppLayout>
    );
}
