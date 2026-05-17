export type RoomRefType = 'house_room' | 'ho_resource' | 'facility_zone';

export type BuilderMode = 'full' | 'emergency';
export const SELECT_TOOL = '__select';

export function isSelectMode(kind: string | null): boolean {
    return kind === null || kind === SELECT_TOOL;
}

export function isEmergencyPlanKind(kind: string, emergencyKinds: string[]): boolean {
    return emergencyKinds.includes(kind);
}

export type PlanRoom = {
    id: string;
    label?: string | null;
    shape?: 'rect' | 'polygon';
    x: number;
    y: number;
    width: number;
    height: number;
    rotation_deg?: number;
    room_ref_type?: RoomRefType | null;
    room_ref_id?: number | null;
};

export type PlanWall = {
    id: string;
    points: Array<{ x: number; y: number }>;
    thickness?: number;
};

export type PlanDoor = {
    id: string;
    x: number;
    y: number;
    width?: number;
    swing?: string;
    rotation_deg?: number;
};

export type PlanWindow = {
    id: string;
    x: number;
    y: number;
    width?: number;
    rotation_deg?: number;
};

export type PlanLabel = {
    id: string;
    x: number;
    y: number;
    text: string;
    size?: number;
    rotation_deg?: number;
};

export type PlanLayout = {
    schema_version?: number;
    canvas?: {
        width?: number;
        height?: number;
        unit?: string;
        meters_per_unit?: number;
    };
    grid?: { enabled?: boolean; size?: number; snap?: boolean };
    scale?: null | {
        meters_per_unit?: number;
        calibrated_at?: string;
    };
    walls?: PlanWall[];
    rooms?: PlanRoom[];
    doors?: PlanDoor[];
    windows?: PlanWindow[];
    labels?: PlanLabel[];
};

export type PlanPin = {
    id?: number | string;
    kind: string;
    subkind?: string | null;
    device_id?: number | null;
    room_ref_type?: RoomRefType | null;
    room_ref_id?: number | null;
    label?: string | null;
    notes?: string | null;
    meta?: Record<string, unknown> | null;
    x: number;
    y: number;
    rotation_deg?: number;
    width?: number | null;
    height?: number | null;
    path_points?: Array<{ x: number; y: number }> | null;
    sort_order?: number | null;
};

export type InventoryRoom = {
    id: number;
    name: string;
    type: RoomRefType;
    type_label: string;
    is_active: boolean;
    is_assigned: boolean;
};

export type InventoryDevice = {
    id: number;
    name: string;
    uid: string;
    category?: string | null;
    subcategory?: string | null;
    manufacturer?: string | null;
    model?: string | null;
    status?: string | null;
    health?: string | null;
};

export type Inventory = {
    rooms: InventoryRoom[];
    devices: InventoryDevice[];
};

export type TaxonomySubkind = {
    value: string;
    label: string;
};

export type TaxonomyKind = {
    label: string;
    icon: string;
    color: string;
    subkinds?: TaxonomySubkind[];
    measure?: 'line' | 'polyline' | 'area';
};

export type TaxonomyShape = {
    label: string;
    icon: string;
    tool: 'room' | 'wall' | 'door' | 'window' | 'label';
    measure?: 'line' | 'polyline' | 'area';
};

export type TaxonomyGroup = {
    id: string;
    label: string;
    kinds: string[];
};

export type Taxonomy = {
    groups: TaxonomyGroup[];
    shapes: Record<string, TaxonomyShape>;
    kinds: Record<string, TaxonomyKind>;
};

export type ShapeTool = 'room' | 'wall' | 'door' | 'window' | 'label' | 'scale';

export type SelectionRef =
    | { type: 'room'; id: string }
    | { type: 'wall'; id: string }
    | { type: 'door'; id: string }
    | { type: 'window'; id: string }
    | { type: 'label'; id: string }
    | { type: 'pin'; id: string | number };

export function normaliseLayout(layout?: PlanLayout | null): Required<
    Pick<PlanLayout, 'rooms' | 'walls' | 'doors' | 'windows' | 'labels'>
> &
    PlanLayout {
    return {
        schema_version: 1,
        canvas: {
            width: 1000,
            height: 700,
            unit: 'rel',
            meters_per_unit: 0.025,
            ...(layout?.canvas ?? {}),
        },
        grid: { enabled: true, size: 10, snap: true, ...(layout?.grid ?? {}) },
        scale: layout?.scale ?? null,
        rooms: layout?.rooms ?? [],
        walls: layout?.walls ?? [],
        doors: layout?.doors ?? [],
        windows: layout?.windows ?? [],
        labels: layout?.labels ?? [],
    };
}

export function metersPerUnit(layout?: PlanLayout | null): number {
    const fromScale = layout?.scale?.meters_per_unit;
    if (typeof fromScale === 'number' && fromScale > 0) return fromScale;
    const fromCanvas = layout?.canvas?.meters_per_unit;
    if (typeof fromCanvas === 'number' && fromCanvas > 0) return fromCanvas;
    return 0.025;
}

/**
 * Format a length in virtual canvas units as a human readable metric string.
 */
export function formatMeters(canvasUnits: number, mpu: number): string {
    const meters = canvasUnits * mpu;
    if (meters < 1) return `${(meters * 100).toFixed(0)} cm`;
    if (meters < 10) return `${meters.toFixed(2)} m`;
    return `${meters.toFixed(1)} m`;
}

export function distanceCanvasUnits(
    a: { x: number; y: number },
    b: { x: number; y: number },
    canvasWidth: number,
    canvasHeight: number,
): number {
    const dx = (b.x - a.x) * canvasWidth;
    const dy = (b.y - a.y) * canvasHeight;
    return Math.hypot(dx, dy);
}
