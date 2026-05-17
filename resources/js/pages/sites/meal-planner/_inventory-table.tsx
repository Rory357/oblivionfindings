import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { router } from '@inertiajs/react';
import { Minus, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ConfirmAction } from '../_confirm-action';
import { formatMoneyFromCents, formatQty, type InventoryItem } from './_helpers';

type Props = {
    siteId: number;
    items: InventoryItem[];
    canAdjust: boolean;
    onOpenAdjust: (item: InventoryItem | null) => void;
    onOpenStocktake: () => void;
    onEditItem: (item: InventoryItem) => void;
    onChanged: () => void;
};

export default function InventoryTable({ siteId, items, canAdjust, onOpenAdjust, onOpenStocktake, onEditItem, onChanged }: Props) {
    const [busyId, setBusyId] = useState<number | null>(null);

    function quickAdjust(item: InventoryItem, sign: 1 | -1) {
        setBusyId(item.id);
        router.post(`/sites/${siteId}/meal-inventory/adjust`, {
            product_id: item.product_id,
            delta: sign,
            unit: item.unit,
            reason: 'adjustment',
            note: sign > 0 ? 'Quick +1' : 'Quick -1',
        }, {
            preserveScroll: true,
            onSuccess: () => onChanged(),
            onFinish: () => setBusyId(null),
        });
    }

    function destroy(item: InventoryItem) {
        router.delete(`/sites/${siteId}/meal-inventory/items/${item.id}`, {
            preserveScroll: true,
            onSuccess: () => onChanged(),
        });
    }

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between gap-2">
                <h2 className="text-lg font-medium">Inventory</h2>
                {canAdjust && (
                    <div className="flex gap-2">
                        <Button variant="outline" onClick={onOpenStocktake}>Stocktake</Button>
                        <Button onClick={() => onOpenAdjust(null)}>+ Add item</Button>
                    </div>
                )}
            </div>
            <div className="rounded-md border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Product</TableHead>
                            <TableHead>Category</TableHead>
                            <TableHead>On hand</TableHead>
                            <TableHead>Par / reorder</TableHead>
                            <TableHead>Last counted</TableHead>
                            <TableHead>Value</TableHead>
                            <TableHead className="w-40">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {items.length === 0 && (
                            <TableRow><TableCell colSpan={7} className="text-center text-muted-foreground">No inventory items yet.</TableCell></TableRow>
                        )}
                        {items.map((i) => {
                            const current = typeof i.current_qty === 'string' ? parseFloat(i.current_qty) : i.current_qty;
                            const reorder = i.reorder_level !== null ? (typeof i.reorder_level === 'string' ? parseFloat(i.reorder_level) : i.reorder_level) : null;
                            const par = i.par_level !== null ? (typeof i.par_level === 'string' ? parseFloat(i.par_level) : i.par_level) : null;
                            const low = reorder !== null && current <= reorder;
                            const value = i.product.cost_per_unit_cents !== null ? current * i.product.cost_per_unit_cents : null;
                            return (
                                <TableRow key={i.id} className={low ? 'bg-red-50/50' : ''}>
                                    <TableCell className="font-medium">
                                        {i.product.name}
                                        {low && <Badge variant="outline" className="ml-2 border-red-300 bg-red-100 text-red-800">Low stock</Badge>}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">{i.product.category ?? '—'}</TableCell>
                                    <TableCell>{formatQty(current, i.unit)}</TableCell>
                                    <TableCell className="text-xs text-muted-foreground">
                                        {par !== null ? `par ${par}` : '—'}
                                        {reorder !== null ? ` / reorder ${reorder}` : ''}
                                    </TableCell>
                                    <TableCell className="text-xs text-muted-foreground">{i.last_counted_at ? new Date(i.last_counted_at).toLocaleDateString('en-NZ') : '—'}</TableCell>
                                    <TableCell>{value !== null ? formatMoneyFromCents(value, i.product.currency) : '—'}</TableCell>
                                    <TableCell>
                                        {canAdjust && (
                                            <div className="flex gap-1">
                                                <Button size="icon" variant="ghost" disabled={busyId === i.id} onClick={() => quickAdjust(i, -1)}><Minus className="h-4 w-4" /></Button>
                                                <Button size="icon" variant="ghost" disabled={busyId === i.id} onClick={() => quickAdjust(i, 1)}><Plus className="h-4 w-4" /></Button>
                                                <Button size="icon" variant="ghost" onClick={() => onEditItem(i)}><Pencil className="h-4 w-4" /></Button>
                                                <ConfirmAction
                                                    title={`Remove ${i.product.name}?`}
                                                    description="This removes the product from this site's inventory list. Movement history is kept for audit."
                                                    confirmLabel="Remove"
                                                    onConfirm={() => destroy(i)}
                                                >
                                                    <Button size="icon" variant="ghost"><Trash2 className="h-4 w-4 text-destructive" /></Button>
                                                </ConfirmAction>
                                            </div>
                                        )}
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
            </div>
        </div>
    );
}
