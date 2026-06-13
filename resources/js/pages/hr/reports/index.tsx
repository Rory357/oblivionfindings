import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    BarChart3,
    CalendarDays,
    Clock3,
    Download,
    GraduationCap,
    ShieldCheck,
    TrendingDown,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';

interface AvailableReport {
    key: string;
    title: string;
    description: string;
    category: string;
}

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

interface ReportSubscription {
    id: number;
    report_type: string;
    cadence: 'daily' | 'weekly' | 'monthly';
    day_of_week: number | null;
    day_of_month: number | null;
    run_at: string;
    timezone: string;
    is_active: boolean;
    next_run_at: string | null;
    last_run_at: string | null;
    last_status: string | null;
    last_error: string | null;
    recipient_user_ids: number[];
    recipient_names: string[];
    filters: {
        date_from: string | null;
        date_to: string | null;
    };
}

interface RecipientUser {
    id: number;
    name: string;
    email: string;
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
    headcount: 'border-status-info/30 text-status-info bg-status-info',
    turnover:
        'border-status-critical/30 text-status-critical bg-status-critical',
    compliance:
        'border-status-success/30 text-status-success bg-status-success',
    leave: 'border-status-warning/30 text-status-warning bg-status-warning',
    training: 'border-primary/30 text-primary bg-primary/10',
};

const weekdayLabels = [
    'Sunday',
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
];

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
    const [editingSubscriptionId, setEditingSubscriptionId] = useState<
        number | null
    >(null);

    const buildDefaultSubscription = () => ({
        report_type: availableReports[0]?.key ?? 'headcount',
        cadence: 'weekly' as 'daily' | 'weekly' | 'monthly',
        day_of_week: '1',
        day_of_month: '1',
        run_at: '08:00',
        timezone: 'Pacific/Auckland',
        recipient_user_id: recipientOptions[0]
            ? String(recipientOptions[0].id)
            : '',
        date_from: defaultFilters.date_from || '',
        date_to: defaultFilters.date_to || '',
    });

    const [newSubscription, setNewSubscription] = useState(
        buildDefaultSubscription,
    );

    const cadenceHint = useMemo(() => {
        if (newSubscription.cadence === 'daily') {
            return 'Runs every day at the selected time.';
        }

        if (newSubscription.cadence === 'weekly') {
            const dayIndex = Number(newSubscription.day_of_week);
            return `Runs each ${weekdayLabels[dayIndex] ?? 'Monday'} at the selected time.`;
        }

        return `Runs each month on day ${newSubscription.day_of_month} at the selected time.`;
    }, [
        newSubscription.cadence,
        newSubscription.day_of_week,
        newSubscription.day_of_month,
    ]);

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

    function submitSubscription(e: React.FormEvent) {
        e.preventDefault();

        const payload = {
            report_type: newSubscription.report_type,
            cadence: newSubscription.cadence,
            day_of_week:
                newSubscription.cadence === 'weekly'
                    ? Number(newSubscription.day_of_week)
                    : null,
            day_of_month:
                newSubscription.cadence === 'monthly'
                    ? Number(newSubscription.day_of_month)
                    : null,
            run_at: newSubscription.run_at,
            timezone: newSubscription.timezone || 'Pacific/Auckland',
            recipient_user_ids: newSubscription.recipient_user_id
                ? [Number(newSubscription.recipient_user_id)]
                : [],
            date_from: newSubscription.date_from || null,
            date_to: newSubscription.date_to || null,
            is_active: true,
        };

        const onSuccess = () => {
            setEditingSubscriptionId(null);
            setNewSubscription(buildDefaultSubscription());
        };

        if (editingSubscriptionId) {
            router.put(
                `/hr/reports/subscriptions/${editingSubscriptionId}`,
                payload,
                {
                    preserveScroll: true,
                    onSuccess,
                },
            );
            return;
        }

        router.post('/hr/reports/subscriptions', payload, {
            preserveScroll: true,
            onSuccess,
        });
    }

    function toggleSubscription(id: number) {
        router.post(
            `/hr/reports/subscriptions/${id}/toggle-active`,
            {},
            { preserveScroll: true },
        );
    }

    function startEditSubscription(subscription: ReportSubscription) {
        setEditingSubscriptionId(subscription.id);
        setNewSubscription({
            report_type: subscription.report_type,
            cadence: subscription.cadence,
            day_of_week: String(subscription.day_of_week ?? 1),
            day_of_month: String(subscription.day_of_month ?? 1),
            run_at: (subscription.run_at || '08:00').slice(0, 5),
            timezone: subscription.timezone || 'Pacific/Auckland',
            recipient_user_id: subscription.recipient_user_ids[0]
                ? String(subscription.recipient_user_ids[0])
                : '',
            date_from: subscription.filters.date_from || '',
            date_to: subscription.filters.date_to || '',
        });
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
                    ? weekdayLabels[subscription.day_of_week]
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
                    <PageHero category="hr"
                        icon={BarChart3}
                        title="HR Reports"
                        description="Export, schedule, and subscribe to HR analytics reports."
                        stats={[
                            { label: 'Available', value: availableReports.length },
                            { label: 'Recent exports', value: recentExports.length },
                            { label: 'Subscriptions', value: subscriptions.length },
                        ]}
                        actions={
                            can.export_data ? (
                                <div className="flex items-center gap-2">
                                    <Button variant="outline" asChild className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground">
                                        <Link href="/hr/reports/automations">
                                            Automations
                                        </Link>
                                    </Button>
                                    <Button variant="outline" asChild className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground">
                                        <Link href="/hr/reports/webhooks">
                                            Webhooks
                                        </Link>
                                    </Button>
                                </div>
                            ) : undefined
                        }
                    />
                }
            >
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
                                                {report.period_start ||
                                                    '\u2014'}{' '}
                                                to{' '}
                                                {report.period_end || '\u2014'}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {report.row_count}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {report.generated_at ||
                                                    '\u2014'}
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
                    <>
                        <div>
                            <h2 className="mb-4 text-lg font-semibold">
                                Scheduled Reports
                            </h2>
                            <Card>
                                <CardHeader>
                                    <div className="flex items-center justify-between">
                                        <CardTitle className="text-base">
                                            {editingSubscriptionId
                                                ? 'Edit Schedule'
                                                : 'Create Schedule'}
                                        </CardTitle>
                                        {editingSubscriptionId && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() => {
                                                    setEditingSubscriptionId(
                                                        null,
                                                    );
                                                    setNewSubscription(
                                                        buildDefaultSubscription(),
                                                    );
                                                }}
                                            >
                                                Cancel Edit
                                            </Button>
                                        )}
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    <form
                                        className="grid gap-4 md:grid-cols-4"
                                        onSubmit={submitSubscription}
                                    >
                                        <div className="space-y-2">
                                            <Label>Report</Label>
                                            <Select
                                                value={
                                                    newSubscription.report_type
                                                }
                                                onValueChange={(value) =>
                                                    setNewSubscription(
                                                        (prev) => ({
                                                            ...prev,
                                                            report_type: value,
                                                        }),
                                                    )
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {availableReports.map(
                                                        (report) => (
                                                            <SelectItem
                                                                key={report.key}
                                                                value={
                                                                    report.key
                                                                }
                                                            >
                                                                {report.title}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        </div>

                                        <div className="space-y-2">
                                            <Label>Cadence</Label>
                                            <Select
                                                value={newSubscription.cadence}
                                                onValueChange={(value) =>
                                                    setNewSubscription(
                                                        (prev) => ({
                                                            ...prev,
                                                            cadence: value as
                                                                | 'daily'
                                                                | 'weekly'
                                                                | 'monthly',
                                                        }),
                                                    )
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="daily">
                                                        Daily
                                                    </SelectItem>
                                                    <SelectItem value="weekly">
                                                        Weekly
                                                    </SelectItem>
                                                    <SelectItem value="monthly">
                                                        Monthly
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </div>

                                        {newSubscription.cadence ===
                                            'weekly' && (
                                            <div className="space-y-2">
                                                <Label>Weekday</Label>
                                                <Select
                                                    value={
                                                        newSubscription.day_of_week
                                                    }
                                                    onValueChange={(value) =>
                                                        setNewSubscription(
                                                            (prev) => ({
                                                                ...prev,
                                                                day_of_week:
                                                                    value,
                                                            }),
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {weekdayLabels.map(
                                                            (day, index) => (
                                                                <SelectItem
                                                                    key={day}
                                                                    value={String(
                                                                        index,
                                                                    )}
                                                                >
                                                                    {day}
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                        )}

                                        {newSubscription.cadence ===
                                            'monthly' && (
                                            <div className="space-y-2">
                                                <Label>Day Of Month</Label>
                                                <Input
                                                    type="number"
                                                    min={1}
                                                    max={28}
                                                    value={
                                                        newSubscription.day_of_month
                                                    }
                                                    onChange={(event) =>
                                                        setNewSubscription(
                                                            (prev) => ({
                                                                ...prev,
                                                                day_of_month:
                                                                    event.target
                                                                        .value,
                                                            }),
                                                        )
                                                    }
                                                />
                                            </div>
                                        )}

                                        <div className="space-y-2">
                                            <Label>Run At</Label>
                                            <Input
                                                type="time"
                                                value={newSubscription.run_at}
                                                onChange={(event) =>
                                                    setNewSubscription(
                                                        (prev) => ({
                                                            ...prev,
                                                            run_at: event.target
                                                                .value,
                                                        }),
                                                    )
                                                }
                                            />
                                        </div>

                                        <div className="space-y-2">
                                            <Label>Timezone</Label>
                                            <Input
                                                value={newSubscription.timezone}
                                                onChange={(event) =>
                                                    setNewSubscription(
                                                        (prev) => ({
                                                            ...prev,
                                                            timezone:
                                                                event.target
                                                                    .value,
                                                        }),
                                                    )
                                                }
                                                placeholder="Pacific/Auckland"
                                            />
                                        </div>

                                        <div className="space-y-2">
                                            <Label>Recipient</Label>
                                            <Select
                                                value={
                                                    newSubscription.recipient_user_id ||
                                                    '__none__'
                                                }
                                                onValueChange={(value) =>
                                                    setNewSubscription(
                                                        (prev) => ({
                                                            ...prev,
                                                            recipient_user_id:
                                                                value ===
                                                                '__none__'
                                                                    ? ''
                                                                    : value,
                                                        }),
                                                    )
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Current user" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="__none__">
                                                        Current user
                                                    </SelectItem>
                                                    {recipientOptions.map(
                                                        (recipient) => (
                                                            <SelectItem
                                                                key={
                                                                    recipient.id
                                                                }
                                                                value={String(
                                                                    recipient.id,
                                                                )}
                                                            >
                                                                {recipient.name}{' '}
                                                                (
                                                                {
                                                                    recipient.email
                                                                }
                                                                )
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        </div>

                                        <div className="space-y-2">
                                            <Label>Default Date From</Label>
                                            <Input
                                                type="date"
                                                value={
                                                    newSubscription.date_from
                                                }
                                                onChange={(event) =>
                                                    setNewSubscription(
                                                        (prev) => ({
                                                            ...prev,
                                                            date_from:
                                                                event.target
                                                                    .value,
                                                        }),
                                                    )
                                                }
                                            />
                                        </div>

                                        <div className="space-y-2">
                                            <Label>Default Date To</Label>
                                            <Input
                                                type="date"
                                                value={newSubscription.date_to}
                                                onChange={(event) =>
                                                    setNewSubscription(
                                                        (prev) => ({
                                                            ...prev,
                                                            date_to:
                                                                event.target
                                                                    .value,
                                                        }),
                                                    )
                                                }
                                            />
                                        </div>

                                        <div className="flex items-end">
                                            <Button type="submit">
                                                {editingSubscriptionId
                                                    ? 'Update Schedule'
                                                    : 'Create Schedule'}
                                            </Button>
                                        </div>
                                    </form>
                                    <p className="mt-3 text-xs text-muted-foreground">
                                        {cadenceHint}
                                    </p>
                                </CardContent>
                            </Card>
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
                                                        : '\u2014'}
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
                                                        '\u2014'}
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
                                                                startEditSubscription(
                                                                    subscription,
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
                    </>
                )}
            </PageLayout>
        </AppLayout>
    );
}
