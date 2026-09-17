import {
    PageHeader,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    BarChart3,
    ClipboardCheck,
    Clock,
    DollarSign,
    FileBarChart,
    PieChart,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';

export default function ReportsIndex() {
    const { labels } = usePage().props as any;
    const clientSingular: string = labels?.['client.singular'] ?? 'Client';
    const clientPlural: string = labels?.['client.plural'] ?? 'Clients';

    const [search, setSearch] = useState('');

    const reportTypes = [
        {
            title: 'Shift Operations',
            description:
                'Decision-grade staffing, coverage, reconciliation, variance, and operational risk reporting.',
            href: '/operations/reports/shifts',
            icon: FileBarChart,
            color: 'bg-status-critical-bg text-status-critical',
        },
        {
            title: `${clientSingular} Summary`,
            description: `${clientSingular} activity, hours delivered, care plan progress, and goal outcomes.`,
            href: '/operations/reports/client-summary',
            icon: Users,
            color: 'bg-primary/10 text-primary',
        },
        {
            title: 'Staff Utilisation',
            description:
                'Staff hours worked, utilisation rates, overtime, and availability.',
            href: '/operations/reports/staff-utilisation',
            icon: Clock,
            color: 'bg-status-info-bg text-status-info',
        },
        {
            title: 'Shift Analytics',
            description:
                'Shift patterns, cancellations, no-shows, punctuality, and trends.',
            href: '/operations/reports/shift-analytics',
            icon: BarChart3,
            color: 'bg-status-info-bg text-status-info',
        },
        {
            title: 'Billing Report',
            description:
                'Revenue summary, billing entries, outstanding amounts, and payment tracking.',
            href: '/operations/reports/billing',
            icon: DollarSign,
            color: 'bg-status-success-bg text-status-success',
        },
        {
            title: 'Compliance Report',
            description:
                'Care plan reviews due, expired agreements, missing documents, and alerts.',
            href: '/operations/reports/compliance',
            icon: ClipboardCheck,
            color: 'bg-status-warning-bg text-status-warning',
        },
        {
            title: 'Service Hours',
            description: `Hours delivered vs funded by ${clientSingular.toLowerCase()}, service type, and period.`,
            href: '/operations/reports/service-hours',
            icon: PieChart,
            color: 'bg-primary/10 text-primary',
        },
    ];

    const shownReports = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return reportTypes;
        return reportTypes.filter((report) =>
            `${report.title} ${report.description}`.toLowerCase().includes(q),
        );
        // reportTypes is rebuilt each render from static data + labels.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search, clientSingular]);

    const header = (
        <PageHeader
            icon={BarChart3}
            title="Reports"
            titleChip={
                <PageHeaderStatusChip variant="info">
                    {reportTypes.length} available
                </PageHeaderStatusChip>
            }
            subline={`Operational reporting across ${clientPlural.toLowerCase()}, staff, shifts, billing and compliance`}
            actions={
                <PageHeaderSearch
                    value={search}
                    onChange={setSearch}
                    placeholder="Search reports…"
                />
            }
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Operations', href: '/operations' },
                { title: 'Reports', href: '/operations/reports' },
            ]}
        >
            <Head title="Operations reports" />

            <PageLayout hero={header}>
                {shownReports.length === 0 ? (
                    <EmptyState
                        icon={BarChart3}
                        title="No reports match"
                        description="Try a different search term."
                    />
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {shownReports.map((report) => {
                            const Icon = report.icon;
                            return (
                                <Link
                                    key={report.href}
                                    href={report.href}
                                    className="block"
                                >
                                    <Card className="h-full transition-all hover:-translate-y-0.5 hover:border-border hover:shadow-md">
                                        <CardContent className="p-4">
                                            <div className="flex items-start gap-3">
                                                <div
                                                    className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${report.color}`}
                                                >
                                                    <Icon className="h-5 w-5" />
                                                </div>
                                                <div className="min-w-0 flex-1">
                                                    <h3 className="text-sm font-semibold">
                                                        {report.title}
                                                    </h3>
                                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                                        {report.description}
                                                    </p>
                                                </div>
                                            </div>
                                        </CardContent>
                                    </Card>
                                </Link>
                            );
                        })}
                    </div>
                )}
            </PageLayout>
        </AppLayout>
    );
}
