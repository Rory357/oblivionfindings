import { Button } from '@/components/ui/button';
import { Card as GuardrailCard } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import axios, { AxiosError } from 'axios';
import {
    ArrowLeft,
    Leaf,
    Loader2,
    Package,
    Pencil,
    Plus,
    Search,
    ShieldAlert,
    Tags,
    Trash2,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';
import { formatMoneyFromCents as money } from './_helpers';

type TagKind = 'dietary' | 'allergen';
type TagSeverity = 'info' | 'warn' | 'critical';
type TagOpt = { id: number; label: string; kind: TagKind };

const PRODUCT_UNITS = [
    'each',
    'kg',
    'g',
    'L',
    'ml',
    'pack',
    'tin',
    'bottle',
    'bunch',
];

function firstError(err: unknown, fallback: string): string {
    const ax = err as AxiosError<{
        errors?: Record<string, string[]>;
        message?: string;
    }>;
    const errors = ax.response?.data?.errors;
    return (
        (errors ? Object.values(errors)[0]?.[0] : ax.response?.data?.message) ||
        fallback
    );
}

/* ===================================================================== */
/* Products manager                                                       */
/* ===================================================================== */

type ManagedProduct = {
    id: number;
    name: string;
    category: string | null;
    default_unit: string;
    pack_size: string | number | null;
    pack_unit: string | null;
    cost_per_unit_cents: number | null;
    currency: string;
    is_active: boolean;
    barcode: string | null;
    notes: string | null;
    tags?: TagOpt[];
};

type ProductForm = {
    name: string;
    category: string;
    default_unit: string;
    pack_size: string;
    pack_unit: string;
    cost_dollars: string;
    currency: string;
    barcode: string;
    is_active: boolean;
    notes: string;
    tag_ids: number[];
};

export function ProductsManagerDialog({
    open,
    onClose,
    onChanged,
}: {
    open: boolean;
    onClose: () => void;
    onChanged: () => void;
}) {
    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto"
                style={{
                    maxWidth: 'min(92vw, 900px)',
                    width: 'min(92vw, 900px)',
                }}
            >
                {open && (
                    <ProductsManagerBody
                        onClose={onClose}
                        onChanged={onChanged}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function ProductsManagerBody({
    onClose,
    onChanged,
}: {
    onClose: () => void;
    onChanged: () => void;
}) {
    const [loading, setLoading] = useState(true);
    const [products, setProducts] = useState<ManagedProduct[]>([]);
    const [tags, setTags] = useState<TagOpt[]>([]);
    const [categories, setCategories] = useState<string[]>([]);
    const [q, setQ] = useState('');
    const [editing, setEditing] = useState<ManagedProduct | 'new' | null>(null);

    const fetchAll = useCallback(async () => {
        setLoading(true);
        try {
            const res = await axios.get('/catering/products', {
                headers: { Accept: 'application/json' },
            });
            setProducts(res.data.products ?? []);
            setTags(res.data.tags ?? []);
            setCategories(res.data.categories ?? []);
        } catch {
            toast.error('Could not load products');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        fetchAll();
    }, [fetchAll]);

    if (editing !== null) {
        return (
            <ProductFormView
                product={editing === 'new' ? null : editing}
                categories={categories}
                tags={tags}
                onCancel={() => setEditing(null)}
                onSaved={() => {
                    setEditing(null);
                    fetchAll();
                    onChanged();
                }}
            />
        );
    }

    const needle = q.trim().toLowerCase();
    const filtered = needle
        ? products.filter(
              (p) =>
                  p.name.toLowerCase().includes(needle) ||
                  (p.category ?? '').toLowerCase().includes(needle),
          )
        : products;

    return (
        <>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <Package className="h-4 w-4 text-sites" /> Manage products
                </DialogTitle>
                <DialogDescription>
                    Products power inventory tracking and recipe stock checks
                    across every site.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3 space-y-3">
                <div className="flex items-center justify-between gap-2">
                    <div className="relative w-full max-w-xs">
                        <Search className="absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder="Search products…"
                            className="pl-8"
                        />
                    </div>
                    <Button size="sm" onClick={() => setEditing('new')}>
                        <Plus className="mr-1.5 h-[15px] w-[15px]" /> Add
                        product
                    </Button>
                </div>

                {loading ? (
                    <div className="flex items-center justify-center gap-2 py-16 text-sm text-muted-foreground">
                        <Loader2 className="h-4 w-4 animate-spin" /> Loading
                        products…
                    </div>
                ) : filtered.length === 0 ? (
                    <div className="rounded-xl border border-dashed border-border px-6 py-12 text-center text-sm text-muted-foreground">
                        No products match.
                    </div>
                ) : (
                    <div className="max-h-[55vh] overflow-y-auto rounded-xl border border-border">
                        <table className="w-full text-sm">
                            <thead className="sticky top-0 border-b border-border bg-muted/60 text-[11px] tracking-wide text-muted-foreground uppercase backdrop-blur">
                                <tr>
                                    <th className="px-3 py-2 text-left font-semibold">
                                        Product
                                    </th>
                                    <th className="px-3 py-2 text-left font-semibold">
                                        Category
                                    </th>
                                    <th className="px-3 py-2 text-left font-semibold">
                                        Pack
                                    </th>
                                    <th className="px-3 py-2 text-right font-semibold">
                                        Cost
                                    </th>
                                    <th className="px-3 py-2 text-right font-semibold">
                                        Edit
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {filtered.map((p) => (
                                    <tr
                                        key={p.id}
                                        className="border-b border-border last:border-b-0 hover:bg-accent/30"
                                    >
                                        <td className="px-3 py-2">
                                            <div className="flex items-center gap-2">
                                                <span className="font-medium text-foreground">
                                                    {p.name}
                                                </span>
                                                {!p.is_active && (
                                                    <span className="rounded-full bg-muted px-1.5 py-px text-[10px] text-muted-foreground">
                                                        Inactive
                                                    </span>
                                                )}
                                            </div>
                                            {p.tags && p.tags.length > 0 && (
                                                <div className="mt-1 flex flex-wrap gap-1">
                                                    {p.tags.map((t) => (
                                                        <span
                                                            key={t.id}
                                                            className={cn(
                                                                'rounded-full px-1.5 py-px text-[10px] font-medium',
                                                                t.kind ===
                                                                    'allergen'
                                                                    ? 'bg-status-critical-bg text-status-critical'
                                                                    : 'bg-sites-bg text-sites-deep',
                                                            )}
                                                        >
                                                            {t.label}
                                                        </span>
                                                    ))}
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-3 py-2 text-muted-foreground capitalize">
                                            {p.category ?? '—'}
                                        </td>
                                        <td className="px-3 py-2 text-muted-foreground">
                                            {p.pack_size
                                                ? `${p.pack_size} ${p.pack_unit ?? ''}`.trim()
                                                : p.default_unit}
                                        </td>
                                        <td className="px-3 py-2 text-right text-muted-foreground tabular-nums">
                                            {p.cost_per_unit_cents != null
                                                ? money(
                                                      p.cost_per_unit_cents,
                                                      p.currency,
                                                  )
                                                : '—'}
                                        </td>
                                        <td className="px-3 py-2 text-right">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                className="h-8 w-8"
                                                onClick={() => setEditing(p)}
                                                aria-label={`Edit ${p.name}`}
                                            >
                                                <Pencil className="h-4 w-4 text-muted-foreground" />
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Close
                </Button>
            </DialogFooter>
        </>
    );
}

function ProductFormView({
    product,
    categories,
    tags,
    onCancel,
    onSaved,
}: {
    product: ManagedProduct | null;
    categories: string[];
    tags: TagOpt[];
    onCancel: () => void;
    onSaved: () => void;
}) {
    const isNew = product == null;
    const [data, setData] = useState<ProductForm>(() => ({
        name: product?.name ?? '',
        category: product?.category ?? '',
        default_unit: product?.default_unit ?? 'each',
        pack_size: product?.pack_size != null ? String(product.pack_size) : '',
        pack_unit: product?.pack_unit ?? '',
        cost_dollars:
            product?.cost_per_unit_cents != null
                ? (product.cost_per_unit_cents / 100).toString()
                : '',
        currency: product?.currency ?? 'NZD',
        barcode: product?.barcode ?? '',
        is_active: product?.is_active ?? true,
        notes: product?.notes ?? '',
        tag_ids: (product?.tags ?? []).map((t) => t.id),
    }));
    const [saving, setSaving] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);

    function patch(p: Partial<ProductForm>) {
        setData((d) => ({ ...d, ...p }));
    }
    function toggleTag(id: number) {
        patch({
            tag_ids: data.tag_ids.includes(id)
                ? data.tag_ids.filter((x) => x !== id)
                : [...data.tag_ids, id],
        });
    }

    async function submit(e: React.FormEvent) {
        e.preventDefault();
        if (!data.name.trim()) {
            toast.error('Give the product a name');
            return;
        }
        setSaving(true);
        const payload = {
            name: data.name.trim(),
            category: data.category.trim() || null,
            default_unit: data.default_unit,
            pack_size: data.pack_size === '' ? null : Number(data.pack_size),
            pack_unit: data.pack_unit.trim() || null,
            cost_per_unit_cents:
                data.cost_dollars === ''
                    ? null
                    : Math.round(Number(data.cost_dollars) * 100),
            currency: data.currency || 'NZD',
            barcode: data.barcode.trim() || null,
            is_active: data.is_active,
            notes: data.notes.trim() || null,
            tag_ids: data.tag_ids,
        };
        try {
            if (isNew)
                await axios.post('/catering/products', payload, {
                    headers: { Accept: 'application/json' },
                });
            else
                await axios.put(`/catering/products/${product.id}`, payload, {
                    headers: { Accept: 'application/json' },
                });
            toast.success(isNew ? 'Product added' : 'Product saved');
            onSaved();
        } catch (err) {
            toast.error(firstError(err, 'Could not save the product'));
        } finally {
            setSaving(false);
        }
    }

    async function doDelete() {
        if (isNew) return;
        setDeleting(true);
        try {
            await axios.delete(`/catering/products/${product.id}`, {
                headers: { Accept: 'application/json' },
            });
            toast.success('Product archived');
            onSaved();
        } catch {
            toast.error('Could not archive the product');
        } finally {
            setDeleting(false);
        }
    }

    return (
        <form onSubmit={submit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <Button
                        unstyled
                        type="button"
                        onClick={onCancel}
                        className="rounded-md p-0.5 text-muted-foreground hover:bg-accent hover:text-foreground"
                        aria-label="Back to products"
                    >
                        <ArrowLeft className="h-4 w-4" />
                    </Button>
                    {isNew ? 'Add product' : 'Edit product'}
                </DialogTitle>
                <DialogDescription>
                    Link this to recipe ingredients and site inventory to track
                    stock and cost.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3 grid gap-3 sm:grid-cols-2">
                <div className="sm:col-span-2">
                    <Label>
                        Name <span className="text-status-critical">*</span>
                    </Label>
                    <Input
                        className="mt-1"
                        value={data.name}
                        onChange={(e) => patch({ name: e.target.value })}
                        placeholder="e.g. Chicken breast (1kg)"
                        autoFocus
                    />
                </div>
                <div>
                    <Label>Category</Label>
                    <Input
                        className="mt-1"
                        list="mp-product-categories"
                        value={data.category}
                        onChange={(e) => patch({ category: e.target.value })}
                        placeholder="e.g. protein"
                    />
                    <datalist id="mp-product-categories">
                        {categories.map((c) => (
                            <option key={c} value={c} />
                        ))}
                    </datalist>
                </div>
                <div>
                    <Label>Default unit</Label>
                    <Select
                        value={data.default_unit}
                        onValueChange={(v) => patch({ default_unit: v })}
                    >
                        <SelectTrigger className="mt-1">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {PRODUCT_UNITS.map((u) => (
                                <SelectItem key={u} value={u}>
                                    {u}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <div>
                    <Label>Pack size</Label>
                    <Input
                        className="mt-1"
                        type="number"
                        step="0.01"
                        min={0}
                        value={data.pack_size}
                        onChange={(e) => patch({ pack_size: e.target.value })}
                        placeholder="e.g. 500"
                    />
                </div>
                <div>
                    <Label>Pack unit</Label>
                    <Input
                        className="mt-1"
                        value={data.pack_unit}
                        onChange={(e) => patch({ pack_unit: e.target.value })}
                        placeholder="e.g. g"
                    />
                </div>
                <div>
                    <Label>Cost ({data.currency})</Label>
                    <Input
                        className="mt-1"
                        type="number"
                        step="0.01"
                        min={0}
                        value={data.cost_dollars}
                        onChange={(e) =>
                            patch({ cost_dollars: e.target.value })
                        }
                        placeholder="per unit"
                    />
                </div>
                <div>
                    <Label>Barcode</Label>
                    <Input
                        className="mt-1"
                        value={data.barcode}
                        onChange={(e) => patch({ barcode: e.target.value })}
                        placeholder="optional"
                    />
                </div>
                <div className="sm:col-span-2">
                    <Label>Notes</Label>
                    <Textarea
                        className="mt-1"
                        rows={2}
                        value={data.notes}
                        onChange={(e) => patch({ notes: e.target.value })}
                        placeholder="optional"
                    />
                </div>
                <div className="sm:col-span-2">
                    <Label>Dietary &amp; allergen tags</Label>
                    <div className="mt-1.5 flex flex-wrap gap-1.5">
                        {tags.length === 0 && (
                            <span className="text-xs text-muted-foreground">
                                No tags set up yet.
                            </span>
                        )}
                        {tags.map((t) => {
                            const sel = data.tag_ids.includes(t.id);
                            return (
                                <Button
                                    unstyled
                                    key={t.id}
                                    type="button"
                                    onClick={() => toggleTag(t.id)}
                                    className={cn(
                                        'rounded-full border px-2.5 py-1 text-[12px] font-medium transition-colors',
                                        sel
                                            ? t.kind === 'allergen'
                                                ? 'border-status-critical bg-status-critical-bg text-status-critical'
                                                : 'border-sites bg-sites-bg text-sites-deep'
                                            : 'border-border bg-card text-muted-foreground hover:bg-accent',
                                    )}
                                    aria-pressed={sel}
                                >
                                    {t.label}
                                </Button>
                            );
                        })}
                    </div>
                </div>
                <label className="flex items-center gap-2 text-sm sm:col-span-2">
                    <input
                        type="checkbox"
                        checked={data.is_active}
                        onChange={(e) => patch({ is_active: e.target.checked })}
                    />
                    Active (available when adding inventory &amp; recipe
                    ingredients)
                </label>
            </div>

            <DialogFooter className="mt-4 sm:justify-between">
                <div className="flex items-center">
                    {!isNew &&
                        (confirmDelete ? (
                            <div className="flex items-center gap-2">
                                <span className="text-[13px] text-muted-foreground">
                                    Archive this product?
                                </span>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setConfirmDelete(false)}
                                    disabled={deleting}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="button"
                                    variant="destructive"
                                    size="sm"
                                    onClick={doDelete}
                                    disabled={deleting}
                                >
                                    {deleting && (
                                        <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />
                                    )}{' '}
                                    Archive
                                </Button>
                            </div>
                        ) : (
                            <Button
                                type="button"
                                variant="ghost"
                                className="text-status-critical hover:bg-status-critical-bg hover:text-status-critical"
                                onClick={() => setConfirmDelete(true)}
                            >
                                <Trash2 className="mr-1.5 h-4 w-4" /> Archive
                            </Button>
                        ))}
                </div>
                <div className="flex gap-2">
                    <Button type="button" variant="outline" onClick={onCancel}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={saving}>
                        {saving && (
                            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                        )}
                        {isNew ? 'Add product' : 'Save product'}
                    </Button>
                </div>
            </DialogFooter>
        </form>
    );
}

/* ===================================================================== */
/* Dietary & allergen tags manager                                        */
/* ===================================================================== */

type ManagedTag = {
    id: number;
    key: string;
    label: string;
    kind: TagKind;
    severity: TagSeverity;
    color: string | null;
    description: string | null;
};

type TagForm = {
    label: string;
    kind: TagKind;
    severity: TagSeverity;
    color: string;
    description: string;
};

const SEVERITIES: { value: TagSeverity; label: string }[] = [
    { value: 'info', label: 'Info' },
    { value: 'warn', label: 'Warning' },
    { value: 'critical', label: 'Critical' },
];

export function DietaryTagsManagerDialog({
    open,
    onClose,
    onChanged,
}: {
    open: boolean;
    onClose: () => void;
    onChanged: () => void;
}) {
    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto"
                style={{
                    maxWidth: 'min(92vw, 720px)',
                    width: 'min(92vw, 720px)',
                }}
            >
                {open && (
                    <DietaryTagsManagerBody
                        onClose={onClose}
                        onChanged={onChanged}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function DietaryTagsManagerBody({
    onClose,
    onChanged,
}: {
    onClose: () => void;
    onChanged: () => void;
}) {
    const [loading, setLoading] = useState(true);
    const [tags, setTags] = useState<ManagedTag[]>([]);
    const [editing, setEditing] = useState<ManagedTag | 'new' | null>(null);

    const fetchAll = useCallback(async () => {
        setLoading(true);
        try {
            const res = await axios.get('/catering/tags', {
                headers: { Accept: 'application/json' },
            });
            setTags(res.data.tags ?? []);
        } catch {
            toast.error('Could not load tags');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        fetchAll();
    }, [fetchAll]);

    if (editing !== null) {
        return (
            <TagFormView
                tag={editing === 'new' ? null : editing}
                onCancel={() => setEditing(null)}
                onSaved={() => {
                    setEditing(null);
                    fetchAll();
                    onChanged();
                }}
            />
        );
    }

    const groups: { kind: TagKind; label: string; icon: typeof Leaf }[] = [
        { kind: 'dietary', label: 'Dietary preferences', icon: Leaf },
        { kind: 'allergen', label: 'Allergens', icon: ShieldAlert },
    ];

    return (
        <>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <Tags className="h-4 w-4 text-sites" /> Manage dietary &amp;
                    allergen tags
                </DialogTitle>
                <DialogDescription>
                    Tags drive resident dietary profiles and the
                    allergen-conflict checks when planning meals.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3 space-y-4">
                <div className="flex justify-end">
                    <Button size="sm" onClick={() => setEditing('new')}>
                        <Plus className="mr-1.5 h-[15px] w-[15px]" /> Add tag
                    </Button>
                </div>

                {loading ? (
                    <div className="flex items-center justify-center gap-2 py-16 text-sm text-muted-foreground">
                        <Loader2 className="h-4 w-4 animate-spin" /> Loading
                        tags…
                    </div>
                ) : (
                    <div className="max-h-[55vh] space-y-4 overflow-y-auto">
                        {groups.map((g) => {
                            const list = tags.filter((t) => t.kind === g.kind);
                            const Icon = g.icon;
                            return (
                                <div key={g.kind}>
                                    <div className="mb-1.5 flex items-center gap-1.5 text-[12px] font-semibold tracking-wide text-muted-foreground uppercase">
                                        <Icon className="h-3.5 w-3.5" />{' '}
                                        {g.label}{' '}
                                        <span className="font-normal">
                                            ({list.length})
                                        </span>
                                    </div>
                                    {list.length === 0 ? (
                                        <p className="rounded-lg border border-dashed border-border px-3 py-4 text-center text-xs text-muted-foreground">
                                            None yet.
                                        </p>
                                    ) : (
                                        <div className="flex flex-wrap gap-1.5">
                                            {list.map((t) => (
                                                <Button
                                                    unstyled
                                                    key={t.id}
                                                    type="button"
                                                    onClick={() =>
                                                        setEditing(t)
                                                    }
                                                    className="group inline-flex items-center gap-1.5 rounded-full border border-border bg-card px-2.5 py-1 text-[12px] font-medium text-foreground transition-colors hover:border-sites/50 hover:bg-accent"
                                                >
                                                    <span
                                                        className="h-2.5 w-2.5 rounded-full"
                                                        style={{
                                                            backgroundColor:
                                                                t.color ??
                                                                '#94a3b8',
                                                        }}
                                                    />
                                                    {t.label}
                                                    <span
                                                        className={cn(
                                                            'rounded-full px-1.5 text-[9.5px] font-semibold uppercase',
                                                            t.severity ===
                                                                'critical'
                                                                ? 'text-status-critical'
                                                                : t.severity ===
                                                                    'warn'
                                                                  ? 'text-status-warning'
                                                                  : 'text-muted-foreground',
                                                        )}
                                                    >
                                                        {t.severity}
                                                    </span>
                                                    <Pencil className="h-3 w-3 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" />
                                                </Button>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>

            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Close
                </Button>
            </DialogFooter>
        </>
    );
}

function TagFormView({
    tag,
    onCancel,
    onSaved,
}: {
    tag: ManagedTag | null;
    onCancel: () => void;
    onSaved: () => void;
}) {
    const isNew = tag == null;
    const [data, setData] = useState<TagForm>(() => ({
        label: tag?.label ?? '',
        kind: tag?.kind ?? 'dietary',
        severity: tag?.severity ?? 'info',
        color: tag?.color ?? '#16a34a',
        description: tag?.description ?? '',
    }));
    const [saving, setSaving] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);

    function patch(p: Partial<TagForm>) {
        setData((d) => ({ ...d, ...p }));
    }

    const kinds: {
        value: TagKind;
        label: string;
        description: string;
        icon: typeof Leaf;
    }[] = [
        {
            value: 'dietary',
            label: 'Dietary',
            description: 'A preference or diet (e.g. Vegetarian).',
            icon: Leaf,
        },
        {
            value: 'allergen',
            label: 'Allergen',
            description: 'Triggers a hard conflict check.',
            icon: ShieldAlert,
        },
    ];

    async function submit(e: React.FormEvent) {
        e.preventDefault();
        if (!data.label.trim()) {
            toast.error('Give the tag a label');
            return;
        }
        setSaving(true);
        const payload = {
            label: data.label.trim(),
            kind: data.kind,
            severity: data.severity,
            color: data.color || null,
            description: data.description.trim() || null,
        };
        try {
            if (isNew)
                await axios.post('/catering/tags', payload, {
                    headers: { Accept: 'application/json' },
                });
            else
                await axios.put(`/catering/tags/${tag.id}`, payload, {
                    headers: { Accept: 'application/json' },
                });
            toast.success(isNew ? 'Tag added' : 'Tag saved');
            onSaved();
        } catch (err) {
            toast.error(firstError(err, 'Could not save the tag'));
        } finally {
            setSaving(false);
        }
    }

    async function doDelete() {
        if (isNew) return;
        setDeleting(true);
        try {
            await axios.delete(`/catering/tags/${tag.id}`, {
                headers: { Accept: 'application/json' },
            });
            toast.success('Tag deleted');
            onSaved();
        } catch {
            toast.error('Could not delete the tag — it may be in use.');
        } finally {
            setDeleting(false);
        }
    }

    return (
        <form onSubmit={submit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <Button
                        unstyled
                        type="button"
                        onClick={onCancel}
                        className="rounded-md p-0.5 text-muted-foreground hover:bg-accent hover:text-foreground"
                        aria-label="Back to tags"
                    >
                        <ArrowLeft className="h-4 w-4" />
                    </Button>
                    {isNew ? 'Add tag' : 'Edit tag'}
                </DialogTitle>
                <DialogDescription>
                    Allergen tags drive the hard conflict checks; dietary tags
                    describe a resident's preferences.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3 space-y-4">
                <div>
                    <Label>
                        Label <span className="text-status-critical">*</span>
                    </Label>
                    <Input
                        className="mt-1"
                        value={data.label}
                        onChange={(e) => patch({ label: e.target.value })}
                        placeholder="e.g. Tree nuts"
                        autoFocus
                    />
                </div>

                <div>
                    <Label>Type</Label>
                    <div className="mt-1.5 grid grid-cols-2 gap-2">
                        {kinds.map((k) => {
                            const Icon = k.icon;
                            const active = data.kind === k.value;
                            return (
                                <Button
                                    unstyled
                                    key={k.value}
                                    type="button"
                                    onClick={() => patch({ kind: k.value })}
                                    className={cn(
                                        'group flex items-start gap-2 rounded-xl border bg-card/40 p-3 text-left transition-all hover:bg-card focus:outline-none focus-visible:ring-2 focus-visible:ring-sites',
                                        active
                                            ? 'border-sites bg-sites/10 ring-1 ring-sites/40'
                                            : 'border-border hover:border-sites/50',
                                    )}
                                    aria-pressed={active}
                                >
                                    <span className="mt-0.5 shrink-0 rounded-lg bg-background/60 p-1.5">
                                        <Icon className="h-4 w-4 text-sites" />
                                    </span>
                                    <span className="min-w-0">
                                        <span className="block text-sm font-medium">
                                            {k.label}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {k.description}
                                        </span>
                                    </span>
                                </Button>
                            );
                        })}
                    </div>
                </div>

                <div className="grid gap-3 sm:grid-cols-[1fr_120px]">
                    <div>
                        <Label>Severity</Label>
                        <GuardrailCard
                            unstyled
                            className="mt-1.5 inline-flex w-full rounded-lg border border-border bg-card p-0.5"
                        >
                            {SEVERITIES.map((s) => (
                                <Button
                                    unstyled
                                    key={s.value}
                                    type="button"
                                    onClick={() => patch({ severity: s.value })}
                                    className={cn(
                                        'flex-1 rounded-md px-2 py-1.5 text-[12.5px] font-medium transition-colors',
                                        data.severity === s.value
                                            ? 'bg-sites text-primary-foreground'
                                            : 'text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {s.label}
                                </Button>
                            ))}
                        </GuardrailCard>
                    </div>
                    <div>
                        <Label>Colour</Label>
                        <div className="mt-1.5 flex items-center gap-2">
                            <input
                                type="color"
                                value={data.color || '#16a34a'}
                                onChange={(e) =>
                                    patch({ color: e.target.value })
                                }
                                className="h-9 w-12 shrink-0 cursor-pointer rounded-md border border-border bg-card"
                                aria-label="Tag colour"
                            />
                            <Input
                                value={data.color}
                                onChange={(e) =>
                                    patch({ color: e.target.value })
                                }
                                className="font-mono text-[12px]"
                            />
                        </div>
                    </div>
                </div>

                <div>
                    <Label>Description</Label>
                    <Textarea
                        className="mt-1"
                        rows={2}
                        value={data.description}
                        onChange={(e) => patch({ description: e.target.value })}
                        placeholder="optional — shown as guidance"
                    />
                </div>
            </div>

            <DialogFooter className="mt-4 sm:justify-between">
                <div className="flex items-center">
                    {!isNew &&
                        (confirmDelete ? (
                            <div className="flex items-center gap-2">
                                <span className="text-[13px] text-muted-foreground">
                                    Delete this tag?
                                </span>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setConfirmDelete(false)}
                                    disabled={deleting}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="button"
                                    variant="destructive"
                                    size="sm"
                                    onClick={doDelete}
                                    disabled={deleting}
                                >
                                    {deleting && (
                                        <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />
                                    )}{' '}
                                    Delete
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
                    <Button type="button" variant="outline" onClick={onCancel}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={saving}>
                        {saving && (
                            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                        )}
                        {isNew ? 'Add tag' : 'Save tag'}
                    </Button>
                </div>
            </DialogFooter>
        </form>
    );
}
