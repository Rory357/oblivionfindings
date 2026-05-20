import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import * as Lucide from 'lucide-react';
import {
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
    type CSSProperties,
    type Dispatch,
} from 'react';
import { toast } from 'sonner';
import { DoorSymbol } from './_door';
import {
    ROOM_EDGE_WALL_PREFIX,
    inferSwingDirection,
    openingCentreFromTopLeft,
    resolveAttachedOpening,
    roomEdgeWalls,
    roomIdFromEdgeWallId,
    snapOpeningWithRoomFallback,
    wallSegmentsWithOpenings,
    type AttachedOpening,
} from './_geometry';
import {
    distanceCanvasUnits,
    formatMeters,
    isEmergencyPlanKind,
    isSelectMode,
    metersPerUnit,
    normaliseDoor,
    normaliseLayout,
    type BuilderMode,
    type DoorSubkind,
    type PlanDoor,
    type PlanLabel,
    type PlanLayout,
    type PlanPin,
    type PlanRoom,
    type PlanWall,
    type PlanWindow,
    type SelectionRef,
    type Taxonomy,
} from './_types';
import {
    refKey,
    sameRef,
    type EditingTarget,
    type EditorAction,
    type Interaction,
    type LayerKey,
} from './_use-plan-editor';

type Props = {
    layout: PlanLayout;
    pins: PlanPin[];
    selection: SelectionRef[];
    editing: EditingTarget | null;
    activeKind: string | null;
    activeSubkind: string | null;
    interaction: Interaction;
    layers: Record<LayerKey, boolean>;
    taxonomy: Taxonomy | null;
    mode?: BuilderMode;
    emergencyKinds?: string[];
    validationErrors?: Record<string, string>;
    dispatch: Dispatch<EditorAction>;
    onRequestCalibration: (
        firstPoint: { x: number; y: number },
        secondPoint: { x: number; y: number },
    ) => void;
};

type DragBase =
    | { kind: 'room'; base: PlanRoom }
    | { kind: 'wall'; base: PlanWall }
    | { kind: 'door'; base: PlanDoor }
    | { kind: 'window'; base: PlanWindow }
    | { kind: 'label'; base: PlanLabel }
    | { kind: 'pin'; index: number; base: PlanPin };

type DragRef =
    | {
          mode: 'move';
          pointerId: number;
          origin: { x: number; y: number };
          target: SelectionRef;
          bases: Map<string, DragBase>;
          committed: boolean;
      }
    | {
          mode: 'resize';
          pointerId: number;
          origin: { x: number; y: number };
          roomId: string;
          base: PlanRoom;
          handle: ResizeHandle;
          committed: boolean;
      }
    | {
          mode: 'wall-endpoint';
          pointerId: number;
          origin: { x: number; y: number };
          wallId: string;
          base: PlanWall;
          index: number;
          committed: boolean;
      }
    | {
          mode: 'rotate';
          pointerId: number;
          ref: SelectionRef;
          centre: { x: number; y: number };
          baseRotation: number;
          startAngle: number;
          committed: boolean;
      };

type CanvasPoint = { x: number; y: number };
type MarqueeRef = {
    pointerId: number | null;
    firstPoint: CanvasPoint;
    cursor: CanvasPoint;
    moved: boolean;
};

type ResizeHandle = 'nw' | 'n' | 'ne' | 'e' | 'se' | 's' | 'sw' | 'w';
const RESIZE_HANDLES: ResizeHandle[] = [
    'nw',
    'n',
    'ne',
    'e',
    'se',
    's',
    'sw',
    'w',
];

type IconProps = { className?: string; style?: CSSProperties };

function clamp(value: number, min: number, max: number): number {
    return Math.min(max, Math.max(min, value));
}
function clamp01(value: number): number {
    return clamp(value, 0, 1);
}

function resolveIcon(name: string): React.ComponentType<IconProps> {
    const candidate = (
        Lucide as unknown as Record<string, React.ComponentType<IconProps>>
    )[name];
    return (
        candidate ??
        (Lucide.MapPin as unknown as React.ComponentType<IconProps>)
    );
}

function pinStyle(
    kind: string,
    taxonomy: Taxonomy | null,
): { color: string; icon: string } {
    if (taxonomy?.kinds?.[kind]) {
        return {
            color: taxonomy.kinds[kind].color,
            icon: taxonomy.kinds[kind].icon,
        };
    }
    return { color: '#475569', icon: 'MapPin' };
}

function pinIdOf(pin: PlanPin, index: number): string {
    return pin.id != null ? String(pin.id) : `__idx-${index}`;
}

function layerOfKind(kind: string, taxonomy: Taxonomy | null): LayerKey {
    if (!taxonomy) return 'annotation';
    for (const group of taxonomy.groups) {
        if (group.kinds.includes(kind)) return group.id as LayerKey;
    }
    return 'annotation';
}

function snapToGrid(
    point: { x: number; y: number },
    layout: PlanLayout,
    canvas: { width: number; height: number },
    shouldSnap: boolean,
): { x: number; y: number } {
    if (!shouldSnap) return point;
    const gridSize = layout.grid?.size ?? 10;
    const sx = gridSize / canvas.width;
    const sy = gridSize / canvas.height;
    return {
        x: Math.round(point.x / sx) * sx,
        y: Math.round(point.y / sy) * sy,
    };
}

function constrainAngle(
    start: { x: number; y: number },
    end: { x: number; y: number },
): { x: number; y: number } {
    const dx = end.x - start.x;
    const dy = end.y - start.y;
    if (Math.abs(dx) < 1e-6 && Math.abs(dy) < 1e-6) return end;
    const angle = Math.atan2(dy, dx);
    const step = Math.PI / 4;
    const snapped = Math.round(angle / step) * step;
    const length = Math.hypot(dx, dy);
    return {
        x: start.x + Math.cos(snapped) * length,
        y: start.y + Math.sin(snapped) * length,
    };
}

function isRefInSelection(
    selection: SelectionRef[],
    ref: SelectionRef,
): boolean {
    return selection.some((s) => sameRef(s, ref));
}

/**
 * Items whose centre lies inside the marquee rect are selected.
 */
function refsInsideMarquee(
    layout: ReturnType<typeof normaliseLayout>,
    pins: PlanPin[],
    rect: { x1: number; y1: number; x2: number; y2: number },
    isVisible: (kind: string) => boolean,
    showStructure: boolean,
): SelectionRef[] {
    const x1 = Math.min(rect.x1, rect.x2);
    const x2 = Math.max(rect.x1, rect.x2);
    const y1 = Math.min(rect.y1, rect.y2);
    const y2 = Math.max(rect.y1, rect.y2);
    function inside(p: { x: number; y: number }) {
        return p.x >= x1 && p.x <= x2 && p.y >= y1 && p.y <= y2;
    }
    const result: SelectionRef[] = [];
    if (showStructure) {
        for (const room of layout.rooms) {
            if (
                inside({
                    x: room.x + room.width / 2,
                    y: room.y + room.height / 2,
                })
            ) {
                result.push({ type: 'room', id: room.id });
            }
        }
        for (const wall of layout.walls) {
            if (wall.points.length < 2) continue;
            const a = wall.points[0];
            const b = wall.points[wall.points.length - 1];
            if (inside({ x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 })) {
                result.push({ type: 'wall', id: wall.id });
            }
        }
        for (const door of layout.doors) {
            const normalised = normaliseDoor(door);
            const centre = {
                x: normalised.x + normalised.width / 2,
                y: normalised.y,
            };
            if (inside(centre))
                result.push({ type: 'door', id: door.id });
        }
        for (const win of layout.windows) {
            const w = win.width ?? 0.1;
            const centre = { x: win.x + w / 2, y: win.y };
            if (inside(centre))
                result.push({ type: 'window', id: win.id });
        }
        for (const label of layout.labels) {
            if (inside({ x: label.x, y: label.y }))
                result.push({ type: 'label', id: label.id });
        }
    }
    pins.forEach((pin, index) => {
        if (!isVisible(pin.kind)) return;
        if (inside({ x: pin.x, y: pin.y })) {
            result.push({ type: 'pin', id: pinIdOf(pin, index) });
        }
    });
    return result;
}

function selectionBounds(
    layout: ReturnType<typeof normaliseLayout>,
    pins: PlanPin[],
    selection: SelectionRef[],
): { x: number; y: number; width: number; height: number } | null {
    const points: Array<{ x: number; y: number }> = [];

    for (const ref of selection) {
        if (ref.type === 'room') {
            const room = layout.rooms.find((item) => item.id === ref.id);
            if (room) {
                points.push(
                    { x: room.x, y: room.y },
                    { x: room.x + room.width, y: room.y + room.height },
                );
            }
        } else if (ref.type === 'wall') {
            const wall = layout.walls.find((item) => item.id === ref.id);
            if (wall) points.push(...wall.points);
        } else if (ref.type === 'door') {
            const door = layout.doors.find((item) => item.id === ref.id);
            if (door) points.push({ x: door.x, y: door.y });
        } else if (ref.type === 'window') {
            const win = layout.windows.find((item) => item.id === ref.id);
            if (win) points.push({ x: win.x, y: win.y });
        } else if (ref.type === 'label') {
            const label = layout.labels.find((item) => item.id === ref.id);
            if (label) points.push({ x: label.x, y: label.y });
        } else if (ref.type === 'pin') {
            const pin = pins.find(
                (item, index) => pinIdOf(item, index) === String(ref.id),
            );
            if (pin) points.push({ x: pin.x, y: pin.y });
        }
    }

    if (points.length === 0) return null;

    const minX = Math.min(...points.map((point) => point.x));
    const maxX = Math.max(...points.map((point) => point.x));
    const minY = Math.min(...points.map((point) => point.y));
    const maxY = Math.max(...points.map((point) => point.y));

    return { x: minX, y: minY, width: maxX - minX, height: maxY - minY };
}

export default function PlanCanvas(props: Props) {
    const {
        layout: rawLayout,
        pins,
        selection,
        editing,
        activeKind,
        activeSubkind,
        interaction,
        layers,
        taxonomy,
        mode = 'full',
        emergencyKinds = [],
        validationErrors = {},
        dispatch,
        onRequestCalibration,
    } = props;

    const layout = useMemo(() => normaliseLayout(rawLayout), [rawLayout]);
    const canvasWidth = layout.canvas?.width ?? 1000;
    const canvasHeight = layout.canvas?.height ?? 700;
    const mpu = metersPerUnit(layout);
    const canvasSize = useMemo(
        () => ({ width: canvasWidth, height: canvasHeight }),
        [canvasHeight, canvasWidth],
    );
    const attachedOpenings = useMemo<AttachedOpening[]>(
        () => [
            ...layout.doors.map((door) => ({
                ...door,
                width: normaliseDoor(door).width,
            })),
            ...layout.windows.map((win) => ({
                ...win,
                width: win.width ?? 0.1,
            })),
        ],
        [layout.doors, layout.windows],
    );
    // Rooms that have at least one auto-promoted edge wall in layout.walls.
    // For these we suppress the room rect's own stroke, so the wall is the
    // single visible boundary and door openings read as clean gaps.
    const roomsWithEdgeWalls = useMemo(() => {
        const ids = new Set<string>();
        for (const wall of layout.walls) {
            const ownerId = roomIdFromEdgeWallId(wall.id);
            if (ownerId) ids.add(ownerId);
        }
        return ids;
    }, [layout.walls]);

    const svgRef = useRef<SVGSVGElement | null>(null);
    const dragRef = useRef<DragRef | null>(null);
    const marqueeRef = useRef<MarqueeRef | null>(null);
    // After completing a marquee selection or a drag, the browser fires a
    // synthetic click on the background — we set this ref so the click
    // handler skips the "deselect everything" path.
    const suppressNextClickRef = useRef(false);
    // Tracks whether the current marquee pointer-down has moved beyond a
    // tiny threshold. Below threshold = treat as click (deselect).
    const marqueeMovedRef = useRef(false);

    const [shiftHeld, setShiftHeld] = useState(false);
    const [altHeld, setAltHeld] = useState(false);
    const [groupDragging, setGroupDragging] = useState(false);
    const [hoverPoint, setHoverPoint] = useState<{
        x: number;
        y: number;
    } | null>(null);
    const [contextMenu, setContextMenu] = useState<{
        x: number;
        y: number;
        ref: SelectionRef;
    } | null>(null);
    const shouldSnap = layout.grid?.snap !== false && !altHeld;

    const openContextMenu = useCallback(
        (event: React.MouseEvent, ref: SelectionRef, editable: boolean) => {
            if (!editable) return;
            event.preventDefault();
            event.stopPropagation();
            // Auto-promoted edge wall → present as its parent room.
            if (ref.type === 'wall') {
                const ownerRoomId = roomIdFromEdgeWallId(String(ref.id));
                if (ownerRoomId) ref = { type: 'room', id: ownerRoomId };
            }
            if (!isRefInSelection(selection, ref)) {
                dispatch({ type: 'select', ref, additive: false });
            }
            setContextMenu({ x: event.clientX, y: event.clientY, ref });
        },
        [dispatch, selection],
    );

    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Shift') setShiftHeld(true);
            if (event.key === 'Alt') setAltHeld(true);
        };
        const onKeyUp = (event: KeyboardEvent) => {
            if (event.key === 'Shift') setShiftHeld(false);
            if (event.key === 'Alt') setAltHeld(false);
        };
        const onBlur = () => {
            setShiftHeld(false);
            setAltHeld(false);
        };
        window.addEventListener('keydown', onKeyDown);
        window.addEventListener('keyup', onKeyUp);
        window.addEventListener('blur', onBlur);
        return () => {
            window.removeEventListener('keydown', onKeyDown);
            window.removeEventListener('keyup', onKeyUp);
            window.removeEventListener('blur', onBlur);
        };
    }, []);

    const pointFromEvent = useCallback(
        (event: {
            clientX: number;
            clientY: number;
        }): { x: number; y: number } => {
            const svg = svgRef.current;
            if (!svg) return { x: 0, y: 0 };
            const rect = svg.getBoundingClientRect();
            return {
                x: clamp01((event.clientX - rect.left) / rect.width),
                y: clamp01((event.clientY - rect.top) / rect.height),
            };
        },
        [],
    );

    const isEditablePinKind = useCallback(
        (kind: string) =>
            mode === 'full' || isEmergencyPlanKind(kind, emergencyKinds),
        [emergencyKinds, mode],
    );

    const isVisible = useCallback(
        (kind: string) =>
            layers[layerOfKind(kind, taxonomy)] !== false &&
            (mode === 'full' || isEditablePinKind(kind)),
        [isEditablePinKind, layers, mode, taxonomy],
    );

    const showStructure = layers.structure !== false;
    const structureInteractive = mode === 'full';
    // When a placement tool is active, existing items must be transparent to
    // pointer events so the click reaches the background placement handler.
    const placementMode = !isSelectMode(activeKind);
    const itemsInteractive = structureInteractive && !placementMode;
    const pinsInteractive = !placementMode;
    const gridSize = layout.grid?.size ?? 10;

    // ── Background pointer-down: start marquee or place item ─────────
    const handleBackgroundPointerDown = useCallback(
        (event: React.PointerEvent<SVGRectElement>) => {
            const raw = pointFromEvent(event);

            if (!isSelectMode(activeKind)) {
                // Click-to-place handled by handleBackgroundClick
                return;
            }
            // Start marquee selection
            try {
                (event.currentTarget as Element).setPointerCapture(
                    event.pointerId,
                );
            } catch {
                // setPointerCapture can fail in some test envs — safe to ignore
            }
            marqueeRef.current = {
                pointerId: event.pointerId,
                firstPoint: raw,
                cursor: raw,
                moved: false,
            };
            marqueeMovedRef.current = false;
            dispatch({ type: 'begin_marquee', point: raw });
        },
        [activeKind, dispatch, pointFromEvent],
    );

    const handleBackgroundMouseDown = useCallback(
        (event: React.MouseEvent<SVGRectElement>) => {
            if (
                event.button !== 0 ||
                marqueeRef.current ||
                !isSelectMode(activeKind)
            )
                return;

            const raw = pointFromEvent(event);
            marqueeRef.current = {
                pointerId: null,
                firstPoint: raw,
                cursor: raw,
                moved: false,
            };
            marqueeMovedRef.current = false;
            dispatch({ type: 'begin_marquee', point: raw });
        },
        [activeKind, dispatch, pointFromEvent],
    );

    const handleBackgroundClick = useCallback(
        (event: React.MouseEvent<SVGRectElement>) => {
            // A drag (marquee or item) just released — the browser fires a
            // click right after pointerup. Swallow it so we don't deselect.
            if (suppressNextClickRef.current) {
                suppressNextClickRef.current = false;
                return;
            }
            if (interaction.mode === 'marquee') return;
            if (dragRef.current) return;
            const raw = pointFromEvent(event);
            const point = snapToGrid(
                raw,
                layout,
                { width: canvasWidth, height: canvasHeight },
                shouldSnap,
            );

            if (activeKind === '__scale') {
                if (interaction.mode !== 'calibrating') {
                    dispatch({
                        type: 'begin_calibration',
                        point,
                        rawPoint: raw,
                    });
                } else if (interaction.firstPoint) {
                    onRequestCalibration(interaction.firstPoint, point);
                }
                return;
            }

            if (activeKind === '__wall') {
                if (interaction.mode !== 'drawing_wall') {
                    dispatch({
                        type: 'begin_drawing_wall',
                        point,
                        rawPoint: raw,
                    });
                } else {
                    const second = shiftHeld
                        ? constrainAngle(interaction.firstPoint, point)
                        : point;
                    dispatch({ type: 'complete_drawing_wall', point: second });
                }
                return;
            }

            if (activeKind === 'evacuation_route') {
                if (interaction.mode !== 'drawing_polyline') {
                    dispatch({
                        type: 'begin_drawing_polyline',
                        point,
                        rawPoint: raw,
                    });
                } else {
                    dispatch({ type: 'append_polyline_vertex', point });
                }
                return;
            }

            if (activeKind === '__room') {
                const id = `room-${Date.now()}`;
                dispatch({ type: 'commit' });
                dispatch({
                    type: 'add_room',
                    room: {
                        id,
                        label: `Room ${(layout.rooms?.length ?? 0) + 1}`,
                        shape: 'rect',
                        x: clamp01(point.x - 0.09),
                        y: clamp01(point.y - 0.07),
                        width: 0.18,
                        height: 0.14,
                    },
                    selectAfter: true,
                });
                return;
            }
            if (activeKind === '__door') {
                const id = `door-${Date.now()}`;
                const subkind =
                    (activeSubkind as DoorSubkind | null) ?? 'single_swing';
                const result = snapOpeningWithRoomFallback(
                    point,
                    layout.walls,
                    layout.rooms,
                    canvasSize,
                    { width: 0.08, maxDistancePx: 64 },
                );
                if (!result) {
                    toast.warning(
                        layout.walls.length > 0 || layout.rooms.length > 0
                            ? 'Place doors closer to a wall or room edge.'
                            : 'Draw a room or wall first, then place doors on it.',
                    );
                    return;
                }
                const { snapped, newWalls } = result;
                const swingDirection = inferSwingDirection(
                    point,
                    snapped,
                    canvasSize,
                );
                dispatch({ type: 'commit' });
                for (const wall of newWalls) {
                    dispatch({ type: 'add_wall', wall });
                }
                dispatch({
                    type: 'add_door',
                    door: {
                        id,
                        x: snapped.x,
                        y: snapped.y,
                        width: snapped.width,
                        rotation_deg: snapped.rotation_deg,
                        wall_id: snapped.wall_id,
                        wall_segment_index: snapped.wall_segment_index,
                        wall_t: snapped.wall_t,
                        subkind,
                        swing_side: 'right',
                        swing_direction: swingDirection,
                    },
                    selectAfter: true,
                });
                return;
            }
            if (activeKind === '__window') {
                const id = `win-${Date.now()}`;
                const result = snapOpeningWithRoomFallback(
                    point,
                    layout.walls,
                    layout.rooms,
                    canvasSize,
                    { width: 0.1, maxDistancePx: 64 },
                );
                if (!result) {
                    toast.warning(
                        layout.walls.length > 0 || layout.rooms.length > 0
                            ? 'Place windows closer to a wall or room edge.'
                            : 'Draw a room or wall first, then place windows on it.',
                    );
                    return;
                }
                const { snapped, newWalls } = result;
                dispatch({ type: 'commit' });
                for (const wall of newWalls) {
                    dispatch({ type: 'add_wall', wall });
                }
                dispatch({
                    type: 'add_window',
                    window: {
                        id,
                        x: snapped.x,
                        y: snapped.y,
                        width: snapped.width,
                        rotation_deg: snapped.rotation_deg,
                        wall_id: snapped.wall_id,
                        wall_segment_index: snapped.wall_segment_index,
                        wall_t: snapped.wall_t,
                    },
                    selectAfter: true,
                });
                return;
            }
            if (activeKind === '__label') {
                const id = `label-${Date.now()}`;
                dispatch({ type: 'commit' });
                dispatch({
                    type: 'add_label',
                    label: {
                        id,
                        x: point.x,
                        y: point.y,
                        text: 'Label',
                        size: 16,
                    },
                    selectAfter: true,
                });
                dispatch({ type: 'begin_edit', target: { type: 'label', id } });
                return;
            }

            if (!isSelectMode(activeKind) && activeKind) {
                dispatch({ type: 'commit' });
                const newPin: PlanPin = {
                    kind: activeKind,
                    subkind: activeSubkind ?? null,
                    label: null,
                    x: point.x,
                    y: point.y,
                };
                dispatch({ type: 'add_pin', pin: newPin, selectAfter: true });
                return;
            }

            // No tool, no marquee drag: deselect
            dispatch({ type: 'select', ref: null });
        },
        [
            activeKind,
            activeSubkind,
            canvasHeight,
            canvasSize,
            canvasWidth,
            dispatch,
            interaction,
            layout,
            onRequestCalibration,
            pointFromEvent,
            shiftHeld,
            shouldSnap,
        ],
    );

    // ── Drawing modes preview + marquee live update ──────────────────
    const handleMouseMove = useCallback(
        (event: React.MouseEvent<SVGSVGElement>) => {
            const raw = pointFromEvent(event);
            const snapped = snapToGrid(
                raw,
                layout,
                { width: canvasWidth, height: canvasHeight },
                shouldSnap,
            );
            setHoverPoint(snapped);
            if (interaction.mode === 'drawing_wall') {
                const cursor = shiftHeld
                    ? constrainAngle(interaction.firstPoint, snapped)
                    : snapped;
                dispatch({
                    type: 'update_wall_cursor',
                    point: cursor,
                    rawPoint: raw,
                });
            } else if (interaction.mode === 'drawing_polyline') {
                dispatch({
                    type: 'update_polyline_cursor',
                    point: snapped,
                    rawPoint: raw,
                });
            } else if (
                interaction.mode === 'calibrating' &&
                interaction.firstPoint
            ) {
                dispatch({
                    type: 'update_calibration_cursor',
                    point: snapped,
                    rawPoint: raw,
                });
            } else if (interaction.mode === 'marquee' || marqueeRef.current) {
                const activeMarquee = marqueeRef.current ?? {
                    firstPoint:
                        interaction.mode === 'marquee'
                            ? interaction.firstPoint
                            : raw,
                    cursor: raw,
                    moved: false,
                    pointerId: -1,
                };
                const dx = raw.x - activeMarquee.firstPoint.x;
                const dy = raw.y - activeMarquee.firstPoint.y;
                const moved = Math.hypot(dx, dy) > 0.004;
                activeMarquee.cursor = raw;
                activeMarquee.moved = activeMarquee.moved || moved;
                if (marqueeRef.current) {
                    marqueeRef.current = activeMarquee;
                }
                if (moved) marqueeMovedRef.current = true;
                const pendingRefs = refsInsideMarquee(
                    layout,
                    pins,
                    {
                        x1: activeMarquee.firstPoint.x,
                        y1: activeMarquee.firstPoint.y,
                        x2: raw.x,
                        y2: raw.y,
                    },
                    isVisible,
                    showStructure && structureInteractive,
                );
                dispatch({
                    type: 'update_marquee_cursor',
                    point: raw,
                    pendingRefs,
                });
            }
        },
        [
            canvasHeight,
            canvasWidth,
            dispatch,
            interaction,
            isVisible,
            layout,
            pins,
            pointFromEvent,
            shiftHeld,
            shouldSnap,
            showStructure,
            structureInteractive,
        ],
    );

    const handleDoubleClick = useCallback(() => {
        if (interaction.mode === 'drawing_polyline') {
            dispatch({
                type: 'complete_polyline',
                kind: activeKind ?? 'evacuation_route',
                subkind: activeSubkind ?? null,
            });
        }
    }, [activeKind, activeSubkind, dispatch, interaction.mode]);

    // ── Per-item pointer-down: select + begin move ───────────────────
    const beginMoveDrag = useCallback(
        (
            event: React.PointerEvent,
            target: { type: SelectionRef['type']; id: SelectionRef['id'] },
        ) => {
            event.stopPropagation();
            if (!structureInteractive && target.type !== 'pin') return;
            // Auto-promoted room-edge walls behave as part of their parent
            // room: grabbing one drags the whole room, not just that wall.
            if (target.type === 'wall') {
                const ownerRoomId = roomIdFromEdgeWallId(String(target.id));
                if (
                    ownerRoomId &&
                    layout.rooms.some((room) => room.id === ownerRoomId)
                ) {
                    target = { type: 'room', id: ownerRoomId };
                }
            }
            if (target.type === 'pin') {
                const pin = pins.find(
                    (candidate, index) =>
                        pinIdOf(candidate, index) === String(target.id),
                );
                if (!pin || !isEditablePinKind(pin.kind)) return;
            }

            const rawOrigin = pointFromEvent(event);
            const origin = snapToGrid(
                rawOrigin,
                layout,
                { width: canvasWidth, height: canvasHeight },
                shouldSnap,
            );
            (event.target as Element).setPointerCapture(event.pointerId);

            const ref: SelectionRef = {
                type: target.type,
                id: target.id,
            } as SelectionRef;
            const additive = event.shiftKey;
            const alreadySelected = isRefInSelection(selection, ref);

            let nextSelection = selection;
            if (additive) {
                nextSelection = alreadySelected
                    ? selection.filter((s) => !sameRef(s, ref))
                    : [...selection, ref];
                dispatch({ type: 'select', ref, additive: true });
            } else if (!alreadySelected) {
                nextSelection = [ref];
                dispatch({ type: 'select', ref, additive: false });
            }

            const bases = new Map<string, DragBase>();
            for (const sel of nextSelection) {
                if (sel.type === 'room') {
                    const room = layout.rooms.find((r) => r.id === sel.id);
                    if (room)
                        bases.set(refKey(sel), { kind: 'room', base: room });
                } else if (sel.type === 'wall') {
                    const wall = layout.walls.find((w) => w.id === sel.id);
                    if (wall)
                        bases.set(refKey(sel), { kind: 'wall', base: wall });
                } else if (sel.type === 'door') {
                    const door = layout.doors.find((d) => d.id === sel.id);
                    if (door)
                        bases.set(refKey(sel), { kind: 'door', base: door });
                } else if (sel.type === 'window') {
                    const win = layout.windows.find((w) => w.id === sel.id);
                    if (win)
                        bases.set(refKey(sel), { kind: 'window', base: win });
                } else if (sel.type === 'label') {
                    const label = layout.labels.find((l) => l.id === sel.id);
                    if (label)
                        bases.set(refKey(sel), { kind: 'label', base: label });
                } else if (sel.type === 'pin') {
                    const idx = pins.findIndex(
                        (p, i) => pinIdOf(p, i) === String(sel.id),
                    );
                    if (idx !== -1)
                        bases.set(refKey(sel), {
                            kind: 'pin',
                            index: idx,
                            base: pins[idx],
                        });
                }
            }

            dragRef.current = {
                mode: 'move',
                pointerId: event.pointerId,
                origin,
                target: ref,
                bases,
                committed: false,
            };
            setGroupDragging(nextSelection.length > 1);
        },
        [
            canvasHeight,
            canvasWidth,
            dispatch,
            isEditablePinKind,
            layout,
            pins,
            pointFromEvent,
            selection,
            shouldSnap,
            structureInteractive,
        ],
    );

    const beginResizeDrag = useCallback(
        (
            event: React.PointerEvent,
            roomId: string,
            base: PlanRoom,
            handle: ResizeHandle,
        ) => {
            event.stopPropagation();
            if (!structureInteractive) return;
            const rawOrigin = pointFromEvent(event);
            const origin = snapToGrid(
                rawOrigin,
                layout,
                { width: canvasWidth, height: canvasHeight },
                shouldSnap,
            );
            (event.target as Element).setPointerCapture(event.pointerId);
            dragRef.current = {
                mode: 'resize',
                pointerId: event.pointerId,
                origin,
                roomId,
                base,
                handle,
                committed: false,
            };
        },
        [
            canvasHeight,
            canvasWidth,
            layout,
            pointFromEvent,
            shouldSnap,
            structureInteractive,
        ],
    );

    const beginWallEndpointDrag = useCallback(
        (
            event: React.PointerEvent,
            wallId: string,
            base: PlanWall,
            index: number,
        ) => {
            event.stopPropagation();
            if (!structureInteractive) return;
            const rawOrigin = pointFromEvent(event);
            const origin = snapToGrid(
                rawOrigin,
                layout,
                { width: canvasWidth, height: canvasHeight },
                shouldSnap,
            );
            (event.target as Element).setPointerCapture(event.pointerId);
            dragRef.current = {
                mode: 'wall-endpoint',
                pointerId: event.pointerId,
                origin,
                wallId,
                base,
                index,
                committed: false,
            };
        },
        [
            canvasHeight,
            canvasWidth,
            layout,
            pointFromEvent,
            shouldSnap,
            structureInteractive,
        ],
    );

    const beginRotateDrag = useCallback(
        (
            event: React.PointerEvent,
            ref: SelectionRef,
            centre: { x: number; y: number },
            baseRotation: number,
        ) => {
            event.stopPropagation();
            const raw = pointFromEvent(event);
            const dx = (raw.x - centre.x) * canvasWidth;
            const dy = (raw.y - centre.y) * canvasHeight;
            const startAngle = (Math.atan2(dy, dx) * 180) / Math.PI;
            (event.target as Element).setPointerCapture(event.pointerId);
            dragRef.current = {
                mode: 'rotate',
                pointerId: event.pointerId,
                ref,
                centre,
                baseRotation,
                startAngle,
                committed: false,
            };
        },
        [canvasHeight, canvasWidth, pointFromEvent],
    );

    const handlePointerMove = useCallback(
        (event: React.PointerEvent<SVGElement>) => {
            const activeMarquee = marqueeRef.current;
            if (activeMarquee && activeMarquee.pointerId === event.pointerId) {
                event.preventDefault();
                const raw = pointFromEvent(event);
                const dx = raw.x - activeMarquee.firstPoint.x;
                const dy = raw.y - activeMarquee.firstPoint.y;
                const moved = Math.hypot(dx, dy) > 0.004;
                const nextMarquee = {
                    ...activeMarquee,
                    cursor: raw,
                    moved: activeMarquee.moved || moved,
                };
                marqueeRef.current = nextMarquee;
                if (moved) marqueeMovedRef.current = true;
                const pendingRefs = refsInsideMarquee(
                    layout,
                    pins,
                    {
                        x1: nextMarquee.firstPoint.x,
                        y1: nextMarquee.firstPoint.y,
                        x2: raw.x,
                        y2: raw.y,
                    },
                    isVisible,
                    showStructure && structureInteractive,
                );
                dispatch({
                    type: 'update_marquee_cursor',
                    point: raw,
                    pendingRefs,
                });
                return;
            }

            const drag = dragRef.current;
            if (!drag || event.pointerId !== drag.pointerId) return;
            event.preventDefault();
            if (!drag.committed) {
                dispatch({ type: 'commit' });
                drag.committed = true;
            }
            const raw = pointFromEvent(event);
            const snapped = snapToGrid(
                raw,
                layout,
                { width: canvasWidth, height: canvasHeight },
                shouldSnap,
            );
            const dx = drag.mode !== 'rotate' ? snapped.x - drag.origin.x : 0;
            const dy = drag.mode !== 'rotate' ? snapped.y - drag.origin.y : 0;

            if (drag.mode === 'move') {
                for (const [, base] of drag.bases) {
                    if (base.kind === 'room') {
                        const r = base.base;
                        const nextX = clamp(r.x + dx, 0, 1 - r.width);
                        const nextY = clamp(r.y + dy, 0, 1 - r.height);
                        dispatch({
                            type: 'update_room',
                            id: r.id,
                            patch: { x: nextX, y: nextY },
                        });
                        // Keep any auto-promoted edge walls glued to the room.
                        const edgeWalls = roomEdgeWalls({
                            ...r,
                            x: nextX,
                            y: nextY,
                        });
                        for (const edge of edgeWalls) {
                            if (
                                layout.walls.some(
                                    (wall) => wall.id === edge.id,
                                )
                            ) {
                                dispatch({
                                    type: 'update_wall',
                                    id: edge.id,
                                    patch: { points: edge.points },
                                });
                            }
                        }
                    } else if (base.kind === 'wall') {
                        const w = base.base;
                        const points = w.points.map((p) => ({
                            x: clamp01(p.x + dx),
                            y: clamp01(p.y + dy),
                        }));
                        dispatch({
                            type: 'update_wall',
                            id: w.id,
                            patch: { points },
                        });
                    } else if (base.kind === 'door') {
                        const d = base.base;
                        const moved = {
                            ...d,
                            x: clamp01(d.x + dx),
                            y: clamp01(d.y + dy),
                            width: normaliseDoor(d).width,
                        };
                        const result = snapOpeningWithRoomFallback(
                            openingCentreFromTopLeft(moved, canvasSize),
                            layout.walls,
                            layout.rooms,
                            canvasSize,
                            { width: moved.width, maxDistancePx: 72 },
                        );
                        if (result) {
                            for (const wall of result.newWalls) {
                                dispatch({ type: 'add_wall', wall });
                            }
                        }
                        const snapped = result?.snapped ?? null;
                        dispatch({
                            type: 'update_door',
                            id: d.id,
                            patch: snapped
                                ? {
                                      x: snapped.x,
                                      y: snapped.y,
                                      width: snapped.width,
                                      rotation_deg: snapped.rotation_deg,
                                      wall_id: snapped.wall_id,
                                      wall_segment_index:
                                          snapped.wall_segment_index,
                                      wall_t: snapped.wall_t,
                                  }
                                : {
                                      x: moved.x,
                                      y: moved.y,
                                      wall_id: null,
                                      wall_segment_index: null,
                                      wall_t: null,
                                  },
                        });
                    } else if (base.kind === 'window') {
                        const w = base.base;
                        const moved = {
                            ...w,
                            x: clamp01(w.x + dx),
                            y: clamp01(w.y + dy),
                            width: w.width ?? 0.1,
                        };
                        const result = snapOpeningWithRoomFallback(
                            openingCentreFromTopLeft(moved, canvasSize),
                            layout.walls,
                            layout.rooms,
                            canvasSize,
                            { width: moved.width, maxDistancePx: 72 },
                        );
                        if (result) {
                            for (const wall of result.newWalls) {
                                dispatch({ type: 'add_wall', wall });
                            }
                        }
                        const snapped = result?.snapped ?? null;
                        dispatch({
                            type: 'update_window',
                            id: w.id,
                            patch: snapped
                                ? {
                                      x: snapped.x,
                                      y: snapped.y,
                                      width: snapped.width,
                                      rotation_deg: snapped.rotation_deg,
                                      wall_id: snapped.wall_id,
                                      wall_segment_index:
                                          snapped.wall_segment_index,
                                      wall_t: snapped.wall_t,
                                  }
                                : {
                                      x: moved.x,
                                      y: moved.y,
                                      wall_id: null,
                                      wall_segment_index: null,
                                      wall_t: null,
                                  },
                        });
                    } else if (base.kind === 'label') {
                        const l = base.base;
                        dispatch({
                            type: 'update_label',
                            id: l.id,
                            patch: {
                                x: clamp01(l.x + dx),
                                y: clamp01(l.y + dy),
                            },
                        });
                    } else if (base.kind === 'pin') {
                        const p = base.base;
                        const id = pinIdOf(p, base.index);
                        dispatch({
                            type: 'update_pin',
                            pinId: id,
                            patch: {
                                x: clamp01(p.x + dx),
                                y: clamp01(p.y + dy),
                            },
                        });
                    }
                }
                return;
            }

            if (drag.mode === 'resize') {
                const r = drag.base;
                const handle = drag.handle;
                const minW = 0.04;
                const minH = 0.04;
                let x = r.x;
                let y = r.y;
                let width = r.width;
                let height = r.height;
                if (handle.includes('w')) {
                    const right = r.x + r.width;
                    x = clamp(r.x + dx, 0, right - minW);
                    width = right - x;
                }
                if (handle.includes('e')) {
                    width = clamp(r.width + dx, minW, 1 - r.x);
                }
                if (handle.includes('n')) {
                    const bottom = r.y + r.height;
                    y = clamp(r.y + dy, 0, bottom - minH);
                    height = bottom - y;
                }
                if (handle.includes('s')) {
                    height = clamp(r.height + dy, minH, 1 - r.y);
                }
                dispatch({
                    type: 'update_room',
                    id: drag.roomId,
                    patch: { x, y, width, height },
                });
                // Sync auto-promoted edge walls to the resized rectangle.
                const edgeWalls = roomEdgeWalls({
                    ...r,
                    x,
                    y,
                    width,
                    height,
                });
                for (const edge of edgeWalls) {
                    if (layout.walls.some((wall) => wall.id === edge.id)) {
                        dispatch({
                            type: 'update_wall',
                            id: edge.id,
                            patch: { points: edge.points },
                        });
                    }
                }
                return;
            }

            if (drag.mode === 'wall-endpoint') {
                const w = drag.base;
                const idx = drag.index;
                const points = w.points.map((p, i) =>
                    i === idx ? snapped : p,
                );
                dispatch({
                    type: 'update_wall',
                    id: drag.wallId,
                    patch: { points },
                });
                return;
            }

            if (drag.mode === 'rotate') {
                const dxR = (raw.x - drag.centre.x) * canvasWidth;
                const dyR = (raw.y - drag.centre.y) * canvasHeight;
                const currentAngle = (Math.atan2(dyR, dxR) * 180) / Math.PI;
                let next = drag.baseRotation + (currentAngle - drag.startAngle);
                // Normalise to [-180, 180] then optionally snap to 15° when Shift is held.
                next = ((((next + 180) % 360) + 360) % 360) - 180;
                if (shiftHeld) next = Math.round(next / 15) * 15;
                const rounded = Math.round(next);
                switch (drag.ref.type) {
                    case 'door':
                        dispatch({
                            type: 'update_door',
                            id: String(drag.ref.id),
                            patch: { rotation_deg: rounded },
                        });
                        break;
                    case 'window':
                        dispatch({
                            type: 'update_window',
                            id: String(drag.ref.id),
                            patch: { rotation_deg: rounded },
                        });
                        break;
                    case 'label':
                        dispatch({
                            type: 'update_label',
                            id: String(drag.ref.id),
                            patch: { rotation_deg: rounded },
                        });
                        break;
                    case 'pin':
                        dispatch({
                            type: 'update_pin',
                            pinId: drag.ref.id,
                            patch: { rotation_deg: rounded },
                        });
                        break;
                }
                return;
            }
        },
        [
            canvasHeight,
            canvasSize,
            canvasWidth,
            dispatch,
            isVisible,
            layout,
            pins,
            pointFromEvent,
            shiftHeld,
            shouldSnap,
            showStructure,
            structureInteractive,
        ],
    );

    const completeMarquee = useCallback(
        (activeMarquee: MarqueeRef) => {
            marqueeRef.current = null;
            marqueeMovedRef.current = false;
            if (activeMarquee.moved) {
                const refs = refsInsideMarquee(
                    layout,
                    pins,
                    {
                        x1: activeMarquee.firstPoint.x,
                        y1: activeMarquee.firstPoint.y,
                        x2: activeMarquee.cursor.x,
                        y2: activeMarquee.cursor.y,
                    },
                    isVisible,
                    showStructure && structureInteractive,
                );
                dispatch({ type: 'complete_marquee', refs });
                // The trailing click would otherwise clear the new selection.
                suppressNextClickRef.current = true;
            } else {
                dispatch({ type: 'cancel_interaction' });
            }
        },
        [
            dispatch,
            isVisible,
            layout,
            pins,
            showStructure,
            structureInteractive,
        ],
    );

    const handlePointerUp = useCallback(
        (event: React.PointerEvent<SVGElement>) => {
            const activeMarquee = marqueeRef.current;
            if (activeMarquee && event.pointerId === activeMarquee.pointerId) {
                completeMarquee(activeMarquee);
                return;
            }

            if (
                dragRef.current &&
                dragRef.current.pointerId === event.pointerId
            ) {
                const drag = dragRef.current;
                if (
                    drag.mode === 'move' &&
                    !drag.committed &&
                    selection.length > 1
                ) {
                    dispatch({
                        type: 'select',
                        ref: drag.target,
                        additive: false,
                    });
                }
                dragRef.current = null;
                setGroupDragging(false);
                if (drag.committed) {
                    // Real drags trigger a trailing click after pointerup.
                    suppressNextClickRef.current = true;
                }
            }
            // Complete marquee selection if active
            if (interaction.mode === 'marquee') {
                const moved = marqueeMovedRef.current;
                marqueeMovedRef.current = false;
                marqueeRef.current = null;
                if (moved) {
                    const refs = refsInsideMarquee(
                        layout,
                        pins,
                        {
                            x1: interaction.firstPoint.x,
                            y1: interaction.firstPoint.y,
                            x2: interaction.cursor.x,
                            y2: interaction.cursor.y,
                        },
                        isVisible,
                        showStructure && structureInteractive,
                    );
                    dispatch({ type: 'complete_marquee', refs });
                    // The trailing click would otherwise clear the new selection.
                    suppressNextClickRef.current = true;
                } else {
                    // Background pointer-down without movement → cancel the
                    // marquee and let the click handler decide what to do.
                    dispatch({ type: 'cancel_interaction' });
                }
            }
        },
        [
            completeMarquee,
            dispatch,
            interaction,
            isVisible,
            layout,
            pins,
            selection.length,
            showStructure,
            structureInteractive,
        ],
    );

    const handleMouseUp = useCallback(() => {
        const activeMarquee = marqueeRef.current;
        if (activeMarquee) {
            completeMarquee(activeMarquee);
        }
    }, [completeMarquee]);

    const cursor = isSelectMode(activeKind)
        ? 'cursor-default'
        : 'cursor-crosshair';
    const pendingRefs =
        interaction.mode === 'marquee' ? interaction.pendingRefs : [];
    const groupBounds = groupDragging
        ? selectionBounds(layout, pins, selection)
        : null;
    const orderedPins = useMemo(
        () =>
            pins
                .map((pin, index) => ({ pin, index }))
                .sort(
                    (a, b) =>
                        (a.pin.sort_order ?? a.index) -
                        (b.pin.sort_order ?? b.index),
                ),
        [pins],
    );
    const hasPendingRef = useCallback(
        (ref: SelectionRef) =>
            pendingRefs.some((pending) => sameRef(pending, ref)),
        [pendingRefs],
    );

    function renderSnapPair(
        raw: { x: number; y: number } | null | undefined,
        snapped: { x: number; y: number } | null | undefined,
    ) {
        if (!raw || !snapped || !shouldSnap) return null;
        const rawX = raw.x * canvasWidth;
        const rawY = raw.y * canvasHeight;
        const snapX = snapped.x * canvasWidth;
        const snapY = snapped.y * canvasHeight;
        const distance = Math.hypot(rawX - snapX, rawY - snapY);

        return (
            <g pointerEvents="none">
                {distance > 3 && (
                    <line
                        x1={rawX}
                        y1={rawY}
                        x2={snapX}
                        y2={snapY}
                        stroke="#94a3b8"
                        strokeWidth={1}
                        strokeDasharray="3 3"
                    />
                )}
                <path
                    d={`M ${rawX - 5} ${rawY} L ${rawX + 5} ${rawY} M ${rawX} ${rawY - 5} L ${rawX} ${rawY + 5}`}
                    stroke="#94a3b8"
                    strokeWidth={1.5}
                />
                <circle cx={snapX} cy={snapY} r={4} fill="#2563eb" />
            </g>
        );
    }

    return (
        <div
            className="relative h-full min-h-[420px] overflow-hidden rounded-md border bg-white"
            data-test="site-plan-canvas"
        >
            <svg
                ref={svgRef}
                viewBox={`0 0 ${canvasWidth} ${canvasHeight}`}
                preserveAspectRatio="none"
                className={cn(
                    'h-full min-h-[420px] w-full select-none',
                    cursor,
                )}
                onMouseMove={handleMouseMove}
                onMouseUp={handleMouseUp}
                onPointerMove={handlePointerMove}
                onPointerUp={handlePointerUp}
                onPointerCancel={handlePointerUp}
                onDoubleClick={handleDoubleClick}
            >
                <rect
                    width={canvasWidth}
                    height={canvasHeight}
                    fill="#ffffff"
                    onPointerDown={handleBackgroundPointerDown}
                    onMouseDown={handleBackgroundMouseDown}
                    onPointerMove={handlePointerMove}
                    onPointerUp={handlePointerUp}
                    onPointerCancel={handlePointerUp}
                    onClick={handleBackgroundClick}
                />

                {/* Grid */}
                {layout.grid?.enabled &&
                    Array.from({
                        length: Math.floor(canvasWidth / gridSize) + 1,
                    }).map((_, index) => (
                        <line
                            key={`gx-${index}`}
                            x1={index * gridSize}
                            y1={0}
                            x2={index * gridSize}
                            y2={canvasHeight}
                            stroke="#e2e8f0"
                            strokeWidth={1}
                            pointerEvents="none"
                        />
                    ))}
                {layout.grid?.enabled &&
                    Array.from({
                        length: Math.floor(canvasHeight / gridSize) + 1,
                    }).map((_, index) => (
                        <line
                            key={`gy-${index}`}
                            x1={0}
                            y1={index * gridSize}
                            x2={canvasWidth}
                            y2={index * gridSize}
                            stroke="#e2e8f0"
                            strokeWidth={1}
                            pointerEvents="none"
                        />
                    ))}

                {/* Rooms */}
                {showStructure &&
                    layout.rooms?.map((room) => {
                        const selected = isRefInSelection(selection, {
                            type: 'room',
                            id: room.id,
                        });
                        const pending = hasPendingRef({
                            type: 'room',
                            id: room.id,
                        });
                        const linked = !!room.room_ref_id;
                        const x = room.x * canvasWidth;
                        const y = room.y * canvasHeight;
                        const w = room.width * canvasWidth;
                        const h = room.height * canvasHeight;
                        const isEditing =
                            editing?.type === 'room' &&
                            String(editing.id) === room.id;
                        const onlySelected = selected && selection.length === 1;
                        // When the room has edge walls, those walls are the
                        // visible boundary — don't paint the rect border too,
                        // or it would close door openings the walls have cut.
                        const hasEdgeWalls = roomsWithEdgeWalls.has(room.id);
                        const stroke = selected || pending
                            ? '#2563eb'
                            : hasEdgeWalls
                              ? 'transparent'
                              : '#334155';
                        return (
                            <g
                                key={room.id}
                                opacity={structureInteractive ? 1 : 0.45}
                                pointerEvents={
                                    itemsInteractive ? 'auto' : 'none'
                                }
                            >
                                <rect
                                    x={x}
                                    y={y}
                                    width={w}
                                    height={h}
                                    fill={linked ? '#e0f2fe' : '#f8fafc'}
                                    stroke={stroke}
                                    strokeWidth={selected ? 4 : 3}
                                    strokeDasharray={
                                        pending && !selected ? '6 4' : undefined
                                    }
                                    onPointerDown={(event) =>
                                        beginMoveDrag(event, {
                                            type: 'room',
                                            id: room.id,
                                        })
                                    }
                                    onClick={(event) => event.stopPropagation()}
                                    onDoubleClick={(event) => {
                                        event.stopPropagation();
                                        if (!linked) {
                                            dispatch({
                                                type: 'begin_edit',
                                                target: {
                                                    type: 'room',
                                                    id: room.id,
                                                },
                                            });
                                        }
                                    }}
                                    onContextMenu={(event) =>
                                        openContextMenu(
                                            event,
                                            { type: 'room', id: room.id },
                                            structureInteractive,
                                        )
                                    }
                                    style={{
                                        cursor: selected ? 'grab' : 'move',
                                    }}
                                />
                                {isEditing ? (
                                    <foreignObject
                                        x={x + 4}
                                        y={y + 4}
                                        width={Math.max(60, w - 8)}
                                        height={28}
                                    >
                                        <input
                                            autoFocus
                                            defaultValue={room.label ?? ''}
                                            onBlur={(event) => {
                                                dispatch({ type: 'commit' });
                                                dispatch({
                                                    type: 'update_room',
                                                    id: room.id,
                                                    patch: {
                                                        label:
                                                            event.target
                                                                .value || null,
                                                    },
                                                });
                                                dispatch({ type: 'end_edit' });
                                            }}
                                            onKeyDown={(event) => {
                                                if (event.key === 'Enter')
                                                    (
                                                        event.target as HTMLInputElement
                                                    ).blur();
                                                if (event.key === 'Escape') {
                                                    event.preventDefault();
                                                    dispatch({
                                                        type: 'end_edit',
                                                    });
                                                }
                                            }}
                                            className="w-full rounded border border-blue-400 bg-white px-1.5 py-0.5 text-sm text-slate-900 outline-none"
                                        />
                                    </foreignObject>
                                ) : (
                                    <text
                                        x={x + 12}
                                        y={y + 28}
                                        fontSize={18}
                                        fill="#0f172a"
                                        pointerEvents="none"
                                    >
                                        {room.label ?? 'Room'}
                                    </text>
                                )}
                                {linked && !isEditing && (
                                    <text
                                        x={x + 12}
                                        y={y + 48}
                                        fontSize={11}
                                        fill="#0369a1"
                                        pointerEvents="none"
                                    >
                                        ↳ linked
                                    </text>
                                )}
                                {onlySelected && !isEditing && (
                                    <>
                                        <text
                                            x={x + w / 2}
                                            y={y - 8}
                                            fontSize={11}
                                            textAnchor="middle"
                                            fill="#2563eb"
                                            pointerEvents="none"
                                        >
                                            {formatMeters(w, mpu)} ×{' '}
                                            {formatMeters(h, mpu)}
                                        </text>
                                        {RESIZE_HANDLES.map((handle) => {
                                            const hx = handle.includes('w')
                                                ? x
                                                : handle.includes('e')
                                                  ? x + w
                                                  : x + w / 2;
                                            const hy = handle.includes('n')
                                                ? y
                                                : handle.includes('s')
                                                  ? y + h
                                                  : y + h / 2;
                                            const onPointerDown = (
                                                event: React.PointerEvent,
                                            ) =>
                                                beginResizeDrag(
                                                    event,
                                                    room.id,
                                                    room,
                                                    handle,
                                                );
                                            return (
                                                <g key={handle}>
                                                    {/* Generous transparent hit pad so the handle is easy to grab. */}
                                                    <rect
                                                        x={hx - 9}
                                                        y={hy - 9}
                                                        width={18}
                                                        height={18}
                                                        fill="transparent"
                                                        style={{
                                                            cursor: `${handle}-resize`,
                                                        }}
                                                        onPointerDown={
                                                            onPointerDown
                                                        }
                                                    />
                                                    {/* Visible handle. */}
                                                    <rect
                                                        x={hx - 6}
                                                        y={hy - 6}
                                                        width={12}
                                                        height={12}
                                                        fill="#ffffff"
                                                        stroke="#2563eb"
                                                        strokeWidth={2}
                                                        pointerEvents="none"
                                                    />
                                                </g>
                                            );
                                        })}
                                    </>
                                )}
                            </g>
                        );
                    })}

                {/* Walls */}
                {showStructure &&
                    layout.walls?.map((wall) => {
                        const selected = isRefInSelection(selection, {
                            type: 'wall',
                            id: wall.id,
                        });
                        const pending = hasPendingRef({
                            type: 'wall',
                            id: wall.id,
                        });
                        const onlySelected = selected && selection.length === 1;
                        if (wall.points.length < 2) return null;
                        const pts = wall.points
                            .map(
                                (p) =>
                                    `${p.x * canvasWidth},${p.y * canvasHeight}`,
                            )
                            .join(' ');
                        const renderSegments = wallSegmentsWithOpenings(
                            wall,
                            attachedOpenings,
                            canvasSize,
                        );
                        const a = wall.points[0];
                        const b = wall.points[wall.points.length - 1];
                        const lengthUnits = distanceCanvasUnits(
                            a,
                            b,
                            canvasWidth,
                            canvasHeight,
                        );
                        const mid = {
                            x: ((a.x + b.x) / 2) * canvasWidth,
                            y: ((a.y + b.y) / 2) * canvasHeight,
                        };
                        return (
                            <g
                                key={wall.id}
                                opacity={structureInteractive ? 1 : 0.45}
                                pointerEvents={
                                    itemsInteractive ? 'auto' : 'none'
                                }
                            >
                                {renderSegments.map((segment) => (
                                    <line
                                        key={segment.id}
                                        x1={segment.a.x * canvasWidth}
                                        y1={segment.a.y * canvasHeight}
                                        x2={segment.b.x * canvasWidth}
                                        y2={segment.b.y * canvasHeight}
                                        stroke={
                                            selected || pending
                                                ? '#2563eb'
                                                : '#111827'
                                        }
                                        strokeWidth={wall.thickness ?? 4}
                                        strokeDasharray={
                                            pending && !selected
                                                ? '8 5'
                                                : undefined
                                        }
                                        strokeLinecap="round"
                                        pointerEvents="none"
                                    />
                                ))}
                                <polyline
                                    points={pts}
                                    fill="none"
                                    stroke="transparent"
                                    strokeWidth={Math.max(
                                        14,
                                        (wall.thickness ?? 4) + 10,
                                    )}
                                    strokeLinecap="round"
                                    onPointerDown={(event) =>
                                        beginMoveDrag(event, {
                                            type: 'wall',
                                            id: wall.id,
                                        })
                                    }
                                    onClick={(event) => event.stopPropagation()}
                                    onContextMenu={(event) =>
                                        openContextMenu(
                                            event,
                                            { type: 'wall', id: wall.id },
                                            structureInteractive,
                                        )
                                    }
                                    style={{
                                        cursor: selected ? 'grab' : 'move',
                                    }}
                                />
                                <g
                                    transform={`translate(${mid.x} ${mid.y - 8})`}
                                    pointerEvents="none"
                                >
                                    <rect
                                        x={-26}
                                        y={-12}
                                        width={52}
                                        height={16}
                                        rx={3}
                                        fill="#ffffff"
                                        stroke="#cbd5e1"
                                    />
                                    <text
                                        x={0}
                                        y={0}
                                        textAnchor="middle"
                                        fontSize={11}
                                        fill="#0f172a"
                                    >
                                        {formatMeters(lengthUnits, mpu)}
                                    </text>
                                </g>
                                {onlySelected &&
                                    wall.points.map((p, index) => (
                                        <circle
                                            key={index}
                                            cx={p.x * canvasWidth}
                                            cy={p.y * canvasHeight}
                                            r={6}
                                            fill="#ffffff"
                                            stroke="#2563eb"
                                            strokeWidth={2}
                                            style={{ cursor: 'grab' }}
                                            onPointerDown={(event) =>
                                                beginWallEndpointDrag(
                                                    event,
                                                    wall.id,
                                                    wall,
                                                    index,
                                                )
                                            }
                                        />
                                    ))}
                            </g>
                        );
                    })}

                {/* Doors */}
                {showStructure &&
                    layout.doors?.map((door) => {
                        const selected = isRefInSelection(selection, {
                            type: 'door',
                            id: door.id,
                        });
                        const pending = hasPendingRef({
                            type: 'door',
                            id: door.id,
                        });
                        const onlySelected = selected && selection.length === 1;
                        const normalised = normaliseDoor(door);
                        const resolved = resolveAttachedOpening(
                            normalised,
                            layout.walls,
                            canvasSize,
                        );
                        const renderDoor = { ...normalised, ...resolved };
                        const x = resolved.x * canvasWidth;
                        const w = resolved.width * canvasWidth;
                        const rotation = resolved.rotation_deg ?? 0;
                        // Rotation pivot: centre of the opening (matches existing rotation-handle math).
                        const cx = x + w / 2;
                        const cy = resolved.y * canvasHeight;
                        return (
                            <g
                                key={door.id}
                                transform={
                                    rotation
                                        ? `rotate(${rotation} ${cx} ${cy})`
                                        : undefined
                                }
                                opacity={structureInteractive ? 1 : 0.45}
                                pointerEvents={
                                    itemsInteractive ? 'auto' : 'none'
                                }
                            >
                                <DoorSymbol
                                    door={renderDoor}
                                    canvasWidth={canvasWidth}
                                    canvasHeight={canvasHeight}
                                    selected={selected}
                                    pending={pending}
                                    detached={!resolved.attached}
                                    onPointerDown={(event) =>
                                        beginMoveDrag(event, {
                                            type: 'door',
                                            id: door.id,
                                        })
                                    }
                                    onClick={(event) => event.stopPropagation()}
                                    onContextMenu={(event) =>
                                        openContextMenu(
                                            event,
                                            { type: 'door', id: door.id },
                                            structureInteractive,
                                        )
                                    }
                                />
                                {onlySelected &&
                                    structureInteractive &&
                                    !resolved.attached && (
                                        <RotationHandle
                                            cx={cx}
                                            cy={cy}
                                            offset={26}
                                            onBegin={(event) =>
                                                beginRotateDrag(
                                                    event,
                                                    {
                                                        type: 'door',
                                                        id: door.id,
                                                    },
                                                    {
                                                        x:
                                                            resolved.x +
                                                            resolved.width / 2,
                                                        y: resolved.y,
                                                    },
                                                    rotation,
                                                )
                                            }
                                        />
                                    )}
                            </g>
                        );
                    })}

                {/* Windows */}
                {showStructure &&
                    layout.windows?.map((win) => {
                        const selected = isRefInSelection(selection, {
                            type: 'window',
                            id: win.id,
                        });
                        const pending = hasPendingRef({
                            type: 'window',
                            id: win.id,
                        });
                        const onlySelected = selected && selection.length === 1;
                        const resolved = resolveAttachedOpening(
                            win,
                            layout.walls,
                            canvasSize,
                        );
                        const x = resolved.x * canvasWidth;
                        const y = resolved.y * canvasHeight;
                        const w = resolved.width * canvasWidth;
                        const h = 12;
                        const rotation = resolved.rotation_deg ?? 0;
                        const cx = x + w / 2;
                        const cy = y;
                        return (
                            <g
                                key={win.id}
                                transform={
                                    rotation
                                        ? `rotate(${rotation} ${cx} ${cy})`
                                        : undefined
                                }
                                opacity={structureInteractive ? 1 : 0.45}
                                pointerEvents={
                                    itemsInteractive ? 'auto' : 'none'
                                }
                            >
                                <rect
                                    x={x - 2}
                                    y={y - 8}
                                    width={w + 4}
                                    height={16}
                                    fill="#ffffff"
                                    pointerEvents="none"
                                />
                                <rect
                                    x={x}
                                    y={y - h / 2}
                                    width={w}
                                    height={h}
                                    rx={2}
                                    fill="#e0f2fe"
                                    stroke={
                                        selected || pending
                                            ? '#2563eb'
                                            : resolved.attached
                                              ? '#0284c7'
                                              : '#d97706'
                                    }
                                    strokeWidth={selected || pending ? 3 : 2}
                                    strokeDasharray={
                                        (pending && !selected) ||
                                        (!resolved.attached &&
                                            !(selected || pending))
                                            ? '6 4'
                                            : undefined
                                    }
                                    onPointerDown={(event) =>
                                        beginMoveDrag(event, {
                                            type: 'window',
                                            id: win.id,
                                        })
                                    }
                                    onClick={(event) => event.stopPropagation()}
                                    onContextMenu={(event) =>
                                        openContextMenu(
                                            event,
                                            { type: 'window', id: win.id },
                                            structureInteractive,
                                        )
                                    }
                                    style={{
                                        cursor: selected ? 'grab' : 'move',
                                    }}
                                />
                                <line
                                    x1={x + 5}
                                    y1={y - 3}
                                    x2={x + w - 5}
                                    y2={y - 3}
                                    stroke="#0369a1"
                                    strokeWidth={1.5}
                                    pointerEvents="none"
                                />
                                <line
                                    x1={x + 5}
                                    y1={y + 3}
                                    x2={x + w - 5}
                                    y2={y + 3}
                                    stroke="#0369a1"
                                    strokeWidth={1.5}
                                    pointerEvents="none"
                                />
                                {onlySelected &&
                                    structureInteractive &&
                                    !resolved.attached && (
                                        <RotationHandle
                                            cx={cx}
                                            cy={cy}
                                            offset={26}
                                            onBegin={(event) =>
                                                beginRotateDrag(
                                                    event,
                                                    {
                                                        type: 'window',
                                                        id: win.id,
                                                    },
                                                    {
                                                        x:
                                                            resolved.x +
                                                            resolved.width / 2,
                                                        y: resolved.y,
                                                    },
                                                    rotation,
                                                )
                                            }
                                        />
                                    )}
                            </g>
                        );
                    })}

                {/* Labels */}
                {showStructure &&
                    layout.labels?.map((label) => {
                        const selected = isRefInSelection(selection, {
                            type: 'label',
                            id: label.id,
                        });
                        const pending = hasPendingRef({
                            type: 'label',
                            id: label.id,
                        });
                        const isEditing =
                            editing?.type === 'label' &&
                            String(editing.id) === label.id;
                        const x = label.x * canvasWidth;
                        const y = label.y * canvasHeight;
                        if (isEditing) {
                            return (
                                <foreignObject
                                    key={label.id}
                                    x={x - 60}
                                    y={y - 18}
                                    width={140}
                                    height={28}
                                >
                                    <input
                                        autoFocus
                                        defaultValue={label.text ?? ''}
                                        onBlur={(event) => {
                                            dispatch({ type: 'commit' });
                                            dispatch({
                                                type: 'update_label',
                                                id: label.id,
                                                patch: {
                                                    text:
                                                        event.target.value ||
                                                        'Label',
                                                },
                                            });
                                            dispatch({ type: 'end_edit' });
                                        }}
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter')
                                                (
                                                    event.target as HTMLInputElement
                                                ).blur();
                                            if (event.key === 'Escape') {
                                                event.preventDefault();
                                                dispatch({ type: 'end_edit' });
                                            }
                                        }}
                                        className="w-full rounded border border-blue-400 bg-white px-1.5 py-0.5 text-sm text-slate-900 outline-none"
                                    />
                                </foreignObject>
                            );
                        }
                        const rotation = label.rotation_deg ?? 0;
                        const onlySelected = selected && selection.length === 1;
                        return (
                            <g
                                key={label.id}
                                transform={
                                    rotation
                                        ? `rotate(${rotation} ${x} ${y})`
                                        : undefined
                                }
                                opacity={structureInteractive ? 1 : 0.45}
                                pointerEvents={
                                    itemsInteractive ? 'auto' : 'none'
                                }
                            >
                                <text
                                    x={x}
                                    y={y}
                                    fontSize={label.size ?? 16}
                                    fill={
                                        selected || pending
                                            ? '#2563eb'
                                            : '#334155'
                                    }
                                    onPointerDown={(event) =>
                                        beginMoveDrag(event, {
                                            type: 'label',
                                            id: label.id,
                                        })
                                    }
                                    onClick={(event) => event.stopPropagation()}
                                    onDoubleClick={(event) => {
                                        event.stopPropagation();
                                        dispatch({
                                            type: 'begin_edit',
                                            target: {
                                                type: 'label',
                                                id: label.id,
                                            },
                                        });
                                    }}
                                    onContextMenu={(event) =>
                                        openContextMenu(
                                            event,
                                            { type: 'label', id: label.id },
                                            structureInteractive,
                                        )
                                    }
                                    style={{
                                        cursor: selected ? 'grab' : 'move',
                                    }}
                                >
                                    {label.text}
                                </text>
                                {onlySelected && structureInteractive && (
                                    <RotationHandle
                                        cx={x}
                                        cy={y - 12}
                                        offset={20}
                                        onBegin={(event) =>
                                            beginRotateDrag(
                                                event,
                                                { type: 'label', id: label.id },
                                                { x: label.x, y: label.y },
                                                rotation,
                                            )
                                        }
                                    />
                                )}
                            </g>
                        );
                    })}

                {/* Evacuation route polylines */}
                {orderedPins.map(({ pin, index }) => {
                    if (
                        pin.kind !== 'evacuation_route' ||
                        !pin.path_points ||
                        pin.path_points.length < 2
                    )
                        return null;
                    if (!isVisible(pin.kind)) return null;
                    const points = pin.path_points
                        .map(
                            (p) => `${p.x * canvasWidth},${p.y * canvasHeight}`,
                        )
                        .join(' ');
                    return (
                        <polyline
                            key={`route-${pinIdOf(pin, index)}`}
                            points={points}
                            fill="none"
                            stroke="#d97706"
                            strokeWidth={4}
                            strokeDasharray="8 6"
                            pointerEvents="none"
                        />
                    );
                })}

                {/* Pins */}
                {orderedPins.map(({ pin, index }) => {
                    if (!isVisible(pin.kind)) return null;
                    const id = pinIdOf(pin, index);
                    const selected = isRefInSelection(selection, {
                        type: 'pin',
                        id,
                    });
                    const onlySelected = selected && selection.length === 1;
                    const pending = hasPendingRef({ type: 'pin', id });
                    const isEditing =
                        editing?.type === 'pin' && String(editing.id) === id;
                    const style = pinStyle(pin.kind, taxonomy);
                    const Icon = resolveIcon(style.icon);
                    const x = pin.x * canvasWidth;
                    const y = pin.y * canvasHeight;
                    const editable = isEditablePinKind(pin.kind);
                    const hasError = Boolean(validationErrors[`pin:${id}`]);
                    const rotation = pin.rotation_deg ?? 0;
                    const canRotate = pin.kind !== 'evacuation_route';
                    return (
                        <g
                            key={`pin-${id}`}
                            transform={`translate(${x} ${y})`}
                            pointerEvents={pinsInteractive ? 'auto' : 'none'}
                            onPointerDown={
                                editable
                                    ? (event) =>
                                          beginMoveDrag(event, {
                                              type: 'pin',
                                              id,
                                          })
                                    : undefined
                            }
                            onClick={(event) => event.stopPropagation()}
                            onDoubleClick={(event) => {
                                event.stopPropagation();
                                if (editable)
                                    dispatch({
                                        type: 'begin_edit',
                                        target: { type: 'pin', id },
                                    });
                            }}
                            onContextMenu={(event) =>
                                openContextMenu(
                                    event,
                                    { type: 'pin', id },
                                    editable,
                                )
                            }
                            style={{
                                cursor: editable
                                    ? selected
                                        ? 'grab'
                                        : 'move'
                                    : 'default',
                            }}
                        >
                            <g
                                transform={
                                    rotation ? `rotate(${rotation})` : undefined
                                }
                            >
                                <circle
                                    r={selected ? 18 : 14}
                                    fill={style.color}
                                    stroke={
                                        hasError
                                            ? '#dc2626'
                                            : pending && !selected
                                              ? '#2563eb'
                                              : '#fff'
                                    }
                                    strokeWidth={hasError ? 5 : 4}
                                    strokeDasharray={
                                        pending && !selected ? '5 4' : undefined
                                    }
                                />
                                {hasError && (
                                    <circle
                                        r={23}
                                        fill="none"
                                        stroke="#dc2626"
                                        strokeWidth={2}
                                        strokeDasharray="4 3"
                                    />
                                )}
                                <foreignObject
                                    x={-8}
                                    y={-8}
                                    width={16}
                                    height={16}
                                >
                                    <Icon className="h-4 w-4 text-white" />
                                </foreignObject>
                                {onlySelected && editable && canRotate && (
                                    <RotationHandle
                                        cx={0}
                                        cy={0}
                                        offset={32}
                                        onBegin={(event) =>
                                            beginRotateDrag(
                                                event,
                                                { type: 'pin', id },
                                                { x: pin.x, y: pin.y },
                                                rotation,
                                            )
                                        }
                                    />
                                )}
                            </g>
                            {isEditing ? (
                                <foreignObject
                                    x={20}
                                    y={-12}
                                    width={180}
                                    height={24}
                                >
                                    <input
                                        autoFocus
                                        defaultValue={pin.label ?? ''}
                                        placeholder={
                                            taxonomy?.kinds?.[pin.kind]
                                                ?.label ?? pin.kind
                                        }
                                        onBlur={(event) => {
                                            dispatch({ type: 'commit' });
                                            dispatch({
                                                type: 'update_pin',
                                                pinId: id,
                                                patch: {
                                                    label:
                                                        event.target.value ||
                                                        null,
                                                },
                                            });
                                            dispatch({ type: 'end_edit' });
                                        }}
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter')
                                                (
                                                    event.target as HTMLInputElement
                                                ).blur();
                                            if (event.key === 'Escape') {
                                                event.preventDefault();
                                                dispatch({ type: 'end_edit' });
                                            }
                                        }}
                                        className="w-full rounded border border-blue-400 bg-white px-1 py-0.5 text-xs text-slate-900 outline-none"
                                    />
                                </foreignObject>
                            ) : (
                                <text
                                    x={20}
                                    y={5}
                                    fontSize={13}
                                    fill="#111827"
                                    pointerEvents="none"
                                >
                                    {pin.label || pin.kind.replaceAll('_', ' ')}
                                    {pin.subkind
                                        ? ` · ${pin.subkind.replaceAll('_', ' ')}`
                                        : ''}
                                </text>
                            )}
                        </g>
                    );
                })}

                {/* Drawing previews */}
                {interaction.mode === 'drawing_wall' && interaction.cursor && (
                    <g pointerEvents="none">
                        <line
                            x1={interaction.firstPoint.x * canvasWidth}
                            y1={interaction.firstPoint.y * canvasHeight}
                            x2={interaction.cursor.x * canvasWidth}
                            y2={interaction.cursor.y * canvasHeight}
                            stroke="#2563eb"
                            strokeWidth={3}
                            strokeDasharray="6 4"
                        />
                        <circle
                            cx={interaction.firstPoint.x * canvasWidth}
                            cy={interaction.firstPoint.y * canvasHeight}
                            r={5}
                            fill="#2563eb"
                        />
                        {renderSnapPair(
                            interaction.rawCursor,
                            interaction.cursor,
                        )}
                        <g
                            transform={`translate(${
                                ((interaction.firstPoint.x +
                                    interaction.cursor.x) /
                                    2) *
                                canvasWidth
                            } ${((interaction.firstPoint.y + interaction.cursor.y) / 2) * canvasHeight - 14})`}
                        >
                            <rect
                                x={-34}
                                y={-12}
                                width={68}
                                height={18}
                                rx={3}
                                fill="#2563eb"
                            />
                            <text
                                x={0}
                                y={1}
                                fill="#ffffff"
                                fontSize={11}
                                textAnchor="middle"
                            >
                                {formatMeters(
                                    distanceCanvasUnits(
                                        interaction.firstPoint,
                                        interaction.cursor,
                                        canvasWidth,
                                        canvasHeight,
                                    ),
                                    mpu,
                                )}
                            </text>
                        </g>
                    </g>
                )}

                {interaction.mode === 'drawing_polyline' &&
                    interaction.points.length >= 1 && (
                        <g pointerEvents="none">
                            <polyline
                                points={[
                                    ...interaction.points.map(
                                        (p) =>
                                            `${p.x * canvasWidth},${p.y * canvasHeight}`,
                                    ),
                                    interaction.cursor
                                        ? `${interaction.cursor.x * canvasWidth},${interaction.cursor.y * canvasHeight}`
                                        : '',
                                ]
                                    .filter(Boolean)
                                    .join(' ')}
                                fill="none"
                                stroke="#d97706"
                                strokeWidth={3}
                                strokeDasharray="6 4"
                            />
                            {interaction.points.map((p, i) => (
                                <circle
                                    key={i}
                                    cx={p.x * canvasWidth}
                                    cy={p.y * canvasHeight}
                                    r={4}
                                    fill="#d97706"
                                />
                            ))}
                            {renderSnapPair(
                                interaction.rawCursor,
                                interaction.cursor,
                            )}
                        </g>
                    )}

                {interaction.mode === 'calibrating' &&
                    interaction.firstPoint && (
                        <g pointerEvents="none">
                            <circle
                                cx={interaction.firstPoint.x * canvasWidth}
                                cy={interaction.firstPoint.y * canvasHeight}
                                r={6}
                                fill="#2563eb"
                            />
                            {interaction.secondPoint && (
                                <>
                                    <line
                                        x1={
                                            interaction.firstPoint.x *
                                            canvasWidth
                                        }
                                        y1={
                                            interaction.firstPoint.y *
                                            canvasHeight
                                        }
                                        x2={
                                            interaction.secondPoint.x *
                                            canvasWidth
                                        }
                                        y2={
                                            interaction.secondPoint.y *
                                            canvasHeight
                                        }
                                        stroke="#2563eb"
                                        strokeWidth={3}
                                    />
                                    <g
                                        transform={`translate(${
                                            ((interaction.firstPoint.x +
                                                interaction.secondPoint.x) /
                                                2) *
                                            canvasWidth
                                        } ${((interaction.firstPoint.y + interaction.secondPoint.y) / 2) * canvasHeight - 12})`}
                                    >
                                        <rect
                                            x={-50}
                                            y={-12}
                                            width={100}
                                            height={18}
                                            rx={3}
                                            fill="#2563eb"
                                        />
                                        <text
                                            x={0}
                                            y={1}
                                            fill="#ffffff"
                                            fontSize={11}
                                            textAnchor="middle"
                                        >
                                            Calibrating:{' '}
                                            {formatMeters(
                                                distanceCanvasUnits(
                                                    interaction.firstPoint,
                                                    interaction.secondPoint,
                                                    canvasWidth,
                                                    canvasHeight,
                                                ),
                                                mpu,
                                            )}
                                        </text>
                                    </g>
                                    {renderSnapPair(
                                        interaction.rawSecondPoint,
                                        interaction.secondPoint,
                                    )}
                                </>
                            )}
                        </g>
                    )}

                {interaction.mode === 'marquee' && (
                    <rect
                        x={
                            Math.min(
                                interaction.firstPoint.x,
                                interaction.cursor.x,
                            ) * canvasWidth
                        }
                        y={
                            Math.min(
                                interaction.firstPoint.y,
                                interaction.cursor.y,
                            ) * canvasHeight
                        }
                        width={
                            Math.abs(
                                interaction.cursor.x - interaction.firstPoint.x,
                            ) * canvasWidth
                        }
                        height={
                            Math.abs(
                                interaction.cursor.y - interaction.firstPoint.y,
                            ) * canvasHeight
                        }
                        fill="rgba(37, 99, 235, 0.08)"
                        stroke="#2563eb"
                        strokeWidth={1}
                        strokeDasharray="6 4"
                        pointerEvents="none"
                    />
                )}

                {groupBounds && (
                    <rect
                        x={groupBounds.x * canvasWidth - 8}
                        y={groupBounds.y * canvasHeight - 8}
                        width={groupBounds.width * canvasWidth + 16}
                        height={groupBounds.height * canvasHeight + 16}
                        fill="none"
                        stroke="#2563eb"
                        strokeWidth={2}
                        strokeDasharray="8 5"
                        pointerEvents="none"
                    />
                )}

                {!isSelectMode(activeKind) &&
                    activeKind &&
                    hoverPoint &&
                    interaction.mode === 'idle' && (
                        <g
                            transform={`translate(${hoverPoint.x * canvasWidth} ${hoverPoint.y * canvasHeight})`}
                            pointerEvents="none"
                            opacity={0.42}
                        >
                            {activeKind === '__room' ? (
                                <rect
                                    x={-90}
                                    y={-55}
                                    width={180}
                                    height={110}
                                    fill="#dbeafe"
                                    stroke="#2563eb"
                                    strokeWidth={2}
                                    strokeDasharray="6 4"
                                />
                            ) : activeKind === '__wall' ? (
                                <line
                                    x1={-48}
                                    y1={0}
                                    x2={48}
                                    y2={0}
                                    stroke="#2563eb"
                                    strokeWidth={4}
                                    strokeDasharray="6 4"
                                />
                            ) : activeKind === '__door' ||
                              activeKind === '__window' ? (
                                <rect
                                    x={-35}
                                    y={-5}
                                    width={70}
                                    height={10}
                                    fill="#2563eb"
                                />
                            ) : activeKind === '__label' ? (
                                <text
                                    x={0}
                                    y={4}
                                    textAnchor="middle"
                                    fontSize={16}
                                    fill="#2563eb"
                                >
                                    Label
                                </text>
                            ) : activeKind !== '__scale' ? (
                                <circle
                                    r={14}
                                    fill={pinStyle(activeKind, taxonomy).color}
                                />
                            ) : null}
                        </g>
                    )}
            </svg>

            <div className="pointer-events-none absolute right-2 bottom-2 flex flex-col items-end gap-1">
                <div className="rounded-md border bg-white/90 px-2 py-1 text-xs text-slate-600 shadow-sm">
                    Scale: 1 m ≈ {(1 / mpu).toFixed(0)} units · grid{' '}
                    {layout.grid?.size ?? 10}
                    {layout.grid?.snap === false
                        ? ' · snap off'
                        : altHeld
                          ? ' · Alt unsnapped'
                          : ''}
                    {selection.length > 1 && ` · ${selection.length} selected`}
                </div>
                {selection.length > 1 && (
                    <div
                        className="rounded-md border bg-blue-50 px-2 py-1 text-xs text-blue-900 shadow-sm"
                        data-test="site-plan-marquee-count"
                    >
                        {selection.length} items selected - drag any selected
                        item to move all - Delete removes
                    </div>
                )}
                {!isSelectMode(activeKind) && activeKind && (
                    <div
                        className="rounded-md border bg-blue-50 px-2 py-1 text-xs text-blue-900 shadow-sm"
                        data-test="site-plan-tool-hint"
                    >
                        Tool:{' '}
                        <strong>
                            {toolHint(activeKind, activeSubkind, taxonomy)}
                        </strong>
                        {activeKind === '__wall' ? ' (click two points)' : ''}
                        {activeKind === '__scale'
                            ? ' (click two points to calibrate)'
                            : ''}
                        {activeKind === 'evacuation_route'
                            ? ' (click vertices, double-click to finish)'
                            : ''}
                    </div>
                )}
                {isSelectMode(activeKind) && selection.length === 0 && (
                    <div className="rounded-md border bg-slate-50 px-2 py-1 text-xs text-slate-700 shadow-sm">
                        Drag on empty canvas to select multiple items ·
                        Shift-click to add · Double-click to edit text
                    </div>
                )}
            </div>

            {contextMenu && (
                <DropdownMenu
                    open
                    onOpenChange={(next) => !next && setContextMenu(null)}
                >
                    <DropdownMenuTrigger asChild>
                        <span
                            aria-hidden
                            style={{
                                position: 'fixed',
                                left: contextMenu.x,
                                top: contextMenu.y,
                                width: 1,
                                height: 1,
                                pointerEvents: 'none',
                            }}
                        />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        align="start"
                        data-test="site-plan-context-menu"
                    >
                        <DropdownMenuLabel className="text-[10px] tracking-wider text-slate-500 uppercase">
                            {contextMenu.ref.type === 'pin'
                                ? 'Pin'
                                : contextMenu.ref.type.charAt(0).toUpperCase() +
                                  contextMenu.ref.type.slice(1)}
                        </DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        {contextMenu.ref.type === 'pin' && (
                            <>
                                <DropdownMenuItem
                                    data-test="site-plan-context-bring-to-front"
                                    onSelect={() => {
                                        dispatch({
                                            type: 'bring_to_front',
                                            ref: contextMenu.ref,
                                        });
                                        setContextMenu(null);
                                    }}
                                >
                                    <Lucide.BringToFront className="mr-2 h-4 w-4" />
                                    Bring to front
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    data-test="site-plan-context-send-to-back"
                                    onSelect={() => {
                                        dispatch({
                                            type: 'send_to_back',
                                            ref: contextMenu.ref,
                                        });
                                        setContextMenu(null);
                                    }}
                                >
                                    <Lucide.SendToBack className="mr-2 h-4 w-4" />
                                    Send to back
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                            </>
                        )}
                        <DropdownMenuItem
                            data-test="site-plan-context-duplicate"
                            onSelect={() => {
                                dispatch({ type: 'commit' });
                                dispatch({
                                    type: 'set_selection',
                                    refs: [contextMenu.ref],
                                });
                                dispatch({ type: 'duplicate_selected' });
                                setContextMenu(null);
                            }}
                        >
                            <Lucide.Copy className="mr-2 h-4 w-4" />
                            Duplicate
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            data-test="site-plan-context-delete"
                            onSelect={() => {
                                dispatch({ type: 'commit' });
                                dispatch({
                                    type: 'set_selection',
                                    refs: [contextMenu.ref],
                                });
                                dispatch({ type: 'delete_selected' });
                                setContextMenu(null);
                            }}
                        >
                            <Lucide.Trash2 className="mr-2 h-4 w-4 text-red-600" />
                            Delete
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            )}
        </div>
    );
}

function RotationHandle({
    cx,
    cy,
    offset,
    onBegin,
}: {
    cx: number;
    cy: number;
    offset: number;
    onBegin: (event: React.PointerEvent) => void;
}) {
    return (
        <g pointerEvents="auto">
            <line
                x1={cx}
                y1={cy}
                x2={cx}
                y2={cy - offset}
                stroke="#2563eb"
                strokeWidth={1.5}
                pointerEvents="none"
            />
            <circle
                cx={cx}
                cy={cy - offset}
                r={6}
                fill="#ffffff"
                stroke="#2563eb"
                strokeWidth={2}
                style={{ cursor: 'grab' }}
                onPointerDown={onBegin}
                onClick={(event) => event.stopPropagation()}
            />
        </g>
    );
}

function toolHint(
    kind: string,
    subkind: string | null,
    taxonomy: Taxonomy | null,
): string {
    if (kind === '__room') return 'Room';
    if (kind === '__wall') return 'Wall';
    if (kind === '__door') return 'Door';
    if (kind === '__window') return 'Window';
    if (kind === '__label') return 'Label';
    if (kind === '__scale') return 'Set scale';
    const t = taxonomy?.kinds?.[kind];
    const sub = t?.subkinds?.find((s) => s.value === subkind);
    if (!t) return kind.replaceAll('_', ' ');
    return sub ? `${t.label} — ${sub.label}` : t.label;
}
