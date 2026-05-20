import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import * as Lucide from 'lucide-react';
import {
    BringToFront,
    ChevronDown,
    ExternalLink,
    Link2,
    Link2Off,
    MapPin,
    Search,
    SendToBack,
    Trash2,
} from 'lucide-react';
import { useState, type CSSProperties, type Dispatch } from 'react';
import EmergencyChecklist from './_emergency-checklist';
import {
    DOOR_SUBKIND_LABELS,
    doorHasSwing,
    formatMeters,
    isEmergencyPlanKind,
    metersPerUnit,
    normaliseDoor,
    type BuilderMode,
    type DoorSubkind,
    type DoorSwingDirection,
    type DoorSwingSide,
    type Inventory,
    type PlanLayout,
    type PlanPin,
    type SelectionRef,
    type Taxonomy,
} from './_types';
import type { EditorAction, LayerKey } from './_use-plan-editor';

type IconProps = { className?: string; style?: CSSProperties };

function resolveIcon(name: string): React.ComponentType<IconProps> {
    const candidate = (
        Lucide as unknown as Record<string, React.ComponentType<IconProps>>
    )[name];
    return candidate ?? (MapPin as unknown as React.ComponentType<IconProps>);
}

type Props = {
    layout: PlanLayout;
    pins: PlanPin[];
    selection: SelectionRef[];
    inventory: Inventory | null;
    taxonomy: Taxonomy | null;
    layers: Record<LayerKey, boolean>;
    inventoryHref: string;
    inventoryLabel: string;
    mode?: BuilderMode;
    emergencyKinds?: string[];
    validationErrors?: Record<string, string>;
    dispatch: Dispatch<EditorAction>;
};

function singleSelection(selection: SelectionRef[]): SelectionRef | null {
    return selection.length === 1 ? selection[0] : null;
}

function pinIdOf(pin: PlanPin, index: number): string {
    return pin.id != null ? String(pin.id) : `__idx-${index}`;
}

export default function PlanInspector({
    layout,
    pins,
    selection,
    inventory,
    taxonomy,
    layers,
    inventoryHref,
    inventoryLabel,
    mode = 'full',
    emergencyKinds = [],
    validationErrors = {},
    dispatch,
}: Props) {
    const mpu = metersPerUnit(layout);
    const rooms = layout.rooms ?? [];

    return (
        <div className="space-y-4 text-sm">
            {/* Selection details ------------------------------------------------- */}
            <SelectionDetails
                selection={selection}
                layout={layout}
                pins={pins}
                inventory={inventory}
                taxonomy={taxonomy}
                mode={mode}
                emergencyKinds={emergencyKinds}
                validationErrors={validationErrors}
                dispatch={dispatch}
            />

            {/* Scale --------------------------------------------------------- */}
            <section className="rounded-md border p-3">
                <div className="mb-2 flex items-center justify-between">
                    <h3 className="text-sm font-medium">Scale</h3>
                    <span className="text-xs text-muted-foreground">
                        1 m ≈ {(1 / mpu).toFixed(1)} units
                    </span>
                </div>
                <div className="grid grid-cols-2 items-center gap-2">
                    <Label htmlFor="mpu" className="text-xs">
                        Metres per canvas unit
                    </Label>
                    <Input
                        id="mpu"
                        type="number"
                        step="0.001"
                        min={0.001}
                        value={mpu}
                        onChange={(event) => {
                            const value = Number.parseFloat(event.target.value);
                            if (!Number.isFinite(value) || value <= 0) return;
                            dispatch({
                                type: 'set_canvas_meters_per_unit',
                                metersPerUnit: value,
                            });
                        }}
                        className="h-7 text-xs"
                    />
                </div>
                <p className="mt-2 text-[11px] text-muted-foreground">
                    Use the <strong>Set scale</strong> tool on the canvas for a
                    click-and-measure calibration. Walls show their length live.
                </p>
                <div className="mt-3 flex items-center justify-between rounded-md border bg-slate-50 px-2 py-2">
                    <div>
                        <Label className="text-xs">Snap to grid</Label>
                        <p className="text-[11px] text-muted-foreground">
                            Hold Alt to place without snap.
                        </p>
                    </div>
                    <Switch
                        checked={layout.grid?.snap !== false}
                        onCheckedChange={(checked) =>
                            dispatch({ type: 'set_grid_snap', snap: checked })
                        }
                    />
                </div>
            </section>

            {/* Layers ------------------------------------------------------------ */}
            {mode === 'emergency' ? (
                <EmergencyChecklist
                    pins={pins}
                    emergencyKinds={emergencyKinds}
                    taxonomy={taxonomy}
                    dispatch={dispatch}
                />
            ) : (
                <section className="rounded-md border p-3">
                    <h3 className="mb-2 text-sm font-medium">Layers</h3>
                    <div className="space-y-1.5">
                        {(taxonomy?.groups ?? []).map((group) => {
                            const layerKey = group.id as LayerKey;
                            const visible = layers[layerKey] !== false;
                            return (
                                <div
                                    key={group.id}
                                    className="flex items-center justify-between"
                                >
                                    <span className="text-xs text-slate-700">
                                        {group.label}
                                    </span>
                                    <Switch
                                        checked={visible}
                                        onCheckedChange={(checked) =>
                                            dispatch({
                                                type: 'set_layer_visibility',
                                                layer: layerKey,
                                                visible: checked,
                                            })
                                        }
                                    />
                                </div>
                            );
                        })}
                    </div>
                </section>
            )}

            {/* Rooms ------------------------------------------------------------- */}
            {mode === 'full' && (
                <section className="rounded-md border p-3">
                    <div className="mb-2 flex items-center justify-between">
                        <h3 className="text-sm font-medium">
                            Rooms ({rooms.length})
                        </h3>
                        <Button
                            asChild
                            variant="ghost"
                            size="sm"
                            className="h-6 gap-1 px-1.5 text-xs"
                        >
                            <Link href={inventoryHref}>
                                {inventoryLabel}
                                <ExternalLink className="h-3 w-3" />
                            </Link>
                        </Button>
                    </div>
                    <div className="space-y-2">
                        {rooms.length === 0 ? (
                            <p className="text-xs text-muted-foreground">
                                No rooms placed yet.
                            </p>
                        ) : (
                            rooms.map((room) => {
                                const linkedRoom = room.room_ref_id
                                    ? inventory?.rooms.find(
                                          (r) =>
                                              r.id === room.room_ref_id &&
                                              r.type === room.room_ref_type,
                                      )
                                    : null;
                                const selected = selection.some(
                                    (s) =>
                                        s.type === 'room' && s.id === room.id,
                                );
                                return (
                                    <div
                                        key={room.id}
                                        className={`rounded-md border p-2 ${selected ? 'border-blue-400 bg-blue-50' : ''}`}
                                    >
                                        <button
                                            type="button"
                                            className="block w-full text-left"
                                            onClick={() =>
                                                dispatch({
                                                    type: 'select',
                                                    ref: {
                                                        type: 'room',
                                                        id: room.id,
                                                    },
                                                })
                                            }
                                        >
                                            <div className="text-xs font-medium">
                                                {room.label ?? 'Unnamed room'}
                                            </div>
                                            {linkedRoom ? (
                                                <Badge
                                                    variant="secondary"
                                                    className="mt-1 gap-1 text-[10px]"
                                                >
                                                    <Link2 className="h-3 w-3" />
                                                    {linkedRoom.type_label}
                                                </Badge>
                                            ) : (
                                                <span className="mt-1 inline-block text-[10px] text-muted-foreground">
                                                    Free-form label
                                                </span>
                                            )}
                                        </button>
                                        <div className="mt-2 flex flex-wrap gap-1">
                                            <RoomLinkPopover
                                                roomId={room.id}
                                                currentRefId={
                                                    room.room_ref_id ?? null
                                                }
                                                currentRefType={
                                                    room.room_ref_type ?? null
                                                }
                                                inventory={inventory}
                                                dispatch={dispatch}
                                            />
                                            {!linkedRoom && (
                                                <Input
                                                    value={room.label ?? ''}
                                                    onChange={(event) =>
                                                        dispatch({
                                                            type: 'update_room',
                                                            id: room.id,
                                                            patch: {
                                                                label: event
                                                                    .target
                                                                    .value,
                                                            },
                                                        })
                                                    }
                                                    placeholder="Free-form label"
                                                    className="h-6 flex-1 text-xs"
                                                />
                                            )}
                                        </div>
                                    </div>
                                );
                            })
                        )}
                    </div>
                </section>
            )}

            {/* Pins -------------------------------------------------------------- */}
            <section className="rounded-md border p-3">
                <h3 className="mb-2 text-sm font-medium">
                    Pins ({pins.length})
                </h3>
                <div className="space-y-2">
                    {pins.length === 0 ? (
                        <p className="text-xs text-muted-foreground">
                            No pins placed yet.
                        </p>
                    ) : (
                        pins
                            .map((pin, index) => ({ pin, index }))
                            .filter(
                                ({ pin }) =>
                                    mode === 'full' ||
                                    isEmergencyPlanKind(
                                        pin.kind,
                                        emergencyKinds,
                                    ),
                            )
                            .map(({ pin, index }) => {
                                const id = pinIdOf(pin, index);
                                const selected = selection.some(
                                    (s) =>
                                        s.type === 'pin' && String(s.id) === id,
                                );
                                const kindMeta = taxonomy?.kinds?.[pin.kind];
                                const linkedDevice = pin.device_id
                                    ? inventory?.devices.find(
                                          (d) => d.id === pin.device_id,
                                      )
                                    : null;
                                const displayLabel =
                                    pin.label ||
                                    linkedDevice?.name ||
                                    kindMeta?.label ||
                                    pin.kind.replaceAll('_', ' ');
                                return (
                                    <div
                                        key={id}
                                        className={`rounded-md border p-2 ${selected ? 'border-blue-400 bg-blue-50' : ''}`}
                                    >
                                        <div className="flex items-start justify-between gap-2">
                                            <button
                                                type="button"
                                                className="block flex-1 text-left"
                                                onClick={() =>
                                                    dispatch({
                                                        type: 'select',
                                                        ref: {
                                                            type: 'pin',
                                                            id,
                                                        },
                                                    })
                                                }
                                            >
                                                <div className="text-xs font-medium">
                                                    {displayLabel}
                                                </div>
                                                <div className="mt-0.5 flex flex-wrap gap-1">
                                                    <Badge
                                                        variant="outline"
                                                        className="text-[10px]"
                                                    >
                                                        {kindMeta?.label ??
                                                            pin.kind.replaceAll(
                                                                '_',
                                                                ' ',
                                                            )}
                                                    </Badge>
                                                    {pin.subkind && (
                                                        <Badge
                                                            variant="secondary"
                                                            className="text-[10px]"
                                                        >
                                                            {pin.subkind.replaceAll(
                                                                '_',
                                                                ' ',
                                                            )}
                                                        </Badge>
                                                    )}
                                                    {linkedDevice && (
                                                        <Badge
                                                            variant="secondary"
                                                            className="gap-1 text-[10px]"
                                                        >
                                                            <Link2 className="h-3 w-3" />
                                                            {linkedDevice.uid}
                                                        </Badge>
                                                    )}
                                                </div>
                                            </button>
                                            <Button
                                                type="button"
                                                size="icon"
                                                variant="ghost"
                                                className="h-6 w-6"
                                                onClick={() => {
                                                    dispatch({
                                                        type: 'commit',
                                                    });
                                                    dispatch({
                                                        type: 'select',
                                                        ref: {
                                                            type: 'pin',
                                                            id,
                                                        },
                                                    });
                                                    dispatch({
                                                        type: 'delete_selected',
                                                    });
                                                }}
                                            >
                                                <Trash2 className="h-3.5 w-3.5" />
                                                <span className="sr-only">
                                                    Remove pin
                                                </span>
                                            </Button>
                                        </div>
                                        {pin.kind === 'device' && (
                                            <div className="mt-2">
                                                <DeviceLinkPopover
                                                    pinId={id}
                                                    currentDeviceId={
                                                        pin.device_id ?? null
                                                    }
                                                    inventory={inventory}
                                                    dispatch={dispatch}
                                                />
                                            </div>
                                        )}
                                    </div>
                                );
                            })
                    )}
                </div>
            </section>
        </div>
    );
}

function SelectionDetails({
    selection,
    layout,
    pins,
    inventory,
    taxonomy,
    mode,
    emergencyKinds,
    validationErrors,
    dispatch,
}: {
    selection: SelectionRef[];
    layout: PlanLayout;
    pins: PlanPin[];
    inventory: Inventory | null;
    taxonomy: Taxonomy | null;
    mode: BuilderMode;
    emergencyKinds: string[];
    validationErrors: Record<string, string>;
    dispatch: Dispatch<EditorAction>;
}) {
    if (selection.length === 0) {
        return (
            <div className="rounded-md border border-dashed bg-slate-50 p-3 text-xs text-muted-foreground">
                Select an item on the canvas to edit it. Drag on empty canvas to
                select multiple items.
            </div>
        );
    }

    if (selection.length > 1) {
        return (
            <div className="rounded-md border bg-blue-50 p-3 text-xs">
                <div className="mb-2 font-medium text-blue-900">
                    {selection.length} items selected
                </div>
                <p className="mb-2 text-blue-900/80">
                    Drag any selected item on the canvas to move the whole
                    group.
                </p>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-7 w-full gap-1 text-xs"
                    onClick={() => {
                        dispatch({ type: 'commit' });
                        dispatch({ type: 'delete_selected' });
                    }}
                >
                    <Trash2 className="h-3.5 w-3.5" />
                    Delete {selection.length} items
                </Button>
            </div>
        );
    }

    const single = selection[0];

    const mpu = metersPerUnit(layout);

    if (single.type === 'room') {
        if (mode !== 'full') return null;
        const room = layout.rooms?.find((r) => r.id === single.id);
        if (!room) return null;
        const canvasWidth = layout.canvas?.width ?? 1000;
        const canvasHeight = layout.canvas?.height ?? 700;
        return (
            <div className="rounded-md border p-3">
                <h3 className="mb-2 text-sm font-medium">
                    Room — {room.label ?? 'Unnamed'}
                </h3>
                <dl className="space-y-1 text-xs text-slate-700">
                    <div className="flex justify-between">
                        <dt>Width</dt>
                        <dd>{formatMeters(room.width * canvasWidth, mpu)}</dd>
                    </div>
                    <div className="flex justify-between">
                        <dt>Height</dt>
                        <dd>{formatMeters(room.height * canvasHeight, mpu)}</dd>
                    </div>
                    <div className="flex justify-between">
                        <dt>Area</dt>
                        <dd>
                            {(
                                room.width *
                                canvasWidth *
                                mpu *
                                room.height *
                                canvasHeight *
                                mpu
                            ).toFixed(1)}{' '}
                            m²
                        </dd>
                    </div>
                </dl>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="mt-2 h-7 w-full gap-1 text-xs"
                    onClick={() => {
                        dispatch({ type: 'commit' });
                        dispatch({ type: 'delete_selected' });
                    }}
                >
                    <Trash2 className="h-3.5 w-3.5" />
                    Delete room
                </Button>
            </div>
        );
    }

    if (single.type === 'wall') {
        if (mode !== 'full') return null;
        const wall = layout.walls?.find((w) => w.id === single.id);
        if (!wall || wall.points.length < 2) return null;
        const canvasWidth = layout.canvas?.width ?? 1000;
        const canvasHeight = layout.canvas?.height ?? 700;
        const a = wall.points[0];
        const b = wall.points[wall.points.length - 1];
        const dx = (b.x - a.x) * canvasWidth;
        const dy = (b.y - a.y) * canvasHeight;
        const length = Math.hypot(dx, dy);
        return (
            <div className="rounded-md border p-3">
                <h3 className="mb-2 text-sm font-medium">Wall</h3>
                <dl className="space-y-1 text-xs text-slate-700">
                    <div className="flex justify-between">
                        <dt>Length</dt>
                        <dd>{formatMeters(length, mpu)}</dd>
                    </div>
                    <div className="flex justify-between">
                        <dt>Angle</dt>
                        <dd>
                            {((Math.atan2(dy, dx) * 180) / Math.PI).toFixed(1)}°
                        </dd>
                    </div>
                </dl>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="mt-2 h-7 w-full gap-1 text-xs"
                    onClick={() => {
                        dispatch({ type: 'commit' });
                        dispatch({ type: 'delete_selected' });
                    }}
                >
                    <Trash2 className="h-3.5 w-3.5" />
                    Delete wall
                </Button>
            </div>
        );
    }

    if (single.type === 'pin') {
        const index = pins.findIndex(
            (pin, i) => pinIdOf(pin, i) === String(single.id),
        );
        if (index === -1) return null;
        const pin = pins[index];
        if (
            mode === 'emergency' &&
            !isEmergencyPlanKind(pin.kind, emergencyKinds)
        )
            return null;
        const kindMeta = taxonomy?.kinds?.[pin.kind];
        const linkedDevice = pin.device_id
            ? inventory?.devices.find((d) => d.id === pin.device_id)
            : null;
        const validationError = validationErrors[`pin:${single.id}`];
        return (
            <div className="space-y-2 rounded-md border p-3">
                <h3 className="text-sm font-medium">
                    {kindMeta?.label ?? pin.kind.replaceAll('_', ' ')}
                </h3>
                {validationError && (
                    <div className="rounded-md border border-red-200 bg-red-50 px-2 py-1 text-xs text-red-700">
                        {validationError}
                    </div>
                )}
                <div className="space-y-2">
                    <div className="grid grid-cols-3 items-center gap-2">
                        <Label className="text-xs">Kind</Label>
                        <PinKindPicker
                            pin={pin}
                            pinId={single.id}
                            taxonomy={taxonomy}
                            mode={mode}
                            emergencyKinds={emergencyKinds}
                            dispatch={dispatch}
                        />
                    </div>
                    <div className="grid grid-cols-3 items-center gap-2">
                        <Label className="text-xs">Label</Label>
                        <Input
                            value={pin.label ?? ''}
                            onChange={(event) =>
                                dispatch({
                                    type: 'update_pin',
                                    pinId: single.id,
                                    patch: { label: event.target.value },
                                })
                            }
                            placeholder={kindMeta?.label ?? ''}
                            className="col-span-2 h-7 text-xs"
                        />
                    </div>
                    {kindMeta?.subkinds && kindMeta.subkinds.length > 0 && (
                        <div className="grid grid-cols-3 items-center gap-2">
                            <Label className="text-xs">Type</Label>
                            <select
                                value={pin.subkind ?? ''}
                                onChange={(event) =>
                                    dispatch({
                                        type: 'update_pin',
                                        pinId: single.id,
                                        patch: {
                                            subkind: event.target.value || null,
                                        },
                                    })
                                }
                                className="col-span-2 h-7 rounded border bg-white px-1 text-xs"
                            >
                                <option value="">Generic</option>
                                {kindMeta.subkinds.map((sub) => (
                                    <option key={sub.value} value={sub.value}>
                                        {sub.label}
                                    </option>
                                ))}
                            </select>
                            {pin.subkind && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="col-span-2 col-start-2 h-6 justify-start px-1 text-xs"
                                    onClick={() =>
                                        dispatch({
                                            type: 'update_pin',
                                            pinId: single.id,
                                            patch: { subkind: null },
                                        })
                                    }
                                >
                                    Clear type
                                </Button>
                            )}
                        </div>
                    )}
                    {pin.kind === 'device' && (
                        <div className="grid grid-cols-3 items-center gap-2">
                            <Label className="text-xs">Device</Label>
                            <div className="col-span-2">
                                <DeviceLinkPopover
                                    pinId={single.id}
                                    currentDeviceId={pin.device_id ?? null}
                                    inventory={inventory}
                                    dispatch={dispatch}
                                />
                                {linkedDevice && (
                                    <div className="mt-1 text-[10px] text-muted-foreground">
                                        {linkedDevice.manufacturer
                                            ? `${linkedDevice.manufacturer} `
                                            : ''}
                                        {linkedDevice.model}
                                    </div>
                                )}
                            </div>
                        </div>
                    )}
                    {pin.kind !== 'evacuation_route' && (
                        <RotationField
                            rotation={pin.rotation_deg ?? 0}
                            onChange={(next) => {
                                dispatch({ type: 'commit' });
                                dispatch({
                                    type: 'update_pin',
                                    pinId: single.id,
                                    patch: { rotation_deg: next },
                                });
                            }}
                        />
                    )}
                </div>
                {single.type === 'pin' && (
                    <div className="grid grid-cols-2 gap-1">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="h-7 gap-1 text-xs"
                            onClick={() =>
                                dispatch({
                                    type: 'bring_to_front',
                                    ref: single,
                                })
                            }
                        >
                            <BringToFront className="h-3.5 w-3.5" />
                            Front
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="h-7 gap-1 text-xs"
                            onClick={() =>
                                dispatch({ type: 'send_to_back', ref: single })
                            }
                        >
                            <SendToBack className="h-3.5 w-3.5" />
                            Back
                        </Button>
                    </div>
                )}
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-7 w-full gap-1 text-xs"
                    onClick={() => {
                        dispatch({ type: 'commit' });
                        dispatch({ type: 'delete_selected' });
                    }}
                >
                    <Trash2 className="h-3.5 w-3.5" />
                    Delete pin
                </Button>
            </div>
        );
    }

    if (single.type === 'door') {
        const door = layout.doors?.find((entry) => entry.id === single.id);
        if (!door) return null;
        const normalised = normaliseDoor(door);
        const canvasWidth = layout.canvas?.width ?? 1000;
        const widthMetres = normalised.width * canvasWidth * mpu;
        const showSwingControls = doorHasSwing(normalised.subkind);
        return (
            <div className="space-y-2 rounded-md border p-3">
                <div className="flex items-center justify-between gap-2">
                    <h3 className="text-sm font-medium">Door</h3>
                    <Badge
                        variant={normalised.wall_id ? 'secondary' : 'outline'}
                        className="text-[10px]"
                    >
                        {normalised.wall_id ? 'Wall opening' : 'Needs wall'}
                    </Badge>
                </div>
                {!normalised.wall_id && (
                    <div className="rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs text-amber-800">
                        Drag this door close to a wall to create a clean
                        opening.
                    </div>
                )}
                <div className="grid grid-cols-3 items-center gap-2">
                    <Label className="text-xs">Style</Label>
                    <select
                        value={normalised.subkind}
                        onChange={(event) => {
                            dispatch({ type: 'commit' });
                            dispatch({
                                type: 'update_door',
                                id: String(single.id),
                                patch: {
                                    subkind: event.target.value as DoorSubkind,
                                },
                            });
                        }}
                        className="col-span-2 h-7 rounded border bg-white px-1 text-xs"
                        data-test="site-plan-door-subkind"
                    >
                        {(
                            Object.entries(DOOR_SUBKIND_LABELS) as [
                                DoorSubkind,
                                string,
                            ][]
                        ).map(([value, label]) => (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        ))}
                    </select>
                </div>
                {showSwingControls && (
                    <>
                        <div className="grid grid-cols-3 items-center gap-2">
                            <Label className="text-xs">Hinge</Label>
                            <div className="col-span-2 flex gap-1">
                                {(['left', 'right'] as DoorSwingSide[]).map(
                                    (side) => (
                                        <Button
                                            key={side}
                                            type="button"
                                            variant={
                                                normalised.swing_side === side
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            size="sm"
                                            className="h-7 flex-1 text-xs capitalize"
                                            onClick={() => {
                                                dispatch({ type: 'commit' });
                                                dispatch({
                                                    type: 'update_door',
                                                    id: String(single.id),
                                                    patch: {
                                                        swing_side: side,
                                                        swing: side,
                                                    },
                                                });
                                            }}
                                        >
                                            {side}
                                        </Button>
                                    ),
                                )}
                            </div>
                        </div>
                        <div className="grid grid-cols-3 items-center gap-2">
                            <Label className="text-xs">Opens</Label>
                            <div className="col-span-2 flex gap-1">
                                {[
                                    {
                                        value: 'in' as DoorSwingDirection,
                                        label: 'Inward',
                                    },
                                    {
                                        value: 'out' as DoorSwingDirection,
                                        label: 'Outward',
                                    },
                                ].map((option) => (
                                    <Button
                                        key={option.value}
                                        type="button"
                                        variant={
                                            normalised.swing_direction ===
                                            option.value
                                                ? 'default'
                                                : 'outline'
                                        }
                                        size="sm"
                                        className="h-7 flex-1 text-xs"
                                        onClick={() => {
                                            dispatch({ type: 'commit' });
                                            dispatch({
                                                type: 'update_door',
                                                id: String(single.id),
                                                patch: {
                                                    swing_direction:
                                                        option.value,
                                                },
                                            });
                                        }}
                                    >
                                        {option.label}
                                    </Button>
                                ))}
                            </div>
                        </div>
                    </>
                )}
                <div className="grid grid-cols-3 items-center gap-2">
                    <Label className="text-xs">Width</Label>
                    <Input
                        type="number"
                        step="0.05"
                        min="0.5"
                        max="10"
                        value={Math.round(widthMetres * 100) / 100}
                        onChange={(event) => {
                            const m = Number.parseFloat(event.target.value);
                            if (!Number.isFinite(m) || m <= 0) return;
                            const nextWidth = m / (mpu * canvasWidth);
                            dispatch({ type: 'commit' });
                            dispatch({
                                type: 'update_door',
                                id: String(single.id),
                                patch: { width: nextWidth },
                            });
                        }}
                        className="col-span-2 h-7 text-xs"
                    />
                    <span className="col-span-3 -mt-1 text-[10px] text-muted-foreground">
                        Metres
                    </span>
                </div>
                <RotationField
                    rotation={normalised.rotation_deg ?? 0}
                    onChange={(next) => {
                        dispatch({ type: 'commit' });
                        dispatch({
                            type: 'update_door',
                            id: String(single.id),
                            patch: { rotation_deg: next },
                        });
                    }}
                />
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-7 w-full gap-1 text-xs"
                    onClick={() => {
                        dispatch({ type: 'commit' });
                        dispatch({ type: 'delete_selected' });
                    }}
                >
                    <Trash2 className="h-3.5 w-3.5" />
                    Delete door
                </Button>
            </div>
        );
    }

    if (single.type === 'window') {
        const item = layout.windows?.find((entry) => entry.id === single.id);
        if (!item) return null;
        const rotation = item.rotation_deg ?? 0;
        const canvasWidth = layout.canvas?.width ?? 1000;
        const widthMetres = (item.width ?? 0.1) * canvasWidth * mpu;
        return (
            <div className="space-y-2 rounded-md border p-3">
                <div className="flex items-center justify-between gap-2">
                    <h3 className="text-sm font-medium">Window</h3>
                    <Badge
                        variant={item.wall_id ? 'secondary' : 'outline'}
                        className="text-[10px]"
                    >
                        {item.wall_id ? 'Wall opening' : 'Needs wall'}
                    </Badge>
                </div>
                {!item.wall_id && (
                    <div className="rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-xs text-amber-800">
                        Drag this window close to a wall to align it with the
                        plan.
                    </div>
                )}
                <div className="grid grid-cols-3 items-center gap-2">
                    <Label className="text-xs">Width</Label>
                    <Input
                        type="number"
                        step="0.05"
                        min="0.5"
                        max="10"
                        value={Math.round(widthMetres * 100) / 100}
                        onChange={(event) => {
                            const m = Number.parseFloat(event.target.value);
                            if (!Number.isFinite(m) || m <= 0) return;
                            dispatch({ type: 'commit' });
                            dispatch({
                                type: 'update_window',
                                id: String(single.id),
                                patch: { width: m / (mpu * canvasWidth) },
                            });
                        }}
                        className="col-span-2 h-7 text-xs"
                    />
                    <span className="col-span-3 -mt-1 text-[10px] text-muted-foreground">
                        Metres
                    </span>
                </div>
                <RotationField
                    rotation={rotation}
                    onChange={(next) => {
                        dispatch({ type: 'commit' });
                        dispatch({
                            type: 'update_window',
                            id: String(single.id),
                            patch: { rotation_deg: next },
                        });
                    }}
                />
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-7 w-full gap-1 text-xs"
                    onClick={() => {
                        dispatch({ type: 'commit' });
                        dispatch({ type: 'delete_selected' });
                    }}
                >
                    <Trash2 className="h-3.5 w-3.5" />
                    Delete window
                </Button>
            </div>
        );
    }

    if (single.type === 'label') {
        const item = layout.labels?.find((entry) => entry.id === single.id);
        if (!item) return null;
        const rotation = (item as { rotation_deg?: number }).rotation_deg ?? 0;
        return (
            <div className="space-y-2 rounded-md border p-3">
                <h3 className="text-sm font-medium">Label</h3>
                <RotationField
                    rotation={rotation}
                    onChange={(next) => {
                        dispatch({ type: 'commit' });
                        dispatch({
                            type: 'update_label',
                            id: String(single.id),
                            patch: { rotation_deg: next },
                        });
                    }}
                />
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-7 w-full gap-1 text-xs"
                    onClick={() => {
                        dispatch({ type: 'commit' });
                        dispatch({ type: 'delete_selected' });
                    }}
                >
                    <Trash2 className="h-3.5 w-3.5" />
                    Delete label
                </Button>
            </div>
        );
    }

    return null;
}

function RotationField({
    rotation,
    onChange,
}: {
    rotation: number;
    onChange: (next: number) => void;
}) {
    const presets = [0, 45, 90, 135, 180, 225, 270, 315];
    return (
        <div className="space-y-1.5">
            <Label className="text-xs">Rotation</Label>
            <div className="flex items-center gap-2">
                <Input
                    type="number"
                    value={rotation}
                    step={1}
                    min={-180}
                    max={360}
                    className="h-7 text-xs"
                    onChange={(event) => {
                        const value = Number.parseFloat(event.target.value);
                        if (!Number.isFinite(value)) return;
                        onChange(Math.round(value));
                    }}
                />
                <span className="text-[10px] text-muted-foreground">°</span>
            </div>
            <div className="flex flex-wrap gap-1">
                {presets.map((angle) => (
                    <Button
                        key={angle}
                        type="button"
                        variant={rotation === angle ? 'default' : 'outline'}
                        size="sm"
                        className="h-6 px-1.5 text-[10px]"
                        onClick={() => onChange(angle)}
                    >
                        {angle}°
                    </Button>
                ))}
            </div>
            <p className="text-[10px] text-muted-foreground">
                Drag the blue handle on the canvas to rotate freely · Shift
                snaps to 15°.
            </p>
        </div>
    );
}

function PinKindPicker({
    pin,
    pinId,
    taxonomy,
    mode,
    emergencyKinds,
    dispatch,
}: {
    pin: PlanPin;
    pinId: string | number;
    taxonomy: Taxonomy | null;
    mode: BuilderMode;
    emergencyKinds: string[];
    dispatch: Dispatch<EditorAction>;
}) {
    const [open, setOpen] = useState(false);
    const currentKindMeta = taxonomy?.kinds?.[pin.kind] ?? null;
    const currentLabel =
        currentKindMeta?.label ?? pin.kind.replaceAll('_', ' ');
    const CurrentIcon = resolveIcon(currentKindMeta?.icon ?? 'MapPin');

    const groups =
        taxonomy?.groups
            .map((group) => ({
                ...group,
                kinds: group.kinds.filter(
                    (kind) =>
                        !kind.startsWith('__') &&
                        (mode === 'full' ||
                            isEmergencyPlanKind(kind, emergencyKinds)),
                ),
            }))
            .filter((group) => group.kinds.length > 0) ?? [];

    function changeKind(nextKind: string) {
        if (!nextKind || nextKind === pin.kind) {
            setOpen(false);
            return;
        }
        const currentFallbacks = [currentLabel, pin.kind.replaceAll('_', ' ')];
        const shouldResetLabel =
            !pin.label || currentFallbacks.includes(pin.label);

        dispatch({ type: 'commit' });
        dispatch({
            type: 'update_pin',
            pinId,
            patch: {
                kind: nextKind,
                subkind: null,
                device_id:
                    nextKind === 'device' ? (pin.device_id ?? null) : null,
                label: shouldResetLabel ? null : pin.label,
                path_points:
                    nextKind === 'evacuation_route'
                        ? (pin.path_points ?? null)
                        : null,
            },
        });
        setOpen(false);
    }

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="col-span-2 h-7 justify-start gap-1.5 px-2 text-xs"
                    data-test="site-plan-pin-kind-picker"
                >
                    <CurrentIcon
                        className="h-3.5 w-3.5"
                        style={{ color: currentKindMeta?.color ?? '#475569' }}
                    />
                    <span className="flex-1 truncate text-left">
                        {currentLabel}
                    </span>
                    <ChevronDown className="h-3 w-3 opacity-60" />
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-80 p-2" align="start">
                <div className="max-h-[360px] space-y-2 overflow-y-auto">
                    {groups.map((group) => (
                        <div key={group.id}>
                            <div className="mb-1 px-1 text-[10px] font-semibold tracking-wider text-slate-500 uppercase">
                                {group.label}
                            </div>
                            <div className="grid grid-cols-3 gap-1">
                                {group.kinds.map((kindKey) => {
                                    const kind = taxonomy!.kinds[kindKey];
                                    if (!kind) return null;
                                    const Icon = resolveIcon(kind.icon);
                                    const active = pin.kind === kindKey;
                                    return (
                                        <button
                                            key={kindKey}
                                            type="button"
                                            onClick={() => changeKind(kindKey)}
                                            data-test={`site-plan-pin-kind-option-${kindKey}`}
                                            title={kind.label}
                                            className={cn(
                                                'flex flex-col items-center gap-1 rounded border bg-white p-1.5 text-[10px] text-slate-700 transition hover:bg-slate-50',
                                                active &&
                                                    'border-blue-500 bg-blue-50 text-blue-900',
                                            )}
                                        >
                                            <Icon
                                                className="h-4 w-4 shrink-0"
                                                style={{ color: kind.color }}
                                            />
                                            <span className="line-clamp-2 text-center leading-tight">
                                                {kind.label}
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    ))}
                </div>
            </PopoverContent>
        </Popover>
    );
}

function RoomLinkPopover({
    roomId,
    currentRefId,
    currentRefType,
    inventory,
    dispatch,
}: {
    roomId: string;
    currentRefId: number | null;
    currentRefType: string | null;
    inventory: Inventory | null;
    dispatch: Dispatch<EditorAction>;
}) {
    const grouped = (inventory?.rooms ?? []).reduce<
        Record<string, Inventory['rooms']>
    >((acc, room) => {
        const key = room.type_label;
        acc[key] = acc[key] ?? [];
        acc[key].push(room);
        return acc;
    }, {});

    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-6 gap-1 px-2 text-[11px]"
                >
                    <Link2 className="h-3 w-3" />
                    {currentRefId ? 'Change link' : 'Link to room'}
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-72 p-0" align="start">
                <Command>
                    <CommandInput placeholder="Search rooms..." />
                    <CommandList>
                        <CommandEmpty>No matching rooms.</CommandEmpty>
                        {currentRefId !== null && (
                            <CommandGroup heading="Linked">
                                <CommandItem
                                    onSelect={() => {
                                        dispatch({ type: 'commit' });
                                        dispatch({
                                            type: 'link_room',
                                            roomId,
                                            ref: null,
                                        });
                                    }}
                                >
                                    <Link2Off className="h-4 w-4" />
                                    Unlink
                                </CommandItem>
                            </CommandGroup>
                        )}
                        {Object.entries(grouped).map(([typeLabel, items]) => (
                            <CommandGroup key={typeLabel} heading={typeLabel}>
                                {items.map((room) => (
                                    <CommandItem
                                        key={`${room.type}-${room.id}`}
                                        value={`${typeLabel} ${room.name}`}
                                        onSelect={() => {
                                            dispatch({ type: 'commit' });
                                            dispatch({
                                                type: 'link_room',
                                                roomId,
                                                ref: {
                                                    type: room.type,
                                                    id: room.id,
                                                    name: room.name,
                                                },
                                            });
                                        }}
                                    >
                                        <Search className="h-4 w-4 opacity-50" />
                                        <span>{room.name}</span>
                                        {!room.is_active && (
                                            <Badge
                                                variant="outline"
                                                className="ml-auto text-[10px]"
                                            >
                                                Inactive
                                            </Badge>
                                        )}
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        ))}
                        {Object.keys(grouped).length === 0 && (
                            <div className="px-3 py-4 text-center text-xs text-muted-foreground">
                                No rooms found. Add rooms in the inventory.
                            </div>
                        )}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}

function DeviceLinkPopover({
    pinId,
    currentDeviceId,
    inventory,
    dispatch,
}: {
    pinId: string | number;
    currentDeviceId: number | null;
    inventory: Inventory | null;
    dispatch: Dispatch<EditorAction>;
}) {
    const devices = inventory?.devices ?? [];
    const current = currentDeviceId
        ? devices.find((d) => d.id === currentDeviceId)
        : null;

    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-7 w-full justify-start gap-1 text-xs"
                >
                    <Link2 className="h-3 w-3" />
                    {current ? current.name : 'Pick a device'}
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-72 p-0" align="start">
                <Command>
                    <CommandInput placeholder="Search devices..." />
                    <CommandList>
                        <CommandEmpty>No devices found.</CommandEmpty>
                        {currentDeviceId !== null && (
                            <CommandGroup heading="Linked">
                                <CommandItem
                                    onSelect={() => {
                                        dispatch({ type: 'commit' });
                                        dispatch({
                                            type: 'link_device',
                                            pinId,
                                            device: null,
                                        });
                                    }}
                                >
                                    <Link2Off className="h-4 w-4" />
                                    Unlink
                                </CommandItem>
                            </CommandGroup>
                        )}
                        {devices.length === 0 ? (
                            <div className="px-3 py-4 text-center text-xs text-muted-foreground">
                                No devices assigned to this site. Assign devices
                                in the asset registry.
                            </div>
                        ) : (
                            <CommandGroup heading="Site devices">
                                {devices.map((device) => (
                                    <CommandItem
                                        key={device.id}
                                        value={`${device.name} ${device.uid} ${device.category ?? ''}`}
                                        onSelect={() => {
                                            dispatch({ type: 'commit' });
                                            dispatch({
                                                type: 'link_device',
                                                pinId,
                                                device: {
                                                    id: device.id,
                                                    name: device.name,
                                                },
                                            });
                                        }}
                                    >
                                        <div className="flex flex-col">
                                            <span className="text-xs font-medium">
                                                {device.name}
                                            </span>
                                            <span className="text-[10px] text-muted-foreground">
                                                {device.uid}
                                                {device.category
                                                    ? ` · ${device.category}`
                                                    : ''}
                                            </span>
                                        </div>
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        )}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
