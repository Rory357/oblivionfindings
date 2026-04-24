import { useState, FormEvent } from 'react';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { type BreadcrumbItem } from '@/types';
import { ChevronDown, Plus, Trash2 } from 'lucide-react';
import { LaravelPagination } from '@/components/ui/laravel-pagination';

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

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr/my' },
    { title: 'My HR', href: '/hr/my' },
    { title: 'My Expenses', href: '/hr/my/expenses' },
];

const statusConfig: Record<string, { className: string; label: string }> = {
    draft: { className: 'border-slate-500/30 text-muted-foreground bg-slate-500/10', label: 'Draft' },
    submitted: { className: 'border-yellow-500/30 text-yellow-400 bg-yellow-500/10', label: 'Submitted' },
    approved: { className: 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10', label: 'Approved' },
    rejected: { className: 'border-red-500/30 text-red-400 bg-red-500/10', label: 'Rejected' },
    paid: { className: 'border-blue-500/30 text-blue-400 bg-blue-500/10', label: 'Paid' },
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
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency }).format(amount);

type ItemForm = { description: string; category: string; amount: string; expense_date: string };

export default function MyExpenses({ claims, categories }: Props) {
    const [formOpen, setFormOpen] = useState(false);
    const [title, setTitle] = useState('');
    const [items, setItems] = useState<ItemForm[]>([{ description: '', category: 'other', amount: '', expense_date: '' }]);
    const [processing, setProcessing] = useState(false);

    const addItem = () => setItems((p) => [...p, { description: '', category: 'other', amount: '', expense_date: '' }]);
    const removeItem = (i: number) => setItems((p) => p.filter((_, idx) => idx !== i));
    const updateItem = (i: number, key: keyof ItemForm, val: string) =>
        setItems((p) => p.map((item, idx) => (idx === i ? { ...item, [key]: val } : item)));

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
                    setItems([{ description: '', category: 'other', amount: '', expense_date: '' }]);
                    setFormOpen(false);
                },
                onFinish: () => setProcessing(false),
            }
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My Expenses" />
            <div className="flex flex-col gap-6 p-6">
                <h1 className="text-2xl font-bold">My Expenses</h1>

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
                                    <ChevronDown className={`h-4 w-4 transition-transform ${formOpen ? 'rotate-180' : ''}`} />
                                </div>
                            </CardHeader>
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <CardContent>
                                <form onSubmit={handleSubmit} className="space-y-4">
                                    <div className="space-y-2">
                                        <Label>Title</Label>
                                        <Input
                                            value={title}
                                            onChange={(e) => setTitle(e.target.value)}
                                            placeholder="e.g. Client Meeting Expenses"
                                            required
                                        />
                                    </div>

                                    {items.map((item, index) => (
                                        <div key={index} className="grid gap-3 rounded-lg border p-3 sm:grid-cols-4">
                                            <div className="space-y-1 sm:col-span-2">
                                                <Label className="text-xs">Description</Label>
                                                <Input
                                                    value={item.description}
                                                    onChange={(e) => updateItem(index, 'description', e.target.value)}
                                                    placeholder="What was the expense?"
                                                    required
                                                />
                                            </div>
                                            <div className="space-y-1">
                                                <Label className="text-xs">Category</Label>
                                                <Select value={item.category} onValueChange={(v) => updateItem(index, 'category', v)}>
                                                    <SelectTrigger>
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {categories.map((c) => (
                                                            <SelectItem key={c} value={c}>
                                                                {categoryLabels[c] || c}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                            <div className="flex items-end gap-2">
                                                <div className="flex-1 space-y-1">
                                                    <Label className="text-xs">Amount ($)</Label>
                                                    <Input
                                                        type="number"
                                                        step="0.01"
                                                        min="0.01"
                                                        value={item.amount}
                                                        onChange={(e) => updateItem(index, 'amount', e.target.value)}
                                                        required
                                                    />
                                                </div>
                                                {items.length > 1 && (
                                                    <Button type="button" variant="ghost" size="sm" onClick={() => removeItem(index)}>
                                                        <Trash2 className="h-4 w-4 text-destructive" />
                                                    </Button>
                                                )}
                                            </div>
                                            <div className="space-y-1">
                                                <Label className="text-xs">Date</Label>
                                                <Input
                                                    type="date"
                                                    value={item.expense_date}
                                                    onChange={(e) => updateItem(index, 'expense_date', e.target.value)}
                                                    required
                                                />
                                            </div>
                                        </div>
                                    ))}

                                    <div className="flex items-center justify-between">
                                        <Button type="button" variant="ghost" size="sm" onClick={addItem}>
                                            <Plus className="mr-1 h-3 w-3" />
                                            Add Item
                                        </Button>
                                        <Button type="submit" disabled={processing}>
                                            {processing ? 'Creating...' : 'Create Claim'}
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
                                    <th className="px-4 py-3 text-left font-medium">Claim #</th>
                                    <th className="px-4 py-3 text-left font-medium">Title</th>
                                    <th className="px-4 py-3 text-right font-medium">Amount</th>
                                    <th className="px-4 py-3 text-left font-medium">Status</th>
                                    <th className="px-4 py-3 text-left font-medium">Submitted</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {claims.data.map((claim) => {
                                    const config = statusConfig[claim.status] || statusConfig.draft;
                                    return (
                                        <tr key={claim.id} className="hover:bg-muted/30">
                                            <td className="px-4 py-3 font-mono text-sm">{claim.claim_number}</td>
                                            <td className="px-4 py-3 font-medium">{claim.title}</td>
                                            <td className="px-4 py-3 text-right">
                                                {formatCurrency(claim.total_amount, claim.currency)}
                                            </td>
                                            <td className="px-4 py-3">
                                                <Badge variant="outline" className={config.className}>
                                                    {config.label}
                                                </Badge>
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {claim.submitted_at || '-'}
                                            </td>
                                        </tr>
                                    );
                                })}
                                {claims.data.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="px-4 py-8 text-center text-muted-foreground">
                                            No expense claims found.
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
                            Showing {(claims.current_page - 1) * claims.per_page + 1} to{' '}
                            {Math.min(claims.current_page * claims.per_page, claims.total)} of {claims.total} results
                        </p>
                        <LaravelPagination links={claims.links} />
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
