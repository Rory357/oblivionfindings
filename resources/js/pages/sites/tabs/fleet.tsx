import { HorizontalBarChart } from '@/components/fleet-charts';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowUpRight,
    CalendarDays,
    Car,
    Fuel,
    MapPin,
    Route,
} from 'lucide-react';
import { formatRegisterDate, registerLabel } from './safety-register';

type FleetVehicle = {
    id: number;
    name: string;
    asset_tag?: string | null;
    status?: string | null;
    fleet_status?: string | null;
    speed_kph?: number | null;
    last_seen_at?: string | null;
    consent_blocked: boolean;
    wof_expires_at?: string | null;
    registration_expires_at?: string | null;
    href: string;
};

type FleetActivity = {
    id: number;
    vehicle?: { id: number; name: string } | null;
    status: string;
    href: string;
};

export type SiteFleetData = {
    locked?: boolean;
    vehicles: FleetVehicle[];
    today_bookings: Array<
        FleetActivity & {
            booked_by?: string | null;
            purpose?: string | null;
            starts_at?: string | null;
            ends_at?: string | null;
        }
    >;
    active_outings: Array<
        FleetActivity & {
            title: string;
            destination?: string | null;
            driver?: { id: number; name: string } | null;
            planned_departure?: string | null;
            residents_count: number;
        }
    >;
    stats: {
        trips_this_month: number;
        distance_this_month: number;
        fuel_cost_this_month: number;
        incidents_this_month: number;
    };
    compliance: Array<{
        vehicle_name: string;
        vehicle_id: number;
        items: Array<{
            type: string;
            expires_at: string;
            days_remaining: number;
            status: string;
        }>;
    }>;
    href: string;
};

const currency = new Intl.NumberFormat('en-NZ', {
    style: 'currency',
    currency: 'NZD',
});

export function SiteProfileFleet({ data }: { data: SiteFleetData }) {
    const online = data.vehicles.filter(
        (vehicle) => vehicle.fleet_status === 'online',
    ).length;

    return (
        <div className="space-y-5">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 className="text-lg font-semibold">Site Fleet</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Vehicles, live status, telemetry consent, today&apos;s
                        bookings and outings, monthly activity and compliance.
                    </p>
                </div>
                <Button
                    asChild
                    size="sm"
                    variant="outline"
                    className="min-h-11"
                >
                    <Link href={data.href}>
                        Open Fleet dashboard
                        <ArrowUpRight className="ml-1.5 h-4 w-4" />
                    </Link>
                </Button>
            </div>

            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                {[
                    {
                        label: 'Trips this month',
                        value: data.stats.trips_this_month,
                        icon: Route,
                    },
                    {
                        label: 'Distance',
                        value: `${data.stats.distance_this_month} km`,
                        icon: MapPin,
                    },
                    {
                        label: 'Fuel cost',
                        value: currency.format(data.stats.fuel_cost_this_month),
                        icon: Fuel,
                    },
                    {
                        label: 'Incidents',
                        value: data.stats.incidents_this_month,
                        icon: AlertTriangle,
                    },
                ].map((stat) => (
                    <Card key={stat.label}>
                        <CardContent className="flex items-center gap-3 p-4">
                            <stat.icon className="h-5 w-5 text-primary" />
                            <div>
                                <div className="text-lg font-semibold">
                                    {stat.value}
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    {stat.label}
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                ))}
            </div>

            <div className="grid gap-5 xl:grid-cols-[minmax(0,1.4fr)_minmax(280px,0.6fr)]">
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0">
                        <CardTitle className="text-base">Vehicles</CardTitle>
                        <Badge variant="secondary">
                            {online}/{data.vehicles.length} online
                        </Badge>
                    </CardHeader>
                    <CardContent>
                        {data.vehicles.length ? (
                            <div className="divide-y rounded-xl border">
                                {data.vehicles.map((vehicle) => (
                                    <Link
                                        key={vehicle.id}
                                        href={vehicle.href}
                                        className="grid gap-3 p-4 transition-colors hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none md:grid-cols-[minmax(0,1fr)_auto]"
                                    >
                                        <div className="flex min-w-0 items-start gap-3">
                                            <span
                                                className={`mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full ${vehicle.fleet_status === 'online' ? 'bg-status-success' : 'bg-muted-foreground/40'}`}
                                            />
                                            <div>
                                                <div className="font-medium">
                                                    {vehicle.name}
                                                </div>
                                                <div className="mt-1 text-xs text-muted-foreground">
                                                    {vehicle.asset_tag ??
                                                        'No asset tag'}{' '}
                                                    ·{' '}
                                                    {registerLabel(
                                                        vehicle.status,
                                                    )}
                                                </div>
                                                <div className="mt-2 flex flex-wrap gap-2">
                                                    {vehicle.consent_blocked ? (
                                                        <Badge variant="outline">
                                                            Location hidden by
                                                            consent
                                                        </Badge>
                                                    ) : vehicle.last_seen_at ? (
                                                        <Badge variant="outline">
                                                            Seen{' '}
                                                            {formatRegisterDate(
                                                                vehicle.last_seen_at,
                                                            )}
                                                        </Badge>
                                                    ) : null}
                                                    {vehicle.speed_kph ? (
                                                        <Badge variant="secondary">
                                                            {vehicle.speed_kph}{' '}
                                                            km/h
                                                        </Badge>
                                                    ) : null}
                                                </div>
                                            </div>
                                        </div>
                                        <div className="text-sm text-muted-foreground md:text-right">
                                            <div>
                                                WOF{' '}
                                                {formatRegisterDate(
                                                    vehicle.wof_expires_at,
                                                )}
                                            </div>
                                            <div>
                                                Registration{' '}
                                                {formatRegisterDate(
                                                    vehicle.registration_expires_at,
                                                )}
                                            </div>
                                        </div>
                                    </Link>
                                ))}
                            </div>
                        ) : (
                            <div className="rounded-xl border border-dashed py-10 text-center text-sm text-muted-foreground">
                                <Car className="mx-auto mb-2 h-8 w-8 opacity-40" />
                                No vehicles are assigned to this Site.
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Monthly activity
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <HorizontalBarChart
                            items={[
                                {
                                    label: 'Trips',
                                    value: data.stats.trips_this_month,
                                },
                                {
                                    label: 'Distance (km)',
                                    value: data.stats.distance_this_month,
                                },
                                {
                                    label: 'Fuel cost ($)',
                                    value: data.stats.fuel_cost_this_month,
                                },
                                {
                                    label: 'Incidents',
                                    value: data.stats.incidents_this_month,
                                },
                            ]}
                        />
                    </CardContent>
                </Card>
            </div>

            <div className="grid gap-5 xl:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <CalendarDays className="h-4 w-4" /> Today&apos;s
                            activity
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {[...data.today_bookings, ...data.active_outings]
                            .length ? (
                            <>
                                {data.today_bookings.map((booking) => (
                                    <Link
                                        key={`booking-${booking.id}`}
                                        href={booking.href}
                                        className="flex items-start justify-between gap-3 rounded-lg border p-3 hover:bg-muted/40"
                                    >
                                        <div>
                                            <div className="font-medium">
                                                {booking.vehicle?.name ??
                                                    'Vehicle booking'}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {booking.purpose ??
                                                    'No purpose'}
                                                {booking.booked_by
                                                    ? ` · ${booking.booked_by}`
                                                    : ''}
                                            </div>
                                        </div>
                                        <Badge variant="outline">
                                            {registerLabel(booking.status)}
                                        </Badge>
                                    </Link>
                                ))}
                                {data.active_outings.map((outing) => (
                                    <Link
                                        key={`outing-${outing.id}`}
                                        href={outing.href}
                                        className="flex items-start justify-between gap-3 rounded-lg border p-3 hover:bg-muted/40"
                                    >
                                        <div>
                                            <div className="font-medium">
                                                {outing.title}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {outing.destination ??
                                                    'Destination not recorded'}{' '}
                                                · {outing.residents_count}{' '}
                                                residents
                                            </div>
                                        </div>
                                        <Badge variant="outline">
                                            {registerLabel(outing.status)}
                                        </Badge>
                                    </Link>
                                ))}
                            </>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                No active bookings or outings today.
                            </p>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <AlertTriangle className="h-4 w-4" /> Compliance
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {data.compliance.length ? (
                            data.compliance.map((vehicle) => (
                                <div
                                    key={vehicle.vehicle_id}
                                    className="rounded-lg border p-3"
                                >
                                    <div className="font-medium">
                                        {vehicle.vehicle_name}
                                    </div>
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        {vehicle.items.map((item) => (
                                            <Badge
                                                key={`${vehicle.vehicle_id}-${item.type}`}
                                                variant={
                                                    item.status === 'expired'
                                                        ? 'destructive'
                                                        : 'outline'
                                                }
                                            >
                                                {item.type}:{' '}
                                                {item.status === 'expired'
                                                    ? 'Expired'
                                                    : `${item.days_remaining} days`}
                                            </Badge>
                                        ))}
                                    </div>
                                </div>
                            ))
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                No WOF or registration warnings in the next 90
                                days.
                            </p>
                        )}
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}
