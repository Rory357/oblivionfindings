import FleetMap from '@/components/fleet-map';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';

export default function FleetVehicle({
    asset,
    state,
    telemetry,
    signals,
    trips,
    geofences,
}) {
    const { fleet, auth } = usePage().props as any;
    const apiKey = fleet?.maps?.apiKey;
    const canManageGeofences = !!auth?.can?.assets?.geofencesManage;

    const geofenceForm = useForm({
        name: '',
        type: 'circle',
        breach_type: 'soft',
        shape: { lat: state?.lat ?? 0, lon: state?.lng ?? 0, radius_m: 200 },
        is_active: true,
    });

    const marker =
        state?.lat && state?.lng
            ? [
                  {
                      id: asset.id,
                      lat: Number(state.lat),
                      lng: Number(state.lng),
                      title: asset.name ?? asset.asset_tag ?? `Vehicle ${asset.id}`,
                  },
              ]
            : [];

    const center =
        marker.length > 0
            ? { lat: marker[0].lat, lng: marker[0].lng }
            : { lat: -36.8485, lng: 174.7633 };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet Management', href: '/fleet-management' },
                { title: asset.name ?? 'Vehicle', href: `/fleet/vehicles/${asset.id}` },
            ]}
        >
            <Head title={`Fleet • ${asset.name ?? 'Vehicle'}`} />
            <PageShell>
                <PageHeader
                    title={asset.name ?? 'Vehicle'}
                    description="Live status, signals, and recent trips."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/fleet-management">Back to Fleet</Link>
                        </Button>
                    }
                />

                <div className="grid gap-4 lg:grid-cols-[2fr,1fr]">
                    <div className="space-y-3">
                        {apiKey ? (
                            <FleetMap
                                apiKey={apiKey}
                                center={center}
                                zoom={14}
                                markers={marker}
                                height={420}
                                usageContext="vehicle"
                                usageAssetId={asset.id}
                            />
                        ) : (
                            <div className="rounded-md border bg-muted/30 p-6 text-sm text-muted-foreground">
                                Google Maps API key missing. Add
                                GOOGLE_MAPS_API_KEY to enable live maps.
                            </div>
                        )}

                        <div className="rounded-md border p-4">
                            <div className="mb-3 text-sm font-medium">
                                Recent telemetry
                            </div>
                            <div className="grid gap-2">
                                {telemetry?.length ? (
                                    telemetry.map((t) => (
                                        <div
                                            key={t.id}
                                            className="flex flex-col gap-1 rounded-md border p-2 text-xs text-muted-foreground"
                                        >
                                            <div className="flex items-center gap-2">
                                                <Badge variant="secondary">
                                                    {t.event_type ?? 'update'}
                                                </Badge>
                                                <span>{t.occurred_at}</span>
                                            </div>
                                            <div>
                                                {t.lat && t.lng
                                                    ? `${t.lat}, ${t.lng}`
                                                    : 'Location masked'}
                                            </div>
                                            <div>Speed: {t.speed_kph ?? 0} kph</div>
                                        </div>
                                    ))
                                ) : (
                                    <div className="text-sm text-muted-foreground">
                                        No telemetry yet.
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>

                    <div className="space-y-3">
                        <div className="rounded-md border p-4">
                            <div className="text-sm font-medium">Status</div>
                            <div className="mt-3 space-y-2 text-sm text-muted-foreground">
                                <div>
                                    Status:{' '}
                                    <Badge
                                        variant={
                                            state?.status === 'online'
                                                ? 'default'
                                                : 'secondary'
                                        }
                                    >
                                        {state?.status ?? 'offline'}
                                    </Badge>
                                </div>
                                <div>Last seen: {state?.last_seen_at ?? '—'}</div>
                                <div>Speed: {state?.speed_kph ?? 0} kph</div>
                                <div>
                                    Consent blocked:{' '}
                                    {state?.consent_blocked ? 'Yes' : 'No'}
                                </div>
                            </div>
                        </div>

                        <div className="rounded-md border p-4">
                            <div className="mb-3 text-sm font-medium">Signals</div>
                            <div className="grid gap-2">
                                {signals?.length ? (
                                    signals.map((s) => (
                                        <div
                                            key={s.id}
                                            className="flex items-center justify-between rounded-md border p-2 text-xs"
                                        >
                                            <div>
                                                <div className="font-medium">
                                                    {s.signal_type}
                                                </div>
                                                <div className="text-muted-foreground">
                                                    {s.occurred_at}
                                                </div>
                                            </div>
                                            <Badge variant="secondary">
                                                {s.severity}
                                            </Badge>
                                        </div>
                                    ))
                                ) : (
                                    <div className="text-sm text-muted-foreground">
                                        No signals.
                                    </div>
                                )}
                            </div>
                        </div>

                        <div className="rounded-md border p-4">
                            <div className="mb-3 text-sm font-medium">Trips</div>
                            <div className="grid gap-2">
                                {trips?.length ? (
                                    trips.map((trip) => (
                                        <div
                                            key={trip.id}
                                            className="flex items-center justify-between rounded-md border p-2 text-xs"
                                        >
                                            <div>
                                                <div className="font-medium">
                                                    {trip.started_at}
                                                </div>
                                                <div className="text-muted-foreground">
                                                    {trip.distance_km} km
                                                </div>
                                            </div>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                asChild
                                            >
                                                <Link
                                                    href={`/fleet/trips/${trip.id}`}
                                                >
                                                    View
                                                </Link>
                                            </Button>
                                        </div>
                                    ))
                                ) : (
                                    <div className="text-sm text-muted-foreground">
                                        No trips recorded.
                                    </div>
                                )}
                            </div>
                        </div>

                        <div className="rounded-md border p-4">
                            <div className="mb-3 text-sm font-medium">
                                Geofences
                            </div>
                            {geofences?.length ? (
                                <div className="mb-3 grid gap-2">
                                    {geofences.map((g) => (
                                        <div
                                            key={g.id}
                                            className="rounded-md border p-2 text-xs text-muted-foreground"
                                        >
                                            {g.name} • {g.type} •{' '}
                                            {g.breach_type} •{' '}
                                            {g.is_active ? 'active' : 'inactive'}
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <div className="mb-3 text-sm text-muted-foreground">
                                    No geofences configured.
                                </div>
                            )}

                            {canManageGeofences ? (
                                <form
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        geofenceForm.post(
                                            `/assets/${asset.id}/geofences`,
                                            {
                                                preserveScroll: true,
                                                onSuccess: () =>
                                                    geofenceForm.reset(
                                                        'name',
                                                    ),
                                            },
                                        );
                                    }}
                                    className="space-y-2"
                                >
                                    <input
                                        className="w-full rounded-md border px-3 py-2 text-xs"
                                        placeholder="Geofence name"
                                        value={geofenceForm.data.name}
                                        onChange={(e) =>
                                            geofenceForm.setData(
                                                'name',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <div className="grid grid-cols-2 gap-2">
                                        <input
                                            className="w-full rounded-md border px-3 py-2 text-xs"
                                            placeholder="Latitude"
                                            value={geofenceForm.data.shape.lat}
                                            onChange={(e) =>
                                                geofenceForm.setData('shape', {
                                                    ...geofenceForm.data.shape,
                                                    lat: Number(e.target.value),
                                                })
                                            }
                                        />
                                        <input
                                            className="w-full rounded-md border px-3 py-2 text-xs"
                                            placeholder="Longitude"
                                            value={geofenceForm.data.shape.lon}
                                            onChange={(e) =>
                                                geofenceForm.setData('shape', {
                                                    ...geofenceForm.data.shape,
                                                    lon: Number(e.target.value),
                                                })
                                            }
                                        />
                                    </div>
                                    <input
                                        className="w-full rounded-md border px-3 py-2 text-xs"
                                        placeholder="Radius (m)"
                                        value={geofenceForm.data.shape.radius_m}
                                        onChange={(e) =>
                                            geofenceForm.setData('shape', {
                                                ...geofenceForm.data.shape,
                                                radius_m: Number(
                                                    e.target.value,
                                                ),
                                            })
                                        }
                                    />
                                    <Button
                                        type="submit"
                                        size="sm"
                                        disabled={geofenceForm.processing}
                                    >
                                        Create geofence
                                    </Button>
                                </form>
                            ) : (
                                <div className="text-xs text-muted-foreground">
                                    You do not have permission to manage
                                    geofences.
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </PageShell>
        </AppLayout>
    );
}
