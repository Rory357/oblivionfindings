import { GovernanceSectionRail } from '@/components/governance/GovernanceSectionRail';
import {
    EmptyValue,
    EntityContextMenu,
    EntityStatusChip,
    EntityTable,
    ListCaption,
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
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, toDateInput } from '@/lib/datetime';
import { riskScoreLevel } from '@/lib/governance-status';
import { PageProps } from '@/types';
import { Head, router } from '@inertiajs/react';
import { ExternalLink, Users, X } from 'lucide-react';
import { useMemo, useState } from 'react';

import { riskCategoryIcon, STRATEGY_OPTIONS } from './_dialogs';
import { RiskViewToggle, riskLevelVariant } from './_shared';

interface CommitteeRisk {
    id: number;
    risk_reference: string;
    title: string;
    category: string | null;
    residual_score: number | null;
    within_appetite: boolean;
    mitigation_strategy: string | null;
    next_review_date: string | null;
}

interface Props extends PageProps {
    committee: string;
    risks: CommitteeRisk[];
}

/** Committees served by RiskRegisterController::committeeView. */
const COMMITTEES = [
    { value: 'audit_risk', label: 'Audit & Risk' },
    { value: 'people', label: 'People' },
    { value: 'finance', label: 'Finance' },
];

const SEVERITY_OPTIONS = [
    { value: 'all', label: 'All severities' },
    { value: 'critical', label: 'Critical (20+)' },
    { value: 'high', label: 'High (15–19)' },
];

function dateOnly(value: string | null): string | null {
    return value ? value.slice(0, 10) : null;
}

function reviewDue(risk: CommitteeRisk): boolean {
    const date = dateOnly(risk.next_review_date);
    if (!date) return false;
    const soon = new Date(Date.now() + 7 * 86_400_000);
    return date <= toDateInput(soon);
}

export default function CommitteeRisks({ committee, risks }: Props) {
    const [severity, setSeverity] = useState('all');
    const [aboveOnly, setAboveOnly] = useState(false);
    const [dueOnly, setDueOnly] = useState(false);
    const ctxMenu = useEntityContextMenu<CommitteeRisk>();

    const title =
        COMMITTEES.find((c) => c.value === committee)?.label ??
        committee.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

    const sorted = useMemo(
        () =>
            [...risks].sort(
                (a, b) => (b.residual_score ?? 0) - (a.residual_score ?? 0),
            ),
        [risks],
    );
    const above = sorted.filter((r) => r.within_appetite === false).length;
    const critical = sorted.filter((r) => (r.residual_score ?? 0) >= 20).length;
    const due = sorted.filter(reviewDue).length;

    const visible = sorted.filter((risk) => {
        const score = risk.residual_score ?? 0;
        if (severity === 'critical' && score < 20) return false;
        if (severity === 'high' && (score < 15 || score > 19)) return false;
        if (aboveOnly && risk.within_appetite !== false) return false;
        if (dueOnly && !reviewDue(risk)) return false;
        return true;
    });
    const hasFilters = severity !== 'all' || aboveOnly || dueOnly;
    const clearFilters = () => {
        setSeverity('all');
        setAboveOnly(false);
        setDueOnly(false);
    };

    const actionsFor = (risk: CommitteeRisk): MenuItem[] =>
        compactMenu([
            {
                label: 'Open risk',
                icon: ExternalLink,
                onClick: () => router.visit(`/governance/risks/${risk.id}`),
            },
        ]);

    const header = (
        <PageHeader
            icon={Users}
            title={`${title} committee risks`}
            titleChip={
                above > 0 ? (
                    <PageHeaderStatusChip variant="critical">
                        {above} above appetite
                    </PageHeaderStatusChip>
                ) : (
                    <PageHeaderStatusChip variant="success">
                        Within appetite
                    </PageHeaderStatusChip>
                )
            }
            subline={`Active risks in this committee's categories · highest residual score first`}
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Committee risks"
                        ariaLabel="View all committee risks"
                        onClick={clearFilters}
                    >
                        <PageHeaderMeterBig>{sorted.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            active in scope
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Critical"
                        ariaLabel="View critical committee risks"
                        onClick={() => setSeverity('critical')}
                        tone={critical > 0 ? 'critical' : 'brand'}
                    >
                        <PageHeaderMeterBig>{critical}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Residual score 20+
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Above appetite"
                        value={above}
                        ariaLabel="View committee risks above appetite"
                        onClick={() => setAboveOnly(true)}
                        tone={above > 0 ? 'critical' : 'brand'}
                    >
                        <PageHeaderMeterDonut
                            percent={
                                sorted.length > 0
                                    ? (above / sorted.length) * 100
                                    : 0
                            }
                            caption={`${above} of ${sorted.length} risks`}
                        />
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Reviews due"
                        ariaLabel="View committee risks due for review"
                        onClick={() => setDueOnly(true)}
                        tone={due > 0 ? 'warning' : 'brand'}
                    >
                        <PageHeaderMeterBig>{due}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            overdue or within 7 days
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <RiskViewToggle value={null} />
                    <PageHeaderFilterSelect
                        label="Committee"
                        value={committee}
                        // A committee is always selected: no clear control.
                        allValue={committee}
                        options={COMMITTEES}
                        onChange={(value) => {
                            if (value !== committee) {
                                router.visit(
                                    `/governance/risks/committee/${value}`,
                                );
                            }
                        }}
                    />
                    <PageHeaderFilterSelect
                        label="Severity"
                        value={severity}
                        options={SEVERITY_OPTIONS}
                        onChange={setSeverity}
                    />
                    <PageHeaderFilterCheck
                        label="Above appetite"
                        checked={aboveOnly}
                        onChange={setAboveOnly}
                    />
                    <PageHeaderFilterCheck
                        label="Review due"
                        checked={dueOnly}
                        onChange={setDueOnly}
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
                {
                    title: `${title} committee`,
                    href: `/governance/risks/committee/${committee}`,
                },
            ]}
        >
            <Head title={`${title} committee risks`} />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title={hasFilters ? 'Matching risks' : 'Committee risks'}
                        caption={`${visible.length} of ${sorted.length} shown`}
                    />
                    {visible.length === 0 ? (
                        <EmptyState
                            icon={Users}
                            title={
                                hasFilters
                                    ? 'No committee risks match your filters'
                                    : 'No active risks for this committee'
                            }
                            description={
                                hasFilters
                                    ? 'Try clearing a filter.'
                                    : 'Active risks in this committee’s categories will appear here.'
                            }
                            action={
                                hasFilters ? (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={clearFilters}
                                    >
                                        <X className="h-3.5 w-3.5" />
                                        Clear filters
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <EntityTable<CommitteeRisk>
                            rows={visible}
                            rowKey={(risk) => risk.id}
                            identityLabel="Risk"
                            identity={(risk) => ({
                                icon: riskCategoryIcon(risk.category ?? ''),
                                name: risk.title,
                                linkLabel: `Open ${risk.title}`,
                                subline: [
                                    risk.risk_reference,
                                    risk.category
                                        ? risk.category
                                              .replace(/_/g, ' ')
                                              .replace(/\b\w/g, (c) =>
                                                  c.toUpperCase(),
                                              )
                                        : null,
                                ]
                                    .filter(Boolean)
                                    .join(' · '),
                            })}
                            hrefFor={(risk) => `/governance/risks/${risk.id}`}
                            onOpen={(risk) =>
                                router.visit(`/governance/risks/${risk.id}`)
                            }
                            minWidth={820}
                            columns={[
                                {
                                    key: 'residual',
                                    label: 'Residual',
                                    width: '1fr',
                                    cell: (risk) =>
                                        risk.residual_score != null ? (
                                            <EntityStatusChip
                                                variant={riskLevelVariant(
                                                    risk.residual_score,
                                                )}
                                            >
                                                {risk.residual_score} ·{' '}
                                                {riskScoreLevel(
                                                    risk.residual_score,
                                                )}
                                            </EntityStatusChip>
                                        ) : (
                                            <EmptyValue />
                                        ),
                                },
                                {
                                    key: 'appetite',
                                    label: 'Appetite',
                                    width: '0.9fr',
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
                                    key: 'strategy',
                                    label: 'Strategy',
                                    width: '0.9fr',
                                    cell: (risk) =>
                                        STRATEGY_OPTIONS.find(
                                            (s) =>
                                                s.key ===
                                                risk.mitigation_strategy,
                                        )?.label ??
                                        (risk.mitigation_strategy || (
                                            <EmptyValue />
                                        )),
                                },
                                {
                                    key: 'review',
                                    label: 'Next review',
                                    width: '1fr',
                                    cell: (risk) =>
                                        risk.next_review_date ? (
                                            reviewDue(risk) ? (
                                                <EntityStatusChip variant="warning">
                                                    {formatDateOnly(
                                                        dateOnly(
                                                            risk.next_review_date,
                                                        ),
                                                    )}
                                                </EntityStatusChip>
                                            ) : (
                                                formatDateOnly(
                                                    dateOnly(
                                                        risk.next_review_date,
                                                    ),
                                                )
                                            )
                                        ) : (
                                            <EmptyValue />
                                        ),
                                },
                            ]}
                            actionsFor={actionsFor}
                            onRowContextMenu={(e, risk) =>
                                ctxMenu.open(e, risk)
                            }
                        />
                    )}
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={riskCategoryIcon(ctxMenu.ctx.record.category ?? '')}
                    title={ctxMenu.ctx.record.title}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}
        </AppLayout>
    );
}
