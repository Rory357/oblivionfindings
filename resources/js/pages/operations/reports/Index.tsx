import PageShell from '@/components/page-shell';
import { Card, CardContent } from '@/components/ui/card';
import { PageHero } from '@/components/page';
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

export default function ReportsIndex() {
    const { labels } = usePage().props as any;
    const clientSingular = labels?.['client.singular'] ?? 'Client';
    const clientPlural = labels?.['client.plural'] ?? 'Clients';

    const reportTypes = [
        {
            title: 'Shift Operations',
            description: 'Decision-grade staffing, coverage, reconciliation, variance, and operational risk reporting.',
            href: '/operations/reports/shifts',
            icon: FileBarChart,
            color: 'bg-status-critical-bg text-status-critical dark:bg-status-critical-bg dark:text-status-critical',
        },
        {
            title: `${clientSingular} Summary`,
            description: `${clientSingular} activity, hours delivered, care plan progress, and goal outcomes.`,
            href: '/operations/reports/client-summary',
            icon: Users,
            color: 'bg-primary/10 text-primary dark:bg-primary/40 dark:text-primary/70',
        },
        {
            title: 'Staff Utilisation',
            description: 'Staff hours worked, utilisation rates, overtime, and availability.',
            href: '/operations/reports/staff-utilisation',
            icon: Clock,
            color: 'bg-status-info-bg text-status-info dark:bg-status-info-bg dark:text-status-info',
        },
        {
            title: 'Shift Analytics',
            description: 'Shift patterns, cancellations, no-shows, punctuality, and trends.',
            href: '/operations/reports/shift-analytics',
            icon: BarChart3,
            color: 'bg-status-info-bg text-status-info dark:bg-status-info-bg dark:text-status-info',
        },
        {
            title: 'Billing Report',
            description: 'Revenue summary, billing entries, outstanding amounts, and payment tracking.',
            href: '/operations/reports/billing',
            icon: DollarSign,
            color: 'bg-status-success-bg text-status-success dark:bg-status-success-bg dark:text-status-success',
        },
        {
            title: 'Compliance Report',
            description: 'Care plan reviews due, expired agreements, missing documents, and alerts.',
            href: '/operations/reports/compliance',
            icon: ClipboardCheck,
            color: 'bg-status-warning-bg text-status-warning dark:bg-status-warning-bg dark:text-status-warning',
        },
        {
            title: 'Service Hours',
            description: `Hours delivered vs funded by ${clientSingular.toLowerCase()}, service type, and period.`,
            href: '/operations/reports/service-hours',
            icon: PieChart,
            color: 'bg-primary/10 text-primary dark:bg-primary/40 dark:text-primary/70',
        },
    ];

    return (
        <AppLayout>
            <Head title="Operations Reports" />
            <PageHero
                icon={BarChart3}
                title="Reports & Analytics"
                description={`Operational reports across ${clientPlural.toLowerCase()}, staff, shifts, billing, and compliance.`}
                stats={[{ label: 'Reports available', value: reportTypes.length }]}
            />
            <PageShell>
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {reportTypes.map((report) => {
                        const Icon = report.icon;
                        return (
                            <Link key={report.href} href={report.href} className="block">
                                <Card className="h-full transition-all hover:border-border hover:shadow-md hover:-translate-y-0.5">
                                    <CardContent className="p-4">
                                        <div className="flex items-start gap-3">
                                            <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${report.color}`}>
                                                <Icon className="h-5 w-5" />
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <h3 className="text-sm font-semibold">{report.title}</h3>
                                                <p className="mt-0.5 text-xs text-muted-foreground">{report.description}</p>
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>
                            </Link>
                        );
                    })}
                </div>
            </PageShell>
        </AppLayout>
    );
}
