import { Button } from '@/components/ui/button';
import { Card as GuardrailCard } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
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
import { cn } from '@/lib/utils';
import axios from 'axios';
import {
    CalendarDays,
    Check,
    ChefHat,
    ChevronDown,
    ChevronRight,
    Download,
    FileSpreadsheet,
    FileText,
    Plus,
    Printer,
    RefreshCw,
    ShoppingCart,
    Trash2,
    Truck,
} from 'lucide-react';
import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { toast } from 'sonner';
import { ConfirmAction } from '../_confirm-action';
import {
    formatQty,
    formatMoneyFromCents as money,
    type ShoppingList,
    type ShoppingListItem,
    type SiteInfo,
} from './_helpers';

type Props = {
    siteId: number;
    site: SiteInfo;
    lists: ShoppingList[];
    canManage: boolean;
    products: { id: number; name: string; default_unit: string }[];
    onGenerate: () => void;
    onChanged: () => void;
};

const SOURCE_BADGE: Record<
    ShoppingListItem['source'],
    { label: string; className: string }
> = {
    meal_plan: {
        label: 'From plan',
        className: 'border-sites/30 bg-sites-bg text-sites-deep',
    },
    restock_to_par: {
        label: 'Top up',
        className: 'border-primary/30 bg-primary/10 text-primary',
    },
    manual: {
        label: 'Manual',
        className: 'border-amberx/30 bg-amberx-bg text-amberx',
    },
};

const STATUS_BADGE: Record<ShoppingList['status'], string> = {
    draft: 'border-sites/30 bg-sites-bg text-sites-deep',
    ordered: 'border-primary/30 bg-primary/10 text-primary',
    received:
        'border-status-success/30 bg-status-success-bg text-status-success',
    cancelled: 'border-border bg-muted text-muted-foreground',
};

function nzDate(d: string | null | undefined): string | null {
    if (!d) return null;
    const parsed = new Date(d);
    return Number.isNaN(parsed.getTime())
        ? d
        : parsed.toLocaleDateString('en-NZ', {
              day: 'numeric',
              month: 'short',
          });
}

function listProvenance(list: ShoppingList): string {
    return [
        list.received_at
            ? `Received ${nzDate(list.received_at)}`
            : list.ordered_at
              ? `Ordered ${nzDate(list.ordered_at)}`
              : null,
        list.provider_key || null,
        list.provider_order_ref ? `ref ${list.provider_order_ref}` : null,
    ]
        .filter(Boolean)
        .join(' · ');
}

function itemName(i: ShoppingListItem) {
    return i.product?.name ?? i.free_text_name ?? 'item';
}
function itemCategory(i: ShoppingListItem) {
    return i.product?.category ?? (i.source === 'manual' ? 'Manual' : 'Other');
}
function groupItems(items: ShoppingListItem[]) {
    const groups = new Map<string, ShoppingListItem[]>();
    for (const item of items) {
        const cat = itemCategory(item);
        if (!groups.has(cat)) groups.set(cat, []);
        groups.get(cat)!.push(item);
    }
    return Array.from(groups.entries()).sort((a, b) =>
        a[0].localeCompare(b[0]),
    );
}

export default function ShoppingListPanel({
    siteId,
    site,
    lists,
    canManage,
    products,
    onGenerate,
    onChanged,
}: Props) {
    const draft = lists.find((l) => l.status === 'draft') ?? null;
    const past = lists.filter((l) => l !== draft);
    const [showAdd, setShowAdd] = useState(false);
    const [historyOpen, setHistoryOpen] = useState(false);
    const [viewList, setViewList] = useState<ShoppingList | null>(null);
    const [printList, setPrintList] = useState<ShoppingList | null>(null);

    useEffect(() => {
        if (!printList) return;
        const t = setTimeout(() => {
            window.print();
            setPrintList(null);
        }, 150);
        return () => clearTimeout(t);
    }, [printList]);

    return (
        <div className="space-y-4">
            {draft ? (
                <DraftCard
                    siteId={siteId}
                    list={draft}
                    canManage={canManage}
                    products={products}
                    showAdd={showAdd}
                    setShowAdd={setShowAdd}
                    onGenerate={onGenerate}
                    onChanged={onChanged}
                    onExportPrint={() => setPrintList(draft)}
                />
            ) : (
                <GuardrailCard
                    unstyled
                    className="flex flex-col items-center gap-3 rounded-xl border-2 border-dashed border-border bg-card p-10 text-center"
                >
                    <div className="rounded-full bg-sites-bg p-3 text-sites-deep">
                        <ShoppingCart className="h-6 w-6" />
                    </div>
                    <div>
                        <div className="font-medium text-foreground">
                            No draft shopping list yet
                        </div>
                        <p className="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                            Generate one from this week's planned meals plus
                            anything below par level. Manual items survive
                            future regenerations.
                        </p>
                    </div>
                    {canManage && (
                        <Button onClick={onGenerate}>
                            <RefreshCw className="mr-2 h-4 w-4" /> Generate now
                        </Button>
                    )}
                </GuardrailCard>
            )}

            <GuardrailCard
                unstyled
                className="overflow-hidden rounded-xl border border-border bg-card"
            >
                <Button
                    unstyled
                    type="button"
                    className="flex w-full items-center justify-between gap-2 px-4 py-3 text-sm font-medium hover:bg-accent"
                    onClick={() => setHistoryOpen((v) => !v)}
                >
                    <span className="flex items-center gap-2">
                        {historyOpen ? (
                            <ChevronDown className="h-4 w-4" />
                        ) : (
                            <ChevronRight className="h-4 w-4" />
                        )}
                        Past lists ({past.length})
                    </span>
                    <span className="text-xs text-muted-foreground">
                        Ordered &amp; received history
                    </span>
                </Button>
                {historyOpen && (
                    <div className="border-t border-border bg-muted/10 p-2">
                        {past.length === 0 ? (
                            <div className="py-4 text-center text-sm text-muted-foreground">
                                No past lists yet.
                            </div>
                        ) : (
                            <div className="space-y-1.5">
                                {past.map((l) => (
                                    <HistoryRow
                                        key={l.id}
                                        list={l}
                                        onView={() => setViewList(l)}
                                        onPrint={() => setPrintList(l)}
                                        onExportCsv={() => exportCsv(l, site)}
                                    />
                                ))}
                            </div>
                        )}
                    </div>
                )}
            </GuardrailCard>

            {viewList && (
                <ViewListDialog
                    list={viewList}
                    onClose={() => setViewList(null)}
                />
            )}
            {printList && <BrandedListPrintDoc list={printList} site={site} />}
        </div>
    );
}

function DraftCard({
    siteId,
    list,
    canManage,
    products,
    showAdd,
    setShowAdd,
    onGenerate,
    onChanged,
    onExportPrint,
}: {
    siteId: number;
    list: ShoppingList;
    canManage: boolean;
    products: { id: number; name: string; default_unit: string }[];
    showAdd: boolean;
    setShowAdd: (v: boolean) => void;
    onGenerate: () => void;
    onChanged: () => void;
    onExportPrint: () => void;
}) {
    const items = useMemo(() => list.items ?? [], [list.items]);
    const totalCents = items.reduce(
        (s, i) => s + (i.estimated_cost_cents ?? 0),
        0,
    );
    // "Tick as you shop" — client-only, but persisted to localStorage so a same-device
    // reload doesn't lose progress; Mark received commits it to inventory (P2-15).
    const storageKey = `mp-shopping-ticks-${list.id}`;
    const [ticked, setTicked] = useState<Set<number>>(() => {
        const fromServer = new Set(
            items.filter((i) => i.is_checked).map((i) => i.id),
        );
        if (typeof window === 'undefined') return fromServer;
        try {
            const raw = localStorage.getItem(storageKey);
            if (raw) return new Set(JSON.parse(raw) as number[]);
        } catch {
            /* ignore */
        }
        return fromServer;
    });
    const checkedCount = ticked.size;
    const loadedChecked = useMemo(
        () => new Set(items.filter((i) => i.is_checked).map((i) => i.id)),
        [items],
    );
    const ticksDirty =
        ticked.size !== loadedChecked.size ||
        Array.from(ticked).some((id) => !loadedChecked.has(id));
    useEffect(() => {
        if (typeof window === 'undefined') return;
        try {
            localStorage.setItem(
                storageKey,
                JSON.stringify(Array.from(ticked)),
            );
        } catch {
            /* ignore */
        }
    }, [ticked, storageKey]);
    useEffect(() => {
        if (!ticksDirty || typeof window === 'undefined') return;
        const handler = (e: BeforeUnloadEvent) => {
            e.preventDefault();
            e.returnValue = '';
        };
        window.addEventListener('beforeunload', handler);
        return () => window.removeEventListener('beforeunload', handler);
    }, [ticksDirty]);
    const grouped = useMemo(() => groupItems(items), [items]);
    const [exportOpen, setExportOpen] = useState(false);
    const exportRef = useRef<HTMLDivElement>(null);
    const exportBtnRef = useRef<HTMLButtonElement>(null);
    const exportMenuRef = useRef<HTMLDivElement>(null);
    const exportMenuId = useId();
    useEffect(() => {
        function onDoc(e: MouseEvent) {
            if (
                exportRef.current &&
                !exportRef.current.contains(e.target as Node)
            )
                setExportOpen(false);
        }
        document.addEventListener('mousedown', onDoc);
        return () => document.removeEventListener('mousedown', onDoc);
    }, []);
    useEffect(() => {
        if (exportOpen)
            exportMenuRef.current
                ?.querySelector<HTMLElement>('[role="menuitem"]')
                ?.focus();
    }, [exportOpen]);
    function closeExport(restoreFocus = true) {
        setExportOpen(false);
        if (restoreFocus) exportBtnRef.current?.focus();
    }
    function onExportMenuKeyDown(e: React.KeyboardEvent) {
        const els = Array.from(
            exportMenuRef.current?.querySelectorAll<HTMLElement>(
                '[role="menuitem"]',
            ) ?? [],
        );
        if (els.length === 0) return;
        const idx = els.indexOf(document.activeElement as HTMLElement);
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            els[(idx + 1) % els.length]?.focus();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            els[(idx - 1 + els.length) % els.length]?.focus();
        }
    }

    function toggle(id: number) {
        setTicked((cur) => {
            const next = new Set(cur);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    }

    async function markReceived() {
        try {
            const payload = items.map((i) => ({
                id: i.id,
                received_qty: i.received_qty ?? i.needed_qty,
            }));
            await axios.post(
                `/sites/${siteId}/meal-shopping-lists/${list.id}/receive`,
                { items: payload },
            );
            toast.success('List received · stock added to inventory');
            try {
                localStorage.removeItem(storageKey);
            } catch {
                /* ignore */
            }
            onChanged();
        } catch {
            toast.error('Could not mark received');
        }
    }

    return (
        <GuardrailCard
            unstyled
            className="overflow-hidden rounded-xl border border-border bg-card shadow-sm"
        >
            <div className="border-b border-border bg-gradient-to-br from-sites-bg/60 to-transparent p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex items-start gap-3">
                        <div className="rounded-xl bg-sites p-2 text-primary-foreground">
                            <ShoppingCart className="h-5 w-5" />
                        </div>
                        <div>
                            <div className="flex items-center gap-2">
                                <span className="text-base font-semibold text-foreground">
                                    Draft shopping list
                                </span>
                                <span
                                    className={cn(
                                        'rounded-full border px-2 py-0.5 text-[10.5px] font-semibold capitalize',
                                        STATUS_BADGE[list.status],
                                    )}
                                >
                                    {list.status}
                                </span>
                            </div>
                            <div className="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground">
                                <CalendarDays className="h-3 w-3" /> Covers{' '}
                                {list.covers_from} → {list.covers_to}
                                {list.generated_by && (
                                    <span>· by {list.generated_by.name}</span>
                                )}
                            </div>
                        </div>
                    </div>
                    <div className="text-right">
                        <div className="text-[10px] tracking-wide text-muted-foreground uppercase">
                            Estimated total
                        </div>
                        <div className="text-2xl font-bold text-foreground tabular-nums">
                            {money(totalCents)}
                        </div>
                        <div className="text-[10px] text-muted-foreground">
                            {items.length} item{items.length === 1 ? '' : 's'}
                        </div>
                    </div>
                </div>

                {items.length > 0 && (
                    <div className="mt-3">
                        <div
                            role="progressbar"
                            aria-valuenow={checkedCount}
                            aria-valuemin={0}
                            aria-valuemax={items.length}
                            aria-label={`${checkedCount} of ${items.length} items collected`}
                            className="relative h-2 w-full overflow-hidden rounded-full bg-muted"
                        >
                            <div
                                className="h-full rounded-full bg-sites transition-all"
                                style={{
                                    width: `${(checkedCount / items.length) * 100}%`,
                                }}
                            />
                        </div>
                        <div className="mt-1 text-[11px] text-muted-foreground">
                            {checkedCount} of {items.length} ticked off
                        </div>
                        <div className="mt-0.5 text-[10.5px] text-muted-foreground/80">
                            Tick progress is in this browser only — click{' '}
                            <strong className="font-medium">
                                Mark received
                            </strong>{' '}
                            to save it.
                        </div>
                    </div>
                )}

                {canManage && (
                    <div className="mt-3 flex flex-wrap gap-2">
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setShowAdd(!showAdd)}
                        >
                            <Plus className="mr-1 h-3.5 w-3.5" /> Add item
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={onGenerate}
                        >
                            <RefreshCw className="mr-1 h-3.5 w-3.5" />{' '}
                            Regenerate
                        </Button>
                        <div
                            ref={exportRef}
                            className="relative"
                            onKeyDown={(e) => {
                                if (e.key === 'Escape' && exportOpen) {
                                    e.stopPropagation();
                                    closeExport();
                                }
                            }}
                        >
                            <Button
                                ref={exportBtnRef}
                                size="sm"
                                variant="outline"
                                aria-haspopup="menu"
                                aria-expanded={exportOpen}
                                aria-controls={
                                    exportOpen ? exportMenuId : undefined
                                }
                                onClick={() => setExportOpen((v) => !v)}
                            >
                                <Download
                                    className="mr-1 h-3.5 w-3.5"
                                    aria-hidden="true"
                                />{' '}
                                Export{' '}
                                <ChevronDown
                                    className="ml-1 h-3 w-3"
                                    aria-hidden="true"
                                />
                            </Button>
                            {exportOpen && (
                                <div
                                    ref={exportMenuRef}
                                    id={exportMenuId}
                                    role="menu"
                                    aria-label="Export shopping list"
                                    onKeyDown={onExportMenuKeyDown}
                                    className="absolute left-0 z-50 mt-1.5 w-[210px] animate-pop overflow-hidden rounded-xl border border-border bg-popover p-1 shadow-float"
                                >
                                    <Button
                                        unstyled
                                        type="button"
                                        role="menuitem"
                                        onClick={() => {
                                            onExportPrint();
                                            closeExport(false);
                                        }}
                                        className="flex w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-left text-[13px] font-medium hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                                    >
                                        <FileText
                                            className="h-[15px] w-[15px] text-muted-foreground"
                                            aria-hidden="true"
                                        />{' '}
                                        Download PDF (branded)
                                    </Button>
                                    <Button
                                        unstyled
                                        type="button"
                                        role="menuitem"
                                        onClick={() => {
                                            exportCsv(list, null);
                                            closeExport(false);
                                        }}
                                        className="flex w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-left text-[13px] font-medium hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                                    >
                                        <FileSpreadsheet
                                            className="h-[15px] w-[15px] text-muted-foreground"
                                            aria-hidden="true"
                                        />{' '}
                                        Export for Excel (.csv)
                                    </Button>
                                </div>
                            )}
                        </div>
                        <ConfirmAction
                            title="Mark this list as received?"
                            description="Tracked items flow into inventory and the list moves to history."
                            confirmLabel="Mark received"
                            onConfirm={markReceived}
                        >
                            <Button size="sm">
                                <Truck className="mr-1 h-3.5 w-3.5" /> Mark
                                received
                            </Button>
                        </ConfirmAction>
                    </div>
                )}
            </div>

            {showAdd && canManage && (
                <AddManualItem
                    siteId={siteId}
                    list={list}
                    products={products}
                    onDone={() => {
                        setShowAdd(false);
                        onChanged();
                    }}
                />
            )}

            {items.length === 0 ? (
                <div className="px-3 py-10 text-center text-sm text-muted-foreground">
                    No items on this list yet. Generate or add one above.
                </div>
            ) : (
                <div className="divide-y divide-border">
                    {grouped.map(([category, rows]) => {
                        const subtotal = rows.reduce(
                            (s, i) => s + (i.estimated_cost_cents ?? 0),
                            0,
                        );
                        return (
                            <section key={category}>
                                <header className="flex items-center justify-between gap-2 bg-muted/30 px-4 py-2 text-xs">
                                    <span className="font-semibold tracking-wide text-muted-foreground capitalize uppercase">
                                        {category}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {rows.length} · {money(subtotal)}
                                    </span>
                                </header>
                                <ul className="divide-y divide-border">
                                    {rows.map((item) => (
                                        <ItemRow
                                            key={item.id}
                                            siteId={siteId}
                                            listId={list.id}
                                            item={item}
                                            canManage={canManage}
                                            checked={ticked.has(item.id)}
                                            onToggle={() => toggle(item.id)}
                                            onChanged={onChanged}
                                        />
                                    ))}
                                </ul>
                            </section>
                        );
                    })}
                </div>
            )}
        </GuardrailCard>
    );
}

function ItemRow({
    siteId,
    listId,
    item,
    canManage,
    checked,
    onToggle,
    onChanged,
}: {
    siteId: number;
    listId: number;
    item: ShoppingListItem;
    canManage: boolean;
    checked: boolean;
    onToggle: () => void;
    onChanged: () => void;
}) {
    const name = itemName(item);
    const source = SOURCE_BADGE[item.source];

    async function destroy() {
        try {
            await axios.delete(
                `/sites/${siteId}/meal-shopping-lists/${listId}/items/${item.id}`,
            );
            onChanged();
        } catch {
            toast.error('Could not remove item');
        }
    }

    return (
        <li
            className={cn(
                'flex items-center gap-3 px-4 py-2.5',
                checked ? 'bg-status-success-bg/30' : 'hover:bg-accent/30',
            )}
        >
            <Button
                unstyled
                type="button"
                onClick={onToggle}
                aria-pressed={checked}
                aria-label={checked ? `Untick ${name}` : `Tick off ${name}`}
                className={cn(
                    'flex h-6 w-6 flex-none items-center justify-center rounded-full border transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                    checked
                        ? 'border-status-success bg-status-success text-white'
                        : 'border-muted-foreground/30 hover:border-sites',
                )}
            >
                {checked && <Check className="h-3.5 w-3.5" />}
            </Button>
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                    <span
                        className={cn(
                            'truncate text-sm font-medium',
                            checked
                                ? 'text-muted-foreground line-through'
                                : 'text-foreground',
                        )}
                    >
                        {name}
                    </span>
                    <span
                        className={cn(
                            'rounded-full border px-1.5 py-px text-[10px] font-medium',
                            source.className,
                        )}
                    >
                        {source.label}
                    </span>
                </div>
                {item.notes && (
                    <div className="mt-0.5 text-xs text-muted-foreground">
                        {item.notes}
                    </div>
                )}
            </div>
            <div className="flex flex-none items-center gap-3 text-sm">
                <span className="font-medium tabular-nums">
                    {formatQty(item.needed_qty, item.unit)}
                </span>
                {item.estimated_cost_cents != null && (
                    <span className="hidden text-muted-foreground tabular-nums sm:inline">
                        {money(item.estimated_cost_cents)}
                    </span>
                )}
                {canManage && (
                    <ConfirmAction
                        title="Remove this item?"
                        description={`Remove "${name}" from this shopping list.`}
                        confirmLabel="Remove"
                        onConfirm={destroy}
                    >
                        <Button size="icon" variant="ghost" className="h-7 w-7">
                            <Trash2 className="h-4 w-4 text-status-critical" />
                        </Button>
                    </ConfirmAction>
                )}
            </div>
        </li>
    );
}

function AddManualItem({
    siteId,
    list,
    products,
    onDone,
}: {
    siteId: number;
    list: ShoppingList;
    products: { id: number; name: string; default_unit: string }[];
    onDone: () => void;
}) {
    const [productId, setProductId] = useState('free');
    const [name, setName] = useState('');
    const [qty, setQty] = useState('1');
    const [unit, setUnit] = useState('each');
    const [busy, setBusy] = useState(false);

    async function submit(e: React.FormEvent) {
        e.preventDefault();
        setBusy(true);
        try {
            await axios.post(
                `/sites/${siteId}/meal-shopping-lists/${list.id}/items`,
                {
                    product_id: productId === 'free' ? null : Number(productId),
                    free_text_name: productId === 'free' ? name : null,
                    needed_qty: Number(qty),
                    unit,
                },
            );
            setName('');
            setQty('1');
            setProductId('free');
            setUnit('each');
            onDone();
        } catch {
            toast.error('Could not add item');
        } finally {
            setBusy(false);
        }
    }

    return (
        <form
            onSubmit={submit}
            className="grid grid-cols-12 items-end gap-2 border-b border-border bg-amberx-bg/40 p-3"
        >
            <div className="col-span-12 sm:col-span-5">
                <Label className="text-xs">Product</Label>
                <Select
                    value={productId}
                    onValueChange={(v) => {
                        setProductId(v);
                        if (v !== 'free') {
                            const p = products.find((x) => String(x.id) === v);
                            if (p) setUnit(p.default_unit);
                        }
                    }}
                >
                    <SelectTrigger>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="free">— Free text —</SelectItem>
                        {products.map((p) => (
                            <SelectItem key={p.id} value={String(p.id)}>
                                {p.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                {productId === 'free' && (
                    <Input
                        className="mt-1"
                        placeholder="Item name"
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        required
                    />
                )}
            </div>
            <div className="col-span-4 sm:col-span-2">
                <Label className="text-xs">Qty</Label>
                <Input
                    type="number"
                    min={0}
                    step="0.01"
                    value={qty}
                    onChange={(e) => setQty(e.target.value)}
                />
            </div>
            <div className="col-span-4 sm:col-span-2">
                <Label className="text-xs">Unit</Label>
                <Input value={unit} onChange={(e) => setUnit(e.target.value)} />
            </div>
            <div className="col-span-4 flex gap-2 sm:col-span-3">
                <Button type="submit" disabled={busy} className="flex-1">
                    Add
                </Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}

function HistoryRow({
    list,
    onView,
    onPrint,
    onExportCsv,
}: {
    list: ShoppingList;
    onView: () => void;
    onPrint: () => void;
    onExportCsv: () => void;
}) {
    const items = list.items ?? [];
    const total = items.reduce((s, i) => s + (i.estimated_cost_cents ?? 0), 0);
    return (
        <GuardrailCard
            unstyled
            className="flex items-center gap-3 rounded-lg border border-border bg-card px-3 py-2.5"
        >
            <div
                className={cn(
                    'flex h-9 w-9 shrink-0 items-center justify-center rounded-lg',
                    list.status === 'received'
                        ? 'bg-status-success-bg text-status-success'
                        : 'bg-muted text-muted-foreground',
                )}
            >
                {list.status === 'received' ? (
                    <Check className="h-4 w-4" />
                ) : (
                    <ShoppingCart className="h-4 w-4" />
                )}
            </div>
            <Button
                unstyled
                type="button"
                onClick={onView}
                className="min-w-0 flex-1 text-left"
            >
                <div className="flex items-center gap-2">
                    <span className="text-sm font-medium text-foreground">
                        {nzDate(list.covers_from)} → {nzDate(list.covers_to)}
                    </span>
                    <span
                        className={cn(
                            'rounded-full border px-1.5 py-px text-[10px] font-semibold capitalize',
                            STATUS_BADGE[list.status],
                        )}
                    >
                        {list.status}
                    </span>
                </div>
                <div className="truncate text-[11px] text-muted-foreground">
                    {items.length} items
                    {list.generated_by ? ` · ${list.generated_by.name}` : ''}
                    {listProvenance(list) ? ` · ${listProvenance(list)}` : ''}
                </div>
            </Button>
            <span className="shrink-0 text-sm font-semibold text-foreground tabular-nums">
                {money(total)}
            </span>
            <div className="flex shrink-0 items-center gap-1">
                <Button
                    size="icon"
                    variant="ghost"
                    className="h-7 w-7"
                    onClick={onPrint}
                    aria-label="Print PDF"
                >
                    <Printer className="h-3.5 w-3.5" />
                </Button>
                <Button
                    size="icon"
                    variant="ghost"
                    className="h-7 w-7"
                    onClick={onExportCsv}
                    aria-label="Export CSV"
                >
                    <FileSpreadsheet className="h-3.5 w-3.5" />
                </Button>
            </div>
        </GuardrailCard>
    );
}

function ViewListDialog({
    list,
    onClose,
}: {
    list: ShoppingList;
    onClose: () => void;
}) {
    const items = list.items ?? [];
    const grouped = groupItems(items);
    const total = items.reduce((s, i) => s + (i.estimated_cost_cents ?? 0), 0);
    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <ShoppingCart className="h-4 w-4 text-sites" />{' '}
                        {nzDate(list.covers_from)} → {nzDate(list.covers_to)}
                    </DialogTitle>
                    <DialogDescription className="capitalize">
                        {list.status}
                        {listProvenance(list) ? (
                            <span className="normal-case">
                                {' '}
                                · {listProvenance(list)}
                            </span>
                        ) : null}
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-3">
                    {grouped.map(([cat, rows]) => (
                        <div key={cat}>
                            <div className="mb-1 text-[11px] font-semibold tracking-wide text-muted-foreground capitalize uppercase">
                                {cat}
                            </div>
                            <ul className="space-y-1">
                                {rows.map((i) => (
                                    <li
                                        key={i.id}
                                        className="flex items-center justify-between gap-2 text-sm"
                                    >
                                        <span className="text-foreground">
                                            {itemName(i)}
                                        </span>
                                        <span className="text-muted-foreground tabular-nums">
                                            {formatQty(i.needed_qty, i.unit)} ·{' '}
                                            {money(i.estimated_cost_cents)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ))}
                    <div className="flex items-center justify-between border-t border-border pt-2 text-sm font-semibold">
                        <span>Total</span>
                        <span className="tabular-nums">{money(total)}</span>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}

function exportCsv(list: ShoppingList, _site: SiteInfo | null) {
    const items = list.items ?? [];
    const rows = [
        ['Category', 'Item', 'Qty', 'Unit', 'Source', 'Est cost (NZD)'],
    ];
    for (const i of items) {
        rows.push([
            itemCategory(i),
            itemName(i),
            String(i.needed_qty),
            i.unit,
            i.source,
            ((i.estimated_cost_cents ?? 0) / 100).toFixed(2),
        ]);
    }
    const csv = rows
        .map((r) =>
            r.map((c) => `"${String(c).replace(/"/g, '""')}"`).join(','),
        )
        .join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `shopping-list-${list.id}-${list.covers_from}.csv`;
    a.click();
    URL.revokeObjectURL(url);
    toast.success('CSV downloaded');
}

function BrandedListPrintDoc({
    list,
    site,
}: {
    list: ShoppingList;
    site: SiteInfo;
}) {
    const items = list.items ?? [];
    const grouped = groupItems(items);
    const total = items.reduce((s, i) => s + (i.estimated_cost_cents ?? 0), 0);
    // Follow Settings → Branding instead of a hardcoded green (P2-1).
    const brand =
        (typeof window !== 'undefined' &&
            getComputedStyle(document.documentElement)
                .getPropertyValue('--primary')
                .trim()) ||
        '#1f7a4d';
    const brandTintBg = `color-mix(in oklch, ${brand} 12%, white)`;
    const brandDeep = `color-mix(in oklch, ${brand}, black 22%)`;
    return createPortal(
        <div
            className="mp-print-doc"
            style={{
                fontFamily: "'Instrument Sans', sans-serif",
                color: '#1a1a2e',
            }}
        >
            <div
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                    borderBottom: `3px solid ${brand}`,
                    paddingBottom: 12,
                    marginBottom: 14,
                }}
            >
                <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                    <div
                        style={{
                            width: 44,
                            height: 44,
                            borderRadius: 12,
                            background: brand,
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            color: '#fff',
                        }}
                    >
                        <ChefHat className="h-6 w-6" />
                    </div>
                    <div>
                        <div
                            style={{
                                fontSize: 18,
                                fontWeight: 700,
                                lineHeight: 1.1,
                            }}
                        >
                            Oblivion Findings
                        </div>
                        <div
                            style={{
                                fontSize: 11,
                                color: '#6b6b80',
                                textTransform: 'uppercase',
                                letterSpacing: '0.04em',
                            }}
                        >
                            Shopping List · {site.name}
                        </div>
                    </div>
                </div>
                <div style={{ textAlign: 'right' }}>
                    <div
                        style={{ fontSize: 18, fontWeight: 700, color: brand }}
                    >
                        Weekly Shopping List
                    </div>
                    <div style={{ fontSize: 12, color: '#6b6b80' }}>
                        {list.covers_from} → {list.covers_to}
                        {list.generated_by
                            ? ` · ${list.generated_by.name}`
                            : ''}
                    </div>
                </div>
            </div>
            {grouped.map(([cat, rows]) => {
                const subtotal = rows.reduce(
                    (s, i) => s + (i.estimated_cost_cents ?? 0),
                    0,
                );
                return (
                    <div
                        key={cat}
                        className="pg-break"
                        style={{ marginBottom: 10 }}
                    >
                        <div
                            style={{
                                display: 'flex',
                                justifyContent: 'space-between',
                                background: brandTintBg,
                                borderRadius: 6,
                                padding: '5px 10px',
                                fontSize: 12.5,
                                fontWeight: 700,
                                color: brandDeep,
                            }}
                        >
                            <span style={{ textTransform: 'capitalize' }}>
                                {cat}
                            </span>
                            <span>{money(subtotal)}</span>
                        </div>
                        <table
                            style={{
                                width: '100%',
                                borderCollapse: 'collapse',
                                fontSize: 12.5,
                            }}
                        >
                            <tbody>
                                {rows.map((i) => (
                                    <tr
                                        key={i.id}
                                        style={{
                                            borderBottom: '1px solid #e6e6ef',
                                        }}
                                    >
                                        <td
                                            style={{
                                                padding: '5px 10px',
                                                width: 24,
                                            }}
                                        >
                                            ☐
                                        </td>
                                        <td
                                            style={{
                                                padding: '5px 10px',
                                                fontWeight: 600,
                                            }}
                                        >
                                            {itemName(i)}
                                        </td>
                                        <td
                                            style={{
                                                padding: '5px 10px',
                                                width: 90,
                                                textAlign: 'right',
                                            }}
                                        >
                                            {formatQty(i.needed_qty, i.unit)}
                                        </td>
                                        <td
                                            style={{
                                                padding: '5px 10px',
                                                width: 80,
                                                textAlign: 'right',
                                                color: '#6b6b80',
                                            }}
                                        >
                                            {money(i.estimated_cost_cents)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                );
            })}
            <div
                style={{
                    display: 'flex',
                    justifyContent: 'space-between',
                    borderTop: `2px solid ${brand}`,
                    paddingTop: 8,
                    marginTop: 8,
                    fontSize: 14,
                    fontWeight: 700,
                }}
            >
                <span>Grand total</span>
                <span>{money(total)}</span>
            </div>
            <div style={{ marginTop: 16, fontSize: 10, color: '#9a9ab0' }}>
                Oblivion Findings Meal Planner · Estimated costs
            </div>
        </div>,
        document.body,
    );
}
