import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import axios, { AxiosError } from 'axios';
import { ChefHat, Loader2, Plus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

type ProductOpt = { id: number; name: string; default_unit: string };
type TagOpt = { id: number; label: string; kind: 'allergen' | 'dietary' };

type IngredientRow = {
    product_id: number | null;
    free_text_name: string;
    quantity: number | string;
    unit: string;
    notes: string;
};

type FormData = {
    name: string;
    description: string;
    serves_default: number;
    prep_minutes: number | string;
    cook_minutes: number | string;
    instructions: string;
    is_active: boolean;
    tag_ids: number[];
    ingredients: IngredientRow[];
};

type FetchedRecipe = {
    name: string;
    description: string | null;
    serves_default: number | null;
    prep_minutes: number | null;
    cook_minutes: number | null;
    instructions: string | null;
    is_active: boolean;
    tags: { id: number }[];
    ingredients: { product_id: number | null; free_text_name: string | null; quantity: number | string; unit: string; notes: string | null }[];
};

type Props = {
    open: boolean;
    recipeId: number | null;
    products: ProductOpt[];
    tags: TagOpt[];
    onClose: () => void;
    onSaved: () => void;
};

const UNIT_OPTIONS = ['each', 'kg', 'g', 'L', 'ml', 'tsp', 'tbsp', 'cup', 'pack', 'tin'];

const BLANK: FormData = {
    name: '',
    description: '',
    serves_default: 4,
    prep_minutes: '',
    cook_minutes: '',
    instructions: '',
    is_active: true,
    tag_ids: [],
    ingredients: [],
};

export default function RecipeEditDialog({ open, recipeId, products, tags, onClose, onSaved }: Props) {
    const isNew = recipeId == null;
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [data, setData] = useState<FormData>({ ...BLANK });
    const [productSearch, setProductSearch] = useState('');

    useEffect(() => {
        if (!open) return;
        setProductSearch('');
        if (isNew) {
            setData({ ...BLANK, ingredients: [] });
            return;
        }
        setLoading(true);
        axios
            .get(`/catering/recipes/${recipeId}/edit`, { headers: { Accept: 'application/json' } })
            .then((res) => {
                const r = res.data.recipe as FetchedRecipe;
                setData({
                    name: r.name ?? '',
                    description: r.description ?? '',
                    serves_default: r.serves_default ?? 4,
                    prep_minutes: r.prep_minutes ?? '',
                    cook_minutes: r.cook_minutes ?? '',
                    instructions: r.instructions ?? '',
                    is_active: !!r.is_active,
                    tag_ids: (r.tags ?? []).map((t) => t.id),
                    ingredients: (r.ingredients ?? []).map((i) => ({
                        product_id: i.product_id ?? null,
                        free_text_name: i.free_text_name ?? '',
                        quantity: i.quantity,
                        unit: i.unit,
                        notes: i.notes ?? '',
                    })),
                });
            })
            .catch(() => toast.error('Could not load the recipe'))
            .finally(() => setLoading(false));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, recipeId]);

    function patch(p: Partial<FormData>) {
        setData((d) => ({ ...d, ...p }));
    }
    function addIngredient() {
        patch({ ingredients: [...data.ingredients, { product_id: null, free_text_name: '', quantity: 1, unit: 'each', notes: '' }] });
    }
    function updateIngredient(idx: number, p: Partial<IngredientRow>) {
        patch({ ingredients: data.ingredients.map((row, i) => (i === idx ? { ...row, ...p } : row)) });
    }
    function removeIngredient(idx: number) {
        patch({ ingredients: data.ingredients.filter((_, i) => i !== idx) });
    }
    function toggleTag(id: number) {
        patch({ tag_ids: data.tag_ids.includes(id) ? data.tag_ids.filter((x) => x !== id) : [...data.tag_ids, id] });
    }

    async function submit(e: React.FormEvent) {
        e.preventDefault();
        if (!data.name.trim()) {
            toast.error('Give the recipe a name');
            return;
        }
        setSaving(true);
        const payload = {
            name: data.name,
            description: data.description,
            serves_default: data.serves_default,
            prep_minutes: data.prep_minutes === '' ? null : Number(data.prep_minutes),
            cook_minutes: data.cook_minutes === '' ? null : Number(data.cook_minutes),
            instructions: data.instructions,
            is_active: data.is_active,
            tag_ids: data.tag_ids,
            ingredients: data.ingredients.map((i) => ({
                product_id: i.product_id,
                free_text_name: i.product_id ? null : i.free_text_name || null,
                quantity: i.quantity === '' ? 0 : Number(i.quantity),
                unit: i.unit,
                notes: i.notes || null,
            })),
        };
        try {
            if (isNew) await axios.post('/catering/recipes', payload);
            else await axios.put(`/catering/recipes/${recipeId}`, payload);
            toast.success(isNew ? 'Recipe created' : 'Recipe saved');
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

    const filteredProducts = productSearch.trim()
        ? products.filter((p) => p.name.toLowerCase().includes(productSearch.toLowerCase()))
        : products;

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <ChefHat className="h-4 w-4 text-sites" /> {isNew ? 'New recipe' : 'Edit recipe'}
                    </DialogTitle>
                </DialogHeader>
                {loading ? (
                    <div className="flex items-center justify-center gap-2 py-16 text-sm text-muted-foreground">
                        <Loader2 className="h-4 w-4 animate-spin" /> Loading recipe…
                    </div>
                ) : (
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                            <div className="space-y-3 lg:col-span-2">
                                <div>
                                    <Label>Name</Label>
                                    <Input value={data.name} onChange={(e) => patch({ name: e.target.value })} required />
                                </div>
                                <div>
                                    <Label>Description</Label>
                                    <Textarea value={data.description} onChange={(e) => patch({ description: e.target.value })} rows={2} />
                                </div>
                                <div className="grid grid-cols-3 gap-3">
                                    <div>
                                        <Label>Serves</Label>
                                        <Input type="number" min={1} value={data.serves_default} onChange={(e) => patch({ serves_default: Number(e.target.value) })} />
                                    </div>
                                    <div>
                                        <Label>Prep (min)</Label>
                                        <Input type="number" min={0} value={data.prep_minutes} onChange={(e) => patch({ prep_minutes: e.target.value })} />
                                    </div>
                                    <div>
                                        <Label>Cook (min)</Label>
                                        <Input type="number" min={0} value={data.cook_minutes} onChange={(e) => patch({ cook_minutes: e.target.value })} />
                                    </div>
                                </div>
                                <div>
                                    <Label>Instructions</Label>
                                    <Textarea value={data.instructions} onChange={(e) => patch({ instructions: e.target.value })} rows={6} />
                                </div>
                                <label className="flex items-center gap-2 text-sm">
                                    <input type="checkbox" checked={data.is_active} onChange={(e) => patch({ is_active: e.target.checked })} />
                                    Active (available in the meal planner)
                                </label>
                            </div>
                            <div>
                                <Label>Dietary &amp; allergen tags</Label>
                                <div className="mt-1 flex flex-wrap gap-1 rounded-md border border-border p-2">
                                    {tags.length === 0 && <span className="text-xs text-muted-foreground">No tags yet.</span>}
                                    {tags.map((t) => {
                                        const selected = data.tag_ids.includes(t.id);
                                        return (
                                            <button
                                                key={t.id}
                                                type="button"
                                                onClick={() => toggleTag(t.id)}
                                                className={cn(
                                                    'rounded-md border px-2 py-1 text-xs transition',
                                                    selected ? 'border-primary bg-primary/10 text-primary' : 'border-border text-muted-foreground hover:bg-accent',
                                                )}
                                            >
                                                {t.label}
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>
                        </div>

                        <div className="rounded-md border border-border p-3">
                            <div className="mb-2 flex items-center justify-between">
                                <h3 className="text-sm font-medium">Ingredients</h3>
                                <Button type="button" size="sm" variant="outline" onClick={addIngredient}>
                                    <Plus className="mr-1.5 h-3.5 w-3.5" /> Add
                                </Button>
                            </div>
                            {products.length > 8 && (
                                <Input className="mb-2" value={productSearch} onChange={(e) => setProductSearch(e.target.value)} placeholder="Filter the product dropdowns…" />
                            )}
                            {data.ingredients.length === 0 && <p className="text-sm text-muted-foreground">No ingredients yet.</p>}
                            <div className="space-y-2">
                                {data.ingredients.map((ing, idx) => (
                                    <div key={idx} className="grid grid-cols-12 items-end gap-2 rounded-md border border-border p-2">
                                        <div className="col-span-5">
                                            <Label className="text-xs">Product</Label>
                                            <Select
                                                value={ing.product_id ? String(ing.product_id) : 'free'}
                                                onValueChange={(v) => updateIngredient(idx, { product_id: v === 'free' ? null : Number(v) })}
                                            >
                                                <SelectTrigger>
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="free">— Free text —</SelectItem>
                                                    {filteredProducts.map((p) => (
                                                        <SelectItem key={p.id} value={String(p.id)}>
                                                            {p.name}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            {!ing.product_id && (
                                                <Input
                                                    className="mt-1"
                                                    placeholder="Ingredient name"
                                                    value={ing.free_text_name}
                                                    onChange={(e) => updateIngredient(idx, { free_text_name: e.target.value })}
                                                />
                                            )}
                                        </div>
                                        <div className="col-span-2">
                                            <Label className="text-xs">Qty</Label>
                                            <Input type="number" step="0.01" min={0} value={ing.quantity} onChange={(e) => updateIngredient(idx, { quantity: e.target.value })} />
                                        </div>
                                        <div className="col-span-2">
                                            <Label className="text-xs">Unit</Label>
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
                                        </div>
                                        <div className="col-span-2">
                                            <Label className="text-xs">Notes</Label>
                                            <Input value={ing.notes} onChange={(e) => updateIngredient(idx, { notes: e.target.value })} placeholder="optional" />
                                        </div>
                                        <div className="col-span-1 flex justify-end">
                                            <Button type="button" size="icon" variant="ghost" onClick={() => removeIngredient(idx)}>
                                                <Trash2 className="h-4 w-4 text-destructive" />
                                            </Button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={onClose}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={saving}>
                                {saving ? 'Saving…' : isNew ? 'Create recipe' : 'Save changes'}
                            </Button>
                        </DialogFooter>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}
