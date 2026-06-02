import { cn } from '@/lib/utils';
import { router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { CalendarDays, ChefHat, CircleAlert, Clock, LayoutTemplate, Package, ShieldAlert, ShoppingCart, TriangleAlert, type LucideIcon } from 'lucide-react';
import { lazy, Suspense, useCallback, useEffect, useMemo, useState } from 'react';
import CalendarGrid from './_calendar-grid';
import MealPlannerHero, { MealPlannerToolbar, type HeroNotification, type HeroStats } from './_hero';
import InventoryTable from './_inventory-table';
import RecipesPanel from './_recipes-panel';
import ShoppingListPanel from './_shopping-list-panel';
import TemplatesPanel from './_templates-panel';
import {
    addDays,
    buildRecipeMap,
    CORE_SLOTS,
    conflictsFor,
    isoWeekNo,
    startOfWeek,
    toIsoDate,
    toNum,
    type IddsiLevel,
    type InventoryItem,
    type MealSlot,
    type PlanEntry,
    type RecipeFull,
    type Resident,
    type ShoppingList,
    type SiteInfo,
    type SiteSearchItem,
} from './_helpers';

const PlanEntryDialog = lazy(() => import('./_dialogs').then((m) => ({ default: m.PlanEntryDialog })));
const AdjustInventoryDialog = lazy(() => import('./_dialogs').then((m) => ({ default: m.AdjustInventoryDialog })));
const StocktakeDialog = lazy(() => import('./_dialogs').then((m) => ({ default: m.StocktakeDialog })));
const ShoppingListGenerateDialog = lazy(() => import('./_dialogs').then((m) => ({ default: m.ShoppingListGenerateDialog })));
const SettingsDialog = lazy(() => import('./_dialogs').then((m) => ({ default: m.SettingsDialog })));

type Props = {
    /** Fixed site (embedded in a Site profile). */
    site?: { id: number; name: string; type: string };
    /** 'embedded' = inside the Site profile (no banner); 'standalone' = the /catering page (brand hero + site switcher). */
    mode?: 'standalone' | 'embedded';
    /** Initial site for standalone mode (the /catering page). */
    defaultSiteId?: number;
};

type SubTab = 'calendar' | 'inventory' | 'shopping' | 'recipes' | 'templates';

export default function MealPlannerSubTabs({ site: siteProp, mode = 'embedded', defaultSiteId }: Props) {
    const page = usePage<{ auth?: { user?: { name?: string } } }>();
    const firstName = (page.props.auth?.user?.name ?? 'there').split(' ')[0];
    const standalone = mode === 'standalone';

    const initialSiteId = siteProp?.id ?? defaultSiteId ?? 0;
    const [currentSiteId, setCurrentSiteId] = useState(initialSiteId);

    const [bootstrapped, setBootstrapped] = useState(false);
    const [site, setSite] = useState<SiteInfo>({ id: initialSiteId, name: siteProp?.name ?? 'Meal Planner', type: siteProp?.type ?? 'house', suburb: null, region: null, weekly_food_budget_cents: null });
    const [recipes, setRecipes] = useState<RecipeFull[]>([]);
    const [products, setProducts] = useState<{ id: number; name: string; default_unit: string }[]>([]);
    const [productCategories, setProductCategories] = useState<string[]>([]);
    const [residents, setResidents] = useState<Resident[]>([]);
    const [templates, setTemplates] = useState<import('./_helpers').WeekTemplate[]>([]);
    const [sites, setSites] = useState<SiteSearchItem[]>([]);
    const [iddsiLevels, setIddsiLevels] = useState<IddsiLevel[]>([]);
    const [dietaryTags, setDietaryTags] = useState<{ id: number; label: string; kind: 'allergen' | 'dietary' }[]>([]);
    const [perms, setPerms] = useState({ plan: false, inventory_adjust: false, shopping_manage: false, products_manage: false, recipes_manage: false, can_override: false });

    const isHouse = site.type === 'house';
    const [tab, setTab] = useState<SubTab>((siteProp?.type ?? 'house') === 'house' ? 'calendar' : 'inventory');
    const [weekStart, setWeekStart] = useState<Date>(startOfWeek(new Date()));

    const [entries, setEntries] = useState<PlanEntry[]>([]);
    const [weekTotalCents, setWeekTotalCents] = useState(0);
    const [inventory, setInventory] = useState<InventoryItem[]>([]);
    const [lists, setLists] = useState<ShoppingList[]>([]);

    const [planDialog, setPlanDialog] = useState<{ open: boolean; entry: PlanEntry | null; date: string; slot: MealSlot; prefillRecipeId?: number }>({ open: false, entry: null, date: toIsoDate(weekStart), slot: 'lunch' });
    const [adjustDialog, setAdjustDialog] = useState<{ open: boolean; item: InventoryItem | null }>({ open: false, item: null });
    const [stocktakeOpen, setStocktakeOpen] = useState(false);
    const [generateOpen, setGenerateOpen] = useState(false);
    const [settingsOpen, setSettingsOpen] = useState(false);

    const recipeMap = useMemo(() => buildRecipeMap(recipes), [recipes]);

    const bootstrap = useCallback(async () => {
        if (!currentSiteId) {
            setBootstrapped(true);
            return;
        }
        try {
            const res = await axios.get(`/sites/${currentSiteId}/meal-planner/bootstrap`);
            const nextSite = res.data.site as SiteInfo | undefined;
            if (nextSite) {
                setSite(nextSite);
                // Standalone: when switching to an office, leave house-only tabs.
                if (standalone && nextSite.type !== 'house') {
                    setTab((t) => (t === 'calendar' || t === 'templates' ? 'inventory' : t));
                }
            }
            setRecipes(res.data.recipes ?? []);
            setProducts(res.data.products ?? []);
            setProductCategories(res.data.product_categories ?? []);
            setResidents(res.data.clients ?? []);
            setTemplates(res.data.templates ?? []);
            setSites(res.data.sites ?? []);
            setIddsiLevels(res.data.iddsi_levels ?? []);
            setDietaryTags(res.data.dietary_tags ?? []);
            setPerms((p) => ({ ...p, ...(res.data.permissions ?? {}) }));
        } catch {
            /* swallow — render with empties */
        } finally {
            setBootstrapped(true);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [currentSiteId, standalone]);

    useEffect(() => {
        bootstrap();
    }, [bootstrap]);

    const reloadCalendar = useCallback(async () => {
        if (!currentSiteId) return;
        const week = toIsoDate(weekStart);
        const [planRes, summaryRes] = await Promise.all([
            axios.get(`/sites/${currentSiteId}/meal-plan`, { params: { week } }),
            axios.get(`/sites/${currentSiteId}/meal-plan/week-summary`, { params: { week } }),
        ]);
        setEntries(planRes.data.entries ?? []);
        setWeekTotalCents(summaryRes.data.total_cost_cents ?? 0);
    }, [currentSiteId, weekStart]);

    const reloadInventory = useCallback(async () => {
        if (!currentSiteId) return;
        const res = await axios.get(`/sites/${currentSiteId}/meal-inventory`);
        setInventory(res.data.items ?? []);
    }, [currentSiteId]);

    const reloadLists = useCallback(async () => {
        if (!currentSiteId) return;
        const res = await axios.get(`/sites/${currentSiteId}/meal-shopping-lists`);
        setLists(res.data.lists ?? []);
    }, [currentSiteId]);

    const reloadTemplates = useCallback(async () => {
        if (!currentSiteId) return;
        const res = await axios.get(`/sites/${currentSiteId}/meal-templates`);
        setTemplates(res.data.templates ?? []);
    }, [currentSiteId]);

    useEffect(() => {
        reloadCalendar();
    }, [reloadCalendar]);
    useEffect(() => {
        reloadInventory();
    }, [reloadInventory]);
    useEffect(() => {
        reloadLists();
    }, [reloadLists]);

    /* ── derived stats + notifications ──────────────────────────────────── */
    const stats: HeroStats = useMemo(() => {
        const mealsPlanned = entries.length;
        const served = entries.filter((e) => !!e.served_at).length;
        const overrides = entries.filter((e) => !!e.allergen_override_at).length;
        const itemsTracked = inventory.length;
        const outOfStock = inventory.filter((i) => toNum(i.current_qty) <= 0).length;
        const lowStock = inventory.filter((i) => {
            const cur = toNum(i.current_qty);
            const reorder = i.reorder_level == null ? null : toNum(i.reorder_level);
            return cur > 0 && reorder != null && cur <= reorder;
        }).length;
        const atPar = inventory.filter((i) => {
            const cur = toNum(i.current_qty);
            const reorder = i.reorder_level == null ? null : toNum(i.reorder_level);
            return cur > 0 && (reorder == null || cur > reorder);
        }).length;

        const days = Array.from({ length: 7 }, (_, i) => toIsoDate(addDays(weekStart, i)));
        let filled = 0;
        days.forEach((di) => CORE_SLOTS.forEach((s) => {
            if (entries.some((e) => e.plan_date.slice(0, 10) === di && e.meal_slot === s)) filled++;
        }));
        const fillPct = isHouse ? Math.round((filled / 21) * 100) : itemsTracked ? Math.round((atPar / itemsTracked) * 100) : 0;

        let unresolved = 0;
        entries.forEach((e) => {
            const c = conflictsFor(e, residents, recipeMap);
            if (c.hard.length && !e.allergen_override_at) unresolved++;
        });

        return { mealsPlanned, served, overrides, weekCostCents: weekTotalCents, lowStock, outOfStock, itemsTracked, fillPct, unresolved };
    }, [entries, inventory, weekStart, isHouse, residents, recipeMap, weekTotalCents]);

    const notifications: HeroNotification[] = useMemo(() => {
        const out: HeroNotification[] = [];
        if (isHouse && stats.unresolved > 0)
            out.push({ id: 'allergen', icon: ShieldAlert, tone: 'critical', label: `${stats.unresolved} allergen conflict${stats.unresolved === 1 ? '' : 's'} to resolve`, tab: 'calendar' });
        if (stats.outOfStock > 0) out.push({ id: 'out', icon: CircleAlert, tone: 'critical', label: `${stats.outOfStock} item${stats.outOfStock === 1 ? '' : 's'} out of stock`, tab: 'inventory' });
        if (stats.lowStock > 0) out.push({ id: 'low', icon: TriangleAlert, tone: 'warning', label: `${stats.lowStock} item${stats.lowStock === 1 ? '' : 's'} below par`, tab: 'inventory' });
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const expiring = inventory.filter((i) => {
            if (!i.expiry_date || toNum(i.current_qty) <= 0) return false;
            const days = (new Date(i.expiry_date).getTime() - today.getTime()) / 86400000;
            return days <= 3;
        });
        if (expiring.length > 0)
            out.push({ id: 'expiring', icon: Clock, tone: 'warning', label: `${expiring.length} item${expiring.length === 1 ? '' : 's'} expiring soon`, sub: expiring.map((i) => i.product?.name).filter(Boolean).join(', '), tab: 'inventory' });
        return out;
    }, [stats, isHouse, inventory]);

    /* ── week label ──────────────────────────────────────────────────────── */
    const rangeStart = weekStart.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' });
    const rangeEnd = addDays(weekStart, 6).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' });
    const weekLabel = `Wk ${isoWeekNo(weekStart)}`;
    const isThisWeek = toIsoDate(weekStart) === toIsoDate(startOfWeek(new Date()));

    /* ── handlers ────────────────────────────────────────────────────────── */
    function selectSite(id: number) {
        if (id === currentSiteId) return;
        if (standalone) {
            // Switch in place on the /catering page and keep the URL shareable.
            setCurrentSiteId(id);
            if (typeof window !== 'undefined') window.history.replaceState({}, '', `/catering?site=${id}`);
        } else {
            router.visit(`/sites/${id}`, { data: { tab: 'meal-planner' } });
        }
    }

    function shiftWeek(delta: number) {
        if (delta === 0) setWeekStart(startOfWeek(new Date()));
        else setWeekStart((w) => addDays(w, delta));
    }

    function openNewMeal(date: string, slot: MealSlot) {
        if (!perms.plan) return;
        setPlanDialog({ open: true, entry: null, date, slot });
    }

    function openExistingMeal(entry: PlanEntry) {
        setPlanDialog({ open: true, entry, date: entry.plan_date, slot: entry.meal_slot });
    }

    function planToday() {
        setTab('calendar');
        setWeekStart(startOfWeek(new Date()));
        setPlanDialog({ open: true, entry: null, date: toIsoDate(new Date()), slot: 'lunch' });
    }

    function openBuildList() {
        setTab('shopping');
        setGenerateOpen(true);
    }

    return bootstrapped ? (
        <div className="space-y-5">
            {standalone ? (
                <MealPlannerHero
                    site={site}
                    firstName={firstName}
                    weekLabel={weekLabel}
                    rangeStart={rangeStart}
                    rangeEnd={rangeEnd}
                    isThisWeek={isThisWeek}
                    isHouse={isHouse}
                    residentCount={residents.length}
                    stats={stats}
                    sites={sites}
                    notifications={notifications}
                    canPlan={perms.plan}
                    canShop={perms.shopping_manage}
                    onSelectSite={selectSite}
                    onNotificationClick={(t) => setTab(t as SubTab)}
                    onPlan={planToday}
                    onBuildList={openBuildList}
                    onOpenSettings={() => setSettingsOpen(true)}
                    onPrevWeek={() => shiftWeek(-7)}
                    onNextWeek={() => shiftWeek(7)}
                    onThisWeek={() => shiftWeek(0)}
                    onReviewConflicts={() => setTab('calendar')}
                />
            ) : (
                <MealPlannerToolbar
                    weekLabel={weekLabel}
                    rangeStart={rangeStart}
                    rangeEnd={rangeEnd}
                    isThisWeek={isThisWeek}
                    isHouse={isHouse}
                    stats={stats}
                    notifications={notifications}
                    canPlan={perms.plan}
                    canShop={perms.shopping_manage}
                    onPlan={planToday}
                    onBuildList={openBuildList}
                    onOpenSettings={() => setSettingsOpen(true)}
                    onPrevWeek={() => shiftWeek(-7)}
                    onNextWeek={() => shiftWeek(7)}
                    onThisWeek={() => shiftWeek(0)}
                    onReviewConflicts={() => setTab('calendar')}
                    onNotificationClick={(t) => setTab(t as SubTab)}
                />
            )}

            <SubTabs tab={tab} onChange={setTab} isHouse={isHouse} />

            <div key={`${tab}-${site.type}`}>
                {tab === 'calendar' && isHouse && (
                    <CalendarGrid
                        siteId={site.id}
                        siteName={site.name}
                        weekStart={weekStart}
                        entries={entries}
                        residents={residents}
                        recipes={recipes}
                        recipeMap={recipeMap}
                        templates={templates}
                        iddsiLevels={iddsiLevels}
                        dietaryTags={dietaryTags}
                        budgetCents={site.weekly_food_budget_cents}
                        canPlan={perms.plan}
                        weekLabel={weekLabel}
                        rangeLabel={`${rangeStart} → ${rangeEnd}`}
                        onCellClick={openNewMeal}
                        onEntryClick={openExistingMeal}
                        onChanged={reloadCalendar}
                        onTemplatesChanged={reloadTemplates}
                        onResidentSaved={() => { bootstrap(); reloadCalendar(); }}
                        onOpenSettings={() => setSettingsOpen(true)}
                    />
                )}
                {tab === 'inventory' && (
                    <InventoryTable
                        siteId={site.id}
                        items={inventory}
                        canAdjust={perms.inventory_adjust}
                        onOpenAdjust={(i) => setAdjustDialog({ open: true, item: i })}
                        onOpenStocktake={() => setStocktakeOpen(true)}
                        onAddItem={() => setAdjustDialog({ open: true, item: null })}
                        onChanged={reloadInventory}
                    />
                )}
                {tab === 'shopping' && (
                    <ShoppingListPanel
                        siteId={site.id}
                        site={site}
                        lists={lists}
                        canManage={perms.shopping_manage}
                        products={products}
                        onGenerate={() => setGenerateOpen(true)}
                        onChanged={() => {
                            reloadLists();
                            reloadInventory();
                        }}
                    />
                )}
                {tab === 'recipes' && (
                    <RecipesPanel
                        siteId={site.id}
                        siteName={site.name}
                        recipes={recipes}
                        inventory={inventory}
                        products={products}
                        tags={dietaryTags}
                        canManage={perms.recipes_manage}
                        canPlan={perms.plan}
                        onPlanRecipe={(recipeId) => setPlanDialog({ open: true, entry: null, date: toIsoDate(new Date()), slot: 'dinner', prefillRecipeId: recipeId })}
                        onChanged={bootstrap}
                    />
                )}
                {tab === 'templates' && isHouse && (
                    <TemplatesPanel
                        siteId={site.id}
                        templates={templates}
                        recipes={recipes}
                        weekLabel={weekLabel}
                        rangeLabel={`${rangeStart} → ${rangeEnd}`}
                        weekStart={weekStart}
                        canManage={perms.plan}
                        onChanged={reloadTemplates}
                        onApplied={() => {
                            reloadCalendar();
                            setTab('calendar');
                        }}
                    />
                )}
            </div>

            <Suspense fallback={null}>
                {planDialog.open && (
                    <PlanEntryDialog
                        open={planDialog.open}
                        onClose={() => {
                            setPlanDialog((s) => ({ ...s, open: false }));
                            reloadCalendar();
                            reloadInventory();
                        }}
                        siteId={site.id}
                        siteType={site.type}
                        entry={planDialog.entry}
                        initialDate={planDialog.date}
                        initialSlot={planDialog.slot}
                        initialRecipeId={planDialog.prefillRecipeId}
                        recipes={recipes}
                        residents={residents}
                        canOverride={perms.can_override}
                    />
                )}
                {adjustDialog.open && (
                    <AdjustInventoryDialog
                        open={adjustDialog.open}
                        onClose={() => {
                            setAdjustDialog({ open: false, item: null });
                            reloadInventory();
                        }}
                        siteId={site.id}
                        item={adjustDialog.item}
                        products={products}
                        productCategories={productCategories}
                        canCreateProducts={perms.products_manage}
                        onProductCreated={(p, category) => {
                            setProducts((curr) => [...curr, p].sort((a, b) => a.name.localeCompare(b.name)));
                            if (category && !productCategories.includes(category)) setProductCategories((curr) => [...curr, category].sort());
                        }}
                    />
                )}
                {stocktakeOpen && (
                    <StocktakeDialog open={stocktakeOpen} onClose={() => { setStocktakeOpen(false); reloadInventory(); }} siteId={site.id} items={inventory} />
                )}
                {generateOpen && (
                    <ShoppingListGenerateDialog open={generateOpen} onClose={() => { setGenerateOpen(false); reloadLists(); reloadInventory(); }} siteId={site.id} weekStart={weekStart} />
                )}
                {settingsOpen && (
                    <SettingsDialog
                        open={settingsOpen}
                        onClose={() => { setSettingsOpen(false); bootstrap(); }}
                        siteId={site.id}
                        budgetCents={site.weekly_food_budget_cents}
                        templates={templates}
                        recipes={recipes}
                        weekStart={weekStart}
                        entries={entries}
                        canManage={perms.shopping_manage}
                        onTemplatesChanged={reloadTemplates}
                    />
                )}
            </Suspense>
        </div>
    ) : (
        <div className="rounded-2xl border border-border bg-card p-10 text-center text-sm text-muted-foreground">Loading meal planner…</div>
    );
}

function SubTabs({ tab, onChange, isHouse }: { tab: SubTab; onChange: (t: SubTab) => void; isHouse: boolean }) {
    const items: { value: SubTab; label: string; icon: LucideIcon }[] = [
        { value: 'calendar', label: 'Calendar', icon: CalendarDays },
        { value: 'inventory', label: 'Inventory', icon: Package },
        { value: 'shopping', label: 'Shopping', icon: ShoppingCart },
        { value: 'recipes', label: 'Recipes', icon: ChefHat },
        { value: 'templates', label: 'Templates', icon: LayoutTemplate },
    ];
    const ordered = isHouse ? items : [items[1], items[2], items[3]];
    return (
        <div className="flex items-center gap-1 overflow-x-auto rounded-xl border border-border bg-card p-1 shadow-sm">
            {ordered.map((it) => {
                const active = it.value === tab;
                const Icon = it.icon;
                return (
                    <button
                        key={it.value}
                        type="button"
                        onClick={() => onChange(it.value)}
                        className={cn(
                            'inline-flex flex-1 items-center justify-center gap-2 whitespace-nowrap rounded-lg px-3 py-2 text-[13.5px] font-medium transition-all sm:flex-none',
                            active ? 'bg-sites text-primary-foreground shadow-sm' : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                        )}
                    >
                        <Icon className="h-[15px] w-[15px]" /> {it.label}
                    </button>
                );
            })}
        </div>
    );
}
