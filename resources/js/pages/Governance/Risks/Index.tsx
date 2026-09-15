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
import { refSuffix } from '@/lib/governance-labels';
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
    RiskScoreExplainer,
    RiskViewToggle,
    riskBandLabel,
    riskLevelVariant,
    riskStatusChip,
} from './_shared';

interface Risk {
    id: number;
    risk_reference: string;
    title: string;
    category: string;
    category_label: string;
    inherent_score: number;
    residual_score: number;
    appetite_threshold: number;
    status: string;
    within_appetite: boolean;
    accepted_until: string | null;
    risk_owner: { name: string } | null;
    treatments_count: number;
}

interface Filters {
    category?: string;
    status?: string;
    severity?: string;
    score?: string;
    above_appetite?: string;
    likelihood?: string;
    impact?: string;
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
    summary: {
        current: number;
        critical: number;
        high: number;
        above_limit: number;
        accepted: number;
        closed: number;
    };
    filters: Filters;
    committees: Array<{ id: number; name: string; type: string }>;
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
    committees = [],
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

    const abovePct =
        summary.current > 0 ? (summary.above_limit / summary.current) * 100 : 0;

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
                summary.above_limit > 0 ? (
                    <PageHeaderStatusChip variant="critical">
                        {summary.above_limit} above the board&apos;s limit
                    </PageHeaderStatusChip>
                ) : summary.current === 0 ? (
                    <PageHeaderStatusChip variant="neutral">
                        No open risks
                    </PageHeaderStatusChip>
                ) : (
                    <PageHeaderStatusChip variant="success">
                        None above the board&apos;s limit
                    </PageHeaderStatusChip>
                )
            }
            subline={`Risks the board watches, how serious they are and what is being done · ${summary.current} open or accepted`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search risks…"
                    />
                    {canCreate && formOptions ? (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() => setWizardOpen(true)}
                        >
                            Add risk
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="On the register"
                        ariaLabel="View open and accepted risks"
                        href="/governance/risks"
                    >
                        <PageHeaderMeterBig>{summary.current}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            open or accepted by the board
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Critical"
                        ariaLabel="View critical risks"
                        href="/governance/risks?severity=critical"
                        tone={summary.critical > 0 ? 'critical' : 'brand'}
                    >
                        <PageHeaderMeterBig>{summary.critical}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            after controls, 20–25
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="High"
                        ariaLabel="View high risks"
                        href="/governance/risks?severity=high"
                        tone={summary.high > 0 ? 'warning' : 'brand'}
                    >
                        <PageHeaderMeterBig>{summary.high}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            after controls, 15–19
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Above the board's limit"
                        ariaLabel="View risks above the board's limit"
                        value={summary.above_limit}
                        href="/governance/risks?above_appetite=1"
                        tone={summary.above_limit > 0 ? 'critical' : 'brand'}
                    >
                        <PageHeaderMeterDonut
                            percent={abovePct}
                            caption={`${summary.above_limit} of ${summary.current} need action or board acceptance`}
                        />
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Accepted by the board"
                        ariaLabel="View risks the board has accepted"
                        href="/governance/risks?status=accepted"
                        tone={summary.accepted > 0 ? 'success' : 'brand'}
                    >
                        <PageHeaderMeterBig>{summary.accepted}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            accepted for a set time
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <RiskViewToggle value="register" />
                    <PageHeaderFilterSelect
                        label="All kinds of risk"
                        value={filters.category ?? 'all'}
                        options={[
                            { value: 'all', label: 'All kinds of risk' },
                            ...categories,
                        ]}
                        onChange={(v) =>
                            go({ category: v === 'all' ? undefined : v })
                        }
                    />
                    <PageHeaderFilterSelect
                        label="Open and accepted"
                        value={filters.status ?? 'current'}
                        allValue="current"
                        options={RISK_STATUS_FILTERS}
                        onChange={(v) =>
                            go({ status: v === 'current' ? undefined : v })
                        }
                    />
                    <PageHeaderFilterSelect
                        label="Any level"
                        value={filters.severity ?? 'all'}
                        options={RISK_SEVERITY_FILTERS}
                        onChange={(v) =>
                            go({
                                severity: v === 'all' ? undefined : v,
                                score: undefined,
                            })
                        }
                    />
                    <PageHeaderFilterCheck
                        label="Above the board's limit"
                        checked={filters.above_appetite === '1'}
                        onChange={(checked) =>
                            go({ above_appetite: checked ? '1' : undefined })
                        }
                    />
                    {committees.length > 0 ? (
                        <PageHeaderFilterSelect
                            label="Committee view"
                            value="all"
                            options={[
                                { value: 'all', label: 'Committee view' },
                                ...committees.map((committee) => ({
                                    value: String(committee.id),
                                    label: committee.name,
                                })),
                            ]}
                            onChange={(v) => {
                                if (v !== 'all') {
                                    router.visit(`/governance/risks/committee/${v}`);
                                }
                            }}
                        />
                    ) : null}
                </>
            }
            rail={<GovernanceSectionRail />}
        />
    );

    const scoreFilterNote =
        filters.likelihood && filters.impact
            ? `Likelihood ${filters.likelihood} × impact ${filters.impact}`
            : filters.severity && filters.score === 'before'
              ? 'Levels use the score before controls'
              : null;

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
                        title={hasFilters ? 'Matching risks' : 'Open and accepted risks'}
                        caption={[
                            `${shown} of ${total} shown`,
                            'highest risk after controls first',
                            scoreFilterNote,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                        right={<RiskScoreExplainer />}
                    />

                    {risks.data.length === 0 ? (
                        <EmptyState
                            icon={ShieldAlert}
                            title={
                                hasFilters
                                    ? 'No risks match your filters'
                                    : 'No open risks on the register'
                            }
                            description={
                                hasFilters
                                    ? 'Try clearing a filter or search term.'
                                    : canCreate
                                      ? 'Add the first risk the board should keep an eye on.'
                                      : 'Risks added by the risk lead appear here.'
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
                                subline: `${risk.category_label} · ${refSuffix(risk.risk_reference)}`,
                            })}
                            hrefFor={(risk) => `/governance/risks/${risk.id}`}
                            onOpen={(risk) =>
                                router.visit(`/governance/risks/${risk.id}`)
                            }
                            minWidth={900}
                            columns={[
                                {
                                    key: 'scores',
                                    label: 'Before → after controls',
                                    width: '1.2fr',
                                    cell: (risk) => (
                                        <EntityStatusChip
                                            variant={riskLevelVariant(
                                                risk.residual_score,
                                            )}
                                        >
                                            <span
                                                aria-label={`Risk before controls ${risk.inherent_score}, after controls ${risk.residual_score} (${riskBandLabel(risk.residual_score)})`}
                                            >
                                                {risk.inherent_score} →{' '}
                                                {risk.residual_score} ·{' '}
                                                {riskBandLabel(risk.residual_score)}
                                            </span>
                                        </EntityStatusChip>
                                    ),
                                },
                                {
                                    key: 'limit',
                                    label: "The board's limit",
                                    width: '0.9fr',
                                    cell: (risk) =>
                                        risk.within_appetite ? (
                                            <EntityStatusChip variant="success">
                                                Within ({risk.appetite_threshold})
                                            </EntityStatusChip>
                                        ) : (
                                            <EntityStatusChip
                                                variant={
                                                    risk.status === 'accepted'
                                                        ? 'warning'
                                                        : 'critical'
                                                }
                                            >
                                                Above ({risk.appetite_threshold})
                                            </EntityStatusChip>
                                        ),
                                },
                                {
                                    key: 'status',
                                    label: 'Status',
                                    width: '1.3fr',
                                    cell: (risk) => {
                                        const chip = riskStatusChip({
                                            ...risk,
                                            within_appetite: true,
                                        });
                                        return (
                                            <EntityStatusChip variant={chip.variant}>
                                                {chip.label}
                                            </EntityStatusChip>
                                        );
                                    },
                                },
                                {
                                    key: 'owner',
                                    label: 'Owner',
                                    width: '1.1fr',
                                    cell: (risk) => (
                                        <PersonCell
                                            name={risk.risk_owner?.name}
                                        />
                                    ),
                                },
                                {
                                    key: 'treatments',
                                    label: 'Actions',
                                    width: '0.6fr',
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
                            mutedFor={(risk) => risk.status === 'closed'}
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
