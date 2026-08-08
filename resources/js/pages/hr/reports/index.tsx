import { ReportsTabs } from '@/components/hr';
import {
    ScheduleReportWizard,
    WEEKDAY_LABELS,
    type AvailableReport,
    type RecipientUser,
    type ReportSubscription,
} from '@/components/hr/report-wizards';
import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    BarChart3,
    Bookmark,
    CalendarClock,
    CalendarDays,
    Clock3,
    Download,
    GraduationCap,
    ShieldCheck,
    TrendingDown,
    Users,
    Wrench,
} from 'lucide-react';
import { useState } from 'react';

interface RecentExport {
    id: number;
    report_type: string;
    period_start: string | null;
    period_end: string | null;
    row_count: number;
    generated_at: string | null;
    generated_by: string | null;
    subscription_id: number | null;
}

interface Props {
    availableReports: AvailableReport[];
    recentExports: RecentExport[];
    subscriptions: ReportSubscription[];
    recipientOptions: RecipientUser[];
    defaultFilters: {
        date_from: string;
        date_to: string;
    };
    can: { export_data: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Reports', href: '/hr/reports' },
];

const categoryIcons: Record<string, React.ElementType> = {
    headcount: Users,
    turnover: TrendingDown,
    compliance: ShieldCheck,
    leave: CalendarDays,
    training: GraduationCap,
};

const categoryColors: Record<string, string> = {
    headcount: 'border-status-info/30 text-status-info bg-status-info-bg',
    turnover:
        'border-status-critical/30 text-status-critical bg-status-critical-bg',
    compliance:
        'border-status-success/30 text-status-success bg-status-success-bg',
    leave: 'border-status-warning/30 text-status-warning bg-status-warning-bg',
    training: 'border-primary/30 text-primary bg-primary/10',
};

type ScheduleWizardState = { subscription: ReportSubscription | null } | null;

export default function ReportsIndex({
    availableReports,
    recentExports,
    subscriptions,
    recipientOptions,
    defaultFilters,
    can,
}: Props) {
    const [dateFrom, setDateFrom] = useState(defaultFilters.date_from || '');
    const [dateTo, setDateTo] = useState(defaultFilters.date_to || '');
    const [scheduleWizard, setScheduleWizard] =
        useState<ScheduleWizardState>(null);

    const activeSubscriptions = subscriptions.filter(
        (subscription) => subscription.is_active,
    ).length;

    function buildFilterParams(reportType: string): string {
        const params = new URLSearchParams({ report_type: reportType });
        if (dateFrom) {
            params.set('date_from', dateFrom);
        }
        if (dateTo) {
            params.set('date_to', dateTo);
        }

        return params.toString();
    }

    function generateReport(reportType: string) {
        router.post('/hr/reports/generate', {
            report_type: reportType,
            date_from: dateFrom || undefined,
            date_to: dateTo || undefined,
        });
    }

    function toggleSubscription(id: number) {
        router.post(
            `/hr/reports/subscriptions/${id}/toggle-active`,
            {},
            { preserveScroll: true },
        );
    }

    function subscriptionScheduleLabel(
        subscription: ReportSubscription,
    ): string {
        if (subscription.cadence === 'daily') {
            return `Daily at ${subscription.run_at}`;
        }
        if (subscription.cadence === 'weekly') {
            const label =
                subscription.day_of_week !== null
                    ? WEEKDAY_LABELS[subscription.day_of_week]
                    : 'Weekly';
            return `${label} at ${subscription.run_at}`;
        }

        return `Day ${subscription.day_of_month ?? 1} at ${subscription.run_at}`;
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="HR Reports" />
            <PageLayout
                hero={
                    <PageHero
                        category="hr"
                        icon={BarChart3}
                        title="HR Reports"
                        description="Export, schedule, and subscribe to HR analytics reports."
                        stats={[
                            {
                                label: 'Available',
                                value: availableReports.length,
                            },
                            {
                                label: 'Recent exports',
                                value: recentExports.length,
                            },
                            {
                                label: 'Active schedules',
                                value: activeSubscriptions,
                                tone:
                                    activeSubscriptions > 0
                                        ? 'success'
                                        : undefined,
                            },
                        ]}
                        actions={
                            <>
                                <Button variant="outline" asChild>
                                    <Link href="/hr/reports/saved">
                                        <Bookmark className="mr-2 h-4 w-4" />
                                        Saved reports
                                    </Link>
                                </Button>
                                <Button variant="outline" asChild>
                                    <Link href="/hr/reports/builder">
                                        <Wrench className="mr-2 h-4 w-4" />
                                        Report builder
                                    </Link>
                                </Button>
                                {can.export_data && (
                                    <Button
                                        onClick={() =>
                                            setScheduleWizard({
                                                subscription: null,
                                            })
                                        }
                                    >
                                        <CalendarClock className="mr-2 h-4 w-4" />
                                        Schedule report
                                    </Button>
                                )}
                            </>
                        }
                    />
                }
            >
                <ReportsTabs active="index" />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Report Date Range
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-3 md:grid-cols-3">
                        <div className="space-y-2">
                            <Label>Date From</Label>
                            <Input
                                type="date"
                                value={dateFrom}
                                onChange={(event) =>
                                    setDateFrom(event.target.value)
                                }
                            />
                        </div>
                        <div className="space-y-2">
                            <Label>Date To</Label>
                            <Input
                                type="date"
                                value={dateTo}
                                onChange={(event) =>
                                    setDateTo(event.target.value)
                                }
                            />
                        </div>
                        <div className="flex items-end text-xs text-muted-foreground">
                            These filters apply to quick generate/export actions
                            below.
                        </div>
                    </CardContent>
                </Card>

                {/* Available Reports Grid */}
                <div>
                    <h2 className="mb-4 text-lg font-semibold">
                        Available Reports
                    </h2>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {availableReports.map((report) => {
                            const IconComponent =
                                categoryIcons[report.category] || BarChart3;
                            const colorClass =
                                categoryColors[report.category] ||
                                categoryColors.headcount;
                            return (
                                <Card
                                    key={report.key}
                                    className="transition-shadow hover:shadow-md"
                                >
                                    <CardHeader className="pb-2">
                                        <div className="flex items-center gap-3">
                                            <div
                                                className={`flex h-10 w-10 items-center justify-center rounded-lg border ${colorClass}`}
                                            >
                                                <IconComponent className="h-5 w-5" />
                                            </div>
                                            <div>
                                                <CardTitle className="text-base">
                                                    {report.title}
                                                </CardTitle>
                                                <Badge
                                                    variant="outline"
                                                    className={`mt-1 text-xs ${colorClass}`}
                                                >
                                                    {report.category}
                                                </Badge>
                                            </div>
                                        </div>
                                    </CardHeader>
                                    <CardContent>
                                        <p className="mb-3 text-sm text-muted-foreground">
                                            {report.description}
                                        </p>
                                        <div className="flex items-center gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    generateReport(report.key)
                                                }
                                            >
                                                Generate Report
                                            </Button>
                                            {can.export_data && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <a
                                                        href={`/hr/reports/export?${buildFilterParams(report.key)}`}
                                                    >
                                                        <Download className="mr-1 h-3 w-3" />
                                                        CSV
                                                    </a>
                                                </Button>
                                            )}
                                        </div>
                                    </CardContent>
                                </Card>
                            );
                        })}
                        {availableReports.length === 0 && (
                            <p className="col-span-full py-8 text-center text-muted-foreground">
                                No reports available.
                            </p>
                        )}
                    </div>
                </div>

                {/* Recent Reports */}
                <div>
                    <h2 className="mb-4 text-lg font-semibold">
                        Recent Exports
                    </h2>
                    <Card>
                        <CardContent className="p-0">
                            <table className="w-full text-sm">
                                <thead className="border-b bg-muted/50">
                                    <tr>
                                        <th className="px-4 py-3 text-left font-medium">
                                            Report Type
                                        </th>
                                        <th className="px-4 py-3 text-left font-medium">
                                            Period
                                        </th>
                                        <th className="px-4 py-3 text-left font-medium">
                                            Rows
                                        </th>
                                        <th className="px-4 py-3 text-left font-medium">
                                            Generated
                                        </th>
                                        <th className="px-4 py-3 text-right font-medium">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {recentExports.map((report) => (
                                        <tr
                                            key={report.id}
                                            className="hover:bg-muted/30"
                                        >
                                            <td className="px-4 py-3 font-medium capitalize">
                                                {report.report_type.replace(
                                                    /_/g,
                                                    ' ',
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {report.period_start || '—'} to{' '}
                                                {report.period_end || '—'}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {report.row_count}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {report.generated_at || '—'}
                                                {report.generated_by
                                                    ? ` by ${report.generated_by}`
                                                    : ''}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <div className="flex items-center justify-end gap-2">
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={`/hr/reports/exports/${report.id}`}
                                                        >
                                                            View
                                                        </Link>
                                                    </Button>
                                                    {can.export_data && (
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            asChild
                                                        >
                                                            <a
                                                                href={`/hr/reports/exports/${report.id}/download`}
                                                                target="_blank"
                                                                rel="noreferrer"
                                                            >
                                                                <Download className="mr-1 h-3 w-3" />
                                                                Download
                                                            </a>
                                                        </Button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                    {recentExports.length === 0 && (
                                        <tr>
                                            <td
                                                colSpan={5}
                                                className="px-4 py-8 text-center text-muted-foreground"
                                            >
                                                No reports generated yet.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>
                </div>

                {can.export_data && (
                    <div>
                        <div className="mb-4 flex items-center justify-between">
                            <h2 className="text-lg font-semibold">
                                Scheduled Reports
                            </h2>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    setScheduleWizard({ subscription: null })
                                }
                            >
                                <CalendarClock className="mr-2 h-4 w-4" />
                                Schedule report
                            </Button>
                        </div>
                        <Card>
                            <CardContent className="p-0">
                                <table className="w-full text-sm">
                                    <thead className="border-b bg-muted/50">
                                        <tr>
                                            <th className="px-4 py-3 text-left font-medium">
                                                Report
                                            </th>
                                            <th className="px-4 py-3 text-left font-medium">
                                                Schedule
                                            </th>
                                            <th className="px-4 py-3 text-left font-medium">
                                                Recipients
                                            </th>
                                            <th className="px-4 py-3 text-left font-medium">
                                                Status
                                            </th>
                                            <th className="px-4 py-3 text-left font-medium">
                                                Next Run
                                            </th>
                                            <th className="px-4 py-3 text-right font-medium">
                                                Actions
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {subscriptions.map((subscription) => (
                                            <tr
                                                key={subscription.id}
                                                className="hover:bg-muted/30"
                                            >
                                                <td className="px-4 py-3 font-medium capitalize">
                                                    {subscription.report_type.replace(
                                                        /_/g,
                                                        ' ',
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-muted-foreground">
                                                    <div className="flex items-center gap-1.5">
                                                        <Clock3 className="h-3.5 w-3.5" />
                                                        {subscriptionScheduleLabel(
                                                            subscription,
                                                        )}{' '}
                                                        ({subscription.timezone}
                                                        )
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3 text-muted-foreground">
                                                    {subscription
                                                        .recipient_names
                                                        .length > 0
                                                        ? subscription.recipient_names.join(
                                                              ', ',
                                                          )
                                                        : '—'}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <Badge
                                                        variant={
                                                            subscription.is_active
                                                                ? 'default'
                                                                : 'secondary'
                                                        }
                                                    >
                                                        {subscription.is_active
                                                            ? 'Active'
                                                            : 'Paused'}
                                                    </Badge>
                                                    {subscription.last_status && (
                                                        <Badge
                                                            variant="outline"
                                                            className="ml-2 capitalize"
                                                        >
                                                            {
                                                                subscription.last_status
                                                            }
                                                        </Badge>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-muted-foreground">
                                                    {subscription.next_run_at ||
                                                        '—'}
                                                    {subscription.last_run_at
                                                        ? ` (last: ${subscription.last_run_at})`
                                                        : ''}
                                                </td>
                                                <td className="px-4 py-3 text-right">
                                                    <div className="flex items-center justify-end gap-2">
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() =>
                                                                setScheduleWizard(
                                                                    {
                                                                        subscription,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            Edit
                                                        </Button>
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() =>
                                                                toggleSubscription(
                                                                    subscription.id,
                                                                )
                                                            }
                                                        >
                                                            {subscription.is_active
                                                                ? 'Pause'
                                                                : 'Resume'}
                                                        </Button>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                        {subscriptions.length === 0 && (
                                            <tr>
                                                <td
                                                    colSpan={6}
                                                    className="px-4 py-8 text-center text-muted-foreground"
                                                >
                                                    No scheduled reports
                                                    configured.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </CardContent>
                        </Card>
                    </div>
                )}

                {scheduleWizard !== null && (
                    <ScheduleReportWizard
                        key={scheduleWizard.subscription?.id ?? 'new'}
                        subscription={scheduleWizard.subscription}
                        reports={availableReports}
                        recipients={recipientOptions}
                        defaultFilters={defaultFilters}
                        onClose={() => setScheduleWizard(null)}
                    />
                )}
            </PageLayout>
        </AppLayout>
    );
}
