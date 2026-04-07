import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Card, CardContent } from '@/components/ui/card';
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
            color: 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300',
        },
        {
            title: `${clientSingular} Summary`,
            description: `${clientSingular} activity, hours delivered, care plan progress, and goal outcomes.`,
            href: '/operations/reports/client-summary',
            icon: Users,
            color: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300',
        },
        {
            title: 'Staff Utilisation',
            description: 'Staff hours worked, utilisation rates, overtime, and availability.',
            href: '/operations/reports/staff-utilisation',
            icon: Clock,
            color: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
        },
        {
            title: 'Shift Analytics',
            description: 'Shift patterns, cancellations, no-shows, punctuality, and trends.',
            href: '/operations/reports/shift-analytics',
            icon: BarChart3,
            color: 'bg-cyan-100 text-cyan-700 dark:bg-cyan-900/40 dark:text-cyan-300',
        },
        {
            title: 'Billing Report',
            description: 'Revenue summary, billing entries, outstanding amounts, and payment tracking.',
            href: '/operations/reports/billing',
            icon: DollarSign,
            color: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
        },
        {
            title: 'Compliance Report',
            description: 'Care plan reviews due, expired agreements, missing documents, and alerts.',
            href: '/operations/reports/compliance',
            icon: ClipboardCheck,
            color: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
        },
        {
            title: 'Service Hours',
            description: `Hours delivered vs funded by ${clientSingular.toLowerCase()}, service type, and period.`,
            href: '/operations/reports/service-hours',
            icon: PieChart,
            color: 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300',
        },
    ];

    return (
        <AppLayout>
            <Head title="Operations Reports" />
            <PageHeader
                title="Reports & Analytics"
                description={`Operational reports across ${clientPlural.toLowerCase()}, staff, shifts, billing, and compliance.`}
                backHref="/operations"
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
