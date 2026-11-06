import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
    type RefObject,
} from 'react';

/**
 * Oblivion's own long-list rendering for the message history (plan C2.7). Written from the DOM and
 * ResizeObserver documentation only; no list or virtualisation library was used or consulted.
 *
 * Below MESSAGE_WINDOW_FROM loaded messages every row stays mounted, so find-in-page and screen
 * readers see the whole loaded history. Past it, only the rows around the visible ones are mounted;
 * the rest are replaced by two spacers sized from each row's measured height. Rows always enter
 * the list at an edge the reader is looking at (older pages at the top, new messages at the end),
 * so every row is measured before it is ever unmounted.
 */
export const MESSAGE_WINDOW_FROM = 160;
/** Rows kept mounted on each side of the visible ones. */
export const MESSAGE_WINDOW_OVERSCAN = 40;
/** Height assumed for a row that has never been on screen. */
export const MESSAGE_ROW_ESTIMATE = 96;

export type MessageRange = { start: number; end: number };

/** The rows that intersect [top, top + height], widened by `overscan` on each side. Inclusive. */
export function visibleMessageRange(
    heights: readonly number[],
    top: number,
    height: number,
    overscan = MESSAGE_WINDOW_OVERSCAN,
): MessageRange {
    const count = heights.length;
    if (!count) return { start: 0, end: -1 };
    let first = 0;
    let rowTop = 0;
    while (first < count - 1 && rowTop + heights[first] <= top) {
        rowTop += heights[first];
        first++;
    }
    let last = first;
    let rowBottom = rowTop + heights[first];
    while (last < count - 1 && rowBottom < top + height) {
        last++;
        rowBottom += heights[last];
    }
    return {
        start: Math.max(0, first - overscan),
        end: Math.min(count - 1, last + overscan),
    };
}

export function sumHeights(
    heights: readonly number[],
    from: number,
    to: number,
) {
    let total = 0;
    for (
        let index = Math.max(0, from);
        index < Math.min(heights.length, to);
        index++
    )
        total += heights[index];
    return total;
}

type WindowState = {
    startId: number;
    endId: number;
    /** The window reaches the first row: rows loaded above it are mounted straight away. */
    fromStart: boolean;
    /** The window reaches the last row: new messages are mounted straight away. */
    toEnd: boolean;
    /** Spacer heights for the rows above and below, from their measured heights. */
    before: number;
    after: number;
};

export type MessageWindow = {
    start: number;
    end: number;
    before: number;
    after: number;
    windowed: boolean;
    /** Call from the viewport's scroll handler. */
    update: () => void;
};

/**
 * `viewport` is the scrolling element (positioned, so rows' offsetTop is measured from it) and
 * `rows` the element holding the row elements, which carry `data-chat-message`.
 */
export function useMessageWindow(
    viewport: RefObject<HTMLElement | null>,
    rows: RefObject<HTMLElement | null>,
    ids: readonly number[],
): MessageWindow {
    const heights = useRef(new Map<number, number>());
    const [state, setState] = useState<WindowState | null>(null);
    const idsRef = useRef(ids);
    const frame = useRef(0);
    const observer = useRef<ResizeObserver | null>(null);
    const observed = useRef(new Set<Element>());

    // A row's height is the distance to the next element, so collapsed margins are counted once.
    const measureRows = useCallback(() => {
        const container = rows.current;
        if (!container) return;
        const children = Array.from(container.children) as HTMLElement[];
        children.forEach((child, index) => {
            const id = Number(child.dataset.chatMessage);
            if (!id) return;
            const next = children[index + 1];
            const height = next
                ? next.offsetTop - child.offsetTop
                : child.offsetHeight;
            if (height > 0) heights.current.set(id, height);
        });
    }, [rows]);

    const compute = useCallback(() => {
        frame.current = 0;
        const element = viewport.current;
        const container = rows.current;
        const list = idsRef.current;
        if (
            !element ||
            !container ||
            list.length < MESSAGE_WINDOW_FROM ||
            element.clientHeight <= 0
        ) {
            setState((current) => (current === null ? current : null));
            return;
        }
        measureRows();
        const known = list.map(
            (id) => heights.current.get(id) ?? MESSAGE_ROW_ESTIMATE,
        );
        const range = visibleMessageRange(
            known,
            element.scrollTop - container.offsetTop,
            element.clientHeight,
        );
        const next: WindowState = {
            startId: list[range.start],
            endId: list[range.end],
            fromStart: range.start === 0,
            toEnd: range.end === list.length - 1,
            before: sumHeights(known, 0, range.start),
            after: sumHeights(known, range.end + 1, list.length),
        };
        setState((current) =>
            current &&
            current.startId === next.startId &&
            current.endId === next.endId &&
            current.fromStart === next.fromStart &&
            current.toEnd === next.toEnd &&
            current.before === next.before &&
            current.after === next.after
                ? current
                : next,
        );
    }, [viewport, rows, measureRows]);

    const update = useCallback(() => {
        if (!frame.current) frame.current = requestAnimationFrame(compute);
    }, [compute]);

    useEffect(() => {
        if (typeof ResizeObserver === 'undefined') return;
        const resize = new ResizeObserver(() => update());
        observer.current = resize;
        if (viewport.current) resize.observe(viewport.current);
        const tracked = observed.current;
        return () => {
            resize.disconnect();
            tracked.clear();
            observer.current = null;
            cancelAnimationFrame(frame.current);
            frame.current = 0;
        };
    }, [viewport, update]);

    const count = ids.length;
    let start = 0;
    let end = count - 1;
    if (state && count >= MESSAGE_WINDOW_FROM) {
        const from = state.fromStart ? 0 : ids.indexOf(state.startId);
        const to = state.toEnd ? count - 1 : ids.indexOf(state.endId);
        if (from >= 0 && to >= from) {
            start = from;
            end = to;
        }
    }
    const windowed = start > 0 || end < count - 1;

    // Watch the mounted rows (a photo loading changes a height) and stop watching removed ones.
    useLayoutEffect(() => {
        idsRef.current = ids;
        measureRows();
        const resize = observer.current;
        const container = rows.current;
        if (resize && container) {
            for (const element of observed.current)
                if (!element.isConnected) {
                    resize.unobserve(element);
                    observed.current.delete(element);
                }
            for (const child of Array.from(container.children))
                if (
                    (child as HTMLElement).dataset.chatMessage &&
                    !observed.current.has(child)
                ) {
                    resize.observe(child);
                    observed.current.add(child);
                }
        }
        update();
    }, [ids, start, end, rows, measureRows, update]);

    return {
        start,
        end,
        // Until the next measure, rows added at a mounted edge need no spacer there.
        before: windowed && start > 0 && state ? state.before : 0,
        after: windowed && end < count - 1 && state ? state.after : 0,
        windowed,
        update,
    };
}
