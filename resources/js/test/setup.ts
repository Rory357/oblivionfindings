import '@testing-library/jest-dom/vitest';
import { vi } from 'vitest';

// jsdom doesn't implement ResizeObserver / pointer-capture / scrollIntoView,
// which Radix popovers, dialogs and cmdk rely on. Polyfill them so component
// tests that open these surfaces don't throw.
if (!('ResizeObserver' in window)) {
    window.ResizeObserver = class {
        observe() {}
        unobserve() {}
        disconnect() {}
    } as unknown as typeof ResizeObserver;
}

if (!Element.prototype.scrollIntoView) {
    Element.prototype.scrollIntoView = vi.fn();
}
if (!Element.prototype.hasPointerCapture) {
    Element.prototype.hasPointerCapture = vi.fn(() => false) as unknown as (pointerId: number) => boolean;
}
if (!Element.prototype.setPointerCapture) {
    Element.prototype.setPointerCapture = vi.fn() as unknown as (pointerId: number) => void;
}
if (!Element.prototype.releasePointerCapture) {
    Element.prototype.releasePointerCapture = vi.fn() as unknown as (pointerId: number) => void;
}

Object.defineProperty(window, 'matchMedia', {
    writable: true,
    value: (query: string) => ({
        matches: false,
        media: query,
        onchange: null,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        addListener: vi.fn(),
        removeListener: vi.fn(),
        dispatchEvent: vi.fn(),
    }),
});
