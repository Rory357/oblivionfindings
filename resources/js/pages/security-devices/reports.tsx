import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import {
    AlertOctagon,
    BarChart3,
    Download,
    HardDrive,
    Wrench,
} from 'lucide-react';

type Props = {
    stats: {
        devices: number;
        events_90d: number;
        maintenance: number;
    };
    windowDays: number;
};

export default function SecurityDevicesReports({ stats, windowDays }: Props) {
    const breadcrumbs = [
        { title: 'Security & Devices', href: '/security-devices' },
        { title: 'Reports', href: '/security-devices/reports' },
    ];

    const reports = [
        {
            id: 'devices',
            title: 'Device inventory',
            description:
                'Full device register with hardware descriptors, provider, status, health, firmware, and creation timestamps. One row per active device.',
            stat: `${stats.devices.toLocaleString()} devices`,
            href: '/security-devices/reports/devices.csv',
            icon: HardDrive,
        },
        {
            id: 'events',
            title: `Device events (${windowDays}d)`,
            description:
                'Rolling window of signals, alarms, state changes, and maintenance markers with severity and source. One row per event.',
            stat: `${stats.events_90d.toLocaleString()} events`,
            href: '/security-devices/reports/events.csv',
            icon: AlertOctagon,
        },
        {
            id: 'maintenance',
            title: 'Maintenance log',
            description:
                'Scheduled and completed service records across the estate, including technician, vendor reference, and informational cost. One row per record.',
            stat: `${stats.maintenance.toLocaleString()} records`,
            href: '/security-devices/reports/maintenance.csv',
            icon: Wrench,
        },
    ] as const;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Reports - Security & Devices" />
            <PageShell>
                <PageHero
                    icon={BarChart3}
                    title="Reports"
                    description="Stable CSV exports for inventory, events, and maintenance. A broader per-domain reporting surface lands with the dedicated Reporting module."
                    stats={[
                        { label: 'Devices', value: stats.devices },
                        { label: `Events (${windowDays}d)`, value: stats.events_90d },
                        { label: 'Maintenance', value: stats.maintenance },
                    ]}
                    actions={<Badge variant="outline" className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm">CSV exports</Badge>}
                />

                <div className="grid gap-4 lg:grid-cols-3">
                    {reports.map((r) => (
                        <Card key={r.id}>
                            <CardHeader className="space-y-3">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="rounded-lg border bg-primary/5 p-2 text-primary">
                                        <r.icon className="h-5 w-5" />
                                    </div>
                                    <Badge variant="secondary">{r.stat}</Badge>
                                </div>
                                <CardTitle className="text-lg">{r.title}</CardTitle>
                                <CardDescription>{r.description}</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <Button asChild className="w-full">
                                    <a href={r.href}>
                                        <Download className="mr-2 h-4 w-4" />
                                        Download CSV
                                    </a>
                                </Button>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Notes</CardTitle>
                        <CardDescription>
                            How these exports behave and what they are and are not for.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-3 text-sm md:grid-cols-2">
                        <div className="rounded-xl border p-4">
                            <p className="font-medium">Tenant scope</p>
                            <p className="leading-6 text-muted-foreground">
                                Every export is filtered to the current
                                tenant. Users without the
                                <code className="mx-1 rounded bg-muted px-1 text-xs">
                                    securityDevices.reports.view
                                </code>
                                permission get a 403.
                            </p>
                        </div>
                        <div className="rounded-xl border p-4">
                            <p className="font-medium">Streaming</p>
                            <p className="leading-6 text-muted-foreground">
                                Files are streamed row-by-row via a cursor
                                query. Large tenants can export without
                                buffering the whole dataset in memory.
                            </p>
                        </div>
                        <div className="rounded-xl border p-4">
                            <p className="font-medium">Encoding</p>
                            <p className="leading-6 text-muted-foreground">
                                UTF-8 with a BOM so Excel opens NZ-format
                                names and te reo characters correctly.
                            </p>
                        </div>
                        <div className="rounded-xl border p-4">
                            <p className="font-medium">What's out of scope</p>
                            <p className="leading-6 text-muted-foreground">
                                No scheduling, no PDF, no pivot tables, no
                                per-site drill-downs. Build those in the
                                dedicated Reporting module, not here.
                            </p>
                        </div>
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
