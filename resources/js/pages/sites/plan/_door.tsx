import type { ReactNode } from 'react';
import { normaliseDoor, type NormalisedDoor, type PlanDoor } from './_types';

const STROKE = '#1f2937';

type DoorSymbolProps = {
    door: PlanDoor;
    canvasWidth: number;
    canvasHeight: number;
    selected?: boolean;
    pending?: boolean;
    onPointerDown?: (event: React.PointerEvent<SVGElement>) => void;
    onClick?: (event: React.MouseEvent<SVGElement>) => void;
    onContextMenu?: (event: React.MouseEvent<SVGElement>) => void;
    onDoubleClick?: (event: React.MouseEvent<SVGElement>) => void;
};

/**
 * Render an architectural door symbol for the given PlanDoor.
 * The returned <g> includes a transparent hit shield covering the symbol's
 * bounding box so pointer events behave like the old solid <rect>.
 */
export function DoorSymbol({
    door,
    canvasWidth,
    canvasHeight,
    selected = false,
    pending = false,
    onPointerDown,
    onClick,
    onContextMenu,
    onDoubleClick,
}: DoorSymbolProps) {
    const normalised = normaliseDoor(door);
    const x = normalised.x * canvasWidth;
    const y = normalised.y * canvasHeight;
    const w = normalised.width * canvasWidth;

    const bbox = symbolBoundingBox(normalised, w);
    const highlightStroke = selected || pending ? '#2563eb' : 'transparent';
    const highlightDash = pending && !selected ? '6 4' : undefined;
    const cursorStyle: React.CSSProperties = { cursor: selected ? 'grab' : 'move' };

    return (
        <g>
            {/* Hit shield — transparent rect sized to the symbol bbox */}
            <rect
                x={x + bbox.minX - 4}
                y={y + bbox.minY - 4}
                width={bbox.maxX - bbox.minX + 8}
                height={bbox.maxY - bbox.minY + 8}
                fill="transparent"
                stroke={highlightStroke}
                strokeWidth={selected || pending ? 1.5 : 0}
                strokeDasharray={highlightDash}
                onPointerDown={onPointerDown}
                onClick={onClick}
                onContextMenu={onContextMenu}
                onDoubleClick={onDoubleClick}
                style={cursorStyle}
            />
            <SymbolPaths door={normalised} x={x} y={y} w={w} />
        </g>
    );
}

/**
 * Centre of the opening — used as the pivot for rotation handles.
 */
export function doorCentreNormalised(door: PlanDoor): { x: number; y: number } {
    const n = normaliseDoor(door);
    return { x: n.x + n.width / 2, y: n.y };
}

type SwingKey = 'right-in' | 'right-out' | 'left-in' | 'left-out';

function swingKey(door: NormalisedDoor): SwingKey {
    return `${door.swing_side}-${door.swing_direction}` as SwingKey;
}

function SymbolPaths({ door, x, y, w }: { door: NormalisedDoor; x: number; y: number; w: number }): ReactNode {
    switch (door.subkind) {
        case 'single_swing':
            return <SingleSwing door={door} x={x} y={y} w={w} />;
        case 'double_swing':
            return <DoubleSwing door={door} x={x} y={y} w={w} />;
        case 'sliding':
            return <Sliding x={x} y={y} w={w} />;
        case 'pocket':
            return <Pocket door={door} x={x} y={y} w={w} />;
        case 'bifold':
            return <Bifold x={x} y={y} w={w} />;
        case 'folding':
            return <Folding x={x} y={y} w={w} />;
        case 'garage':
            return <Garage x={x} y={y} w={w} />;
        case 'revolving':
            return <Revolving x={x} y={y} w={w} />;
        default:
            return <SingleSwing door={door} x={x} y={y} w={w} />;
    }
}

function WallStops({ x, y, w }: { x: number; y: number; w: number }) {
    return (
        <>
            <path d={`M ${x},${y - 3} L ${x},${y + 3}`} stroke={STROKE} strokeWidth={2} />
            <path d={`M ${x + w},${y - 3} L ${x + w},${y + 3}`} stroke={STROKE} strokeWidth={2} />
        </>
    );
}

function SingleSwing({ door, x, y, w }: { door: NormalisedDoor; x: number; y: number; w: number }) {
    const key = swingKey(door);
    const config = SWING_PATHS[key];
    const hinge = { x: x + config.hinge[0] * w, y: y + config.hinge[1] * w };
    const end = { x: x + config.end[0] * w, y: y + config.end[1] * w };
    return (
        <>
            <WallStops x={x} y={y} w={w} />
            <path
                d={`M ${hinge.x},${hinge.y} L ${end.x},${end.y}`}
                stroke={STROKE}
                strokeWidth={2}
                strokeLinecap="round"
                fill="none"
            />
            <path
                d={`M ${end.x},${end.y} A ${w},${w} 0 0 ${config.arcSweep} ${
                    config.swing_side === 'right' ? x : x + w
                },${y}`}
                stroke={STROKE}
                strokeWidth={1.5}
                fill="none"
            />
            <circle cx={hinge.x} cy={hinge.y} r={2} fill={STROKE} />
        </>
    );
}

/**
 * Lookup table for single-swing geometry. `hinge` and `end` are multipliers of `w`
 * relative to the opening's top-left `(x, y)`. `arcSweep` is the SVG arc sweep flag.
 */
const SWING_PATHS: Record<
    SwingKey,
    { hinge: [number, number]; end: [number, number]; arcSweep: 0 | 1; swing_side: 'left' | 'right' }
> = {
    'right-in': { hinge: [1, 0], end: [1, 1], arcSweep: 1, swing_side: 'right' },
    'right-out': { hinge: [1, 0], end: [1, -1], arcSweep: 0, swing_side: 'right' },
    'left-in': { hinge: [0, 0], end: [0, 1], arcSweep: 0, swing_side: 'left' },
    'left-out': { hinge: [0, 0], end: [0, -1], arcSweep: 1, swing_side: 'left' },
};

function DoubleSwing({ door, x, y, w }: { door: NormalisedDoor; x: number; y: number; w: number }) {
    // Both leaves are w/2 wide. swing_direction flips both arcs together.
    const out = door.swing_direction === 'out';
    const leafEndY = out ? y - w / 2 : y + w / 2;
    // For 'in': left leaf sweeps clockwise (arc=1), right leaf counter-clockwise (arc=0) so they curl inward.
    // For 'out': flip both.
    const leftSweep: 0 | 1 = out ? 0 : 1;
    const rightSweep: 0 | 1 = out ? 1 : 0;
    return (
        <>
            <WallStops x={x} y={y} w={w} />
            {/* Left leaf */}
            <path d={`M ${x},${y} L ${x},${leafEndY}`} stroke={STROKE} strokeWidth={2} strokeLinecap="round" fill="none" />
            <path
                d={`M ${x},${leafEndY} A ${w / 2},${w / 2} 0 0 ${leftSweep} ${x + w / 2},${y}`}
                stroke={STROKE}
                strokeWidth={1.5}
                fill="none"
            />
            <circle cx={x} cy={y} r={2} fill={STROKE} />
            {/* Right leaf */}
            <path d={`M ${x + w},${y} L ${x + w},${leafEndY}`} stroke={STROKE} strokeWidth={2} strokeLinecap="round" fill="none" />
            <path
                d={`M ${x + w},${leafEndY} A ${w / 2},${w / 2} 0 0 ${rightSweep} ${x + w / 2},${y}`}
                stroke={STROKE}
                strokeWidth={1.5}
                fill="none"
            />
            <circle cx={x + w} cy={y} r={2} fill={STROKE} />
        </>
    );
}

function Sliding({ x, y, w }: { x: number; y: number; w: number }) {
    return (
        <>
            <path d={`M ${x},${y - 2} L ${x + w},${y - 2}`} stroke={STROKE} strokeWidth={1} />
            <rect x={x} y={y - 1} width={w * 0.55} height={3} fill={STROKE} />
            <rect x={x + w * 0.45} y={y + 3} width={w * 0.55} height={3} fill={STROKE} />
            <path
                d={`M ${x + w - 6},${y + 4.5} L ${x + w - 2},${y + 4.5} M ${x + w - 4},${y + 3} L ${x + w - 2},${y + 4.5} L ${x + w - 4},${y + 6}`}
                stroke={STROKE}
                strokeWidth={1.2}
                fill="none"
            />
        </>
    );
}

function Pocket({ door, x, y, w }: { door: NormalisedDoor; x: number; y: number; w: number }) {
    // Pocket extends behind the wall on the hinge side. For 'left' the pocket is on the left, otherwise right.
    const pocketLeft = door.swing_side === 'left';
    const pocketX = pocketLeft ? x - w * 0.9 : x + w;
    const stubX = pocketLeft ? x + w : x; // the visible-opening jamb (the side opposite the pocket)
    return (
        <>
            <path d={`M ${stubX},${y - 3} L ${stubX},${y + 3}`} stroke={STROKE} strokeWidth={2} />
            <rect
                x={pocketX}
                y={y - 4}
                width={w * 0.9}
                height={8}
                fill="none"
                stroke={STROKE}
                strokeWidth={1}
                strokeDasharray="3 3"
            />
            {/* Leaf shown partially withdrawn into the pocket */}
            <rect
                x={pocketLeft ? x - w * 0.7 : x + w * 0.1}
                y={y - 1}
                width={w * 0.6}
                height={3}
                fill={STROKE}
            />
        </>
    );
}

function Bifold({ x, y, w }: { x: number; y: number; w: number }) {
    return (
        <>
            <WallStops x={x} y={y} w={w} />
            <path
                d={`M ${x},${y} L ${x + w / 2},${y + w / 2} L ${x + w},${y}`}
                stroke={STROKE}
                strokeWidth={2}
                fill="none"
                strokeLinecap="round"
            />
            <circle cx={x} cy={y} r={2} fill={STROKE} />
            <circle cx={x + w} cy={y} r={2} fill={STROKE} />
        </>
    );
}

function Folding({ x, y, w }: { x: number; y: number; w: number }) {
    const points = [
        `M ${x},${y}`,
        `L ${x + w * 0.25},${y + w * 0.25}`,
        `L ${x + w * 0.5},${y}`,
        `L ${x + w * 0.75},${y + w * 0.25}`,
        `L ${x + w},${y}`,
    ].join(' ');
    return (
        <>
            <WallStops x={x} y={y} w={w} />
            <path d={points} stroke={STROKE} strokeWidth={2} fill="none" strokeLinecap="round" />
            <circle cx={x} cy={y} r={2} fill={STROKE} />
            <circle cx={x + w} cy={y} r={2} fill={STROKE} />
        </>
    );
}

function Garage({ x, y, w }: { x: number; y: number; w: number }) {
    const panels = 6;
    const lines: ReactNode[] = [];
    for (let i = 1; i < panels; i += 1) {
        const lx = x + (w * i) / panels;
        lines.push(<line key={`g-${i}`} x1={lx} y1={y - 1} x2={lx} y2={y + 5} stroke="#ffffff" strokeWidth={1} />);
    }
    return (
        <>
            <rect x={x} y={y - 1} width={w} height={6} fill={STROKE} stroke={STROKE} strokeWidth={1} />
            {lines}
        </>
    );
}

function Revolving({ x, y, w }: { x: number; y: number; w: number }) {
    const cx = x + w / 2;
    const cy = y;
    const r = w / 2;
    return (
        <>
            <circle cx={cx} cy={cy} r={r} fill="none" stroke={STROKE} strokeWidth={1.5} />
            <path d={`M ${cx - r},${cy} L ${cx + r},${cy}`} stroke={STROKE} strokeWidth={1.5} />
            <path d={`M ${cx},${cy - r} L ${cx},${cy + r}`} stroke={STROKE} strokeWidth={1.5} />
        </>
    );
}

/**
 * Bounding box of the rendered symbol in local SVG units, relative to the
 * opening's top-left (so caller adds `x, y` themselves).
 */
function symbolBoundingBox(door: NormalisedDoor, w: number): { minX: number; maxX: number; minY: number; maxY: number } {
    switch (door.subkind) {
        case 'single_swing': {
            const out = door.swing_direction === 'out';
            return {
                minX: 0,
                maxX: w,
                minY: out ? -w : -3,
                maxY: out ? 3 : w,
            };
        }
        case 'double_swing': {
            const out = door.swing_direction === 'out';
            return {
                minX: 0,
                maxX: w,
                minY: out ? -w / 2 : -3,
                maxY: out ? 3 : w / 2,
            };
        }
        case 'sliding':
            return { minX: 0, maxX: w, minY: -3, maxY: 7 };
        case 'pocket': {
            const pocketLeft = door.swing_side === 'left';
            return {
                minX: pocketLeft ? -w * 0.9 : 0,
                maxX: pocketLeft ? w : w * 1.9,
                minY: -4,
                maxY: 4,
            };
        }
        case 'bifold':
            return { minX: 0, maxX: w, minY: -3, maxY: w / 2 };
        case 'folding':
            return { minX: 0, maxX: w, minY: -3, maxY: w * 0.25 };
        case 'garage':
            return { minX: 0, maxX: w, minY: -2, maxY: 6 };
        case 'revolving':
            return { minX: 0, maxX: w, minY: -w / 2, maxY: w / 2 };
        default:
            return { minX: 0, maxX: w, minY: -3, maxY: w };
    }
}
