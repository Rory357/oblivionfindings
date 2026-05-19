import LeafletMap from '@/components/leaflet-map';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

export default function FleetManagementIndex({ vehicles }) {
    const [searchTerm, setSearchTerm] = useState('');
    const [statusFilter, setStatusFilter] = useState('all');

    useEffect(() => {
        const interval = window.setInterval(() => {
            if (document.hidden) return;
            router.reload({ only: ['vehicles'] });
        }, 30000);

        return () => window.clearInterval(interval);
    }, []);

    const filteredVehicles = useMemo(() => {
        const term = searchTerm.trim().toLowerCase();
        return (vehicles ?? []).filter((vehicle) => {
            const name =
                (vehicle.name ?? vehicle.asset_tag ?? `vehicle ${vehicle.id}`)
                    .toLowerCase();
            const matchesTerm = term ? name.includes(term) : true;
            const status = vehicle.state?.status ?? 'offline';
            const consentBlocked = vehicle.state?.consent_blocked;

            if (statusFilter === 'online' && status !== 'online') {
                return false;
            }
            if (statusFilter === 'offline' && status === 'online') {
                return false;
            }
            if (statusFilter === 'consent_blocked' && !consentBlocked) {
                return false;
            }

            return matchesTerm;
        });
    }, [vehicles, searchTerm, statusFilter]);

    const markers = (filteredVehicles ?? [])
        .filter((v) => v.state?.lat && v.state?.lng)
        .map((v) => ({
            id: v.id,
            lat: Number(v.state.lat),
            lng: Number(v.state.lng),
            title: v.name ?? v.asset_tag ?? `Vehicle ${v.id}`,
        }));

    const center =
        markers.length > 0
            ? { lat: markers[0].lat, lng: markers[0].lng }
            : { lat: -36.8485, lng: 174.7633 };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet Management', href: '/fleet-management' },
            ]}
        >
            <Head title="Fleet Management" />
            <PageShell>
                <PageHero variant="compact"
                    title="Fleet Management"
                    description="Live vehicle status, trip activity, and signals."
                />

                <div className="grid gap-4 lg:grid-cols-[2fr,1fr]">
                    <div className="space-y-3">
                        <LeafletMap
                            center={center}
                            zoom={12}
                            markers={markers.map(m => ({ ...m, type: 'vehicle' as const, status: 'online' }))}
                            height={420}
                        />

                        <div className="rounded-md border p-4">
                            <div className="mb-3 text-sm font-medium">
                                Live vehicles
                            </div>
                            <div className="grid gap-3">
                                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <Input
                                        placeholder="Search vehicles..."
                                        value={searchTerm}
                                        onChange={(event) =>
                                            setSearchTerm(event.target.value)
                                        }
                                        className="sm:max-w-xs"
                                    />
                                    <Select
                                        value={statusFilter}
                                        onValueChange={setStatusFilter}
                                    >
                                        <SelectTrigger className="sm:w-44">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">
                                                All statuses
                                            </SelectItem>
                                            <SelectItem value="online">
                                                Online
                                            </SelectItem>
                                            <SelectItem value="offline">
                                                Offline
                                            </SelectItem>
                                            <SelectItem value="consent_blocked">
                                                Consent blocked
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                {filteredVehicles?.length ? (
                                    filteredVehicles.map((vehicle) => (
                                        <div
                                            key={vehicle.id}
                                            className="flex flex-col gap-3 rounded-md border p-3 sm:flex-row sm:items-center sm:justify-between"
                                        >
                                            <div>
                                                <div className="text-sm font-semibold">
                                                    {vehicle.name ??
                                                        vehicle.asset_tag ??
                                                        `Vehicle ${vehicle.id}`}
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    Last seen:{' '}
                                                    {vehicle.state
                                                        ?.last_seen_at ??
                                                        '—'}
                                                </div>
                                                <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                                    <Badge
                                                        variant={
                                                            vehicle.state
                                                                ?.status ===
                                                            'online'
                                                                ? 'default'
                                                                : 'secondary'
                                                        }
                                                    >
                                                        {vehicle.state
                                                            ?.status ??
                                                            'offline'}
                                                    </Badge>
                                                    {vehicle.last_signal ? (
                                                        <span>
                                                            Signal:{' '}
                                                            {
                                                                vehicle
                                                                    .last_signal
                                                                    .type
                                                            }
                                                        </span>
                                                    ) : null}
                                                    {vehicle.state
                                                        ?.speed_kph ? (
                                                        <span>
                                                            {vehicle.state.speed_kph ??
                                                                0}{' '}
                                                            kph
                                                        </span>
                                                    ) : null}
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <Link
                                                        href={`/fleet/vehicles/${vehicle.id}`}
                                                    >
                                                        View
                                                    </Link>
                                                </Button>
                                            </div>
                                        </div>
                                    ))
                                ) : (
                                    <div className="text-sm text-muted-foreground">
                                        No vehicles match your filters.
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>

                    <div className="space-y-3">
                        <div className="rounded-md border p-4">
                            <div className="text-sm font-medium">
                                Operational summary
                            </div>
                            <div className="mt-3 space-y-2 text-sm text-muted-foreground">
                                <div>
                                    Vehicles tracked: {vehicles?.length ?? 0}
                                </div>
                                <div>
                                    Active trips:{' '}
                                    {vehicles?.reduce(
                                        (sum, v) =>
                                            sum + (v.open_trip_count ?? 0),
                                        0,
                                    ) ?? 0}
                                </div>
                                <div>
                                    Consent blocked:{' '}
                                    {vehicles?.filter(
                                        (v) => v.state?.consent_blocked,
                                    ).length ?? 0}
                                </div>
                            </div>
                        </div>
                        <div className="rounded-md border p-4">
                            <div className="text-sm font-medium mb-2">Quick Links</div>
                            <div className="space-y-2 text-sm">
                                <div>
                                    <Link
                                        href="/fleet/fuel"
                                        className="text-primary hover:underline"
                                    >
                                        Fuel Logs
                                    </Link>
                                </div>
                                <div>
                                    <Link
                                        href="/fleet/reports"
                                        className="text-primary hover:underline"
                                    >
                                        Fleet Reports
                                    </Link>
                                </div>
                                <div>
                                    <Link
                                        href="/fleet-management/maps-usage"
                                        className="text-primary hover:underline"
                                    >
                                        Map Usage
                                    </Link>
                                </div>
                            </div>
                        </div>
                        <div className="rounded-md border p-4 text-sm text-muted-foreground">
                            Signals are emitted to Control Room for triage and
                            escalation. Fleet does not create alerts.
                        </div>
                    </div>
                </div>
            </PageShell>
        </AppLayout>
    );
}
