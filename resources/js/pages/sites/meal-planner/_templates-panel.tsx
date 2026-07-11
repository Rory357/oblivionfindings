import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn } from '@/lib/utils';
import axios from 'axios';
import { CalendarCheck, CalendarDays, Check, Clock, Copy, LayoutTemplate, Minus, Pencil, Plus, RefreshCw, ShieldAlert, Trash2, UtensilsCrossed, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import { ConfirmAction } from '../_confirm-action';
import { buildRecipeMap, conflictsFor, MEAL_SLOTS, SLOT_ICON, SLOT_LABEL, toIsoDate, type MealSlot, type PlanEntry, type RecipeFull, type RecipeMap, type Resident, type WeekTemplate, type WeekTemplateMeal } from './_helpers';
import { Card as GuardrailCard } from '@/components/ui/card';

const DOW = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

type Props = {
    siteId: number;
    templates: WeekTemplate[];
    recipes: RecipeFull[];
    residents: Resident[];
    weekLabel: string;
    rangeLabel: string;
    weekStart: Date;
    canManage: boolean;
    onChanged: () => void;
    onApplied: () => void;
};

export default function TemplatesPanel({ siteId, templates, recipes, residents, weekLabel, rangeLabel, weekStart, canManage, onChanged, onApplied }: Props) {
    const [applyTpl, setApplyTpl] = useState<WeekTemplate | null>(null);
    const [builder, setBuilder] = useState<{ open: boolean; initial: WeekTemplate | null }>({ open: false, initial: null });
    const recipeMap = useMemo(() => buildRecipeMap(recipes), [recipes]);
    const recipeName = (id: number) => recipes.find((r) => r.id === id)?.name ?? 'Meal';
    // Distinct allergen tags across a template's recipes — its allergen "footprint".
    const templateAllergens = (t: WeekTemplate): string[] => {
        const set = new Map<number, string>();
        t.meals.forEach((m) => recipeMap.get(m.recipe_id)?.tags.filter((tag) => tag.kind === 'allergen').forEach((tag) => set.set(tag.id, tag.label)));
        return Array.from(set.values());
    };

    async function duplicate(t: WeekTemplate) {
        try {
            await axios.post(`/sites/${siteId}/meal-templates`, { name: `${t.name} (copy)`, description: t.description, meals: t.meals });
            toast.success('Template duplicated');
            onChanged();
        } catch {
            toast.error('Could not duplicate');
        }
    }

    async function destroy(t: WeekTemplate) {
        try {
            await axios.delete(`/sites/${siteId}/meal-templates/${t.id}`);
            toast.success('Template deleted');
            onChanged();
        } catch {
            toast.error('Could not delete');
        }
    }

    async function applyTemplate(t: WeekTemplate, replace: boolean) {
        try {
            await axios.post(`/sites/${siteId}/meal-templates/${t.id}/apply`, { week: toIsoDate(weekStart), replace });
            toast.success(`Applied “${t.name}”`);
            setApplyTpl(null);
            onApplied();
        } catch {
            toast.error('Could not apply template');
        }
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-[15px] font-semibold text-foreground">Week templates</h2>
                    <p className="text-[12.5px] text-muted-foreground">Reusable meal rotations — apply any to the week you're viewing ({weekLabel}).</p>
                </div>
                {canManage && <Button size="sm" onClick={() => setBuilder({ open: true, initial: null })}><Plus className="mr-1.5 h-[15px] w-[15px]" /> Build template</Button>}
            </div>

            {templates.length === 0 ? (
                <GuardrailCard unstyled className="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-border bg-card px-6 py-12 text-center">
                    <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-sites-bg text-sites-deep"><LayoutTemplate className="h-7 w-7" /></span>
                    <div>
                        <div className="text-[15px] font-semibold text-foreground">No templates yet</div>
                        <div className="mt-0.5 text-[13px] text-muted-foreground">Build a reusable week to speed up planning.</div>
                    </div>
                    {canManage && <Button size="sm" onClick={() => setBuilder({ open: true, initial: null })}><Plus className="mr-1.5 h-[15px] w-[15px]" /> Build your first template</Button>}
                </GuardrailCard>
            ) : (
                <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                    {templates.map((t) => {
                        const byDay: WeekTemplateMeal[][] = Array.from({ length: 7 }, () => []);
                        t.meals.forEach((m) => byDay[m.day]?.push(m));
                        const daysUsed = byDay.filter((d) => d.length > 0).length;
                        const slotsUsed = Array.from(new Set(t.meals.map((m) => m.slot))).sort((a, b) => MEAL_SLOTS.indexOf(a) - MEAL_SLOTS.indexOf(b));
                        return (
                            <GuardrailCard unstyled key={t.id} className="flex flex-col overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                                <div className="flex items-start gap-3 border-b border-border bg-gradient-to-br from-sites-bg/50 to-transparent p-4">
                                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-sites text-primary-foreground"><LayoutTemplate className="h-5 w-5" /></span>
                                    <div className="min-w-0">
                                        <div className="flex items-center gap-2">
                                            <span className="text-[14.5px] font-semibold leading-tight text-foreground">{t.name}</span>
                                            {t.is_starter && <span className="rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">Starter</span>}
                                        </div>
                                        <div className="mt-0.5 text-[11.5px] text-muted-foreground">{t.description || `${t.meals.length} meals`}</div>
                                    </div>
                                </div>
                                <div className="flex flex-1 flex-col p-4">
                                    <div className="mb-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-[11.5px] text-muted-foreground">
                                        <span className="inline-flex items-center gap-1.5"><UtensilsCrossed className="h-3.5 w-3.5" /> {t.meals.length} meals</span>
                                        <span className="inline-flex items-center gap-1.5"><CalendarDays className="h-3.5 w-3.5" /> {daysUsed} day{daysUsed === 1 ? '' : 's'}</span>
                                        <span className="inline-flex items-center gap-1.5"><Clock className="h-3.5 w-3.5" /> {slotsUsed.map((s) => SLOT_LABEL[s]).join(', ')}</span>
                                    </div>
                                    <div className="mb-3 grid grid-cols-7 gap-1">
                                        {byDay.map((meals, d) => (
                                            <div key={d} className="rounded-md border border-border bg-muted/30 p-1">
                                                <div className="mb-0.5 text-center text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">{DOW[d]}</div>
                                                <div className="space-y-0.5">
                                                    {meals.slice(0, 2).map((m, i) => (
                                                        <div key={i} className="truncate rounded bg-sites-bg/70 px-1 py-0.5 text-[11px] font-medium leading-tight text-sites-deep" title={recipeName(m.recipe_id)}>{recipeName(m.recipe_id)}</div>
                                                    ))}
                                                    {meals.length === 0 && <div className="py-0.5 text-center text-[10px] text-muted-foreground/50">–</div>}
                                                    {meals.length > 2 && <div className="text-center text-[10px] text-muted-foreground">+{meals.length - 2} more</div>}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                    {templateAllergens(t).length > 0 && (
                                        <div className="mb-3 flex flex-wrap items-center gap-1">
                                            <span className="inline-flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wide text-status-critical"><ShieldAlert className="h-3 w-3" /> Allergens:</span>
                                            {templateAllergens(t).map((label) => (
                                                <span key={label} className="rounded-full bg-status-critical-bg px-1.5 py-px text-[10px] font-medium text-status-critical">{label}</span>
                                            ))}
                                        </div>
                                    )}
                                    <div className="mt-auto flex items-center gap-2">
                                        <Button size="sm" className="flex-1" onClick={() => setApplyTpl(t)}><CalendarCheck className="mr-1.5 h-[15px] w-[15px]" /> Apply to week</Button>
                                        {canManage && (
                                            <>
                                                <Button variant="outline" size="icon" className="h-8 w-8" onClick={() => duplicate(t)} aria-label="Duplicate"><Copy className="h-3.5 w-3.5" /></Button>
                                                <Button variant="outline" size="icon" className="h-8 w-8" onClick={() => setBuilder({ open: true, initial: t })} aria-label="Edit"><Pencil className="h-3.5 w-3.5" /></Button>
                                                <ConfirmAction title={`Delete “${t.name}”?`} description="This removes the template. Planned meals already applied stay on the calendar." confirmLabel="Delete" onConfirm={() => destroy(t)}>
                                                    <Button variant="outline" size="icon" className="h-8 w-8" aria-label="Delete"><Trash2 className="h-3.5 w-3.5 text-status-critical" /></Button>
                                                </ConfirmAction>
                                            </>
                                        )}
                                    </div>
                                </div>
                            </GuardrailCard>
                        );
                    })}
                </div>
            )}

            {applyTpl && <ApplyTemplateDialog tpl={applyTpl} weekLabel={weekLabel} rangeLabel={rangeLabel} residents={residents} recipeMap={recipeMap} onClose={() => setApplyTpl(null)} onConfirm={applyTemplate} />}
            {builder.open && <TemplateBuilderDialog siteId={siteId} recipes={recipes} initial={builder.initial} onClose={() => setBuilder({ open: false, initial: null })} onSaved={() => { setBuilder({ open: false, initial: null }); onChanged(); }} />}
        </div>
    );
}

function ApplyTemplateDialog({ tpl, weekLabel, rangeLabel, residents, recipeMap, onClose, onConfirm }: { tpl: WeekTemplate; weekLabel: string; rangeLabel: string; residents: Resident[]; recipeMap: RecipeMap; onClose: () => void; onConfirm: (t: WeekTemplate, replace: boolean) => void }) {
    const [mode, setMode] = useState<'replace' | 'merge'>('replace');
    // Pre-flight: how many of the template's meals would clash with current residents' allergens.
    const conflictCount = useMemo(() => {
        if (residents.length === 0) return 0;
        const clientIds = residents.map((r) => r.id);
        let n = 0;
        tpl.meals.forEach((m) => {
            const pseudo = { source_type: 'recipe', recipe_id: m.recipe_id, client_ids: clientIds } as PlanEntry;
            if (conflictsFor(pseudo, residents, recipeMap).hard.length) n++;
        });
        return n;
    }, [tpl, residents, recipeMap]);
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2"><CalendarCheck className="h-4 w-4 text-sites" /> Apply “{tpl.name}”</DialogTitle>
                    <DialogDescription>{tpl.meals.length} meals → {weekLabel} ({rangeLabel})</DialogDescription>
                </DialogHeader>
                {conflictCount > 0 && (
                    <div className="flex items-start gap-2 rounded-md border border-status-critical/40 bg-status-critical-bg/60 p-2.5 text-xs text-status-critical">
                        <ShieldAlert className="mt-0.5 h-4 w-4 shrink-0" />
                        <span>Heads up: {conflictCount} meal{conflictCount === 1 ? '' : 's'} conflict with residents' allergens. Review them after applying.</span>
                    </div>
                )}
                <div className="space-y-2">
                    <Button unstyled type="button" onClick={() => setMode('replace')} className={cn('flex w-full items-start gap-3 rounded-xl border p-3 text-left transition-all', mode === 'replace' ? 'border-primary bg-primary/5 ring-1 ring-primary/30' : 'border-border hover:bg-accent')}>
                        <RefreshCw className={cn('mt-0.5 h-4 w-4', mode === 'replace' ? 'text-primary' : 'text-muted-foreground')} />
                        <div><div className="text-[13.5px] font-medium text-foreground">Replace the week</div><div className="text-[12px] text-muted-foreground">Clears existing meals, then applies the template.</div></div>
                    </Button>
                    <Button unstyled type="button" onClick={() => setMode('merge')} className={cn('flex w-full items-start gap-3 rounded-xl border p-3 text-left transition-all', mode === 'merge' ? 'border-primary bg-primary/5 ring-1 ring-primary/30' : 'border-border hover:bg-accent')}>
                        <Plus className={cn('mt-0.5 h-4 w-4', mode === 'merge' ? 'text-primary' : 'text-muted-foreground')} />
                        <div><div className="text-[13.5px] font-medium text-foreground">Add to the week</div><div className="text-[12px] text-muted-foreground">Keeps existing meals and adds the template on top.</div></div>
                    </Button>
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>Cancel</Button>
                    <Button onClick={() => onConfirm(tpl, mode === 'replace')}><Check className="mr-1.5 h-4 w-4" /> Apply</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export function TemplateBuilderDialog({ siteId, recipes, initial, onClose, onSaved }: { siteId: number; recipes: RecipeFull[]; initial: WeekTemplate | null; onClose: () => void; onSaved: () => void }) {
    const [name, setName] = useState(initial?.name ?? '');
    const [description, setDescription] = useState(initial?.description ?? '');
    const [cells, setCells] = useState<Record<string, { recipe_id: number; servings: number }>>(() => {
        const m: Record<string, { recipe_id: number; servings: number }> = {};
        (initial?.meals ?? []).forEach((mm) => { m[`${mm.day}|${mm.slot}`] = { recipe_id: mm.recipe_id, servings: mm.servings }; });
        return m;
    });
    const [slots, setSlots] = useState<MealSlot[]>(() => {
        if (initial?.meals?.length) {
            const used = new Set(initial.meals.map((m) => m.slot));
            return MEAL_SLOTS.filter((s) => used.has(s) || (['breakfast', 'lunch', 'dinner'] as MealSlot[]).includes(s));
        }
        return ['breakfast', 'lunch', 'dinner'];
    });
    const [editing, setEditing] = useState<string | null>(null);
    const [fillRow, setFillRow] = useState<MealSlot | null>(null);
    const [saving, setSaving] = useState(false);

    const count = Object.keys(cells).length;
    const availableSlots = MEAL_SLOTS.filter((s) => !slots.includes(s));
    const recipeName = (id: number) => recipes.find((r) => r.id === id)?.name ?? 'Meal';
    const recipeServes = (id: number) => recipes.find((r) => r.id === id)?.serves_default ?? 5;

    function assign(day: number, slot: MealSlot, recipeId: number) {
        setCells((c) => ({ ...c, [`${day}|${slot}`]: { recipe_id: recipeId, servings: recipeServes(recipeId) } }));
        setEditing(null);
    }
    function clearCell(day: number, slot: MealSlot) {
        setCells((c) => {
            const n = { ...c };
            delete n[`${day}|${slot}`];
            return n;
        });
    }
    function setServings(day: number, slot: MealSlot, v: number) {
        setCells((c) => ({ ...c, [`${day}|${slot}`]: { ...c[`${day}|${slot}`], servings: Math.max(1, v) } }));
    }
    function fillRowWith(slot: MealSlot, recipeId: number) {
        setCells((c) => {
            const n = { ...c };
            for (let d = 0; d < 7; d++) n[`${d}|${slot}`] = { recipe_id: recipeId, servings: recipeServes(recipeId) };
            return n;
        });
        setFillRow(null);
    }
    function clearRow(slot: MealSlot) {
        setCells((c) => {
            const n = { ...c };
            for (let d = 0; d < 7; d++) delete n[`${d}|${slot}`];
            return n;
        });
    }
    function addSlot(s: MealSlot) {
        setSlots((cur) => MEAL_SLOTS.filter((x) => cur.includes(x) || x === s));
    }
    function removeSlot(s: MealSlot) {
        setSlots((cur) => cur.filter((x) => x !== s));
        clearRow(s);
    }

    async function save() {
        if (!name.trim() || count === 0) return;
        const meals: WeekTemplateMeal[] = Object.entries(cells).map(([k, v]) => {
            const [day, slot] = k.split('|');
            return { day: Number(day), slot: slot as MealSlot, recipe_id: v.recipe_id, servings: v.servings };
        });
        setSaving(true);
        try {
            const body = { name: name.trim(), description: description.trim() || `${meals.length} meals`, meals };
            if (initial) await axios.put(`/sites/${siteId}/meal-templates/${initial.id}`, body);
            else await axios.post(`/sites/${siteId}/meal-templates`, body);
            toast.success(initial ? 'Template updated' : 'Template created');
            onSaved();
        } catch {
            toast.error('Could not save template');
        } finally {
            setSaving(false);
        }
    }

    const recipeOpts = useMemo(() => recipes.map((r) => ({ value: r.id, label: r.name })), [recipes]);

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-4xl">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2"><LayoutTemplate className="h-4 w-4 text-sites" /> {initial ? 'Edit template' : 'Build a week template'}</DialogTitle>
                    <DialogDescription>Assign recipes to any day and meal slot. Apply it to any week later from “Plan week”.</DialogDescription>
                </DialogHeader>
                <div className="space-y-4">
                    <div className="grid gap-3 sm:grid-cols-[1fr_1.4fr]">
                        <div>
                            <Label>Template name <span className="text-status-critical">*</span></Label>
                            <Input value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Winter rotation" autoFocus />
                        </div>
                        <div>
                            <Label>Description <span className="font-normal text-muted-foreground">(optional)</span></Label>
                            <Input value={description} onChange={(e) => setDescription(e.target.value)} placeholder="e.g. Hearty mains, lighter lunches" />
                        </div>
                    </div>

                    <div className="nice-scroll overflow-x-auto rounded-xl border border-border">
                        <div className="min-w-[820px]">
                            <div className="grid grid-cols-[132px_repeat(7,1fr)] border-b border-border bg-muted/40">
                                <div className="px-3 py-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Meal</div>
                                {DOW.map((d) => <div key={d} className="border-l border-border px-2 py-2 text-center text-[12px] font-semibold text-foreground">{d}</div>)}
                            </div>
                            {slots.map((slot, si) => {
                                const SlotIcon = SLOT_ICON[slot];
                                return (
                                    <div key={slot} className={cn('grid grid-cols-[132px_repeat(7,1fr)] border-b border-border last:border-b-0', si % 2 === 1 && 'bg-muted/20')}>
                                        <div className="flex items-start gap-1.5 border-r border-border px-2.5 py-2">
                                            <SlotIcon className="mt-0.5 h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                                            <div className="min-w-0 flex-1">
                                                <div className="truncate text-[11.5px] font-semibold leading-tight text-foreground">{SLOT_LABEL[slot]}</div>
                                                <div className="flex items-center gap-1.5">
                                                    <Button unstyled type="button" onClick={() => setFillRow(fillRow === slot ? null : slot)} className="text-[10px] font-medium text-primary hover:underline">Fill all</Button>
                                                    {slots.length > 1 && <Button unstyled type="button" onClick={() => removeSlot(slot)} className="text-[10px] text-muted-foreground hover:text-status-critical">Remove</Button>}
                                                </div>
                                                {fillRow === slot && (
                                                    <div className="mt-1">
                                                        <Select value="" onValueChange={(v) => fillRowWith(slot, Number(v))}>
                                                            <SelectTrigger className="h-8 w-[150px] text-[12px]"><SelectValue placeholder="Pick recipe…" /></SelectTrigger>
                                                            <SelectContent>{recipeOpts.map((o) => <SelectItem key={o.value} value={String(o.value)}>{o.label}</SelectItem>)}</SelectContent>
                                                        </Select>
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                        {Array.from({ length: 7 }, (_, day) => {
                                            const key = `${day}|${slot}`;
                                            const cell = cells[key];
                                            const isEditing = editing === key;
                                            return (
                                                <div key={day} className="min-h-[58px] border-l border-border p-1">
                                                    {isEditing ? (
                                                        <Select value={cell ? String(cell.recipe_id) : ''} onValueChange={(v) => assign(day, slot, Number(v))}>
                                                            <SelectTrigger className="h-8 text-[11px]"><SelectValue placeholder="Pick…" /></SelectTrigger>
                                                            <SelectContent>{recipeOpts.map((o) => <SelectItem key={o.value} value={String(o.value)}>{o.label}</SelectItem>)}</SelectContent>
                                                        </Select>
                                                    ) : cell ? (
                                                        <div className="flex h-full flex-col rounded-md border border-sites/30 bg-sites-bg/50 px-1.5 py-1">
                                                            <Button unstyled type="button" onClick={() => setEditing(key)} className="line-clamp-2 flex-1 text-left text-[11px] font-medium leading-tight text-sites-deep">{recipeName(cell.recipe_id)}</Button>
                                                            <div className="mt-0.5 flex items-center justify-between">
                                                                <div className="flex items-center gap-0.5">
                                                                    <Button unstyled type="button" aria-label="Decrease servings" onClick={() => setServings(day, slot, cell.servings - 1)} className="flex h-6 w-6 items-center justify-center rounded text-muted-foreground transition-colors hover:bg-card focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"><Minus className="h-3 w-3" /></Button>
                                                                    <span className="w-4 text-center text-[10px] font-semibold tabular-nums text-foreground">{cell.servings}</span>
                                                                    <Button unstyled type="button" aria-label="Increase servings" onClick={() => setServings(day, slot, cell.servings + 1)} className="flex h-6 w-6 items-center justify-center rounded text-muted-foreground transition-colors hover:bg-card focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"><Plus className="h-3 w-3" /></Button>
                                                                </div>
                                                                <Button unstyled type="button" aria-label="Clear meal" onClick={() => clearCell(day, slot)} className="flex h-6 w-6 items-center justify-center rounded text-muted-foreground transition-colors hover:text-status-critical focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"><X className="h-3.5 w-3.5" /></Button>
                                                            </div>
                                                        </div>
                                                    ) : (
                                                        <Button unstyled type="button" onClick={() => setEditing(key)} className="flex h-full min-h-[50px] w-full items-center justify-center rounded-md border border-dashed border-border text-muted-foreground transition-colors hover:border-primary/50 hover:bg-primary/5 hover:text-primary"><Plus className="h-3.5 w-3.5" /></Button>
                                                    )}
                                                </div>
                                            );
                                        })}
                                    </div>
                                );
                            })}
                        </div>
                    </div>

                    {availableSlots.length > 0 && (
                        <div className="flex flex-wrap items-center gap-1.5">
                            <span className="text-[12px] text-muted-foreground">Add meal slot:</span>
                            {availableSlots.map((s) => (
                                <Button unstyled key={s} type="button" onClick={() => addSlot(s)} className="inline-flex items-center gap-1 rounded-full border border-border bg-card px-2.5 py-1 text-[11.5px] font-medium text-muted-foreground transition-colors hover:border-primary/50 hover:text-primary"><Plus className="h-3 w-3" /> {SLOT_LABEL[s]}</Button>
                            ))}
                        </div>
                    )}
                </div>
                <DialogFooter className="sm:justify-between">
                    <span className="text-[12.5px] text-muted-foreground">{count} meal{count === 1 ? '' : 's'} placed</span>
                    <div className="flex gap-2">
                        <Button variant="outline" onClick={onClose}>Cancel</Button>
                        <Button onClick={save} disabled={!name.trim() || count === 0 || saving}><Check className="mr-1.5 h-4 w-4" /> {initial ? 'Save changes' : 'Create template'}</Button>
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
