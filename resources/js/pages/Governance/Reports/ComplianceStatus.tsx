import { Head, router } from '@inertiajs/react';
import { ClipboardCheck, Eye, ShieldCheck } from 'lucide-react';

import {
    EmptyValue,
    EntityStatusChip,
    EntityTable,
    ListCaption,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly } from '@/lib/datetime';
import { refSuffix } from '@/lib/governance-labels';
import { PageProps } from '@/types';

import {
    dueLabel,
    obligationStatusLabel,
    obligationStatusVariant,
    onTimeSentence,
    plural,
} from '../Compliance/_shared';
import { GeneratedAt } from './_shared';

interface RequirementRow {
    id: number;
    title: string;
    code: string | null;
    owner: string | null;
    due_date: string | null;
    status: string;
    days_until_due: number | null;
}

interface Props extends PageProps {
    report: {
        summary: {
            total: number;
            counted: number;
            complete: number;
            overdue: number;
            due_soon: number;
            not_due: number;
            cancelled: number;
            on_time: number;
        };
        due_soon_days: number;
        frameworks: Array<{
            key: string;
            title: string;
            count: number;
            counted: number;
            on_time: number;
            overdue: number;
            items: RequirementRow[];
        }>;
    };
    today: string;
    generatedAt: string;
}

export default function ComplianceStatus({ auth, report, today, generatedAt }: Props) {
    const { summary } = report;
    const onTimePct = summary.counted > 0 ? (summary.on_time / summary.counted) * 100 : 0;

    const titleChip =
        summary.total === 0 ? (
            <PageHeaderStatusChip variant="neutral">No requirements yet</PageHeaderStatusChip>
        ) : summary.overdue > 0 ? (
            <PageHeaderStatusChip variant="critical">{summary.overdue} overdue</PageHeaderStatusChip>
        ) : summary.due_soon > 0 ? (
            <PageHeaderStatusChip variant="warning">
                {summary.due_soon} due in {report.due_soon_days} days
            </PageHeaderStatusChip>
        ) : (
            <PageHeaderStatusChip variant="success">Nothing overdue</PageHeaderStatusChip>
        );

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Compliance', href: '/governance/compliance' },
                {
                    title: 'Compliance status report',
                    href: '/governance/reports/compliance-status',
                },
            ]}
        >
            <Head title="Compliance status report" />

            <PageLayout
                hero={
                    <PageHeader
                        icon={ShieldCheck}
                        title="Compliance status report"
                        titleChip={titleChip}
                        subline={`Legal, standards and funding requirements, by where they come from · as at ${formatDateOnly(today)}`}
                        backHref="/governance/compliance"
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Requirements"
                                    href="/governance/compliance"
                                    ariaLabel="Open all requirements"
                                >
                                    <PageHeaderMeterBig>{summary.total}</PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {summary.cancelled > 0
                                            ? `${summary.cancelled} cancelled`
                                            : 'on the register'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Overdue"
                                    href="/governance/compliance?status=overdue"
                                    tone={summary.overdue > 0 ? 'critical' : 'brand'}
                                    ariaLabel="Open overdue requirements"
                                >
                                    <PageHeaderMeterBig>{summary.overdue}</PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>past their due date</PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label={`Due in ${report.due_soon_days} days`}
                                    href="/governance/compliance?status=due_soon"
                                    tone={summary.due_soon > 0 ? 'warning' : 'brand'}
                                    ariaLabel="Open requirements due soon"
                                >
                                    <PageHeaderMeterBig>{summary.due_soon}</PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>not overdue yet</PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Done"
                                    href="/governance/compliance?status=complete"
                                    ariaLabel="Open requirements that are done"
                                >
                                    <PageHeaderMeterBig>{summary.complete}</PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>marked as done</PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="On time"
                                    value={`${summary.on_time}/${summary.counted}`}
                                    href="/governance/compliance?status=on_time"
                                    tone="success"
                                    ariaLabel="Open requirements that are on time"
                                >
                                    <PageHeaderMeterDonut
                                        percent={onTimePct}
                                        caption="done or not yet overdue"
                                    />
                                </PageHeaderMeterBlock>
                            </>
                        }
                    />
                }
            >
                {report.frameworks.length === 0 ? (
                    <EmptyState
                        icon={ClipboardCheck}
                        title="No requirements yet"
                        description="Requirements appear here once they are added in Compliance."
                    />
                ) : (
                    report.frameworks.map((framework) => (
                        <section
                            key={framework.key}
                            className="flex flex-col gap-3"
                            aria-label={framework.title}
                        >
                            <ListCaption
                                title={framework.title}
                                caption={`${plural(framework.count, 'requirement')} · ${onTimeSentence(framework.on_time, framework.counted)}`}
                            />
                            <EntityTable<RequirementRow>
                                rows={framework.items}
                                rowKey={(item) => item.id}
                                identityLabel="Requirement"
                                identity={(item) => ({
                                    icon: ClipboardCheck,
                                    name: item.title,
                                    subline: refSuffix(item.code) || undefined,
                                })}
                                hrefFor={(item) => `/governance/compliance/${item.id}`}
                                actionsFor={(item) => [
                                    {
                                        label: 'Open requirement',
                                        icon: Eye,
                                        onClick: () => router.visit(`/governance/compliance/${item.id}`),
                                    },
                                ]}
                                onOpen={(item) => router.visit(`/governance/compliance/${item.id}`)}
                                minWidth={760}
                                columns={[
                                    {
                                        key: 'status',
                                        label: 'Status',
                                        width: '0.9fr',
                                        cell: (item) => (
                                            <EntityStatusChip variant={obligationStatusVariant(item.status)}>
                                                {obligationStatusLabel(item.status)}
                                            </EntityStatusChip>
                                        ),
                                    },
                                    {
                                        key: 'due',
                                        label: 'Due',
                                        width: '1fr',
                                        cell: (item) =>
                                            item.due_date ? (
                                                <span>
                                                    {formatDateOnly(item.due_date)}
                                                    {item.status !== 'complete' && item.status !== 'cancelled' ? (
                                                        <span className="text-caption block">
                                                            {dueLabel(item.days_until_due)}
                                                        </span>
                                                    ) : null}
                                                </span>
                                            ) : (
                                                <EmptyValue />
                                            ),
                                    },
                                    {
                                        key: 'owner',
                                        label: 'Owner',
                                        width: '0.9fr',
                                        cell: (item) =>
                                            item.owner ? (
                                                <span className="truncate">{item.owner}</span>
                                            ) : (
                                                <EmptyValue />
                                            ),
                                    },
                                ]}
                            />
                        </section>
                    ))
                )}
                <GeneratedAt at={generatedAt} />
            </PageLayout>
        </AppLayout>
    );
}
