import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import axios, { AxiosError } from 'axios';
import { BookPlus, Home, Leaf, Library, Loader2, Pencil, Plus, ShieldAlert, Trash2, X, type LucideIcon } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import type { RecipeFull } from './_helpers';

type ProductOpt = { id: number; name: string; default_unit: string };
type TagOpt = { id: number; label: string; kind: 'allergen' | 'dietary'; severity?: string };

type IngredientRow = {
    product_id: number | null;
    name: string;
    qty: number | string;
    unit: string;
};

type FormData = {
    name: string;
    category: string;
    scope: 'house' | 'shared';
    serves_default: number;
    prep_minutes: number | string;
    cook_minutes: number | string;
    instructions: string;
    is_active: boolean;
    tag_ids: number[];
    ingredients: IngredientRow[];
};

type Props = {
    open: boolean;
    /** The recipe to edit, or null to add a new one. */
    recipe: RecipeFull | null;
    products: ProductOpt[];
    tags: TagOpt[];
    siteId: number;
    siteName: string;
    /** Gates the Delete action (catering.recipes.manage). */
    canManage: boolean;
    onClose: () => void;
    onSaved: () => void;
};

const RECIPE_CATEGORIES = ['Mains', 'Breakfast', 'Soups', 'Baking', 'Sides', 'Desserts'];
const UNIT_OPTIONS = ['each', 'kg', 'g', 'L', 'ml', 'pack', 'tin', 'bottle', 'bunch'];

function blankIngredient(): IngredientRow {
    return { product_id: null, name: '', qty: 1, unit: 'each' };
}

function initialData(recipe: RecipeFull | null): FormData {
    if (!recipe) {
        return {
            name: '',
            category: 'Mains',
            scope: 'house',
            serves_default: 6,
            prep_minutes: 10,
            cook_minutes: 20,
            instructions: '',
            is_active: true,
            tag_ids: [],
            ingredients: [blankIngredient()],
        };
    }
    return {
        name: recipe.name ?? '',
        category: recipe.category ?? 'Mains',
        scope: recipe.scope ?? 'house',
        serves_default: recipe.serves_default ?? 6,
        prep_minutes: recipe.prep_minutes ?? '',
        cook_minutes: recipe.cook_minutes ?? '',
        instructions: recipe.instructions ?? '',
        is_active: recipe.is_active ?? true,
        tag_ids: recipe.tag_ids ?? [],
        ingredients: recipe.ingredients.length
            ? recipe.ingredients.map((i) => ({ product_id: i.product_id, name: i.name ?? '', qty: i.qty, unit: i.unit }))
            : [blankIngredient()],
    };
}

export default function RecipeEditDialog(props: Props) {
    return (
        <Dialog open={props.open} onOpenChange={(o) => !o && props.onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto" style={{ maxWidth: 'min(92vw, 720px)', width: 'min(92vw, 720px)' }}>
                {props.open && <RecipeEditBody {...props} />}
            </DialogContent>
        </Dialog>
    );
}

function RecipeEditBody({ recipe, products, tags, siteId, siteName, canManage, onClose, onSaved }: Props) {
    const isNew = recipe == null;
    const allergenTags = tags.filter((t) => t.kind === 'allergen');
    const dietaryTagOpts = tags.filter((t) => t.kind === 'dietary');
    const [data, setData] = useState<FormData>(() => initialData(recipe));
    const [saving, setSaving] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);

    const availability: { value: 'house' | 'shared'; icon: LucideIcon; title: string; description: string }[] = [
        { value: 'house', icon: Home, title: siteName || 'This house', description: 'Only this house sees this recipe.' },
        { value: 'shared', icon: Library, title: 'Shared library', description: 'Added to the org-wide library for every site.' },
    ];

    function patch(p: Partial<FormData>) {
        setData((d) => ({ ...d, ...p }));
    }
    function updateIngredient(idx: number, p: Partial<IngredientRow>) {
        patch({ ingredients: data.ingredients.map((row, i) => (i === idx ? { ...row, ...p } : row)) });
    }
    function addIngredient() {
        patch({ ingredients: [...data.ingredients, blankIngredient()] });
    }
    function removeIngredient(idx: number) {
        patch({ ingredients: data.ingredients.filter((_, i) => i !== idx) });
    }
    function toggleTag(id: number) {
        patch({ tag_ids: data.tag_ids.includes(id) ? data.tag_ids.filter((x) => x !== id) : [...data.tag_ids, id] });
    }

    const valid = data.name.trim() !== '' && data.ingredients.some((i) => i.product_id != null || i.name.trim() !== '');

    async function submit(e: React.FormEvent) {
        e.preventDefault();
        if (!valid) {
            toast.error('Give the recipe a name and at least one ingredient');
            return;
        }
        setSaving(true);
        const payload = {
            name: data.name.trim(),
            category: data.category,
            scope: data.scope,
            site_id: data.scope === 'house' ? siteId : null,
            serves_default: data.serves_default,
            prep_minutes: data.prep_minutes === '' ? null : Number(data.prep_minutes),
            cook_minutes: data.cook_minutes === '' ? null : Number(data.cook_minutes),
            instructions: data.instructions,
            is_active: data.is_active,
            tag_ids: data.tag_ids,
            ingredients: data.ingredients
                .filter((i) => i.product_id != null || i.name.trim() !== '')
                .map((i) => ({
                    product_id: i.product_id,
                    free_text_name: i.product_id ? null : i.name.trim() || null,
                    quantity: i.qty === '' ? 0 : Number(i.qty),
                    unit: i.unit,
                })),
        };
        try {
            if (isNew) await axios.post('/catering/recipes', payload);
            else await axios.put(`/catering/recipes/${recipe.id}`, payload);
            toast.success(isNew ? 'Recipe added' : 'Recipe saved');
            onSaved();
            onClose();
        } catch (err) {
            const ax = err as AxiosError<{ errors?: Record<string, string[]>; message?: string }>;
            const errors = ax.response?.data?.errors;
            const first = errors ? Object.values(errors)[0]?.[0] : ax.response?.data?.message;
            toast.error(first || 'Could not save the recipe');
        } finally {
            setSaving(false);
        }
    }

    async function doDelete() {
        if (isNew) return;
        setDeleting(true);
        try {
            await axios.delete(`/catering/recipes/${recipe.id}`, { headers: { Accept: 'application/json' } });
            toast.success('Recipe deleted');
            onSaved();
            onClose();
        } catch {
            toast.error('Could not delete the recipe');
        } finally {
            setDeleting(false);
        }
    }

    return (
        <form onSubmit={submit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    {isNew ? <BookPlus className="h-4 w-4 text-sites" /> : <Pencil className="h-4 w-4 text-sites" />}
                    {isNew ? 'Add recipe' : 'Edit recipe'}
                </DialogTitle>
                <DialogDescription>
                    Recipes power meal planning and the stock check. Link ingredients to inventory products to track stock automatically.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3 space-y-4">
                {/* name + category */}
                <div className="grid gap-3 sm:grid-cols-[1fr_160px]">
                    <div>
                        <Label>
                            Recipe name <span className="text-status-critical">*</span>
                        </Label>
                        <Input className="mt-1" value={data.name} onChange={(e) => patch({ name: e.target.value })} placeholder="e.g. Creamy chicken & leek pie" autoFocus />
                    </div>
                    <div>
                        <Label>Category</Label>
                        <Select value={data.category} onValueChange={(v) => patch({ category: v })}>
                            <SelectTrigger className="mt-1">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {RECIPE_CATEGORIES.map((c) => (
                                    <SelectItem key={c} value={c}>
                                        {c}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                {/* serves / prep / cook */}
                <div className="grid grid-cols-3 gap-3">
                    <div>
                        <Label>Serves</Label>
                        <Input className="mt-1" type="number" min={1} value={data.serves_default} onChange={(e) => patch({ serves_default: Number(e.target.value) })} />
                    </div>
                    <div>
                        <Label>Prep (min)</Label>
                        <Input className="mt-1" type="number" min={0} value={data.prep_minutes} onChange={(e) => patch({ prep_minutes: e.target.value })} />
                    </div>
                    <div>
                        <Label>Cook (min)</Label>
                        <Input className="mt-1" type="number" min={0} value={data.cook_minutes} onChange={(e) => patch({ cook_minutes: e.target.value })} />
                    </div>
                </div>

                {/* availability */}
                <div>
                    <Label>Availability</Label>
                    <div className="mt-1.5 grid grid-cols-2 gap-2">
                        {availability.map((opt) => {
                            const Icon = opt.icon;
                            const active = data.scope === opt.value;
                            return (
                                <button
                                    key={opt.value}
                                    type="button"
                                    onClick={() => patch({ scope: opt.value })}
                                    className={cn(
                                        'group flex items-start gap-2 rounded-xl border bg-card/40 p-3 text-left transition-all hover:bg-card focus:outline-none focus-visible:ring-2 focus-visible:ring-sites',
                                        active ? 'border-sites bg-sites/10 ring-1 ring-sites/40' : 'border-border hover:border-sites/50',
                                    )}
                                    aria-pressed={active}
                                >
                                    <span className="mt-0.5 shrink-0 rounded-lg bg-background/60 p-1.5">
                                        <Icon className="h-4 w-4 text-sites" />
                                    </span>
                                    <span className="min-w-0">
                                        <span className="block truncate text-sm font-medium">{opt.title}</span>
                                        <span className="block text-xs text-muted-foreground">{opt.description}</span>
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                </div>

                {/* dietary & allergen tags — split so the safety weight of allergens is unmissable */}
                <div className="space-y-3">
                    <div>
                        <Label className="flex items-center gap-1.5 text-status-critical"><ShieldAlert className="h-3.5 w-3.5" /> Contains allergens</Label>
                        <p className="mt-0.5 text-[11px] text-muted-foreground">Allergen tags drive the safety check shown when planning meals.</p>
                        <div role="group" aria-label="Allergen tags" className="mt-1.5 flex flex-wrap gap-1.5">
                            {allergenTags.length === 0 && <span className="text-xs text-muted-foreground">No allergen tags are set up yet.</span>}
                            {allergenTags.map((t) => {
                                const sel = data.tag_ids.includes(t.id);
                                const critical = t.severity === 'critical';
                                return (
                                    <button
                                        key={t.id}
                                        type="button"
                                        aria-pressed={sel}
                                        aria-label={`${t.label}, allergen${critical ? ', critical' : ''}`}
                                        onClick={() => toggleTag(t.id)}
                                        className={cn(
                                            'inline-flex min-h-6 items-center gap-1 rounded-full border px-2.5 py-1 text-[12px] font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                            sel ? 'border-status-critical bg-status-critical-bg text-status-critical' : 'border-border bg-card text-muted-foreground hover:bg-accent',
                                        )}
                                    >
                                        <ShieldAlert className="h-3 w-3" aria-hidden="true" /> {t.label}
                                        {critical && <span className="rounded-full bg-status-critical px-1 text-[8.5px] font-bold uppercase leading-tight text-white">Critical</span>}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                    <div>
                        <Label className="flex items-center gap-1.5"><Leaf className="h-3.5 w-3.5 text-sites" /> Dietary</Label>
                        <div role="group" aria-label="Dietary tags" className="mt-1.5 flex flex-wrap gap-1.5">
                            {dietaryTagOpts.length === 0 && <span className="text-xs text-muted-foreground">No dietary tags are set up yet.</span>}
                            {dietaryTagOpts.map((t) => {
                                const sel = data.tag_ids.includes(t.id);
                                return (
                                    <button
                                        key={t.id}
                                        type="button"
                                        aria-pressed={sel}
                                        aria-label={`${t.label}, dietary`}
                                        onClick={() => toggleTag(t.id)}
                                        className={cn(
                                            'inline-flex min-h-6 items-center rounded-full border px-2.5 py-1 text-[12px] font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                            sel ? 'border-sites bg-sites-bg text-sites-deep' : 'border-border bg-card text-muted-foreground hover:bg-accent',
                                        )}
                                    >
                                        {t.label}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                </div>

                {/* ingredients */}
                <div>
                    <div className="mb-1.5 flex items-center justify-between">
                        <Label className="mb-0">Ingredients</Label>
                        <span className="text-[11px] text-muted-foreground">Link to a product to enable stock checks</span>
                    </div>
                    <div className="space-y-2">
                        {data.ingredients.map((ing, idx) => (
                            <div key={idx} className="space-y-1.5">
                                <div className="grid grid-cols-[1fr_72px_92px_36px] gap-2">
                                    <Select
                                        value={ing.product_id ? String(ing.product_id) : 'custom'}
                                        onValueChange={(v) => {
                                            if (v === 'custom') {
                                                updateIngredient(idx, { product_id: null });
                                            } else {
                                                const p = products.find((x) => x.id === Number(v));
                                                updateIngredient(idx, { product_id: Number(v), name: p?.name ?? ing.name, unit: p?.default_unit ?? ing.unit });
                                            }
                                        }}
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="custom">Custom item (not tracked)</SelectItem>
                                            {products.map((p) => (
                                                <SelectItem key={p.id} value={String(p.id)}>
                                                    {p.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <Input type="number" step="0.1" min={0} value={ing.qty} onChange={(e) => updateIngredient(idx, { qty: e.target.value })} />
                                    <Select value={ing.unit} onValueChange={(v) => updateIngredient(idx, { unit: v })}>
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {UNIT_OPTIONS.map((u) => (
                                                <SelectItem key={u} value={u}>
                                                    {u}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <Button type="button" variant="ghost" size="icon" className="h-9 w-9" onClick={() => removeIngredient(idx)} aria-label="Remove ingredient">
                                        <X className="h-4 w-4 text-muted-foreground" />
                                    </Button>
                                </div>
                                {ing.product_id == null && (
                                    <Input
                                        value={ing.name}
                                        onChange={(e) => updateIngredient(idx, { name: e.target.value })}
                                        placeholder={`Custom item name (row ${idx + 1})`}
                                        className="h-9 text-[13px]"
                                    />
                                )}
                            </div>
                        ))}
                    </div>
                    <Button type="button" variant="outline" size="sm" className="mt-2" onClick={addIngredient}>
                        <Plus className="mr-1.5 h-3.5 w-3.5" /> Add ingredient
                    </Button>
                </div>

                {/* method */}
                <div>
                    <Label>
                        Method <span className="font-normal text-muted-foreground">(optional)</span>
                    </Label>
                    <Textarea className="mt-1" rows={3} value={data.instructions} onChange={(e) => patch({ instructions: e.target.value })} placeholder="Short cooking steps…" />
                </div>

                {/* active / draft */}
                <label className="flex items-start gap-2 rounded-lg border border-border bg-muted/30 p-2.5 text-sm">
                    <input type="checkbox" className="mt-0.5" checked={data.is_active} onChange={(e) => patch({ is_active: e.target.checked })} />
                    <span>
                        <span className="font-medium text-foreground">Active</span>
                        <span className="ml-1 font-normal text-muted-foreground">— available to plan meals from. Uncheck to keep it as a draft.</span>
                    </span>
                </label>
            </div>

            <DialogFooter className="mt-4 sm:justify-between">
                <div className="flex items-center">
                    {!isNew &&
                        canManage &&
                        (confirmDelete ? (
                            <div className="flex items-center gap-2">
                                <span className="text-[13px] text-muted-foreground">Delete this recipe?</span>
                                <Button type="button" variant="ghost" size="sm" onClick={() => setConfirmDelete(false)} disabled={deleting}>
                                    Cancel
                                </Button>
                                <Button type="button" variant="destructive" size="sm" onClick={doDelete} disabled={deleting}>
                                    {deleting && <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />} Delete
                                </Button>
                            </div>
                        ) : (
                            <Button
                                type="button"
                                variant="ghost"
                                className="text-status-critical hover:bg-status-critical-bg hover:text-status-critical"
                                onClick={() => setConfirmDelete(true)}
                            >
                                <Trash2 className="mr-1.5 h-4 w-4" /> Delete
                            </Button>
                        ))}
                </div>
                <div className="flex gap-2">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={!valid || saving}>
                        {saving && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                        {isNew ? 'Add recipe' : 'Save recipe'}
                    </Button>
                </div>
            </DialogFooter>
        </form>
    );
}
