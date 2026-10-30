import { addLocalMinutes } from '@/components/fleet-assets/vehicle-workspace/booking-wizard';
import {
    useEffect,
    useRef,
    useState,
    type ReactNode,
    type PointerEvent as ReactPointerEvent,
} from 'react';

/** Range selection uses the shared calendar's labelled slots; clicks and keyboard stay native. */
export function CalendarRange({
    children,
    enabled,
    onRange,
}: {
    children: ReactNode;
    enabled: boolean;
    onRange: (start: string, end: string) => void;
}) {
    const host = useRef<HTMLDivElement>(null);
    const cleanup = useRef<(() => void) | null>(null);
    const suppress = useRef(false);
    const [rects, setRects] = useState<
        Array<{ left: number; top: number; width: number; height: number }>
    >([]);
    useEffect(() => () => cleanup.current?.(), [enabled]);
    const begin = (event: ReactPointerEvent) => {
        suppress.current = false;
        if (
            !enabled ||
            event.button !== 0 ||
            event.pointerType === 'touch' ||
            !(event.target instanceof Element) ||
            !event.target.closest('[data-calendar-slot]')
        )
            return;
        cleanup.current?.();
        const root = host.current!;
        const slots = [
            ...root.querySelectorAll<HTMLElement>('[data-calendar-slot]'),
        ];
        const at = (x: number, y: number) => {
            const slot = slots.find((item) => {
                const r = item.getBoundingClientRect();
                return (
                    x >= r.left && x <= r.right && y >= r.top && y <= r.bottom
                );
            });
            if (!slot?.dataset.calendarDay) return null;
            const r = slot.getBoundingClientRect();
            const minute =
                Number(slot.dataset.calendarHour) * 60 +
                Math.min(
                    45,
                    Math.max(0, Math.floor(((y - r.top) / r.height) * 4) * 15),
                );
            return addLocalMinutes(`${slot.dataset.calendarDay}T00:00`, minute);
        };
        const first = at(event.clientX, event.clientY);
        if (!first) return;
        let moved = false;
        let last = first;
        const clear = () => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', up);
            window.removeEventListener('pointercancel', cancel);
            window.removeEventListener('keydown', key);
            cleanup.current = null;
            setRects([]);
        };
        const move = (point: PointerEvent) => {
            if (
                point.pointerId !== event.pointerId ||
                (!moved &&
                    Math.hypot(
                        point.clientX - event.clientX,
                        point.clientY - event.clientY,
                    ) <= 5)
            )
                return;
            moved = true;
            point.preventDefault();
            const next = at(point.clientX, point.clientY);
            if (!next) return;
            last = next;
            const low = first < last ? first : last;
            const high = addLocalMinutes(first > last ? first : last, 15);
            const origin = root.getBoundingClientRect();
            setRects(
                slots.flatMap((slot) => {
                    const start = `${slot.dataset.calendarDay}T${String(slot.dataset.calendarHour).padStart(2, '0')}:00`;
                    const end = addLocalMinutes(start, 60);
                    if (start >= high || end <= low) return [];
                    const from = Math.max(
                        0,
                        (Date.parse(`${low}:00Z`) -
                            Date.parse(`${start}:00Z`)) /
                            60000,
                    );
                    const to = Math.min(
                        60,
                        (Date.parse(`${high}:00Z`) -
                            Date.parse(`${start}:00Z`)) /
                            60000,
                    );
                    const r = slot.getBoundingClientRect();
                    return [
                        {
                            left: r.left - origin.left,
                            top: r.top - origin.top + (from / 60) * r.height,
                            width: r.width,
                            height: ((to - from) / 60) * r.height,
                        },
                    ];
                }),
            );
        };
        const up = (point: PointerEvent) => {
            if (point.pointerId !== event.pointerId) return;
            const end = at(point.clientX, point.clientY);
            clear();
            if (!moved) return;
            suppress.current = true;
            if (end)
                onRange(
                    first < end ? first : end,
                    addLocalMinutes(first > end ? first : end, 15),
                );
        };
        const cancel = (point: PointerEvent) => {
            if (point.pointerId === event.pointerId) {
                suppress.current = moved;
                clear();
            }
        };
        const key = (keyEvent: KeyboardEvent) => {
            if (keyEvent.key === 'Escape') {
                keyEvent.preventDefault();
                suppress.current = moved;
                clear();
            }
        };
        window.addEventListener('pointermove', move, { passive: false });
        window.addEventListener('pointerup', up);
        window.addEventListener('pointercancel', cancel);
        window.addEventListener('keydown', key);
        cleanup.current = clear;
    };
    return (
        <div
            ref={host}
            className="relative h-full"
            onPointerDownCapture={begin}
            onClickCapture={(event) => {
                if (suppress.current) {
                    event.preventDefault();
                    event.stopPropagation();
                    suppress.current = false;
                }
            }}
        >
            {children}
            {rects.map((rect, index) => (
                <div
                    key={index}
                    className="pointer-events-none absolute z-40 border border-primary bg-primary/15"
                    style={rect}
                />
            ))}
            {!!rects.length && (
                <span role="status" className="sr-only">
                    Release to review the selected time. Escape cancels.
                </span>
            )}
        </div>
    );
}
