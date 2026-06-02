import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import axios from 'axios';
import { Boxes, CircleAlert, ClipboardCheck, DollarSign, Minus, Package, Pencil, Plus, TriangleAlert } from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import { ConfirmAction } from '../_confirm-action';
import { formatMoneyFromCents as money, formatQty, toNum, type InventoryItem } from './_helpers';

type Props = {
    siteId: number;
    items: InventoryItem[];
    canAdjust: boolean;
    canManageProducts?: boolean;
    onOpenAdjust: (item: InventoryItem) => void;
    onOpenStocktake: () => void;
    onAddItem: () => void;
    onManageProducts?: () => void;
    onChanged: () => void;
};

type StockState = 'out' | 'low' | 'ok';
function stockState(item: InventoryItem): StockState {
    const cur = toNum(item.current_qty);
    if (cur <= 0) return 'out';
    const reorder = item.reorder_level == null ? null : toNum(item.reorder_level);
    if (reorder != null && cur <= reorder) return 'low';
    return 'ok';
}

function Tile({ icon: Icon, label, value, sub, tone }: { icon: typeof Package; label: string; value: string; sub?: string; tone: 'primary' | 'success' | 'warning' | 'critical' }) {
    const toneClass: Record<string, string> = {
        primary: 'bg-sites-bg text-sites-deep',
        success: 'bg-status-success-bg text-status-success',
        warning: 'bg-status-warning-bg text-status-warning',
        critical: 'bg-status-critical-bg text-status-critical',
    };
    return (
        <div className="flex items-start gap-3 rounded-xl border border-border bg-card p-3.5 shadow-sm">
            <div className={cn('flex h-10 w-10 shrink-0 items-center justify-center rounded-xl', toneClass[tone])}>
                <Icon className="h-5 w-5" />
            </div>
            <div className="min-w-0">
                <div className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">{label}</div>
                <div className="text-xl font-bold tabular-nums text-foreground">{value}</div>
                {sub && <div className="text-[11px] text-muted-foreground">{sub}</div>}
            </div>
        </div>
    );
}

function StockGauge({ item }: { item: InventoryItem }) {
    const cur = toNum(item.current_qty);
    const par = item.par_level == null ? null : toNum(item.par_level);
    const reorder = item.reorder_level == null ? null : toNum(item.reorder_level);
    const state = stockState(item);
    const scaleMax = Math.max(par ?? 0, cur, 1);
    const pct = Math.min(100, (cur / scaleMax) * 100);
    const reorderPct = reorder != null ? Math.min(100, (reorder / scaleMax) * 100) : null;
    const barColor = state === 'out' ? 'bg-status-critical' : state === 'low' ? 'bg-status-warning' : 'bg-sites';

    return (
        <div className="w-40">
            <div className="relative h-2.5 w-full overflow-hidden rounded-full bg-muted">
                <div className={cn('h-full rounded-full transition-all', barColor)} style={{ width: `${Math.max(2, pct)}%` }} />
                {reorderPct != null && (
                    <div className="absolute top-1/2 h-3.5 w-px -translate-y-1/2 bg-status-critical/70" style={{ left: `${reorderPct}%` }} title={`Reorder at ${reorder}`} />
                )}
            </div>
            <div className="mt-1 text-[11px] tabular-nums text-muted-foreground">
                {formatQty(cur, item.unit)}{par != null ? ` / par ${par}` : ''}
            </div>
        </div>
    );
}

export default function InventoryTable({ siteId, items, canAdjust, canManageProducts, onOpenAdjust, onOpenStocktake, onAddItem, onManageProducts, onChanged }: Props) {
    const [busyId, setBusyId] = useState<number | null>(null);
    const [cat, setCat] = useState<string>('all');

    const categories = useMemo(() => {
        const set = new Set<string>();
        items.forEach((i) => i.product.category && set.add(i.product.category));
        return ['all', ...Array.from(set).sort()];
    }, [items]);

    const filtered = cat === 'all' ? items : items.filter((i) => i.product.category === cat);

    const itemsTracked = items.length;
    const value = items.reduce((s, i) => s + (i.product.cost_per_unit_cents ? Math.round(toNum(i.current_qty) * i.product.cost_per_unit_cents) : 0), 0);
    const low = items.filter((i) => stockState(i) === 'low').length;
    const out = items.filter((i) => stockState(i) === 'out').length;

    async function quickAdjust(item: InventoryItem, sign: 1 | -1) {
        setBusyId(item.id);
        try {
            await axios.post(`/sites/${siteId}/meal-inventory/adjust`, { product_id: item.product_id, delta: sign, unit: item.unit, reason: 'adjustment', note: sign > 0 ? 'Quick +1' : 'Quick -1' });
            onChanged();
        } catch {
            toast.error('Could not adjust stock');
        } finally {
            setBusyId(null);
        }
    }

    async function destroy(item: InventoryItem) {
        try {
            await axios.delete(`/sites/${siteId}/meal-inventory/items/${item.id}`);
            toast.success('Item removed');
            onChanged();
        } catch {
            toast.error('Could not remove item');
        }
    }

    return (
        <div className="space-y-4">
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Tile icon={Boxes} label="Items tracked" value={String(itemsTracked)} tone="primary" />
                <Tile icon={DollarSign} label="Inventory value" value={money(value)} sub="on hand" tone="success" />
                <Tile icon={TriangleAlert} label="Low stock" value={String(low)} sub="below reorder" tone={low > 0 ? 'warning' : 'success'} />
                <Tile icon={CircleAlert} label="Out of stock" value={String(out)} sub="needs ordering" tone={out > 0 ? 'critical' : 'success'} />
            </div>

            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex flex-wrap items-center gap-1.5">
                    {categories.map((c) => (
                        <button
                            key={c}
                            type="button"
                            onClick={() => setCat(c)}
                            className={cn('rounded-full border px-3 py-1 text-[12px] font-medium capitalize transition-colors', c === cat ? 'border-sites bg-sites-bg text-sites-deep' : 'border-border bg-card text-muted-foreground hover:bg-accent')}
                        >
                            {c === 'all' ? 'All items' : c}
                        </button>
                    ))}
                </div>
                {(canAdjust || canManageProducts) && (
                    <div className="flex gap-2">
                        {canManageProducts && (
                            <Button variant="outline" size="sm" onClick={onManageProducts}><Package className="mr-1.5 h-[15px] w-[15px]" /> Manage products</Button>
                        )}
                        {canAdjust && <Button variant="outline" size="sm" onClick={onOpenStocktake}><ClipboardCheck className="mr-1.5 h-[15px] w-[15px]" /> Stocktake</Button>}
                        {canAdjust && <Button size="sm" onClick={onAddItem}><Plus className="mr-1.5 h-[15px] w-[15px]" /> Add item</Button>}
                    </div>
                )}
            </div>

            <div className="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                <div className="nice-scroll overflow-x-auto">
                    <table className="w-full min-w-[820px] text-sm">
                        <thead className="border-b border-border bg-muted/40 text-[11px] uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th className="px-4 py-2.5 text-left font-semibold">Product</th>
                                <th className="px-4 py-2.5 text-left font-semibold">Stock level</th>
                                <th className="px-4 py-2.5 text-left font-semibold">Location</th>
                                <th className="px-4 py-2.5 text-left font-semibold">Last counted</th>
                                <th className="px-4 py-2.5 text-right font-semibold">Value</th>
                                {canAdjust && <th className="px-4 py-2.5 text-right font-semibold">Adjust</th>}
                            </tr>
                        </thead>
                        <tbody>
                            {filtered.length === 0 && (
                                <tr><td colSpan={canAdjust ? 6 : 5} className="px-4 py-10 text-center text-muted-foreground">No inventory items{cat === 'all' ? ' yet' : ' in this category'}.</td></tr>
                            )}
                            {filtered.map((i) => {
                                const state = stockState(i);
                                const val = i.product.cost_per_unit_cents != null ? Math.round(toNum(i.current_qty) * i.product.cost_per_unit_cents) : null;
                                return (
                                    <tr key={i.id} className="border-b border-border last:border-b-0 hover:bg-accent/30">
                                        <td className="px-4 py-2.5">
                                            <div className="flex items-center gap-2">
                                                <span className="font-medium text-foreground">{i.product.name}</span>
                                                {state === 'out' && <span className="rounded-full bg-status-critical px-1.5 py-px text-[9px] font-bold uppercase text-white">Out</span>}
                                                {state === 'low' && <span className="rounded-full bg-status-warning px-1.5 py-px text-[9px] font-bold uppercase text-white">Low</span>}
                                            </div>
                                            {i.product.category && <div className="text-[11px] capitalize text-muted-foreground">{i.product.category}</div>}
                                        </td>
                                        <td className="px-4 py-2.5"><StockGauge item={i} /></td>
                                        <td className="px-4 py-2.5 text-[12px] text-muted-foreground">{i.location_label ?? '—'}</td>
                                        <td className="px-4 py-2.5 text-[12px] text-muted-foreground">{i.last_counted_at ? new Date(i.last_counted_at).toLocaleDateString('en-NZ') : '—'}</td>
                                        <td className="px-4 py-2.5 text-right tabular-nums text-foreground">{val != null ? money(val, i.product.currency) : '—'}</td>
                                        {canAdjust && (
                                            <td className="px-4 py-2.5">
                                                <div className="flex items-center justify-end gap-1">
                                                    <Button size="icon" variant="outline" className="h-7 w-7" disabled={busyId === i.id} onClick={() => quickAdjust(i, -1)}><Minus className="h-3.5 w-3.5" /></Button>
                                                    <Button size="icon" variant="outline" className="h-7 w-7" disabled={busyId === i.id} onClick={() => quickAdjust(i, 1)}><Plus className="h-3.5 w-3.5" /></Button>
                                                    <Button size="icon" variant="ghost" className="h-7 w-7" onClick={() => onOpenAdjust(i)}><Pencil className="h-3.5 w-3.5" /></Button>
                                                    <ConfirmAction title={`Remove ${i.product.name}?`} description="Removes the product from this site's inventory list. Movement history is kept for audit." confirmLabel="Remove" onConfirm={() => destroy(i)}>
                                                        <Button size="icon" variant="ghost" className="h-7 w-7"><Package className="h-3.5 w-3.5 text-status-critical" /></Button>
                                                    </ConfirmAction>
                                                </div>
                                            </td>
                                        )}
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}
