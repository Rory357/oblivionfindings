import { useCallback, useEffect, useMemo, useReducer } from 'react';
import { roomIdFromEdgeWallId } from './_geometry';
import {
    SELECT_TOOL,
    metersPerUnit,
    normaliseLayout,
    type PlanDoor,
    type PlanLabel,
    type PlanLayout,
    type PlanPin,
    type PlanRoom,
    type PlanWall,
    type PlanWindow,
    type RoomRefType,
    type SelectionRef,
} from './_types';

const HISTORY_LIMIT = 50;

type DraftLayout = Required<
    Pick<PlanLayout, 'rooms' | 'walls' | 'doors' | 'windows' | 'labels'>
> &
    PlanLayout;

export type LayerKey =
    | 'structure'
    | 'fire'
    | 'emergency'
    | 'life_safety'
    | 'utilities'
    | 'devices'
    | 'annotation';

export type Interaction =
    | { mode: 'idle' }
    | {
          mode: 'drawing_wall';
          firstPoint: { x: number; y: number };
          cursor: { x: number; y: number } | null;
          rawCursor: { x: number; y: number } | null;
      }
    | {
          mode: 'drawing_polyline';
          points: Array<{ x: number; y: number }>;
          cursor: { x: number; y: number } | null;
          rawCursor: { x: number; y: number } | null;
      }
    | {
          mode: 'calibrating';
          firstPoint: { x: number; y: number } | null;
          secondPoint: { x: number; y: number } | null;
          rawSecondPoint: { x: number; y: number } | null;
      }
    | {
          mode: 'marquee';
          firstPoint: { x: number; y: number };
          cursor: { x: number; y: number };
          pendingRefs: SelectionRef[];
      };

export type EditingTarget = {
    type: 'room' | 'label' | 'pin';
    id: string | number;
};

type Snapshot = { layout: DraftLayout; pins: PlanPin[] };

export type EditorState = Snapshot & {
    selection: SelectionRef[];
    editing: EditingTarget | null;
    activeKind: string | null;
    activeSubkind: string | null;
    interaction: Interaction;
    past: Snapshot[];
    future: Snapshot[];
    layers: Record<LayerKey, boolean>;
    validationErrors: Record<string, string>;
    dirty: boolean;
};

export type EditorAction =
    | { type: 'replace_all'; layout: PlanLayout; pins: PlanPin[] }
    | { type: 'commit' }
    | { type: 'set_tool'; kind: string | null; subkind?: string | null }
    | { type: 'select'; ref: SelectionRef | null; additive?: boolean }
    | { type: 'set_selection'; refs: SelectionRef[] }
    | { type: 'delete_selected' }
    | { type: 'duplicate_selected' }
    | { type: 'undo' }
    | { type: 'redo' }
    | { type: 'begin_edit'; target: EditingTarget }
    | { type: 'end_edit' }
    | { type: 'add_room'; room: PlanRoom; selectAfter?: boolean }
    | { type: 'add_wall'; wall: PlanWall; selectAfter?: boolean }
    | { type: 'add_door'; door: PlanDoor; selectAfter?: boolean }
    | { type: 'add_window'; window: PlanWindow; selectAfter?: boolean }
    | { type: 'add_label'; label: PlanLabel; selectAfter?: boolean }
    | { type: 'add_pin'; pin: PlanPin; selectAfter?: boolean }
    | { type: 'update_room'; id: string; patch: Partial<PlanRoom> }
    | { type: 'update_wall'; id: string; patch: Partial<PlanWall> }
    | { type: 'update_door'; id: string; patch: Partial<PlanDoor> }
    | { type: 'update_window'; id: string; patch: Partial<PlanWindow> }
    | { type: 'update_label'; id: string; patch: Partial<PlanLabel> }
    | { type: 'update_pin'; pinId: string | number; patch: Partial<PlanPin> }
    | {
          type: 'link_room';
          roomId: string;
          ref: { type: RoomRefType; id: number; name: string } | null;
      }
    | {
          type: 'link_device';
          pinId: string | number;
          device: { id: number; name: string } | null;
      }
    | {
          type: 'begin_drawing_wall';
          point: { x: number; y: number };
          rawPoint?: { x: number; y: number };
      }
    | {
          type: 'update_wall_cursor';
          point: { x: number; y: number } | null;
          rawPoint?: { x: number; y: number } | null;
      }
    | { type: 'complete_drawing_wall'; point: { x: number; y: number } }
    | {
          type: 'begin_drawing_polyline';
          point: { x: number; y: number };
          rawPoint?: { x: number; y: number };
      }
    | { type: 'append_polyline_vertex'; point: { x: number; y: number } }
    | {
          type: 'update_polyline_cursor';
          point: { x: number; y: number } | null;
          rawPoint?: { x: number; y: number } | null;
      }
    | { type: 'complete_polyline'; kind: string; subkind?: string | null }
    | {
          type: 'begin_calibration';
          point: { x: number; y: number };
          rawPoint?: { x: number; y: number };
      }
    | {
          type: 'update_calibration_cursor';
          point: { x: number; y: number };
          rawPoint?: { x: number; y: number };
      }
    | {
          type: 'complete_calibration_second';
          point: { x: number; y: number };
          rawPoint?: { x: number; y: number };
      }
    | { type: 'apply_calibration'; metersPerUnit: number }
    | { type: 'begin_marquee'; point: { x: number; y: number } }
    | {
          type: 'update_marquee_cursor';
          point: { x: number; y: number };
          pendingRefs?: SelectionRef[];
      }
    | { type: 'complete_marquee'; refs: SelectionRef[] }
    | { type: 'cancel_interaction' }
    | { type: 'set_layer_visibility'; layer: LayerKey; visible: boolean }
    | { type: 'set_grid_snap'; snap: boolean }
    | { type: 'set_canvas_meters_per_unit'; metersPerUnit: number }
    | { type: 'set_validation_errors'; errors: Record<string, string> }
    | { type: 'bring_to_front'; ref: SelectionRef }
    | { type: 'send_to_back'; ref: SelectionRef }
    | { type: 'mark_clean' };

function snapshot(state: Snapshot): Snapshot {
    return {
        layout: structuredClone(state.layout),
        pins: structuredClone(state.pins),
    };
}

function pushHistory(state: EditorState): EditorState {
    const next = [...state.past, snapshot(state)];
    return {
        ...state,
        past:
            next.length > HISTORY_LIMIT
                ? next.slice(next.length - HISTORY_LIMIT)
                : next,
        future: [],
        dirty: true,
    };
}

function withDirty(state: EditorState): EditorState {
    return { ...state, dirty: true };
}

function pinId(pin: PlanPin, index: number): string {
    return pin.id != null ? String(pin.id) : `__idx-${index}`;
}

function sameRef(a: SelectionRef, b: SelectionRef): boolean {
    return a.type === b.type && String(a.id) === String(b.id);
}

function refKey(ref: SelectionRef): string {
    return `${ref.type}:${ref.id}`;
}

function newElementId(prefix: string): string {
    return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
}

function clamp01(value: number): number {
    return Math.min(1, Math.max(0, value));
}

function reducer(state: EditorState, action: EditorAction): EditorState {
    switch (action.type) {
        case 'replace_all': {
            const layout = normaliseLayout(action.layout);
            return {
                ...state,
                layout,
                pins: action.pins,
                selection: [],
                editing: null,
                past: [],
                future: [],
                interaction: { mode: 'idle' },
                activeKind: SELECT_TOOL,
                dirty: false,
            };
        }

        case 'commit': {
            return pushHistory(state);
        }

        case 'undo': {
            if (state.past.length === 0) return state;
            const previous = state.past[state.past.length - 1];
            return {
                ...state,
                layout: previous.layout,
                pins: previous.pins,
                past: state.past.slice(0, -1),
                future: [
                    ...state.future,
                    { layout: state.layout, pins: state.pins },
                ],
                selection: [],
                editing: null,
                interaction: { mode: 'idle' },
                dirty: true,
            };
        }

        case 'redo': {
            if (state.future.length === 0) return state;
            const next = state.future[state.future.length - 1];
            return {
                ...state,
                layout: next.layout,
                pins: next.pins,
                past: [
                    ...state.past,
                    { layout: state.layout, pins: state.pins },
                ],
                future: state.future.slice(0, -1),
                selection: [],
                editing: null,
                interaction: { mode: 'idle' },
                dirty: true,
            };
        }

        case 'set_tool': {
            return {
                ...state,
                activeKind: action.kind ?? SELECT_TOOL,
                activeSubkind: action.subkind ?? null,
                interaction: { mode: 'idle' },
                editing: null,
            };
        }

        case 'select': {
            if (!action.ref) return { ...state, selection: [] };
            if (action.additive) {
                const exists = state.selection.some((s) =>
                    sameRef(s, action.ref!),
                );
                return {
                    ...state,
                    selection: exists
                        ? state.selection.filter(
                              (s) => !sameRef(s, action.ref!),
                          )
                        : [...state.selection, action.ref],
                };
            }
            const already =
                state.selection.length === 1 &&
                sameRef(state.selection[0], action.ref);
            return {
                ...state,
                selection: already ? state.selection : [action.ref],
            };
        }

        case 'set_selection': {
            return { ...state, selection: action.refs };
        }

        case 'begin_edit': {
            return { ...state, editing: action.target };
        }

        case 'end_edit': {
            return { ...state, editing: null };
        }

        case 'delete_selected': {
            if (state.selection.length === 0) return state;
            const roomIds = new Set<string>();
            const wallIds = new Set<string>();
            const doorIds = new Set<string>();
            const windowIds = new Set<string>();
            const labelIds = new Set<string>();
            const pinIds = new Set<string>();
            for (const ref of state.selection) {
                switch (ref.type) {
                    case 'room':
                        roomIds.add(String(ref.id));
                        break;
                    case 'wall':
                        wallIds.add(String(ref.id));
                        break;
                    case 'door':
                        doorIds.add(String(ref.id));
                        break;
                    case 'window':
                        windowIds.add(String(ref.id));
                        break;
                    case 'label':
                        labelIds.add(String(ref.id));
                        break;
                    case 'pin':
                        pinIds.add(String(ref.id));
                        break;
                }
            }
            const layout = state.layout;
            // Cascade: when a room is deleted, its auto-promoted edge walls
            // and any openings attached to them go with it.
            for (const wall of layout.walls) {
                const ownerRoomId = roomIdFromEdgeWallId(wall.id);
                if (ownerRoomId && roomIds.has(ownerRoomId)) {
                    wallIds.add(wall.id);
                }
            }
            // Cascade: when a wall is deleted, openings attached to it are
            // removed too (they'd otherwise float as orphan ghosts).
            for (const door of layout.doors) {
                if (door.wall_id && wallIds.has(door.wall_id)) {
                    doorIds.add(door.id);
                }
            }
            for (const win of layout.windows) {
                if (win.wall_id && wallIds.has(win.wall_id)) {
                    windowIds.add(win.id);
                }
            }
            return withDirty({
                ...state,
                layout: {
                    ...layout,
                    rooms: layout.rooms.filter((room) => !roomIds.has(room.id)),
                    walls: layout.walls.filter((wall) => !wallIds.has(wall.id)),
                    doors: layout.doors.filter((door) => !doorIds.has(door.id)),
                    windows: layout.windows.filter((w) => !windowIds.has(w.id)),
                    labels: layout.labels.filter((l) => !labelIds.has(l.id)),
                },
                pins: state.pins.filter(
                    (pin, index) => !pinIds.has(pinId(pin, index)),
                ),
                selection: [],
                editing: null,
            });
        }

        case 'duplicate_selected': {
            if (state.selection.length === 0) return state;
            const nextLayout = structuredClone(state.layout);
            const nextPins = structuredClone(state.pins);
            const nextSelection: SelectionRef[] = [];
            const offset = 0.025;

            for (const ref of state.selection) {
                if (ref.type === 'room') {
                    const source = state.layout.rooms.find(
                        (room) => room.id === ref.id,
                    );
                    if (!source) continue;
                    const duplicate = {
                        ...source,
                        id: newElementId('room'),
                        x: clamp01(source.x + offset),
                        y: clamp01(source.y + offset),
                        room_ref_type: null,
                        room_ref_id: null,
                        label: source.label
                            ? `${source.label} copy`
                            : 'Room copy',
                    };
                    nextLayout.rooms.push(duplicate);
                    nextSelection.push({ type: 'room', id: duplicate.id });
                } else if (ref.type === 'wall') {
                    const source = state.layout.walls.find(
                        (wall) => wall.id === ref.id,
                    );
                    if (!source) continue;
                    const duplicate = {
                        ...source,
                        id: newElementId('wall'),
                        points: source.points.map((point) => ({
                            x: clamp01(point.x + offset),
                            y: clamp01(point.y + offset),
                        })),
                    };
                    nextLayout.walls.push(duplicate);
                    nextSelection.push({ type: 'wall', id: duplicate.id });
                } else if (ref.type === 'door') {
                    const source = state.layout.doors.find(
                        (door) => door.id === ref.id,
                    );
                    if (!source) continue;
                    const duplicate = {
                        ...source,
                        id: newElementId('door'),
                        x: clamp01(source.x + offset),
                        y: clamp01(source.y + offset),
                        wall_t:
                            typeof source.wall_t === 'number'
                                ? clamp01(source.wall_t + 0.08)
                                : source.wall_t,
                    };
                    nextLayout.doors.push(duplicate);
                    nextSelection.push({ type: 'door', id: duplicate.id });
                } else if (ref.type === 'window') {
                    const source = state.layout.windows.find(
                        (window) => window.id === ref.id,
                    );
                    if (!source) continue;
                    const duplicate = {
                        ...source,
                        id: newElementId('window'),
                        x: clamp01(source.x + offset),
                        y: clamp01(source.y + offset),
                        wall_t:
                            typeof source.wall_t === 'number'
                                ? clamp01(source.wall_t + 0.08)
                                : source.wall_t,
                    };
                    nextLayout.windows.push(duplicate);
                    nextSelection.push({ type: 'window', id: duplicate.id });
                } else if (ref.type === 'label') {
                    const source = state.layout.labels.find(
                        (label) => label.id === ref.id,
                    );
                    if (!source) continue;
                    const duplicate = {
                        ...source,
                        id: newElementId('label'),
                        x: clamp01(source.x + offset),
                        y: clamp01(source.y + offset),
                        text: source.text
                            ? `${source.text} copy`
                            : 'Label copy',
                    };
                    nextLayout.labels.push(duplicate);
                    nextSelection.push({ type: 'label', id: duplicate.id });
                } else if (ref.type === 'pin') {
                    const index = state.pins.findIndex(
                        (pin, pinIndex) =>
                            pinId(pin, pinIndex) === String(ref.id),
                    );
                    const source = state.pins[index];
                    if (!source) continue;
                    const duplicate = {
                        ...source,
                        id: undefined,
                        x: clamp01(source.x + offset),
                        y: clamp01(source.y + offset),
                        label: source.label
                            ? `${source.label} copy`
                            : source.label,
                    };
                    nextPins.push(duplicate);
                    nextSelection.push({
                        type: 'pin',
                        id: pinId(duplicate, nextPins.length - 1),
                    });
                }
            }

            if (nextSelection.length === 0) return state;

            return withDirty({
                ...state,
                layout: nextLayout,
                pins: nextPins,
                selection: nextSelection,
                editing: null,
            });
        }

        case 'add_room': {
            const next = withDirty({
                ...state,
                layout: {
                    ...state.layout,
                    rooms: [...state.layout.rooms, action.room],
                },
            });
            return action.selectAfter
                ? { ...next, selection: [{ type: 'room', id: action.room.id }] }
                : next;
        }
        case 'add_wall': {
            const next = withDirty({
                ...state,
                layout: {
                    ...state.layout,
                    walls: [...state.layout.walls, action.wall],
                },
            });
            return action.selectAfter
                ? { ...next, selection: [{ type: 'wall', id: action.wall.id }] }
                : next;
        }
        case 'add_door': {
            const next = withDirty({
                ...state,
                layout: {
                    ...state.layout,
                    doors: [...state.layout.doors, action.door],
                },
            });
            return action.selectAfter
                ? { ...next, selection: [{ type: 'door', id: action.door.id }] }
                : next;
        }
        case 'add_window': {
            const next = withDirty({
                ...state,
                layout: {
                    ...state.layout,
                    windows: [...state.layout.windows, action.window],
                },
            });
            return action.selectAfter
                ? {
                      ...next,
                      selection: [{ type: 'window', id: action.window.id }],
                  }
                : next;
        }
        case 'add_label': {
            const next = withDirty({
                ...state,
                layout: {
                    ...state.layout,
                    labels: [...state.layout.labels, action.label],
                },
            });
            return action.selectAfter
                ? {
                      ...next,
                      selection: [{ type: 'label', id: action.label.id }],
                  }
                : next;
        }
        case 'add_pin': {
            const next = withDirty({
                ...state,
                pins: [...state.pins, action.pin],
            });
            if (action.selectAfter) {
                const idx = next.pins.length - 1;
                return {
                    ...next,
                    selection: [{ type: 'pin', id: pinId(action.pin, idx) }],
                };
            }
            return next;
        }

        case 'update_room':
            return withDirty({
                ...state,
                layout: {
                    ...state.layout,
                    rooms: state.layout.rooms.map((room) =>
                        room.id === action.id
                            ? { ...room, ...action.patch }
                            : room,
                    ),
                },
            });
        case 'update_wall':
            return withDirty({
                ...state,
                layout: {
                    ...state.layout,
                    walls: state.layout.walls.map((wall) =>
                        wall.id === action.id
                            ? { ...wall, ...action.patch }
                            : wall,
                    ),
                },
            });
        case 'update_door':
            return withDirty({
                ...state,
                layout: {
                    ...state.layout,
                    doors: state.layout.doors.map((door) =>
                        door.id === action.id
                            ? { ...door, ...action.patch }
                            : door,
                    ),
                },
            });
        case 'update_window':
            return withDirty({
                ...state,
                layout: {
                    ...state.layout,
                    windows: state.layout.windows.map((w) =>
                        w.id === action.id ? { ...w, ...action.patch } : w,
                    ),
                },
            });
        case 'update_label':
            return withDirty({
                ...state,
                layout: {
                    ...state.layout,
                    labels: state.layout.labels.map((l) =>
                        l.id === action.id ? { ...l, ...action.patch } : l,
                    ),
                },
            });
        case 'update_pin':
            return withDirty({
                ...state,
                pins: state.pins.map((pin, index) =>
                    pinId(pin, index) === String(action.pinId)
                        ? { ...pin, ...action.patch }
                        : pin,
                ),
                validationErrors: {
                    ...state.validationErrors,
                    [`pin:${action.pinId}`]: '',
                },
            });

        case 'link_room': {
            return withDirty({
                ...state,
                layout: {
                    ...state.layout,
                    rooms: state.layout.rooms.map((room) => {
                        if (room.id !== action.roomId) return room;
                        if (!action.ref) {
                            return {
                                ...room,
                                room_ref_type: null,
                                room_ref_id: null,
                            };
                        }
                        return {
                            ...room,
                            room_ref_type: action.ref.type,
                            room_ref_id: action.ref.id,
                            label: action.ref.name,
                        };
                    }),
                },
            });
        }

        case 'link_device': {
            return withDirty({
                ...state,
                pins: state.pins.map((pin, index) => {
                    if (pinId(pin, index) !== String(action.pinId)) return pin;
                    if (!action.device) {
                        return { ...pin, device_id: null };
                    }
                    return {
                        ...pin,
                        device_id: action.device.id,
                        label: pin.label ?? action.device.name,
                    };
                }),
            });
        }

        case 'begin_drawing_wall':
            return {
                ...state,
                interaction: {
                    mode: 'drawing_wall',
                    firstPoint: action.point,
                    cursor: null,
                    rawCursor: action.rawPoint ?? action.point,
                },
            };
        case 'update_wall_cursor':
            if (state.interaction.mode !== 'drawing_wall') return state;
            return {
                ...state,
                interaction: {
                    ...state.interaction,
                    cursor: action.point,
                    rawCursor: action.rawPoint ?? action.point,
                },
            };
        case 'complete_drawing_wall': {
            if (state.interaction.mode !== 'drawing_wall') return state;
            const wall: PlanWall = {
                id: `wall-${Date.now()}`,
                points: [state.interaction.firstPoint, action.point],
                thickness: 4,
            };
            return {
                ...withDirty({
                    ...state,
                    layout: {
                        ...state.layout,
                        walls: [...state.layout.walls, wall],
                    },
                }),
                interaction: { mode: 'idle' },
                selection: [{ type: 'wall', id: wall.id }],
            };
        }

        case 'begin_drawing_polyline':
            return {
                ...state,
                interaction: {
                    mode: 'drawing_polyline',
                    points: [action.point],
                    cursor: null,
                    rawCursor: action.rawPoint ?? action.point,
                },
            };
        case 'append_polyline_vertex':
            if (state.interaction.mode !== 'drawing_polyline') return state;
            return {
                ...state,
                interaction: {
                    ...state.interaction,
                    points: [...state.interaction.points, action.point],
                },
            };
        case 'update_polyline_cursor':
            if (state.interaction.mode !== 'drawing_polyline') return state;
            return {
                ...state,
                interaction: {
                    ...state.interaction,
                    cursor: action.point,
                    rawCursor: action.rawPoint ?? action.point,
                },
            };
        case 'complete_polyline': {
            if (state.interaction.mode !== 'drawing_polyline') return state;
            const points = state.interaction.points;
            if (points.length < 2)
                return { ...state, interaction: { mode: 'idle' } };
            const last = points[points.length - 1];
            const pin: PlanPin = {
                kind: action.kind,
                subkind: action.subkind ?? null,
                label: null,
                x: last.x,
                y: last.y,
                path_points: points,
            };
            return {
                ...withDirty({ ...state, pins: [...state.pins, pin] }),
                interaction: { mode: 'idle' },
            };
        }

        case 'begin_calibration':
            return {
                ...state,
                interaction: {
                    mode: 'calibrating',
                    firstPoint: action.point,
                    secondPoint: null,
                    rawSecondPoint: action.rawPoint ?? action.point,
                },
            };
        case 'update_calibration_cursor':
            if (state.interaction.mode !== 'calibrating') return state;
            return {
                ...state,
                interaction: {
                    ...state.interaction,
                    secondPoint: action.point,
                    rawSecondPoint: action.rawPoint ?? action.point,
                },
            };
        case 'complete_calibration_second':
            if (state.interaction.mode !== 'calibrating') return state;
            return {
                ...state,
                interaction: {
                    ...state.interaction,
                    secondPoint: action.point,
                    rawSecondPoint: action.rawPoint ?? action.point,
                },
            };
        case 'apply_calibration': {
            const current = metersPerUnit(state.layout);
            if (Math.abs(current - action.metersPerUnit) < 1e-9) {
                return { ...state, interaction: { mode: 'idle' } };
            }
            return {
                ...withDirty({
                    ...state,
                    layout: {
                        ...state.layout,
                        canvas: {
                            ...state.layout.canvas,
                            meters_per_unit: action.metersPerUnit,
                        },
                        scale: {
                            meters_per_unit: action.metersPerUnit,
                            calibrated_at: new Date().toISOString(),
                        },
                    },
                }),
                interaction: { mode: 'idle' },
            };
        }

        case 'begin_marquee':
            return {
                ...state,
                interaction: {
                    mode: 'marquee',
                    firstPoint: action.point,
                    cursor: action.point,
                    pendingRefs: [],
                },
            };
        case 'update_marquee_cursor':
            if (state.interaction.mode !== 'marquee') return state;
            return {
                ...state,
                interaction: {
                    ...state.interaction,
                    cursor: action.point,
                    pendingRefs: action.pendingRefs ?? [],
                },
            };
        case 'complete_marquee':
            return {
                ...state,
                interaction: { mode: 'idle' },
                selection: action.refs,
            };

        case 'cancel_interaction':
            return { ...state, interaction: { mode: 'idle' } };

        case 'set_layer_visibility':
            return {
                ...state,
                layers: { ...state.layers, [action.layer]: action.visible },
            };

        case 'set_grid_snap': {
            return withDirty({
                ...state,
                layout: {
                    ...state.layout,
                    grid: { ...(state.layout.grid ?? {}), snap: action.snap },
                },
            });
        }

        case 'set_canvas_meters_per_unit': {
            return withDirty({
                ...state,
                layout: {
                    ...state.layout,
                    canvas: {
                        ...state.layout.canvas,
                        meters_per_unit: action.metersPerUnit,
                    },
                },
            });
        }

        case 'set_validation_errors':
            return { ...state, validationErrors: action.errors };

        case 'bring_to_front':
            if (action.ref.type !== 'pin') return state;
            return withDirty({
                ...state,
                pins: state.pins.map((pin, index) =>
                    pinId(pin, index) === String(action.ref.id)
                        ? { ...pin, sort_order: state.pins.length }
                        : pin,
                ),
            });

        case 'send_to_back':
            if (action.ref.type !== 'pin') return state;
            return withDirty({
                ...state,
                pins: state.pins.map((pin, index) =>
                    pinId(pin, index) === String(action.ref.id)
                        ? { ...pin, sort_order: -1 }
                        : pin,
                ),
            });

        case 'mark_clean':
            return { ...state, dirty: false };

        default:
            return state;
    }
}

function initial(
    layout: PlanLayout | null | undefined,
    pins: PlanPin[],
): EditorState {
    return {
        layout: normaliseLayout(layout) as DraftLayout,
        pins,
        selection: [],
        editing: null,
        activeKind: SELECT_TOOL,
        activeSubkind: null,
        interaction: { mode: 'idle' },
        past: [],
        future: [],
        layers: {
            structure: true,
            fire: true,
            emergency: true,
            life_safety: true,
            utilities: true,
            devices: true,
            annotation: true,
        },
        validationErrors: {},
        dirty: false,
    };
}

export { refKey, sameRef };

export function usePlanEditor(
    initialLayout: PlanLayout | null | undefined,
    initialPins: PlanPin[],
) {
    const [state, dispatch] = useReducer(reducer, undefined, () =>
        initial(initialLayout, initialPins),
    );

    const canUndo = state.past.length > 0;
    const canRedo = state.future.length > 0;

    const reset = useCallback(
        (layout: PlanLayout | null | undefined, pins: PlanPin[]) => {
            dispatch({
                type: 'replace_all',
                layout: normaliseLayout(layout),
                pins,
            });
        },
        [dispatch],
    );

    const markClean = useCallback(
        () => dispatch({ type: 'mark_clean' }),
        [dispatch],
    );

    const helpers = useMemo(
        () => ({
            canUndo,
            canRedo,
            metersPerUnit: metersPerUnit(state.layout),
            reset,
            markClean,
        }),
        [canUndo, canRedo, state.layout, reset, markClean],
    );

    // Re-sync if outer props change while dialog open (e.g. after publish reload)
    useEffect(() => {
        // No-op: caller resets explicitly to avoid clobbering edits.
    }, []);

    return { state, dispatch, ...helpers };
}
