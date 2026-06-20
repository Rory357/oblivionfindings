/**
 * PPE & Equipment register — shared types, tone/category maps, chips and helpers.
 *
 * Types mirror `PpeController` payloads 1:1. Tone logic + category maps come from
 * the design spec (docs/ppe-redesign/DESIGN_SPEC.md §3.3/§8). Semantic tokens only
 * — `info` → primary/accent in this token system.
 */
import {
    AlertTriangle,
    Anchor,
    Ban,
    Clock,
    Ear,
    Eye,
    Footprints,
    Hand,
    HardHat,
    Package,
    Shirt,
    Wind,
    type LucideIcon,
} from 'lucide-react';

// ───────────────────────── Tone ─────────────────────────

export type PpeTone = 'success' | 'warning' | 'critical' | 'neutral' | 'info';

export const TONE_CHIP: Record<PpeTone, string> = {
    success: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
    info: 'bg-accent text-primary',
    neutral: 'bg-muted text-muted-foreground',
};

export const TONE_DOT: Record<PpeTone, string> = {
    success: 'bg-status-success',
    warning: 'bg-status-warning',
    critical: 'bg-status-critical',
    info: 'bg-primary',
    neutral: 'bg-muted-foreground',
};

export const TONE_ICON_TILE: Record<PpeTone, string> = {
    success: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
    info: 'bg-accent text-primary',
    neutral: 'bg-muted text-muted-foreground',
};

// ───────────────────────── Category maps ─────────────────────────

export type PpeCategory =
    | 'head'
    | 'eye'
    | 'ear'
    | 'respiratory'
    | 'hand'
    | 'foot'
    | 'high_visibility'
    | 'body'
    | 'fall_protection'
    | 'other';

export const CAT_ICON: Record<string, LucideIcon> = {
    head: HardHat,
    eye: Eye,
    ear: Ear,
    respiratory: Wind,
    hand: Hand,
    foot: Footprints,
    high_visibility: Shirt,
    body: Shirt,
    fall_protection: Anchor,
    other: Package,
};

export const CAT_LABEL: Record<string, string> = {
    head: 'Head',
    eye: 'Eye',
    ear: 'Hearing',
    respiratory: 'Respiratory',
    hand: 'Hand',
    foot: 'Foot',
    high_visibility: 'Hi-vis',
    body: 'Body',
    fall_protection: 'Fall protection',
    other: 'Other',
};

export const CAT_TONE: Record<string, PpeTone> = {
    respiratory: 'info',
    head: 'warning',
    eye: 'success',
    ear: 'neutral',
    hand: 'info',
    foot: 'warning',
    high_visibility: 'success',
    fall_protection: 'critical',
    body: 'neutral',
    other: 'neutral',
};

export function catIcon(category?: string | null): LucideIcon {
    return CAT_ICON[category ?? 'other'] ?? Package;
}
export function catLabel(category?: string | null): string {
    return CAT_LABEL[category ?? 'other'] ?? 'Other';
}
export function catTone(category?: string | null): PpeTone {
    return CAT_TONE[category ?? 'other'] ?? 'neutral';
}

// Filter/picker option lists.
export const CATEGORY_OPTIONS: { value: string; label: string }[] = [
    'respiratory',
    'head',
    'eye',
    'ear',
    'hand',
    'foot',
    'high_visibility',
    'fall_protection',
    'body',
    'other',
].map((value) => ({ value, label: CAT_LABEL[value] }));

// Add-type TilePicker tiles (the 8 core NZ categories).
export const CATEGORY_TILES: {
    key: string;
    label: string;
    description: string;
    icon: LucideIcon;
}[] = [
    {
        key: 'head',
        label: 'Head',
        description: 'Helmets, bump caps',
        icon: HardHat,
    },
    { key: 'eye', label: 'Eye', description: 'Glasses, goggles', icon: Eye },
    { key: 'ear', label: 'Hearing', description: 'Plugs, muffs', icon: Ear },
    {
        key: 'respiratory',
        label: 'Respiratory',
        description: 'Masks, RPE',
        icon: Wind,
    },
    { key: 'hand', label: 'Hand', description: 'Gloves', icon: Hand },
    { key: 'foot', label: 'Foot', description: 'Boots', icon: Footprints },
    {
        key: 'high_visibility',
        label: 'Hi-vis',
        description: 'Vests, jackets',
        icon: Shirt,
    },
    {
        key: 'fall_protection',
        label: 'Fall protection',
        description: 'Harnesses, lanyards',
        icon: Anchor,
    },
];

// ───────────────────────── Condition / status ─────────────────────────

export function condTone(condition?: string | null): PpeTone {
    switch (condition) {
        case 'new':
            return 'success';
        case 'good':
            return 'info';
        case 'fair':
            return 'warning';
        case 'poor':
            return 'warning';
        case 'condemned':
            return 'critical';
        default:
            return 'neutral';
    }
}

export function statusTone(status?: string | null): PpeTone {
    switch (status) {
        case 'available':
            return 'success';
        case 'allocated':
            return 'info';
        case 'maintenance':
            return 'warning';
        case 'condemned':
            return 'critical';
        case 'disposed':
            return 'neutral';
        default:
            return 'neutral';
    }
}

/** Title-cased condition label (new/good/fair/poor/condemned → "New" …). */
export function condLabel(condition?: string | null): string {
    if (!condition) return '—';
    return condition.charAt(0).toUpperCase() + condition.slice(1);
}

export function statusLabel(status?: string | null): string {
    switch (status) {
        case 'available':
            return 'Available';
        case 'allocated':
            return 'Allocated';
        case 'maintenance':
            return 'In repair';
        case 'condemned':
            return 'Condemned';
        case 'disposed':
            return 'Disposed';
        default:
            return titleCaseLocal(status ?? '—');
    }
}

export const CONDITION_OPTIONS = [
    'new',
    'good',
    'fair',
    'poor',
    'condemned',
] as const;
export const STATUS_FILTER_OPTIONS: { value: string; label: string }[] = [
    { value: 'available', label: 'Available' },
    { value: 'allocated', label: 'Allocated' },
    { value: 'maintenance', label: 'In repair' },
    { value: 'condemned', label: 'Condemned' },
    { value: 'disposed', label: 'Disposed' },
];

export const INSPECTION_FREQUENCY_OPTIONS: { value: string; label: string }[] =
    [
        { value: 'daily', label: 'Daily' },
        { value: 'weekly', label: 'Weekly' },
        { value: 'monthly', label: 'Monthly' },
        { value: 'quarterly', label: 'Quarterly' },
        { value: 'annually', label: 'Annually' },
    ];

function titleCaseLocal(s: string): string {
    return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

// ───────────────────────── Date helpers (en-NZ) ─────────────────────────

/** Whole days from today to `date` (negative = in the past). null when no date. */
export function daysUntil(date?: string | null): number | null {
    if (!date) return null;
    const target = new Date(date);
    if (Number.isNaN(target.getTime())) return null;
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    target.setHours(0, 0, 0, 0);
    return Math.round((target.getTime() - today.getTime()) / 86_400_000);
}

export function fmtDateNZ(date?: string | null): string {
    if (!date) return '—';
    const d = new Date(date);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleDateString('en-NZ', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

export function fmtDateTimeNZ(date?: string | null): string {
    if (!date) return '—';
    const d = new Date(date);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleString('en-NZ', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export function isRespiratory(category?: string | null): boolean {
    return category === 'respiratory';
}

// ───────────────────────── Flag derivation ─────────────────────────

export type PpeFlag = {
    icon: LucideIcon;
    label: string;
    tone: PpeTone;
    title: string;
};

export function inventoryFlags(row: {
    status?: string | null;
    next_inspection_due?: string | null;
    expiry_date?: string | null;
}): PpeFlag[] {
    const flags: PpeFlag[] = [];

    const insp = daysUntil(row.next_inspection_due);
    if (insp !== null && insp < 0) {
        flags.push({
            icon: Clock,
            label: 'Inspection overdue',
            tone: 'critical',
            title: 'Inspection overdue',
        });
    } else if (insp !== null && insp <= 30) {
        flags.push({
            icon: Clock,
            label: 'Inspection due',
            tone: 'warning',
            title: 'Inspection due soon',
        });
    }

    const exp = daysUntil(row.expiry_date);
    if (exp !== null && exp < 0) {
        flags.push({
            icon: AlertTriangle,
            label: 'Expired',
            tone: 'critical',
            title: 'Past expiry date',
        });
    } else if (exp !== null && exp <= 60) {
        flags.push({
            icon: AlertTriangle,
            label: 'Expiring',
            tone: 'warning',
            title: 'Expiring within 60 days',
        });
    }

    if (row.status === 'condemned') {
        flags.push({
            icon: Ban,
            label: 'Awaiting disposal',
            tone: 'warning',
            title: 'Condemned — awaiting disposal',
        });
    }

    return flags;
}

export function allocationFlags(row: {
    returned_at?: string | null;
    acknowledged?: boolean;
    fit_test_completed?: boolean;
    ppe_type?: { category?: string | null } | null;
}): PpeFlag[] {
    const flags: PpeFlag[] = [];
    const active = !row.returned_at;
    if (!active) return flags;

    if (!row.acknowledged) {
        flags.push({
            icon: AlertTriangle,
            label: 'Unacknowledged',
            tone: 'warning',
            title: 'Worker has not acknowledged',
        });
    }
    if (isRespiratory(row.ppe_type?.category) && !row.fit_test_completed) {
        flags.push({
            icon: Wind,
            label: 'No fit-test',
            tone: 'critical',
            title: 'RPE issued without a fit-test (AS/NZS 1715)',
        });
    }

    return flags;
}

// ───────────────────────── Server payload types (mirror PpeController) ─────────────────────────

export type Paginator<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

export type Ref = { id: number; name: string };

export type InventoryRow = {
    id: number;
    ppe_type: {
        id: number;
        name: string;
        category: string;
        standards_reference: string | null;
    } | null;
    site: Ref | null;
    brand: string | null;
    model: string | null;
    serial_number: string | null;
    quantity: number;
    location: string | null;
    condition: string;
    status: string;
    purchase_date: string | null;
    expiry_date: string | null;
    last_inspected_at: string | null;
    next_inspection_due: string | null;
};

export type AllocationRow = {
    id: number;
    user: Ref | null;
    inventory_item: {
        id: number;
        brand: string | null;
        model: string | null;
        serial_number: string | null;
        site: Ref | null;
    } | null;
    ppe_type: { name: string; category: string } | null;
    allocated_at: string | null;
    fit_test_completed: boolean;
    fit_test_date: string | null;
    training_completed: boolean;
    training_date: string | null;
    acknowledged: boolean;
    acknowledged_at: string | null;
};

export type TypeRow = {
    id: number;
    name: string;
    category: string;
    description: string | null;
    hazards_addressed: string | null;
    standards_reference: string | null;
    inspection_frequency: string | null;
    typical_lifespan_months: number | null;
    is_active: boolean;
    inventory_count: number;
};

export type TabCounts = {
    inv_all: number;
    inv_available: number;
    inv_allocated: number;
    inv_inspection: number;
    inv_expiring: number;
    inv_condemned: number;
    alloc_active: number;
    alloc_unack: number;
    types: number;
};

export type HeroBlock = {
    clusters: {
        live: {
            total: number;
            units: number;
            allocated: number;
            available: number;
            inspections_due: number;
        };
        attention: {
            inspection_overdue: number;
            expiring: number;
            condemned: number;
            unacknowledged: number;
        };
    };
    compliance: {
        rpe_fit_test_due: number;
        inspections_overdue: number;
        inspections_due: number;
        items_expiring: number;
        condemned_awaiting: number;
        hi_vis_covered: boolean;
        footwear_covered: boolean;
    };
};

export type PpeAttachment = {
    id: number;
    original_name: string;
    url: string;
    download_url: string;
    mime: string | null;
    kind: string | null;
    notes: string | null;
    alt_text: string | null;
    size: number | null;
    is_image: boolean;
    uploaded_by: Ref | null;
    created_at: string | null;
};

export type HistoryEvent = {
    type: string;
    label: string;
    at: string | null;
    actor: string | null;
};

export type ItemDetail = {
    id: number;
    ppe_type: {
        id: number;
        name: string;
        category: string;
        standards_reference: string | null;
        inspection_frequency: string | null;
        hazards_addressed: string | null;
    } | null;
    site: Ref | null;
    brand: string | null;
    model: string | null;
    serial_number: string | null;
    quantity: number;
    location: string | null;
    condition: string;
    status: string;
    purchase_date: string | null;
    expiry_date: string | null;
    last_inspected_at: string | null;
    next_inspection_due: string | null;
    condemned_at: string | null;
    condemned_by: Ref | null;
    condemned_reason: string | null;
    disposed_at: string | null;
    disposed_by: Ref | null;
    disposal_method: string | null;
    created_by: Ref | null;
    created_at: string | null;
    active_allocation: {
        id: number;
        user: Ref | null;
        allocated_at: string | null;
        fit_test_completed: boolean;
        fit_test_date: string | null;
        fit_test_result: string | null;
        training_completed: boolean;
        acknowledged: boolean;
        acknowledged_at: string | null;
    } | null;
    allocations: {
        id: number;
        user: Ref | null;
        allocated_at: string | null;
        returned_at: string | null;
        acknowledged: boolean;
    }[];
    inspections: {
        id: number;
        result: string;
        condition_after: string | null;
        findings: string | null;
        action_taken: string | null;
        inspected_at: string | null;
        inspector: Ref | null;
        next_inspection_due: string | null;
        attachments: PpeAttachment[];
    }[];
    attachments: PpeAttachment[];
    history: HistoryEvent[];
};

export type AllocationDetail = {
    id: number;
    user: Ref | null;
    inventory_item: {
        id: number;
        brand: string | null;
        model: string | null;
        serial_number: string | null;
        condition: string;
        status: string;
        site: Ref | null;
    } | null;
    ppe_type: {
        name: string;
        category: string;
        standards_reference: string | null;
    } | null;
    allocated_at: string | null;
    returned_at: string | null;
    fit_test_completed: boolean;
    fit_test_date: string | null;
    fit_test_result: string | null;
    training_completed: boolean;
    training_date: string | null;
    acknowledged: boolean;
    acknowledged_at: string | null;
    acknowledged_by: Ref | null;
    notes: string | null;
    issued_by: Ref | null;
    attachments: PpeAttachment[];
};

export type PpeDetail =
    | { kind: 'item'; item: ItemDetail }
    | { kind: 'allocation'; allocation: AllocationDetail };

export type Allocatable = {
    id: number;
    label: string;
    category: string | null;
    site: string | null;
};

export type PpeFilters = {
    site_id?: string | number | null;
    category?: string | null;
    status?: string | null;
    ppe_type_id?: string | number | null;
    search?: string | null;
};

export type PpePageProps = {
    tab: string;
    filters: PpeFilters;
    inventory: Paginator<InventoryRow>;
    allocations: Paginator<AllocationRow>;
    types: TypeRow[];
    tabCounts: TabCounts;
    hero: HeroBlock;
    sites: Ref[];
    staff: Ref[];
    allocatable: Allocatable[];
    detail: PpeDetail | null;
    can: { manage: boolean };
};

// ───────────────────────── Chip ─────────────────────────

export function PpeChip({
    tone,
    icon: Icon,
    children,
}: {
    tone: PpeTone;
    icon?: LucideIcon;
    children: React.ReactNode;
}) {
    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-md px-2 py-[3px] text-[11px] font-bold whitespace-nowrap ${TONE_CHIP[tone]}`}
        >
            {Icon ? <Icon className="h-3 w-3" /> : null}
            {children}
        </span>
    );
}

export function formatBytes(bytes?: number | null): string {
    if (!bytes || bytes <= 0) return '—';
    const units = ['B', 'KB', 'MB', 'GB'];
    const i = Math.min(
        units.length - 1,
        Math.floor(Math.log(bytes) / Math.log(1024)),
    );
    return `${(bytes / 1024 ** i).toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
}
