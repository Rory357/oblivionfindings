import '@/../css/app.css';
import LeafletMap from '@/components/leaflet-map';
import { createInertiaApp } from '@inertiajs/react';
import { useState } from 'react';
import { createRoot } from 'react-dom/client';
import {
    failSyntheticGoogle,
    moveSyntheticGoogle,
    resizeEvents,
} from './map-sdk-fixture';

const google = new URLSearchParams(location.search).has('google');
const point = { lat: -41.285, lng: 174.775 };
function Fixture() {
    const [small, setSmall] = useState(false),
        [selected, setSelected] = useState('none');
    const [viewport, setViewport] = useState({ center: point, zoom: 13 });
    const [moved, setMoved] = useState(false);
    const [report, setReport] = useState<Record<string, unknown> | null>(null);
    const common = {
        center: point,
        zoom: 13,
        observeResize: true,
        fitMarkers: false,
        showMarkerPopups: false,
        markers: [
            { ...point, id: 'application', title: 'Application vehicle' },
        ],
        googleResults: {
            markers: [
                {
                    lat: -41.282,
                    lng: 174.778,
                    id: 'provider',
                    title: 'Temporary Google result',
                },
            ],
        },
        onMarkerClick: (id: string | number) => setSelected(String(id)),
    };
    function measure() {
        const near = (a: number, b: number) => Math.abs(a - b) <= 2;
        const rows = ['percentage', 'fixed', 'default'].map((id) => {
            const host = document.getElementById(id)!;
            const frame =
                host.querySelector<HTMLElement>('[data-map-frame]') ??
                (host.firstElementChild as HTMLElement);
            const map = host.querySelector<HTMLElement>(
                '.leaflet-container, [aria-label="Google map"]',
            )!;
            const message = host.querySelector<HTMLElement>('[role="status"]');
            const frameRect = frame.getBoundingClientRect(),
                mapRect = map.getBoundingClientRect();
            const statusRect = message?.getBoundingClientRect();
            const target =
                id === 'percentage'
                    ? host.clientHeight
                    : id === 'fixed'
                      ? 280
                      : 400;
            return {
                id,
                target,
                frameHeight: frameRect.height,
                mapHeight: mapRect.height,
                heightPass:
                    near(frameRect.height, target) && mapRect.height > 100,
                contentInsideFrame:
                    mapRect.bottom <= frameRect.bottom + 1 &&
                    (!statusRect || statusRect.bottom <= frameRect.bottom + 1),
                callerBorder: getComputedStyle(frame).borderTopWidth,
                statusReachable:
                    !message ||
                    (!!message.querySelector('button') &&
                        message.clientHeight > 0 &&
                        message.scrollWidth <= message.clientWidth),
            };
        });
        const expected = moved
            ? { center: { lat: -41.29, lng: 174.78 }, zoom: 15 }
            : { center: point, zoom: 13 };
        // Leaflet rounds pixel centres when dimensions change. Allow at most
        // one projected pixel, not a geographic pan hidden by a loose tolerance.
        const longitudePixel = 360 / (256 * 2 ** expected.zoom);
        const latitudePixel = longitudePixel * Math.cos(expected.center.lat * Math.PI / 180);
        const viewportPass =
            Math.abs(viewport.center.lat - expected.center.lat) <= latitudePixel &&
            Math.abs(viewport.center.lng - expected.center.lng) <= longitudePixel &&
            viewport.zoom === expected.zoom;
        const fallback = !!document.querySelector('[role="status"]');
        const providerMarkersRemaining = document.querySelectorAll(
            '[aria-label="Temporary Google result"]',
        ).length;
        setReport({
            pass:
                rows.every(
                    (row) =>
                        row.heightPass &&
                        row.contentInsideFrame &&
                        row.statusReachable,
                ) &&
                rows[0].callerBorder === '0px' &&
                viewportPass &&
                (!fallback || providerMarkersRemaining === 0),
            phase: document.querySelector('[role="status"]')
                ? 'synthetic fallback to real Leaflet'
                : google
                  ? 'synthetic Google renderer'
                  : 'real Leaflet renderer',
            rows,
            selected,
            viewport,
            viewportPass,
            resizeEvents,
            providerMarkersRemaining,
            leafletContainers:
                document.querySelectorAll('.leaflet-container').length,
        });
    }
    return (
        <main className="space-y-4 bg-background p-6 text-foreground">
            <h1 className="text-xl font-bold">
                PKG-08 rendered map regression
            </h1>
            <p>
                Actual shared component and browser layout; synthetic Google SDK
                only. No provider credentials or Google requests.
            </p>
            <nav className="flex gap-3">
                <a href="?">OSM case</a>
                <a href="?google=1">Synthetic Google case</a>
            </nav>
            <div className="flex gap-3">
                <button
                    className="rounded border p-2"
                    onClick={() => setSmall(!small)}
                >
                    Resize containers
                </button>
                <button
                    className="rounded border p-2"
                    onClick={() => {
                        moveSyntheticGoogle();
                        setMoved(true);
                    }}
                >
                    Move synthetic viewport
                </button>
                <button
                    className="rounded border p-2"
                    onClick={failSyntheticGoogle}
                >
                    Fail synthetic Google
                </button>
                <button className="rounded border p-2" onClick={measure}>
                    Check rendered layout
                </button>
            </div>
            <p>
                Selected: {selected}; viewport: {viewport.center.lat},{' '}
                {viewport.center.lng}; zoom: {viewport.zoom}
            </p>
            <output
                aria-label="Layout regression result"
                className="block max-h-44 overflow-auto"
            >
                <pre>
                    {report
                        ? JSON.stringify(report, null, 2)
                        : 'Awaiting rendered check'}
                </pre>
            </output>
            <div className="grid grid-cols-2 gap-4">
                <section>
                    <h2>Percentage height, caller border removal</h2>
                    <div
                        id="percentage"
                        style={{
                            height: small ? 340 : 480,
                            width: small ? '80%' : '100%',
                        }}
                    >
                        <LeafletMap
                            {...common}
                            height="100%"
                            className="h-full rounded-none border-0"
                            onViewport={(center, zoom) =>
                                setViewport({ center, zoom })
                            }
                        />
                    </div>
                </section>
                <section id="fixed">
                    <h2>Fixed numeric height</h2>
                    <LeafletMap {...common} height={280} />
                </section>
                <section id="default">
                    <h2>Default height</h2>
                    <LeafletMap {...common} />
                </section>
            </div>
        </main>
    );
}
void createInertiaApp({
    page: {
        component: 'fixture',
        props: {
            fleet: {
                maps: {
                    provider: google ? 'google' : 'osm',
                    apiKey: google ? 'synthetic' : null,
                    revision: 'fixture',
                },
            },
        },
        url: location.pathname + location.search,
        version: 'fixture',
        clearHistory: false,
        encryptHistory: false,
    },
    resolve: () => Fixture,
    setup: ({ el, App, props }) => {
        createRoot(el).render(<App {...props} />);
    },
});
