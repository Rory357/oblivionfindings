import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Car,
    CheckCircle2,
    ClipboardList,
    Map,
    Route,
    Truck,
} from 'lucide-react';

type Props = {
    assigned_vehicle: {
        id: number;
        name: string;
        asset_tag: string;
        status: string;
    } | null;
    today_bookings_count: number;
    today_checks_count: number;
    auth_user: { id: number; name: string };
    can: {
        start_inspection: boolean;
    };
};

export default function MobileDashboard({
    assigned_vehicle,
    today_bookings_count,
    today_checks_count,
    auth_user,
    can,
}: Props) {
    const safeBookingsCount = today_bookings_count ?? 0;
    const safeChecksCount = today_checks_count ?? 0;
    const quickActions = [
        {
            label: 'Daily Vehicle Check',
            href: '/fleet-assets/daily-check',
            icon: CheckCircle2,
            color: 'bg-status-success',
            show: true,
        },
        {
            label: 'Start Inspection',
            href: '/fleet-assets/inspections/create',
            icon: ClipboardList,
            color: 'bg-status-info',
            show: can.start_inspection,
        },
        {
            label: 'Log Transport',
            href: '/fleet-assets/transports/create',
            icon: Truck,
            color: 'bg-status-warning',
            show: true,
        },
        {
            label: 'Live Map',
            href: '/fleet-assets/map',
            icon: Map,
            color: 'bg-status-info',
            show: true,
        },
        {
            label: 'Report Incident',
            href: '/fleet-assets/incidents/create',
            icon: AlertTriangle,
            color: 'bg-status-critical',
            show: true,
        },
        {
            label: 'My Trips',
            href: '/fleet-assets/trips',
            icon: Route,
            color: 'bg-primary',
            show: true,
        },
    ].filter((action) => action.show);

    return (
        <>
            <Head title="Mobile Dashboard" />
            <div className="min-h-screen bg-background">
                {/* Explicitly compact mobile Fleet hero: no desktop command-centre chrome. */}
                <div
                    data-fleet-mobile-hero
                    className="bg-gradient-to-br from-primary/90 via-primary to-primary/80 px-4 pb-6 pt-8 text-primary-foreground"
                >
                    <p
                        className="text-xs font-semibold uppercase tracking-[0.2em] text-primary-foreground/75"
                        dusk="fleet-mobile-dashboard-heading"
                    >
                        Mobile Dashboard
                    </p>
                    <p className="text-sm font-medium text-primary-foreground/75">Welcome back,</p>
                    <h1 className="text-2xl font-bold">{auth_user?.name ?? 'Driver'}</h1>
                    <p className="mt-1 text-xs text-primary-foreground/75">Oblivion Findings Fleet</p>
                </div>

                <div className="mx-auto max-w-lg space-y-4 px-4 -mt-3">
                    {/* Current Status Card */}
                    <Card className="shadow-lg">
                        <CardContent className="p-4 space-y-3">
                            <div className="flex items-center justify-between">
                                <span className="text-sm font-medium text-muted-foreground">Assigned Vehicle</span>
                                {assigned_vehicle ? (
                                    <Badge variant="default" className="bg-primary">Active</Badge>
                                ) : (
                                    <Badge variant="secondary">None</Badge>
                                )}
                            </div>
                            {assigned_vehicle ? (
                                <Link
                                    href={`/fleet-assets/vehicles/${assigned_vehicle.id}`}
                                    className="flex items-center gap-3 rounded-lg border bg-muted/30 p-3 transition-colors hover:bg-muted/50"
                                >
                                    <Car className="h-8 w-8 text-primary" />
                                    <div>
                                        <p className="font-semibold">{assigned_vehicle.name}</p>
                                        <p className="text-xs text-muted-foreground">{assigned_vehicle.asset_tag}</p>
                                    </div>
                                </Link>
                            ) : (
                                <p className="text-sm text-muted-foreground">No vehicle currently assigned.</p>
                            )}

                            <div className="grid grid-cols-2 gap-3">
                                <div className="rounded-lg border p-3 text-center">
                                    <p className="text-2xl font-bold text-primary">{safeBookingsCount}</p>
                                    <p className="text-[10px] text-muted-foreground">Today's Bookings</p>
                                </div>
                                <div className="rounded-lg border p-3 text-center">
                                    <p className="text-2xl font-bold text-primary">{safeChecksCount}</p>
                                    <p className="text-[10px] text-muted-foreground">Checks Completed</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Quick Actions */}
                    <div data-fleet-mobile-list className="space-y-2">
                        {quickActions.map((action) => {
                            const IconComp = action.icon;
                            return (
                                <Link
                                    key={action.href}
                                    href={action.href}
                                    className="flex w-full items-center gap-4 rounded-xl border bg-card p-4 shadow-sm transition-all hover:shadow-md active:scale-[0.98]"
                                    style={{ minHeight: 60 }}
                                >
                                    <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg ${action.color} text-white`}>
                                        <IconComp className="h-5 w-5" />
                                    </div>
                                    <span className="text-sm font-semibold">{action.label}</span>
                                </Link>
                            );
                        })}
                    </div>

                    {/* Back to full dashboard */}
                    <div className="pb-8 pt-2 text-center">
                        <Link
                            href="/fleet-assets"
                            className="text-xs text-muted-foreground underline hover:text-foreground"
                        >
                            Open Full Dashboard
                        </Link>
                    </div>
                </div>
            </div>
        </>
    );
}
