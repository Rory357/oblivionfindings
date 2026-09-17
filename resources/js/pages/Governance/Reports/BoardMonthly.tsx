import { Head } from '@inertiajs/react';
import { BarChart3 } from 'lucide-react';

import {
    PageHeader,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { PageProps } from '@/types';

import {
    GeneratedAt,
    ReportSections,
    cardStatusCounts,
    metricTone,
    plural,
    type ReportMetric,
    type ReportSection,
} from './_shared';

interface Props extends PageProps {
    report: {
        headline: ReportMetric[];
        sections: ReportSection[];
    };
    period: { start: string; end: string; label: string };
    generatedAt: string;
}

const HEADLINE_CAPTIONS: Record<string, string> = {
    'Resolutions waiting': 'for a board decision',
    'Overdue actions': 'past their due date',
    'Critical risks': 'after controls',
    'Over or under budget': 'spending against budget',
};

export default function BoardMonthly({ auth, report, period, generatedAt }: Props) {
    const counts = cardStatusCounts(report.sections);

    const titleChip =
        counts.critical > 0 ? (
            <PageHeaderStatusChip variant="critical">
                {plural(counts.critical, 'serious area', 'serious areas')}
            </PageHeaderStatusChip>
        ) : counts.warning > 0 ? (
            <PageHeaderStatusChip variant="warning">
                {plural(counts.warning, 'area needs', 'areas need')} attention
            </PageHeaderStatusChip>
        ) : counts.unknown > 0 ? (
            <PageHeaderStatusChip variant="neutral">
                Some figures not available
            </PageHeaderStatusChip>
        ) : (
            <PageHeaderStatusChip variant="success">On track</PageHeaderStatusChip>
        );

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                {
                    title: 'Board monthly report',
                    href: '/governance/reports/board-monthly',
                },
            ]}
        >
            <Head title="Board monthly report" />

            <PageLayout
                hero={
                    <PageHeader
                        icon={BarChart3}
                        title="Board monthly report"
                        titleChip={titleChip}
                        subline={period.label}
                        backHref="/governance/dashboard"
                        meters={
                            <>
                                {report.headline.map((metric) => (
                                    <PageHeaderMeterBlock
                                        key={metric.label}
                                        label={metric.label}
                                        href={metric.href}
                                        tone={metricTone(metric.tone)}
                                        ariaLabel={`Open ${metric.label.toLowerCase()}`}
                                    >
                                        <PageHeaderMeterBig>
                                            {metric.value === 'Not available' ? (
                                                <span className="text-base">
                                                    Not available
                                                </span>
                                            ) : (
                                                metric.value
                                            )}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            {metric.value === 'Not available'
                                                ? "couldn't be loaded"
                                                : (HEADLINE_CAPTIONS[metric.label] ?? '')}
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                ))}
                            </>
                        }
                    />
                }
            >
                <ReportSections sections={report.sections} />
                <GeneratedAt at={generatedAt} />
            </PageLayout>
        </AppLayout>
    );
}
