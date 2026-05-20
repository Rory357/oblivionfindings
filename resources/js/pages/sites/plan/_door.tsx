import type { ReactNode } from 'react';
import { normaliseDoor, type NormalisedDoor, type PlanDoor } from './_types';

const STROKE = '#1f2937';
/** Door panel (leaf) thickness in canvas units. */
const PANEL_THICKNESS = 2.4;

type DoorSymbolProps = {
    door: PlanDoor;
    canvasWidth: number;
    canvasHeight: number;
    selected?: boolean;
    pending?: boolean;
    /**
     * The door isn't attached to a wall (`wall_id == null`). Render with an
     * amber dashed outline so users can see it's floating and needs to be
     * dragged back near a wall.
     */
    detached?: boolean;
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
    detached = false,
    onPointerDown,
    onClick,
    onContextMenu,
    onDoubleClick,
}: DoorSymbolProps) {
    const normalised = normaliseDoor(door);
    const x = normalised.x * canvasWidth;
    const y = normalised.y * canvasHeight;
    const w = normalised.width * canvasWidth;

    const click = clickShieldBox(w);
    const visual = symbolBoundingBox(normalised, w);
    const outlineStroke = selected
        ? detached
            ? '#d97706'
            : '#2563eb'
        : pending
          ? '#2563eb'
          : detached
            ? '#d97706'
            : 'transparent';
    const outlineDash = detached || (pending && !selected) ? '6 4' : undefined;
    const showOutline = selected || pending || detached;
    const cursorStyle: React.CSSProperties = { cursor: selected ? 'grab' : 'move' };

    return (
        <g>
            {/* Click shield — narrow rect over the opening (not the swing arc). */}
            <rect
                x={x + click.minX - 4}
                y={y + click.minY - 4}
                width={click.maxX - click.minX + 8}
                height={click.maxY - click.minY + 8}
                fill="transparent"
                onPointerDown={onPointerDown}
                onClick={onClick}
                onContextMenu={onContextMenu}
                onDoubleClick={onDoubleClick}
                style={cursorStyle}
            />
            {/* Selection / pending / detached outline tracks the full symbol bbox. */}
            {showOutline && (
                <rect
                    x={x + visual.minX - 4}
                    y={y + visual.minY - 4}
                    width={visual.maxX - visual.minX + 8}
                    height={visual.maxY - visual.minY + 8}
                    fill="none"
                    stroke={outlineStroke}
                    strokeWidth={1.5}
                    strokeDasharray={outlineDash}
                    pointerEvents="none"
                />
            )}
            <SymbolPaths door={normalised} x={x} y={y} w={w} />
        </g>
    );
}

/**
 * Hit area for clicks — sized to just the opening plus a small margin around
 * the door panel. Stays clear of the swing arc so clicks in the arc region
 * (which can extend deep into the room) don't accidentally select the door.
 */
function clickShieldBox(w: number): { minX: number; maxX: number; minY: number; maxY: number } {
    const margin = Math.max(6, w * 0.18);
    return { minX: 0, maxX: w, minY: -margin, maxY: margin };
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
    return (
        <>
            {/* Block-out: covers the cut wall area so room fill / grid don't show through. */}
            <OpeningClear x={x} y={y} w={w} />
            {symbolFor(door, x, y, w)}
        </>
    );
}

function symbolFor(door: NormalisedDoor, x: number, y: number, w: number): ReactNode {
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

/**
 * White rect drawn under the door symbol to mask the wall cut + anything
 * behind it, so the door reads as a solid opening rather than transparent.
 */
function OpeningClear({ x, y, w }: { x: number; y: number; w: number }) {
    return <rect x={x} y={y - 5} width={w} height={10} fill="#ffffff" pointerEvents="none" />;
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
    // The door leaf is drawn as a thin filled rect (length `w`, hinge-aligned)
    // rotated so it points to `end`. This reads as a solid door instead of a
    // hairline stroke.
    const angle = Math.atan2(
        config.end[1] - config.hinge[1],
        config.end[0] - config.hinge[0],
    );
    const angleDeg = (angle * 180) / Math.PI;
    return (
        <>
            <WallStops x={x} y={y} w={w} />
            <rect
                x={hinge.x}
                y={hinge.y - PANEL_THICKNESS / 2}
                width={w}
                height={PANEL_THICKNESS}
                fill={STROKE}
                transform={`rotate(${angleDeg} ${hinge.x} ${hinge.y})`}
            />
            <path
                d={`M ${x + (config.end[0] * w)},${y + (config.end[1] * w)} A ${w},${w} 0 0 ${config.arcSweep} ${
                    config.swing_side === 'right' ? x : x + w
                },${y}`}
                stroke={STROKE}
                strokeWidth={1.2}
                strokeDasharray="4 3"
                fill="none"
                opacity={0.6}
            />
            <circle cx={hinge.x} cy={hinge.y} r={2.5} fill={STROKE} />
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
    const out = door.swing_direction === 'out';
    const half = w / 2;
    const leafEndY = out ? y - half : y + half;
    // Leaves are shown open, perpendicular to the wall, half-width long.
    const leafTopY = out ? y - half : y;
    const leftSweep: 0 | 1 = out ? 0 : 1;
    const rightSweep: 0 | 1 = out ? 1 : 0;
    return (
        <>
            <WallStops x={x} y={y} w={w} />
            {/* Left leaf */}
            <rect
                x={x - PANEL_THICKNESS / 2}
                y={leafTopY}
                width={PANEL_THICKNESS}
                height={half}
                fill={STROKE}
            />
            <path
                d={`M ${x},${leafEndY} A ${half},${half} 0 0 ${leftSweep} ${x + half},${y}`}
                stroke={STROKE}
                strokeWidth={1.2}
                strokeDasharray="4 3"
                fill="none"
                opacity={0.6}
            />
            <circle cx={x} cy={y} r={2.5} fill={STROKE} />
            {/* Right leaf */}
            <rect
                x={x + w - PANEL_THICKNESS / 2}
                y={leafTopY}
                width={PANEL_THICKNESS}
                height={half}
                fill={STROKE}
            />
            <path
                d={`M ${x + w},${leafEndY} A ${half},${half} 0 0 ${rightSweep} ${x + half},${y}`}
                stroke={STROKE}
                strokeWidth={1.2}
                strokeDasharray="4 3"
                fill="none"
                opacity={0.6}
            />
            <circle cx={x + w} cy={y} r={2.5} fill={STROKE} />
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
