import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogFooter,
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import {
    BookOpen,
    CalendarCheck,
    DollarSign,
    Paperclip,
    Plus,
    TrendingDown,
    TrendingUp,
    Upload,
    Wallet,
    X,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

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
    return new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
    }).format(amount);
};

const entryTypeColors: Record<string, string> = {
    income: 'bg-status-success-bg text-status-success border-status-success/30',
    expense:
        'bg-status-critical-bg text-status-critical border-status-critical/30',
    adjustment:
        'bg-status-warning-bg text-status-warning border-status-warning/30',
    transfer: 'bg-status-info-bg text-status-info border-status-info/30',
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

export default function SiteLedger({
    site,
    ledger,
    entries,
    canCreate,
    canManage,
}: Props) {
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
        router.post(
            `/sites/${site.id}/ledger/reconcile`,
            {},
            { preserveScroll: true },
        );
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Sites', href: '/sites' },
                { title: site.name, href: `/sites/${site.id}` },
                { title: 'Ledger', href: `/sites/${site.id}/ledger` },
            ]}
        >
            <Head title={`${site.name} - Ledger`} />

            <div className="m-4 space-y-4">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="flex items-center gap-2 text-lg font-semibold">
                            <BookOpen className="h-5 w-5" />
                            House Ledger
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {site.name}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        {canManage && (
                            <Button
                                variant="secondary"
                                onClick={handleReconcile}
                            >
                                <CalendarCheck className="mr-1 h-4 w-4" />
                                Reconcile
                            </Button>
                        )}
                        {canCreate && (
                            <Button onClick={() => setCreateOpen(true)}>
                                <Plus className="mr-1 h-4 w-4" />
                                Add Entry
                            </Button>
                        )}
                    </div>
                </div>

                {/* Balance Summary Cards */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card className="border-primary/20 bg-primary/5">
                        <CardContent className="p-4">
                            <div className="mb-1 flex items-center gap-2">
                                <Wallet className="h-5 w-5 text-primary" />
                                <span className="text-sm text-muted-foreground">
                                    Current Balance
                                </span>
                            </div>
                            <div className="text-3xl font-bold text-primary">
                                {formatCurrency(ledger.current_balance)}
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="border-status-success/20 bg-status-success">
                        <CardContent className="p-4">
                            <div className="mb-1 flex items-center gap-2">
                                <TrendingUp className="h-4 w-4 text-status-success" />
                                <span className="text-sm text-muted-foreground">
                                    Income
                                </span>
                            </div>
                            <div className="text-2xl font-bold text-status-success">
                                {formatCurrency(incomeTotal)}
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="border-status-critical/20 bg-status-critical">
                        <CardContent className="p-4">
                            <div className="mb-1 flex items-center gap-2">
                                <TrendingDown className="h-4 w-4 text-status-critical" />
                                <span className="text-sm text-muted-foreground">
                                    Expenses
                                </span>
                            </div>
                            <div className="text-2xl font-bold text-status-critical">
                                {formatCurrency(expenseTotal)}
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="mb-1 flex items-center gap-2">
                                <CalendarCheck className="h-4 w-4 text-muted-foreground" />
                                <span className="text-sm text-muted-foreground">
                                    Last Reconciled
                                </span>
                            </div>
                            <div className="text-lg font-semibold">
                                {ledger.last_reconciled_at
                                    ? new Date(
                                          ledger.last_reconciled_at,
                                      ).toLocaleDateString()
                                    : 'Never'}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Entries Table */}
                <Card>
                    <CardContent className="p-0">
                        {entries.data.length === 0 ? (
                            <div className="py-12 text-center text-muted-foreground">
                                <DollarSign className="mx-auto mb-3 h-12 w-12 opacity-50" />
                                <p>No ledger entries yet</p>
                                {canCreate && (
                                    <p className="mt-1 text-sm">
                                        Click "Add Entry" to record your first
                                        transaction
                                    </p>
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
                                        <TableHead className="text-right">
                                            Amount
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Running Balance
                                        </TableHead>
                                        <TableHead>Attachment</TableHead>
                                        <TableHead>Recorded By</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {entries.data.map((entry) => (
                                        <TableRow key={entry.id}>
                                            <TableCell>
                                                {new Date(
                                                    entry.entry_date,
                                                ).toLocaleDateString()}
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    className={
                                                        entryTypeColors[
                                                            entry.entry_type
                                                        ]
                                                    }
                                                >
                                                    {
                                                        entryTypeLabels[
                                                            entry.entry_type
                                                        ]
                                                    }
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                {categoryLabels[
                                                    entry.category
                                                ] || entry.category}
                                            </TableCell>
                                            <TableCell className="max-w-[200px] truncate">
                                                {entry.description}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {entry.reference || '-'}
                                            </TableCell>
                                            <TableCell className="text-right font-medium">
                                                <span
                                                    className={
                                                        entry.entry_type ===
                                                        'income'
                                                            ? 'text-status-success'
                                                            : entry.entry_type ===
                                                                'expense'
                                                              ? 'text-status-critical'
                                                              : ''
                                                    }
                                                >
                                                    {entry.entry_type ===
                                                    'expense'
                                                        ? '-'
                                                        : ''}
                                                    {formatCurrency(
                                                        entry.amount,
                                                    )}
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-right font-medium">
                                                {formatCurrency(
                                                    entry.running_balance,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {entry.attachments &&
                                                entry.attachments.length > 0 ? (
                                                    <a
                                                        href={`/sites/${site.id}/ledger/entries/${entry.id}/attachment`}
                                                        className="inline-flex items-center gap-1 text-sm text-status-info hover:text-status-info"
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                    >
                                                        <Paperclip className="h-3.5 w-3.5" />
                                                        {
                                                            entry.attachments[0]
                                                                .original_name
                                                        }
                                                    </a>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        -
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {entry.recorded_by.name}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                {/* Pagination */}
                {entries.data.length > 0 &&
                    (entries.links.prev || entries.links.next) && (
                        <div className="flex justify-center gap-2">
                            {entries.links.prev && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        router.visit(entries.links.prev!)
                                    }
                                >
                                    Previous
                                </Button>
                            )}
                            {entries.links.next && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        router.visit(entries.links.next!)
                                    }
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
                                        onValueChange={(v) =>
                                            form.setData('entry_type', v)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="income">
                                                Income
                                            </SelectItem>
                                            <SelectItem value="expense">
                                                Expense
                                            </SelectItem>
                                            <SelectItem value="adjustment">
                                                Adjustment
                                            </SelectItem>
                                            <SelectItem value="transfer">
                                                Transfer
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <Label>Category *</Label>
                                    <Select
                                        value={form.data.category}
                                        onValueChange={(v) =>
                                            form.setData('category', v)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="groceries">
                                                Groceries
                                            </SelectItem>
                                            <SelectItem value="utilities">
                                                Utilities
                                            </SelectItem>
                                            <SelectItem value="maintenance">
                                                Maintenance
                                            </SelectItem>
                                            <SelectItem value="petty_cash">
                                                Petty Cash
                                            </SelectItem>
                                            <SelectItem value="funding">
                                                Funding
                                            </SelectItem>
                                            <SelectItem value="other">
                                                Other
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            <div>
                                <Label>Description *</Label>
                                <Input
                                    value={form.data.description}
                                    onChange={(e) =>
                                        form.setData(
                                            'description',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="What was this transaction for?"
                                    required
                                />
                                {form.errors.description && (
                                    <p className="mt-1 text-sm text-status-critical">
                                        {form.errors.description}
                                    </p>
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
                                        onChange={(e) =>
                                            form.setData(
                                                'amount',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="0.00"
                                        required
                                    />
                                    {form.errors.amount && (
                                        <p className="mt-1 text-sm text-status-critical">
                                            {form.errors.amount}
                                        </p>
                                    )}
                                </div>
                                <div>
                                    <Label>Entry Date *</Label>
                                    <Input
                                        type="date"
                                        value={form.data.entry_date}
                                        onChange={(e) =>
                                            form.setData(
                                                'entry_date',
                                                e.target.value,
                                            )
                                        }
                                        required
                                    />
                                </div>
                            </div>
                            <div>
                                <Label>Reference</Label>
                                <Input
                                    value={form.data.reference}
                                    onChange={(e) =>
                                        form.setData(
                                            'reference',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="Invoice #, receipt #, etc."
                                />
                            </div>
                            <div>
                                <Label>Notes</Label>
                                <Textarea
                                    value={form.data.notes}
                                    onChange={(e) =>
                                        form.setData('notes', e.target.value)
                                    }
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
                                    onChange={(e) =>
                                        form.setData(
                                            'attachment',
                                            e.target.files?.[0] ?? null,
                                        )
                                    }
                                />
                                {form.data.attachment ? (
                                    <div className="mt-1 flex items-center gap-2 rounded-md border border-status-info/30 bg-status-info px-3 py-2">
                                        <Paperclip className="h-4 w-4 shrink-0 text-status-info" />
                                        <span className="flex-1 truncate text-sm text-status-info">
                                            {form.data.attachment.name}
                                        </span>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            onClick={() => {
                                                form.setData(
                                                    'attachment',
                                                    null,
                                                );
                                                if (fileInputRef.current)
                                                    fileInputRef.current.value =
                                                        '';
                                            }}
                                            className="h-7 w-7 text-muted-foreground hover:text-status-critical"
                                        >
                                            <X className="h-4 w-4" />
                                        </Button>
                                    </div>
                                ) : (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() =>
                                            fileInputRef.current?.click()
                                        }
                                        className="mt-1 h-auto w-full cursor-pointer gap-2 rounded-md border-2 border-dashed border-border px-3 py-3 text-sm text-muted-foreground hover:border-status-info/50 hover:bg-status-info hover:text-status-info"
                                    >
                                        <Upload className="h-4 w-4" />
                                        Choose file (PDF or image, max 10MB)
                                    </Button>
                                )}
                                {form.errors.attachment && (
                                    <p className="mt-1 text-sm text-status-critical">
                                        {form.errors.attachment}
                                    </p>
                                )}
                            </div>
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setCreateOpen(false)}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                >
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
