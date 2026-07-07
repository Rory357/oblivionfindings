import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { PageHero } from '@/components/page';
import { formatMoney, PriceBookDialog } from '@/components/finance';
import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';
import { CalendarDays, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';

type PriceBookItem = {
    id: number;
    service_code: string | null;
    name: string;
    unit: string;
    rate: number;
    rate_type: string;
    category: string | null;
    is_active: boolean;
};

type Props = {
    price_book: {
        id: number;
        name: string;
        description: string | null;
        is_default: boolean;
        is_active: boolean;
        effective_from: string | null;
        effective_to: string | null;
        items: PriceBookItem[];
    };
    canManage: boolean;
};

function formatDate(d: string | null): string {
    if (!d) return '-';
    return new Date(d).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
}

export default function PriceBookShow({ price_book, canManage = false }: Props) {
    const [editOpen, setEditOpen] = useState(false);
    const [showItemForm, setShowItemForm] = useState(false);
    const itemForm = useForm({
        service_code: '',
        name: '',
        unit: 'hour',
        rate: '',
        rate_type: 'fixed',
        category: '',
        is_active: true,
    });

    const handleAddItem = (e: React.FormEvent) => {
        e.preventDefault();
        itemForm.post(`/finance/price-books/${price_book.id}/items`, {
            preserveScroll: true,
            onSuccess: () => {
                itemForm.reset();
                setShowItemForm(false);
            },
        });
    };

    const items = price_book.items ?? [];

    return (
        <AppLayout>
            <Head title={price_book.name} />
            <PageHero category="finance" variant="compact" title={price_book.name} description={price_book.description ?? ''} backHref="/finance/price-books" />
            <PageShell>
                {/* Header info */}
                <div className="flex flex-wrap items-center gap-2">
                    {price_book.is_default && <Badge variant="default">Default</Badge>}
                    {price_book.effective_from && (
                        <span className="flex items-center gap-1 text-xs text-muted-foreground">
                            <CalendarDays className="h-3 w-3" />
                            {formatDate(price_book.effective_from)} — {formatDate(price_book.effective_to)}
                        </span>
                    )}
                    {canManage && (
                        <div className="ml-auto flex gap-1">
                            <Button size="sm" variant="outline" onClick={() => setEditOpen(true)}>
                                <Pencil className="mr-1.5 h-3.5 w-3.5" /> Edit
                            </Button>
                        </div>
                    )}
                </div>

                {/* Items table */}
                <div className="mt-6">
                    <div className="mb-3 flex items-center justify-between">
                        <h3 className="text-sm font-semibold">Items ({items.length})</h3>
                        <Button size="sm" variant="outline" onClick={() => setShowItemForm(!showItemForm)}>
                            <Plus className="mr-1.5 h-3.5 w-3.5" /> Add Item
                        </Button>
                    </div>

                    {/* Add Item Form */}
                    {showItemForm && (
                        <Card className="mb-4 border-dashed border-primary bg-primary/10 dark:border-primary/30 dark:bg-primary/20">
                            <CardContent className="p-4">
                                <form onSubmit={handleAddItem} className="space-y-3">
                                    <div className="grid gap-3 sm:grid-cols-3">
                                        <div className="space-y-1">
                                            <Label className="text-xs">Service Code</Label>
                                            <Input
                                                value={itemForm.data.service_code}
                                                onChange={(e) => itemForm.setData('service_code', e.target.value)}
                                                placeholder="e.g. SVC-001"
                                                className="h-8 text-sm"
                                            />
                                        </div>
                                        <div className="space-y-1">
                                            <Label className="text-xs">Name *</Label>
                                            <Input
                                                value={itemForm.data.name}
                                                onChange={(e) => itemForm.setData('name', e.target.value)}
                                                placeholder="e.g. Personal Care"
                                                className="h-8 text-sm"
                                            />
                                        </div>
                                        <div className="space-y-1">
                                            <Label className="text-xs">Category</Label>
                                            <Input
                                                value={itemForm.data.category}
                                                onChange={(e) => itemForm.setData('category', e.target.value)}
                                                placeholder="e.g. Core Supports"
                                                className="h-8 text-sm"
                                            />
                                        </div>
                                    </div>
                                    <div className="grid gap-3 sm:grid-cols-3">
                                        <div className="space-y-1">
                                            <Label className="text-xs">Unit</Label>
                                            <Select value={itemForm.data.unit} onValueChange={(v) => itemForm.setData('unit', v)}>
                                                <SelectTrigger className="h-8 text-xs"><SelectValue /></SelectTrigger>
                                                <SelectContent>
                                                    {['hour', 'day', 'each', 'km', 'week'].map((u) => (
                                                        <SelectItem key={u} value={u} className="capitalize">{u}</SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="space-y-1">
                                            <Label className="text-xs">Rate (NZD) *</Label>
                                            <Input
                                                type="number"
                                                step="0.01"
                                                value={itemForm.data.rate}
                                                onChange={(e) => itemForm.setData('rate', e.target.value)}
                                                placeholder="0.00"
                                                className="h-8 text-sm"
                                            />
                                        </div>
                                        <div className="space-y-1">
                                            <Label className="text-xs">Rate Type</Label>
                                            <Select value={itemForm.data.rate_type} onValueChange={(v) => itemForm.setData('rate_type', v)}>
                                                <SelectTrigger className="h-8 text-xs"><SelectValue /></SelectTrigger>
                                                <SelectContent>
                                                    {['fixed', 'variable', 'tiered'].map((t) => (
                                                        <SelectItem key={t} value={t} className="capitalize">{t}</SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                    </div>
                                    <div className="flex gap-2">
                                        <Button type="submit" size="sm" disabled={itemForm.processing}>Add Item</Button>
                                        <Button type="button" size="sm" variant="ghost" onClick={() => setShowItemForm(false)}>Cancel</Button>
                                    </div>
                                </form>
                            </CardContent>
                        </Card>
                    )}

                    {/* Items List */}
                    <Card>
                        <CardContent className="p-0">
                            {items.length === 0 ? (
                                <p className="py-8 text-center text-sm text-muted-foreground">No items added yet. Add items to define service rates.</p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b text-left text-[10px] font-medium uppercase tracking-wider text-muted-foreground">
                                                <th className="px-4 py-2">Service Code</th>
                                                <th className="px-4 py-2">Name</th>
                                                <th className="px-4 py-2">Unit</th>
                                                <th className="px-4 py-2 text-right">Rate (NZD)</th>
                                                <th className="px-4 py-2">Rate Type</th>
                                                <th className="px-4 py-2">Category</th>
                                                <th className="px-4 py-2 text-center">Active</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {items.map((item) => (
                                                <tr key={item.id} className="border-b last:border-0">
                                                    <td className="px-4 py-2 text-xs text-muted-foreground">{item.service_code ?? '-'}</td>
                                                    <td className="px-4 py-2 text-xs font-medium">{item.name}</td>
                                                    <td className="px-4 py-2 text-xs capitalize text-muted-foreground">{item.unit}</td>
                                                    <td className="px-4 py-2 text-right text-xs tabular-nums">{formatMoney(item.rate)}</td>
                                                    <td className="px-4 py-2 text-xs capitalize text-muted-foreground">{item.rate_type}</td>
                                                    <td className="px-4 py-2 text-xs text-muted-foreground">{item.category ?? '-'}</td>
                                                    <td className="px-4 py-2 text-center">
                                                        <Badge variant={item.is_active ? 'default' : 'outline'} className="text-[10px]">
                                                            {item.is_active ? 'Active' : 'Inactive'}
                                                        </Badge>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </PageShell>

            {/* Mounted only while open so each edit starts from fresh props. */}
            {canManage && editOpen && (
                <PriceBookDialog
                    open
                    onClose={() => setEditOpen(false)}
                    priceBook={{
                        id: price_book.id,
                        name: price_book.name,
                        description: price_book.description,
                        effective_from: price_book.effective_from,
                        effective_to: price_book.effective_to,
                        is_active: price_book.is_active,
                    }}
                />
            )}
        </AppLayout>
    );
}
