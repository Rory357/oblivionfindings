import type { LucideIcon } from 'lucide-react';
import { Cookie, CupSoda, EggFried, Moon, Soup, UtensilsCrossed } from 'lucide-react';

export type MealSlot = 'breakfast' | 'morning_tea' | 'lunch' | 'afternoon_tea' | 'dinner' | 'supper';

export const MEAL_SLOTS: MealSlot[] = ['breakfast', 'morning_tea', 'lunch', 'afternoon_tea', 'dinner', 'supper'];

export const SLOT_LABEL: Record<MealSlot, string> = {
    breakfast: 'Breakfast',
    morning_tea: 'Morning tea',
    lunch: 'Lunch',
    afternoon_tea: 'Afternoon tea',
    dinner: 'Dinner',
    supper: 'Supper',
};

export const SLOT_TIME: Record<MealSlot, string> = {
    breakfast: '7:30',
    morning_tea: '10:00',
    lunch: '12:30',
    afternoon_tea: '15:00',
    dinner: '17:30',
    supper: '20:00',
};

export const SLOT_ICON: Record<MealSlot, LucideIcon> = {
    breakfast: EggFried,
    morning_tea: CupSoda,
    lunch: Soup,
    afternoon_tea: Cookie,
    dinner: UtensilsCrossed,
    supper: Moon,
};

/** The three "core" meals counted for plan-completeness. */
export const CORE_SLOTS: MealSlot[] = ['breakfast', 'lunch', 'dinner'];

export type RecipeTag = { id: number; label: string; kind: 'allergen' | 'dietary'; severity?: string };

export type RecipeIngredient = {
    product_id: number | null;
    name: string | null;
    qty: number;
    unit: string;
    category?: string | null;
};

/** Slim recipe option (legacy callers). */
export type RecipeOption = {
    id: number;
    name: string;
    slug: string;
    serves_default: number;
    tag_ids?: number[];
};

/** Full recipe payload from the meal-planner bootstrap. */
export type RecipeFull = RecipeOption & {
    prep_minutes: number | null;
    cook_minutes: number | null;
    scope: 'shared' | 'house';
    site_id: number | null;
    instructions: string | null;
    tags: RecipeTag[];
    tag_ids: number[];
    allergen_tag_ids: number[];
    cost_cents: number;
    ingredients: RecipeIngredient[];
};

export type Resident = {
    id: number;
    name: string;
    initials: string;
    hue: number;
    allergens: string[];
    allergen_tag_ids: number[];
    dietary: string[];
    dietary_tag_ids: number[];
    dislikes: string[];
    dislike_product_ids: number[];
    texture: { level: number; label: string } | null;
    fluids: string | null;
};

export type WeekTemplateMeal = { day: number; slot: MealSlot; recipe_id: number; servings: number };

export type WeekTemplate = {
    id: number;
    name: string;
    description: string | null;
    is_starter: boolean;
    meals: WeekTemplateMeal[];
};

export type IddsiLevel = { level: number; label: string };

export type SiteSearchItem = {
    id: number;
    name: string;
    type: string;
    suburb: string | null;
    region: string | null;
    beds: number;
};

export type SiteInfo = {
    id: number;
    name: string;
    type: string;
    suburb: string | null;
    region: string | null;
    weekly_food_budget_cents: number | null;
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
    expiry_date?: string | null;
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

/* ── date helpers ─────────────────────────────────────────────────────── */

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

export function isoWeekNo(d: Date): number {
    const date = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
    const dayNum = date.getUTCDay() || 7;
    date.setUTCDate(date.getUTCDate() + 4 - dayNum);
    const yearStart = new Date(Date.UTC(date.getUTCFullYear(), 0, 1));
    return Math.ceil(((date.getTime() - yearStart.getTime()) / 86400000 + 1) / 7);
}

/* ── formatters ───────────────────────────────────────────────────────── */

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

export function toNum(v: number | string | null | undefined): number {
    if (v === null || v === undefined) return 0;
    const n = typeof v === 'string' ? parseFloat(v) : v;
    return Number.isNaN(n) ? 0 : n;
}

/* ── cost + conflict engine (client-side, mirrors the backend) ─────────── */

export type RecipeMap = Map<number, RecipeFull>;

export function buildRecipeMap(recipes: RecipeFull[]): RecipeMap {
    return new Map(recipes.map((r) => [r.id, r]));
}

export function mealCostCents(entry: PlanEntry, recipes: RecipeMap): number {
    if (entry.source_type === 'takeaway') return entry.takeaway_cost_cents ?? 0;
    if (entry.source_type === 'recipe' && entry.recipe_id != null) {
        const r = recipes.get(entry.recipe_id);
        if (!r) return 0;
        const base = Math.max(1, r.serves_default || 1);
        return Math.round(r.cost_cents * ((entry.servings || base) / base));
    }
    return 0;
}

export function entryDisplayName(entry: PlanEntry, recipes: RecipeMap): string {
    if (entry.source_type === 'takeaway') return entry.takeaway_vendor || 'Takeaway';
    if (entry.source_type === 'ad_hoc') return entry.ad_hoc_name || 'Ad-hoc meal';
    const r = entry.recipe_id != null ? recipes.get(entry.recipe_id) : null;
    return r?.name ?? entry.recipe?.name ?? 'Meal';
}

export type ConflictResult = {
    hard: { resident: Resident; matches: string[] }[];
    soft: { resident: Resident; matches: string[] }[];
};

export function conflictsFor(entry: PlanEntry, residents: Resident[], recipes: RecipeMap): ConflictResult {
    const empty: ConflictResult = { hard: [], soft: [] };
    if (entry.source_type !== 'recipe' || entry.recipe_id == null) return empty;
    const recipe = recipes.get(entry.recipe_id);
    if (!recipe) return empty;
    const ids = entry.client_ids ?? [];
    const allergenIds = new Set(recipe.allergen_tag_ids ?? []);
    const productIds = new Set(recipe.ingredients.map((i) => i.product_id).filter((x): x is number => x != null));
    const hard: ConflictResult['hard'] = [];
    const soft: ConflictResult['soft'] = [];
    for (const cid of ids) {
        const r = residents.find((x) => x.id === cid);
        if (!r) continue;
        const allergenHits = r.allergen_tag_ids.filter((t) => allergenIds.has(t));
        if (allergenHits.length) {
            const labels = r.allergens.filter((_, idx) => allergenIds.has(r.allergen_tag_ids[idx]));
            hard.push({ resident: r, matches: labels.length ? labels : r.allergens });
            continue;
        }
        const dislikeHits = r.dislike_product_ids.filter((p) => productIds.has(p));
        if (dislikeHits.length) soft.push({ resident: r, matches: r.dislikes });
    }
    return { hard, soft };
}

export function residentRelation(
    entry: PlanEntry,
    resident: Resident,
    recipes: RecipeMap,
): { involved: boolean; clash: 'allergen' | 'dislike' | null } {
    const involved = (entry.client_ids ?? []).includes(resident.id);
    let clash: 'allergen' | 'dislike' | null = null;
    if (involved && entry.source_type === 'recipe' && entry.recipe_id != null) {
        const recipe = recipes.get(entry.recipe_id);
        if (recipe) {
            const allergenIds = new Set(recipe.allergen_tag_ids ?? []);
            const productIds = new Set(recipe.ingredients.map((i) => i.product_id).filter((x): x is number => x != null));
            if (resident.allergen_tag_ids.some((t) => allergenIds.has(t))) clash = 'allergen';
            else if (resident.dislike_product_ids.some((p) => productIds.has(p))) clash = 'dislike';
        }
    }
    return { involved, clash };
}

/** A stable, pleasant avatar colour from a hue. */
export function hueStyle(hue: number): { backgroundColor: string; color: string } {
    return {
        backgroundColor: `oklch(0.92 0.06 ${hue})`,
        color: `oklch(0.40 0.13 ${hue})`,
    };
}
