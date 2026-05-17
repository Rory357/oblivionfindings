import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { router, useForm } from '@inertiajs/react';
import axios from 'axios';
import { AlertTriangle, ChefHat, Info, Lock, ShieldAlert, ShoppingBag, Utensils } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { ConfirmAction } from '../_confirm-action';
import { MEAL_SLOTS, SLOT_LABEL, formatQty, type InventoryItem, type MealSlot, type PlanEntry, type RecipeOption, type SourceType } from './_helpers';

type ClientOption = { id: number; name: string };
type ProductOpt = { id: number; name: string; default_unit: string };

type ConflictMatch = {
    label: string;
    severity: 'critical' | 'warn' | 'dislike' | 'info';
    kind: 'allergen' | 'dietary' | 'dislike';
    source: 'recipe_tag' | 'product_tag' | 'ingredient_name' | 'recipe_name' | 'product_match';
};

type ConflictPanel = {
    client_id: number;
    client_name: string;
    matches: ConflictMatch[];
};

type ConflictReport = {
    has_hard_blocks: boolean;
    has_soft_warnings: boolean;
    hard_blocks: ConflictPanel[];
    soft_warnings: ConflictPanel[];
    recipe_tag_ids: number[];
};

const EMPTY_REPORT: ConflictReport = {
    has_hard_blocks: false,
    has_soft_warnings: false,
    hard_blocks: [],
    soft_warnings: [],
    recipe_tag_ids: [],
};

const OVERRIDE_MIN_CHARS = 10;

export function PlanEntryDialog({
    open,
    onClose,
    siteId,
    siteType,
    entry,
    initialDate,
    initialSlot,
    recipes,
    clients,
}: {
    open: boolean;
    onClose: () => void;
    siteId: number;
    siteType: string;
    entry: PlanEntry | null;
    initialDate: string;
    initialSlot: MealSlot;
    recipes: RecipeOption[];
    clients: ClientOption[];
}) {
    const isNew = !entry;
    const existingOverrideReason = entry?.allergen_override_reason ?? '';

    const initialSource: SourceType = entry?.source_type
        ?? (entry?.takeaway_vendor ? 'takeaway' : entry?.recipe_id ? 'recipe' : 'ad_hoc');

    const initialTakeawayCost = entry?.takeaway_cost_cents != null
        ? (entry.takeaway_cost_cents / 100).toFixed(2)
        : '';

    const form = useForm({
        plan_date: (entry?.plan_date ?? initialDate).slice(0, 10),
        meal_slot: (entry?.meal_slot ?? initialSlot) as MealSlot,
        source_type: initialSource,
        recipe_id: entry?.recipe_id ?? null,
        ad_hoc_name: entry?.ad_hoc_name ?? '',
        takeaway_vendor: entry?.takeaway_vendor ?? '',
        takeaway_cost: initialTakeawayCost,
        takeaway_reference: entry?.takeaway_reference ?? '',
        servings: entry?.servings ?? 4,
        notes: entry?.notes ?? '',
        client_ids: entry?.client_ids ?? [],
        allergen_override_reason: '' as string,
    });

    const [report, setReport] = useState<ConflictReport>(EMPTY_REPORT);
    const [reportLoading, setReportLoading] = useState(false);
    const [acknowledgedSoft, setAcknowledgedSoft] = useState(false);
    const [pastVendors, setPastVendors] = useState<string[]>([]);

    useEffect(() => {
        if (!open) return;
        form.setData({
            plan_date: (entry?.plan_date ?? initialDate).slice(0, 10),
            meal_slot: (entry?.meal_slot ?? initialSlot) as MealSlot,
            source_type: initialSource,
            recipe_id: entry?.recipe_id ?? null,
            ad_hoc_name: entry?.ad_hoc_name ?? '',
            takeaway_vendor: entry?.takeaway_vendor ?? '',
            takeaway_cost: initialTakeawayCost,
            takeaway_reference: entry?.takeaway_reference ?? '',
            servings: entry?.servings ?? 4,
            notes: entry?.notes ?? '',
            client_ids: entry?.client_ids ?? [],
            allergen_override_reason: '',
        });
        setReport(EMPTY_REPORT);
        setAcknowledgedSoft(false);
        // Lazy-load past vendors on open so autocomplete is ready
        axios.get(`/sites/${siteId}/meal-planner/takeaway-vendors`).then((res) => {
            setPastVendors(res.data?.vendors ?? []);
        }).catch(() => setPastVendors([]));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, entry?.id]);

    // Live conflict preview — debounced. Only meaningful for recipe-backed meals.
    useEffect(() => {
        if (!open) return;
        if (form.data.source_type !== 'recipe') {
            setReport(EMPTY_REPORT);
            return;
        }
        const recipeId = form.data.recipe_id;
        const clientIds = form.data.client_ids ?? [];
        if (!recipeId || clientIds.length === 0) {
            setReport(EMPTY_REPORT);
            return;
        }
        let cancelled = false;
        setReportLoading(true);
        const timer = setTimeout(() => {
            axios.post(`/sites/${siteId}/meal-planner/check-conflicts`, {
                recipe_id: recipeId,
                client_ids: clientIds,
            }).then((res) => {
                if (cancelled) return;
                setReport(res.data ?? EMPTY_REPORT);
                setAcknowledgedSoft(false);
            }).catch(() => {
                if (cancelled) return;
                setReport(EMPTY_REPORT);
            }).finally(() => {
                if (!cancelled) setReportLoading(false);
            });
        }, 300);
        return () => {
            cancelled = true;
            clearTimeout(timer);
        };
    }, [open, siteId, form.data.source_type, form.data.recipe_id, JSON.stringify(form.data.client_ids)]);

    const showClients = siteType === 'house';

    const hasHard = report.has_hard_blocks;
    const hasSoft = report.has_soft_warnings;
    const overrideValid = form.data.allergen_override_reason.trim().length >= OVERRIDE_MIN_CHARS;
    const saveDisabled = form.processing || (hasHard && !overrideValid) || (hasSoft && !acknowledgedSoft && !hasHard);

    function submit(e: React.FormEvent) {
        e.preventDefault();
        if (saveDisabled) return;
        const onSuccess = () => onClose();
        const onError = () => {
            // server may have detected a conflict the client missed —
            // re-run the preview so the warning surfaces
            if (form.data.recipe_id && (form.data.client_ids ?? []).length > 0) {
                axios.post(`/sites/${siteId}/meal-planner/check-conflicts`, {
                    recipe_id: form.data.recipe_id,
                    client_ids: form.data.client_ids,
                }).then((res) => setReport(res.data ?? EMPTY_REPORT));
            }
        };
        if (isNew) {
            form.post(`/sites/${siteId}/meal-plan`, { onSuccess, onError, preserveScroll: true });
        } else {
            form.put(`/sites/${siteId}/meal-plan/${entry!.id}`, { onSuccess, onError, preserveScroll: true });
        }
    }

    function destroy() {
        if (!entry) return;
        router.delete(`/sites/${siteId}/meal-plan/${entry.id}`, {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    }

    function markServed() {
        if (!entry) return;
        router.post(`/sites/${siteId}/meal-plan/${entry.id}/serve`, {}, {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    }

    function toggleClient(id: number) {
        const current = form.data.client_ids ?? [];
        form.setData('client_ids', current.includes(id) ? current.filter((x) => x !== id) : [...current, id]);
    }

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-xl">
                <DialogHeader>
                    <DialogTitle>{isNew ? 'Add meal' : 'Edit meal'}</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    {!isNew && entry?.allergen_override_at && existingOverrideReason && (
                        <div className="flex items-start gap-2 rounded-md border border-red-200 bg-red-50/60 p-2 text-xs text-red-900">
                            <Lock className="mt-0.5 h-3.5 w-3.5 flex-none" />
                            <div>
                                <div className="font-medium">Allergen override on file</div>
                                <div className="mt-0.5">&ldquo;{existingOverrideReason}&rdquo;</div>
                                {entry.allergen_override_by && (
                                    <div className="mt-0.5 text-red-800/80">by {entry.allergen_override_by.name} · {new Date(entry.allergen_override_at).toLocaleString('en-NZ')}</div>
                                )}
                            </div>
                        </div>
                    )}

                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <Label>Date</Label>
                            <Input type="date" value={form.data.plan_date} onChange={(e) => form.setData('plan_date', e.target.value)} required />
                        </div>
                        <div>
                            <Label>Meal slot</Label>
                            <Select value={form.data.meal_slot} onValueChange={(v) => form.setData('meal_slot', v as MealSlot)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    {MEAL_SLOTS.map((s) => <SelectItem key={s} value={s}>{SLOT_LABEL[s]}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    {/* Source mode toggle — recipe / ad-hoc / takeaway */}
                    <div>
                        <Label className="mb-2 block">Meal type</Label>
                        <div className="grid grid-cols-3 gap-2">
                            {([
                                { key: 'recipe', label: 'From recipe', icon: ChefHat, desc: 'Cooked from a saved recipe' },
                                { key: 'ad_hoc', label: 'Ad-hoc cook', icon: Utensils, desc: 'Cooked without a recipe' },
                                { key: 'takeaway', label: 'Takeaway', icon: ShoppingBag, desc: 'Ordered in from a vendor' },
                            ] as { key: SourceType; label: string; icon: typeof ChefHat; desc: string }[]).map((m) => {
                                const ModeIcon = m.icon;
                                const isActive = form.data.source_type === m.key;
                                return (
                                    <button
                                        key={m.key}
                                        type="button"
                                        onClick={() => {
                                            form.setData('source_type', m.key);
                                            // Clear fields that don't belong to the new mode
                                            if (m.key !== 'recipe') form.setData('recipe_id', null);
                                            if (m.key !== 'ad_hoc') form.setData('ad_hoc_name', '');
                                            if (m.key !== 'takeaway') {
                                                form.setData('takeaway_vendor', '');
                                                form.setData('takeaway_cost', '');
                                                form.setData('takeaway_reference', '');
                                            }
                                        }}
                                        className={`flex flex-col items-start gap-1 rounded-md border p-2 text-left transition ${isActive ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'hover:bg-accent'}`}
                                    >
                                        <ModeIcon className={`h-4 w-4 ${isActive ? 'text-primary' : 'text-muted-foreground'}`} />
                                        <div className="text-xs font-medium">{m.label}</div>
                                        <div className="text-[10px] text-muted-foreground">{m.desc}</div>
                                    </button>
                                );
                            })}
                        </div>
                    </div>

                    {form.data.source_type === 'recipe' && (
                        <div>
                            <Label>Recipe</Label>
                            <Select
                                value={form.data.recipe_id ? String(form.data.recipe_id) : ''}
                                onValueChange={(v) => form.setData('recipe_id', v === '' ? null : Number(v))}
                            >
                                <SelectTrigger><SelectValue placeholder="Pick a recipe" /></SelectTrigger>
                                <SelectContent>
                                    {recipes.map((r) => <SelectItem key={r.id} value={String(r.id)}>{r.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>
                    )}

                    {form.data.source_type === 'ad_hoc' && (
                        <>
                            <div>
                                <Label>Ad-hoc meal name</Label>
                                <Input value={form.data.ad_hoc_name} onChange={(e) => form.setData('ad_hoc_name', e.target.value)} placeholder="e.g. Cheese toasties" />
                            </div>
                            <div className="flex items-start gap-2 rounded-md border border-muted bg-muted/30 p-2 text-xs text-muted-foreground">
                                <Info className="mt-0.5 h-3.5 w-3.5 flex-none" />
                                <span>No allergen check available for ad-hoc meals — check ingredients carefully before serving.</span>
                            </div>
                        </>
                    )}

                    {form.data.source_type === 'takeaway' && (
                        <>
                            <div className="grid grid-cols-12 gap-3">
                                <div className="col-span-12 sm:col-span-6">
                                    <Label>Vendor</Label>
                                    <Input
                                        list={pastVendors.length ? 'past-takeaway-vendors' : undefined}
                                        value={form.data.takeaway_vendor}
                                        onChange={(e) => form.setData('takeaway_vendor', e.target.value)}
                                        placeholder="e.g. Hell Pizza, Sushi Train…"
                                        required
                                    />
                                    {pastVendors.length > 0 && (
                                        <datalist id="past-takeaway-vendors">
                                            {pastVendors.map((v) => <option key={v} value={v} />)}
                                        </datalist>
                                    )}
                                </div>
                                <div className="col-span-7 sm:col-span-3">
                                    <Label>Cost (NZD)</Label>
                                    <div className="relative">
                                        <span className="pointer-events-none absolute left-2 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">$</span>
                                        <Input
                                            type="number"
                                            step="0.01"
                                            min={0}
                                            value={form.data.takeaway_cost}
                                            onChange={(e) => form.setData('takeaway_cost', e.target.value)}
                                            placeholder="0.00"
                                            className="pl-6"
                                        />
                                    </div>
                                </div>
                                <div className="col-span-5 sm:col-span-3">
                                    <Label>Order ref</Label>
                                    <Input
                                        value={form.data.takeaway_reference}
                                        onChange={(e) => form.setData('takeaway_reference', e.target.value)}
                                        placeholder="#12345"
                                    />
                                </div>
                            </div>
                            <div className="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 p-2 text-xs text-amber-900">
                                <Info className="mt-0.5 h-3.5 w-3.5 flex-none" />
                                <span><strong>Takeaway meal</strong> — allergen check unavailable. Confirm dietary suitability with the vendor before serving.</span>
                            </div>
                        </>
                    )}
                    <div>
                        <Label>Servings</Label>
                        <Input type="number" min={1} value={form.data.servings} onChange={(e) => form.setData('servings', Number(e.target.value))} />
                    </div>
                    <div>
                        <Label>Notes</Label>
                        <Textarea value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} rows={2} />
                    </div>
                    {showClients && clients.length > 0 && (
                        <div>
                            <Label>Residents (allergens are checked against these)</Label>
                            <div className="mt-1 max-h-32 overflow-y-auto rounded-md border p-2">
                                {clients.map((c) => {
                                    const selected = (form.data.client_ids ?? []).includes(c.id);
                                    return (
                                        <label key={c.id} className="flex items-center gap-2 py-1 text-sm">
                                            <input type="checkbox" checked={selected} onChange={() => toggleClient(c.id)} />
                                            {c.name}
                                        </label>
                                    );
                                })}
                            </div>
                        </div>
                    )}

                    {reportLoading && (
                        <div className="text-xs text-muted-foreground">Checking dietary conflicts…</div>
                    )}

                    {hasHard && (
                        <div className="rounded-md border-2 border-red-400 bg-red-50 p-3 text-sm">
                            <div className="mb-2 flex items-center gap-2 font-semibold text-red-900">
                                <ShieldAlert className="h-4 w-4" /> Allergy alert — override required to save
                            </div>
                            <ul className="space-y-1 text-red-900">
                                {report.hard_blocks.map((panel) => (
                                    <li key={panel.client_id}>
                                        <strong>{panel.client_name}</strong>:{' '}
                                        {panel.matches.map((m) => m.label).join(', ')}
                                    </li>
                                ))}
                            </ul>
                            <div className="mt-3">
                                <Label className="text-xs font-medium text-red-900">Override reason (min {OVERRIDE_MIN_CHARS} chars, logged)</Label>
                                <Textarea
                                    value={form.data.allergen_override_reason}
                                    onChange={(e) => form.setData('allergen_override_reason', e.target.value)}
                                    rows={2}
                                    placeholder="e.g. Cook prepared a separate gluten-free portion for Mila"
                                    className="mt-1"
                                />
                                <div className="mt-1 text-xs text-red-900/80">
                                    {form.data.allergen_override_reason.trim().length} / {OVERRIDE_MIN_CHARS} chars
                                    {overrideValid && ' ✓'}
                                </div>
                            </div>
                        </div>
                    )}

                    {hasSoft && !hasHard && (
                        <div className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm">
                            <div className="mb-2 flex items-center gap-2 font-medium text-amber-900">
                                <AlertTriangle className="h-4 w-4" /> Heads up — soft warnings
                            </div>
                            <ul className="space-y-1 text-amber-900">
                                {report.soft_warnings.map((panel) => (
                                    <li key={panel.client_id}>
                                        <strong>{panel.client_name}</strong>:{' '}
                                        {panel.matches.map((m) => `${m.label}${m.kind === 'dislike' ? ' (dislike)' : ''}`).join(', ')}
                                    </li>
                                ))}
                            </ul>
                            <label className="mt-2 flex items-center gap-2 text-xs text-amber-900">
                                <input
                                    type="checkbox"
                                    checked={acknowledgedSoft}
                                    onChange={(e) => setAcknowledgedSoft(e.target.checked)}
                                />
                                I've reviewed these and want to plan this meal anyway
                            </label>
                        </div>
                    )}

                    {hasHard && hasSoft && (
                        <div className="rounded-md border border-amber-300 bg-amber-50 p-2 text-xs text-amber-900">
                            <strong>Also noted:</strong>{' '}
                            {report.soft_warnings.flatMap((p) => p.matches.map((m) => `${p.client_name} — ${m.label}`)).join('; ')}
                        </div>
                    )}

                    <DialogFooter className="flex items-center justify-between gap-2 sm:justify-between">
                        <div className="flex gap-2">
                            {!isNew && (
                                <ConfirmAction
                                    title="Remove this meal?"
                                    description={`This will remove ${entry?.recipe?.name ?? entry?.ad_hoc_name ?? 'the meal'} from the calendar.`}
                                    confirmLabel="Remove"
                                    onConfirm={destroy}
                                >
                                    <Button type="button" variant="ghost" className="text-destructive">Delete</Button>
                                </ConfirmAction>
                            )}
                            {!isNew && !entry?.served_at && <Button type="button" variant="outline" onClick={markServed}>Mark served</Button>}
                        </div>
                        <div className="flex gap-2">
                            <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
                            <Button type="submit" disabled={saveDisabled}>
                                {hasHard ? 'Override and save' : (isNew ? 'Add meal' : 'Save')}
                            </Button>
                        </div>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

type AdjustMode = 'add' | 'remove' | 'set';

const MODE_PRESETS: Record<AdjustMode, { label: string; helper: string; reason: string; reasons: { value: string; label: string }[] }> = {
    add: {
        label: 'Add stock',
        helper: 'Something was added — a delivery arrived, you found extra, etc.',
        reason: 'delivery',
        reasons: [
            { value: 'delivery', label: 'Delivery arrived' },
            { value: 'adjustment', label: 'Found / correction' },
        ],
    },
    remove: {
        label: 'Remove stock',
        helper: 'Something left the shelf — used in cooking, thrown out, given away.',
        reason: 'consumption',
        reasons: [
            { value: 'consumption', label: 'Used for cooking' },
            { value: 'waste', label: 'Wasted / spoiled' },
            { value: 'adjustment', label: 'Correction' },
        ],
    },
    set: {
        label: 'Set total',
        helper: 'I just counted what is actually here and want to set the on-hand total.',
        reason: 'stocktake',
        reasons: [
            { value: 'stocktake', label: 'Counted on hand' },
        ],
    },
};

export function AdjustInventoryDialog({
    open,
    onClose,
    siteId,
    item,
    products,
    productCategories = [],
    canCreateProducts = false,
    onProductCreated,
}: {
    open: boolean;
    onClose: () => void;
    siteId: number;
    item: InventoryItem | null;
    products: ProductOpt[];
    productCategories?: string[];
    reasons?: string[];
    canCreateProducts?: boolean;
    onProductCreated?: (product: ProductOpt, category: string | null) => void;
}) {
    const [mode, setMode] = useState<AdjustMode>('add');
    const [productId, setProductId] = useState<number | null>(item?.product_id ?? null);
    const [qty, setQty] = useState<string>('');
    const [unit, setUnit] = useState<string>(item?.unit ?? 'each');
    const [reason, setReason] = useState<string>('delivery');
    const [note, setNote] = useState<string>('');
    const [submitting, setSubmitting] = useState(false);

    // Inline product creation
    const [creatingProduct, setCreatingProduct] = useState(false);
    const [newProductName, setNewProductName] = useState('');
    const [newProductCategory, setNewProductCategory] = useState<string>(''); // '' = none, '__new__' = adding new
    const [newCategoryDraft, setNewCategoryDraft] = useState<string>('');
    const [newProductUnit, setNewProductUnit] = useState('each');
    const [newProductBusy, setNewProductBusy] = useState(false);
    const [newProductError, setNewProductError] = useState<string | null>(null);

    useEffect(() => {
        if (!open) return;
        setMode('add');
        setProductId(item?.product_id ?? null);
        setQty('');
        setUnit(item?.unit ?? 'each');
        setReason('delivery');
        setNote('');
        setCreatingProduct(false);
        setNewProductName('');
        setNewProductCategory('');
        setNewCategoryDraft('');
        setNewProductUnit('each');
        setNewProductError(null);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, item?.id]);

    useEffect(() => {
        setReason(MODE_PRESETS[mode].reason);
    }, [mode]);

    const currentQty = item ? (typeof item.current_qty === 'string' ? parseFloat(item.current_qty) : item.current_qty) : null;
    const numericQty = qty === '' ? null : Number(qty);

    const preview = useMemo(() => {
        if (numericQty === null || Number.isNaN(numericQty) || numericQty < 0) return null;
        if (currentQty === null) return null;
        if (mode === 'add') return currentQty + numericQty;
        if (mode === 'remove') return currentQty - numericQty;
        return numericQty;
    }, [mode, numericQty, currentQty]);

    function submit(e: React.FormEvent) {
        e.preventDefault();
        if (!productId) return;
        if (numericQty === null || Number.isNaN(numericQty) || numericQty < 0) return;

        setSubmitting(true);
        if (mode === 'set') {
            router.post(`/sites/${siteId}/meal-inventory/stocktake`, {
                counts: [{ product_id: productId, qty: numericQty, unit }],
                note: note || null,
            }, {
                preserveScroll: true,
                onSuccess: () => onClose(),
                onFinish: () => setSubmitting(false),
            });
        } else {
            const delta = mode === 'add' ? numericQty : -numericQty;
            router.post(`/sites/${siteId}/meal-inventory/adjust`, {
                product_id: productId,
                delta,
                unit,
                reason,
                note: note || null,
            }, {
                preserveScroll: true,
                onSuccess: () => onClose(),
                onFinish: () => setSubmitting(false),
            });
        }
    }

    const preset = MODE_PRESETS[mode];

    async function createProduct(e: React.FormEvent) {
        e.preventDefault();
        const name = newProductName.trim();
        if (name.length === 0) {
            setNewProductError('Name is required');
            return;
        }

        // Resolve the chosen category: existing pick, or fresh name when "+ Create new" was used.
        let chosenCategory: string | null = null;
        if (newProductCategory === '__new__') {
            const draft = newCategoryDraft.trim();
            if (draft.length === 0) {
                setNewProductError('Type a category name or pick an existing one');
                return;
            }
            chosenCategory = draft.toLowerCase();
        } else if (newProductCategory !== '') {
            chosenCategory = newProductCategory;
        }

        setNewProductBusy(true);
        setNewProductError(null);
        try {
            const res = await axios.post('/catering/products', {
                name,
                category: chosenCategory,
                default_unit: newProductUnit,
                is_active: true,
            }, { headers: { Accept: 'application/json' } });
            const created: ProductOpt = {
                id: res.data.id,
                name: res.data.name,
                default_unit: res.data.default_unit,
            };
            onProductCreated?.(created, chosenCategory);
            setProductId(created.id);
            setUnit(created.default_unit);
            setCreatingProduct(false);
            setNewProductName('');
            setNewProductCategory('');
            setNewCategoryDraft('');
        } catch (err) {
            const axErr = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } };
            const apiMsg = axErr.response?.data?.errors?.name?.[0] ?? axErr.response?.data?.message ?? 'Could not create product';
            setNewProductError(apiMsg);
        } finally {
            setNewProductBusy(false);
        }
    }

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{item ? item.product.name : 'Update inventory'}</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    {!item && !creatingProduct && (
                        <div>
                            <Label>Product</Label>
                            <Select
                                value={productId ? String(productId) : ''}
                                onValueChange={(v) => {
                                    if (v === '__new__') {
                                        setCreatingProduct(true);
                                        return;
                                    }
                                    setProductId(Number(v));
                                    const p = products.find((x) => x.id === Number(v));
                                    if (p) setUnit(p.default_unit);
                                }}
                            >
                                <SelectTrigger><SelectValue placeholder="Pick a product" /></SelectTrigger>
                                <SelectContent>
                                    {canCreateProducts && (
                                        <SelectItem value="__new__" className="font-medium text-primary">
                                            + Create new product
                                        </SelectItem>
                                    )}
                                    {products.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                            {canCreateProducts && (
                                <p className="mt-1 text-xs text-muted-foreground">Can't find it? Pick "Create new product" at the top of the list.</p>
                            )}
                        </div>
                    )}

                    {!item && creatingProduct && (
                        <div className="rounded-md border-2 border-primary/40 bg-primary/5 p-3">
                            <div className="mb-2 text-sm font-semibold text-primary">New product</div>
                            <div className="grid grid-cols-2 gap-2">
                                <div className="col-span-2">
                                    <Label className="text-xs">Name</Label>
                                    <Input
                                        autoFocus
                                        value={newProductName}
                                        onChange={(e) => setNewProductName(e.target.value)}
                                        placeholder="e.g. Greek Yoghurt (500g)"
                                    />
                                </div>
                                <div>
                                    <Label className="text-xs">Category (optional)</Label>
                                    <Select value={newProductCategory || '__none__'} onValueChange={(v) => {
                                        setNewProductCategory(v === '__none__' ? '' : v);
                                        if (v !== '__new__') setNewCategoryDraft('');
                                    }}>
                                        <SelectTrigger><SelectValue placeholder="— None —" /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">— None —</SelectItem>
                                            <SelectItem value="__new__" className="font-medium text-primary">+ Create new category</SelectItem>
                                            {productCategories.map((c) => (
                                                <SelectItem key={c} value={c} className="capitalize">{c}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {newProductCategory === '__new__' && (
                                        <Input
                                            className="mt-1"
                                            autoFocus
                                            value={newCategoryDraft}
                                            onChange={(e) => setNewCategoryDraft(e.target.value)}
                                            placeholder="e.g. beverages"
                                        />
                                    )}
                                </div>
                                <div>
                                    <Label className="text-xs">Default unit</Label>
                                    <Select value={newProductUnit} onValueChange={setNewProductUnit}>
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            {['each', 'kg', 'g', 'L', 'ml', 'pack', 'tin', 'bottle', 'box'].map((u) => (
                                                <SelectItem key={u} value={u}>{u}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            {newProductError && (
                                <div className="mt-2 text-xs text-destructive">{newProductError}</div>
                            )}
                            <div className="mt-3 flex justify-end gap-2">
                                <Button type="button" size="sm" variant="ghost" onClick={() => { setCreatingProduct(false); setNewProductError(null); }}>
                                    Cancel
                                </Button>
                                <Button type="button" size="sm" disabled={newProductBusy} onClick={createProduct}>
                                    {newProductBusy ? 'Creating…' : 'Create + use'}
                                </Button>
                            </div>
                        </div>
                    )}

                    <div>
                        <Label className="mb-2 block">What happened?</Label>
                        <div className="grid grid-cols-3 gap-2">
                            {(['add', 'remove', 'set'] as AdjustMode[]).map((m) => (
                                <button
                                    key={m}
                                    type="button"
                                    onClick={() => setMode(m)}
                                    className={`rounded-md border p-3 text-left transition ${mode === m ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'hover:bg-accent'}`}
                                >
                                    <div className="text-sm font-medium">{MODE_PRESETS[m].label}</div>
                                </button>
                            ))}
                        </div>
                        <p className="mt-2 text-xs text-muted-foreground">{preset.helper}</p>
                    </div>

                    {item && currentQty !== null && (
                        <div className="rounded-md border bg-muted/30 px-3 py-2 text-sm">
                            <span className="text-muted-foreground">Currently on hand: </span>
                            <strong>{currentQty} {item.unit}</strong>
                            {preview !== null && (
                                <>
                                    <span className="mx-2 text-muted-foreground">→</span>
                                    <strong className={preview < 0 ? 'text-destructive' : ''}>{preview} {item.unit}</strong>
                                </>
                            )}
                        </div>
                    )}

                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <Label>{mode === 'set' ? 'New on-hand total' : 'Quantity'}</Label>
                            <Input
                                type="number"
                                step="0.01"
                                min={0}
                                value={qty}
                                onChange={(e) => setQty(e.target.value)}
                                placeholder={mode === 'set' ? 'e.g. 6' : 'e.g. 2'}
                                autoFocus
                            />
                        </div>
                        <div>
                            <Label>Unit</Label>
                            <Select value={unit} onValueChange={setUnit}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    {['each', 'kg', 'g', 'L', 'ml', 'pack', 'tin', 'bottle', 'box'].map((u) => (
                                        <SelectItem key={u} value={u}>{u}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    {mode !== 'set' && preset.reasons.length > 1 && (
                        <div>
                            <Label>Reason</Label>
                            <Select value={reason} onValueChange={setReason}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    {preset.reasons.map((r) => <SelectItem key={r.value} value={r.value}>{r.label}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>
                    )}

                    <div>
                        <Label>Note (optional)</Label>
                        <Input value={note} onChange={(e) => setNote(e.target.value)} placeholder="e.g. delivered by FreshChoice" />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
                        <Button type="submit" disabled={submitting || !productId || qty === ''}>
                            {mode === 'add' && 'Add to stock'}
                            {mode === 'remove' && 'Remove from stock'}
                            {mode === 'set' && 'Set total'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export function StocktakeDialog({
    open,
    onClose,
    siteId,
    items,
}: {
    open: boolean;
    onClose: () => void;
    siteId: number;
    items: InventoryItem[];
}) {
    const [counts, setCounts] = useState<Record<number, number>>({});
    const [note, setNote] = useState('');
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        if (open) setCounts({});
    }, [open]);

    function update(productId: number, value: string) {
        const n = parseFloat(value);
        setCounts((prev) => ({ ...prev, [productId]: Number.isNaN(n) ? 0 : n }));
    }

    function submit(e: React.FormEvent) {
        e.preventDefault();
        const rows = items
            .filter((i) => counts[i.product_id] !== undefined)
            .map((i) => ({ product_id: i.product_id, qty: counts[i.product_id], unit: i.unit }));
        if (rows.length === 0) {
            onClose();
            return;
        }
        setSubmitting(true);
        router.post(`/sites/${siteId}/meal-inventory/stocktake`, { counts: rows, note }, {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onFinish: () => setSubmitting(false),
        });
    }

    const total = useMemo(() => Object.keys(counts).length, [counts]);

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Stocktake</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <p className="text-sm text-muted-foreground">Set the on-hand quantity for each item you count. Items left blank are skipped.</p>
                    <div className="max-h-96 overflow-y-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50">
                                <tr>
                                    <th className="px-3 py-2 text-left">Product</th>
                                    <th className="px-3 py-2 text-left">Current</th>
                                    <th className="px-3 py-2 text-left">Counted</th>
                                </tr>
                            </thead>
                            <tbody>
                                {items.length === 0 && (
                                    <tr><td colSpan={3} className="px-3 py-6 text-center text-muted-foreground">No inventory yet — add an item first.</td></tr>
                                )}
                                {items.map((i) => (
                                    <tr key={i.id} className="border-t">
                                        <td className="px-3 py-2 font-medium">{i.product.name}</td>
                                        <td className="px-3 py-2 text-muted-foreground">{formatQty(i.current_qty, i.unit)}</td>
                                        <td className="px-3 py-2">
                                            <div className="flex items-center gap-1">
                                                <Input
                                                    type="number"
                                                    step="0.01"
                                                    min={0}
                                                    placeholder="—"
                                                    value={counts[i.product_id] ?? ''}
                                                    onChange={(e) => update(i.product_id, e.target.value)}
                                                    className="w-24"
                                                />
                                                <span className="text-xs text-muted-foreground">{i.unit}</span>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <div>
                        <Label>Note (optional)</Label>
                        <Input value={note} onChange={(e) => setNote(e.target.value)} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
                        <Button type="submit" disabled={submitting || total === 0}>Record {total} count{total === 1 ? '' : 's'}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export function ShoppingListGenerateDialog({
    open,
    onClose,
    siteId,
}: {
    open: boolean;
    onClose: () => void;
    siteId: number;
}) {
    const today = new Date();
    const end = new Date();
    end.setDate(today.getDate() + 6);

    const form = useForm({
        covers_from: today.toISOString().slice(0, 10),
        covers_to: end.toISOString().slice(0, 10),
        include_restock_to_par: true,
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.post(`/sites/${siteId}/meal-shopping-lists/generate`, {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    }

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Generate shopping list</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <Label>From</Label>
                            <Input type="date" value={form.data.covers_from} onChange={(e) => form.setData('covers_from', e.target.value)} />
                        </div>
                        <div>
                            <Label>To</Label>
                            <Input type="date" value={form.data.covers_to} onChange={(e) => form.setData('covers_to', e.target.value)} />
                        </div>
                    </div>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={form.data.include_restock_to_par} onChange={(e) => form.setData('include_restock_to_par', e.target.checked)} />
                        Top up stock to par levels
                    </label>
                    <Badge variant="outline" className="text-xs">Manual items on the current draft are preserved.</Badge>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
                        <Button type="submit" disabled={form.processing}>Generate</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
