import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import { formatCurrency, formatDate } from '@/lib/fleet-utils';
import { Link, router, useForm } from '@inertiajs/react';
import {
    BarChart3,
    CalendarCheck,
    Download,
    FileText,
    Loader2,
    Paperclip,
    Plus,
    RefreshCw,
    Upload,
    Wallet,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

type Site = {
    id: number;
    name: string;
    type: string;
};

type Ledger = {
    id: number;
    opening_balance: number;
    current_balance: number;
    currency: string;
    last_reconciled_at: string | null;
    reconciled_by?: number | null;
};

type Attachment = {
    path: string;
    disk?: string;
    original_name?: string;
    mime_type?: string;
    size?: number;
};

type Entry = {
    id: number;
    entry_type: 'income' | 'expense' | 'adjustment' | 'transfer';
    category: string;
    description: string;
    reference?: string | null;
    amount: number;
    running_balance: number;
    entry_date: string;
    recorded_by?: { id: number; name: string } | null;
    approved_by?: { id: number; name: string } | null;
    approved_at?: string | null;
    approval_state: 'approved' | 'pending';
    notes?: string | null;
    attachments?: Attachment[];
};

type PaginatedEntries = {
    data: Entry[];
    links: {
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from?: number | null;
        to?: number | null;
    };
};

export type SiteLedgerPanelData = {
    ledger: Ledger;
    entries: PaginatedEntries;
    filters?: {
        from?: string | null;
        to?: string | null;
    };
    canCreate: boolean;
    canManage: boolean;
};

type Props = {
    site: Site;
    ledgerData?: SiteLedgerPanelData | null;
};

const emptyEntries: PaginatedEntries = {
    data: [],
    links: { prev: null, next: null },
    meta: {
        current_page: 1,
        last_page: 1,
        per_page: 10,
        total: 0,
        from: null,
        to: null,
    },
};

const entryTypeLabels: Record<Entry['entry_type'], string> = {
    income: 'Income',
    expense: 'Expense',
    adjustment: 'Adjustment',
    transfer: 'Transfer',
};

const entryTypeClasses: Record<Entry['entry_type'], string> = {
    income: 'border-status-success/30 bg-status-success-bg text-status-success',
    expense:
        'border-status-critical/30 bg-status-critical-bg text-status-critical',
    adjustment:
        'border-status-warning/30 bg-status-warning-bg text-status-warning',
    transfer: 'border-status-info/30 bg-status-info-bg text-status-info',
};

const categoryOptions = [
    ['groceries', 'Groceries'],
    ['utilities', 'Utilities'],
    ['maintenance', 'Maintenance'],
    ['petty_cash', 'Petty Cash'],
    ['funding', 'Funding'],
    ['rent', 'Rent / Lease'],
    ['transport', 'Transport'],
    ['other', 'Other'],
] as const;

const categoryLabels = Object.fromEntries(categoryOptions);

const today = () => new Date().toISOString().slice(0, 10);

const signedAmount = (entry: Entry) => {
    if (entry.entry_type === 'expense') {
        return -Math.abs(Number(entry.amount));
    }

    return Number(entry.amount);
};

export default function SiteLedgerPanel({ site, ledgerData }: Props) {
    const [ledger, setLedger] = useState<Ledger | null>(
        ledgerData?.ledger ?? null,
    );
    const [entries, setEntries] = useState<PaginatedEntries>(
        ledgerData?.entries ?? emptyEntries,
    );
    const [from, setFrom] = useState(ledgerData?.filters?.from ?? '');
    const [to, setTo] = useState(ledgerData?.filters?.to ?? '');
    const [loading, setLoading] = useState(false);
    const [createOpen, setCreateOpen] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const form = useForm<{
        entry_type: Entry['entry_type'];
        category: string;
        description: string;
        reference: string;
        amount: string;
        entry_date: string;
        notes: string;
        attachment: File | null;
    }>({
        entry_type: 'expense',
        category: 'groceries',
        description: '',
        reference: '',
        amount: '',
        entry_date: today(),
        notes: '',
        attachment: null,
    });

    useEffect(() => {
        if (ledgerData?.ledger) {
            setLedger(ledgerData.ledger);
        }
    }, [ledgerData?.ledger]);

    const loadLedger = async (
        href?: string | null,
        nextFrom = from,
        nextTo = to,
    ) => {
        if (!ledgerData) return;

        const url = new URL(
            href ?? `/sites/${site.id}/ledger`,
            window.location.origin,
        );

        if (!href) {
            if (nextFrom) url.searchParams.set('from', nextFrom);
            if (nextTo) url.searchParams.set('to', nextTo);
            url.searchParams.set('per_page', '10');
        }

        setLoading(true);
        try {
            const response = await fetch(url.toString(), {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                throw new Error('Unable to load ledger entries.');
            }

            const data = await response.json();
            setLedger(data.ledger);
            setEntries(data.entries);
            setFrom(data.filters?.from ?? nextFrom ?? '');
            setTo(data.filters?.to ?? nextTo ?? '');
        } catch (error) {
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'Unable to load ledger entries.',
            );
        } finally {
            setLoading(false);
        }
    };

    const handleCreate = (event: React.FormEvent) => {
        event.preventDefault();

        form.post(`/sites/${site.id}/ledger/entries`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setCreateOpen(false);
                form.reset();
                form.setData('entry_date', today());
                if (fileInputRef.current) fileInputRef.current.value = '';
                void loadLedger();
            },
            onError: () => toast.error('Please check the entry details.'),
        });
    };

    const handleReconcile = () => {
        router.post(
            `/sites/${site.id}/ledger/reconcile`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => toast.success('Ledger reconciled.'),
                onError: () => toast.error('Unable to reconcile ledger.'),
            },
        );
    };

    const clearFilters = () => {
        setFrom('');
        setTo('');
        void loadLedger(null, '', '');
    };

    if (!ledgerData || !ledger) {
        return (
            <div className="space-y-4">
                <PanelHeader />
                <Card>
                    <CardContent className="flex flex-col items-center justify-center gap-3 py-12 text-center">
                        <Wallet className="h-10 w-10 text-muted-foreground/50" />
                        <div>
                            <p className="font-medium">No house ledger</p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                House ledger entries are available for house
                                and residential sites.
                            </p>
                        </div>
                    </CardContent>
                </Card>
            </div>
        );
    }

    return (
        <div className="space-y-4">
            <PanelHeader
                canCreate={ledgerData.canCreate}
                onCreate={() => setCreateOpen(true)}
            />

            <div className="grid gap-4 lg:grid-cols-3">
                <Card>
                    <CardContent className="p-4">
                        <div className="flex items-center gap-2 text-sm text-muted-foreground">
                            <Wallet className="h-4 w-4" />
                            Current Balance
                        </div>
                        <div className="mt-3 text-2xl font-semibold tabular-nums">
                            {formatCurrency(
                                ledger.current_balance,
                                ledger.currency,
                            )}
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="flex h-full flex-col gap-3 p-4">
                        <div className="flex items-center gap-2 text-sm text-muted-foreground">
                            <CalendarCheck className="h-4 w-4" />
                            Last Reconciled
                        </div>
                        <div className="flex flex-1 items-end justify-between gap-3">
                            <div className="text-lg font-semibold">
                                {ledger.last_reconciled_at
                                    ? formatDate(ledger.last_reconciled_at)
                                    : 'Never'}
                            </div>
                            {ledgerData.canManage && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={handleReconcile}
                                    disabled={loading}
                                >
                                    <RefreshCw className="h-4 w-4" />
                                    Reconcile
                                </Button>
                            )}
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="space-y-3 p-4">
                        <div className="flex items-center gap-2 text-sm text-muted-foreground">
                            <FileText className="h-4 w-4" />
                            Period Filter
                        </div>
                        <div className="grid gap-2 sm:grid-cols-[1fr_1fr_auto]">
                            <Input
                                type="date"
                                value={from}
                                onChange={(event) =>
                                    setFrom(event.target.value)
                                }
                                aria-label="Ledger filter from date"
                            />
                            <Input
                                type="date"
                                value={to}
                                onChange={(event) => setTo(event.target.value)}
                                aria-label="Ledger filter to date"
                            />
                            <div className="flex gap-2">
                                <Button
                                    type="button"
                                    size="sm"
                                    onClick={() => void loadLedger()}
                                    disabled={loading}
                                >
                                    {loading ? (
                                        <Loader2 className="h-4 w-4 animate-spin" />
                                    ) : (
                                        <RefreshCw className="h-4 w-4" />
                                    )}
                                    Apply
                                </Button>
                                {(from || to) && (
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="ghost"
                                        onClick={clearFilters}
                                        disabled={loading}
                                        aria-label="Clear ledger filters"
                                    >
                                        <X className="h-4 w-4" />
                                    </Button>
                                )}
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader className="flex flex-row items-center justify-between space-y-0">
                    <CardTitle className="text-base">Ledger Entries</CardTitle>
                    <div className="text-sm text-muted-foreground">
                        {entries.meta.total > 0
                            ? `${entries.meta.from ?? 1}-${entries.meta.to ?? entries.data.length} of ${entries.meta.total}`
                            : 'No entries'}
                    </div>
                </CardHeader>
                <CardContent className="p-0">
                    {entries.data.length === 0 ? (
                        <div className="flex flex-col items-center justify-center gap-3 py-12 text-center text-muted-foreground">
                            <FileText className="h-10 w-10 opacity-50" />
                            <p>No ledger entries for this period</p>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Date</TableHead>
                                        <TableHead>Type</TableHead>
                                        <TableHead>Category</TableHead>
                                        <TableHead>Description</TableHead>
                                        <TableHead className="text-right">
                                            Amount
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Running Balance
                                        </TableHead>
                                        <TableHead>Recorded By</TableHead>
                                        <TableHead>Attachment</TableHead>
                                        <TableHead>Approval</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {entries.data.map((entry) => {
                                        const amount = signedAmount(entry);

                                        return (
                                            <TableRow key={entry.id}>
                                                <TableCell className="whitespace-nowrap">
                                                    {formatDate(
                                                        entry.entry_date,
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge
                                                        variant="outline"
                                                        className={
                                                            entryTypeClasses[
                                                                entry
                                                                    .entry_type
                                                            ]
                                                        }
                                                    >
                                                        {
                                                            entryTypeLabels[
                                                                entry
                                                                    .entry_type
                                                            ]
                                                        }
                                                    </Badge>
                                                </TableCell>
                                                <TableCell className="whitespace-nowrap">
                                                    {categoryLabels[
                                                        entry.category
                                                    ] ?? entry.category}
                                                </TableCell>
                                                <TableCell className="min-w-56">
                                                    <div className="font-medium">
                                                        {entry.description}
                                                    </div>
                                                    {entry.reference && (
                                                        <div className="text-xs text-muted-foreground">
                                                            {entry.reference}
                                                        </div>
                                                    )}
                                                </TableCell>
                                                <TableCell
                                                    className={`text-right font-medium tabular-nums ${
                                                        amount < 0
                                                            ? 'text-status-critical'
                                                            : 'text-status-success'
                                                    }`}
                                                >
                                                    {amount < 0 ? '-' : '+'}
                                                    {formatCurrency(
                                                        Math.abs(amount),
                                                        ledger.currency,
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {formatCurrency(
                                                        entry.running_balance,
                                                        ledger.currency,
                                                    )}
                                                </TableCell>
                                                <TableCell className="whitespace-nowrap">
                                                    {entry.recorded_by?.name ??
                                                        '-'}
                                                </TableCell>
                                                <TableCell>
                                                    {entry.attachments?.length ? (
                                                        <Button
                                                            asChild
                                                            size="sm"
                                                            variant="ghost"
                                                        >
                                                            <a
                                                                href={`/sites/${site.id}/ledger/entries/${entry.id}/download`}
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                            >
                                                                <Download className="h-4 w-4" />
                                                                <span className="max-w-32 truncate">
                                                                    {entry
                                                                        .attachments[0]
                                                                        .original_name ??
                                                                        'Attachment'}
                                                                </span>
                                                            </a>
                                                        </Button>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            -
                                                        </span>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge
                                                        variant="outline"
                                                        className={
                                                            entry.approval_state ===
                                                            'approved'
                                                                ? 'border-status-success/30 bg-status-success-bg text-status-success'
                                                                : 'border-muted bg-muted/40 text-muted-foreground'
                                                        }
                                                    >
                                                        {entry.approval_state ===
                                                        'approved'
                                                            ? 'Approved'
                                                            : 'Pending'}
                                                    </Badge>
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </CardContent>
            </Card>

            {(entries.links.prev || entries.links.next) && (
                <div className="flex justify-end gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={!entries.links.prev || loading}
                        onClick={() => void loadLedger(entries.links.prev)}
                    >
                        Previous
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={!entries.links.next || loading}
                        onClick={() => void loadLedger(entries.links.next)}
                    >
                        Next
                    </Button>
                </div>
            )}

            <Dialog open={createOpen} onOpenChange={setCreateOpen}>
                <DialogContent className="sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>Add Ledger Entry</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={handleCreate} className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label>Entry Type</Label>
                                <Select
                                    value={form.data.entry_type || undefined}
                                    onValueChange={(value) =>
                                        form.setData(
                                            'entry_type',
                                            value as Entry['entry_type'],
                                        )
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
                            <div className="space-y-1.5">
                                <Label>Category</Label>
                                <Select
                                    value={form.data.category || undefined}
                                    onValueChange={(value) =>
                                        form.setData('category', value)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {categoryOptions.map(
                                            ([value, label]) => (
                                                <SelectItem
                                                    key={value}
                                                    value={value}
                                                >
                                                    {label}
                                                </SelectItem>
                                            ),
                                        )}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="space-y-1.5">
                            <Label>Description</Label>
                            <Input
                                value={form.data.description}
                                onChange={(event) =>
                                    form.setData(
                                        'description',
                                        event.target.value,
                                    )
                                }
                                required
                            />
                            {form.errors.description && (
                                <p className="text-sm text-status-critical">
                                    {form.errors.description}
                                </p>
                            )}
                        </div>

                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="space-y-1.5">
                                <Label>Amount</Label>
                                <Input
                                    type="number"
                                    min="0.01"
                                    step="0.01"
                                    value={form.data.amount}
                                    onChange={(event) =>
                                        form.setData(
                                            'amount',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                                {form.errors.amount && (
                                    <p className="text-sm text-status-critical">
                                        {form.errors.amount}
                                    </p>
                                )}
                            </div>
                            <div className="space-y-1.5">
                                <Label>Entry Date</Label>
                                <Input
                                    type="date"
                                    value={form.data.entry_date}
                                    onChange={(event) =>
                                        form.setData(
                                            'entry_date',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label>Reference</Label>
                                <Input
                                    value={form.data.reference}
                                    onChange={(event) =>
                                        form.setData(
                                            'reference',
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>
                        </div>

                        <div className="space-y-1.5">
                            <Label>Notes</Label>
                            <Textarea
                                value={form.data.notes}
                                onChange={(event) =>
                                    form.setData('notes', event.target.value)
                                }
                                rows={3}
                            />
                        </div>

                        <div className="space-y-1.5">
                            <Label>Attachment</Label>
                            <input
                                ref={fileInputRef}
                                type="file"
                                accept=".pdf,.jpg,.jpeg,.png,.gif,.webp"
                                className="hidden"
                                onChange={(event) =>
                                    form.setData(
                                        'attachment',
                                        event.target.files?.[0] ?? null,
                                    )
                                }
                            />
                            {form.data.attachment ? (
                                <div className="flex items-center gap-2 rounded-md border px-3 py-2 text-sm">
                                    <Paperclip className="h-4 w-4 text-muted-foreground" />
                                    <span className="min-w-0 flex-1 truncate">
                                        {form.data.attachment.name}
                                    </span>
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="ghost"
                                        onClick={() => {
                                            form.setData('attachment', null);
                                            if (fileInputRef.current) {
                                                fileInputRef.current.value = '';
                                            }
                                        }}
                                        aria-label="Remove attachment"
                                    >
                                        <X className="h-4 w-4" />
                                    </Button>
                                </div>
                            ) : (
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="w-full justify-center border-dashed"
                                    onClick={() => fileInputRef.current?.click()}
                                >
                                    <Upload className="h-4 w-4" />
                                    Upload receipt or invoice
                                </Button>
                            )}
                            {form.errors.attachment && (
                                <p className="text-sm text-status-critical">
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
                            <Button type="submit" disabled={form.processing}>
                                {form.processing && (
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                )}
                                Add Entry
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    );
}

function PanelHeader({
    canCreate = false,
    onCreate,
}: {
    canCreate?: boolean;
    onCreate?: () => void;
}) {
    return (
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 className="text-lg font-semibold">House Ledger</h2>
                <p className="text-sm text-muted-foreground">
                    Balance, reconciliation, and operating entries.
                </p>
            </div>
            <div className="flex flex-wrap gap-2">
                <Button asChild variant="outline">
                    <Link href="/finance/sites">
                        <BarChart3 className="h-4 w-4" />
                        Open Financial Dashboard
                    </Link>
                </Button>
                {canCreate && (
                    <Button type="button" onClick={onCreate}>
                        <Plus className="h-4 w-4" />
                        Add Entry
                    </Button>
                )}
            </div>
        </div>
    );
}
