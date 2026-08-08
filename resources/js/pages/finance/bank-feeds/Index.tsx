import { BankingTabsFooter, ConfirmDialog } from '@/components/finance';
import { PageHero, PageLayout } from '@/components/page';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
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
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertCircle,
    FileText,
    Plus,
    Radio,
    RefreshCw,
    Rss,
    Trash2,
} from 'lucide-react';
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
    providerSetupEnabled: boolean;
    csvImportSupported: boolean;
    providerSetupMessage: string;
    csvImportUrl: string;
}

const providerLabels: Record<string, string> = {
    asb: 'ASB',
    anz: 'ANZ',
    westpac: 'Westpac',
    bnz: 'BNZ',
};

const statusVariant = (
    status: string | null,
): 'default' | 'destructive' | 'secondary' | 'outline' => {
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

export default function BankFeedsIndex({
    feeds,
    bankAccounts,
    existingAccountIds,
    providerSetupEnabled,
    csvImportSupported,
    providerSetupMessage,
    csvImportUrl,
}: Props) {
    const [showAddDialog, setShowAddDialog] = useState(false);
    const [syncing, setSyncing] = useState<number | null>(null);
    const [syncingAll, setSyncingAll] = useState(false);
    const [disconnectTarget, setDisconnectTarget] = useState<BankFeed | null>(
        null,
    );
    const [disconnecting, setDisconnecting] = useState(false);

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
        router.post(
            `/finance/bank-feeds/${feedId}/sync`,
            {},
            {
                onFinish: () => setSyncing(null),
            },
        );
    };

    const handleSyncAll = () => {
        setSyncingAll(true);
        router.post(
            '/finance/bank-feeds/sync-all',
            {},
            {
                onFinish: () => setSyncingAll(false),
            },
        );
    };

    const confirmDisconnect = () => {
        if (!disconnectTarget) return;
        router.delete(`/finance/bank-feeds/${disconnectTarget.id}`, {
            onStart: () => setDisconnecting(true),
            onFinish: () => setDisconnecting(false),
            onSuccess: () => setDisconnectTarget(null),
        });
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Finance', href: '/finance' },
        { title: 'Bank Feeds', href: '/finance/bank-feeds' },
    ];

    const activeFeeds = feeds.filter((f) => f.is_active).length;
    const failedFeeds = feeds.filter(
        (f) => f.last_sync_status === 'failed',
    ).length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Bank Feeds" />

            <PageLayout
                hero={
                    <PageHero
                        category="finance"
                        icon={Rss}
                        title="Bank Feeds"
                        description="Automated bank transaction imports from NZ banks"
                        stats={[
                            { label: 'Feeds', value: feeds.length },
                            { label: 'Active', value: activeFeeds },
                            { label: 'Failed', value: failedFeeds },
                        ]}
                        actions={
                            <div className="flex flex-wrap items-center gap-2">
                                {feeds.length > 0 && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={handleSyncAll}
                                        disabled={
                                            syncingAll || !providerSetupEnabled
                                        }
                                        className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                    >
                                        <RefreshCw
                                            className={`mr-1.5 h-4 w-4 ${syncingAll ? 'animate-spin' : ''}`}
                                        />
                                        Sync All
                                    </Button>
                                )}
                                <Dialog
                                    open={showAddDialog}
                                    onOpenChange={setShowAddDialog}
                                >
                                    <DialogTrigger asChild>
                                        <Button
                                            size="sm"
                                            disabled={
                                                availableAccounts.length ===
                                                    0 || !providerSetupEnabled
                                            }
                                        >
                                            <Plus className="mr-1.5 h-4 w-4" />
                                            Add Bank Feed
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent>
                                        <form onSubmit={handleSubmit}>
                                            <DialogHeader>
                                                <DialogTitle>
                                                    Connect Bank Feed
                                                </DialogTitle>
                                                <DialogDescription>
                                                    Set up an automated bank
                                                    feed connection for a bank
                                                    account.
                                                </DialogDescription>
                                            </DialogHeader>
                                            <div className="space-y-4 py-4">
                                                <div className="space-y-2">
                                                    <Label htmlFor="bank_account_id">
                                                        Bank Account
                                                    </Label>
                                                    <Select
                                                        value={
                                                            form.data
                                                                .bank_account_id
                                                        }
                                                        onValueChange={(
                                                            value,
                                                        ) =>
                                                            form.setData(
                                                                'bank_account_id',
                                                                value,
                                                            )
                                                        }
                                                    >
                                                        <SelectTrigger>
                                                            <SelectValue placeholder="Select a bank account" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {availableAccounts.map(
                                                                (account) => (
                                                                    <SelectItem
                                                                        key={
                                                                            account.id
                                                                        }
                                                                        value={String(
                                                                            account.id,
                                                                        )}
                                                                    >
                                                                        {
                                                                            account.name
                                                                        }{' '}
                                                                        (
                                                                        {
                                                                            account.bank_name
                                                                        }
                                                                        )
                                                                    </SelectItem>
                                                                ),
                                                            )}
                                                        </SelectContent>
                                                    </Select>
                                                    {form.errors
                                                        .bank_account_id && (
                                                        <p className="text-sm text-destructive">
                                                            {
                                                                form.errors
                                                                    .bank_account_id
                                                            }
                                                        </p>
                                                    )}
                                                </div>

                                                <div className="space-y-2">
                                                    <Label htmlFor="provider">
                                                        Bank Provider
                                                    </Label>
                                                    <Select
                                                        value={
                                                            form.data.provider
                                                        }
                                                        onValueChange={(
                                                            value,
                                                        ) =>
                                                            form.setData(
                                                                'provider',
                                                                value,
                                                            )
                                                        }
                                                    >
                                                        <SelectTrigger>
                                                            <SelectValue placeholder="Select provider" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="asb">
                                                                ASB
                                                            </SelectItem>
                                                            <SelectItem value="anz">
                                                                ANZ
                                                            </SelectItem>
                                                            <SelectItem value="westpac">
                                                                Westpac
                                                            </SelectItem>
                                                            <SelectItem value="bnz">
                                                                BNZ
                                                            </SelectItem>
                                                        </SelectContent>
                                                    </Select>
                                                    {form.errors.provider && (
                                                        <p className="text-sm text-destructive">
                                                            {
                                                                form.errors
                                                                    .provider
                                                            }
                                                        </p>
                                                    )}
                                                </div>

                                                <div className="space-y-2">
                                                    <Label htmlFor="sync_from_date">
                                                        Sync From Date
                                                        (optional)
                                                    </Label>
                                                    <Input
                                                        type="date"
                                                        id="sync_from_date"
                                                        value={
                                                            form.data
                                                                .sync_from_date
                                                        }
                                                        onChange={(e) =>
                                                            form.setData(
                                                                'sync_from_date',
                                                                e.target.value,
                                                            )
                                                        }
                                                    />
                                                    <p className="text-xs text-muted-foreground">
                                                        Leave blank to sync the
                                                        last 30 days by default.
                                                    </p>
                                                </div>
                                            </div>
                                            <DialogFooter>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    onClick={() =>
                                                        setShowAddDialog(false)
                                                    }
                                                >
                                                    Cancel
                                                </Button>
                                                <Button
                                                    type="submit"
                                                    disabled={form.processing}
                                                >
                                                    Connect Feed
                                                </Button>
                                            </DialogFooter>
                                        </form>
                                    </DialogContent>
                                </Dialog>
                            </div>
                        }
                        footer={<BankingTabsFooter active="feeds" />}
                    />
                }
            >
                {!providerSetupEnabled && (
                    <Alert>
                        <AlertCircle className="h-4 w-4" />
                        <AlertTitle>Bank provider setup unavailable</AlertTitle>
                        <AlertDescription className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <span>{providerSetupMessage}</span>
                            {csvImportSupported && (
                                <Button asChild variant="outline" size="sm">
                                    <Link href={csvImportUrl}>
                                        <FileText className="mr-1 h-4 w-4" />
                                        CSV import
                                    </Link>
                                </Button>
                            )}
                        </AlertDescription>
                    </Alert>
                )}

                {feeds.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center justify-center py-12 text-center">
                            <Radio className="mb-4 h-12 w-12 text-muted-foreground/40" />
                            <h3 className="mb-1 text-lg font-medium text-foreground">
                                No bank feeds
                            </h3>
                            <p className="mb-4 text-muted-foreground">
                                {providerSetupEnabled
                                    ? 'Connect a bank feed to automatically import transactions from your NZ bank.'
                                    : 'CSV import is the supported bank transaction import path.'}
                            </p>
                            {providerSetupEnabled ? (
                                <Button
                                    onClick={() => setShowAddDialog(true)}
                                    disabled={availableAccounts.length === 0}
                                >
                                    <Plus className="mr-2 h-4 w-4" />
                                    Add Bank Feed
                                </Button>
                            ) : (
                                csvImportSupported && (
                                    <Button asChild>
                                        <Link href={csvImportUrl}>
                                            <FileText className="mr-2 h-4 w-4" />
                                            Open CSV import
                                        </Link>
                                    </Button>
                                )
                            )}
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
                                                <p className="mt-0.5 text-sm text-muted-foreground">
                                                    {providerLabels[
                                                        feed.provider
                                                    ] || feed.provider}{' '}
                                                    &middot; {feed.bank_name}
                                                </p>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Badge
                                                variant={
                                                    feed.is_active
                                                        ? 'default'
                                                        : 'secondary'
                                                }
                                            >
                                                {feed.is_active
                                                    ? 'Active'
                                                    : 'Inactive'}
                                            </Badge>
                                            <Badge
                                                variant={statusVariant(
                                                    feed.last_sync_status,
                                                )}
                                            >
                                                {statusLabel(
                                                    feed.last_sync_status,
                                                )}
                                            </Badge>
                                        </div>
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-6 text-sm text-muted-foreground">
                                            <span>
                                                Last sync:{' '}
                                                {feed.last_sync_at || 'Never'}
                                            </span>
                                            {feed.consent_expires_at && (
                                                <span>
                                                    Consent expires:{' '}
                                                    {feed.consent_expires_at}
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
                                                <Link
                                                    href={`/finance/bank-feeds/${feed.id}/logs`}
                                                >
                                                    <FileText className="mr-1 h-4 w-4" />
                                                    Logs
                                                </Link>
                                            </Button>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    handleSync(feed.id)
                                                }
                                                disabled={
                                                    syncing === feed.id ||
                                                    !providerSetupEnabled
                                                }
                                            >
                                                <RefreshCw
                                                    className={`mr-1 h-4 w-4 ${syncing === feed.id ? 'animate-spin' : ''}`}
                                                />
                                                Sync
                                            </Button>
                                            <Button
                                                variant="destructive"
                                                size="sm"
                                                onClick={() =>
                                                    setDisconnectTarget(feed)
                                                }
                                            >
                                                <Trash2 className="mr-1 h-4 w-4" />
                                                Disconnect
                                            </Button>
                                        </div>
                                    </div>
                                    {feed.last_error &&
                                        feed.last_sync_status === 'failed' && (
                                            <div className="mt-3 flex items-start gap-2 rounded-md bg-destructive/10 px-3 py-2 text-destructive">
                                                <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" />
                                                <span className="text-sm">
                                                    {feed.last_error}
                                                </span>
                                            </div>
                                        )}
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
            </PageLayout>

            <ConfirmDialog
                open={!!disconnectTarget}
                onOpenChange={(open) => !open && setDisconnectTarget(null)}
                title="Disconnect bank feed?"
                description={
                    <>
                        This disconnects the automated feed for{' '}
                        <span className="font-medium text-foreground">
                            {disconnectTarget?.bank_account_name}
                        </span>
                        . Transactions will stop importing until you reconnect
                        it.
                    </>
                }
                confirmLabel="Disconnect feed"
                variant="destructive"
                processing={disconnecting}
                onConfirm={confirmDisconnect}
            />
        </AppLayout>
    );
}
