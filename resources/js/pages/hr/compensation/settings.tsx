import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    CompensationHero,
    CompensationTabs,
    type CompensationHeroStats,
    type CompensationQuickAction,
} from '@/components/hr';
import { PageLayout } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { Download, Info, Plus } from 'lucide-react';

type BreadcrumbItem = { title: string; href: string };

type GlAccount = { key: string; account: string | null };

type Props = {
    settings: {
        mileage_rate_per_km: number;
        currency: string;
        gl_accounts: GlAccount[];
    };
    stats: CompensationHeroStats;
    can: { manage: boolean };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Compensation', href: '/hr/compensation/bands' },
    { title: 'Settings', href: '/hr/compensation/settings' },
];

const heroActions: CompensationQuickAction[] = [
    { label: 'New band', icon: Plus, href: '/hr/compensation/bands' },
    { label: 'Export', icon: Download, href: '/hr/compensation/bands/export' },
];

const humanise = (key: string) =>
    key.replace(/[_-]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

export default function CompensationSettings({ settings, stats }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Compensation & Benefits" />

            <PageLayout hero={<CompensationHero stats={stats} quickActions={heroActions} />}>
                <CompensationTabs active="settings" />

                <div className="rounded-lg border border-primary/35 bg-primary/10 p-3 text-[13px] leading-relaxed text-foreground">
                    <span className="inline-flex items-center gap-2 font-semibold text-primary">
                        <Info className="h-4 w-4" /> Read-only
                    </span>{' '}
                    These values are configured in the application config (and, for the
                    mileage rate, consolidated with Operations & Fleet in a future pass).
                    They are shown here so payroll and finance can see what every claim
                    surface uses.
                </div>

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Mileage reimbursement</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-baseline gap-2">
                                <span className="text-3xl font-bold tabular-nums">
                                    {new Intl.NumberFormat('en-NZ', {
                                        style: 'currency',
                                        currency: settings.currency,
                                        minimumFractionDigits: 2,
                                    }).format(settings.mileage_rate_per_km)}
                                </span>
                                <span className="text-sm text-muted-foreground">per km</span>
                            </div>
                            <p className="mt-2 text-sm text-muted-foreground">
                                IRD-aligned rate read by every expense claim wizard
                                (mileage = distance × rate). The claim form never
                                hard-codes this number.
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">GL account map</CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Event</TableHead>
                                        <TableHead className="text-right">Debit account</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {settings.gl_accounts.length > 0 ? (
                                        settings.gl_accounts.map((a) => (
                                            <TableRow key={a.key}>
                                                <TableCell className="text-sm">{humanise(a.key)}</TableCell>
                                                <TableCell className="text-right text-sm tabular-nums text-muted-foreground">
                                                    {a.account ?? '—'}
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    ) : (
                                        <TableRow>
                                            <TableCell colSpan={2} className="py-6 text-center text-sm text-muted-foreground">
                                                No GL accounts configured.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
