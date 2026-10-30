/** The small Maps JavaScript surface used by our renderer; application records stay outside the SDK. */
export type Point = { lat: number; lng: number };
export type LatLng = { lat(): number; lng(): number; toJSON(): Point };
export type Listener = { remove(): void };
export type MapEvent = { latLng?: LatLng; domEvent?: MouseEvent };
export interface GoogleMap {
    addListener(event: string, callback: (event: MapEvent) => void): Listener;
    getCenter(): LatLng | undefined;
    getZoom(): number | undefined;
    setCenter(point: Point): void;
    setZoom(zoom: number): void;
    setOptions(options: Record<string, unknown>): void;
    fitBounds(bounds: GoogleBounds, padding?: number): void;
    panTo(point: Point): void;
}
export interface GoogleBounds {
    extend(point: Point): GoogleBounds;
    isEmpty(): boolean;
}
export interface GoogleOverlay {
    setMap(map: GoogleMap | null): void;
    getProjection(): {
        fromLatLngToDivPixel(point: LatLng): { x: number; y: number } | null;
    };
    getPanes(): { overlayMouseTarget: HTMLElement } | null;
    onAdd(): void;
    onRemove(): void;
    draw(): void;
}
export interface GoogleShape {
    setMap(map: GoogleMap | null): void;
    addListener(event: string, callback: (event: MapEvent) => void): Listener;
}
export interface MapsSDK {
    Map: new (
        element: HTMLElement,
        options: Record<string, unknown>,
    ) => GoogleMap;
    LatLng: new (point: Point) => LatLng;
    LatLngBounds: new () => GoogleBounds;
    OverlayView: new () => GoogleOverlay;
    Polygon: new (options: Record<string, unknown>) => GoogleShape;
    Circle: new (options: Record<string, unknown>) => GoogleShape;
    Polyline: new (options: Record<string, unknown>) => GoogleShape;
    InfoWindow: new (options: Record<string, unknown>) => {
        open(options: Record<string, unknown>): void;
        close(): void;
    };
    event: {
        clearInstanceListeners(instance: unknown): void;
        trigger(instance: unknown, name: string): void;
    };
}
type GoogleWindow = Window & {
    google?: { maps: MapsSDK };
    __fleetGoogleReady?: () => void;
    gm_authFailure?: () => void;
};
let pending: Promise<MapsSDK> | undefined;
let loadedKey: string | undefined;
let terminalFailure = false;
let authHookInstalled = false;
const failures = new Set<() => void>();
export function onGoogleFailure(listener: () => void) {
    failures.add(listener);
    return () => {
        failures.delete(listener);
    };
}
export function loadGoogleMaps(key: string): Promise<MapsSDK> {
    const host = window as GoogleWindow;
    // An SDK already loaded with another key cannot be safely rotated in place.
    if (loadedKey && loadedKey !== key)
        return Promise.reject(
            new Error(
                'The provider credential changed. Reload to use the current key.',
            ),
        );
    if (terminalFailure)
        return Promise.reject(
            new Error(
                'Google Maps rejected this configuration. Reload after correcting the provider configuration.',
            ),
        );
    if (pending) return pending;
    loadedKey = key;
    pending = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        const timeout = window.setTimeout(() => fail(), 15000);
        function fail() {
            window.clearTimeout(timeout);
            script.remove();
            // Once execution may have started, a second SDK cannot safely be injected.
            terminalFailure = true;
            reject(
                new Error(
                    'Google Maps is unavailable. Reload after checking the provider configuration.',
                ),
            );
            failures.forEach((listener) => listener());
        }
        if (!authHookInstalled) {
            const previousAuthFailure = host.gm_authFailure;
            host.gm_authFailure = () => {
                previousAuthFailure?.();
                fail();
            };
            authHookInstalled = true;
        }
        host.__fleetGoogleReady = () => {
            window.clearTimeout(timeout);
            if (!terminalFailure && host.google?.maps)
                resolve(host.google.maps);
            else fail();
        };
        script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(key)}&v=quarterly&loading=async&callback=__fleetGoogleReady`;
        script.async = true;
        // Reuse the document nonce; no unsafe-inline script policy is needed.
        script.nonce =
            document.querySelector<HTMLScriptElement>('script[nonce]')?.nonce ??
            '';
        script.onerror = fail;
        document.head.appendChild(script);
    });
    return pending;
}
