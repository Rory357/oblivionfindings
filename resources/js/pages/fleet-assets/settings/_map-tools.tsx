import LeafletMap, { type MapMarker } from '@/components/leaflet-map';
import { SettingsNotice } from '@/components/settings/settings-notice';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { WizardShell, WizardStepPane } from '@/components/wizard/shell';
import { usePage } from '@inertiajs/react';
import { MapPin, Route, Search } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { MapSnapshot } from './_types';
import { api } from './_ui';

type Point = { lat: number; lng: number };
type Place = Point & {
    place_id: string;
    display_name: string;
    attributions: { provider?: string; providerUri?: string }[];
};
function attributionUrl(value?: string) {
    try {
        const url = new URL(value ?? '');
        return ['https:', 'http:'].includes(url.protocol)
            ? url.href
            : undefined;
    } catch {
        return undefined;
    }
}
function decodePolyline(encoded: string): Point[] {
    let index = 0,
        latitude = 0,
        longitude = 0;
    const points: Point[] = [];
    function number() {
        let result = 0,
            shift = 0,
            byte = 0;
        do {
            if (index >= encoded.length || shift > 30)
                throw new Error('The provider returned an invalid route.');
            byte = encoded.charCodeAt(index++) - 63;
            result |= (byte & 31) << shift;
            shift += 5;
        } while (byte >= 32);
        return result & 1 ? ~(result >> 1) : result >> 1;
    }
    while (index < encoded.length && points.length < 20000) {
        latitude += number();
        longitude += number();
        points.push({ lat: latitude / 1e5, lng: longitude / 1e5 });
    }
    return points;
}
export function MapTools({
    settings,
    onClose,
}: {
    settings: MapSnapshot;
    onClose: () => void;
}) {
    const { fleet } = usePage<{
        fleet: { maps: { provider: string; revision: string } };
    }>().props;
    const [section, setSection] = useState(0),
        [query, setQuery] = useState(''),
        [busy, setBusy] = useState(false),
        [error, setError] = useState('');
    const [places, setPlaces] = useState<Place[]>([]),
        [address, setAddress] = useState<string | null>(null),
        [route, setRoute] = useState<Point[]>([]),
        [routeDetail, setRouteDetail] = useState(''),
        [markers, setMarkers] = useState<MapMarker[]>([]),
        [focus, setFocus] = useState<(Point & { nonce: number }) | undefined>();
    const [coordinates, setCoordinates] = useState({
            lat: '',
            lng: '',
            toLat: '',
            toLng: '',
        }),
        [provider, setProvider] = useState(fleet.maps.provider);
    const request = useRef<AbortController | null>(null);
    const clearResults = useCallback(() => {
        request.current?.abort();
        request.current = null;
        setBusy(false);
        setPlaces([]);
        setAddress(null);
        setRoute([]);
        setRouteDetail('');
        setMarkers([]);
        setFocus(undefined);
    }, []);
    const onProvider = useCallback(
        (value: 'google' | 'osm') => {
            setProvider(value);
            if (value !== 'google') clearResults();
        },
        [clearResults],
    );
    useEffect(() => clearResults(), [fleet.maps.revision, clearResults]);
    useEffect(() => () => request.current?.abort(), []);
    const enabled = (key: string) =>
        provider === 'google' &&
        settings.capabilities.some(
            (capability) => capability.key === key && capability.enabled,
        );
    function point(lat: string, lng: string): Point {
        if (
            !lat.trim() ||
            !lng.trim() ||
            !Number.isFinite(Number(lat)) ||
            !Number.isFinite(Number(lng)) ||
            Math.abs(Number(lat)) > 90 ||
            Math.abs(Number(lng)) > 180
        )
            throw new Error('Enter valid latitude and longitude values.');
        return { lat: Number(lat), lng: Number(lng) };
    }
    async function run() {
        clearResults();
        setBusy(true);
        setError('');
        const controller = new AbortController();
        request.current = controller;
        try {
            const capability = ['places', 'geocoding', 'routes'][section];
            const origin =
                section > 0 ? point(coordinates.lat, coordinates.lng) : null;
            const body =
                section === 0
                    ? { q: query }
                    : section === 1
                      ? origin
                      : {
                            origin,
                            destination: point(
                                coordinates.toLat,
                                coordinates.toLng,
                            ),
                        };
            const result = await api<{
                revision: string;
                results?: Place[];
                address?: string | null;
                routes?: {
                    distance_m: number | null;
                    duration: string | null;
                    polyline: string | null;
                }[];
            }>(
                `map-capabilities/${capability}`,
                'POST',
                body,
                controller.signal,
            );
            if (controller.signal.aborted || request.current !== controller)
                return;
            if (result.revision !== fleet.maps.revision)
                throw new Error(
                    'Map configuration changed. Close this viewer and reload the page before retrying.',
                );
            if (section === 0) setPlaces(result.results ?? []);
            if (section === 1) {
                setAddress(result.address ?? 'No address found.');
                setFocus({ ...origin!, nonce: Date.now() });
            }
            if (section === 2) {
                const value = result.routes?.[0];
                if (!value?.polyline)
                    throw new Error('No route is available for these points.');
                setRoute(decodePolyline(value.polyline));
                const seconds = Number(value.duration?.replace(/s$/, ''));
                setRouteDetail(
                    `${value.distance_m === null ? 'Distance unavailable' : `${(value.distance_m / 1000).toFixed(1)} km`} · ${Number.isFinite(seconds) && value.duration ? `${Math.ceil(seconds / 60)} min` : 'Duration unavailable'} · planning estimate`,
                );
            }
        } catch (problem) {
            if (!controller.signal.aborted)
                setError(
                    problem instanceof Error
                        ? problem.message
                        : 'The map request could not complete.',
                );
        } finally {
            if (request.current === controller) {
                request.current = null;
                setBusy(false);
            }
        }
    }
    const coordinateField = (key: keyof typeof coordinates, label: string) => (
        <div className="space-y-2">
            <Label htmlFor={`map-${key}`}>{label}</Label>
            <Input
                id={`map-${key}`}
                inputMode="decimal"
                value={coordinates[key]}
                onChange={(event) => {
                    setCoordinates((current) => ({
                        ...current,
                        [key]: event.target.value,
                    }));
                    clearResults();
                }}
            />
        </div>
    );
    return (
        <WizardShell
            open
            onClose={onClose}
            title="Map capability explorer"
            description="Explicit map requests use your configured Google project."
            railIcon={MapPin}
            railTitle="Map capabilities"
            railSub="Provider-attributed results"
            steps={[
                {
                    key: 'search',
                    label: 'Address search',
                    blurb: 'Submit a place or address',
                    icon: Search,
                },
                {
                    key: 'position',
                    label: 'Reverse geocoding',
                    blurb: 'Look up entered coordinates',
                    icon: MapPin,
                },
                {
                    key: 'route',
                    label: 'Route estimate',
                    blurb: 'Compare two entered points',
                    icon: Route,
                },
            ]}
            stepIndex={section}
            onStepClick={(value) => {
                setSection(value);
                clearResults();
                setError('');
            }}
            sequential={false}
            pct={null}
            headerLabel={
                ['Address search', 'Reverse geocoding', 'Route estimate'][
                    section
                ]
            }
            footerStart={
                <Button variant="outline" onClick={onClose}>
                    Close
                </Button>
            }
            footerEnd={
                <Button
                    disabled={
                        busy ||
                        !enabled(['places', 'geocoding', 'routes'][section])
                    }
                    onClick={run}
                >
                    {busy
                        ? 'Requesting…'
                        : [
                              'Search with Google',
                              'Look up address',
                              'Estimate route',
                          ][section]}
                </Button>
            }
        >
            <WizardStepPane key={section}>
                <div className="space-y-4">
                    <SettingsNotice>
                        Requests use the configured Google APIs and may incur
                        provider charges. Enter only information you are
                        authorised to share. Results are temporary and are not
                        saved into source records.
                    </SettingsNotice>
                    {error && (
                        <div role="alert">
                            <SettingsNotice>{error}</SettingsNotice>
                        </div>
                    )}
                    {!enabled(['places', 'geocoding', 'routes'][section]) && (
                        <SettingsNotice>
                            This capability is not available with the current
                            provider configuration.
                        </SettingsNotice>
                    )}
                    {section === 0 ? (
                        <div className="space-y-2">
                            <Label htmlFor="map-place-query">
                                Address or place
                            </Label>
                            <Input
                                id="map-place-query"
                                value={query}
                                maxLength={200}
                                onChange={(event) => {
                                    setQuery(event.target.value);
                                    clearResults();
                                }}
                            />
                        </div>
                    ) : (
                        <div className="grid grid-cols-2 gap-4">
                            {coordinateField(
                                'lat',
                                section === 2 ? 'Origin latitude' : 'Latitude',
                            )}
                            {coordinateField(
                                'lng',
                                section === 2
                                    ? 'Origin longitude'
                                    : 'Longitude',
                            )}
                            {section === 2 && (
                                <>
                                    {coordinateField(
                                        'toLat',
                                        'Destination latitude',
                                    )}
                                    {coordinateField(
                                        'toLng',
                                        'Destination longitude',
                                    )}
                                </>
                            )}
                        </div>
                    )}
                    <LeafletMap
                        center={{ lat: -41.2866, lng: 174.7756 }}
                        height={260}
                        focus={focus}
                        fitMarkers={false}
                        autoFit={!!route.length}
                        googleResults={{ markers, polyline: route }}
                        onProviderChange={onProvider}
                    />
                    {provider === 'google' && (
                        <>
                            <p className="text-caption">
                                Google Maps · provider results
                            </p>
                            {places.map((place) => (
                                <div key={place.place_id} className="space-y-1">
                                    <Button
                                        variant="outline"
                                        className="h-auto w-full justify-start text-left whitespace-normal"
                                        onClick={() => {
                                            setMarkers([
                                                {
                                                    ...place,
                                                    id: place.place_id,
                                                    title: place.display_name,
                                                },
                                            ]);
                                            setFocus({
                                                lat: place.lat,
                                                lng: place.lng,
                                                nonce: Date.now(),
                                            });
                                        }}
                                    >
                                        {place.display_name}
                                    </Button>
                                    {place.attributions?.map(
                                        (attribution, i) => (
                                            <p key={i} className="text-caption">
                                                {attributionUrl(
                                                    attribution.providerUri,
                                                ) ? (
                                                    <a
                                                        href={attributionUrl(
                                                            attribution.providerUri,
                                                        )}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                    >
                                                        {attribution.provider}
                                                    </a>
                                                ) : (
                                                    attribution.provider
                                                )}
                                            </p>
                                        ),
                                    )}
                                </div>
                            ))}
                            {address && (
                                <p className="text-subtle">{address}</p>
                            )}
                            {routeDetail && (
                                <p className="text-subtle">{routeDetail}</p>
                            )}
                        </>
                    )}
                </div>
            </WizardStepPane>
        </WizardShell>
    );
}
