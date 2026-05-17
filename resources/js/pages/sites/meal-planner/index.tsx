import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { TabsRoot as Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { AlertTriangle, Calendar, ChefHat, ClipboardCheck, DollarSign, Package, ShoppingCart, Sparkles, Truck, Utensils } from 'lucide-react';
import { lazy, Suspense, useCallback, useEffect, useMemo, useState } from 'react';
import CalendarGrid from './_calendar-grid';
import InventoryTable from './_inventory-table';
import ShoppingListPanel from './_shopping-list-panel';
import { type ConflictSummary, type InventoryItem, type MealSlot, type PlanEntry, type RecipeOption, type ShoppingList, addDays, formatMoneyFromCents, startOfWeek, toIsoDate } from './_helpers';

const PlanEntryDialog = lazy(() => import('./_dialogs').then((m) => ({ default: m.PlanEntryDialog })));
const AdjustInventoryDialog = lazy(() => import('./_dialogs').then((m) => ({ default: m.AdjustInventoryDialog })));
const StocktakeDialog = lazy(() => import('./_dialogs').then((m) => ({ default: m.StocktakeDialog })));
const ShoppingListGenerateDialog = lazy(() => import('./_dialogs').then((m) => ({ default: m.ShoppingListGenerateDialog })));

type SiteSummary = { id: number; name: string; type: string };
type ClientOption = { id: number; name: string };

type Props = {
    site: SiteSummary;
};

export default function MealPlannerSubTabs({ site }: Props) {
    const [bootstrapped, setBootstrapped] = useState(false);
    const [recipes, setRecipes] = useState<RecipeOption[]>([]);
    const [products, setProducts] = useState<{ id: number; name: string; default_unit: string }[]>([]);
    const [productCategories, setProductCategories] = useState<string[]>([]);
    const [clients, setClients] = useState<ClientOption[]>([]);
    const [canPlan, setCanPlan] = useState(false);
    const [canAdjust, setCanAdjust] = useState(false);
    const [canShop, setCanShop] = useState(false);
    const [canCreateProducts, setCanCreateProducts] = useState(false);

    useEffect(() => {
        let cancelled = false;
        axios.get(`/sites/${site.id}/meal-planner/bootstrap`).then((res) => {
            if (cancelled) return;
            setRecipes(res.data.recipes ?? []);
            setProducts(res.data.products ?? []);
            setProductCategories(res.data.product_categories ?? []);
            setClients(res.data.clients ?? []);
            const perms = res.data.permissions ?? {};
            setCanPlan(!!perms.plan);
            setCanAdjust(!!perms.inventory_adjust);
            setCanShop(!!perms.shopping_manage);
            setCanCreateProducts(!!perms.products_manage);
            setBootstrapped(true);
        }).catch(() => setBootstrapped(true));
        return () => { cancelled = true; };
    }, [site.id]);

    const defaultTab = site.type === 'house' ? 'calendar' : 'inventory';
    const [tab, setTab] = useState(defaultTab);

    const [weekStart, setWeekStart] = useState<Date>(startOfWeek(new Date()));
    const [entries, setEntries] = useState<PlanEntry[]>([]);
    const [weekTotalCents, setWeekTotalCents] = useState<number>(0);
    const [weekCookCents, setWeekCookCents] = useState<number>(0);
    const [weekTakeawayCents, setWeekTakeawayCents] = useState<number>(0);
    const [inventory, setInventory] = useState<InventoryItem[]>([]);
    const [reasons, setReasons] = useState<string[]>(['stocktake', 'delivery', 'consumption', 'waste', 'adjustment', 'plan_consumption']);
    const [lists, setLists] = useState<ShoppingList[]>([]);
    const [conflictsByList, setConflictsByList] = useState<Record<number, ConflictSummary>>({});

    const [planDialog, setPlanDialog] = useState<{ open: boolean; entry: PlanEntry | null; date: string; slot: MealSlot }>({ open: false, entry: null, date: toIsoDate(weekStart), slot: 'lunch' });
    const [adjustDialog, setAdjustDialog] = useState<{ open: boolean; item: InventoryItem | null }>({ open: false, item: null });
    const [stocktakeOpen, setStocktakeOpen] = useState(false);
    const [generateOpen, setGenerateOpen] = useState(false);

    const reloadCalendar = useCallback(async () => {
        const week = toIsoDate(weekStart);
        const [planRes, summaryRes] = await Promise.all([
            axios.get(`/sites/${site.id}/meal-plan`, { params: { week } }),
            axios.get(`/sites/${site.id}/meal-plan/week-summary`, { params: { week } }),
        ]);
        setEntries(planRes.data.entries ?? []);
        setWeekTotalCents(summaryRes.data.total_cost_cents ?? 0);
        setWeekCookCents(summaryRes.data.cook_cost_cents ?? 0);
        setWeekTakeawayCents(summaryRes.data.takeaway_cost_cents ?? 0);
    }, [site.id, weekStart]);

    const reloadInventory = useCallback(async () => {
        const res = await axios.get(`/sites/${site.id}/meal-inventory`);
        setInventory(res.data.items ?? []);
        setReasons(res.data.reasons ?? reasons);
    }, [site.id]);

    const reloadLists = useCallback(async () => {
        const res = await axios.get(`/sites/${site.id}/meal-shopping-lists`);
        setLists(res.data.lists ?? []);
        setConflictsByList(res.data.conflicts_by_list ?? {});
    }, [site.id]);

    useEffect(() => { reloadCalendar(); }, [reloadCalendar]);
    // Load inventory + shopping lists on mount as well as on tab change,
    // so the KPI strip above Quick actions always reflects current data.
    useEffect(() => { reloadInventory(); }, [reloadInventory]);
    useEffect(() => { reloadLists(); }, [reloadLists]);

    function shiftWeek(delta: number) {
        if (delta === 0) setWeekStart(startOfWeek(new Date()));
        else setWeekStart((w) => addDays(w, delta));
    }

    function openNewMeal(date: string, slot: MealSlot) {
        if (!canPlan) return;
        setPlanDialog({ open: true, entry: null, date, slot });
    }

    function openExistingMeal(entry: PlanEntry) {
        setPlanDialog({ open: true, entry, date: entry.plan_date, slot: entry.meal_slot });
    }

    const inertiaReload = useCallback(() => {
        router.reload({ only: [], preserveScroll: true });
        reloadCalendar();
        if (tab === 'inventory') reloadInventory();
        if (tab === 'shopping') { reloadInventory(); reloadLists(); }
    }, [reloadCalendar, reloadInventory, reloadLists, tab]);

    if (!bootstrapped) {
        return <div className="rounded-md border p-8 text-center text-sm text-muted-foreground">Loading meal planner…</div>;
    }

    function quickPlanToday() {
        setTab('calendar');
        const today = startOfWeek(new Date());
        setWeekStart(today);
        const todayIso = toIsoDate(new Date());
        setPlanDialog({ open: true, entry: null, date: todayIso, slot: 'lunch' });
    }

    function quickReceiveDelivery() {
        setTab('inventory');
        setAdjustDialog({ open: true, item: null });
    }

    function quickStocktake() {
        setTab('inventory');
        setStocktakeOpen(true);
    }

    function quickGenerateList() {
        setTab('shopping');
        setGenerateOpen(true);
    }

    const isHouse = site.type === 'house';

    // Derived KPIs for the strip above Quick actions
    const mealsPlanned = entries.length;
    const mealsServed = entries.filter((e) => !!e.served_at).length;
    const overrideCount = entries.filter((e) => !!e.allergen_override_at).length;
    const lowStock = inventory.filter((i) => {
        const reorder = i.reorder_level !== null ? (typeof i.reorder_level === 'string' ? parseFloat(i.reorder_level) : i.reorder_level) : null;
        if (reorder === null) return false;
        const current = typeof i.current_qty === 'string' ? parseFloat(i.current_qty) : i.current_qty;
        return current <= reorder;
    }).length;
    const outOfStock = inventory.filter((i) => {
        const current = typeof i.current_qty === 'string' ? parseFloat(i.current_qty) : i.current_qty;
        return current <= 0;
    }).length;
    const inventoryValueCents = inventory.reduce((sum, i) => {
        if (!i.product?.cost_per_unit_cents) return sum;
        const current = typeof i.current_qty === 'string' ? parseFloat(i.current_qty) : i.current_qty;
        return sum + Math.round(current * i.product.cost_per_unit_cents);
    }, 0);
    const draftListCount = lists.filter((l) => l.status === 'draft').length;

    return (
        <div className="space-y-4">
            {/* KPI strip — at-a-glance numbers for this site */}
            <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                <KpiCard
                    icon={Calendar}
                    tone="primary"
                    label="Meals planned this week"
                    value={mealsPlanned.toString()}
                    sub={`${mealsServed} served${overrideCount > 0 ? ` · ${overrideCount} override${overrideCount === 1 ? '' : 's'}` : ''}`}
                />
                <KpiCard
                    icon={DollarSign}
                    tone="success"
                    label="Estimated week cost"
                    value={formatMoneyFromCents(weekTotalCents)}
                    sub={weekTakeawayCents > 0
                        ? `${formatMoneyFromCents(weekCookCents)} cook · ${formatMoneyFromCents(weekTakeawayCents)} takeaway`
                        : 'Planned meals + takeaways'}
                />
                <KpiCard
                    icon={AlertTriangle}
                    tone={lowStock > 0 ? 'warning' : 'muted'}
                    label="Low stock alerts"
                    value={lowStock.toString()}
                    sub={`${outOfStock} out of stock`}
                />
                <KpiCard
                    icon={ShoppingCart}
                    tone="info"
                    label={draftListCount > 0 ? 'Draft shopping lists' : 'Inventory value'}
                    value={draftListCount > 0 ? draftListCount.toString() : formatMoneyFromCents(inventoryValueCents)}
                    sub={draftListCount > 0 ? `Inventory value ${formatMoneyFromCents(inventoryValueCents)}` : `${inventory.length} items tracked`}
                />
            </div>

            <div className="rounded-md border bg-card p-3">
                <div className="mb-2 flex items-center justify-between gap-3">
                    <div className="flex items-center gap-2 text-sm font-medium">
                        <Sparkles className="h-4 w-4 text-primary" /> Quick actions
                    </div>
                    <Badge variant="outline" className="capitalize">{site.type} site</Badge>
                </div>
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    {canPlan && (
                        <button
                            type="button"
                            onClick={quickPlanToday}
                            className="flex flex-col items-start gap-1 rounded-md border bg-background p-3 text-left transition hover:border-primary hover:bg-primary/5"
                        >
                            <Utensils className="h-5 w-5 text-primary" />
                            <div className="text-sm font-medium">{isHouse ? "Plan today's meals" : 'Add a meal'}</div>
                            <div className="text-xs text-muted-foreground">{isHouse ? 'Lunch, dinner, snacks' : 'Catered lunch, event'}</div>
                        </button>
                    )}
                    {canAdjust && (
                        <button
                            type="button"
                            onClick={quickReceiveDelivery}
                            className="flex flex-col items-start gap-1 rounded-md border bg-background p-3 text-left transition hover:border-primary hover:bg-primary/5"
                        >
                            <Truck className="h-5 w-5 text-primary" />
                            <div className="text-sm font-medium">Stock arrived</div>
                            <div className="text-xs text-muted-foreground">Add to inventory</div>
                        </button>
                    )}
                    {canAdjust && (
                        <button
                            type="button"
                            onClick={quickStocktake}
                            className="flex flex-col items-start gap-1 rounded-md border bg-background p-3 text-left transition hover:border-primary hover:bg-primary/5"
                        >
                            <ClipboardCheck className="h-5 w-5 text-primary" />
                            <div className="text-sm font-medium">Take stocktake</div>
                            <div className="text-xs text-muted-foreground">Count what's on hand</div>
                        </button>
                    )}
                    {canShop && (
                        <button
                            type="button"
                            onClick={quickGenerateList}
                            className="flex flex-col items-start gap-1 rounded-md border bg-background p-3 text-left transition hover:border-primary hover:bg-primary/5"
                        >
                            <ShoppingCart className="h-5 w-5 text-primary" />
                            <div className="text-sm font-medium">Build shopping list</div>
                            <div className="text-xs text-muted-foreground">From plan + low stock</div>
                        </button>
                    )}
                </div>
            </div>


            <Tabs value={tab} onValueChange={setTab}>
                <TabsList>
                    <TabsTrigger value="calendar"><Calendar className="mr-2 h-4 w-4" /> Calendar</TabsTrigger>
                    <TabsTrigger value="inventory"><Package className="mr-2 h-4 w-4" /> Inventory</TabsTrigger>
                    <TabsTrigger value="shopping"><ShoppingCart className="mr-2 h-4 w-4" /> Shopping</TabsTrigger>
                    <TabsTrigger value="recipes"><ChefHat className="mr-2 h-4 w-4" /> Recipes</TabsTrigger>
                </TabsList>

                <TabsContent value="calendar" className="mt-4">
                    <CalendarGrid
                        weekStart={weekStart}
                        onWeekChange={shiftWeek}
                        entries={entries}
                        onCellClick={openNewMeal}
                        onEntryClick={openExistingMeal}
                    />
                </TabsContent>

                <TabsContent value="inventory" className="mt-4">
                    <InventoryTable
                        siteId={site.id}
                        items={inventory}
                        canAdjust={canAdjust}
                        onOpenAdjust={(i) => setAdjustDialog({ open: true, item: i })}
                        onOpenStocktake={() => setStocktakeOpen(true)}
                        onEditItem={(i) => setAdjustDialog({ open: true, item: i })}
                        onChanged={reloadInventory}
                    />
                </TabsContent>

                <TabsContent value="shopping" className="mt-4">
                    <ShoppingListPanel
                        siteId={site.id}
                        lists={lists}
                        conflictsByList={conflictsByList}
                        canManage={canShop}
                        products={products}
                        onGenerate={() => setGenerateOpen(true)}
                        onChanged={() => { reloadLists(); reloadInventory(); }}
                        onJumpToEntry={(entryId, planDate) => {
                            const entry = entries.find((e) => e.id === entryId);
                            if (entry) {
                                setTab('calendar');
                                setWeekStart(startOfWeek(new Date(planDate)));
                                setTimeout(() => setPlanDialog({ open: true, entry, date: entry.plan_date, slot: entry.meal_slot }), 80);
                            }
                        }}
                    />
                </TabsContent>

                <TabsContent value="recipes" className="mt-4">
                    <RecipesPanel recipes={recipes} />
                </TabsContent>
            </Tabs>

            <Suspense fallback={null}>
                {planDialog.open && (
                    <PlanEntryDialog
                        open={planDialog.open}
                        onClose={() => { setPlanDialog((s) => ({ ...s, open: false })); inertiaReload(); }}
                        siteId={site.id}
                        siteType={site.type}
                        entry={planDialog.entry}
                        initialDate={planDialog.date}
                        initialSlot={planDialog.slot}
                        recipes={recipes}
                        clients={clients}
                    />
                )}
                {adjustDialog.open && (
                    <AdjustInventoryDialog
                        open={adjustDialog.open}
                        onClose={() => { setAdjustDialog({ open: false, item: null }); reloadInventory(); }}
                        siteId={site.id}
                        item={adjustDialog.item}
                        products={products}
                        productCategories={productCategories}
                        reasons={reasons}
                        canCreateProducts={canCreateProducts}
                        onProductCreated={(p, category) => {
                            setProducts((curr) => [...curr, p].sort((a, b) => a.name.localeCompare(b.name)));
                            if (category && !productCategories.includes(category)) {
                                setProductCategories((curr) => [...curr, category].sort());
                            }
                        }}
                    />
                )}
                {stocktakeOpen && (
                    <StocktakeDialog
                        open={stocktakeOpen}
                        onClose={() => { setStocktakeOpen(false); reloadInventory(); }}
                        siteId={site.id}
                        items={inventory}
                    />
                )}
                {generateOpen && (
                    <ShoppingListGenerateDialog
                        open={generateOpen}
                        onClose={() => { setGenerateOpen(false); reloadLists(); }}
                        siteId={site.id}
                    />
                )}
            </Suspense>
        </div>
    );
}

function RecipesPanel({ recipes }: { recipes: RecipeOption[] }) {
    return (
        <div className="space-y-3">
            <p className="text-sm text-muted-foreground">Read-only list. Manage recipes in the <a href="/catering/recipes" className="text-primary underline">Catering library</a>.</p>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {recipes.map((r) => (
                    <a key={r.id} href={`/catering/recipes/${r.id}`} className="rounded-md border p-3 hover:bg-accent">
                        <div className="font-medium">{r.name}</div>
                        <div className="text-xs text-muted-foreground">Serves {r.serves_default}</div>
                    </a>
                ))}
                {recipes.length === 0 && <div className="text-sm text-muted-foreground">No recipes yet — add some in the Catering library.</div>}
            </div>
        </div>
    );
}

type KpiTone = 'primary' | 'success' | 'warning' | 'info' | 'muted';

function KpiCard({
    icon: Icon,
    tone,
    label,
    value,
    sub,
}: {
    icon: React.ComponentType<{ className?: string }>;
    tone: KpiTone;
    label: string;
    value: string;
    sub?: string;
}) {
    const toneClass: Record<KpiTone, string> = {
        primary: 'bg-primary/10 text-primary',
        success: 'bg-emerald-100 text-emerald-700',
        warning: 'bg-amber-100 text-amber-800',
        info: 'bg-sky-100 text-sky-800',
        muted: 'bg-muted text-muted-foreground',
    };
    return (
        <div className="flex items-start gap-3 rounded-md border bg-card p-3">
            <div className={`rounded-md p-2 ${toneClass[tone]}`}>
                <Icon className="h-5 w-5" />
            </div>
            <div className="min-w-0 flex-1">
                <div className="text-xs text-muted-foreground">{label}</div>
                <div className="truncate text-2xl font-semibold">{value}</div>
                {sub && <div className="text-xs text-muted-foreground">{sub}</div>}
            </div>
        </div>
    );
}
