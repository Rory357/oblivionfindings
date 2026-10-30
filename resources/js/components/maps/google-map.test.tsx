import { act, render, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { GoogleMap } from './google-map';

const state = vi.hoisted(() => ({
    mapOptions: [] as Record<string, unknown>[],
    shapes: [] as Record<string, unknown>[],
    detached: 0,
    cleared: 0,
    listeners: {} as Record<string, (event?: unknown) => void>,
    pane: null as HTMLElement | null,
    failure: null as (() => void) | null,
}));
vi.mock('@/components/leaflet-map', () => ({
    clusterMarkers: (markers: unknown[]) => markers,
    getMapMarkerColor: () => '#64748b',
}));
vi.mock('./google-sdk', () => ({
    onGoogleFailure: (listener: () => void) => {
        state.failure = listener;
        return () => {
            state.failure = null;
        };
    },
    loadGoogleMaps: async () => ({
        Map: class {
            constructor(_node: HTMLElement, options: Record<string, unknown>) {
                state.mapOptions.push(options);
            }
            addListener(name: string, listener: (event?: unknown) => void) {
                state.listeners[name] = listener;
            }
            getCenter() {
                return { toJSON: () => ({ lat: -41, lng: 174 }) };
            }
            getZoom() {
                return 12;
            }
            setCenter() {}
            setZoom() {}
            panTo() {}
            fitBounds() {}
        },
        OverlayView: class {
            setMap(map: unknown) {
                if (map) (this as unknown as { onAdd(): void }).onAdd();
                else {
                    state.detached++;
                    (this as unknown as { onRemove(): void }).onRemove();
                }
            }
            getPanes() {
                return { overlayMouseTarget: state.pane };
            }
        },
        Circle: class {
            constructor(options: Record<string, unknown>) {
                state.shapes.push({ kind: 'circle', ...options });
            }
            setMap() {
                state.detached++;
            }
        },
        Polygon: class {
            constructor(options: Record<string, unknown>) {
                state.shapes.push({ kind: 'polygon', ...options });
            }
            setMap() {
                state.detached++;
            }
        },
        Polyline: class {
            constructor(options: Record<string, unknown>) {
                state.shapes.push({ kind: 'line', ...options });
            }
            setMap() {
                state.detached++;
            }
        },
        event: {
            clearInstanceListeners: () => {
                state.cleared++;
            },
        },
    }),
}));
beforeEach(() => {
    state.mapOptions = [];
    state.shapes = [];
    state.detached = 0;
    state.cleared = 0;
    state.listeners = {};
    state.pane = document.createElement('div');
    document.body.append(state.pane);
});
it('retains authorised markers and application geometry, forwards viewport and cleans up the provider', async () => {
    const click = vi.fn(),
        viewport = vi.fn(),
        failure = vi.fn();
    const view = render(
        <GoogleMap
            apiKey="synthetic"
            center={{ lat: -41, lng: 174 }}
            zoom={12}
            fitMarkers={false}
            showMarkerPopups={false}
            onFailure={failure}
            onViewport={viewport}
            onMarkerClick={click}
            markers={[
                {
                    id: 'permitted',
                    lat: -41,
                    lng: 174,
                    title: '<script>fixture</script>',
                },
            ]}
            geofences={[
                {
                    id: 1,
                    type: 'circle',
                    center: { lat: -41, lng: 174 },
                    radius_m: 100,
                },
                {
                    id: 2,
                    type: 'polygon',
                    coordinates: [
                        { lat: -41, lng: 174 },
                        { lat: -40, lng: 174 },
                        { lat: -41, lng: 175 },
                    ],
                },
            ]}
            polylineOptions={{ dashArray: '7 7', showArrows: true }}
            polyline={[
                { lat: -41, lng: 174 },
                { lat: -40, lng: 175 },
            ]}
        />,
    );
    await waitFor(() => expect(state.shapes).toHaveLength(3));
    expect(state.mapOptions[0].center).toEqual({ lat: -41, lng: 174 });
    expect(state.shapes.map((shape) => shape.kind)).toEqual([
        'circle',
        'polygon',
        'line',
    ]);
    expect(state.shapes[0].radius).toBe(100);
    expect(state.shapes[2].strokeOpacity).toBe(0);
    expect(state.shapes[2].icons).toHaveLength(2);
    expect(state.pane?.querySelector('script')).toBeNull();
    act(() => state.pane?.querySelector('button')?.click());
    expect(click).toHaveBeenCalledWith('permitted');
    act(() => state.listeners.idle());
    expect(viewport).toHaveBeenCalledWith({ lat: -41, lng: 174 }, 12);
    act(() => state.failure?.());
    expect(failure).toHaveBeenCalledOnce();
    view.unmount();
    expect(state.detached).toBeGreaterThanOrEqual(4);
    expect(state.cleared).toBe(1);
    expect(state.pane?.children).toHaveLength(0);
    state.pane?.remove();
});
