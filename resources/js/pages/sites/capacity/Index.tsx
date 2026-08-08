import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { BarChart3, Clock, Gauge, Home, UserPlus, Users } from 'lucide-react';

type Room = {
    id: number;
    name: string;
    is_active: boolean;
    assigned_client?: {
        id: number;
        first_name: string;
        last_name: string;
    } | null;
};

type SiteLite = {
    id: number;
    name: string;
    type: string;
    total_capacity: number | null;
    current_occupancy: number | null;
    waitlist_count: number | null;
};

type Props = {
    site: SiteLite;
    rooms?: Room[];
};

function DonutChart({
    percentage,
    size = 120,
}: {
    percentage: number;
    size?: number;
}) {
    const strokeWidth = 10;
    const radius = (size - strokeWidth) / 2;
    const circumference = 2 * Math.PI * radius;
    const offset = circumference - (percentage / 100) * circumference;
    const center = size / 2;

    const color =
        percentage >= 90
            ? 'text-status-critical'
            : percentage >= 70
              ? 'text-status-warning'
              : 'text-status-success';

    return (
        <div className="relative inline-flex items-center justify-center">
            <svg width={size} height={size} className="-rotate-90">
                <circle
                    cx={center}
                    cy={center}
                    r={radius}
                    fill="none"
                    stroke="currentColor"
                    strokeWidth={strokeWidth}
                    className="text-foreground"
                />
                <circle
                    cx={center}
                    cy={center}
                    r={radius}
                    fill="none"
                    stroke="currentColor"
                    strokeWidth={strokeWidth}
                    strokeDasharray={circumference}
                    strokeDashoffset={offset}
                    strokeLinecap="round"
                    className={color}
                />
            </svg>
            <div className="absolute inset-0 flex flex-col items-center justify-center">
                <span className="text-2xl font-bold">{percentage}%</span>
                <span className="text-xs text-muted-foreground">occupied</span>
            </div>
        </div>
    );
}

export default function CapacityIndex({ site, rooms = [] }: Props) {
    const totalCapacity = site.total_capacity ?? 0;
    const currentOccupancy = site.current_occupancy ?? 0;
    const waitlistCount = site.waitlist_count ?? 0;
    const availableSpaces = Math.max(0, totalCapacity - currentOccupancy);
    const occupancyPercent =
        totalCapacity > 0
            ? Math.round((currentOccupancy / totalCapacity) * 100)
            : 0;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Sites', href: '/sites' },
                { title: site.name, href: `/sites/${site.id}` },
                {
                    title: 'Capacity & Occupancy',
                    href: `/sites/${site.id}/capacity`,
                },
            ]}
        >
            <Head title={`${site.name} — Capacity & Occupancy`} />

            <PageShell>
                <PageHero
                    icon={Gauge}
                    backHref={`/sites/${site.id}`}
                    backLabel="Back to site"
                    title={`${site.name} — Capacity & Occupancy`}
                    description="Monitor room assignments, occupancy levels, and waitlist status"
                    stats={[
                        { label: 'Capacity', value: totalCapacity || '—' },
                        { label: 'Occupied', value: currentOccupancy },
                        { label: 'Available', value: availableSpaces },
                        { label: 'Waitlist', value: waitlistCount },
                    ]}
                />

                {/* Stats Row */}
                <div className="grid gap-4 md:grid-cols-4">
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-status-info p-2">
                                    <Home className="h-5 w-5 text-status-info" />
                                </div>
                                <div>
                                    <div className="text-sm text-muted-foreground">
                                        Total Capacity
                                    </div>
                                    <div className="text-2xl font-bold">
                                        {totalCapacity || '—'}
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-status-success p-2">
                                    <Users className="h-5 w-5 text-status-success" />
                                </div>
                                <div>
                                    <div className="text-sm text-muted-foreground">
                                        Current Occupancy
                                    </div>
                                    <div className="text-2xl font-bold">
                                        {currentOccupancy}
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-primary/10 p-2">
                                    <UserPlus className="h-5 w-5 text-primary" />
                                </div>
                                <div>
                                    <div className="text-sm text-muted-foreground">
                                        Available Spaces
                                    </div>
                                    <div className="text-2xl font-bold">
                                        {availableSpaces}
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-status-warning p-2">
                                    <Clock className="h-5 w-5 text-status-warning" />
                                </div>
                                <div>
                                    <div className="text-sm text-muted-foreground">
                                        Waitlist
                                    </div>
                                    <div className="text-2xl font-bold">
                                        {waitlistCount}
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    {/* Occupancy Donut */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Occupancy Rate</CardTitle>
                        </CardHeader>
                        <CardContent className="flex items-center justify-center py-6">
                            {totalCapacity > 0 ? (
                                <DonutChart
                                    percentage={occupancyPercent}
                                    size={160}
                                />
                            ) : (
                                <div className="text-center text-muted-foreground">
                                    <BarChart3 className="mx-auto mb-2 h-12 w-12 opacity-50" />
                                    <p className="text-sm">
                                        Set total capacity to see occupancy rate
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Room List */}
                    <Card className="lg:col-span-2">
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle>Rooms</CardTitle>
                            <Badge variant="outline">
                                {rooms.filter((r) => !r.assigned_client).length}{' '}
                                vacant
                            </Badge>
                        </CardHeader>
                        <CardContent>
                            {rooms.length === 0 ? (
                                <div className="py-8 text-center text-muted-foreground">
                                    <Home className="mx-auto mb-2 h-10 w-10 opacity-50" />
                                    <p className="text-sm">
                                        No rooms configured for this site
                                    </p>
                                    <Button
                                        asChild
                                        variant="outline"
                                        size="sm"
                                        className="mt-3"
                                    >
                                        <Link href={`/sites/${site.id}/rooms`}>
                                            Manage Rooms
                                        </Link>
                                    </Button>
                                </div>
                            ) : (
                                <div className="space-y-2">
                                    {rooms.map((room) => (
                                        <div
                                            key={room.id}
                                            className="flex items-center justify-between rounded-lg border p-3"
                                        >
                                            <div className="flex items-center gap-3">
                                                <Home className="h-4 w-4 text-muted-foreground" />
                                                <div>
                                                    <div className="text-sm font-medium">
                                                        {room.name}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {room.assigned_client
                                                            ? `${room.assigned_client.first_name} ${room.assigned_client.last_name}`
                                                            : 'Vacant'}
                                                    </div>
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                {room.assigned_client ? (
                                                    <Badge
                                                        variant="outline"
                                                        className="border-status-success/30 bg-status-success text-status-success"
                                                    >
                                                        Occupied
                                                    </Badge>
                                                ) : (
                                                    <>
                                                        <Badge
                                                            variant="outline"
                                                            className="border-border/30 text-muted-foreground"
                                                        >
                                                            Vacant
                                                        </Badge>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            className="text-xs"
                                                            asChild
                                                        >
                                                            <Link
                                                                href={`/sites/${site.id}/rooms`}
                                                            >
                                                                Assign Client
                                                            </Link>
                                                        </Button>
                                                    </>
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Occupancy Trend placeholder */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <BarChart3 className="h-5 w-5" />
                            Occupancy Trend
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="flex items-center justify-center py-12 text-muted-foreground">
                            <div className="text-center">
                                <BarChart3 className="mx-auto mb-3 h-16 w-16 opacity-30" />
                                <p className="text-sm">
                                    Occupancy trend chart coming soon
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Historical occupancy data will be displayed
                                    here
                                </p>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
