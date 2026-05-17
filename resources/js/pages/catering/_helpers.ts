export type TagKind = 'dietary' | 'allergen';
export type TagSeverity = 'info' | 'warn' | 'critical';

export type DietaryTag = {
    id: number;
    key: string;
    label: string;
    kind: TagKind;
    severity: TagSeverity;
    color: string | null;
    description?: string | null;
};

export type Product = {
    id: number;
    name: string;
    category: string | null;
    default_unit: string;
    pack_size: string | number | null;
    pack_unit: string | null;
    cost_per_unit_cents: number | null;
    currency: string;
    is_active: boolean;
    deleted_at: string | null;
    barcode: string | null;
    notes: string | null;
    tags?: DietaryTag[];
};

export type RecipeIngredient = {
    id?: number;
    product_id: number | null;
    free_text_name: string | null;
    quantity: number | string;
    unit: string;
    notes?: string | null;
    sort_order?: number;
};

export type Recipe = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    serves_default: number;
    prep_minutes: number | null;
    cook_minutes: number | null;
    instructions: string | null;
    is_active: boolean;
    deleted_at: string | null;
    tags?: DietaryTag[];
    ingredients?: RecipeIngredient[];
    creator?: { id: number; name: string } | null;
};

export const SEVERITY_BADGE: Record<TagSeverity, string> = {
    info: 'bg-blue-100 text-blue-800 border border-blue-200',
    warn: 'bg-amber-100 text-amber-900 border border-amber-200',
    critical: 'bg-red-100 text-red-900 border border-red-200',
};

export function tagBadgeStyle(tag: DietaryTag): React.CSSProperties {
    if (!tag.color) return {};
    return {
        backgroundColor: `${tag.color}22`,
        color: tag.color,
        borderColor: `${tag.color}55`,
    };
}

export function formatMoneyFromCents(cents: number | null | undefined, currency = 'NZD'): string {
    if (cents === null || cents === undefined) return '—';
    const value = cents / 100;
    try {
        return new Intl.NumberFormat('en-NZ', { style: 'currency', currency }).format(value);
    } catch {
        return `${currency} ${value.toFixed(2)}`;
    }
}

export function formatQuantity(qty: number | string | null | undefined, unit: string | null): string {
    if (qty === null || qty === undefined) return '—';
    const n = typeof qty === 'string' ? parseFloat(qty) : qty;
    if (Number.isNaN(n)) return '—';
    const stripped = n % 1 === 0 ? n.toFixed(0) : n.toFixed(2).replace(/\.?0+$/, '');
    return unit ? `${stripped} ${unit}` : stripped;
}
