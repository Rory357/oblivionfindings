import { describe, expect, it, vi } from 'vitest';
import { bindProtectedPress } from './protected-press';

const target = {
    getBoundingClientRect: () =>
        ({ left: 0, right: 100, top: 0, bottom: 50 }) as DOMRect,
};
const pointer = (type: string, x: number, y: number, id = 1) =>
    Object.assign(new Event(type), { clientX: x, clientY: y, pointerId: id });
function press() {
    const events = new EventTarget();
    const open = vi.fn();
    let time = 0;
    const start = {
        clientX: 20,
        clientY: 20,
        pointerId: 1,
        preventDefault: vi.fn(),
        stopPropagation: vi.fn(),
    };
    const cleanup = bindProtectedPress(start, target, open, events, () => time);
    return {
        events,
        open,
        start,
        cleanup,
        advance: (ms: number) => {
            time += ms;
        },
    };
}

describe('protected calendar presses', () => {
    it('opens once only after a short release inside, never on down', () => {
        const run = press();
        expect(run.open).not.toHaveBeenCalled();
        run.advance(100);
        run.events.dispatchEvent(pointer('pointerup', 20, 20));
        run.events.dispatchEvent(pointer('pointerup', 20, 20));
        expect(run.open).toHaveBeenCalledTimes(1);
        expect(run.start.preventDefault).toHaveBeenCalledTimes(1);
    });

    it.each([
        'long hold',
        'drag away and back',
        'release outside',
        'cancel',
        'Escape',
        'unmount',
    ])('does not inspect after %s', (scenario) => {
        const run = press();
        if (scenario === 'long hold') run.advance(450);
        if (scenario === 'drag away and back') {
            run.events.dispatchEvent(pointer('pointermove', 40, 20));
            run.events.dispatchEvent(pointer('pointermove', 20, 20));
        }
        if (scenario === 'cancel')
            run.events.dispatchEvent(pointer('pointercancel', 20, 20));
        if (scenario === 'Escape')
            run.events.dispatchEvent(
                Object.assign(new Event('keydown'), { key: 'Escape' }),
            );
        if (scenario === 'unmount') run.cleanup();
        run.events.dispatchEvent(
            pointer('pointerup', scenario === 'release outside' ? 120 : 20, 20),
        );
        expect(run.open).not.toHaveBeenCalled();
    });

    it('ignores another pointer and tolerates movement up to five pixels', () => {
        const run = press();
        run.events.dispatchEvent(pointer('pointermove', 23, 24));
        run.events.dispatchEvent(pointer('pointerup', 20, 20, 2));
        expect(run.open).not.toHaveBeenCalled();
        run.events.dispatchEvent(pointer('pointerup', 23, 24));
        expect(run.open).toHaveBeenCalledTimes(1);
    });
});
