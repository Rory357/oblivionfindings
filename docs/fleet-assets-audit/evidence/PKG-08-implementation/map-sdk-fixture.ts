// Synthetic SDK responses, only resolved by vite.map-regression.mts. No provider requests.
type Point = { lat: number; lng: number };
const failures = new Set<() => void>();
export const instances: FixtureMap[] = [];
export let resizeEvents = 0;
export function failSyntheticGoogle() {
    failures.forEach((listener) => listener());
}
export function moveSyntheticGoogle() {
    instances.forEach((map) => {
        map.center = { lat: -41.29, lng: 174.78 };
        map.zoom = 15;
        map.emit('idle');
    });
}
export function onGoogleFailure(listener: () => void) {
    failures.add(listener);
    return () => failures.delete(listener);
}
class LatLng {
    constructor(private point: Point) {}
    lat() {
        return this.point.lat;
    }
    lng() {
        return this.point.lng;
    }
    toJSON() {
        return this.point;
    }
}
class FixtureMap {
    center: Point;
    zoom: number;
    listeners = new Map<string, () => void>();
    constructor(
        public element: HTMLElement,
        options: { center: Point; zoom: number },
    ) {
        this.center = options.center;
        this.zoom = options.zoom;
        instances.push(this);
        element.style.background = '#e2e8f0';
    }
    addListener(name: string, listener: () => void) {
        this.listeners.set(name, listener);
        return { remove: () => this.listeners.delete(name) };
    }
    emit(name: string) {
        this.listeners.get(name)?.();
    }
    getCenter() {
        return new LatLng(this.center);
    }
    getZoom() {
        return this.zoom;
    }
    setCenter(point: Point) {
        this.center = point;
    }
    setZoom(zoom: number) {
        this.zoom = zoom;
    }
    setOptions() {}
    fitBounds() {}
    panTo(point: Point) {
        this.center = point;
        this.emit('idle');
    }
}
class Overlay {
    map: FixtureMap | null = null;
    setMap(map: FixtureMap | null) {
        this.map = map;
        if (map) {
            this.onAdd();
            this.draw();
        } else this.onRemove();
    }
    getProjection() {
        return {
            fromLatLngToDivPixel: (point: LatLng) => ({
                x: 100 + (point.lng() - 174.77) * 8000,
                y: 140 + (point.lat() + 41.28) * 8000,
            }),
        };
    }
    getPanes() {
        return this.map ? { overlayMouseTarget: this.map.element } : null;
    }
    onAdd() {}
    onRemove() {}
    draw() {}
}
class Shape {
    setMap() {}
    addListener() {
        return { remove() {} };
    }
}
export async function loadGoogleMaps() {
    return {
        Map: FixtureMap,
        LatLng,
        OverlayView: Overlay,
        LatLngBounds: class {
            extend() {
                return this;
            }
            isEmpty() {
                return false;
            }
        },
        Circle: Shape,
        Polygon: Shape,
        Polyline: Shape,
        InfoWindow: class {
            open() {}
            close() {}
        },
        event: {
            clearInstanceListeners: (map: FixtureMap) => map.listeners.clear(),
            trigger: (map: FixtureMap, name: string) => {
                if (name === 'resize') resizeEvents++;
                map.emit(name);
            },
        },
    };
}
