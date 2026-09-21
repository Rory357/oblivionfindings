import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import L from 'leaflet';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import ClientLocationMap from './client-location-map';
import type { Geometry } from './types';

const shape: Geometry = {
    type: 'polygon',
    coordinates: [
        { lat: -36.8505, lng: 174.7595 },
        { lat: -36.8505, lng: 174.7605 },
        { lat: -36.8495, lng: 174.7605 },
        { lat: -36.8495, lng: 174.7595 },
    ],
};
const center = { lat: -36.85, lng: 174.76 };
const svg = L.Browser.svg;
beforeEach(() => {
    Object.defineProperty(L.Browser, 'svg', { configurable: true, value: true });
});
afterEach(() => {
    cleanup();
    Object.defineProperty(L.Browser, 'svg', { configurable: true, value: svg });
});
const pointer = (element: Element, type: string, x: number, y: number) => {
    const event = new MouseEvent(type, {
        bubbles: true,
        cancelable: true,
        clientX: x,
        clientY: y,
        button: 0,
    });
    Object.defineProperties(event, {
        pointerId: { value: 1 },
        isPrimary: { value: true },
        pointerType: { value: 'touch' },
    });
    fireEvent(element, event);
};

it('previews a whole-zone drag live and commits it only once on release', () => {
    const change = vi.fn();
    const view = render(
        <ClientLocationMap
            center={center}
            shape={shape}
            editing
            onChange={change}
        />,
    );
    const path = view.container.querySelector('.location-map-movable')!;
    const before = path.getAttribute('d');
    pointer(path, 'pointerdown', 100, 100);
    pointer(path, 'pointermove', 160, 125);
    expect(path.getAttribute('d')).not.toBe(before);
    expect(change).not.toHaveBeenCalled();
    pointer(path, 'pointerup', 160, 125);
    expect(change).toHaveBeenCalledOnce();
    const next = change.mock.calls[0][0];
    expect(next.coordinates).toHaveLength(4);
    expect(next.coordinates[0].lng).toBeGreaterThan(shape.coordinates[0].lng);
    expect(view.container.querySelector('.location-map-dragging')).toBeNull();
});

it('cancels interrupted touch movement without committing or leaving the map locked', () => {
    const change = vi.fn();
    const view = render(
        <ClientLocationMap
            center={center}
            shape={shape}
            editing
            onChange={change}
        />,
    );
    const path = view.container.querySelector('.location-map-movable')!;
    const before = path.getAttribute('d');
    pointer(path, 'pointerdown', 100, 100);
    pointer(path, 'pointermove', 150, 130);
    pointer(path, 'pointercancel', 150, 130);
    expect(path.getAttribute('d')).toBe(before);
    expect(change).not.toHaveBeenCalled();
    expect(view.container.querySelector('.location-map-dragging')).toBeNull();
});

it('moves the whole polygon with the centre handle and only one corner with its own handle', () => {
    const change = vi.fn();
    render(
        <ClientLocationMap
            center={center}
            shape={shape}
            editing
            onChange={change}
        />,
    );
    fireEvent.keyDown(
        screen.getByRole('button', { name: /Move entire safe zone/ }),
        { key: 'ArrowRight' },
    );
    const moved = change.mock.calls[0][0];
    shape.coordinates.forEach((point, index) =>
        expect(moved.coordinates[index].lng).toBeGreaterThan(point.lng),
    );
    fireEvent.keyDown(screen.getByRole('button', { name: /Corner 1\./ }), {
        key: 'ArrowRight',
        shiftKey: true,
    });
    const edited = change.mock.calls[1][0];
    expect(edited.coordinates[0]).not.toEqual(shape.coordinates[0]);
    expect(edited.coordinates.slice(1)).toEqual(shape.coordinates.slice(1));
});

it('moves a circle without changing its radius and keeps read-only boundaries immovable', () => {
    const change = vi.fn();
    const circle: Geometry = { type: 'circle', center, radius_m: 80 };
    const view = render(
        <ClientLocationMap
            center={center}
            shape={circle}
            editing
            onChange={change}
        />,
    );
    fireEvent.keyDown(
        screen.getByRole('button', { name: /Move entire safe zone/ }),
        { key: 'ArrowRight' },
    );
    expect(change.mock.calls[0][0].radius_m).toBe(80);
    expect(change.mock.calls[0][0].center.lng).toBeGreaterThan(center.lng);
    view.rerender(
        <ClientLocationMap
            center={center}
            shape={circle}
            editing={false}
            onChange={change}
        />,
    );
    expect(
        screen.queryByRole('button', { name: /Move entire safe zone/ }),
    ).toBeNull();
    expect(view.container.querySelector('.location-map-movable')).toBeNull();
});
