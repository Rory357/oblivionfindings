import { Head, router } from '@inertiajs/react';
import { BarChart3, Eye, ShieldAlert, Users } from 'lucide-react';

import {
    EmptyValue,
    EntityStatusChip,
    EntityTable,
    ListCaption,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterAvatars,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { refSuffix } from '@/lib/governance-labels';
import { PageProps } from '@/types';

import { riskBandLabel, riskLevelVariant, riskStatusChip } from '../Risks/_shared';
import {
    GeneratedAt,
    ReportSections,
    metricTone,
    plural,
    type ReportMetric,
    type ReportSection,
} from './_shared';

interface CommitteeRisk {
    id: number;
    reference: string | null;
    title: string;
    category: string;
    residual_score: number;
    owner: string | null;
    within_appetite: boolean;
    status: string;
}

interface Props extends PageProps {
    report: {
        committee: {
            id: number;
            name: string;
            type: string;
            description?: string | null;
            members: Array<{ name: string; role: string; is_chair: boolean }>;
            categories: Array<{ value: string; label: string }>;
            risk_view_href: string;
        };
        headline: ReportMetric[];
        sections: ReportSection[];
        risks: CommitteeRisk[];
    };
    committees: Array<{ id: number; name: string; type: string }>;
    period: { label: string };
    generatedAt: string;
}

function scrollToMembers() {
    document
        .getElementById('committee-members')
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

export default function CommitteeReport({
    auth,
    report,
    committees = [],
    period,
    generatedAt,
}: Props) {
    const { committee } = report;
    const highOrCritical = report.risks.filter((risk) => risk.residual_score >= 15).length;
    const critical = report.risks.filter((risk) => risk.residual_score >= 20).length;
    const oversees = committee.categories.map((c) => c.label.toLowerCase()).join(', ');

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Risk register', href: '/governance/risks' },
                {
                    title: `${committee.name} report`,
                    href: `/governance/reports/committee/${committee.id}`,
                },
            ]}
        >
            <Head title={`${committee.name} report`} />

            <PageLayout
                hero={
                    <PageHeader
                        icon={BarChart3}
                        title={`${committee.name} report`}
                        titleChip={
                            critical > 0 ? (
                                <PageHeaderStatusChip variant="critical">
                                    {plural(critical, 'critical risk', 'critical risks')}
                                </PageHeaderStatusChip>
                            ) : highOrCritical > 0 ? (
                                <PageHeaderStatusChip variant="warning">
                                    {plural(highOrCritical, 'high risk', 'high risks')}
                                </PageHeaderStatusChip>
                            ) : (
                                <PageHeaderStatusChip variant="success">
                                    No high or critical risks
                                </PageHeaderStatusChip>
                            )
                        }
                        subline={`${period.label}${oversees ? ` · Oversees ${oversees} risks` : ''}`}
                        backHref={committee.risk_view_href}
                        actions={
                            <PageHeaderGlassButton
                                icon={ShieldAlert}
                                onClick={() => router.visit(committee.risk_view_href)}
                            >
                                Committee risks
                            </PageHeaderGlassButton>
                        }
                        meters={
                            <>
                                {report.headline.map((metric) => (
                                    <PageHeaderMeterBlock
                                        key={metric.label}
                                        label={metric.label}
                                        href={metric.href ?? committee.risk_view_href}
                                        tone={metricTone(metric.tone)}
                                        ariaLabel={`Open ${committee.name} risks`}
                                    >
                                        <PageHeaderMeterBig>{metric.value}</PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            {metric.label === 'High or critical'
                                                ? 'score 15 or more after controls'
                                                : 'open or accepted by the board'}
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                ))}
                                <PageHeaderMeterBlock
                                    label="Members"
                                    value={committee.members.length}
                                    ariaLabel="Go to this committee's members"
                                    onClick={scrollToMembers}
                                >
                                    {committee.members.length > 0 ? (
                                        <PageHeaderMeterAvatars
                                            people={committee.members
                                                .slice(0, 6)
                                                .map((member, index) => ({
                                                    id: `${member.name}-${index}`,
                                                    name: member.name,
                                                    detail: member.role,
                                                }))}
                                            overflow={Math.max(0, committee.members.length - 6)}
                                        />
                                    ) : (
                                        <PageHeaderMeterCaption>
                                            No current members
                                        </PageHeaderMeterCaption>
                                    )}
                                </PageHeaderMeterBlock>
                            </>
                        }
                        filters={
                            committees.length > 1 ? (
                                <PageHeaderFilterSelect
                                    icon={Users}
                                    label="Committee"
                                    value={String(committee.id)}
                                    allValue={String(committee.id)}
                                    options={committees.map((c) => ({
                                        value: String(c.id),
                                        label: c.name,
                                    }))}
                                    onChange={(value) =>
                                        router.visit(`/governance/reports/committee/${value}`)
                                    }
                                />
                            ) : undefined
                        }
                    />
                }
            >
                <ReportSections sections={report.sections} />

                <section className="flex flex-col gap-3" aria-label="Risks this committee oversees">
                    <ListCaption
                        title="Risks this committee oversees"
                        caption={`Highest after controls first · ${plural(report.risks.length, 'risk', 'risks')}`}
                    />
                    {report.risks.length === 0 ? (
                        <EmptyState
                            icon={ShieldAlert}
                            title="No open risks for this committee"
                            description="Risks appear here when they are open or accepted by the board and belong to this committee's kinds of risk."
                        />
                    ) : (
                        <EntityTable<CommitteeRisk>
                            rows={report.risks}
                            rowKey={(risk) => risk.id}
                            identityLabel="Risk"
                            identity={(risk) => ({
                                icon: ShieldAlert,
                                name: risk.title,
                                subline: [risk.category, refSuffix(risk.reference)]
                                    .filter(Boolean)
                                    .join(' · '),
                            })}
                            hrefFor={(risk) => `/governance/risks/${risk.id}`}
                            actionsFor={(risk) => [
                                {
                                    label: 'Open risk',
                                    icon: Eye,
                                    onClick: () => router.visit(`/governance/risks/${risk.id}`),
                                },
                            ]}
                            onOpen={(risk) => router.visit(`/governance/risks/${risk.id}`)}
                            minWidth={760}
                            columns={[
                                {
                                    key: 'after',
                                    label: 'Risk after controls',
                                    width: '1fr',
                                    cell: (risk) => (
                                        <EntityStatusChip variant={riskLevelVariant(risk.residual_score)}>
                                            {risk.residual_score} · {riskBandLabel(risk.residual_score)}
                                        </EntityStatusChip>
                                    ),
                                },
                                {
                                    key: 'status',
                                    label: 'Status',
                                    width: '1.1fr',
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
                                    key: 'owner',
                                    label: 'Owner',
                                    width: '0.9fr',
                                    cell: (risk) =>
                                        risk.owner ? (
                                            <span className="truncate">{risk.owner}</span>
                                        ) : (
                                            <EmptyValue />
                                        ),
                                },
                            ]}
                        />
                    )}
                </section>

                <Card id="committee-members" className="scroll-mt-24">
                    <CardHeader>
                        <CardTitle>Members</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {committee.members.length === 0 ? (
                            <p className="text-subtle">
                                Nobody is appointed to this committee at the moment.
                            </p>
                        ) : (
                            <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {committee.members.map((member, index) => (
                                    <li
                                        key={`${member.name}-${index}`}
                                        className="flex flex-col rounded-lg border border-border px-3 py-2"
                                    >
                                        <span className="text-sm font-medium text-foreground">
                                            {member.name}
                                        </span>
                                        <span className="text-caption">{member.role}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>

                <GeneratedAt at={generatedAt} />
            </PageLayout>
        </AppLayout>
    );
}
