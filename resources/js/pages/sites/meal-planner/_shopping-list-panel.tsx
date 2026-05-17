import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { router } from '@inertiajs/react';
import { AlertTriangle, CalendarDays, Check, ChevronDown, ChevronRight, Plus, RefreshCw, ShoppingCart, Trash2, Truck } from 'lucide-react';
import { useMemo, useState } from 'react';
import { ConfirmAction } from '../_confirm-action';
import { formatMoneyFromCents, formatQty, type ConflictSummary, type ShoppingList, type ShoppingListItem } from './_helpers';

type Props = {
    siteId: number;
    lists: ShoppingList[];
    conflictsByList: Record<number, ConflictSummary>;
    canManage: boolean;
    products: { id: number; name: string; default_unit: string }[];
    onGenerate: () => void;
    onChanged: () => void;
    onJumpToEntry: (entryId: number, planDate: string) => void;
};

const SOURCE_BADGE: Record<ShoppingListItem['source'], { label: string; className: string }> = {
    meal_plan: { label: 'From plan', className: 'border-primary/30 bg-primary/10 text-primary' },
    restock_to_par: { label: 'Top up', className: 'border-sky-300 bg-sky-100 text-sky-900' },
    manual: { label: 'Manual', className: 'border-amber-300 bg-amber-100 text-amber-900' },
};

const STATUS_BADGE: Record<ShoppingList['status'], string> = {
    draft: 'border-primary/30 bg-primary/10 text-primary',
    ordered: 'border-sky-300 bg-sky-100 text-sky-900',
    received: 'border-emerald-300 bg-emerald-100 text-emerald-900',
    cancelled: 'border-muted bg-muted text-muted-foreground',
};

export default function ShoppingListPanel({ siteId, lists, conflictsByList, canManage, products, onGenerate, onChanged, onJumpToEntry }: Props) {
    const draft = lists.find((l) => l.status === 'draft') ?? null;
    const [showAdd, setShowAdd] = useState(false);
    const [historyOpen, setHistoryOpen] = useState(false);
    const [conflictsExpanded, setConflictsExpanded] = useState(false);
    const draftConflicts = draft ? conflictsByList[draft.id] : null;

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between gap-2">
                <div>
                    <h2 className="text-lg font-semibold">Shopping list</h2>
                    <p className="text-xs text-muted-foreground">Items pulled from this week's meal plan, plus anything below par level.</p>
                </div>
                {canManage && (
                    <Button variant="outline" onClick={onGenerate}>
                        <RefreshCw className="mr-2 h-4 w-4" /> {draft ? 'Regenerate' : 'Generate'}
                    </Button>
                )}
            </div>

            {draft && draftConflicts && draftConflicts.count > 0 && (
                <div className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm">
                    <div className="flex items-start justify-between gap-3">
                        <div className="flex items-start gap-2 text-amber-900">
                            <AlertTriangle className="mt-0.5 h-4 w-4 flex-none" />
                            <div>
                                <div className="font-semibold">
                                    {draftConflicts.count} planned meal{draftConflicts.count === 1 ? '' : 's'} contain{draftConflicts.count === 1 ? 's' : ''} allergens for current residents
                                </div>
                                {draftConflicts.unresolved_count > 0 && (
                                    <div className="text-xs">
                                        {draftConflicts.unresolved_count} still need{draftConflicts.unresolved_count === 1 ? 's' : ''} an override decision.
                                    </div>
                                )}
                            </div>
                        </div>
                        <Button size="sm" variant="outline" onClick={() => setConflictsExpanded((v) => !v)}>
                            {conflictsExpanded ? 'Hide' : 'Review'}
                        </Button>
                    </div>
                    {conflictsExpanded && (
                        <ul className="mt-3 space-y-1 text-xs text-amber-900">
                            {draftConflicts.details.map((d) => (
                                <li key={`${d.plan_entry_id}-${d.client_name}`} className="flex items-start justify-between gap-2 rounded-md bg-white/60 px-2 py-1">
                                    <div>
                                        <strong>{d.plan_date} · {d.meal_slot.replace('_', ' ')} · {d.recipe_name}</strong>
                                        <div>{d.client_name} — {d.matches.join(', ')}</div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        {d.has_override && <span className="text-[10px] text-emerald-700">override on file</span>}
                                        <Button size="sm" variant="ghost" onClick={() => onJumpToEntry(d.plan_entry_id, d.plan_date)}>
                                            Open meal
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}

            {draft ? (
                <DraftCard
                    siteId={siteId}
                    list={draft}
                    canManage={canManage}
                    products={products}
                    showAdd={showAdd}
                    setShowAdd={setShowAdd}
                    onChanged={onChanged}
                />
            ) : (
                <EmptyState canManage={canManage} onGenerate={onGenerate} />
            )}

            <div className="rounded-md border bg-card">
                <button
                    type="button"
                    className="flex w-full items-center justify-between gap-2 px-4 py-3 text-sm font-medium hover:bg-accent"
                    onClick={() => setHistoryOpen((v) => !v)}
                >
                    <span className="flex items-center gap-2">
                        {historyOpen ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                        Past lists ({lists.length - (draft ? 1 : 0)})
                    </span>
                    <span className="text-xs text-muted-foreground">Ordered + received history</span>
                </button>
                {historyOpen && (
                    <div className="border-t bg-muted/10 p-3">
                        {lists.filter((l) => l !== draft).length === 0 ? (
                            <div className="py-3 text-center text-sm text-muted-foreground">No past lists yet.</div>
                        ) : (
                            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                {lists.filter((l) => l !== draft).map((l) => (
                                    <HistoryCard key={l.id} list={l} />
                                ))}
                            </div>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}

function EmptyState({ canManage, onGenerate }: { canManage: boolean; onGenerate: () => void }) {
    return (
        <div className="flex flex-col items-center gap-3 rounded-md border-2 border-dashed bg-card p-10 text-center">
            <div className="rounded-full bg-primary/10 p-3 text-primary"><ShoppingCart className="h-6 w-6" /></div>
            <div>
                <div className="font-medium">No draft shopping list yet</div>
                <p className="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                    Generate one from this week's planned meals plus anything below par level. Manual items will survive future regenerations.
                </p>
            </div>
            {canManage && (
                <Button onClick={onGenerate}>
                    <RefreshCw className="mr-2 h-4 w-4" /> Generate now
                </Button>
            )}
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
    onChanged,
}: {
    siteId: number;
    list: ShoppingList;
    canManage: boolean;
    products: { id: number; name: string; default_unit: string }[];
    showAdd: boolean;
    setShowAdd: (v: boolean) => void;
    onChanged: () => void;
}) {
    const items = list.items ?? [];
    const totalCents = items.reduce((sum, i) => sum + (i.estimated_cost_cents ?? 0), 0);
    const checkedCount = items.filter((i) => i.is_checked || i.received_qty !== null).length;

    // Group items by product category (fallback "Other" or "Manual" for free-text)
    const grouped = useMemo(() => {
        const groups = new Map<string, ShoppingListItem[]>();
        for (const item of items) {
            const cat = (item.product?.category ?? null) ?? (item.source === 'manual' ? 'Manual' : 'Other');
            if (!groups.has(cat)) groups.set(cat, []);
            groups.get(cat)!.push(item);
        }
        return Array.from(groups.entries()).sort((a, b) => a[0].localeCompare(b[0]));
    }, [items]);

    return (
        <div className="overflow-hidden rounded-lg border bg-card shadow-sm">
            {/* Header */}
            <div className="border-b bg-gradient-to-br from-primary/5 to-transparent p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex items-start gap-3">
                        <div className="rounded-md bg-primary/10 p-2 text-primary"><ShoppingCart className="h-5 w-5" /></div>
                        <div>
                            <div className="flex items-center gap-2">
                                <span className="text-base font-semibold">Draft shopping list</span>
                                <Badge variant="outline" className={STATUS_BADGE[list.status]}>{list.status}</Badge>
                            </div>
                            <div className="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground">
                                <CalendarDays className="h-3 w-3" />
                                Covers {list.covers_from} → {list.covers_to}
                            </div>
                        </div>
                    </div>

                    <div className="text-right">
                        <div className="text-[10px] uppercase tracking-wide text-muted-foreground">Estimated total</div>
                        <div className="text-2xl font-bold">{formatMoneyFromCents(totalCents)}</div>
                        <div className="text-[10px] text-muted-foreground">{items.length} item{items.length === 1 ? '' : 's'}</div>
                    </div>
                </div>

                {/* Action row */}
                {canManage && (
                    <div className="mt-3 flex flex-wrap gap-2">
                        <Button size="sm" variant="outline" onClick={() => setShowAdd(!showAdd)}>
                            <Plus className="mr-1 h-3 w-3" /> Add item
                        </Button>
                        <MarkOrderedButton siteId={siteId} list={list} onChanged={onChanged} />
                        <MarkReceivedButton siteId={siteId} list={list} onChanged={onChanged} />
                    </div>
                )}
            </div>

            {/* Manual-item form */}
            {showAdd && canManage && (
                <AddManualItem siteId={siteId} list={list} products={products} onDone={() => { setShowAdd(false); onChanged(); }} />
            )}

            {/* Item groups */}
            {items.length === 0 ? (
                <div className="px-3 py-10 text-center text-sm text-muted-foreground">
                    No items on this list yet. Generate or add one above.
                </div>
            ) : (
                <div className="divide-y">
                    {grouped.map(([category, rows]) => (
                        <CategoryGroup
                            key={category}
                            category={category}
                            items={rows}
                            siteId={siteId}
                            listId={list.id}
                            canManage={canManage}
                            onChanged={onChanged}
                        />
                    ))}
                </div>
            )}

            {/* Footer total */}
            {items.length > 0 && (
                <div className="flex items-center justify-between gap-2 border-t bg-muted/20 px-4 py-3 text-sm">
                    <div className="text-muted-foreground">
                        {checkedCount} of {items.length} ticked off
                    </div>
                    <div className="text-base font-semibold">
                        {formatMoneyFromCents(totalCents)}
                    </div>
                </div>
            )}
        </div>
    );
}

function CategoryGroup({
    category,
    items,
    siteId,
    listId,
    canManage,
    onChanged,
}: {
    category: string;
    items: ShoppingListItem[];
    siteId: number;
    listId: number;
    canManage: boolean;
    onChanged: () => void;
}) {
    const subtotal = items.reduce((sum, i) => sum + (i.estimated_cost_cents ?? 0), 0);
    return (
        <section>
            <header className="flex items-center justify-between gap-2 bg-muted/30 px-4 py-2 text-xs">
                <span className="font-semibold uppercase tracking-wide text-muted-foreground">
                    {category}
                </span>
                <span className="text-muted-foreground">
                    {items.length} · {formatMoneyFromCents(subtotal)}
                </span>
            </header>
            <ul className="divide-y">
                {items.map((item) => (
                    <ItemRow
                        key={item.id}
                        siteId={siteId}
                        listId={listId}
                        item={item}
                        canManage={canManage}
                        onChanged={onChanged}
                    />
                ))}
            </ul>
        </section>
    );
}

function ItemRow({ siteId, listId, item, canManage, onChanged }: { siteId: number; listId: number; item: ShoppingListItem; canManage: boolean; onChanged: () => void }) {
    const itemName = item.product?.name ?? item.free_text_name ?? 'item';
    const source = SOURCE_BADGE[item.source];
    const isChecked = item.is_checked || item.received_qty !== null;

    function destroy() {
        router.delete(`/sites/${siteId}/meal-shopping-lists/${listId}/items/${item.id}`, {
            preserveScroll: true,
            onSuccess: () => onChanged(),
        });
    }

    return (
        <li className={`flex items-center gap-3 px-4 py-3 ${isChecked ? 'bg-emerald-50/40' : 'hover:bg-accent/30'}`}>
            <div className={`flex h-5 w-5 flex-none items-center justify-center rounded-full border ${isChecked ? 'border-emerald-500 bg-emerald-500 text-white' : 'border-muted-foreground/30'}`}>
                {isChecked && <Check className="h-3 w-3" />}
            </div>

            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                    <span className={`truncate text-sm font-medium ${isChecked ? 'text-muted-foreground line-through' : ''}`}>{itemName}</span>
                    <Badge variant="outline" className={`text-[10px] ${source.className}`}>{source.label}</Badge>
                </div>
                {item.notes && <div className="mt-0.5 text-xs text-muted-foreground">{item.notes}</div>}
            </div>

            <div className="flex flex-none items-center gap-3 text-sm">
                <span className="font-medium">{formatQty(item.needed_qty, item.unit)}</span>
                {item.estimated_cost_cents !== null && (
                    <span className="hidden text-muted-foreground sm:inline">{formatMoneyFromCents(item.estimated_cost_cents)}</span>
                )}
                {canManage && (
                    <ConfirmAction
                        title="Remove this item?"
                        description={`Remove "${itemName}" from this shopping list.`}
                        confirmLabel="Remove"
                        onConfirm={destroy}
                    >
                        <Button size="icon" variant="ghost"><Trash2 className="h-4 w-4 text-destructive" /></Button>
                    </ConfirmAction>
                )}
            </div>
        </li>
    );
}

function AddManualItem({ siteId, list, products, onDone }: { siteId: number; list: ShoppingList; products: { id: number; name: string; default_unit: string }[]; onDone: () => void }) {
    const [productId, setProductId] = useState<string>('free');
    const [name, setName] = useState('');
    const [qty, setQty] = useState('1');
    const [unit, setUnit] = useState('each');
    const [busy, setBusy] = useState(false);

    function submit(e: React.FormEvent) {
        e.preventDefault();
        setBusy(true);
        router.post(`/sites/${siteId}/meal-shopping-lists/${list.id}/items`, {
            product_id: productId === 'free' ? null : Number(productId),
            free_text_name: productId === 'free' ? name : null,
            needed_qty: Number(qty),
            unit,
        }, {
            preserveScroll: true,
            onSuccess: () => { setName(''); setQty('1'); setProductId('free'); setUnit('each'); onDone(); },
            onFinish: () => setBusy(false),
        });
    }

    return (
        <form onSubmit={submit} className="grid grid-cols-12 items-end gap-2 border-b bg-amber-50/40 p-3">
            <div className="col-span-12 sm:col-span-5">
                <Label className="text-xs">Product</Label>
                <Select value={productId} onValueChange={(v) => { setProductId(v); if (v !== 'free') { const p = products.find((x) => String(x.id) === v); if (p) setUnit(p.default_unit); } }}>
                    <SelectTrigger><SelectValue /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="free">— Free text —</SelectItem>
                        {products.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}
                    </SelectContent>
                </Select>
                {productId === 'free' && <Input className="mt-1" placeholder="Item name" value={name} onChange={(e) => setName(e.target.value)} required />}
            </div>
            <div className="col-span-4 sm:col-span-2">
                <Label className="text-xs">Qty</Label>
                <Input type="number" min={0} step="0.01" value={qty} onChange={(e) => setQty(e.target.value)} />
            </div>
            <div className="col-span-4 sm:col-span-2">
                <Label className="text-xs">Unit</Label>
                <Input value={unit} onChange={(e) => setUnit(e.target.value)} />
            </div>
            <div className="col-span-4 sm:col-span-3 flex gap-2">
                <Button type="submit" disabled={busy} className="flex-1">Add</Button>
                <Button type="button" variant="ghost" onClick={onDone}>Cancel</Button>
            </div>
        </form>
    );
}

function MarkOrderedButton({ siteId, list, onChanged }: { siteId: number; list: ShoppingList; onChanged: () => void }) {
    if (list.status !== 'draft') return null;
    return (
        <ConfirmAction
            title="Mark as ordered?"
            description="You'll no longer be able to edit items on this list. Use this when you've placed the order with the supplier."
            confirmLabel="Mark ordered"
            onConfirm={() => router.put(`/sites/${siteId}/meal-shopping-lists/${list.id}`, { status: 'ordered' }, { preserveScroll: true, onSuccess: () => onChanged() })}
        >
            <Button size="sm" variant="outline">Mark ordered</Button>
        </ConfirmAction>
    );
}

function MarkReceivedButton({ siteId, list, onChanged }: { siteId: number; list: ShoppingList; onChanged: () => void }) {
    if (list.status === 'received' || list.status === 'cancelled') return null;
    return (
        <Button size="sm" onClick={() => {
            const items = (list.items ?? []).map((i) => ({ id: i.id, received_qty: i.received_qty ?? i.needed_qty }));
            router.post(`/sites/${siteId}/meal-shopping-lists/${list.id}/receive`, { items }, {
                preserveScroll: true,
                onSuccess: () => onChanged(),
            });
        }}><Truck className="mr-1 h-3 w-3" /> Mark received</Button>
    );
}

function HistoryCard({ list }: { list: ShoppingList }) {
    const items = list.items ?? [];
    const totalCents = items.reduce((sum, i) => sum + (i.estimated_cost_cents ?? 0), 0);
    return (
        <div className="rounded-md border bg-card p-3">
            <div className="flex items-start justify-between gap-2">
                <div>
                    <div className="text-sm font-medium">#{list.id}</div>
                    <div className="flex items-center gap-1 text-xs text-muted-foreground">
                        <CalendarDays className="h-3 w-3" />
                        {list.covers_from} → {list.covers_to}
                    </div>
                </div>
                <Badge variant="outline" className={`capitalize ${STATUS_BADGE[list.status]}`}>{list.status}</Badge>
            </div>
            <div className="mt-2 flex items-center justify-between text-xs text-muted-foreground">
                <span>{items.length} items</span>
                <span className="font-medium text-foreground">{formatMoneyFromCents(totalCents)}</span>
            </div>
        </div>
    );
}
