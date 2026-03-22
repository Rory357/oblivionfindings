import { useState, FormEvent } from 'react';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type BreadcrumbItem } from '@/types';
import { Plus, Trash2 } from 'lucide-react';

type ExpenseItem = {
    description: string;
    category: string;
    amount: string;
    expense_date: string;
    tax_amount: string;
    notes: string;
};

type Props = {
    categories: string[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Expenses', href: '/hr/expenses' },
    { title: 'New Claim', href: '/hr/expenses/create' },
];

const categoryLabels: Record<string, string> = {
    travel: 'Travel',
    meals: 'Meals',
    accommodation: 'Accommodation',
    supplies: 'Supplies',
    mileage: 'Mileage',
    other: 'Other',
};

const emptyItem: ExpenseItem = {
    description: '',
    category: 'other',
    amount: '',
    expense_date: '',
    tax_amount: '',
    notes: '',
};

export default function CreateExpense({ categories }: Props) {
    const [title, setTitle] = useState('');
    const [notes, setNotes] = useState('');
    const [items, setItems] = useState<ExpenseItem[]>([{ ...emptyItem }]);
    const [processing, setProcessing] = useState(false);

    const addItem = () => setItems((prev) => [...prev, { ...emptyItem }]);

    const removeItem = (index: number) => {
        setItems((prev) => prev.filter((_, i) => i !== index));
    };

    const updateItem = (index: number, key: keyof ExpenseItem, value: string) => {
        setItems((prev) => prev.map((item, i) => (i === index ? { ...item, [key]: value } : item)));
    };

    const totalAmount = items.reduce((sum, item) => sum + (parseFloat(item.amount) || 0), 0);

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        setProcessing(true);

        router.post(
            '/hr/expenses',
            {
                title,
                notes: notes || null,
                items: items.map((item) => ({
                    ...item,
                    amount: parseFloat(item.amount) || 0,
                    tax_amount: item.tax_amount ? parseFloat(item.tax_amount) : null,
                    notes: item.notes || null,
                })),
            },
            {
                onFinish: () => setProcessing(false),
            }
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New Expense Claim" />
            <div className="flex flex-col gap-6 p-6">
                <h1 className="text-2xl font-bold">New Expense Claim</h1>

                <form onSubmit={handleSubmit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Claim Details</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2 sm:col-span-2">
                                <Label htmlFor="title">Title</Label>
                                <Input
                                    id="title"
                                    value={title}
                                    onChange={(e) => setTitle(e.target.value)}
                                    placeholder="e.g. March Client Visit Expenses"
                                    required
                                />
                            </div>
                            <div className="space-y-2 sm:col-span-2">
                                <Label htmlFor="notes">Notes (optional)</Label>
                                <Textarea
                                    id="notes"
                                    rows={2}
                                    value={notes}
                                    onChange={(e) => setNotes(e.target.value)}
                                    placeholder="Any additional notes for this claim..."
                                />
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <CardTitle className="text-base">Expense Items</CardTitle>
                                <Button type="button" variant="outline" size="sm" onClick={addItem}>
                                    <Plus className="mr-1.5 h-3.5 w-3.5" />
                                    Add Item
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {items.map((item, index) => (
                                <div key={index} className="rounded-lg border p-4 space-y-3">
                                    <div className="flex items-start justify-between">
                                        <span className="text-sm font-medium text-muted-foreground">Item {index + 1}</span>
                                        {items.length > 1 && (
                                            <Button type="button" variant="ghost" size="sm" onClick={() => removeItem(index)}>
                                                <Trash2 className="h-4 w-4 text-destructive" />
                                            </Button>
                                        )}
                                    </div>
                                    <div className="grid gap-3 sm:grid-cols-3">
                                        <div className="space-y-2 sm:col-span-2">
                                            <Label>Description</Label>
                                            <Input
                                                value={item.description}
                                                onChange={(e) => updateItem(index, 'description', e.target.value)}
                                                placeholder="What was the expense for?"
                                                required
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Category</Label>
                                            <Select value={item.category} onValueChange={(v) => updateItem(index, 'category', v)}>
                                                <SelectTrigger>
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {categories.map((cat) => (
                                                        <SelectItem key={cat} value={cat}>
                                                            {categoryLabels[cat] || cat}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Amount ($)</Label>
                                            <Input
                                                type="number"
                                                step="0.01"
                                                min="0.01"
                                                value={item.amount}
                                                onChange={(e) => updateItem(index, 'amount', e.target.value)}
                                                required
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Date</Label>
                                            <Input
                                                type="date"
                                                value={item.expense_date}
                                                onChange={(e) => updateItem(index, 'expense_date', e.target.value)}
                                                required
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Tax Amount ($)</Label>
                                            <Input
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                value={item.tax_amount}
                                                onChange={(e) => updateItem(index, 'tax_amount', e.target.value)}
                                                placeholder="Optional"
                                            />
                                        </div>
                                    </div>
                                </div>
                            ))}

                            <div className="flex justify-end border-t pt-4">
                                <div className="text-right">
                                    <p className="text-sm text-muted-foreground">Total</p>
                                    <p className="text-xl font-bold">
                                        {new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(totalAmount)}
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex justify-end gap-3">
                        <Button type="button" variant="outline" onClick={() => router.get('/hr/expenses')}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Creating...' : 'Create Claim'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
