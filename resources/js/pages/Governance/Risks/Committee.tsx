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
    PageHeaderGlassButton,
    PageHeaderMeterAvatars,
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
import { refSuffix } from '@/lib/governance-labels';
import { PageProps } from '@/types';
import { Head, router } from '@inertiajs/react';
import { BarChart3, ExternalLink, Users, X } from 'lucide-react';
import { useMemo, useState } from 'react';

import { riskCategoryIcon } from './_dialogs';
import {
    RISK_SEVERITY_FILTERS,
    RiskViewToggle,
    riskBand,
    riskBandLabel,
    riskLevelVariant,
    riskStatusChip,
    strategyLabel,
} from './_shared';

interface CommitteeRisk {
    id: number;
    risk_reference: string;
    title: string;
    category: string;
    category_label: string;
    inherent_score: number;
    residual_score: number;
    within_appetite: boolean;
    status: string;
    accepted_until: string | null;
    mitigation_strategy: string | null;
    next_review_date: string | null;
}

interface CommitteeInfo {
    id: number;
    name: string;
    type: string;
    description: string | null;
    members: Array<{ name: string; role: string; is_chair: boolean }>;
    categories: Array<{ value: string; label: string }>;
}

interface Props extends PageProps {
    committee: CommitteeInfo;
    committees: Array<{ id: number; name: string; type: string }>;
    risks: CommitteeRisk[];
}

function reviewDue(risk: CommitteeRisk, soon: string): boolean {
    return Boolean(risk.next_review_date && risk.next_review_date <= soon);
}

export default function CommitteeRisks({ committee, committees, risks }: Props) {
    const [severity, setSeverity] = useState('all');
    const [aboveOnly, setAboveOnly] = useState(false);
    const [dueOnly, setDueOnly] = useState(false);
    const ctxMenu = useEntityContextMenu<CommitteeRisk>();
    // Overdue or due within 7 days, by the NZ calendar date.
    const soon = toDateInput(Date.now() + 7 * 86_400_000);

    const sorted = useMemo(
        () => [...risks].sort((a, b) => b.residual_score - a.residual_score),
        [risks],
    );
    const aboveLimit = sorted.filter(
        (r) => !r.within_appetite && r.status === 'open',
    ).length;
    const critical = sorted.filter((r) => riskBand(r.residual_score) === 'critical').length;
    const due = sorted.filter((r) => reviewDue(r, soon)).length;

    const visible = sorted.filter((risk) => {
        if (severity !== 'all' && riskBand(risk.residual_score) !== severity) return false;
        if (aboveOnly && (risk.within_appetite || risk.status !== 'open')) return false;
        if (dueOnly && !reviewDue(risk, soon)) return false;
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

    const categoryList = committee.categories.map((c) => c.label.toLowerCase()).join(', ');

    const header = (
        <PageHeader
            icon={Users}
            title={`${committee.name} risks`}
            titleChip={
                aboveLimit > 0 ? (
                    <PageHeaderStatusChip variant="critical">
                        {aboveLimit} above the board&apos;s limit
                    </PageHeaderStatusChip>
                ) : sorted.length === 0 ? (
                    <PageHeaderStatusChip variant="neutral">
                        No open risks
                    </PageHeaderStatusChip>
                ) : (
                    <PageHeaderStatusChip variant="success">
                        None above the board&apos;s limit
                    </PageHeaderStatusChip>
                )
            }
            subline={`Open and accepted risks this committee oversees: ${categoryList || 'none set'}`}
            actions={
                <PageHeaderGlassButton
                    icon={BarChart3}
                    onClick={() =>
                        router.visit(`/governance/reports/committee/${committee.id}`)
                    }
                >
                    Committee report
                </PageHeaderGlassButton>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Risks overseen"
                        ariaLabel="Show all of this committee's risks"
                        onClick={clearFilters}
                    >
                        <PageHeaderMeterBig>{sorted.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>open or accepted</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Critical"
                        ariaLabel="Show this committee's critical risks"
                        onClick={() => setSeverity('critical')}
                        tone={critical > 0 ? 'critical' : 'brand'}
                    >
                        <PageHeaderMeterBig>{critical}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>after controls, 20–25</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Above the board's limit"
                        value={aboveLimit}
                        ariaLabel="Show this committee's risks above the board's limit"
                        onClick={() => setAboveOnly(true)}
                        tone={aboveLimit > 0 ? 'critical' : 'brand'}
                    >
                        <PageHeaderMeterDonut
                            percent={sorted.length > 0 ? (aboveLimit / sorted.length) * 100 : 0}
                            caption={`${aboveLimit} of ${sorted.length} risks`}
                        />
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Reviews due"
                        ariaLabel="Show this committee's risks due for review"
                        onClick={() => setDueOnly(true)}
                        tone={due > 0 ? 'warning' : 'brand'}
                    >
                        <PageHeaderMeterBig>{due}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>overdue or within 7 days</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Members"
                        value={committee.members.length}
                        ariaLabel="View this committee's members on its report"
                        href={`/governance/reports/committee/${committee.id}#committee-members`}
                    >
                        {committee.members.length > 0 ? (
                            <PageHeaderMeterAvatars
                                people={committee.members.slice(0, 6).map((member, index) => ({
                                    id: `${member.name}-${index}`,
                                    name: member.name,
                                    detail: member.role,
                                }))}
                                overflow={Math.max(0, committee.members.length - 6)}
                            />
                        ) : (
                            <PageHeaderMeterCaption>No current members</PageHeaderMeterCaption>
                        )}
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <RiskViewToggle value={null} />
                    {committees.length > 1 ? (
                        <PageHeaderFilterSelect
                            label={committee.name}
                            value={String(committee.id)}
                            // A committee is always selected: no clear control.
                            allValue={String(committee.id)}
                            options={committees.map((c) => ({
                                value: String(c.id),
                                label: c.name,
                            }))}
                            onChange={(value) => {
                                if (value !== String(committee.id)) {
                                    router.visit(`/governance/risks/committee/${value}`);
                                }
                            }}
                        />
                    ) : null}
                    <PageHeaderFilterSelect
                        label="Any level"
                        value={severity}
                        options={RISK_SEVERITY_FILTERS}
                        onChange={setSeverity}
                    />
                    <PageHeaderFilterCheck
                        label="Above the board's limit"
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
                    title: `${committee.name} risks`,
                    href: `/governance/risks/committee/${committee.id}`,
                },
            ]}
        >
            <Head title={`${committee.name} risks`} />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title={hasFilters ? 'Matching risks' : 'Risks this committee oversees'}
                        caption={`${visible.length} of ${sorted.length} shown · highest risk after controls first`}
                    />
                    {visible.length === 0 ? (
                        <EmptyState
                            icon={Users}
                            title={
                                hasFilters
                                    ? 'No risks match your filters'
                                    : 'No open risks for this committee'
                            }
                            description={
                                hasFilters
                                    ? 'Try clearing a filter.'
                                    : `Open or accepted ${categoryList || ''} risks appear here.`
                            }
                            action={
                                hasFilters ? (
                                    <Button variant="outline" size="sm" onClick={clearFilters}>
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
                                icon: riskCategoryIcon(risk.category),
                                name: risk.title,
                                linkLabel: `Open ${risk.title}`,
                                subline: `${risk.category_label} · ${refSuffix(risk.risk_reference)}`,
                            })}
                            hrefFor={(risk) => `/governance/risks/${risk.id}`}
                            onOpen={(risk) => router.visit(`/governance/risks/${risk.id}`)}
                            minWidth={860}
                            columns={[
                                {
                                    key: 'scores',
                                    label: 'Before → after controls',
                                    width: '1.1fr',
                                    cell: (risk) => (
                                        <EntityStatusChip variant={riskLevelVariant(risk.residual_score)}>
                                            {risk.inherent_score} → {risk.residual_score} ·{' '}
                                            {riskBandLabel(risk.residual_score)}
                                        </EntityStatusChip>
                                    ),
                                },
                                {
                                    key: 'status',
                                    label: 'Status',
                                    width: '1.3fr',
                                    cell: (risk) => {
                                        const chip = riskStatusChip(risk);
                                        return (
                                            <EntityStatusChip variant={chip.variant}>
                                                {chip.label}
                                            </EntityStatusChip>
                                        );
                                    },
                                },
                                {
                                    key: 'strategy',
                                    label: 'Response',
                                    width: '1fr',
                                    cell: (risk) =>
                                        risk.mitigation_strategy ? (
                                            strategyLabel(risk.mitigation_strategy)
                                        ) : (
                                            <EmptyValue />
                                        ),
                                },
                                {
                                    key: 'review',
                                    label: 'Next review',
                                    width: '0.9fr',
                                    cell: (risk) =>
                                        risk.next_review_date ? (
                                            reviewDue(risk, soon) ? (
                                                <EntityStatusChip variant="warning">
                                                    {formatDateOnly(risk.next_review_date)}
                                                </EntityStatusChip>
                                            ) : (
                                                formatDateOnly(risk.next_review_date)
                                            )
                                        ) : (
                                            <EmptyValue />
                                        ),
                                },
                            ]}
                            actionsFor={actionsFor}
                            onRowContextMenu={(e, risk) => ctxMenu.open(e, risk)}
                        />
                    )}
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
        </AppLayout>
    );
}
