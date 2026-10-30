import { Maximize2 } from 'lucide-react';
import {
    useId,
    useRef,
    useState,
    type KeyboardEvent,
    type PointerEvent,
} from 'react';

export type BlockSize = { width: number; min_height: number };
type ResizeAxis = 'both' | 'width' | 'height';
const widths = [4, 6, 8, 12];
const boundedHeight = (height: number) =>
    Math.max(0, Math.min(800, Math.round(height / 36) * 36));

export function blockWidthLabel(width: number) {
    return (
        (
            {
                4: 'One third',
                6: 'Half width',
                8: 'Two thirds',
                12: 'Full width',
            } as Record<number, string>
        )[width] ?? `${width} of 12 columns`
    );
}

export function BlockResizeHandle({
    label,
    size,
    onPreview,
    onCommit,
    onSelect,
}: {
    label: string;
    size: BlockSize;
    onPreview: (size: BlockSize | null) => void;
    onCommit: (size: BlockSize) => void;
    onSelect: () => void;
}) {
    const helpId = useId();
    const [announcement, setAnnouncement] = useState('');
    const gesture = useRef<{
        pointerId: number;
        axis: ResizeAxis;
        x: number;
        y: number;
        size: BlockSize;
        renderedHeight: number;
        unit: number;
        draft: BlockSize | null;
    } | null>(null);
    const commit = (next: BlockSize) => {
        if (next.width !== size.width || next.min_height !== size.min_height) {
            onCommit(next);
            setAnnouncement(
                `Resized ${label} to ${next.width} of 12 columns; ${next.min_height ? `minimum height ${next.min_height} pixels` : 'automatic height'}.`,
            );
        }
    };
    const start = (
        event: PointerEvent<HTMLButtonElement>,
        axis: ResizeAxis,
    ) => {
        if (event.button !== 0) return;
        if (gesture.current) return;
        const article = event.currentTarget.closest('article');
        const grid = article?.parentElement;
        if (!article || !grid) return;
        event.preventDefault();
        event.stopPropagation();
        event.currentTarget.focus();
        const gridStyle = getComputedStyle(grid);
        const gap = parseFloat(gridStyle.columnGap) || 0;
        const padding =
            (parseFloat(gridStyle.paddingLeft) || 0) +
            (parseFloat(gridStyle.paddingRight) || 0);
        gesture.current = {
            pointerId: event.pointerId,
            axis,
            x: event.clientX,
            y: event.clientY,
            size,
            renderedHeight:
                article
                    .querySelector('.hub-designer-render')
                    ?.getBoundingClientRect().height ?? 0,
            unit: Math.max(1, (grid.clientWidth - padding + gap) / 12),
            draft: null,
        };
        event.currentTarget.setPointerCapture(event.pointerId);
        onSelect();
    };
    const move = (event: PointerEvent<HTMLButtonElement>) => {
        const current = gesture.current;
        if (!current || current.pointerId !== event.pointerId) return;
        const dx = current.axis === 'height' ? 0 : event.clientX - current.x,
            dy = current.axis === 'width' ? 0 : event.clientY - current.y;
        if (!current.draft && Math.hypot(dx, dy) < 6) return;
        const desired = current.size.width + dx / current.unit;
        current.draft = {
            width:
                Math.abs(dx) < 6
                    ? current.size.width
                    : widths.reduce((best, width) =>
                          Math.abs(width - desired) < Math.abs(best - desired)
                              ? width
                              : best,
                      ),
            min_height:
                Math.abs(dy) < 6
                    ? current.size.min_height
                    : boundedHeight(current.renderedHeight + dy),
        };
        onPreview(current.draft);
    };
    const finish = (button: HTMLButtonElement, cancel = false) => {
        const current = gesture.current;
        if (!current) return;
        gesture.current = null;
        onPreview(null);
        if (button.hasPointerCapture(current.pointerId))
            button.releasePointerCapture(current.pointerId);
        if (!cancel && current.draft) commit(current.draft);
        if (cancel) setAnnouncement('Resize cancelled.');
    };
    const key = (event: KeyboardEvent<HTMLButtonElement>, axis: ResizeAxis) => {
        if (gesture.current) {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                finish(event.currentTarget, true);
            }
            return;
        }
        if (
            !['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(
                event.key,
            )
        )
            return;
        event.preventDefault();
        event.stopPropagation();
        onSelect();
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
            if (axis === 'height') return;
            const next =
                event.key === 'ArrowRight'
                    ? widths.find((width) => width > size.width)
                    : widths.findLast((width) => width < size.width);
            commit({ ...size, width: next ?? size.width });
        } else {
            if (axis === 'width') return;
            const renderedHeight =
                event.currentTarget
                    .closest('article')
                    ?.querySelector('.hub-designer-render')
                    ?.getBoundingClientRect().height ?? 0;
            commit({
                ...size,
                min_height: boundedHeight(
                    (size.min_height || renderedHeight) +
                        (event.key === 'ArrowDown' ? 36 : -36),
                ),
            });
        }
    };
    return (
        <>
            {(['width', 'height', 'both'] as const).map((axis) => (
                <button
                    key={axis}
                    type="button"
                    className={
                        axis === 'both'
                            ? 'hub-corner-resize'
                            : `hub-edge-resize hub-edge-resize-${axis}`
                    }
                    aria-label={
                        axis === 'both'
                            ? `Resize ${label}`
                            : `Resize ${axis} of ${label}`
                    }
                    title={
                        axis === 'both'
                            ? 'Drag corner to resize'
                            : `Drag to change ${axis}`
                    }
                    aria-describedby={helpId}
                    onPointerDown={(event) => start(event, axis)}
                    onPointerMove={move}
                    onPointerUp={(event) => {
                        if (gesture.current?.pointerId === event.pointerId)
                            finish(event.currentTarget);
                    }}
                    onPointerCancel={(event) => {
                        if (gesture.current?.pointerId === event.pointerId)
                            finish(event.currentTarget, true);
                    }}
                    onLostPointerCapture={(event) =>
                        gesture.current?.pointerId === event.pointerId &&
                        finish(event.currentTarget, true)
                    }
                    onKeyDown={(event) => key(event, axis)}
                >
                    {axis === 'both' && (
                        <Maximize2 className="size-4" aria-hidden="true" />
                    )}
                </button>
            ))}
            <span id={helpId} className="sr-only">
                Drag a corner to resize both dimensions, the right edge for
                width, or the bottom edge for height. Use Left and Right for
                width, Up and Down for minimum height. Escape cancels a drag.
            </span>
            <span className="sr-only" role="status">
                {announcement}
            </span>
        </>
    );
}
