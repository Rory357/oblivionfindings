import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm, router } from '@inertiajs/react';
import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
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
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { ArrowLeftRight, Plus, Send, Clock, DollarSign } from 'lucide-react';
import { FormEvent, useState } from 'react';

type Entity = {
    id: number;
    entity_name: string;
    is_active: boolean;
};

type Transaction = {
    id: number;
    from_entity_id: number;
    from_entity_name: string;
    to_entity_id: number;
    to_entity_name: string;
    transaction_date: string;
    description: string;
    amount: string;
    status: 'pending' | 'posted' | 'eliminated';
    created_by: string | null;
    created_at: string;
};

type Group = {
    id: number;
    name: string;
};

type PageProps = {
    group: Group;
    transactions: Transaction[];
    entities: Entity[];
};

const statusColors: Record<string, string> = {
    pending: 'bg-status-warning-bg text-status-warning border-status-warning/30',
    posted: 'bg-status-success-bg text-status-success border-status-success/30',
    eliminated: 'bg-status-info-bg text-status-info border-status-info/30',
};

const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

function CreateTransactionDialog({ groupId, entities }: { groupId: number; entities: Entity[] }) {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        from_entity_id: '',
        to_entity_id: '',
        transaction_date: '',
        description: '',
        amount: '',
    });

    const activeEntities = entities.filter((e) => e.is_active);

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        post(`/finance/intercompany/${groupId}`, {
            onSuccess: () => {
                reset();
                setOpen(false);
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button>
                    <Plus className="mr-2 h-4 w-4" />
                    New Transaction
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Create Intercompany Transaction</DialogTitle>
                    <DialogDescription>Record a transaction between two entities in the group.</DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="ict-from">From Entity *</Label>
                            <Select
                                value={data.from_entity_id}
                                onValueChange={(val) => setData('from_entity_id', val)}
                            >
                                <SelectTrigger id="ict-from">
                                    <SelectValue placeholder="Select entity" />
                                </SelectTrigger>
                                <SelectContent>
                                    {activeEntities.map((entity) => (
                                        <SelectItem key={entity.id} value={String(entity.id)}>
                                            {entity.entity_name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {errors.from_entity_id && <p className="text-sm text-destructive">{errors.from_entity_id}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="ict-to">To Entity *</Label>
                            <Select
                                value={data.to_entity_id}
                                onValueChange={(val) => setData('to_entity_id', val)}
                            >
                                <SelectTrigger id="ict-to">
                                    <SelectValue placeholder="Select entity" />
                                </SelectTrigger>
                                <SelectContent>
                                    {activeEntities
                                        .filter((e) => String(e.id) !== data.from_entity_id)
                                        .map((entity) => (
                                            <SelectItem key={entity.id} value={String(entity.id)}>
                                                {entity.entity_name}
                                            </SelectItem>
                                        ))}
                                </SelectContent>
                            </Select>
                            {errors.to_entity_id && <p className="text-sm text-destructive">{errors.to_entity_id}</p>}
                        </div>
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="ict-date">Transaction Date *</Label>
                            <Input
                                id="ict-date"
                                type="date"
                                value={data.transaction_date}
                                onChange={(e) => setData('transaction_date', e.target.value)}
                            />
                            {errors.transaction_date && <p className="text-sm text-destructive">{errors.transaction_date}</p>}
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="ict-amount">Amount *</Label>
                            <Input
                                id="ict-amount"
                                type="number"
                                min="0.01"
                                step="0.01"
                                value={data.amount}
                                onChange={(e) => setData('amount', e.target.value)}
                                placeholder="0.00"
                            />
                            {errors.amount && <p className="text-sm text-destructive">{errors.amount}</p>}
                        </div>
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="ict-description">Description *</Label>
                        <Input
                            id="ict-description"
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            placeholder="e.g. Management fee for Q1 2026"
                        />
                        {errors.description && <p className="text-sm text-destructive">{errors.description}</p>}
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Creating...' : 'Create Transaction'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function IntercompanyIndex({ group, transactions, entities }: PageProps) {
    const [postingId, setPostingId] = useState<number | null>(null);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Finance', href: '/finance/dashboard' },
        { title: 'Consolidation', href: '/finance/consolidation' },
        { title: group.name, href: `/finance/consolidation/${group.id}` },
        { title: 'Intercompany', href: `/finance/intercompany/${group.id}` },
    ];

    const pendingTransactions = transactions.filter((t) => t.status === 'pending');
    const pendingTotal = pendingTransactions.reduce((sum, t) => sum + Number(t.amount), 0);

    function handlePost(transactionId: number) {
        setPostingId(transactionId);
        router.post(`/finance/intercompany/${group.id}/${transactionId}/post`, {}, {
            onFinish: () => setPostingId(null),
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Intercompany - ${group.name}`} />

            <PageLayout
                hero={
                    <PageHero category="finance"
                        icon={ArrowLeftRight}
                        title="Intercompany Transactions"
                        description={`Manage transactions between entities in ${group.name}`}
                        stats={[
                            { label: 'Total', value: transactions.length },
                            { label: 'Pending', value: pendingTransactions.length },
                            { label: 'Pending amount', value: formatCurrency(pendingTotal) },
                        ]}
                        actions={<CreateTransactionDialog groupId={group.id} entities={entities} />}
                    />
                }
            >
                {/* KPI Cards */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <Card>
                        <CardContent className="flex items-center gap-4 pt-6">
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-status-warning">
                                <Clock className="h-5 w-5 text-status-warning" />
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">Pending Transactions</p>
                                <p className="text-2xl font-bold">{pendingTransactions.length}</p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-4 pt-6">
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10">
                                <DollarSign className="h-5 w-5 text-primary" />
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">Pending Amount</p>
                                <p className="text-2xl font-bold font-mono tabular-nums">{formatCurrency(pendingTotal)}</p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <ArrowLeftRight className="h-5 w-5 text-muted-foreground" />
                            <CardTitle>All Transactions</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Date</TableHead>
                                    <TableHead>From</TableHead>
                                    <TableHead>To</TableHead>
                                    <TableHead>Description</TableHead>
                                    <TableHead className="text-right">Amount</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {transactions.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={7} className="text-center text-muted-foreground py-8">
                                            No intercompany transactions yet. Create your first transaction.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    transactions.map((txn) => (
                                        <TableRow key={txn.id}>
                                            <TableCell className="text-sm">{txn.transaction_date}</TableCell>
                                            <TableCell className="font-medium">{txn.from_entity_name}</TableCell>
                                            <TableCell className="font-medium">{txn.to_entity_name}</TableCell>
                                            <TableCell className="text-sm text-muted-foreground max-w-[200px] truncate">
                                                {txn.description}
                                            </TableCell>
                                            <TableCell className="text-right font-mono tabular-nums">
                                                {formatCurrency(Number(txn.amount))}
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant="outline" className={statusColors[txn.status]}>
                                                    {txn.status.charAt(0).toUpperCase() + txn.status.slice(1)}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {txn.status === 'pending' && (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => handlePost(txn.id)}
                                                        disabled={postingId === txn.id}
                                                    >
                                                        <Send className="mr-1 h-3 w-3" />
                                                        {postingId === txn.id ? 'Posting...' : 'Post'}
                                                    </Button>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
