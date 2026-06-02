import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { PageHero } from '@/components/page';
import { Head, router, useForm } from '@inertiajs/react';
import { ChefHat, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { CateringTabs } from '../_tabs';
import { type DietaryTag, type Recipe, type RecipeIngredient, tagBadgeStyle } from '../_helpers';

type ProductOpt = { id: number; name: string; default_unit: string };

type Props = {
    recipe: Recipe | null;
    tags: DietaryTag[];
    products: ProductOpt[];
};

const UNIT_OPTIONS = ['each', 'kg', 'g', 'L', 'ml', 'tsp', 'tbsp', 'cup', 'pack', 'tin'];

export default function CateringRecipeEdit({ recipe, tags, products }: Props) {
    const isNew = !recipe;

    const initialIngredients: RecipeIngredient[] = useMemo(() => {
        return (recipe?.ingredients ?? []).map((i) => ({
            product_id: i.product_id ?? null,
            free_text_name: i.free_text_name ?? null,
            quantity: i.quantity,
            unit: i.unit,
            notes: i.notes ?? null,
        }));
    }, [recipe]);

    const initialTagIds: number[] = useMemo(() => {
        return (recipe?.tags ?? []).map((t) => t.id);
    }, [recipe]);

    const form = useForm({
        name: recipe?.name ?? '',
        description: recipe?.description ?? '',
        serves_default: recipe?.serves_default ?? 4,
        prep_minutes: recipe?.prep_minutes ?? '',
        cook_minutes: recipe?.cook_minutes ?? '',
        instructions: recipe?.instructions ?? '',
        is_active: recipe?.is_active ?? true,
        tag_ids: initialTagIds,
        ingredients: initialIngredients,
    });

    const [productSearch, setProductSearch] = useState('');

    function addIngredient() {
        const next = [...form.data.ingredients, { product_id: null, free_text_name: '', quantity: 1, unit: 'each', notes: null }];
        form.setData('ingredients', next);
    }

    function updateIngredient(idx: number, patch: Partial<RecipeIngredient>) {
        const next = form.data.ingredients.map((row, i) => (i === idx ? { ...row, ...patch } : row));
        form.setData('ingredients', next);
    }

    function removeIngredient(idx: number) {
        form.setData('ingredients', form.data.ingredients.filter((_, i) => i !== idx));
    }

    function toggleTag(id: number) {
        const current = form.data.tag_ids;
        form.setData('tag_ids', current.includes(id) ? current.filter((x) => x !== id) : [...current, id]);
    }

    function submit(e: React.FormEvent) {
        e.preventDefault();
        if (isNew) {
            form.post('/catering/recipes');
        } else {
            form.put(`/catering/recipes/${recipe!.id}`);
        }
    }

    function cancel() {
        router.visit(isNew ? '/catering/recipes' : `/catering/recipes/${recipe!.id}`);
    }

    const filteredProducts = useMemo(() => {
        if (!productSearch.trim()) return products;
        const needle = productSearch.toLowerCase();
        return products.filter((p) => p.name.toLowerCase().includes(needle));
    }, [productSearch, products]);

    return (
        <AppLayout breadcrumbs={[
            { title: 'Sites & Locations', href: '/sites' },
            { title: 'Catering', href: '/catering' },
            { title: 'Recipes', href: '/catering/recipes' },
            { title: isNew ? 'New recipe' : (recipe!.name), href: isNew ? '/catering/recipes/create' : `/catering/recipes/${recipe!.id}/edit` },
        ]}>
            <Head title={isNew ? 'New recipe' : `Edit ${recipe!.name}`} />
            <form onSubmit={submit} className="space-y-6 p-6">
                <PageHero
                    icon={ChefHat}
                    title={isNew ? 'New recipe' : `Edit ${recipe!.name}`}
                    description="Recipes are reusable across all sites for meal planning."
                />
                <CateringTabs active="recipes" />

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <div className="lg:col-span-2 space-y-4 rounded-md border p-4">
                        <div>
                            <Label>Name</Label>
                            <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                        </div>
                        <div>
                            <Label>Description</Label>
                            <Textarea value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} rows={2} />
                        </div>
                        <div className="grid grid-cols-3 gap-3">
                            <div>
                                <Label>Serves</Label>
                                <Input type="number" min={1} value={form.data.serves_default} onChange={(e) => form.setData('serves_default', Number(e.target.value))} />
                            </div>
                            <div>
                                <Label>Prep (min)</Label>
                                <Input type="number" min={0} value={form.data.prep_minutes} onChange={(e) => form.setData('prep_minutes', e.target.value === '' ? '' : Number(e.target.value))} />
                            </div>
                            <div>
                                <Label>Cook (min)</Label>
                                <Input type="number" min={0} value={form.data.cook_minutes} onChange={(e) => form.setData('cook_minutes', e.target.value === '' ? '' : Number(e.target.value))} />
                            </div>
                        </div>
                        <div>
                            <Label>Instructions</Label>
                            <Textarea value={form.data.instructions} onChange={(e) => form.setData('instructions', e.target.value)} rows={10} />
                        </div>
                        <div className="flex items-center gap-2">
                            <input id="is_active" type="checkbox" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />
                            <Label htmlFor="is_active">Active (available in meal planner)</Label>
                        </div>
                    </div>

                    <div className="space-y-4 rounded-md border p-4">
                        <div>
                            <Label>Tags</Label>
                            <div className="mt-1 flex flex-wrap gap-1">
                                {tags.map((t) => {
                                    const selected = form.data.tag_ids.includes(t.id);
                                    return (
                                        <button
                                            key={t.id}
                                            type="button"
                                            onClick={() => toggleTag(t.id)}
                                            className={`rounded-md border px-2 py-1 text-xs transition ${selected ? 'border-primary bg-primary/10' : 'border-transparent hover:bg-accent'}`}
                                            style={tagBadgeStyle(t)}
                                        >
                                            {t.label}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    </div>
                </div>

                <div className="rounded-md border p-4">
                    <div className="mb-3 flex items-center justify-between">
                        <h2 className="text-lg font-medium">Ingredients</h2>
                        <Button type="button" size="sm" variant="outline" onClick={addIngredient}><Plus className="mr-2 h-4 w-4" /> Add ingredient</Button>
                    </div>
                    <div className="mb-3">
                        <Input value={productSearch} onChange={(e) => setProductSearch(e.target.value)} placeholder="Search products (filter the dropdown below)" />
                    </div>
                    <div className="space-y-2">
                        {form.data.ingredients.length === 0 && (
                            <p className="text-sm text-muted-foreground">No ingredients yet. Add at least one.</p>
                        )}
                        {form.data.ingredients.map((ing, idx) => (
                            <div key={idx} className="grid grid-cols-12 items-end gap-2 rounded-md border p-2">
                                <div className="col-span-5">
                                    <Label className="text-xs">Product</Label>
                                    <Select
                                        value={ing.product_id ? String(ing.product_id) : 'free'}
                                        onValueChange={(v) => updateIngredient(idx, { product_id: v === 'free' ? null : Number(v) })}
                                    >
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="free">— Free text —</SelectItem>
                                            {filteredProducts.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}
                                        </SelectContent>
                                    </Select>
                                    {!ing.product_id && (
                                        <Input
                                            className="mt-1"
                                            placeholder="Free-text ingredient name"
                                            value={ing.free_text_name ?? ''}
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
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            {UNIT_OPTIONS.map((u) => <SelectItem key={u} value={u}>{u}</SelectItem>)}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="col-span-2">
                                    <Label className="text-xs">Notes</Label>
                                    <Input value={ing.notes ?? ''} onChange={(e) => updateIngredient(idx, { notes: e.target.value })} placeholder="optional" />
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

                <div className="flex justify-end gap-2">
                    <Button type="button" variant="ghost" onClick={cancel}>Cancel</Button>
                    <Button type="submit" disabled={form.processing}>{isNew ? 'Create recipe' : 'Save changes'}</Button>
                </div>
            </form>
        </AppLayout>
    );
}
