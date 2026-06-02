import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { ArrowUpRight, Building2, CalendarDays, ChefHat, CircleAlert, Clock, Info, LayoutTemplate, Loader2, Package, RotateCcw, ShieldAlert, ShoppingCart, Soup, TriangleAlert, Users, Wallet, X, type LucideIcon } from 'lucide-react';
import { Component, lazy, Suspense, useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { toast } from 'sonner';
import { subscribeAnnounce } from './_announcer';
import CalendarGrid, { OverridesDialog, SpendReportDialog } from './_calendar-grid';
import MealPlannerHero, { MealPlannerToolbar, type HeroNotification, type HeroStats } from './_hero';
import InventoryTable from './_inventory-table';
import RecipesPanel from './_recipes-panel';
import { DietaryTagsManagerDialog, ProductsManagerDialog } from './_library-dialogs';
import ShoppingListPanel from './_shopping-list-panel';
import TemplatesPanel from './_templates-panel';
import {
    addDays,
    buildRecipeMap,
    CORE_SLOTS,
    conflictsFor,
    entryTextureResidents,
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
    const [loadError, setLoadError] = useState<{ kind: 'access' | 'session' | 'generic' } | null>(null);
    const [lastLoadedAt, setLastLoadedAt] = useState<number | null>(null);
    const [reloadingCalendar, setReloadingCalendar] = useState(false);
    const [staleSurfaces, setStaleSurfaces] = useState<Set<string>>(() => new Set());
    const [site, setSite] = useState<SiteInfo>({ id: initialSiteId, name: siteProp?.name ?? 'Meal Planner', type: siteProp?.type ?? 'house', suburb: null, region: null, weekly_food_budget_cents: null });
    const [recipes, setRecipes] = useState<RecipeFull[]>([]);
    const [products, setProducts] = useState<{ id: number; name: string; default_unit: string }[]>([]);
    const [productCategories, setProductCategories] = useState<string[]>([]);
    const [residents, setResidents] = useState<Resident[]>([]);
    const [templates, setTemplates] = useState<import('./_helpers').WeekTemplate[]>([]);
    const [sites, setSites] = useState<SiteSearchItem[]>([]);
    const [iddsiLevels, setIddsiLevels] = useState<IddsiLevel[]>([]);
    const [dietaryTags, setDietaryTags] = useState<{ id: number; label: string; kind: 'allergen' | 'dietary' }[]>([]);
    const [perms, setPerms] = useState({ plan: false, inventory_adjust: false, shopping_manage: false, products_manage: false, recipes_manage: false, tags_manage: false, can_override: false });

    const isHouse = site.type === 'house';
    const [tab, setTab] = useState<SubTab>((siteProp?.type ?? 'house') === 'house' ? 'calendar' : 'inventory');
    const [weekStart, setWeekStart] = useState<Date>(startOfWeek(new Date()));

    const [entries, setEntries] = useState<PlanEntry[]>([]);
    const [weekTotalCents, setWeekTotalCents] = useState(0);
    const [weekCookCents, setWeekCookCents] = useState(0);
    const [weekTakeawayCents, setWeekTakeawayCents] = useState(0);
    const [inventory, setInventory] = useState<InventoryItem[]>([]);
    const [lists, setLists] = useState<ShoppingList[]>([]);

    const [planDialog, setPlanDialog] = useState<{ open: boolean; entry: PlanEntry | null; date: string; slot: MealSlot; prefillRecipeId?: number }>({ open: false, entry: null, date: toIsoDate(weekStart), slot: 'lunch' });
    const [adjustDialog, setAdjustDialog] = useState<{ open: boolean; item: InventoryItem | null }>({ open: false, item: null });
    const [stocktakeOpen, setStocktakeOpen] = useState(false);
    const [generateOpen, setGenerateOpen] = useState(false);
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [spendOpen, setSpendOpen] = useState(false);
    const [overridesOpen, setOverridesOpen] = useState(false);
    const [productsManagerOpen, setProductsManagerOpen] = useState(false);
    const [tagsManagerOpen, setTagsManagerOpen] = useState(false);
    const [onboardingDismissed, setOnboardingDismissed] = useState(false);
    const [announcement, setAnnouncement] = useState('');

    // Mirror critical errors/results into one assertive live region (P2-20).
    useEffect(() => subscribeAnnounce((msg) => {
        setAnnouncement('');
        requestAnimationFrame(() => setAnnouncement(msg));
    }), []);

    const recipeMap = useMemo(() => buildRecipeMap(recipes), [recipes]);

    const markStale = useCallback((surface: string) => setStaleSurfaces((cur) => { const n = new Set(cur); n.add(surface); return n; }), []);
    const clearStale = useCallback((surface: string) => setStaleSurfaces((cur) => { if (!cur.has(surface)) return cur; const n = new Set(cur); n.delete(surface); return n; }), []);

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
            setLoadError(null);
            setLastLoadedAt(Date.now());
        } catch (e) {
            // Fail VISIBLY: a confident, all-zero planner is indistinguishable
            // from a legitimately empty house and silences the safety layer.
            const status = (e as { response?: { status?: number } }).response?.status;
            const kind: 'access' | 'session' | 'generic' = status === 403 ? 'access' : status === 401 || status === 419 ? 'session' : 'generic';
            setLoadError({ kind });
            toast.error(
                kind === 'access'
                    ? "You may not have access to the meal planner for this site."
                    : kind === 'session'
                      ? 'Your session expired — reload to keep planning safely.'
                      : "Couldn't load the meal planner — don't use this view to serve meals until it reloads.",
            );
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
        setReloadingCalendar(true);
        try {
            const [planRes, summaryRes] = await Promise.all([
                axios.get(`/sites/${currentSiteId}/meal-plan`, { params: { week } }),
                axios.get(`/sites/${currentSiteId}/meal-plan/week-summary`, { params: { week } }),
            ]);
            setEntries(planRes.data.entries ?? []);
            setWeekTotalCents(summaryRes.data.total_cost_cents ?? 0);
            setWeekCookCents(summaryRes.data.cook_cost_cents ?? 0);
            setWeekTakeawayCents(summaryRes.data.takeaway_cost_cents ?? 0);
            clearStale('calendar');
        } catch {
            markStale('calendar');
            toast.error("Couldn't refresh meals for this week — showing last loaded data.");
        } finally {
            setReloadingCalendar(false);
        }
    }, [currentSiteId, weekStart, markStale, clearStale]);

    const reloadInventory = useCallback(async () => {
        if (!currentSiteId) return;
        try {
            const res = await axios.get(`/sites/${currentSiteId}/meal-inventory`);
            setInventory(res.data.items ?? []);
            clearStale('inventory');
        } catch {
            markStale('inventory');
            toast.error("Couldn't refresh inventory — showing last loaded data.");
        }
    }, [currentSiteId, markStale, clearStale]);

    const reloadLists = useCallback(async () => {
        if (!currentSiteId) return;
        try {
            const res = await axios.get(`/sites/${currentSiteId}/meal-shopping-lists`);
            setLists(res.data.lists ?? []);
            clearStale('shopping');
        } catch {
            markStale('shopping');
            toast.error("Couldn't refresh shopping lists — showing last loaded data.");
        }
    }, [currentSiteId, markStale, clearStale]);

    const reloadTemplates = useCallback(async () => {
        if (!currentSiteId) return;
        try {
            const res = await axios.get(`/sites/${currentSiteId}/meal-templates`);
            setTemplates(res.data.templates ?? []);
            clearStale('templates');
        } catch {
            markStale('templates');
            toast.error("Couldn't refresh templates — showing last loaded data.");
        }
    }, [currentSiteId, markStale, clearStale]);

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
        let softWarnings = 0;
        let textureEntries = 0;
        entries.forEach((e) => {
            const c = conflictsFor(e, residents, recipeMap);
            if (c.hard.length && !e.allergen_override_at) unresolved++;
            if (c.soft.length) softWarnings++;
            if (entryTextureResidents(e, residents).length) textureEntries++;
        });
        const textureModified = residents.filter((r) => r.texture != null && r.texture.level < 7).length;

        return { mealsPlanned, served, overrides, weekCostCents: weekTotalCents, cookCostCents: weekCookCents, takeawayCostCents: weekTakeawayCents, lowStock, outOfStock, itemsTracked, fillPct, unresolved, textureModified, textureEntries, softWarnings };
    }, [entries, inventory, weekStart, isHouse, residents, recipeMap, weekTotalCents, weekCookCents, weekTakeawayCents]);

    const notifications: HeroNotification[] = useMemo(() => {
        const out: HeroNotification[] = [];
        if (isHouse && stats.unresolved > 0)
            out.push({ id: 'allergen', icon: ShieldAlert, tone: 'critical', label: `${stats.unresolved} allergen conflict${stats.unresolved === 1 ? '' : 's'} to resolve`, tab: 'calendar' });
        if (isHouse && stats.textureEntries > 0)
            out.push({ id: 'texture', icon: Soup, tone: 'warning', label: `${stats.textureEntries} meal${stats.textureEntries === 1 ? '' : 's'} need a texture check`, tab: 'calendar' });
        if (isHouse && stats.softWarnings > 0)
            out.push({ id: 'soft', icon: TriangleAlert, tone: 'warning', label: `${stats.softWarnings} meal${stats.softWarnings === 1 ? '' : 's'} with a soft warning`, tab: 'calendar' });
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
        const today = new Date();
        const hour = today.getHours();
        // Time-appropriate slot (mirrors SLOT_TIME), not always lunch.
        const slot: MealSlot = hour >= 15 ? 'dinner' : hour >= 12 ? 'lunch' : 'breakfast';
        // Don't bounce the user off a week that already contains today.
        if (toIsoDate(weekStart) !== toIsoDate(startOfWeek(today))) setWeekStart(startOfWeek(today));
        setPlanDialog({ open: true, entry: null, date: toIsoDate(today), slot });
    }

    function openBuildList() {
        setTab('shopping');
        setGenerateOpen(true);
    }

    if (!bootstrapped) {
        return <PlannerSkeleton standalone={standalone} />;
    }
    // Fail visibly — never render a zeroed hero / conflict banner over a failed load.
    if (loadError) {
        return <PlannerLoadError kind={loadError.kind} onRetry={() => { bootstrap(); reloadCalendar(); }} />;
    }
    // Legitimate "no site selected" is not an error.
    if (!currentSiteId) {
        return <PlannerSelectSite />;
    }

    return (
        <div className="space-y-5">
            <div className="sr-only" role="status" aria-live="assertive">{announcement}</div>
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
                    weekStart={weekStart}
                    lastLoadedAt={lastLoadedAt}
                    reloading={reloadingCalendar}
                    canPlan={perms.plan}
                    canShop={perms.shopping_manage}
                    onSelectSite={selectSite}
                    onSelectWeek={(d) => setWeekStart(startOfWeek(d))}
                    onNotificationClick={(t) => setTab(t as SubTab)}
                    onPlan={planToday}
                    onBuildList={openBuildList}
                    onOpenSettings={() => setSettingsOpen(true)}
                    onOpenSpend={() => setSpendOpen(true)}
                    onOpenOverrides={() => setOverridesOpen(true)}
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
                    weekStart={weekStart}
                    reloading={reloadingCalendar}
                    onSelectWeek={(d) => setWeekStart(startOfWeek(d))}
                    onPlan={planToday}
                    onBuildList={openBuildList}
                    onOpenSettings={() => setSettingsOpen(true)}
                    onOpenSpend={() => setSpendOpen(true)}
                    onOpenOverrides={() => setOverridesOpen(true)}
                    onPrevWeek={() => shiftWeek(-7)}
                    onNextWeek={() => shiftWeek(7)}
                    onThisWeek={() => shiftWeek(0)}
                    onReviewConflicts={() => setTab('calendar')}
                    onNotificationClick={(t) => setTab(t as SubTab)}
                />
            )}

            {isHouse && residents.length === 0 && !onboardingDismissed && (
                <ZeroResidentOnboarding siteId={site.id} onDismiss={() => setOnboardingDismissed(true)} onSetBudget={() => setSettingsOpen(true)} onPlanMeals={() => setTab('calendar')} />
            )}

            <SubTabs tab={tab} onChange={setTab} isHouse={isHouse} />

            <div key={`${tab}-${site.type}`} role="tabpanel" id={`mp-panel-${tab}`} aria-labelledby={`mp-tab-${tab}`} tabIndex={0} aria-busy={tab === 'calendar' && reloadingCalendar} className="focus-visible:outline-none">
                {tab === 'calendar' && staleSurfaces.has('calendar') && <StaleStrip onRetry={reloadCalendar} />}
                {tab === 'inventory' && staleSurfaces.has('inventory') && <StaleStrip onRetry={reloadInventory} />}
                {tab === 'shopping' && staleSurfaces.has('shopping') && <StaleStrip onRetry={reloadLists} />}
                {tab === 'templates' && staleSurfaces.has('templates') && <StaleStrip onRetry={reloadTemplates} />}
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
                        cookCostCents={weekCookCents}
                        takeawayCostCents={weekTakeawayCents}
                        canPlan={perms.plan}
                        weekLabel={weekLabel}
                        rangeLabel={`${rangeStart} → ${rangeEnd}`}
                        onCellClick={openNewMeal}
                        onEntryClick={openExistingMeal}
                        onChanged={reloadCalendar}
                        onTemplatesChanged={reloadTemplates}
                        onResidentSaved={() => { bootstrap(); reloadCalendar(); }}
                        onOpenSettings={() => setSettingsOpen(true)}
                        onOpenSpend={() => setSpendOpen(true)}
                    />
                )}
                {tab === 'inventory' && (
                    <InventoryTable
                        siteId={site.id}
                        items={inventory}
                        canAdjust={perms.inventory_adjust}
                        canManageProducts={perms.products_manage}
                        onOpenAdjust={(i) => setAdjustDialog({ open: true, item: i })}
                        onOpenStocktake={() => setStocktakeOpen(true)}
                        onAddItem={() => setAdjustDialog({ open: true, item: null })}
                        onManageProducts={() => setProductsManagerOpen(true)}
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
                        canManageTags={perms.tags_manage}
                        canPlan={perms.plan}
                        onPlanRecipe={(recipeId) => setPlanDialog({ open: true, entry: null, date: toIsoDate(new Date()), slot: 'dinner', prefillRecipeId: recipeId })}
                        onManageTags={() => setTagsManagerOpen(true)}
                        onChanged={bootstrap}
                    />
                )}
                {tab === 'templates' && isHouse && (
                    <TemplatesPanel
                        siteId={site.id}
                        templates={templates}
                        recipes={recipes}
                        residents={residents}
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

            <DialogErrorBoundary onError={() => toast.error("Couldn't open — please reload the page.")}>
            <Suspense fallback={<DialogFallback />}>
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
                    <ShoppingListGenerateDialog open={generateOpen} onClose={() => { setGenerateOpen(false); reloadLists(); reloadInventory(); }} siteId={site.id} weekStart={weekStart} mealsPlanned={stats.mealsPlanned} />
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
            </DialogErrorBoundary>

            <ProductsManagerDialog
                open={productsManagerOpen}
                onClose={() => setProductsManagerOpen(false)}
                onChanged={() => {
                    bootstrap();
                    reloadInventory();
                }}
            />
            <DietaryTagsManagerDialog open={tagsManagerOpen} onClose={() => setTagsManagerOpen(false)} onChanged={bootstrap} />

            {spendOpen && <SpendReportDialog siteId={site.id} currentWeekCents={weekTotalCents} budgetCents={site.weekly_food_budget_cents} onClose={() => setSpendOpen(false)} />}
            {overridesOpen && <OverridesDialog entries={entries} residents={residents} recipes={recipeMap} onOpenEntry={openExistingMeal} onClose={() => setOverridesOpen(false)} />}
        </div>
    );
}

/* ── Lazy-dialog feedback (P1-8) ───────────────────────────────────────── */
function DialogFallback() {
    return (
        <div className="fixed inset-0 z-[200] flex items-center justify-center bg-black/10" role="status" aria-live="polite">
            <div className="flex items-center gap-2 rounded-lg border border-border bg-popover px-4 py-3 text-sm text-muted-foreground shadow-float">
                <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" /> Opening…
            </div>
        </div>
    );
}

class DialogErrorBoundary extends Component<{ children: ReactNode; onError: () => void }, { hasError: boolean }> {
    state = { hasError: false };
    static getDerivedStateFromError() {
        return { hasError: true };
    }
    componentDidCatch() {
        this.props.onError();
    }
    render() {
        return this.state.hasError ? null : this.props.children;
    }
}

/* ── Loading / error / empty surfaces ──────────────────────────────────── */
function PlannerSkeleton({ standalone }: { standalone: boolean }) {
    return (
        <div className="space-y-5">
            {standalone ? (
                <div className="h-44 animate-pulse rounded-2xl bg-muted" />
            ) : (
                <div className="h-16 animate-pulse rounded-xl border border-border bg-muted/60" />
            )}
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                {Array.from({ length: 4 }).map((_, i) => (
                    <div key={i} className="h-20 animate-pulse rounded-xl bg-muted/60" />
                ))}
            </div>
            <div className="h-10 animate-pulse rounded-xl bg-muted/60" />
            <div className="h-[420px] animate-pulse rounded-xl border border-border bg-muted/40" />
            <span className="sr-only" role="status">Loading meal planner…</span>
        </div>
    );
}

function PlannerLoadError({ kind, onRetry }: { kind: 'access' | 'session' | 'generic'; onRetry: () => void }) {
    const body =
        kind === 'access'
            ? 'You may not have access, or this feature needs enabling for your account.'
            : kind === 'session'
              ? 'Your session expired. Reload the page to sign back in.'
              : "Some data didn't load — don't use this view to serve meals until it reloads.";
    return (
        <div role="alert" className="flex flex-col items-center justify-center gap-3 rounded-2xl border border-status-critical/30 bg-status-critical-bg/40 px-6 py-12 text-center">
            <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-status-critical-bg text-status-critical">
                <TriangleAlert className="h-7 w-7" />
            </span>
            <div>
                <div className="text-[16px] font-semibold text-foreground">We couldn't load the meal planner</div>
                <p className="mx-auto mt-1 max-w-md text-[13.5px] text-muted-foreground">{body}</p>
            </div>
            <div className="flex flex-wrap justify-center gap-2">
                <Button onClick={onRetry}><RotateCcw className="mr-1.5 h-4 w-4" /> Try again</Button>
                {kind === 'session' && (
                    <Button variant="outline" onClick={() => { if (typeof window !== 'undefined') window.location.reload(); }}>Reload page</Button>
                )}
            </div>
        </div>
    );
}

function PlannerSelectSite() {
    return (
        <div className="flex flex-col items-center justify-center gap-3 rounded-2xl border border-dashed border-border bg-card px-6 py-12 text-center">
            <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-sites-bg text-sites-deep">
                <Building2 className="h-7 w-7" />
            </span>
            <div>
                <div className="text-[16px] font-semibold text-foreground">Select a site</div>
                <p className="mx-auto mt-1 max-w-md text-[13.5px] text-muted-foreground">Choose a house or office to plan meals, track inventory and build shopping lists.</p>
            </div>
        </div>
    );
}

function ZeroResidentOnboarding({ siteId, onDismiss, onSetBudget, onPlanMeals }: { siteId: number; onDismiss: () => void; onSetBudget: () => void; onPlanMeals: () => void }) {
    const stepClass = 'flex items-start gap-2.5';
    const numClass = 'mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary/15 text-[11px] font-bold text-primary';
    return (
        <div className="relative rounded-xl border border-primary/30 bg-primary/5 p-4 pr-9">
            <button type="button" onClick={onDismiss} aria-label="Dismiss setup guide" className="absolute right-2 top-2 flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground">
                <X className="h-4 w-4" />
            </button>
            <div className="flex items-start gap-3">
                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary text-primary-foreground"><Info className="h-5 w-5" /></span>
                <div className="min-w-0 flex-1">
                    <div className="text-[14.5px] font-semibold text-foreground">Finish setting up this house to plan meals safely.</div>
                    <p className="mt-0.5 text-[12.5px] text-muted-foreground">No residents are linked yet, so allergen and texture checks are paused.</p>
                    <ol className="mt-3 space-y-2 text-[13px]">
                        <li className={stepClass}>
                            <span className={numClass}>1</span>
                            <span className="min-w-0">
                                <Link href={`/sites/${siteId}`} className="inline-flex items-center gap-1 font-medium text-primary hover:underline">
                                    Add residents &amp; their dietary needs <ArrowUpRight className="h-3.5 w-3.5" />
                                </Link>
                                <span className="ml-1 text-muted-foreground">— on the site profile</span>
                            </span>
                        </li>
                        <li className={stepClass}>
                            <span className={numClass}>2</span>
                            <span className="min-w-0">
                                <button type="button" onClick={onSetBudget} className="inline-flex items-center gap-1 font-medium text-primary hover:underline"><Wallet className="h-3.5 w-3.5" /> Set a weekly food budget</button>
                            </span>
                        </li>
                        <li className={stepClass}>
                            <span className={numClass}>3</span>
                            <span className="min-w-0">
                                <button type="button" onClick={onPlanMeals} className="inline-flex items-center gap-1 font-medium text-primary hover:underline"><CalendarDays className="h-3.5 w-3.5" /> Plan meals or apply a template</button>
                            </span>
                        </li>
                    </ol>
                </div>
            </div>
        </div>
    );
}

function StaleStrip({ onRetry }: { onRetry: () => void }) {
    return (
        <div role="status" className="mb-2 flex items-center gap-2 rounded-lg border border-status-warning/40 bg-status-warning-bg/60 px-3 py-1.5 text-[12.5px] font-medium text-status-warning">
            <TriangleAlert className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            <span className="flex-1">Showing data from before your last action — it didn't refresh.</span>
            <button type="button" onClick={onRetry} className="shrink-0 rounded-md border border-status-warning/50 px-2 py-0.5 text-[11.5px] font-semibold transition-colors hover:bg-status-warning/10">Retry</button>
        </div>
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
    const btnRefs = useRef<(HTMLButtonElement | null)[]>([]);

    function onKeyDown(e: React.KeyboardEvent, idx: number) {
        const count = ordered.length;
        let next = -1;
        if (e.key === 'ArrowRight') next = (idx + 1) % count;
        else if (e.key === 'ArrowLeft') next = (idx - 1 + count) % count;
        else if (e.key === 'Home') next = 0;
        else if (e.key === 'End') next = count - 1;
        else return;
        e.preventDefault();
        onChange(ordered[next].value);
        btnRefs.current[next]?.focus();
    }

    return (
        <div role="tablist" aria-label="Meal planner sections" className="flex items-center gap-1 overflow-x-auto rounded-xl border border-border bg-card p-1 shadow-sm">
            {ordered.map((it, i) => {
                const active = it.value === tab;
                const Icon = it.icon;
                return (
                    <button
                        key={it.value}
                        ref={(el) => { btnRefs.current[i] = el; }}
                        type="button"
                        role="tab"
                        id={`mp-tab-${it.value}`}
                        aria-selected={active}
                        aria-controls={`mp-panel-${it.value}`}
                        tabIndex={active ? 0 : -1}
                        onClick={() => onChange(it.value)}
                        onKeyDown={(e) => onKeyDown(e, i)}
                        className={cn(
                            'inline-flex min-h-10 flex-1 items-center justify-center gap-2 whitespace-nowrap rounded-lg px-3 py-2 text-[13.5px] font-medium transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 sm:flex-none',
                            active ? 'bg-primary/10 text-primary shadow-sm' : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                        )}
                    >
                        <Icon className="h-[15px] w-[15px]" /> {it.label}
                    </button>
                );
            })}
        </div>
    );
}
