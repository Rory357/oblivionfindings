import { Card as GuardrailCard } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { MapPin } from 'lucide-react';
import type React from 'react';
import { PLAN_ICONS } from './_icons';
import { DoorSymbol } from './_door';
import {
    resolveAttachedOpening,
    wallSegmentsWithOpenings,
    type AttachedOpening,
} from './_geometry';
import {
    formatMeters,
    metersPerUnit,
    normaliseDoor,
    normaliseLayout,
    type PlanLayout,
    type PlanPin,
    type Taxonomy,
} from './_types';

// Re-export for backwards compatibility with importers that pulled types from
// this module before the dedicated `_types.ts` existed.
export {
    normaliseLayout,
    type PlanLayout,
    type PlanPin,
    type PlanRoom,
} from './_types';

type Props = {
    layout?: PlanLayout | null;
    pins?: PlanPin[];
    className?: string;
    onCanvasClick?: (point: { x: number; y: number }) => void;
    selectedPinId?: number | string | null;
    taxonomy?: Taxonomy | null;
    showScale?: boolean;
};

const FALLBACK_PIN_STYLES: Record<string, { color: string; icon: string }> = {
    device: { color: '#2563eb', icon: 'Video' },
    medication_storage: { color: '#7c3aed', icon: 'Pill' },
    emergency_exit: { color: '#dc2626', icon: 'DoorOpen' },
    evacuation_route: { color: '#d97706', icon: 'Route' },
    assembly_point: { color: '#059669', icon: 'MapPin' },
    you_are_here: { color: '#0f172a', icon: 'Crosshair' },
    fire_extinguisher: { color: '#ea580c', icon: 'FlameKindling' },
    fire_blanket: { color: '#dc2626', icon: 'Shield' },
    smoke_alarm: { color: '#64748b', icon: 'BellRing' },
    custom_marker: { color: '#475569', icon: 'Pin' },
};

function resolveIcon(
    name: string,
): React.ComponentType<{ className?: string }> {
    return PLAN_ICONS[name] ?? MapPin;
}

function pinStyle(
    kind: string,
    taxonomy?: Taxonomy | null,
): { color: string; icon: string } {
    if (taxonomy?.kinds?.[kind]) {
        return {
            color: taxonomy.kinds[kind].color,
            icon: taxonomy.kinds[kind].icon,
        };
    }
    return FALLBACK_PIN_STYLES[kind] ?? { color: '#475569', icon: 'MapPin' };
}

export function PlanThumbnail({
    layout,
    pins = [],
    className,
    onCanvasClick,
    selectedPinId,
    taxonomy,
    showScale,
}: Props) {
    const resolved = normaliseLayout(layout);
    const width = resolved.canvas?.width ?? 1000;
    const height = resolved.canvas?.height ?? 700;
    const mpu = metersPerUnit(resolved);
    const gridSize = resolved.grid?.size ?? 10;
    const canvas = { width, height };
    const openings: AttachedOpening[] = [
        ...resolved.doors.map((door) => ({
            ...door,
            width: normaliseDoor(door).width,
        })),
        ...resolved.windows.map((win) => ({ ...win, width: win.width ?? 0.1 })),
    ];

    function handleClick(event: React.MouseEvent<SVGSVGElement>) {
        if (!onCanvasClick) return;
        const rect = event.currentTarget.getBoundingClientRect();
        onCanvasClick({
            x: Math.min(
                1,
                Math.max(0, (event.clientX - rect.left) / rect.width),
            ),
            y: Math.min(
                1,
                Math.max(0, (event.clientY - rect.top) / rect.height),
            ),
        });
    }

    return (
        <div
            className={cn(
                'relative overflow-hidden rounded-md border bg-white',
                className,
            )}
        >
            <svg
                viewBox={`0 0 ${width} ${height}`}
                className="h-full min-h-[260px] w-full"
                onClick={handleClick}
            >
                <rect width={width} height={height} fill="#ffffff" />
                {resolved.grid?.enabled &&
                    Array.from({
                        length: Math.floor(width / gridSize) + 1,
                    }).map((_, index) => (
                        <line
                            key={`gx-${index}`}
                            x1={index * gridSize}
                            y1={0}
                            x2={index * gridSize}
                            y2={height}
                            stroke="#e2e8f0"
                            strokeWidth={1}
                        />
                    ))}
                {resolved.grid?.enabled &&
                    Array.from({
                        length: Math.floor(height / gridSize) + 1,
                    }).map((_, index) => (
                        <line
                            key={`gy-${index}`}
                            x1={0}
                            y1={index * gridSize}
                            x2={width}
                            y2={index * gridSize}
                            stroke="#e2e8f0"
                            strokeWidth={1}
                        />
                    ))}
                {resolved.rooms.map((room) => (
                    <g key={room.id}>
                        <rect
                            x={room.x * width}
                            y={room.y * height}
                            width={room.width * width}
                            height={room.height * height}
                            fill="#f8fafc"
                            stroke="#334155"
                            strokeWidth={3}
                        />
                        <text
                            x={room.x * width + 12}
                            y={room.y * height + 28}
                            fontSize={18}
                            fill="#0f172a"
                        >
                            {room.label ?? 'Room'}
                        </text>
                    </g>
                ))}
                {resolved.walls.flatMap((wall) =>
                    wallSegmentsWithOpenings(wall, openings, canvas).map(
                        (segment) => (
                            <line
                                key={segment.id}
                                x1={segment.a.x * width}
                                y1={segment.a.y * height}
                                x2={segment.b.x * width}
                                y2={segment.b.y * height}
                                stroke="#111827"
                                strokeWidth={wall.thickness ?? 4}
                                strokeLinecap="round"
                            />
                        ),
                    ),
                )}
                {resolved.doors.map((door) => {
                    const normalised = normaliseDoor(door);
                    const resolvedDoor = resolveAttachedOpening(
                        normalised,
                        resolved.walls,
                        canvas,
                    );
                    const renderDoor = { ...normalised, ...resolvedDoor };
                    const rotation = resolvedDoor.rotation_deg ?? 0;
                    const cx =
                        (resolvedDoor.x + resolvedDoor.width / 2) * width;
                    const cy = resolvedDoor.y * height;
                    return (
                        <g
                            key={door.id}
                            transform={
                                rotation
                                    ? `rotate(${rotation} ${cx} ${cy})`
                                    : undefined
                            }
                        >
                            <DoorSymbol
                                door={renderDoor}
                                canvasWidth={width}
                                canvasHeight={height}
                            />
                        </g>
                    );
                })}
                {resolved.windows.map((win) => {
                    const resolvedWindow = resolveAttachedOpening(
                        win,
                        resolved.walls,
                        canvas,
                    );
                    const x = resolvedWindow.x * width;
                    const y = resolvedWindow.y * height;
                    const w = resolvedWindow.width * width;
                    const cx = x + w / 2;
                    const rotation = resolvedWindow.rotation_deg ?? 0;
                    return (
                        <g
                            key={win.id}
                            transform={
                                rotation
                                    ? `rotate(${rotation} ${cx} ${y})`
                                    : undefined
                            }
                        >
                            <rect
                                x={x - 2}
                                y={y - 7}
                                width={w + 4}
                                height={14}
                                fill="#ffffff"
                            />
                            <rect
                                x={x}
                                y={y - 5}
                                width={w}
                                height={10}
                                rx={2}
                                fill="#e0f2fe"
                                stroke="#0284c7"
                                strokeWidth={2}
                            />
                            <line
                                x1={x + 5}
                                y1={y - 2.5}
                                x2={x + w - 5}
                                y2={y - 2.5}
                                stroke="#0369a1"
                                strokeWidth={1.2}
                            />
                            <line
                                x1={x + 5}
                                y1={y + 2.5}
                                x2={x + w - 5}
                                y2={y + 2.5}
                                stroke="#0369a1"
                                strokeWidth={1.2}
                            />
                        </g>
                    );
                })}
                {resolved.labels.map((label) => (
                    <text
                        key={label.id}
                        x={label.x * width}
                        y={label.y * height}
                        fontSize={label.size ?? 16}
                        fill="#334155"
                    >
                        {label.text}
                    </text>
                ))}
                {pins.map((pin, index) => {
                    if (
                        pin.kind === 'evacuation_route' &&
                        pin.path_points &&
                        pin.path_points.length >= 2
                    ) {
                        return (
                            <polyline
                                key={`route-${pin.id ?? index}`}
                                points={pin.path_points
                                    .map(
                                        (point) =>
                                            `${point.x * width},${point.y * height}`,
                                    )
                                    .join(' ')}
                                fill="none"
                                stroke={pinStyle(pin.kind, taxonomy).color}
                                strokeWidth={4}
                                strokeDasharray="8 6"
                            />
                        );
                    }
                    const style = pinStyle(pin.kind, taxonomy);
                    const Icon = resolveIcon(style.icon);
                    const x = pin.x * width;
                    const y = pin.y * height;
                    const selected =
                        selectedPinId != null &&
                        String(selectedPinId) === String(pin.id);
                    return (
                        <g
                            key={`${pin.kind}-${pin.id ?? index}`}
                            transform={`translate(${x} ${y})`}
                        >
                            <circle
                                r={selected ? 18 : 14}
                                fill={style.color}
                                stroke="#fff"
                                strokeWidth={4}
                            />
                            <foreignObject x={-8} y={-8} width={16} height={16}>
                                <Icon className="h-4 w-4 text-white" />
                            </foreignObject>
                            <text x={20} y={5} fontSize={14} fill="#111827">
                                {pin.label || pin.kind.replaceAll('_', ' ')}
                            </text>
                        </g>
                    );
                })}
            </svg>
            {showScale && (
                <GuardrailCard
                    unstyled
                    className="pointer-events-none absolute right-2 bottom-2 rounded-md border bg-background/90 px-2 py-1 text-xs text-muted-foreground shadow-sm"
                >
                    Scale: {formatMeters(100, mpu)} ≈ 100 units
                </GuardrailCard>
            )}
        </div>
    );
}
