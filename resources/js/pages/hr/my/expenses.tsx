import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { MyHrShell, type MyHrShellData } from '@/components/hr';
import { router } from '@inertiajs/react';
import { ChevronDown, Plus, Receipt, Send, Trash2, Undo2 } from 'lucide-react';
import { FormEvent, useState } from 'react';

type ExpenseClaim = {
    id: number;
    claim_number: string;
    title: string;
    status: string;
    total_amount: number;
    currency: string;
    items_count: number;
    submitted_at: string | null;
    created_at: string;
};

type Props = {
    myHr: MyHrShellData;
    claims: {
        data: ExpenseClaim[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    categories: string[];
};

const statusConfig: Record<string, { className: string; label: string }> = {
    draft: {
        className:
            'border-border/30 text-muted-foreground bg-muted-foreground/10',
        label: 'Draft',
    },
    submitted: {
        className:
            'border-status-warning/30 text-status-warning bg-status-warning-bg',
        label: 'Submitted',
    },
    approved: {
        className:
            'border-status-success/30 text-status-success bg-status-success-bg',
        label: 'Approved',
    },
    rejected: {
        className:
            'border-status-critical/30 text-status-critical bg-status-critical-bg',
        label: 'Rejected',
    },
    paid: {
        className: 'border-status-info/30 text-status-info bg-status-info-bg',
        label: 'Paid',
    },
};

const categoryLabels: Record<string, string> = {
    travel: 'Travel',
    meals: 'Meals',
    accommodation: 'Accommodation',
    supplies: 'Supplies',
    mileage: 'Mileage',
    other: 'Other',
};

const formatCurrency = (amount: number, currency = 'NZD') =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency }).format(
        amount,
    );

type ItemForm = {
    description: string;
    category: string;
    amount: string;
    expense_date: string;
};

export default function MyExpenses({ myHr, claims, categories }: Props) {
    const [formOpen, setFormOpen] = useState(false);
    const [title, setTitle] = useState('');
    const [items, setItems] = useState<ItemForm[]>([
        { description: '', category: 'other', amount: '', expense_date: '' },
    ]);
    const [processing, setProcessing] = useState(false);

    const addItem = () =>
        setItems((p) => [
            ...p,
            {
                description: '',
                category: 'other',
                amount: '',
                expense_date: '',
            },
        ]);
    const removeItem = (i: number) =>
        setItems((p) => p.filter((_, idx) => idx !== i));
    const updateItem = (i: number, key: keyof ItemForm, val: string) =>
        setItems((p) =>
            p.map((item, idx) => (idx === i ? { ...item, [key]: val } : item)),
        );

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        setProcessing(true);
        router.post(
            '/hr/my/expenses',
            {
                title,
                items: items.map((item) => ({
                    ...item,
                    amount: parseFloat(item.amount) || 0,
                })),
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setTitle('');
                    setItems([
                        {
                            description: '',
                            category: 'other',
                            amount: '',
                            expense_date: '',
                        },
                    ]);
                    setFormOpen(false);
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <MyHrShell active="expenses" myHr={myHr} title="Expenses · My HR">
                {/* Submit Expense Form */}
                <Collapsible open={formOpen} onOpenChange={setFormOpen}>
                    <Card>
                        <CollapsibleTrigger asChild>
                            <CardHeader className="cursor-pointer">
                                <div className="flex items-center justify-between">
                                    <CardTitle className="flex items-center gap-2">
                                        <Plus className="h-4 w-4" />
                                        New Expense Claim
                                    </CardTitle>
                                    <ChevronDown
                                        className={`h-4 w-4 transition-transform ${formOpen ? 'rotate-180' : ''}`}
                                    />
                                </div>
                            </CardHeader>
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <CardContent>
                                <form
                                    onSubmit={handleSubmit}
                                    className="space-y-4"
                                >
                                    <div className="space-y-2">
                                        <Label>Title</Label>
                                        <Input
                                            value={title}
                                            onChange={(e) =>
                                                setTitle(e.target.value)
                                            }
                                            placeholder="e.g. Client Meeting Expenses"
                                            required
                                        />
                                    </div>

                                    {items.map((item, index) => (
                                        <div
                                            key={index}
                                            className="grid gap-3 rounded-lg border p-3 sm:grid-cols-4"
                                        >
                                            <div className="space-y-1 sm:col-span-2">
                                                <Label className="text-xs">
                                                    Description
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
                                                    placeholder="What was the expense?"
                                                    required
                                                />
                                            </div>
                                            <div className="space-y-1">
                                                <Label className="text-xs">
                                                    Category
                                                </Label>
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
                                                        {categories.map((c) => (
                                                            <SelectItem
                                                                key={c}
                                                                value={c}
                                                            >
                                                                {categoryLabels[
                                                                    c
                                                                ] || c}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                            <div className="flex items-end gap-2">
                                                <div className="flex-1 space-y-1">
                                                    <Label className="text-xs">
                                                        Amount ($)
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
                                                </div>
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
                                            <div className="space-y-1">
                                                <Label className="text-xs">
                                                    Date
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
                                            </div>
                                        </div>
                                    ))}

                                    <div className="flex items-center justify-between">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={addItem}
                                        >
                                            <Plus className="mr-1 h-3 w-3" />
                                            Add Item
                                        </Button>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {processing
                                                ? 'Creating...'
                                                : 'Create Claim'}
                                        </Button>
                                    </div>
                                </form>
                            </CardContent>
                        </CollapsibleContent>
                    </Card>
                </Collapsible>

                {/* Claims Table */}
                <Card>
                    <CardHeader>
                        <CardTitle>My Expense Claims</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Claim #
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Title
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Amount
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Status
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Submitted
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {claims.data.map((claim) => {
                                    const config =
                                        statusConfig[claim.status] ||
                                        statusConfig.draft;
                                    return (
                                        <tr
                                            key={claim.id}
                                            className="hover:bg-muted/30"
                                        >
                                            <td className="px-4 py-3 font-mono text-sm">
                                                {claim.claim_number}
                                            </td>
                                            <td className="px-4 py-3 font-medium">
                                                {claim.title}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                {formatCurrency(
                                                    claim.total_amount,
                                                    claim.currency,
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                <Badge
                                                    variant="outline"
                                                    className={config.className}
                                                >
                                                    {config.label}
                                                </Badge>
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {claim.submitted_at || '-'}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                {(claim.status === 'draft' ||
                                                    claim.status ===
                                                        'rejected') && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            router.post(
                                                                `/hr/my/expenses/${claim.id}/submit`,
                                                                {},
                                                                {
                                                                    preserveScroll:
                                                                        true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        <Send className="mr-1 h-3.5 w-3.5" />
                                                        {claim.status ===
                                                        'rejected'
                                                            ? 'Resubmit'
                                                            : 'Submit'}
                                                    </Button>
                                                )}
                                                {claim.status ===
                                                    'submitted' && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            router.post(
                                                                `/hr/my/expenses/${claim.id}/withdraw`,
                                                                {},
                                                                {
                                                                    preserveScroll:
                                                                        true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        <Undo2 className="mr-1 h-3.5 w-3.5" />
                                                        Withdraw
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                                {claims.data.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="px-6 py-12">
                                            <div className="flex flex-col items-center gap-2 text-center">
                                                <Receipt className="h-8 w-8 text-muted-foreground/40" />
                                                <div className="text-sm font-semibold">
                                                    No expense claims yet
                                                </div>
                                                <p className="max-w-sm text-[13px] text-muted-foreground">
                                                    Claim work costs like
                                                    mileage, meals or supplies —
                                                    approved claims are paid
                                                    with your pay.
                                                </p>
                                                <Button
                                                    size="sm"
                                                    className="mt-1"
                                                    onClick={() =>
                                                        setFormOpen(true)
                                                    }
                                                >
                                                    <Plus className="mr-1 h-3.5 w-3.5" />
                                                    New expense claim
                                                </Button>
                                            </div>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                {/* Pagination */}
                {claims.last_page > 1 && (
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            Showing{' '}
                            {(claims.current_page - 1) * claims.per_page + 1} to{' '}
                            {Math.min(
                                claims.current_page * claims.per_page,
                                claims.total,
                            )}{' '}
                            of {claims.total} results
                        </p>
                        <LaravelPagination links={claims.links} />
                    </div>
                )}
        </MyHrShell>
    );
}
