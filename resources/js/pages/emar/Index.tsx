import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowRight,
    Award,
    BookOpen,
    CalendarCheck,
    ClipboardCheck,
    Clock,
    FileBarChart,
    Lock,
    Package,
    Pill,
    Scroll,
    Shield,
    Trash2,
    User,
} from 'lucide-react';

const modules = [
    { title: 'Daily Overview', description: 'Today\'s medication rounds and administration status across all clients.', href: '/emar/daily', icon: Activity, color: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300', ready: true },
    { title: 'MAR Charts', description: 'Medication Administration Record charts by client with date navigation.', href: '/emar/mar', icon: ClipboardCheck, color: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300', ready: true },
    { title: 'Medication Rounds', description: 'Round management, staff assignment, progress tracking, and completion.', href: '/emar/rounds', icon: Clock, color: 'bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300', ready: true },
    { title: 'PRN Records', description: 'As-needed medication records, effectiveness reviews, and limit tracking.', href: '/emar/prn', icon: BookOpen, color: 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300', ready: true },
    { title: 'Controlled Drugs', description: 'Controlled substance registers, balance tracking, discrepancies, and destructions.', href: '/emar/controlled', icon: Lock, color: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300', ready: true },
    { title: 'Medications', description: 'Central medication database with search, filtering, and status tracking.', href: '/emar/medications', icon: Pill, color: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300', ready: true },
    { title: 'Stock Management', description: 'Track medication stock levels, low stock alerts, and pharmacy orders.', href: '/emar/stock', icon: Package, color: 'bg-cyan-100 text-cyan-700 dark:bg-cyan-900/40 dark:text-cyan-300', ready: true },
    { title: 'Prescriptions', description: 'Prescriber orders, verbal/telephone orders, countersignatures, and covert authorisations.', href: '/emar/prescriptions', icon: Scroll, color: 'bg-teal-100 text-teal-700 dark:bg-teal-900/40 dark:text-teal-300', ready: true },
    { title: 'Medication Reviews', description: 'Schedule and track routine, triggered, and comprehensive medication reviews.', href: '/emar/reviews', icon: CalendarCheck, color: 'bg-lime-100 text-lime-700 dark:bg-lime-900/40 dark:text-lime-300', ready: true },
    { title: 'Competency', description: 'Staff medication competency assessments, certifications, and renewals.', href: '/emar/competency', icon: Award, color: 'bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300', ready: true },
    { title: 'Self-Administration', description: 'Client capacity assessments per NZ MOH medication support categories.', href: '/emar/self-admin', icon: User, color: 'bg-pink-100 text-pink-700 dark:bg-pink-900/40 dark:text-pink-300', ready: true },
    { title: 'Destructions', description: 'Medication destruction and disposal records with dual-witness verification.', href: '/emar/destructions', icon: Trash2, color: 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300', ready: true },
    { title: 'Handovers', description: 'Shift handover records with controlled drug counts and outstanding items.', href: '/emar/handovers', icon: ArrowRight, color: 'bg-fuchsia-100 text-fuchsia-700 dark:bg-fuchsia-900/40 dark:text-fuchsia-300', ready: true },
    { title: 'Emergency Access', description: 'Break-glass emergency medication access with full audit trail.', href: '/emar/emergency-access', icon: AlertTriangle, color: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300', ready: true },
    { title: 'Audit Trail', description: 'Full audit log of all medication events, changes, and access.', href: '/emar/audit', icon: Shield, color: 'bg-slate-100 text-slate-700 dark:bg-slate-800/40 dark:text-slate-300', ready: true },
    { title: 'Reports', description: 'Medication compliance, administration, PRN usage, and incident reports.', href: '/emar/reports', icon: FileBarChart, color: 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300', ready: true },
];

export default function EmarDashboard() {
    return (
        <AppLayout>
            <Head title="eMAR" />
            <PageHeader
                title="eMAR — Electronic Medication Administration"
                description="Comprehensive electronic medication management for NZ residential care and supported living."
            />
            <PageShell>
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
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
