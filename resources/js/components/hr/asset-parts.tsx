/* Shared atoms for the HR Asset Management hub — category/status metadata, NZ
 * formatting and the small presentational bits reused across the hero, the four
 * tab bodies, the detail page and the wizards. Colours stay token-based so tenant
 * white-label theming propagates. */
import {
    Box,
    CreditCard,
    Key,
    Laptop,
    Shirt,
    Smartphone,
    Tablet,
    Truck,
    type LucideIcon,
} from 'lucide-react';

/* ------------------------------------------------------------------ */
/*  Types                                                             */
/* ------------------------------------------------------------------ */

export type AssetStatus = 'available' | 'assigned' | 'maintenance' | 'retired';

export interface InventoryRow {
    id: number;
    tag: string;
    name: string;
    category: string;
    status: AssetStatus;
    make: string | null;
    model: string | null;
    serial: string | null;
    cost: number | null;
    warranty: string | null;
    supplier: string | null;
    site: string | null;
    fleet: boolean;
    fleet_asset_id: number | null;
    assignment_id: number | null;
    assignee: string | null;
    role: string | null;
    since: string | null;
    due_by: string | null;
    overdue: boolean;
    leaver: boolean;
}

export interface AssignmentRow {
    assignment_id: number;
    asset_id: number;
    tag: string | null;
    name: string | null;
    category: string | null;
    fleet: boolean;
    assignee: string;
    role: string | null;
    site: string | null;
    since: string | null;
    due_by: string | null;
    overdue: boolean;
    acknowledged: boolean;
    leaver: boolean;
}

export interface MaintenanceJob {
    id: number;
    asset_id: number;
    asset_name: string | null;
    asset_tag: string | null;
    type: string;
    vendor: string | null;
    cost: number | null;
    sent_at: string | null;
    expected_back_at: string | null;
    next_due_at: string | null;
}

export interface AssetDocumentRow {
    id: number;
    asset_id: number;
    asset_tag: string | null;
    title: string;
    category: string;
    effective_at: string | null;
    expiry_at: string | null;
    uploaded_by: string | null;
    created_at: string | null;
}

export interface StaffOption {
    id: number;
    name: string;
    role: string | null;
    site: string | null;
}

export interface CategoryOption {
    value: string;
    label: string;
    fleet: boolean;
}

export interface AssetHero {
    site_count: number;
    total: number;
    available: number;
    assigned: number;
    maintenance: number;
    retired: number;
    owned_value: number;
    total_value: number;
    warranties_30d: number;
    warranties_90d: number;
    overdue_returns: number;
    leaver_held: number;
    status_mix: { status: AssetStatus; count: number }[];
    category_mix: Record<string, number>;
}

/* ------------------------------------------------------------------ */
/*  Metadata                                                          */
/* ------------------------------------------------------------------ */

export const CATEGORY_LABEL: Record<string, string> = {
    laptop: 'Laptop',
    phone: 'Phone',
    tablet: 'Tablet',
    uniform: 'Uniform',
    card: 'Access card',
    other: 'Other',
    vehicle: 'Vehicle',
    key: 'Key',
};

export const CATEGORY_ICON: Record<string, LucideIcon> = {
    laptop: Laptop,
    phone: Smartphone,
    tablet: Tablet,
    uniform: Shirt,
    card: CreditCard,
    other: Box,
    vehicle: Truck,
    key: Key,
};

export function categoryLabel(key: string): string {
    return CATEGORY_LABEL[key] ?? key;
}

export function categoryIcon(key: string): LucideIcon {
    return CATEGORY_ICON[key] ?? Box;
}

export const STATUS_META: Record<
    AssetStatus,
    { label: string; className: string; dot: string; ring: string }
> = {
    available: {
        label: 'Available',
        className: 'bg-status-success-bg text-status-success',
        dot: 'bg-status-success',
        ring: 'oklch(0.62 0.14 150)',
    },
    assigned: {
        label: 'Assigned',
        className: 'bg-status-info-bg text-status-info',
        dot: 'bg-status-info',
        ring: 'oklch(0.66 0.16 277)',
    },
    maintenance: {
        label: 'Maintenance',
        className: 'bg-status-warning-bg text-status-warning',
        dot: 'bg-status-warning',
        ring: 'oklch(0.78 0.13 85)',
    },
    retired: {
        label: 'Retired',
        className: 'bg-muted text-muted-foreground',
        dot: 'bg-muted-foreground',
        ring: 'oklch(0.7 0.02 277)',
    },
};

/* ------------------------------------------------------------------ */
/*  NZ formatting                                                     */
/* ------------------------------------------------------------------ */

export function nzd(value: number | null | undefined, opts?: { cents?: boolean }): string {
    if (value == null) return '—';
    return new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
        maximumFractionDigits: opts?.cents ? 2 : 0,
    }).format(value);
}

export function fdate(value: string | null | undefined): string {
    if (!value) return '—';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          });
}

export function initials(name: string | null | undefined): string {
    if (!name) return '—';
    return name
        .split(' ')
        .map((w) => w[0])
        .filter(Boolean)
        .slice(0, 2)
        .join('')
        .toUpperCase();
}

/* ------------------------------------------------------------------ */
/*  Presentational atoms                                              */
/* ------------------------------------------------------------------ */

export function StatusPill({ status }: { status: AssetStatus }) {
    const meta = STATUS_META[status] ?? STATUS_META.retired;
    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11.5px] font-bold ${meta.className}`}
        >
            <span className={`h-1.5 w-1.5 rounded-full ${meta.dot}`} />
            {meta.label}
        </span>
    );
}

export function FleetBadge() {
    return (
        <span
            className="rounded-[5px] px-1.5 py-px text-[9.5px] font-bold tracking-wide uppercase"
            style={{
                color: 'var(--category-fleet)',
                background:
                    'color-mix(in oklch, var(--category-fleet) 14%, transparent)',
            }}
        >
            Fleet
        </span>
    );
}

/** Deterministic initials disc; reddened for a leaver so recover-flags read fast. */
export function PersonAvatar({
    name,
    leaver,
    size = 28,
}: {
    name: string | null;
    leaver?: boolean;
    size?: number;
}) {
    return (
        <span
            className="grid flex-none place-items-center rounded-full text-[11px] font-bold"
            style={{
                height: size,
                width: size,
                background: leaver
                    ? 'var(--status-critical-bg)'
                    : 'color-mix(in oklch, var(--primary) 12%, transparent)',
                color: leaver ? 'var(--status-critical)' : 'var(--primary)',
            }}
        >
            {initials(name)}
        </span>
    );
}
