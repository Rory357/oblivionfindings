import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    BookOpen,
    ClipboardCheck,
    FileBarChart,
    Lock,
    Package,
    Pill,
    Scroll,
    Shield,
} from 'lucide-react';

const modules = [
    { title: 'Daily Overview', description: 'View today\'s medication rounds and administration status.', href: '/emar/daily', icon: Activity, color: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300', ready: true },
    { title: 'MAR Charts', description: 'Medication Administration Record charts for each client.', href: '/emar/mar', icon: ClipboardCheck, color: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300', ready: false },
    { title: 'PRN Records', description: 'As-needed medication administration records.', href: '/emar/prn', icon: BookOpen, color: 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300', ready: false },
    { title: 'Controlled Drugs', description: 'Controlled substance registers and discrepancy tracking.', href: '/emar/controlled', icon: Lock, color: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300', ready: false },
    { title: 'Emergency Access', description: 'Break-glass emergency medication access.', href: '/emar/emergency-access', icon: AlertTriangle, color: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300', ready: true },
    { title: 'Medications', description: 'Central medication database and prescriptions.', href: '/emar/medications', icon: Pill, color: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300', ready: false },
    { title: 'Stock Management', description: 'Track medication stock levels and ordering.', href: '/emar/stock', icon: Package, color: 'bg-cyan-100 text-cyan-700 dark:bg-cyan-900/40 dark:text-cyan-300', ready: false },
    { title: 'Prescriptions', description: 'Manage prescriptions and pharmacy integrations.', href: '/emar/prescriptions', icon: Scroll, color: 'bg-teal-100 text-teal-700 dark:bg-teal-900/40 dark:text-teal-300', ready: false },
    { title: 'Audit Trail', description: 'Full audit log of all medication events.', href: '/emar/audit', icon: Shield, color: 'bg-slate-100 text-slate-700 dark:bg-slate-800/40 dark:text-slate-300', ready: true },
    { title: 'Reports', description: 'Medication compliance and administration reports.', href: '/emar/reports', icon: FileBarChart, color: 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300', ready: true },
    { title: 'Competency', description: 'Staff medication competency records and assessments.', href: '/emar/competency', icon: ClipboardCheck, color: 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300', ready: false },
];

export default function EmarDashboard() {
    return (
        <AppLayout>
            <Head title="eMAR" />
            <PageHeader
                title="eMAR — Electronic Medication Administration"
                description="Manage medication administration records, controlled substances, and compliance."
            />
            <PageShell>
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {modules.map((mod) => {
                        const Icon = mod.icon;
                        return (
                            <Link key={mod.href} href={mod.href} className="block">
                                <Card className="h-full transition-all hover:border-border hover:shadow-md hover:-translate-y-0.5">
                                    <CardContent className="p-4">
                                        <div className="flex items-start gap-3">
                                            <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${mod.color}`}>
                                                <Icon className="h-5 w-5" />
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-center gap-2">
                                                    <h3 className="text-sm font-semibold">{mod.title}</h3>
                                                    {!mod.ready && (
                                                        <Badge variant="outline" className="h-4 px-1.5 text-[9px]">
                                                            Coming Soon
                                                        </Badge>
                                                    )}
                                                </div>
                                                <p className="mt-0.5 text-xs text-muted-foreground">{mod.description}</p>
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
