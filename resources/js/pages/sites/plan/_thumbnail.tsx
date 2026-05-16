import { cn } from '@/lib/utils';
import { CircleAlert, Cross, DoorOpen, MapPin, Pill, Route, Video } from 'lucide-react';
import type React from 'react';

export type PlanRoom = {
    id: string;
    label: string;
    shape?: 'rect' | 'polygon';
    x: number;
    y: number;
    width: number;
    height: number;
};

export type PlanLayout = {
    schema_version?: number;
    canvas?: { width?: number; height?: number; unit?: string };
    grid?: { enabled?: boolean; size?: number; snap?: boolean };
    walls?: Array<{ id: string; points: Array<{ x: number; y: number }>; thickness?: number }>;
    rooms?: PlanRoom[];
    doors?: Array<{ id: string; x: number; y: number; width?: number; swing?: string }>;
    windows?: Array<{ id: string; x: number; y: number; width?: number }>;
    labels?: Array<{ id: string; x: number; y: number; text: string; size?: number }>;
};

export type PlanPin = {
    id?: number | string;
    kind: string;
    subkind?: string | null;
    device_id?: number | null;
    label?: string | null;
    notes?: string | null;
    meta?: Record<string, unknown> | null;
    x: number;
    y: number;
    rotation_deg?: number;
    width?: number | null;
    height?: number | null;
    path_points?: Array<{ x: number; y: number }> | null;
};

type Props = {
    layout?: PlanLayout | null;
    pins?: PlanPin[];
    className?: string;
    onCanvasClick?: (point: { x: number; y: number }) => void;
    selectedPinId?: number | string | null;
};

const pinStyles: Record<string, { className: string; icon: typeof MapPin }> = {
    device: { className: 'fill-blue-600 text-white', icon: Video },
    medication_storage: { className: 'fill-violet-600 text-white', icon: Pill },
    emergency_exit: { className: 'fill-red-600 text-white', icon: DoorOpen },
    evacuation_route: { className: 'fill-amber-600 text-white', icon: Route },
    assembly_point: { className: 'fill-emerald-600 text-white', icon: MapPin },
    you_are_here: { className: 'fill-slate-900 text-white', icon: Cross },
    fire_extinguisher: { className: 'fill-orange-600 text-white', icon: CircleAlert },
};

export function normaliseLayout(layout?: PlanLayout | null): Required<Pick<PlanLayout, 'rooms' | 'walls' | 'doors' | 'windows' | 'labels'>> & PlanLayout {
    return {
        schema_version: 1,
        canvas: { width: 1000, height: 700, unit: 'rel', ...(layout?.canvas ?? {}) },
        grid: { enabled: true, size: 20, snap: true, ...(layout?.grid ?? {}) },
        rooms: layout?.rooms ?? [],
        walls: layout?.walls ?? [],
        doors: layout?.doors ?? [],
        windows: layout?.windows ?? [],
        labels: layout?.labels ?? [],
    };
}

export function PlanThumbnail({ layout, pins = [], className, onCanvasClick, selectedPinId }: Props) {
    const resolved = normaliseLayout(layout);
    const width = resolved.canvas?.width ?? 1000;
    const height = resolved.canvas?.height ?? 700;

    function handleClick(event: React.MouseEvent<SVGSVGElement>) {
        if (!onCanvasClick) return;
        const rect = event.currentTarget.getBoundingClientRect();
        onCanvasClick({
            x: Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width)),
            y: Math.min(1, Math.max(0, (event.clientY - rect.top) / rect.height)),
        });
    }

    return (
        <div className={cn('overflow-hidden rounded-md border bg-white', className)}>
            <svg viewBox={`0 0 ${width} ${height}`} className="h-full min-h-[260px] w-full" onClick={handleClick}>
                <rect width={width} height={height} fill="#ffffff" />
                {resolved.grid?.enabled &&
                    Array.from({ length: Math.floor(width / 50) }).map((_, index) => (
                        <line key={`gx-${index}`} x1={index * 50} y1={0} x2={index * 50} y2={height} stroke="#e2e8f0" strokeWidth={1} />
                    ))}
                {resolved.grid?.enabled &&
                    Array.from({ length: Math.floor(height / 50) }).map((_, index) => (
                        <line key={`gy-${index}`} x1={0} y1={index * 50} x2={width} y2={index * 50} stroke="#e2e8f0" strokeWidth={1} />
                    ))}
                {resolved.rooms.map((room) => (
                    <g key={room.id}>
                        <rect x={room.x * width} y={room.y * height} width={room.width * width} height={room.height * height} fill="#f8fafc" stroke="#334155" strokeWidth={3} />
                        <text x={room.x * width + 12} y={room.y * height + 28} fontSize={18} fill="#0f172a">
                            {room.label}
                        </text>
                    </g>
                ))}
                {resolved.walls.map((wall) => (
                    <polyline
                        key={wall.id}
                        points={wall.points.map((point) => `${point.x * width},${point.y * height}`).join(' ')}
                        fill="none"
                        stroke="#111827"
                        strokeWidth={wall.thickness ?? 4}
                    />
                ))}
                {resolved.doors.map((door) => (
                    <rect key={door.id} x={door.x * width} y={door.y * height} width={(door.width ?? 0.06) * width} height={8} fill="#92400e" />
                ))}
                {resolved.windows.map((window) => (
                    <rect key={window.id} x={window.x * width} y={window.y * height} width={(window.width ?? 0.08) * width} height={6} fill="#38bdf8" />
                ))}
                {resolved.labels.map((label) => (
                    <text key={label.id} x={label.x * width} y={label.y * height} fontSize={label.size ?? 16} fill="#334155">
                        {label.text}
                    </text>
                ))}
                {pins.map((pin, index) => {
                    const style = pinStyles[pin.kind] ?? { className: 'fill-slate-600 text-white', icon: MapPin };
                    const Icon = style.icon;
                    const x = pin.x * width;
                    const y = pin.y * height;
                    const selected = selectedPinId != null && String(selectedPinId) === String(pin.id);
                    return (
                        <g key={`${pin.kind}-${pin.id ?? index}`} transform={`translate(${x} ${y})`}>
                            <circle r={selected ? 18 : 14} className={style.className} stroke="#fff" strokeWidth={4} />
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
        </div>
    );
}
