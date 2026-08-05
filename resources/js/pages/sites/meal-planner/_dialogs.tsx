import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { router, useForm } from '@inertiajs/react';
import axios from 'axios';
import { AlertTriangle, ChefHat, CupSoda, Info, LayoutTemplate, Leaf, Loader2, Lock, Settings, ShieldAlert, ShoppingBag, Soup, Trash2, Utensils, Wallet } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { cn } from '@/lib/utils';
import { ConfirmAction } from '../_confirm-action';
import { announce } from './_announcer';
import { addDays, allergenResidentsFor, dietaryMismatchesFor, firstName, fluidsResidentsFor, joinNames, MEAL_SLOTS, SLOT_LABEL, formatQty, textureResidentsFor, toIsoDate, type InventoryItem, type MealSlot, type PlanEntry, type RecipeFull, type Resident, type SourceType, type WeekTemplate } from './_helpers';
import { TemplateBuilderDialog } from './_templates-panel';
import { Card as GuardrailCard } from '@/components/ui/card';

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
    initialRecipeId,
    recipes,
    residents,
    canOverride,
}: {
    open: boolean;
    onClose: () => void;
    siteId: number;
    siteType: string;
    entry: PlanEntry | null;
    initialDate: string;
    initialSlot: MealSlot;
    initialRecipeId?: number;
    recipes: RecipeFull[];
    residents: Resident[];
    canOverride: boolean;
}) {
    const isNew = !entry;
    const existingOverrideReason = entry?.allergen_override_reason ?? '';

    const initialSource: SourceType = entry?.source_type
        ?? (entry?.takeaway_vendor ? 'takeaway' : (entry?.recipe_id || initialRecipeId) ? 'recipe' : 'ad_hoc');

    const initialTakeawayCost = entry?.takeaway_cost_cents != null
        ? (entry.takeaway_cost_cents / 100).toFixed(2)
        : '';

    const form = useForm({
        plan_date: (entry?.plan_date ?? initialDate).slice(0, 10),
        meal_slot: (entry?.meal_slot ?? initialSlot) as MealSlot,
        source_type: initialSource,
        recipe_id: entry?.recipe_id ?? initialRecipeId ?? null,
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
    // When the allergen check itself FAILS (500/419/dropped wifi) we must NOT
    // fall back to a clean EMPTY_REPORT — that would let an unsafe meal save.
    const [reportError, setReportError] = useState(false);
    const [acknowledgedSoft, setAcknowledgedSoft] = useState(false);
    const [pastVendors, setPastVendors] = useState<string[]>([]);

    useEffect(() => {
        if (!open) return;
        form.setData({
            plan_date: (entry?.plan_date ?? initialDate).slice(0, 10),
            meal_slot: (entry?.meal_slot ?? initialSlot) as MealSlot,
            source_type: initialSource,
            recipe_id: entry?.recipe_id ?? initialRecipeId ?? null,
            ad_hoc_name: entry?.ad_hoc_name ?? '',
            takeaway_vendor: entry?.takeaway_vendor ?? '',
            takeaway_cost: initialTakeawayCost,
            takeaway_reference: entry?.takeaway_reference ?? '',
            servings: entry?.servings ?? 4,
            notes: entry?.notes ?? '',
            client_ids: entry?.client_ids ?? (isNew && siteType === 'house' ? residents.map((r) => r.id) : []),
            allergen_override_reason: '',
        });
        setReport(EMPTY_REPORT);
        setReportError(false);
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
                setReportError(false);
                setAcknowledgedSoft(false);
            }).catch(() => {
                if (cancelled) return;
                // FAIL CLOSED: empty the report AND flag the failure so Save locks.
                setReport(EMPTY_REPORT);
                setReportError(true);
            }).finally(() => {
                if (!cancelled) setReportLoading(false);
            });
        }, 300);
        return () => {
            cancelled = true;
            clearTimeout(timer);
        };
    }, [
        open,
        siteId,
        form.data.source_type,
        form.data.recipe_id,
        form.data.client_ids,
    ]);

    // Explicit, non-debounced re-check (the "Retry check" button + submit-time re-verify).
    function retryCheck() {
        const recipeId = form.data.recipe_id;
        const clientIds = form.data.client_ids ?? [];
        if (form.data.source_type !== 'recipe' || !recipeId || clientIds.length === 0) {
            setReport(EMPTY_REPORT);
            setReportError(false);
            return;
        }
        setReportLoading(true);
        axios.post(`/sites/${siteId}/meal-planner/check-conflicts`, { recipe_id: recipeId, client_ids: clientIds })
            .then((res) => { setReport(res.data ?? EMPTY_REPORT); setReportError(false); setAcknowledgedSoft(false); })
            .catch(() => { setReport(EMPTY_REPORT); setReportError(true); })
            .finally(() => setReportLoading(false));
    }

    const showResidents = siteType === 'house';

    // Resident-aware advisories (P0-4/5/10) — all surfaced from data already in state.
    const selectedClientIds = form.data.client_ids ?? [];
    const selectedRecipe = form.data.source_type === 'recipe' && form.data.recipe_id != null ? recipes.find((r) => r.id === form.data.recipe_id) : undefined;
    const textureResidents = showResidents ? textureResidentsFor(residents, selectedClientIds) : [];
    const fluidsResidents = showResidents ? fluidsResidentsFor(residents, selectedClientIds) : [];
    const allergenResidents = showResidents ? allergenResidentsFor(residents, selectedClientIds) : [];
    const dietaryMismatchList = showResidents && selectedRecipe ? dietaryMismatchesFor(selectedRecipe, residents, selectedClientIds) : [];

    // Named ad-hoc/takeaway allergen reminder (no recipe to auto-check against).
    const allergenReminder = allergenResidents.length
        ? `Check carefully — ${joinNames(allergenResidents.map((r) => `${firstName(r.name)} (${r.allergens.join(', ')})`))} ${allergenResidents.length === 1 ? 'has' : 'have'} recorded allergens.`
        : null;

    // Combined texture + thickened-fluids clauses for the persistent advisory banner.
    const careClauses = [
        ...textureResidents.map((r) => `${firstName(r.name)} needs IDDSI ${r.texture!.level} (${r.texture!.label})`),
        ...fluidsResidents.map((r) => `${firstName(r.name)} needs ${r.fluids} fluids`),
    ];

    const hasHard = report.has_hard_blocks;
    const hasSoft = report.has_soft_warnings;
    const overrideValid = form.data.allergen_override_reason.trim().length >= OVERRIDE_MIN_CHARS;
    const blockedByRole = hasHard && !canOverride;
    // A failed allergen check on a house meal with residents must hard-block Save.
    const blockedByCheckError = reportError && siteType === 'house' && (form.data.client_ids ?? []).length > 0;
    const saveDisabled = form.processing || blockedByRole || blockedByCheckError || (hasHard && canOverride && !overrideValid) || (hasSoft && !acknowledgedSoft && !hasHard);

    function submit(e: React.FormEvent) {
        e.preventDefault();
        if (saveDisabled) return;
        const onSuccess = () => onClose();
        const onError = () => {
            // surface the failure both visibly and to assistive tech (P2-20) — the form stays open & populated
            toast.error("Couldn't save the meal — try again");
            announce("Couldn't save the meal — try again");
            // server may have detected a conflict the client missed —
            // re-run the preview so the warning surfaces (fail closed if it errors)
            if (form.data.source_type === 'recipe' && form.data.recipe_id && (form.data.client_ids ?? []).length > 0) {
                axios.post(`/sites/${siteId}/meal-planner/check-conflicts`, {
                    recipe_id: form.data.recipe_id,
                    client_ids: form.data.client_ids,
                }).then((res) => { setReport(res.data ?? EMPTY_REPORT); setReportError(false); }).catch(() => { setReport(EMPTY_REPORT); setReportError(true); });
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

    function toggleServed() {
        if (!entry) return;
        const path = entry.served_at ? 'unserve' : 'serve';
        router.post(`/sites/${siteId}/meal-plan/${entry.id}/${path}`, {}, {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onError: () => {
                const msg = entry.served_at ? "Couldn't mark not served — try again" : "Couldn't mark served — try again";
                toast.error(msg);
                announce(msg);
            },
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
                    <DialogDescription>
                        Choose the meal time, source and residents, then record any planning notes.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    {!isNew && entry?.allergen_override_at && existingOverrideReason && (
                            <div className="flex items-start gap-2 rounded-md border border-status-critical/30 bg-status-critical-bg/60 p-2 text-xs text-status-critical">
                            <Lock className="mt-0.5 h-3.5 w-3.5 flex-none" />
                            <div>
                                <div className="font-medium">Allergen override on file</div>
                                <div className="mt-0.5">&ldquo;{existingOverrideReason}&rdquo;</div>
                                {entry.allergen_override_by && (
                                        <div className="mt-0.5 text-status-critical/80">by {entry.allergen_override_by.name} · {new Date(entry.allergen_override_at).toLocaleString('en-NZ')}</div>
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
                                    <Button unstyled
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
                                    </Button>
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
                            {allergenReminder ? (
                                <div className="flex items-start gap-2 rounded-md border border-status-warning/30 bg-status-warning-bg p-2 text-xs text-status-warning">
                                    <ShieldAlert className="mt-0.5 h-3.5 w-3.5 flex-none" aria-hidden="true" />
                                    <span>{allergenReminder} Ad-hoc meals aren't auto-checked — confirm ingredients before serving.</span>
                                </div>
                            ) : (
                                <div className="flex items-start gap-2 rounded-md border border-muted bg-muted/30 p-2 text-xs text-muted-foreground">
                                    <Info className="mt-0.5 h-3.5 w-3.5 flex-none" aria-hidden="true" />
                                    <span>No allergen check available for ad-hoc meals — check ingredients carefully before serving.</span>
                                </div>
                            )}
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
                            <div className="flex items-start gap-2 rounded-md border border-status-warning/30 bg-status-warning-bg p-2 text-xs text-status-warning">
                                {allergenReminder ? <ShieldAlert className="mt-0.5 h-3.5 w-3.5 flex-none" aria-hidden="true" /> : <Info className="mt-0.5 h-3.5 w-3.5 flex-none" aria-hidden="true" />}
                                <span><strong>Takeaway meal</strong> — {allergenReminder ? `${allergenReminder} Confirm dietary suitability with the vendor before serving.` : 'allergen check unavailable. Confirm dietary suitability with the vendor before serving.'}</span>
                            </div>
                        </>
                    )}
                    <div>
                        <Label>Servings</Label>
                        <Input type="number" min={1} value={form.data.servings} onChange={(e) => form.setData('servings', Number(e.target.value))} />
                    </div>
                    <div>
                        <Label>Meal record <span className="font-normal text-muted-foreground">(free-text note)</span></Label>
                        <Textarea value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} rows={2} placeholder="Intake / refusals (e.g. 'Aroha ate half, refused vegetables'; 'Mila — dairy-free portion plated')" />
                        <p className="mt-1 text-[11px] text-muted-foreground">One free-text note for the whole meal — not a per-resident intake record.</p>
                    </div>
                    {showResidents && residents.length > 0 && (
                        <div>
                            <div className="mb-1 flex items-center justify-between">
                                <Label className="mb-0">Residents <span className="font-normal text-muted-foreground">(allergens checked against these)</span></Label>
                                <div className="flex gap-2 text-[11px] font-medium text-primary">
                                    <Button unstyled type="button" onClick={() => form.setData('client_ids', residents.map((r) => r.id))} className="hover:underline">All</Button>
                                    <Button unstyled type="button" onClick={() => form.setData('client_ids', [])} className="hover:underline">None</Button>
                                </div>
                            </div>
                            <div className="mt-1 grid max-h-40 grid-cols-1 gap-1 overflow-y-auto rounded-md border border-border p-2 sm:grid-cols-2">
                                {residents.map((c) => {
                                    const selected = (form.data.client_ids ?? []).includes(c.id);
                                    return (
                                        <label key={c.id} className={cn('flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm transition-colors', selected ? 'bg-sites-bg' : 'hover:bg-accent')}>
                                            <input type="checkbox" checked={selected} onChange={() => toggleClient(c.id)} className="accent-[var(--sites)]" />
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate font-medium text-foreground">{c.name}</span>
                                                <span className="flex flex-wrap items-center gap-1 text-[10.5px] leading-tight">
                                                    {c.allergens.length > 0 && <span className="inline-flex items-center gap-0.5 text-status-critical"><ShieldAlert className="h-2.5 w-2.5" />{c.allergens.join(', ')}</span>}
                                                    {c.texture && c.texture.level < 7 && <span className="inline-flex items-center gap-0.5 text-primary"><Soup className="h-2.5 w-2.5" />IDDSI {c.texture.level}</span>}
                                                    {c.fluids && <span className="inline-flex items-center gap-0.5 text-primary"><CupSoda className="h-2.5 w-2.5" />{c.fluids}</span>}
                                                </span>
                                            </span>
                                        </label>
                                    );
                                })}
                            </div>
                        </div>
                    )}

                    {careClauses.length > 0 && (
                            <div className="flex items-start gap-2 rounded-md border border-status-warning/30 bg-status-warning-bg p-2.5 text-xs text-status-warning">
                            <Soup className="mt-0.5 h-4 w-4 flex-none" aria-hidden="true" />
                            <div>
                                <div className="font-semibold">{textureResidents.length > 0 ? 'Texture-modified diet' : 'Thickened fluids'}</div>
                                <div className="mt-0.5">{careClauses.join(', ')}. Confirm this meal is prepared to the right texture and consistency.</div>
                            </div>
                        </div>
                    )}

                    {dietaryMismatchList.length > 0 && (
                            <div className="flex items-start gap-2 rounded-md border border-status-warning/30 bg-status-warning-bg p-2.5 text-xs text-status-warning">
                            <Leaf className="mt-0.5 h-4 w-4 flex-none" aria-hidden="true" />
                            <div>
                                <div className="font-semibold">Dietary requirement</div>
                                <div className="mt-0.5">
                                    {joinNames(dietaryMismatchList.map((m) => `${firstName(m.resident.name)} is ${m.requirements.join(', ')}`))} — confirm this meal meets {dietaryMismatchList.length === 1 ? 'it' : 'them'}.
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Single live region: loading → failure/result is announced to assistive tech (P0-1/P0-6). */}
                    <div role="status" aria-live="assertive" aria-atomic="true">
                        {reportLoading && (
                            <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                <Loader2 className="h-3.5 w-3.5 animate-spin" aria-hidden="true" /> Checking dietary conflicts…
                            </div>
                        )}
                        {reportError && !reportLoading && (
                            <div className="rounded-md border-2 border-status-critical bg-status-critical-bg/60 p-3 text-sm">
                                <div className="mb-1 flex items-center gap-2 font-semibold text-status-critical">
                                    <ShieldAlert className="h-4 w-4" aria-hidden="true" /> Couldn't verify allergens for this meal
                                </div>
                                <p className="text-status-critical">Do not save until allergens are verified.</p>
                                <Button type="button" size="sm" variant="outline" className="mt-2 border-status-critical/50 text-status-critical hover:bg-status-critical-bg" onClick={retryCheck}>
                                    <Loader2 className={cn('mr-1.5 h-3.5 w-3.5', reportLoading ? 'animate-spin' : 'hidden')} aria-hidden="true" /> Retry check
                                </Button>
                            </div>
                        )}
                    </div>

                    {hasHard && (
                        <div role="alert" className="rounded-md border-2 border-status-critical bg-status-critical-bg/60 p-3 text-sm">
                            <div className="mb-2 flex items-center gap-2 font-semibold text-status-critical">
                                <ShieldAlert className="h-4 w-4" aria-hidden="true" /> Allergy alert — {canOverride ? 'override required to save' : 'this meal is unsafe for a resident'}
                            </div>
                            <ul className="space-y-1.5 text-status-critical">
                                {report.hard_blocks.map((panel) => {
                                    const sorted = [...panel.matches].sort((a, b) => Number(b.severity === 'critical') - Number(a.severity === 'critical'));
                                    return (
                                        <li key={panel.client_id} className="flex flex-wrap items-center gap-1.5">
                                            <strong>{panel.client_name}:</strong>
                                            {sorted.map((m, i) => (
                                                <span key={i} className={cn('inline-flex items-center gap-1 rounded-full px-1.5 py-px text-[11px] font-medium', m.severity === 'critical' ? 'bg-status-critical text-white' : 'bg-status-critical-bg text-status-critical')}>
                                                    {m.label}{m.severity === 'critical' && <span className="text-[8.5px] font-bold uppercase">⚠ Critical</span>}
                                                </span>
                                            ))}
                                        </li>
                                    );
                                })}
                            </ul>
                            {canOverride ? (
                                <div className="mt-3">
                                    <Label className="text-xs font-medium text-status-critical">Override reason (min {OVERRIDE_MIN_CHARS} chars, logged)</Label>
                                    <Textarea
                                        value={form.data.allergen_override_reason}
                                        onChange={(e) => form.setData('allergen_override_reason', e.target.value)}
                                        rows={2}
                                        placeholder="e.g. Cook plates a separate gluten-free, dairy-free portion for Mila from the same base"
                                        className="mt-1"
                                    />
                                    <div className="mt-1 text-xs text-status-critical/80">
                                        {form.data.allergen_override_reason.trim().length} / {OVERRIDE_MIN_CHARS} chars{overrideValid && ' ✓'}
                                    </div>
                                </div>
                            ) : (
                                <GuardrailCard unstyled className="mt-3 flex items-start gap-2 rounded-md border border-status-critical/40 bg-card/60 p-2 text-xs text-status-critical">
                                    <Lock className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                    <span>Your role cannot override an allergen conflict. Ask a <strong>Service Manager</strong> or <strong>Registered Nurse</strong> to plan this meal, or choose a safe alternative.</span>
                                </GuardrailCard>
                            )}
                        </div>
                    )}

                    {hasSoft && !hasHard && (
                            <div className="rounded-md border border-status-warning/30 bg-status-warning-bg p-3 text-sm">
                                <div className="mb-2 flex items-center gap-2 font-medium text-status-warning">
                                <AlertTriangle className="h-4 w-4" /> Heads up — soft warnings
                            </div>
                                <ul className="space-y-1 text-status-warning">
                                {report.soft_warnings.map((panel) => (
                                    <li key={panel.client_id}>
                                        <strong>{panel.client_name}</strong>:{' '}
                                        {panel.matches.map((m) => `${m.label}${m.kind === 'dislike' ? ' (dislike)' : ''}`).join(', ')}
                                    </li>
                                ))}
                            </ul>
                                <label className="mt-2 flex items-center gap-2 text-xs text-status-warning">
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
                            <div className="rounded-md border border-status-warning/30 bg-status-warning-bg p-2 text-xs text-status-warning">
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
                        </div>
                        <div className="flex items-center gap-2">
                            {!isNew && (
                                <Button type="button" variant="outline" onClick={toggleServed}>{entry?.served_at ? 'Mark not served' : 'Mark served'}</Button>
                            )}
                            {!isNew && <span className="hidden h-5 w-px bg-border sm:block" />}
                            <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
                            <Button type="submit" disabled={saveDisabled} aria-busy={form.processing}>
                                {blockedByCheckError ? 'Re-check before saving' : blockedByRole ? 'Cannot override' : hasHard ? 'Override and save' : isNew ? 'Add meal' : 'Save'}
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
    const [submitError, setSubmitError] = useState<string | null>(null);

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
        setSubmitError(null);
        const onError = (errors: Record<string, string>) => {
            const first = Object.values(errors)[0];
            setSubmitError(typeof first === 'string' ? first : null);
            toast.error("Couldn't save the adjustment — try again");
            announce("Couldn't save the adjustment");
        };
        if (mode === 'set') {
            router.post(`/sites/${siteId}/meal-inventory/stocktake`, {
                counts: [{ product_id: productId, qty: numericQty, unit }],
                note: note || null,
            }, {
                preserveScroll: true,
                onSuccess: () => onClose(),
                onError,
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
                onError,
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
                                <Button unstyled
                                    key={m}
                                    type="button"
                                    onClick={() => setMode(m)}
                                    className={`rounded-md border p-3 text-left transition ${mode === m ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'hover:bg-accent'}`}
                                >
                                    <div className="text-sm font-medium">{MODE_PRESETS[m].label}</div>
                                </Button>
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
                            {preview !== null && preview < 0 && <div className="mt-0.5 text-xs text-destructive">This sets stock below zero.</div>}
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

                    {submitError && <div className="rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2 text-xs text-destructive">{submitError}</div>}

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
                        <Button type="submit" disabled={submitting || !productId || qty === ''} aria-busy={submitting}>
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
            onError: () => { toast.error("Stocktake didn't save — try again"); announce("Stocktake didn't save"); },
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
                        <Button type="submit" disabled={submitting || total === 0} aria-busy={submitting}>Record {total} count{total === 1 ? '' : 's'}</Button>
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
    weekStart,
    mealsPlanned = 0,
}: {
    open: boolean;
    onClose: () => void;
    siteId: number;
    weekStart?: Date;
    mealsPlanned?: number;
}) {
    const start = weekStart ?? new Date();
    const end = new Date(start);
    end.setDate(start.getDate() + 6);

    const form = useForm({
        covers_from: toIsoDate(start),
        covers_to: toIsoDate(end),
        include_restock_to_par: true,
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.post(`/sites/${siteId}/meal-shopping-lists/generate`, {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onError: () => {
                toast.error("Couldn't generate the shopping list — try again");
                announce("Couldn't generate the shopping list — try again");
            },
        });
    }

    const noMeals = mealsPlanned === 0;

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Generate shopping list</DialogTitle>
                    <DialogDescription>Builds a list from this week's planned meals, plus anything below par if enabled.</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    {noMeals && (
                            <div className="flex items-start gap-2 rounded-md border border-status-warning/30 bg-status-warning-bg p-2.5 text-xs text-status-warning">
                            <Info className="mt-0.5 h-3.5 w-3.5 flex-none" aria-hidden="true" />
                            <span>No planned meals this week to build a list from — plan meals first. You can still top up stock to par.</span>
                        </div>
                    )}
                    <div className="grid grid-cols-2 gap-3">
                        <div>
                            <Label htmlFor="gen-from">From</Label>
                            <Input id="gen-from" type="date" autoFocus value={form.data.covers_from} onChange={(e) => form.setData('covers_from', e.target.value)} />
                            {form.errors.covers_from && <p className="mt-1 text-xs text-destructive">{form.errors.covers_from}</p>}
                        </div>
                        <div>
                            <Label htmlFor="gen-to">To</Label>
                            <Input id="gen-to" type="date" value={form.data.covers_to} onChange={(e) => form.setData('covers_to', e.target.value)} />
                            {form.errors.covers_to && <p className="mt-1 text-xs text-destructive">{form.errors.covers_to}</p>}
                        </div>
                    </div>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={form.data.include_restock_to_par} onChange={(e) => form.setData('include_restock_to_par', e.target.checked)} />
                        Top up stock to par levels
                    </label>
                    <Badge variant="outline" className="text-xs">Manual items on the current draft are preserved.</Badge>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
                        <Button type="submit" disabled={form.processing || (noMeals && !form.data.include_restock_to_par)}>Generate</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export function SettingsDialog({
    open,
    onClose,
    siteId,
    budgetCents,
    templates,
    recipes,
    weekStart,
    entries,
    canManage,
    onTemplatesChanged,
}: {
    open: boolean;
    onClose: () => void;
    siteId: number;
    budgetCents: number | null;
    templates: WeekTemplate[];
    recipes: RecipeFull[];
    weekStart: Date;
    entries: PlanEntry[];
    canManage: boolean;
    onTemplatesChanged: () => void;
}) {
    const [budget, setBudget] = useState(budgetCents ? (budgetCents / 100).toFixed(0) : '');
    const [savingBudget, setSavingBudget] = useState(false);
    const [savedBudget, setSavedBudget] = useState(false);
    const [tplName, setTplName] = useState('');
    const [savingTpl, setSavingTpl] = useState(false);
    const [builderOpen, setBuilderOpen] = useState(false);

    const weekDates = Array.from({ length: 7 }, (_, i) => toIsoDate(addDays(weekStart, i)));
    const weekMeals = entries.filter((e) => weekDates.includes(e.plan_date.slice(0, 10)) && e.source_type === 'recipe' && e.recipe_id != null);

    async function saveBudget() {
        setSavingBudget(true);
        try {
            await axios.put(`/sites/${siteId}/meal-planner/settings`, { weekly_food_budget_cents: budget === '' ? null : Math.round(Number(budget) * 100) });
            setSavedBudget(true);
            setTimeout(() => setSavedBudget(false), 1800);
        } catch {
            toast.error('Could not save budget');
            announce('Could not save budget');
        } finally {
            setSavingBudget(false);
        }
    }

    async function saveCurrentWeek() {
        if (!tplName.trim() || weekMeals.length === 0) return;
        setSavingTpl(true);
        try {
            const meals = weekMeals.map((e) => ({ day: weekDates.indexOf(e.plan_date.slice(0, 10)), slot: e.meal_slot, recipe_id: e.recipe_id, servings: e.servings }));
            await axios.post(`/sites/${siteId}/meal-templates`, { name: tplName.trim(), description: `${meals.length} meals`, meals });
            toast.success(`Template “${tplName.trim()}” saved`);
            setTplName('');
            onTemplatesChanged();
        } catch {
            toast.error('Could not save template');
        } finally {
            setSavingTpl(false);
        }
    }

    async function deleteTemplate(id: number) {
        try {
            await axios.delete(`/sites/${siteId}/meal-templates/${id}`);
            toast.success('Template deleted');
            onTemplatesChanged();
        } catch {
            toast.error('Could not delete');
        }
    }

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2"><Settings className="h-4 w-4 text-primary" /> Meal planner settings</DialogTitle>
                    <DialogDescription>Weekly food budget and reusable week templates.</DialogDescription>
                </DialogHeader>
                <div className="space-y-5">
                    <section>
                        <h3 className="mb-1.5 flex items-center gap-2 text-[13.5px] font-semibold text-foreground"><Wallet className="h-[15px] w-[15px] text-muted-foreground" /> Weekly food budget</h3>
                        <p className="mb-2 text-[12px] text-muted-foreground">Drives the budget bar and spend report for this house.</p>
                        <div className="flex items-end gap-2">
                            <div className="flex-1">
                                <Label>Amount per week (NZD)</Label>
                                <div className="relative">
                                    <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">$</span>
                                    <Input type="number" min={0} step="10" value={budget} onChange={(e) => setBudget(e.target.value)} placeholder="0" className="pl-6" disabled={!canManage} />
                                </div>
                            </div>
                            {canManage && <Button onClick={saveBudget} disabled={savingBudget}>{savedBudget ? 'Saved ✓' : 'Save budget'}</Button>}
                        </div>
                    </section>

                    <div className="h-px bg-border" />

                    <section>
                        <h3 className="mb-1.5 flex items-center gap-2 text-[13.5px] font-semibold text-foreground"><LayoutTemplate className="h-[15px] w-[15px] text-muted-foreground" /> Week templates</h3>
                        <p className="mb-2 text-[12px] text-muted-foreground">Reusable meal rotations you can apply to any week.</p>

                        {canManage && (
                            <Button unstyled type="button" onClick={() => setBuilderOpen(true)} className="mb-2 flex w-full items-center gap-3 rounded-xl border border-primary/30 bg-primary/5 px-3.5 py-3 text-left transition-colors hover:bg-primary/10">
                                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary text-primary-foreground"><LayoutTemplate className="h-5 w-5" /></span>
                                <span className="min-w-0 flex-1">
                                    <span className="block text-[13.5px] font-semibold text-foreground">Build a template from scratch</span>
                                    <span className="block text-[11.5px] text-muted-foreground">Compose a full week on a day × meal grid</span>
                                </span>
                            </Button>
                        )}

                        {canManage && (
                            <details className="mb-3 rounded-xl border border-border bg-muted/40">
                                <summary className="cursor-pointer list-none px-3.5 py-2.5 text-[12.5px] font-medium text-foreground [&::-webkit-details-marker]:hidden">
                                    <span className="inline-flex items-center gap-1.5"><LayoutTemplate className="h-3.5 w-3.5 text-muted-foreground" /> Or save the week you're viewing</span>
                                </summary>
                                <div className="border-t border-border p-3">
                                    <div className="flex items-end gap-2">
                                        <Input value={tplName} onChange={(e) => setTplName(e.target.value)} placeholder="e.g. Winter rotation" className="flex-1" />
                                        <Button onClick={saveCurrentWeek} disabled={savingTpl || !tplName.trim() || weekMeals.length === 0}>Save</Button>
                                    </div>
                                    <p className="mt-1.5 text-[11.5px] text-muted-foreground">
                                        {weekMeals.length > 0 ? `Captures ${weekMeals.length} recipe meal${weekMeals.length === 1 ? '' : 's'} from the week you're viewing.` : 'Plan some recipe meals this week first, then save them as a template.'}
                                    </p>
                                </div>
                            </details>
                        )}

                        <div className="space-y-1.5">
                            {templates.map((t) => (
                                <GuardrailCard unstyled key={t.id} className="flex items-center gap-3 rounded-lg border border-border bg-card px-3 py-2">
                                    <LayoutTemplate className="h-4 w-4 shrink-0 text-muted-foreground" />
                                    <div className="min-w-0 flex-1">
                                        <div className="truncate text-[13px] font-medium text-foreground">{t.name}</div>
                                        <div className="truncate text-[11px] text-muted-foreground">{t.meals.length} meals{t.description ? ` · ${t.description}` : ''}</div>
                                    </div>
                                    {canManage && (
                                        <ConfirmAction title={`Delete “${t.name}”?`} description="Removes the template." confirmLabel="Delete" onConfirm={() => deleteTemplate(t.id)}>
                                            <Button variant="ghost" size="icon" className="h-7 w-7"><Trash2 className="h-4 w-4 text-status-critical" /></Button>
                                        </ConfirmAction>
                                    )}
                                </GuardrailCard>
                            ))}
                            {templates.length === 0 && <div className="rounded-lg border border-dashed border-border px-3 py-4 text-center text-[12px] text-muted-foreground">No templates yet.</div>}
                        </div>
                    </section>
                </div>
                <DialogFooter><Button onClick={onClose}>Done</Button></DialogFooter>
            </DialogContent>
            {builderOpen && (
                <TemplateBuilderDialog siteId={siteId} recipes={recipes} initial={null} onClose={() => setBuilderOpen(false)} onSaved={() => { setBuilderOpen(false); onTemplatesChanged(); }} />
            )}
        </Dialog>
    );
}
