import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { Head, router, useForm } from '@inertiajs/react';
import { Package, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ConfirmAction } from '@/pages/sites/_confirm-action';
import { CateringTabs, LibraryDeprecationNotice } from '../_tabs';
import { type DietaryTag, type Product, formatMoneyFromCents, tagBadgeStyle } from '../_helpers';

type PaginationLink = { url: string | null; label: string; active: boolean };
type Paginated<T> = { data: T[]; links: PaginationLink[]; last_page?: number };

type Props = {
    products: Paginated<Product>;
    categories: string[];
    tags: DietaryTag[];
    filters: { q: string; category: string; inactive: boolean };
    canManage: boolean;
};

type Editing = Partial<Product> & { _isNew?: boolean; tag_ids?: number[] };

const UNIT_OPTIONS = ['each', 'kg', 'g', 'L', 'ml', 'pack', 'bottle', 'tin', 'box'];

export default function CateringProductsIndex({ products, categories, tags, filters, canManage }: Props) {
    const [editing, setEditing] = useState<Editing | null>(null);
    const [search, setSearch] = useState(filters.q ?? '');
    const [category, setCategory] = useState(filters.category ?? '');
    // Cost is entered in dollars (NZD) but submitted as integer cents (server rule unchanged).
    const [costDollars, setCostDollars] = useState('');

    const form = useForm({
        name: '',
        category: '',
        default_unit: 'each',
        pack_size: '' as number | string,
        pack_unit: '',
        cost_per_unit_cents: '' as number | string,
        currency: 'NZD',
        barcode: '',
        is_active: true,
        notes: '',
        tag_ids: [] as number[],
    });

    function applyFilters() {
        router.get('/catering/products', { q: search || undefined, category: category || undefined }, { preserveState: true, replace: true });
    }

    function openNew() {
        form.reset();
        form.setData({
            name: '', category: '', default_unit: 'each', pack_size: '', pack_unit: '',
            cost_per_unit_cents: '', currency: 'NZD', barcode: '', is_active: true, notes: '', tag_ids: [],
        });
        setCostDollars('');
        setEditing({ _isNew: true });
    }

    function openEdit(p: Product) {
        form.setData({
            name: p.name,
            category: p.category ?? '',
            default_unit: p.default_unit,
            pack_size: p.pack_size === null ? '' : Number(p.pack_size),
            pack_unit: p.pack_unit ?? '',
            cost_per_unit_cents: p.cost_per_unit_cents ?? '',
            currency: p.currency ?? 'NZD',
            barcode: p.barcode ?? '',
            is_active: p.is_active,
            notes: p.notes ?? '',
            tag_ids: (p.tags ?? []).map((t) => t.id),
        });
        setCostDollars(p.cost_per_unit_cents != null ? (p.cost_per_unit_cents / 100).toString() : '');
        setEditing(p);
    }

    function submit(e: React.FormEvent) {
        e.preventDefault();
        // Convert dollars → integer cents on submit; the controller's cents rule stays unchanged.
        form.transform((data) => ({ ...data, cost_per_unit_cents: costDollars === '' ? null : Math.round(Number(costDollars) * 100) }));
        const onSuccess = () => setEditing(null);
        if (editing?._isNew) {
            form.post('/catering/products', { onSuccess });
        } else if (editing?.id) {
            form.put(`/catering/products/${editing.id}`, { onSuccess });
        }
    }

    function destroy(p: Product) {
        router.delete(`/catering/products/${p.id}`);
    }

    function toggleTag(id: number) {
        const current = form.data.tag_ids ?? [];
        const next = current.includes(id) ? current.filter((x) => x !== id) : [...current, id];
        form.setData('tag_ids', next);
    }

    const activeCount = products.data.filter((p) => p.is_active).length;

    return (
        <AppLayout breadcrumbs={[{ title: 'Sites & Locations', href: '/sites' }, { title: 'Catering', href: '/catering' }, { title: 'Products', href: '/catering/products' }]}>
            <Head title="Catering Products" />
            <PageLayout
                hero={
                    <PageHero
                        icon={Package}
                        title="Catering Products"
                        description="Master catalogue of ingredients and kitchen consumables."
                        stats={[
                            { label: 'Total', value: products.data.length },
                            { label: 'Active', value: activeCount },
                            { label: 'Categories', value: categories.length },
                        ]}
                        actions={
                            canManage && (
                                <Button onClick={openNew}>
                                    <Plus className="mr-2 h-4 w-4" /> New product
                                </Button>
                            )
                        }
                    />
                }
            >
                <CateringTabs active="products" />
                <LibraryDeprecationNotice thing="Products" />

                <div className="flex flex-wrap items-end gap-3">
                    <div className="flex-1 min-w-[240px]">
                        <Label>Search</Label>
                        <Input value={search} onChange={(e) => setSearch(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && applyFilters()} placeholder="Search product name" />
                    </div>
                    <div className="min-w-[180px]">
                        <Label>Category</Label>
                        <Select value={category || 'all'} onValueChange={(v) => setCategory(v === 'all' ? '' : v)}>
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All</SelectItem>
                                {categories.map((c) => <SelectItem key={c} value={c}>{c}</SelectItem>)}
                            </SelectContent>
                        </Select>
                    </div>
                    <Button variant="outline" onClick={applyFilters}>Apply</Button>
                </div>

                <div className="rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Category</TableHead>
                                <TableHead>Unit</TableHead>
                                <TableHead>Pack</TableHead>
                                <TableHead>Cost</TableHead>
                                <TableHead>Tags</TableHead>
                                <TableHead className="w-24">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {products.data.length === 0 && (
                                <TableRow><TableCell colSpan={7} className="text-center text-muted-foreground">No products match.</TableCell></TableRow>
                            )}
                            {products.data.map((p) => (
                                <TableRow key={p.id}>
                                    <TableCell className="font-medium">
                                        {p.name}
                                        {!p.is_active && <Badge variant="outline" className="ml-2">Inactive</Badge>}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">{p.category ?? '—'}</TableCell>
                                    <TableCell>{p.default_unit}</TableCell>
                                    <TableCell>{p.pack_size ? `${p.pack_size} ${p.pack_unit ?? ''}` : '—'}</TableCell>
                                    <TableCell>{formatMoneyFromCents(p.cost_per_unit_cents, p.currency)}</TableCell>
                                    <TableCell>
                                        <div className="flex flex-wrap gap-1">
                                            {(p.tags ?? []).map((t) => (
                                                <Badge key={t.id} variant="outline" style={tagBadgeStyle(t)} className="text-xs">{t.label}</Badge>
                                            ))}
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        {canManage && (
                                            <div className="flex gap-1">
                                                <Button size="icon" variant="ghost" onClick={() => openEdit(p)}><Pencil className="h-4 w-4" /></Button>
                                                <ConfirmAction
                                                    title={`Archive ${p.name}?`}
                                                    description="The product is hidden from new recipes and lists but kept for history. You can restore it later."
                                                    confirmLabel="Archive"
                                                    onConfirm={() => destroy(p)}
                                                >
                                                    <Button size="icon" variant="ghost"><Trash2 className="h-4 w-4 text-destructive" /></Button>
                                                </ConfirmAction>
                                            </div>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
                <LaravelPagination links={products.links} lastPage={products.last_page} />

                <Dialog open={editing !== null} onOpenChange={(o) => !o && setEditing(null)}>
                    <DialogContent className="max-w-2xl">
                        <DialogHeader>
                            <DialogTitle>{editing?._isNew ? 'New product' : `Edit ${editing?.name ?? 'product'}`}</DialogTitle>
                        </DialogHeader>
                        <form onSubmit={submit} className="space-y-3">
                            <div className="grid grid-cols-2 gap-3">
                                <div className="col-span-2">
                                    <Label>Name</Label>
                                    <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                                </div>
                                <div>
                                    <Label>Category</Label>
                                    <Input value={form.data.category} onChange={(e) => form.setData('category', e.target.value)} placeholder="dairy, pantry, …" />
                                </div>
                                <div>
                                    <Label>Default unit</Label>
                                    <Select value={form.data.default_unit} onValueChange={(v) => form.setData('default_unit', v)}>
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            {UNIT_OPTIONS.map((u) => <SelectItem key={u} value={u}>{u}</SelectItem>)}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <Label>Pack size</Label>
                                    <Input type="number" step="0.01" value={form.data.pack_size} onChange={(e) => form.setData('pack_size', e.target.value === '' ? '' : Number(e.target.value))} />
                                </div>
                                <div>
                                    <Label>Pack unit</Label>
                                    <Input value={form.data.pack_unit} onChange={(e) => form.setData('pack_unit', e.target.value)} placeholder="g, ml, L…" />
                                </div>
                                <div>
                                    <Label>Cost (NZD)</Label>
                                    <div className="relative">
                                        <span className="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">$</span>
                                        <Input type="number" step="0.01" min="0" value={costDollars} onChange={(e) => setCostDollars(e.target.value)} placeholder="0.00" className="pl-6" />
                                    </div>
                                </div>
                                <div>
                                    <Label>Currency</Label>
                                    <Input value={form.data.currency} onChange={(e) => form.setData('currency', e.target.value.toUpperCase())} maxLength={3} />
                                </div>
                                <div className="col-span-2">
                                    <Label>Barcode</Label>
                                    <Input value={form.data.barcode} onChange={(e) => form.setData('barcode', e.target.value)} />
                                </div>
                                <div className="col-span-2">
                                    <Label>Notes</Label>
                                    <Textarea value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} rows={2} />
                                </div>
                                <div className="col-span-2">
                                    <Label>Dietary / allergen tags</Label>
                                    <div className="flex flex-wrap gap-1 rounded-md border p-2">
                                        {tags.map((t) => {
                                            const selected = form.data.tag_ids?.includes(t.id);
                                            return (
                                                <Button unstyled
                                                    key={t.id}
                                                    type="button"
                                                    aria-pressed={selected}
                                                    onClick={() => toggleTag(t.id)}
                                                    className={`rounded-md border px-2 py-1 text-xs transition ${selected ? 'border-primary bg-primary/10' : 'border-transparent hover:bg-accent'}`}
                                                    style={tagBadgeStyle(t)}
                                                >
                                                    {t.label}
                                                </Button>
                                            );
                                        })}
                                    </div>
                                </div>
                                <div className="col-span-2 flex items-center gap-2">
                                    <input id="is_active" type="checkbox" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />
                                    <Label htmlFor="is_active">Active</Label>
                                </div>
                            </div>
                            <DialogFooter>
                                <Button variant="ghost" type="button" onClick={() => setEditing(null)}>Cancel</Button>
                                <Button type="submit" disabled={form.processing}>{editing?._isNew ? 'Create' : 'Save'}</Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </PageLayout>
        </AppLayout>
    );
}
