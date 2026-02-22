import AppLayout from '@/layouts/app-layout';
import { Head, useForm, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogFooter,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { BookOpen, Plus, DollarSign, TrendingUp, TrendingDown, CalendarCheck, Wallet, Paperclip, Upload, X } from 'lucide-react';
import { useState, useMemo, useRef } from 'react';

type Site = {
    id: number;
    name: string;
    type: string;
    display_type: string;
};

type Ledger = {
    id: number;
    opening_balance: number;
    current_balance: number;
    currency: string;
    last_reconciled_at: string | null;
};

type Attachment = {
    path: string;
    disk: string;
    original_name: string;
    mime_type: string;
    size: number;
};

type Entry = {
    id: number;
    entry_type: 'income' | 'expense' | 'adjustment' | 'transfer';
    category: string;
    description: string;
    reference?: string;
    amount: number;
    running_balance: number;
    entry_date: string;
    recorded_by: { id: number; name: string };
    approved_by: { id: number; name: string } | null;
    notes?: string;
    attachments?: Attachment[];
};

type PaginatedEntries = {
    data: Entry[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta?: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
};

type Props = {
    site: Site;
    ledger: Ledger;
    entries: PaginatedEntries;
    canCreate: boolean;
    canManage: boolean;
};

const formatCurrency = (amount: number | undefined | null) => {
    if (amount == null) return '-';
    return new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);
};

const entryTypeColors: Record<string, string> = {
    income: 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30',
    expense: 'bg-red-500/20 text-red-300 border-red-500/30',
    adjustment: 'bg-yellow-500/20 text-yellow-300 border-yellow-500/30',
    transfer: 'bg-blue-500/20 text-blue-300 border-blue-500/30',
};

const entryTypeLabels: Record<string, string> = {
    income: 'Income',
    expense: 'Expense',
    adjustment: 'Adjustment',
    transfer: 'Transfer',
};

const categoryLabels: Record<string, string> = {
    groceries: 'Groceries',
    utilities: 'Utilities',
    maintenance: 'Maintenance',
    petty_cash: 'Petty Cash',
    funding: 'Funding',
    other: 'Other',
};

export default function SiteLedger({ site, ledger, entries, canCreate, canManage }: Props) {
    const [createOpen, setCreateOpen] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const form = useForm<{
        entry_type: string;
        category: string;
        description: string;
        amount: string;
        entry_date: string;
        reference: string;
        notes: string;
        attachment: File | null;
    }>({
        entry_type: 'expense',
        category: 'groceries',
        description: '',
        amount: '',
        entry_date: '',
        reference: '',
        notes: '',
        attachment: null,
    });

    const incomeTotal = useMemo(() => {
        return entries.data
            .filter((e) => e.entry_type === 'income')
            .reduce((sum, e) => sum + e.amount, 0);
    }, [entries.data]);

    const expenseTotal = useMemo(() => {
        return entries.data
            .filter((e) => e.entry_type === 'expense')
            .reduce((sum, e) => sum + e.amount, 0);
    }, [entries.data]);

    const handleCreate = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/sites/${site.id}/ledger`, {
            forceFormData: true,
            onSuccess: () => {
                setCreateOpen(false);
                form.reset();
            },
        });
    };

    const handleReconcile = () => {
        router.post(`/sites/${site.id}/ledger/reconcile`, {}, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={[
            { title: 'Sites', href: '/sites' },
            { title: site.name, href: `/sites/${site.id}` },
            { title: 'Ledger', href: `/sites/${site.id}/ledger` },
        ]}>
            <Head title={`${site.name} - Ledger`} />

            <div className="m-4 space-y-4">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-lg font-semibold flex items-center gap-2">
                            <BookOpen className="w-5 h-5" />
                            House Ledger
                        </h1>
                        <p className="text-sm text-slate-400">{site.name}</p>
                    </div>
                    <div className="flex gap-2">
                        {canManage && (
                            <Button variant="secondary" onClick={handleReconcile}>
                                <CalendarCheck className="w-4 h-4 mr-1" />
                                Reconcile
                            </Button>
                        )}
                        {canCreate && (
                            <Button onClick={() => setCreateOpen(true)}>
                                <Plus className="w-4 h-4 mr-1" />
                                Add Entry
                            </Button>
                        )}
                    </div>
                </div>

                {/* Balance Summary Cards */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card className="bg-indigo-500/5 border-indigo-500/20">
                        <CardContent className="p-4">
                            <div className="flex items-center gap-2 mb-1">
                                <Wallet className="w-5 h-5 text-indigo-400" />
                                <span className="text-sm text-slate-400">Current Balance</span>
                            </div>
                            <div className="text-3xl font-bold text-indigo-400">
                                {formatCurrency(ledger.current_balance)}
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="bg-emerald-500/5 border-emerald-500/20">
                        <CardContent className="p-4">
                            <div className="flex items-center gap-2 mb-1">
                                <TrendingUp className="w-4 h-4 text-emerald-400" />
                                <span className="text-sm text-slate-400">Income</span>
                            </div>
                            <div className="text-2xl font-bold text-emerald-400">
                                {formatCurrency(incomeTotal)}
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="bg-red-500/5 border-red-500/20">
                        <CardContent className="p-4">
                            <div className="flex items-center gap-2 mb-1">
                                <TrendingDown className="w-4 h-4 text-red-400" />
                                <span className="text-sm text-slate-400">Expenses</span>
                            </div>
                            <div className="text-2xl font-bold text-red-400">
                                {formatCurrency(expenseTotal)}
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="flex items-center gap-2 mb-1">
                                <CalendarCheck className="w-4 h-4 text-slate-400" />
                                <span className="text-sm text-slate-400">Last Reconciled</span>
                            </div>
                            <div className="text-lg font-semibold">
                                {ledger.last_reconciled_at
                                    ? new Date(ledger.last_reconciled_at).toLocaleDateString()
                                    : 'Never'}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Entries Table */}
                <Card>
                    <CardContent className="p-0">
                        {entries.data.length === 0 ? (
                            <div className="text-center py-12 text-slate-400">
                                <DollarSign className="w-12 h-12 mx-auto mb-3 opacity-50" />
                                <p>No ledger entries yet</p>
                                {canCreate && (
                                    <p className="text-sm mt-1">Click "Add Entry" to record your first transaction</p>
                                )}
                            </div>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Date</TableHead>
                                        <TableHead>Type</TableHead>
                                        <TableHead>Category</TableHead>
                                        <TableHead>Description</TableHead>
                                        <TableHead>Reference</TableHead>
                                        <TableHead className="text-right">Amount</TableHead>
                                        <TableHead className="text-right">Running Balance</TableHead>
                                        <TableHead>Attachment</TableHead>
                                        <TableHead>Recorded By</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {entries.data.map((entry) => (
                                        <TableRow key={entry.id}>
                                            <TableCell>
                                                {new Date(entry.entry_date).toLocaleDateString()}
                                            </TableCell>
                                            <TableCell>
                                                <Badge className={entryTypeColors[entry.entry_type]}>
                                                    {entryTypeLabels[entry.entry_type]}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                {categoryLabels[entry.category] || entry.category}
                                            </TableCell>
                                            <TableCell className="max-w-[200px] truncate">
                                                {entry.description}
                                            </TableCell>
                                            <TableCell className="text-slate-400">
                                                {entry.reference || '-'}
                                            </TableCell>
                                            <TableCell className="text-right font-medium">
                                                <span className={
                                                    entry.entry_type === 'income'
                                                        ? 'text-emerald-400'
                                                        : entry.entry_type === 'expense'
                                                            ? 'text-red-400'
                                                            : ''
                                                }>
                                                    {entry.entry_type === 'expense' ? '-' : ''}
                                                    {formatCurrency(entry.amount)}
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-right font-medium">
                                                {formatCurrency(entry.running_balance)}
                                            </TableCell>
                                            <TableCell>
                                                {entry.attachments && entry.attachments.length > 0 ? (
                                                    <a
                                                        href={`/sites/${site.id}/ledger/entries/${entry.id}/attachment`}
                                                        className="inline-flex items-center gap-1 text-sm text-blue-400 hover:text-blue-300"
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                    >
                                                        <Paperclip className="w-3.5 h-3.5" />
                                                        {entry.attachments[0].original_name}
                                                    </a>
                                                ) : (
                                                    <span className="text-slate-500">-</span>
                                                )}
                                            </TableCell>
                                            <TableCell>{entry.recorded_by.name}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                {/* Pagination */}
                {entries.data.length > 0 && (entries.links.prev || entries.links.next) && (
                    <div className="flex justify-center gap-2">
                        {entries.links.prev && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => router.visit(entries.links.prev!)}
                            >
                                Previous
                            </Button>
                        )}
                        {entries.links.next && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => router.visit(entries.links.next!)}
                            >
                                Next
                            </Button>
                        )}
                    </div>
                )}

                {/* Add Entry Dialog */}
                <Dialog open={createOpen} onOpenChange={setCreateOpen}>
                    <DialogContent className="sm:max-w-lg">
                        <DialogHeader>
                            <DialogTitle>Add Ledger Entry</DialogTitle>
                        </DialogHeader>
                        <form onSubmit={handleCreate} className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>Entry Type *</Label>
                                    <Select
                                        value={form.data.entry_type}
                                        onValueChange={(v) => form.setData('entry_type', v)}
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="income">Income</SelectItem>
                                            <SelectItem value="expense">Expense</SelectItem>
                                            <SelectItem value="adjustment">Adjustment</SelectItem>
                                            <SelectItem value="transfer">Transfer</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <Label>Category *</Label>
                                    <Select
                                        value={form.data.category}
                                        onValueChange={(v) => form.setData('category', v)}
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="groceries">Groceries</SelectItem>
                                            <SelectItem value="utilities">Utilities</SelectItem>
                                            <SelectItem value="maintenance">Maintenance</SelectItem>
                                            <SelectItem value="petty_cash">Petty Cash</SelectItem>
                                            <SelectItem value="funding">Funding</SelectItem>
                                            <SelectItem value="other">Other</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            <div>
                                <Label>Description *</Label>
                                <Input
                                    value={form.data.description}
                                    onChange={(e) => form.setData('description', e.target.value)}
                                    placeholder="What was this transaction for?"
                                    required
                                />
                                {form.errors.description && (
                                    <p className="text-sm text-red-400 mt-1">{form.errors.description}</p>
                                )}
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>Amount *</Label>
                                    <Input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        value={form.data.amount}
                                        onChange={(e) => form.setData('amount', e.target.value)}
                                        placeholder="0.00"
                                        required
                                    />
                                    {form.errors.amount && (
                                        <p className="text-sm text-red-400 mt-1">{form.errors.amount}</p>
                                    )}
                                </div>
                                <div>
                                    <Label>Entry Date *</Label>
                                    <Input
                                        type="date"
                                        value={form.data.entry_date}
                                        onChange={(e) => form.setData('entry_date', e.target.value)}
                                        required
                                    />
                                </div>
                            </div>
                            <div>
                                <Label>Reference</Label>
                                <Input
                                    value={form.data.reference}
                                    onChange={(e) => form.setData('reference', e.target.value)}
                                    placeholder="Invoice #, receipt #, etc."
                                />
                            </div>
                            <div>
                                <Label>Notes</Label>
                                <Textarea
                                    value={form.data.notes}
                                    onChange={(e) => form.setData('notes', e.target.value)}
                                    rows={2}
                                    placeholder="Optional notes..."
                                />
                            </div>
                            <div>
                                <Label>Receipt / Attachment</Label>
                                <input
                                    ref={fileInputRef}
                                    type="file"
                                    accept=".pdf,.jpg,.jpeg,.png,.gif,.webp"
                                    className="hidden"
                                    onChange={(e) => form.setData('attachment', e.target.files?.[0] ?? null)}
                                />
                                {form.data.attachment ? (
                                    <div className="flex items-center gap-2 rounded-md border border-blue-500/30 bg-blue-500/5 px-3 py-2 mt-1">
                                        <Paperclip className="w-4 h-4 text-blue-400 shrink-0" />
                                        <span className="text-sm text-blue-300 truncate flex-1">{form.data.attachment.name}</span>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                form.setData('attachment', null);
                                                if (fileInputRef.current) fileInputRef.current.value = '';
                                            }}
                                            className="text-slate-400 hover:text-red-400 transition-colors"
                                        >
                                            <X className="w-4 h-4" />
                                        </button>
                                    </div>
                                ) : (
                                    <button
                                        type="button"
                                        onClick={() => fileInputRef.current?.click()}
                                        className="w-full mt-1 flex items-center justify-center gap-2 rounded-md border-2 border-dashed border-slate-600 px-3 py-3 text-sm text-slate-400 transition-colors hover:border-blue-500/50 hover:text-blue-400 hover:bg-blue-500/5 cursor-pointer"
                                    >
                                        <Upload className="w-4 h-4" />
                                        Choose file (PDF or image, max 10MB)
                                    </button>
                                )}
                                {form.errors.attachment && (
                                    <p className="text-sm text-red-400 mt-1">{form.errors.attachment}</p>
                                )}
                            </div>
                            <DialogFooter>
                                <Button type="button" variant="outline" onClick={() => setCreateOpen(false)}>
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={form.processing}>
                                    Add Entry
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
