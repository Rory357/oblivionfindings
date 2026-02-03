import FleetMap from '@/components/fleet-map';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export default function FleetTrip({ trip }) {
    const { fleet } = usePage().props as any;
    const apiKey = fleet?.maps?.apiKey;
    const [points, setPoints] = useState<{ lat: number; lng: number }[]>([]);

    useEffect(() => {
        fetch(`/fleet/trips/${trip.id}/playback`)
            .then((res) => res.json())
            .then((data) => {
                const rows = (data.points ?? [])
                    .filter((p) => p.lat && p.lng)
                    .map((p) => ({ lat: Number(p.lat), lng: Number(p.lng) }));
                setPoints(rows);
            })
            .catch(() => setPoints([]));
    }, [trip.id]);

    const center =
        points.length > 0
            ? { lat: points[0].lat, lng: points[0].lng }
            : { lat: -36.8485, lng: 174.7633 };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet Management', href: '/fleet-management' },
                { title: 'Trip', href: `/fleet/trips/${trip.id}` },
            ]}
        >
            <Head title={`Trip ${trip.id}`} />
            <PageShell>
                <PageHeader
                    title={`Trip ${trip.id}`}
                    description={`Distance: ${trip.distance_km} km`}
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={`/fleet/vehicles/${trip.asset_id}`}>
                                Back to Vehicle
                            </Link>
                        </Button>
                    }
                />

                {apiKey ? (
                    <FleetMap
                        apiKey={apiKey}
                        center={center}
                        zoom={12}
                        polyline={points}
                        height={480}
                        usageContext="trip"
                    />
                ) : (
                    <div className="rounded-md border bg-muted/30 p-6 text-sm text-muted-foreground">
                        Google Maps API key missing. Add GOOGLE_MAPS_API_KEY to
                        enable trip playback.
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
