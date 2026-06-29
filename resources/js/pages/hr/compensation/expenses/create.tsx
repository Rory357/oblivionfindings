import { PageHero, PageLayout } from '@/components/page';
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
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { FormEvent, useState } from 'react';

type ExpenseItem = {
    description: string;
    category: string;
    amount: string;
    expense_date: string;
    tax_amount: string;
    notes: string;
    receipt: File | null;
    source_type?: string | null;
    source_id?: number | null;
};

type Prefill = {
    description?: string;
    category?: string;
    amount?: string | number | null;
    source_type?: string | null;
    source_id?: number | null;
} | null;

type Props = {
    categories: string[];
    prefill?: Prefill;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Expenses', href: '/hr/compensation/expenses' },
    { title: 'New Claim', href: '/hr/compensation/expenses/create' },
];

const categoryLabels: Record<string, string> = {
    travel: 'Travel',
    meals: 'Meals',
    accommodation: 'Accommodation',
    supplies: 'Supplies',
    mileage: 'Mileage',
    development: 'Development',
    other: 'Other',
};

const emptyItem: ExpenseItem = {
    description: '',
    category: 'other',
    amount: '',
    expense_date: '',
    tax_amount: '',
    notes: '',
    receipt: null,
    source_type: null,
    source_id: null,
};

export default function CreateExpense({ categories, prefill }: Props) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [title, setTitle] = useState(prefill?.description ? `Development — ${prefill.description}` : '');
    const [notes, setNotes] = useState('');
    const [items, setItems] = useState<ExpenseItem[]>([
        prefill
            ? {
                  ...emptyItem,
                  description: prefill.description ?? '',
                  category: prefill.category ?? 'development',
                  amount: prefill.amount != null ? String(prefill.amount) : '',
                  source_type: prefill.source_type ?? null,
                  source_id: prefill.source_id ?? null,
              }
            : { ...emptyItem },
    ]);
    const [processing, setProcessing] = useState(false);

    const addItem = () => setItems((prev) => [...prev, { ...emptyItem }]);

    const removeItem = (index: number) => {
        setItems((prev) => prev.filter((_, i) => i !== index));
    };

    const updateItem = (
        index: number,
        key: 'description' | 'category' | 'amount' | 'expense_date' | 'tax_amount' | 'notes',
        value: string,
    ) => {
        setItems((prev) =>
            prev.map((item, i) =>
                i === index ? { ...item, [key]: value } : item,
            ),
        );
    };

    const updateItemReceipt = (index: number, file: File | null) => {
        setItems((prev) =>
            prev.map((item, i) =>
                i === index ? { ...item, receipt: file } : item,
            ),
        );
    };

    const totalAmount = items.reduce(
        (sum, item) => sum + (parseFloat(item.amount) || 0),
        0,
    );

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        setProcessing(true);

        router.post(
            '/hr/compensation/expenses',
            {
                title,
                notes: notes || null,
                items: items.map((item) => ({
                    ...item,
                    amount: parseFloat(item.amount) || 0,
                    tax_amount: item.tax_amount
                        ? parseFloat(item.tax_amount)
                        : null,
                    notes: item.notes || null,
                    receipt: item.receipt,
                })),
            },
            {
                // A receipt File on any item requires a multipart submission.
                forceFormData: true,
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New Expense Claim" />
            <PageLayout
                hero={
                    <PageHero category="hr"
                        variant="compact"
                        backHref="/hr/compensation/expenses"
                        title="New Expense Claim"
                    />
                }
            >
                <form onSubmit={handleSubmit} className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Claim Details
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2 sm:col-span-2">
                                <Label htmlFor="title">
                                    Title{' '}
                                    <span className="text-status-critical">
                                        *
                                    </span>
                                </Label>
                                <Input
                                    id="title"
                                    value={title}
                                    onChange={(e) => setTitle(e.target.value)}
                                    placeholder="e.g. March Client Visit Expenses"
                                    required
                                />
                                {errors.title && (
                                    <p className="text-sm text-status-critical">
                                        {errors.title}
                                    </p>
                                )}
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
                                {errors.notes && (
                                    <p className="text-sm text-status-critical">
                                        {errors.notes}
                                    </p>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <CardTitle className="text-base">
                                    Expense Items
                                </CardTitle>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={addItem}
                                >
                                    <Plus className="mr-1.5 h-3.5 w-3.5" />
                                    Add Item
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {items.map((item, index) => (
                                <div
                                    key={index}
                                    className="space-y-3 rounded-lg border p-4"
                                >
                                    <div className="flex items-start justify-between">
                                        <span className="text-sm font-medium text-muted-foreground">
                                            Item {index + 1}
                                        </span>
                                        {items.length > 1 && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    removeItem(index)
                                                }
                                            >
                                                <Trash2 className="h-4 w-4 text-destructive" />
                                            </Button>
                                        )}
                                    </div>
                                    <div className="grid gap-3 sm:grid-cols-3">
                                        <div className="space-y-2 sm:col-span-2">
                                            <Label>
                                                Description{' '}
                                                <span className="text-status-critical">
                                                    *
                                                </span>
                                            </Label>
                                            <Input
                                                value={item.description}
                                                onChange={(e) =>
                                                    updateItem(
                                                        index,
                                                        'description',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="What was the expense for?"
                                                required
                                            />
                                            {errors[
                                                `items.${index}.description`
                                            ] && (
                                                <p className="text-sm text-status-critical">
                                                    {
                                                        errors[
                                                            `items.${index}.description`
                                                        ]
                                                    }
                                                </p>
                                            )}
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Category</Label>
                                            <Select
                                                value={item.category}
                                                onValueChange={(v) =>
                                                    updateItem(
                                                        index,
                                                        'category',
                                                        v,
                                                    )
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {categories.map((cat) => (
                                                        <SelectItem
                                                            key={cat}
                                                            value={cat}
                                                        >
                                                            {categoryLabels[
                                                                cat
                                                            ] || cat}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            {errors[
                                                `items.${index}.category`
                                            ] && (
                                                <p className="text-sm text-status-critical">
                                                    {
                                                        errors[
                                                            `items.${index}.category`
                                                        ]
                                                    }
                                                </p>
                                            )}
                                        </div>
                                        <div className="space-y-2">
                                            <Label>
                                                Amount ($){' '}
                                                <span className="text-status-critical">
                                                    *
                                                </span>
                                            </Label>
                                            <Input
                                                type="number"
                                                step="0.01"
                                                min="0.01"
                                                value={item.amount}
                                                onChange={(e) =>
                                                    updateItem(
                                                        index,
                                                        'amount',
                                                        e.target.value,
                                                    )
                                                }
                                                required
                                            />
                                            {errors[
                                                `items.${index}.amount`
                                            ] && (
                                                <p className="text-sm text-status-critical">
                                                    {
                                                        errors[
                                                            `items.${index}.amount`
                                                        ]
                                                    }
                                                </p>
                                            )}
                                        </div>
                                        <div className="space-y-2">
                                            <Label>
                                                Date{' '}
                                                <span className="text-status-critical">
                                                    *
                                                </span>
                                            </Label>
                                            <Input
                                                type="date"
                                                value={item.expense_date}
                                                onChange={(e) =>
                                                    updateItem(
                                                        index,
                                                        'expense_date',
                                                        e.target.value,
                                                    )
                                                }
                                                required
                                            />
                                            {errors[
                                                `items.${index}.expense_date`
                                            ] && (
                                                <p className="text-sm text-status-critical">
                                                    {
                                                        errors[
                                                            `items.${index}.expense_date`
                                                        ]
                                                    }
                                                </p>
                                            )}
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Tax Amount ($)</Label>
                                            <Input
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                value={item.tax_amount}
                                                onChange={(e) =>
                                                    updateItem(
                                                        index,
                                                        'tax_amount',
                                                        e.target.value,
                                                    )
                                                }
                                                placeholder="Optional"
                                            />
                                            {errors[
                                                `items.${index}.tax_amount`
                                            ] && (
                                                <p className="text-sm text-status-critical">
                                                    {
                                                        errors[
                                                            `items.${index}.tax_amount`
                                                        ]
                                                    }
                                                </p>
                                            )}
                                        </div>
                                        <div className="space-y-2 sm:col-span-3">
                                            <Label>Receipt (optional)</Label>
                                            <Input
                                                type="file"
                                                accept=".pdf,.jpg,.jpeg,.png"
                                                onChange={(e) =>
                                                    updateItemReceipt(
                                                        index,
                                                        e.target.files?.[0] ??
                                                            null,
                                                    )
                                                }
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                PDF or image, up to 5MB.
                                            </p>
                                            {errors[
                                                `items.${index}.receipt`
                                            ] && (
                                                <p className="text-sm text-status-critical">
                                                    {
                                                        errors[
                                                            `items.${index}.receipt`
                                                        ]
                                                    }
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            ))}

                            <div className="flex justify-end border-t pt-4">
                                <div className="text-right">
                                    <p className="text-sm text-muted-foreground">
                                        Total
                                    </p>
                                    <p className="text-xl font-bold">
                                        {new Intl.NumberFormat('en-NZ', {
                                            style: 'currency',
                                            currency: 'NZD',
                                        }).format(totalAmount)}
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <div className="flex justify-end gap-3">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => router.get('/hr/compensation/expenses')}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Creating...' : 'Create Claim'}
                        </Button>
                    </div>
                </form>
            </PageLayout>
        </AppLayout>
    );
}
