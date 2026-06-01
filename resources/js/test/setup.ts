import '@testing-library/jest-dom/vitest';
import { vi } from 'vitest';

// jsdom doesn't implement ResizeObserver / pointer-capture / scrollIntoView,
// which Radix popovers, dialogs and cmdk rely on. Polyfill them so component
// tests that open these surfaces don't throw.
const win = window as unknown as Record<string, unknown>;
if (typeof win.ResizeObserver === 'undefined') {
    win.ResizeObserver = class {
        observe() {}
        unobserve() {}
        disconnect() {}
    };
}

const elementProto = Element.prototype as unknown as Record<string, unknown>;
if (!elementProto.scrollIntoView) {
    elementProto.scrollIntoView = vi.fn();
}
if (!elementProto.hasPointerCapture) {
    elementProto.hasPointerCapture = vi.fn(() => false);
}
if (!elementProto.setPointerCapture) {
    elementProto.setPointerCapture = vi.fn();
}
if (!elementProto.releasePointerCapture) {
    elementProto.releasePointerCapture = vi.fn();
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
