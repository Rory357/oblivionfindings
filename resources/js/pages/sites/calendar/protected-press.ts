/** A protected calendar entry opens only after a completed, short press. */
export const PROTECTED_HOLD_MS = 450;
export const PROTECTED_MOVE_PX = 5;

type Start = Pick<PointerEvent, 'clientX' | 'clientY' | 'pointerId'> & {
    preventDefault(): void;
    stopPropagation(): void;
};

export function bindProtectedPress(
    start: Start,
    target: Pick<HTMLElement, 'getBoundingClientRect'>,
    open: () => void,
    events: EventTarget = window,
    now: () => number = () => performance.now(),
): () => void {
    start.preventDefault();
    start.stopPropagation();
    const began = now();
    let moved = false;
    let finished = false;
    const same = (event: PointerEvent) => event.pointerId === start.pointerId;
    const cleanup = () => {
        if (finished) return;
        finished = true;
        events.removeEventListener('pointermove', move);
        events.removeEventListener('pointerup', up);
        events.removeEventListener('pointercancel', cancel);
        events.removeEventListener('keydown', key);
    };
    const move: EventListener = (event) => {
        const point = event as PointerEvent;
        if (
            same(point) &&
            Math.hypot(
                point.clientX - start.clientX,
                point.clientY - start.clientY,
            ) > PROTECTED_MOVE_PX
        )
            moved = true;
    };
    const up: EventListener = (event) => {
        const point = event as PointerEvent;
        if (!same(point)) return;
        move(event);
        const rect = target.getBoundingClientRect();
        const inside =
            point.clientX >= rect.left &&
            point.clientX <= rect.right &&
            point.clientY >= rect.top &&
            point.clientY <= rect.bottom;
        const clicked = !moved && inside && now() - began < PROTECTED_HOLD_MS;
        cleanup();
        if (clicked) open();
    };
    const cancel: EventListener = (event) => {
        if (same(event as PointerEvent)) cleanup();
    };
    const key: EventListener = (event) => {
        if ((event as KeyboardEvent).key === 'Escape') {
            (event as KeyboardEvent).preventDefault();
            cleanup();
        }
    };
    events.addEventListener('pointermove', move);
    events.addEventListener('pointerup', up);
    events.addEventListener('pointercancel', cancel);
    events.addEventListener('keydown', key);
    return cleanup;
}
