import '@testing-library/jest-dom/vitest';
import { vi } from 'vitest';
class TestPointerEvent extends MouseEvent {
    pointerId: number;
    constructor(type: string, init: PointerEventInit = {}) {
        super(type, init);
        this.pointerId = init.pointerId ?? 1;
    }
}
Object.defineProperty(window, 'PointerEvent', {
    value: TestPointerEvent,
    configurable: true,
});
Object.defineProperty(globalThis, 'ResizeObserver', {
    value: class {
        callback: ResizeObserverCallback;
        constructor(callback: ResizeObserverCallback) {
            this.callback = callback;
        }
        observe(target: Element) {
            this.callback(
                [
                    {
                        target,
                        contentRect: { width: 1300, height: 800 },
                    } as ResizeObserverEntry,
                ],
                this as unknown as ResizeObserver,
            );
        }
        disconnect() {}
        unobserve() {}
    },
    configurable: true,
});
Object.defineProperty(HTMLElement.prototype, 'clientWidth', {
    get: () => 1000,
    configurable: true,
});
Object.defineProperty(HTMLElement.prototype, 'clientHeight', {
    get: () => 600,
    configurable: true,
});
Object.defineProperty(SVGElement.prototype, 'getBoundingClientRect', {
    value: () => ({
        x: 0,
        y: 0,
        left: 0,
        top: 0,
        right: 1080,
        bottom: 680,
        width: 1080,
        height: 680,
        toJSON() {
            return {};
        },
    }),
    configurable: true,
});
const captures = new WeakMap<Element, Set<number>>();
Object.defineProperty(SVGElement.prototype, 'setPointerCapture', {
    value(id: number) {
        const ids = captures.get(this) ?? new Set();
        ids.add(id);
        captures.set(this, ids);
    },
    configurable: true,
});
Object.defineProperty(SVGElement.prototype, 'hasPointerCapture', {
    value(id: number) {
        return captures.get(this)?.has(id) ?? false;
    },
    configurable: true,
});
Object.defineProperty(SVGElement.prototype, 'releasePointerCapture', {
    value(id: number) {
        captures.get(this)?.delete(id);
    },
    configurable: true,
});
let objectSequence = 0;
Object.defineProperty(URL, 'createObjectURL', {
    value: vi.fn(() => `blob:private-candidate-${++objectSequence}`),
    configurable: true,
});
Object.defineProperty(URL, 'revokeObjectURL', {
    value: vi.fn(),
    configurable: true,
});
Object.defineProperty(window, 'matchMedia', {
    value: () => ({
        matches: false,
        addEventListener() {},
        removeEventListener() {},
    }),
    configurable: true,
});
