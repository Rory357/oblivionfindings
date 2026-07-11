import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import axios from 'axios';
import { CalendarPlus, Check, ChefHat, Clock, Leaf, Loader2, Minus, Pencil, Plus, RotateCcw, Search, ShieldAlert, ShoppingCart, Tags, Users } from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import { formatMoneyFromCents as money, toNum, type InventoryItem, type RecipeFull, type RecipeIngredient } from './_helpers';
import RecipeEditDialog from './_recipe-edit-dialog';
import { Card as GuardrailCard } from '@/components/ui/card';

type Props = {
    siteId: number;
    siteName: string;
    recipes: RecipeFull[];
    inventory: InventoryItem[];
    products: { id: number; name: string; default_unit: string }[];
    tags: { id: number; label: string; kind: 'allergen' | 'dietary' }[];
    canManage: boolean;
    canManageTags?: boolean;
    canPlan: boolean;
    onPlanRecipe: (recipeId: number) => void;
    onManageTags?: () => void;
    onChanged: () => void;
};

type Scope = 'all' | 'shared' | 'house';

function stockStatus(needed: number, item: InventoryItem | undefined): 'in' | 'short' | 'out' | 'untracked' {
    if (!item) return 'untracked';
    const cur = toNum(item.current_qty);
    if (cur <= 0) return 'out';
    if (cur < needed) return 'short';
    return 'in';
}

function recipeStockSummary(recipe: RecipeFull, inventory: InventoryItem[], scale: number) {
    const invByProduct = new Map(inventory.map((i) => [i.product_id, i]));
    let toBuy = 0;
    let estCents = 0;
    for (const ing of recipe.ingredients) {
        if (ing.product_id == null) continue;
        const needed = ing.qty * scale;
        const item = invByProduct.get(ing.product_id);
        const status = stockStatus(needed, item);
        if (status === 'short' || status === 'out') {
            toBuy += 1;
            if (item?.product.cost_per_unit_cents) {
                const cur = item ? toNum(item.current_qty) : 0;
                estCents += Math.round(Math.max(0, needed - cur) * item.product.cost_per_unit_cents);
            }
        }
    }
    return { toBuy, estCents };
}

export default function RecipesPanel({ siteId, siteName, recipes, inventory, products, tags, canManage, canManageTags, canPlan, onPlanRecipe, onManageTags, onChanged }: Props) {
    const [scope, setScope] = useState<Scope>('all');
    const [cat, setCat] = useState<string>('all');
    const [q, setQ] = useState('');
    const [view, setView] = useState<RecipeFull | null>(null);
    const [editor, setEditor] = useState<{ open: boolean; recipe: RecipeFull | null }>({ open: false, recipe: null });

    const categories = useMemo(() => Array.from(new Set(recipes.map((r) => r.category).filter((c): c is string => !!c))).sort(), [recipes]);

    const filtered = useMemo(() => {
        const needle = q.trim().toLowerCase();
        return recipes.filter((r) => {
            if (scope !== 'all' && r.scope !== scope) return false;
            if (cat !== 'all' && r.category !== cat) return false;
            if (needle && !`${r.name} ${r.tags.map((t) => t.label).join(' ')}`.toLowerCase().includes(needle)) return false;
            return true;
        });
    }, [recipes, scope, cat, q]);

    const scopes: { value: Scope; label: string }[] = [
        { value: 'all', label: 'All' },
        { value: 'shared', label: 'Shared library' },
        { value: 'house', label: 'This house' },
    ];

    const filtersActive = q.trim() !== '' || cat !== 'all' || scope !== 'all';
    function clearFilters() {
        setQ('');
        setCat('all');
        setScope('all');
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="relative w-full max-w-xs">
                    <Search className="absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search recipes…" className="pl-8" />
                </div>
                <div className="flex items-center gap-2">
                    <GuardrailCard unstyled className="inline-flex rounded-lg border border-border bg-card p-0.5">
                        {scopes.map((s) => (
                            <Button unstyled key={s.value} type="button" onClick={() => setScope(s.value)} className={cn('rounded-md px-3 py-1.5 text-[12.5px] font-medium transition-colors', scope === s.value ? 'bg-sites text-primary-foreground' : 'text-muted-foreground hover:text-foreground')}>
                                {s.label}
                            </Button>
                        ))}
                    </GuardrailCard>
                    {canManageTags && (
                        <Button unstyled type="button" onClick={onManageTags} className="inline-flex h-9 items-center gap-1.5 rounded-md border border-border bg-card px-3 text-sm font-medium text-foreground transition hover:bg-accent">
                            <Tags className="h-4 w-4" /> Manage tags
                        </Button>
                    )}
                    {canManage && (
                        <Button unstyled type="button" onClick={() => setEditor({ open: true, recipe: null })} className="inline-flex h-9 items-center gap-1.5 rounded-md bg-sites px-3 text-sm font-semibold text-primary-foreground transition hover:opacity-90">
                            <Plus className="h-4 w-4" /> Add recipe
                        </Button>
                    )}
                </div>
            </div>

            <p className="text-[12.5px] text-muted-foreground">
                The <strong className="font-medium text-foreground">shared library</strong> is your org-wide starting point — nothing is locked. A house can use a recipe as-is, or add house-only recipes with <strong className="font-medium text-foreground">Add recipe</strong>.
            </p>

            {categories.length > 0 && (
                <div className="flex flex-wrap items-center gap-1.5">
                    {['all', ...categories].map((c) => (
                        <Button unstyled
                            key={c}
                            type="button"
                            onClick={() => setCat(c)}
                            className={cn(
                                'rounded-full border px-3 py-1 text-[12px] font-medium transition-colors',
                                cat === c ? 'border-sites bg-sites/10 text-sites' : 'border-border bg-card text-muted-foreground hover:bg-accent',
                            )}
                        >
                            {c === 'all' ? 'All recipes' : c}
                        </Button>
                    ))}
                </div>
            )}

            {filtered.length === 0 ? (
                filtersActive ? (
                    <GuardrailCard unstyled className="flex flex-col items-center gap-2.5 rounded-xl border border-dashed border-border bg-card px-6 py-12 text-center">
                        <div className="text-sm font-medium text-foreground">No recipes match your filters</div>
                        <Button variant="ghost" size="sm" onClick={clearFilters}>Clear filters</Button>
                    </GuardrailCard>
                ) : (
                    <GuardrailCard unstyled className="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border bg-card px-6 py-12 text-center">
                        <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-sites-bg text-sites-deep"><ChefHat className="h-7 w-7" /></span>
                        <div>
                            <div className="text-[15px] font-semibold text-foreground">No recipes yet</div>
                            <p className="mx-auto mt-1 max-w-md text-[13px] text-muted-foreground">Build your house's first recipe, or activate one from the shared library.</p>
                            <p className="mx-auto mt-1 max-w-md text-[12px] text-muted-foreground">Some recipes may be saved as drafts — activate them to plan from them.</p>
                        </div>
                        {canManage && (
                            <Button size="sm" onClick={() => setEditor({ open: true, recipe: null })}><Plus className="mr-1.5 h-4 w-4" /> Add recipe</Button>
                        )}
                    </GuardrailCard>
                )
            ) : (
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {filtered.map((r) => {
                        const { toBuy } = recipeStockSummary(r, inventory, 1);
                        const totalTime = (r.prep_minutes ?? 0) + (r.cook_minutes ?? 0);
                        return (
                            <Button unstyled key={r.id} type="button" onClick={() => setView(r)} className="group flex flex-col rounded-xl border border-border bg-card p-4 text-left shadow-sm transition-all hover:border-sites/50 hover:shadow-md">
                                <div className="flex items-start justify-between gap-2">
                                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-sites-bg text-sites-deep"><ChefHat className="h-5 w-5" /></span>
                                    <div className="flex items-center gap-1.5">
                                        {r.category && <span className="rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium text-muted-foreground">{r.category}</span>}
                                        <span className={cn('rounded-full px-2 py-0.5 text-[10px] font-semibold', r.scope === 'house' ? 'bg-sites-bg text-sites-deep' : 'bg-muted text-muted-foreground')}>{r.scope === 'house' ? 'This house' : 'Shared library'}</span>
                                    </div>
                                </div>
                                <div className="mt-2.5 text-[14.5px] font-semibold leading-tight text-foreground">{r.name}</div>
                                <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11.5px] text-muted-foreground">
                                    <span className="inline-flex items-center gap-1"><Users className="h-3 w-3" /> Serves {r.serves_default}</span>
                                    {totalTime > 0 && <span className="inline-flex items-center gap-1"><Clock className="h-3 w-3" /> {totalTime}m</span>}
                                    <span>{r.ingredients.length} ingredient{r.ingredients.length === 1 ? '' : 's'}</span>
                                </div>
                                {r.tags.length > 0 && (
                                    <div className="mt-2 flex flex-wrap gap-1">
                                        {r.tags.slice(0, 4).map((t) => (
                                            <span key={t.id} className={cn('rounded-full px-1.5 py-px text-[10px] font-medium', t.kind === 'allergen' ? (t.severity === 'critical' ? 'bg-status-critical font-semibold text-white' : 'bg-status-critical-bg text-status-critical') : 'bg-sites-bg text-sites-deep')}>{t.label}</span>
                                        ))}
                                    </div>
                                )}
                                <div className="mt-auto pt-3">
                                    <span className={cn('inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium', toBuy === 0 ? 'bg-status-success-bg text-status-success' : 'bg-status-warning-bg text-status-warning')}>
                                        {toBuy === 0 ? <><Check className="h-3 w-3" /> All in stock</> : <><ShoppingCart className="h-3 w-3" /> {toBuy} to buy</>}
                                    </span>
                                </div>
                            </Button>
                        );
                    })}
                </div>
            )}

            {view && (
                <RecipeDetailDialog
                    siteId={siteId}
                    recipe={view}
                    inventory={inventory}
                    canManage={canManage}
                    canPlan={canPlan}
                    onClose={() => setView(null)}
                    onEdit={() => {
                        const r = view;
                        setView(null);
                        setEditor({ open: true, recipe: r });
                    }}
                    onPlan={() => {
                        onPlanRecipe(view.id);
                        setView(null);
                    }}
                />
            )}

            <RecipeEditDialog
                open={editor.open}
                recipe={editor.recipe}
                products={products}
                tags={tags}
                siteId={siteId}
                siteName={siteName}
                canManage={canManage}
                onClose={() => setEditor({ open: false, recipe: null })}
                onSaved={onChanged}
            />
        </div>
    );
}

function RecipeDetailDialog({ siteId, recipe, inventory, canManage, canPlan, onClose, onEdit, onPlan }: { siteId: number; recipe: RecipeFull; inventory: InventoryItem[]; canManage: boolean; canPlan: boolean; onClose: () => void; onEdit: () => void; onPlan: () => void }) {
    const [servings, setServings] = useState(recipe.serves_default);
    const scale = servings / Math.max(1, recipe.serves_default);
    const invByProduct = useMemo(() => new Map(inventory.map((i) => [i.product_id, i])), [inventory]);
    const { toBuy, estCents } = recipeStockSummary(recipe, inventory, scale);
    const inStock = recipe.ingredients.filter((i) => i.product_id != null).length - toBuy;
    const allergenTags = recipe.tags.filter((t) => t.kind === 'allergen');
    const dietaryTags = recipe.tags.filter((t) => t.kind === 'dietary');
    const [adding, setAdding] = useState(false);
    const [addProgress, setAddProgress] = useState<{ current: number; total: number } | null>(null);
    const [addFailures, setAddFailures] = useState<RecipeIngredient[]>([]);

    function buyableIngredients(): RecipeIngredient[] {
        return recipe.ingredients.filter((ing) => {
            if (ing.product_id == null) return true;
            const needed = ing.qty * scale;
            const status = stockStatus(needed, invByProduct.get(ing.product_id));
            return status === 'short' || status === 'out' || status === 'untracked';
        });
    }

    async function addToShopping(only?: RecipeIngredient[]) {
        const items = only ?? buyableIngredients();
        if (items.length === 0) {
            toast.info('Everything is already in stock');
            return;
        }
        setAdding(true);
        setAddFailures([]);
        try {
            const listsRes = await axios.get(`/sites/${siteId}/meal-shopping-lists`);
            let draft = (listsRes.data.lists ?? []).find((l: ShoppingDraft) => l.status === 'draft') as ShoppingDraft | undefined;
            if (!draft) {
                const today = new Date();
                const end = new Date();
                end.setDate(today.getDate() + 6);
                await axios.post(`/sites/${siteId}/meal-shopping-lists/generate`, {
                    covers_from: today.toISOString().slice(0, 10),
                    covers_to: end.toISOString().slice(0, 10),
                    include_restock_to_par: false,
                });
                const again = await axios.get(`/sites/${siteId}/meal-shopping-lists`);
                draft = (again.data.lists ?? []).find((l: ShoppingDraft) => l.status === 'draft') as ShoppingDraft | undefined;
            }
            if (!draft) {
                toast.error('Could not open a draft list');
                return;
            }
            // Add each ingredient independently so one failure doesn't strand the rest.
            const failed: RecipeIngredient[] = [];
            let done = 0;
            setAddProgress({ current: 0, total: items.length });
            for (const ing of items) {
                try {
                    await axios.post(`/sites/${siteId}/meal-shopping-lists/${draft.id}/items`, {
                        product_id: ing.product_id ?? null,
                        free_text_name: ing.product_id == null ? ing.name : null,
                        needed_qty: Math.round(ing.qty * scale * 100) / 100,
                        unit: ing.unit,
                    });
                } catch {
                    failed.push(ing);
                } finally {
                    done += 1;
                    setAddProgress({ current: done, total: items.length });
                }
            }
            const added = items.length - failed.length;
            if (failed.length === 0) {
                toast.success(`Added ${added} item${added === 1 ? '' : 's'} to shopping list`);
                setAddFailures([]);
            } else {
                setAddFailures(failed);
                toast.error(`Added ${added} of ${items.length} — ${failed.length} couldn't be added`);
            }
        } catch {
            toast.error('Could not add to shopping list');
        } finally {
            setAdding(false);
            setAddProgress(null);
        }
    }

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2"><ChefHat className="h-4 w-4 text-sites" /> {recipe.name}</DialogTitle>
                    <DialogDescription>
                        <span className={cn('rounded-full px-1.5 py-px text-[10px] font-semibold', recipe.scope === 'house' ? 'bg-sites-bg text-sites-deep' : 'bg-muted text-muted-foreground')}>{recipe.scope === 'house' ? 'This house' : 'Shared library'}</span>
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-4">
                    {/* Allergen + dietary tags — allergens never flattened into low-contrast text (P2-4/P2-8). */}
                    <div className="space-y-1.5 rounded-xl border border-border bg-muted/20 p-3">
                        <div className="flex flex-wrap items-center gap-1.5">
                            <span className="inline-flex items-center gap-1 text-[11px] font-semibold uppercase tracking-wide text-status-critical"><ShieldAlert className="h-3 w-3" /> Contains allergens:</span>
                            {allergenTags.length === 0 ? (
                                <span className="text-[12px] text-muted-foreground">No allergens tagged</span>
                            ) : (
                                allergenTags.map((t) => (
                                    <span key={t.id} className={cn('inline-flex items-center gap-1 rounded-full px-2 py-px text-[11px] font-medium', t.severity === 'critical' ? 'bg-status-critical text-white' : 'bg-status-critical-bg text-status-critical')}>
                                        {t.label}{t.severity === 'critical' && <span className="rounded-full bg-white/25 px-1 text-[8.5px] font-bold uppercase">Critical</span>}
                                    </span>
                                ))
                            )}
                        </div>
                        {dietaryTags.length > 0 && (
                            <div className="flex flex-wrap items-center gap-1.5">
                                <span className="inline-flex items-center gap-1 text-[11px] font-semibold uppercase tracking-wide text-sites-deep"><Leaf className="h-3 w-3" /> Dietary:</span>
                                {dietaryTags.map((t) => <span key={t.id} className="rounded-full bg-sites-bg px-2 py-px text-[11px] font-medium text-sites-deep">{t.label}</span>)}
                            </div>
                        )}
                    </div>

                    {/* serving scaler + stock summary */}
                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-muted/30 p-3">
                        <div className="flex items-center gap-2">
                            <span className="text-[12px] font-medium text-muted-foreground">Servings</span>
                            <div className="flex items-center gap-1">
                                <Button size="icon" variant="outline" className="h-7 w-7" onClick={() => setServings((s) => Math.max(1, s - 1))}><Minus className="h-3.5 w-3.5" /></Button>
                                <span className="w-8 text-center text-sm font-semibold tabular-nums">{servings}</span>
                                <Button size="icon" variant="outline" className="h-7 w-7" onClick={() => setServings((s) => s + 1)}><Plus className="h-3.5 w-3.5" /></Button>
                            </div>
                        </div>
                        <div className="flex items-center gap-4 text-[12px]">
                            <span className="text-status-success">{inStock} in stock</span>
                            <span className="text-status-warning">{toBuy} to buy</span>
                            <span className="font-semibold text-foreground">≈ {money(estCents)}</span>
                        </div>
                    </div>

                    {/* ingredients & stock check */}
                    <div>
                        <div className="mb-1.5 text-[12px] font-semibold uppercase tracking-wide text-muted-foreground">Ingredients &amp; stock check</div>
                        <div className="overflow-hidden rounded-xl border border-border">
                            <table className="w-full text-sm">
                                <thead className="border-b border-border bg-muted/40 text-[11px] uppercase tracking-wide text-muted-foreground">
                                    <tr>
                                        <th className="px-3 py-2 text-left font-semibold">Ingredient</th>
                                        <th className="px-3 py-2 text-right font-semibold">Needed</th>
                                        <th className="px-3 py-2 text-right font-semibold">On hand</th>
                                        <th className="px-3 py-2 text-right font-semibold">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {recipe.ingredients.map((ing, idx) => {
                                        const needed = ing.qty * scale;
                                        const item = ing.product_id != null ? invByProduct.get(ing.product_id) : undefined;
                                        const status = ing.product_id == null ? 'untracked' : stockStatus(needed, item);
                                        const badge: Record<string, { label: string; cls: string }> = {
                                            in: { label: 'In stock', cls: 'text-status-success' },
                                            short: { label: 'Short', cls: 'text-status-warning' },
                                            out: { label: 'Out', cls: 'text-status-critical' },
                                            untracked: { label: 'Not tracked', cls: 'text-muted-foreground' },
                                        };
                                        return (
                                            <tr key={idx} className="border-b border-border last:border-b-0">
                                                <td className="px-3 py-2 font-medium text-foreground">{ing.name}</td>
                                                <td className="px-3 py-2 text-right tabular-nums">{Math.round(needed * 100) / 100} {ing.unit}</td>
                                                <td className="px-3 py-2 text-right tabular-nums text-muted-foreground">{item ? `${toNum(item.current_qty)} ${item.unit}` : '—'}</td>
                                                <td className={cn('px-3 py-2 text-right font-medium', badge[status].cls)}>{badge[status].label}</td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                        {toBuy > 0 && (
                            <Button variant="outline" size="sm" className="mt-2" disabled={adding} onClick={() => addToShopping()}>
                                {adding ? (
                                    <><Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> Adding {addProgress ? `${addProgress.current} of ${addProgress.total}` : ''}…</>
                                ) : (
                                    <><ShoppingCart className="mr-1.5 h-3.5 w-3.5" /> Add {toBuy} to shopping list</>
                                )}
                            </Button>
                        )}
                        {addFailures.length > 0 && (
                            <div className="mt-2 rounded-lg border border-status-warning/40 bg-status-warning-bg/40 p-2.5 text-[12px]">
                                <div className="font-medium text-status-warning">{addFailures.length} item{addFailures.length === 1 ? '' : 's'} couldn't be added:</div>
                                <ul className="mt-1 list-inside list-disc text-muted-foreground">
                                    {addFailures.map((f, i) => <li key={i}>{f.name || 'item'}</li>)}
                                </ul>
                                <Button variant="outline" size="sm" className="mt-2" disabled={adding} onClick={() => addToShopping(addFailures)}>
                                    <RotateCcw className="mr-1.5 h-3.5 w-3.5" /> Retry remaining
                                </Button>
                            </div>
                        )}
                    </div>

                    {recipe.instructions && (
                        <div>
                            <div className="mb-1 text-[12px] font-semibold uppercase tracking-wide text-muted-foreground">Method</div>
                            <p className="whitespace-pre-line text-[13px] leading-relaxed text-foreground">{recipe.instructions}</p>
                        </div>
                    )}
                </div>
                <DialogFooter className="sm:justify-between">
                    {canManage ? (
                        <Button unstyled type="button" onClick={onEdit} className="inline-flex items-center gap-1.5 text-sm font-medium text-muted-foreground hover:text-foreground">
                            <Pencil className="h-3.5 w-3.5" /> Edit recipe
                        </Button>
                    ) : (
                        <span />
                    )}
                    <div className="flex gap-2">
                        <Button variant="outline" onClick={onClose}>Close</Button>
                        {canPlan && <Button onClick={onPlan}><CalendarPlus className="mr-1.5 h-4 w-4" /> Plan this meal</Button>}
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

type ShoppingDraft = { id: number; status: string };
