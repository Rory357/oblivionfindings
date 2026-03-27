import { Head, Link, router, useForm } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Plus, RefreshCw, Trash2, FileText, Radio, AlertCircle } from 'lucide-react';
import { type BreadcrumbItem } from '@/types';
import { useState } from 'react';

interface BankAccount {
    id: number;
    name: string;
    bank_name: string;
}

interface BankFeed {
    id: number;
    bank_account_id: number;
    bank_account_name: string;
    bank_name: string;
    provider: string;
    is_active: boolean;
    last_sync_at: string | null;
    last_sync_status: 'success' | 'failed' | 'pending' | null;
    last_error: string | null;
    consent_expires_at: string | null;
    sync_from_date: string | null;
    logs_count: number;
    created_by_name: string | null;
    created_at: string;
}

interface Props {
    feeds: BankFeed[];
    bankAccounts: BankAccount[];
    existingAccountIds: number[];
}

const providerLabels: Record<string, string> = {
    asb: 'ASB',
    anz: 'ANZ',
    westpac: 'Westpac',
    bnz: 'BNZ',
};

const statusVariant = (status: string | null): 'default' | 'destructive' | 'secondary' | 'outline' => {
    switch (status) {
        case 'success':
            return 'default';
        case 'failed':
            return 'destructive';
        case 'pending':
            return 'secondary';
        default:
            return 'outline';
    }
};

const statusLabel = (status: string | null): string => {
    switch (status) {
        case 'success':
            return 'Success';
        case 'failed':
            return 'Failed';
        case 'pending':
            return 'Pending';
        default:
            return 'Never synced';
    }
};

export default function BankFeedsIndex({ feeds, bankAccounts, existingAccountIds }: Props) {
    const [showAddDialog, setShowAddDialog] = useState(false);
    const [syncing, setSyncing] = useState<number | null>(null);
    const [syncingAll, setSyncingAll] = useState(false);

    const form = useForm({
        bank_account_id: '',
        provider: '',
        sync_from_date: '',
    });

    const availableAccounts = bankAccounts.filter(
        (account) => !existingAccountIds.includes(account.id),
    );

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/finance/bank-feeds', {
            onSuccess: () => {
                setShowAddDialog(false);
                form.reset();
            },
        });
    };

    const handleSync = (feedId: number) => {
        setSyncing(feedId);
        router.post(`/finance/bank-feeds/${feedId}/sync`, {}, {
            onFinish: () => setSyncing(null),
        });
    };

    const handleSyncAll = () => {
        setSyncingAll(true);
        router.post('/finance/bank-feeds/sync-all', {}, {
            onFinish: () => setSyncingAll(false),
        });
    };

    const handleDelete = (feedId: number) => {
        if (!confirm('Are you sure you want to disconnect this bank feed?')) return;
        router.delete(`/finance/bank-feeds/${feedId}`);
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Finance', href: '/finance' },
        { title: 'Bank Feeds', href: '/finance/bank-feeds' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Bank Feeds" />

            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="flex items-center justify-between mb-6">
                    <div>
                        <h1 className="text-3xl font-bold text-foreground">Bank Feeds</h1>
                        <p className="text-muted-foreground mt-1">
                            Automated bank transaction imports from NZ banks
                        </p>
                    </div>
                    <div className="flex gap-2">
                        {feeds.length > 0 && (
                            <Button
                                variant="outline"
                                onClick={handleSyncAll}
                                disabled={syncingAll}
                            >
                                <RefreshCw className={`w-4 h-4 mr-2 ${syncingAll ? 'animate-spin' : ''}`} />
                                Sync All
                            </Button>
                        )}
                        <Dialog open={showAddDialog} onOpenChange={setShowAddDialog}>
                            <DialogTrigger asChild>
                                <Button disabled={availableAccounts.length === 0}>
                                    <Plus className="w-4 h-4 mr-2" />
                                    Add Bank Feed
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <form onSubmit={handleSubmit}>
                                    <DialogHeader>
                                        <DialogTitle>Connect Bank Feed</DialogTitle>
                                        <DialogDescription>
                                            Set up an automated bank feed connection for a bank account.
                                        </DialogDescription>
                                    </DialogHeader>
                                    <div className="space-y-4 py-4">
                                        <div className="space-y-2">
                                            <Label htmlFor="bank_account_id">Bank Account</Label>
                                            <Select
                                                value={form.data.bank_account_id}
                                                onValueChange={(value) => form.setData('bank_account_id', value)}
                                            >
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Select a bank account" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {availableAccounts.map((account) => (
                                                        <SelectItem key={account.id} value={String(account.id)}>
                                                            {account.name} ({account.bank_name})
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            {form.errors.bank_account_id && (
                                                <p className="text-sm text-destructive">{form.errors.bank_account_id}</p>
                                            )}
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="provider">Bank Provider</Label>
                                            <Select
                                                value={form.data.provider}
                                                onValueChange={(value) => form.setData('provider', value)}
                                            >
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Select provider" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="asb">ASB</SelectItem>
                                                    <SelectItem value="anz">ANZ</SelectItem>
                                                    <SelectItem value="westpac">Westpac</SelectItem>
                                                    <SelectItem value="bnz">BNZ</SelectItem>
                                                </SelectContent>
                                            </Select>
                                            {form.errors.provider && (
                                                <p className="text-sm text-destructive">{form.errors.provider}</p>
                                            )}
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="sync_from_date">Sync From Date (optional)</Label>
                                            <Input
                                                type="date"
                                                id="sync_from_date"
                                                value={form.data.sync_from_date}
                                                onChange={(e) => form.setData('sync_from_date', e.target.value)}
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                Leave blank to sync the last 30 days by default.
                                            </p>
                                        </div>
                                    </div>
                                    <DialogFooter>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => setShowAddDialog(false)}
                                        >
                                            Cancel
                                        </Button>
                                        <Button type="submit" disabled={form.processing}>
                                            Connect Feed
                                        </Button>
                                    </DialogFooter>
                                </form>
                            </DialogContent>
                        </Dialog>
                    </div>
                </div>

                {feeds.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center justify-center py-12 text-center">
                            <Radio className="h-12 w-12 text-muted-foreground/40 mb-4" />
                            <h3 className="text-lg font-medium text-foreground mb-1">No bank feeds</h3>
                            <p className="text-muted-foreground mb-4">
                                Connect a bank feed to automatically import transactions from your NZ bank.
                            </p>
                            <Button onClick={() => setShowAddDialog(true)} disabled={availableAccounts.length === 0}>
                                <Plus className="w-4 h-4 mr-2" />
                                Add Bank Feed
                            </Button>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="space-y-4">
                        {feeds.map((feed) => (
                            <Card key={feed.id}>
                                <CardHeader className="pb-3">
                                    <div className="flex items-start justify-between">
                                        <div className="flex items-center gap-3">
                                            <div>
                                                <CardTitle className="text-lg">
                                                    {feed.bank_account_name}
                                                </CardTitle>
                                                <p className="text-sm text-muted-foreground mt-0.5">
                                                    {providerLabels[feed.provider] || feed.provider} &middot; {feed.bank_name}
                                                </p>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Badge variant={feed.is_active ? 'default' : 'secondary'}>
                                                {feed.is_active ? 'Active' : 'Inactive'}
                                            </Badge>
                                            <Badge variant={statusVariant(feed.last_sync_status)}>
                                                {statusLabel(feed.last_sync_status)}
                                            </Badge>
                                        </div>
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-6 text-sm text-muted-foreground">
                                            <span>
                                                Last sync: {feed.last_sync_at || 'Never'}
                                            </span>
                                            {feed.consent_expires_at && (
                                                <span>
                                                    Consent expires: {feed.consent_expires_at}
                                                </span>
                                            )}
                                            <span>
                                                Sync logs: {feed.logs_count}
                                            </span>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                asChild
                                            >
                                                <Link href={`/finance/bank-feeds/${feed.id}/logs`}>
                                                    <FileText className="w-4 h-4 mr-1" />
                                                    Logs
                                                </Link>
                                            </Button>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => handleSync(feed.id)}
                                                disabled={syncing === feed.id}
                                            >
                                                <RefreshCw className={`w-4 h-4 mr-1 ${syncing === feed.id ? 'animate-spin' : ''}`} />
                                                Sync
                                            </Button>
                                            <Button
                                                variant="destructive"
                                                size="sm"
                                                onClick={() => handleDelete(feed.id)}
                                            >
                                                <Trash2 className="w-4 h-4 mr-1" />
                                                Disconnect
                                            </Button>
                                        </div>
                                    </div>
                                    {feed.last_error && feed.last_sync_status === 'failed' && (
                                        <div className="flex items-start gap-2 mt-3 text-destructive bg-destructive/10 rounded-md px-3 py-2">
                                            <AlertCircle className="h-4 w-4 shrink-0 mt-0.5" />
                                            <span className="text-sm">{feed.last_error}</span>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
