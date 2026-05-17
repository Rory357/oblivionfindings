import type { LucideIcon } from 'lucide-react';
import { Coffee, Cookie, Soup, Utensils, UtensilsCrossed } from 'lucide-react';

export type MealSlot = 'breakfast' | 'morning_tea' | 'lunch' | 'afternoon_tea' | 'dinner' | 'supper';

export const MEAL_SLOTS: MealSlot[] = [
    'breakfast',
    'morning_tea',
    'lunch',
    'afternoon_tea',
    'dinner',
    'supper',
];

export const SLOT_LABEL: Record<MealSlot, string> = {
    breakfast: 'Breakfast',
    morning_tea: 'Morning tea',
    lunch: 'Lunch',
    afternoon_tea: 'Afternoon tea',
    dinner: 'Dinner',
    supper: 'Supper',
};

export const SLOT_ICON: Record<MealSlot, LucideIcon> = {
    breakfast: Coffee,
    morning_tea: Cookie,
    lunch: Soup,
    afternoon_tea: Cookie,
    dinner: Utensils,
    supper: UtensilsCrossed,
};

export type RecipeOption = {
    id: number;
    name: string;
    slug: string;
    serves_default: number;
    tag_ids?: number[];
};

export type SourceType = 'recipe' | 'ad_hoc' | 'takeaway';

export type PlanEntry = {
    id: number;
    site_id: number;
    plan_date: string;
    meal_slot: MealSlot;
    source_type: SourceType;
    recipe_id: number | null;
    ad_hoc_name: string | null;
    takeaway_vendor: string | null;
    takeaway_cost_cents: number | null;
    takeaway_reference: string | null;
    servings: number;
    notes: string | null;
    client_ids: number[] | null;
    served_at: string | null;
    allergen_override_reason: string | null;
    allergen_override_at: string | null;
    allergen_override_by?: { id: number; name: string } | null;
    recipe?: { id: number; name: string; slug: string; serves_default: number } | null;
};

export type InventoryItem = {
    id: number;
    site_id: number;
    product_id: number;
    current_qty: number | string;
    unit: string;
    par_level: number | string | null;
    reorder_level: number | string | null;
    location_label: string | null;
    last_counted_at: string | null;
    product: {
        id: number;
        name: string;
        category: string | null;
        default_unit: string;
        pack_size: string | number | null;
        pack_unit: string | null;
        cost_per_unit_cents: number | null;
        currency: string;
    };
};

export type ShoppingListItem = {
    id: number;
    list_id: number;
    product_id: number | null;
    free_text_name: string | null;
    needed_qty: number | string;
    unit: string;
    source: 'meal_plan' | 'restock_to_par' | 'manual';
    source_meta: unknown;
    received_qty: number | string | null;
    estimated_cost_cents: number | null;
    is_checked: boolean;
    notes: string | null;
    product?: { id: number; name: string; default_unit: string; category?: string | null; currency?: string | null } | null;
};

export type ShoppingList = {
    id: number;
    site_id: number;
    status: 'draft' | 'ordered' | 'received' | 'cancelled';
    covers_from: string;
    covers_to: string;
    generated_at: string | null;
    provider_key: string;
    provider_order_ref: string | null;
    ordered_at: string | null;
    received_at: string | null;
    notes: string | null;
    items?: ShoppingListItem[];
    generated_by?: { id: number; name: string } | null;
};

export type ConflictSummaryRow = {
    plan_entry_id: number;
    plan_date: string;
    meal_slot: string;
    recipe_name: string;
    client_name: string;
    matches: string[];
    has_override: boolean;
};

export type ConflictSummary = {
    count: number;
    unresolved_count: number;
    details: ConflictSummaryRow[];
};

export function startOfWeek(date: Date): Date {
    const d = new Date(date);
    const day = d.getDay(); // 0 = Sun
    const diff = day === 0 ? -6 : 1 - day; // Monday start
    d.setDate(d.getDate() + diff);
    d.setHours(0, 0, 0, 0);
    return d;
}

export function addDays(date: Date, n: number): Date {
    const d = new Date(date);
    d.setDate(d.getDate() + n);
    return d;
}

export function toIsoDate(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

export function formatMoneyFromCents(cents: number | null | undefined, currency = 'NZD'): string {
    if (cents === null || cents === undefined) return '—';
    try {
        return new Intl.NumberFormat('en-NZ', { style: 'currency', currency }).format(cents / 100);
    } catch {
        return `${currency} ${(cents / 100).toFixed(2)}`;
    }
}

export function formatQty(qty: number | string | null | undefined, unit: string | null): string {
    if (qty === null || qty === undefined) return '—';
    const n = typeof qty === 'string' ? parseFloat(qty) : qty;
    if (Number.isNaN(n)) return '—';
    const stripped = n % 1 === 0 ? n.toFixed(0) : n.toFixed(2).replace(/\.?0+$/, '');
    return unit ? `${stripped} ${unit}` : stripped;
}
