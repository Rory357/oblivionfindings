import { GovernanceSectionRail } from '@/components/governance/GovernanceSectionRail';
import { useDialogDeepLink } from '@/components/governance/governance-dialog-deep-link';
import {
    EntityContextMenu,
    EntityStatusChip,
    EntityTable,
    CounterPill,
    ListCaption,
    PersonCell,
    compactMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterCheck,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import AppLayout from '@/layouts/app-layout';
import { riskScoreLevel } from '@/lib/governance-status';
import { PageProps } from '@/types';
import { Head, router } from '@inertiajs/react';
import { ExternalLink, Plus, ShieldAlert, X } from 'lucide-react';
import { useEffect, useState } from 'react';

import {
    RiskWizardDialog,
    riskCategoryIcon,
    type RiskFormOptions,
} from './_dialogs';
import {
    RISK_SEVERITY_FILTERS,
    RISK_STATUS_FILTERS,
    RiskViewToggle,
    riskLevelVariant,
    riskStatusLabel,
    riskStatusVariant,
} from './_shared';

interface Risk {
    id: number;
    risk_reference: string;
    title: string;
    category: string;
    residual_score: number;
    status: string;
    within_appetite: boolean;
    risk_owner: { name: string } | null;
    treatments_count: number;
}

interface Filters {
    category?: string;
    status?: string;
    severity?: string;
    above_appetite?: string;
    search?: string;
}

interface Props extends PageProps {
    risks: {
        data: Risk[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        total?: number;
        last_page?: number;
    };
    categories: Array<{ value: string; label: string }>;
    summary: Record<
        string,
        {
            total: number;
            critical: number;
            high: number;
            above_appetite: number;
        }
    >;
    filters: Filters;
    canCreate?: boolean;
    formOptions?: RiskFormOptions | null;
}

function cleanFilters(filters: Filters): Record<string, string> {
    return Object.fromEntries(
        Object.entries(filters).filter(
            ([, value]) => value != null && value !== '',
        ),
    ) as Record<string, string>;
}

export default function RiskIndex({
    risks,
    categories,
    summary,
    filters,
    canCreate = false,
    formOptions = null,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    // Retired /risks/create deep links arrive as ?create=1.
    const [wizardOpen, setWizardOpen] = useDialogDeepLink(
        'create',
        canCreate && formOptions != null,
    );
    const ctxMenu = useEntityContextMenu<Risk>();

    useEffect(() => {
        setSearch(filters.search ?? '');
    }, [filters.search]);

    const go = (patch: Filters) => {
        router.get(
            '/governance/risks',
            cleanFilters({ ...filters, ...patch }),
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    // Debounced server-side search.
    useEffect(() => {
        const handle = window.setTimeout(() => {
            if ((filters.search ?? '') !== search.trim()) {
                go({ search: search.trim() || undefined });
            }
        }, 350);
        return () => window.clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const totals = Object.values(summary).reduce(
        (acc, cat) => ({
            total: acc.total + cat.total,
            critical: acc.critical + cat.critical,
            high: acc.high + cat.high,
            above_appetite: acc.above_appetite + cat.above_appetite,
        }),
        { total: 0, critical: 0, high: 0, above_appetite: 0 },
    );
    const abovePct =
        totals.total > 0 ? (totals.above_appetite / totals.total) * 100 : 0;

    const categoryLabel = (value: string) =>
        categories.find((c) => c.value === value)?.label ?? value;

    const hasFilters = Object.keys(cleanFilters(filters)).length > 0;
    const shown = risks.data.length;
    const total = risks.total ?? shown;

    const actionsFor = (risk: Risk): MenuItem[] =>
        compactMenu([
            {
                label: 'Open risk',
                icon: ExternalLink,
                onClick: () => router.visit(`/governance/risks/${risk.id}`),
            },
        ]);

    const header = (
        <PageHeader
            icon={ShieldAlert}
            title="Risk register"
            titleChip={
                totals.above_appetite > 0 ? (
                    <PageHeaderStatusChip variant="critical">
                        {totals.above_appetite} above appetite
                    </PageHeaderStatusChip>
                ) : (
                    <PageHeaderStatusChip variant="success">
                        Within appetite
                    </PageHeaderStatusChip>
                )
            }
            subline={`Enterprise risks, residual scores and treatments · ${totals.total} active · ${categories.length} categories`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search risks or references…"
                    />
                    {canCreate && formOptions ? (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() => setWizardOpen(true)}
                        >
                            Register risk
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Active risks"
                        ariaLabel="View all risks"
                        href="/governance/risks"
                    >
                        <PageHeaderMeterBig>{totals.total}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            across {categories.length} categories
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Critical"
                        ariaLabel="View critical risks"
                        href="/governance/risks?severity=critical"
                        tone={totals.critical > 0 ? 'critical' : 'brand'}
                    >
                        <PageHeaderMeterBig>
                            {totals.critical}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Residual score 20+
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="High"
                        ariaLabel="View high risks"
                        href="/governance/risks?severity=high"
                        tone={totals.high > 0 ? 'warning' : 'brand'}
                    >
                        <PageHeaderMeterBig>{totals.high}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Residual score 15–19
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Above appetite"
                        ariaLabel="View risks above appetite"
                        value={totals.above_appetite}
                        href="/governance/risks?above_appetite=1"
                        tone={totals.above_appetite > 0 ? 'critical' : 'brand'}
                    >
                        <PageHeaderMeterDonut
                            percent={abovePct}
                            caption={`${totals.above_appetite} of ${totals.total} active risks`}
                        />
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <RiskViewToggle value="register" />
                    <PageHeaderFilterSelect
                        label="Category"
                        value={filters.category ?? 'all'}
                        options={[
                            { value: 'all', label: 'All categories' },
                            ...categories,
                        ]}
                        onChange={(v) =>
                            go({ category: v === 'all' ? undefined : v })
                        }
                    />
                    <PageHeaderFilterSelect
                        label="Status"
                        value={filters.status ?? 'all'}
                        options={RISK_STATUS_FILTERS}
                        onChange={(v) =>
                            go({ status: v === 'all' ? undefined : v })
                        }
                    />
                    <PageHeaderFilterSelect
                        label="Severity"
                        value={filters.severity ?? 'all'}
                        options={RISK_SEVERITY_FILTERS}
                        onChange={(v) =>
                            go({ severity: v === 'all' ? undefined : v })
                        }
                    />
                    <PageHeaderFilterCheck
                        label="Above appetite"
                        checked={filters.above_appetite === '1'}
                        onChange={(checked) =>
                            go({ above_appetite: checked ? '1' : undefined })
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
                { title: 'Risk register', href: '/governance/risks' },
            ]}
        >
            <Head title="Risk register" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title={hasFilters ? 'Matching risks' : 'All risks'}
                        caption={`${shown} of ${total} shown · highest residual score first`}
                    />

                    {risks.data.length === 0 ? (
                        <EmptyState
                            icon={ShieldAlert}
                            title={
                                hasFilters
                                    ? 'No risks match your filters'
                                    : 'No risks registered yet'
                            }
                            description={
                                hasFilters
                                    ? 'Try clearing a filter or search term.'
                                    : canCreate
                                      ? 'Register the first enterprise risk to start tracking residual scores and treatments.'
                                      : 'Risks registered by the risk lead will appear here.'
                            }
                            action={
                                hasFilters ? (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            router.get(
                                                '/governance/risks',
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <X className="h-3.5 w-3.5" />
                                        Clear filters
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <EntityTable<Risk>
                            rows={risks.data}
                            rowKey={(risk) => risk.id}
                            identityLabel="Risk"
                            identity={(risk) => ({
                                icon: riskCategoryIcon(risk.category),
                                name: risk.title,
                                linkLabel: `Open ${risk.title}`,
                                subline: `${risk.risk_reference} · ${categoryLabel(risk.category)}`,
                            })}
                            hrefFor={(risk) => `/governance/risks/${risk.id}`}
                            onOpen={(risk) =>
                                router.visit(`/governance/risks/${risk.id}`)
                            }
                            columns={[
                                {
                                    key: 'residual',
                                    label: 'Residual',
                                    width: '1fr',
                                    cell: (risk) => (
                                        <EntityStatusChip
                                            variant={riskLevelVariant(
                                                risk.residual_score,
                                            )}
                                        >
                                            {risk.residual_score} ·{' '}
                                            {riskScoreLevel(risk.residual_score)}
                                        </EntityStatusChip>
                                    ),
                                },
                                {
                                    key: 'appetite',
                                    label: 'Appetite',
                                    width: '1fr',
                                    cell: (risk) =>
                                        risk.within_appetite ? (
                                            <EntityStatusChip variant="success">
                                                Within
                                            </EntityStatusChip>
                                        ) : (
                                            <EntityStatusChip variant="critical">
                                                Above
                                            </EntityStatusChip>
                                        ),
                                },
                                {
                                    key: 'status',
                                    label: 'Status',
                                    width: '1fr',
                                    cell: (risk) => (
                                        <EntityStatusChip
                                            variant={riskStatusVariant(
                                                risk.status,
                                            )}
                                        >
                                            {riskStatusLabel(risk.status)}
                                        </EntityStatusChip>
                                    ),
                                },
                                {
                                    key: 'owner',
                                    label: 'Owner',
                                    width: '1.2fr',
                                    cell: (risk) => (
                                        <PersonCell
                                            name={risk.risk_owner?.name}
                                        />
                                    ),
                                },
                                {
                                    key: 'treatments',
                                    label: 'Treatments',
                                    width: '0.8fr',
                                    align: 'center',
                                    cell: (risk) => (
                                        <CounterPill tone="neutral">
                                            {risk.treatments_count ?? 0}
                                        </CounterPill>
                                    ),
                                },
                            ]}
                            actionsFor={actionsFor}
                            onRowContextMenu={(e, risk) =>
                                ctxMenu.open(e, risk)
                            }
                        />
                    )}

                    <LaravelPagination
                        links={risks.links}
                        lastPage={risks.last_page}
                        preserveScroll
                    />
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={riskCategoryIcon(ctxMenu.ctx.record.category)}
                    title={ctxMenu.ctx.record.title}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}

            {canCreate && formOptions ? (
                <RiskWizardDialog
                    open={wizardOpen}
                    onClose={() => setWizardOpen(false)}
                    options={formOptions}
                />
            ) : null}
        </AppLayout>
    );
}
