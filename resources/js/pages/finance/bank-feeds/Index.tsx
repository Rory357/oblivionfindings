import { ConfirmDialog, FinanceSectionRail } from '@/components/finance';
import {
    EntityCard,
    EntityCardGrid,
    EntityChip,
    EntityContextMenu,
    EntityStatusChip,
    ListCaption,
    compactMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { EmptyList, EmptySearch } from '@/components/ui/empty-state';
import { LoadingState } from '@/components/ui/loading-state';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertCircle,
    FileText,
    Landmark,
    Plus,
    RefreshCw,
    Rss,
    Trash2,
} from 'lucide-react';
import { useMemo, useState } from 'react';

import { BANK_FEED_PROVIDERS, ConnectFeedDialog, providerLabel } from './_dialogs';

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

const ALL = '__all';

const SYNC_OPTIONS = [
    { value: ALL, label: 'Any sync state' },
    { value: 'success', label: 'Last sync succeeded' },
    { value: 'failed', label: 'Last sync failed' },
    { value: 'pending', label: 'Never synced' },
];

const syncLabel = (status: string | null) => {
    switch (status) {
        case 'success':
            return 'Sync succeeded';
        case 'failed':
            return 'Sync failed';
        case 'pending':
            return 'Awaiting first sync';
        default:
            return 'Never synced';
    }
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'Banking', href: '/finance/banking' },
    { title: 'Bank feeds', href: '/finance/bank-feeds' },
];

export default function BankFeedsIndex({
    feeds,
    bankAccounts,
    existingAccountIds,
    providerSetupEnabled,
    csvImportSupported,
    providerSetupMessage,
    csvImportUrl,
}: Props) {
    const [connectOpen, setConnectOpen] = useState(false);
    const [syncingAll, setSyncingAll] = useState(false);
    const [disconnectTarget, setDisconnectTarget] = useState<BankFeed | null>(
        null,
    );
    const [disconnecting, setDisconnecting] = useState(false);
    const [search, setSearch] = useState('');
    const [provider, setProvider] = useState(ALL);
    const [syncState, setSyncState] = useState(ALL);
    const ctxMenu = useEntityContextMenu<BankFeed>();

    const availableAccounts = bankAccounts.filter(
        (account) => !existingAccountIds.includes(account.id),
    );

    const activeFeeds = feeds.filter((feed) => feed.is_active).length;
    const failedFeeds = feeds.filter(
        (feed) => feed.last_sync_status === 'failed',
    ).length;
    const connectedAccounts = new Set(feeds.map((feed) => feed.bank_account_id))
        .size;

    const handleSync = (feed: BankFeed) => {
        router.post(
            `/finance/bank-feeds/${feed.id}/sync`,
            {},
            { preserveScroll: true },
        );
    };

    const handleSyncAll = () => {
        setSyncingAll(true);
        router.post(
            '/finance/bank-feeds/sync-all',
            {},
            { onFinish: () => setSyncingAll(false) },
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

    const actionsFor = (feed: BankFeed): MenuItem[] =>
        compactMenu([
            {
                label: 'Sync logs',
                icon: FileText,
                onClick: () =>
                    router.visit(`/finance/bank-feeds/${feed.id}/logs`),
            },
            providerSetupEnabled
                ? {
                      label: 'Sync now',
                      icon: RefreshCw,
                      onClick: () => handleSync(feed),
                  }
                : null,
            {
                label: 'Open bank account',
                icon: Landmark,
                onClick: () =>
                    router.visit(
                        `/finance/bank-accounts/${feed.bank_account_id}`,
                    ),
            },
            { separator: true },
            {
                label: 'Disconnect feed',
                icon: Trash2,
                danger: true,
                onClick: () => setDisconnectTarget(feed),
            },
        ]);

    const term = search.trim().toLowerCase();
    const visible = useMemo(
        () =>
            feeds.filter((feed) => {
                if (provider !== ALL && feed.provider !== provider)
                    return false;
                if (syncState !== ALL) {
                    const state = feed.last_sync_status ?? 'pending';
                    if (state !== syncState) return false;
                }
                if (!term) return true;
                return [
                    feed.bank_account_name,
                    feed.bank_name,
                    providerLabel(feed.provider),
                ]
                    .join(' ')
                    .toLowerCase()
                    .includes(term);
            }),
        [feeds, provider, syncState, term],
    );

    const clearFilters = () => {
        setSearch('');
        setProvider(ALL);
        setSyncState(ALL);
    };

    const header = (
        <PageHeader
            variant="index"
            icon={Rss}
            title="Bank feeds"
            titleChip={
                <PageHeaderStatusChip
                    variant={failedFeeds > 0 ? 'critical' : 'success'}
                >
                    {failedFeeds > 0
                        ? `${failedFeeds} failing`
                        : 'All feeds healthy'}
                </PageHeaderStatusChip>
            }
            subline={`Banking · ${feeds.length} feeds · ${activeFeeds} active`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search feeds, banks…"
                    />
                    {csvImportSupported && (
                        <PageHeaderGlassButton
                            icon={FileText}
                            onClick={() => router.visit(csvImportUrl)}
                        >
                            CSV import
                        </PageHeaderGlassButton>
                    )}
                    {feeds.length > 0 && (
                        <PageHeaderGlassButton
                            icon={RefreshCw}
                            onClick={handleSyncAll}
                            disabled={syncingAll || !providerSetupEnabled}
                        >
                            {syncingAll ? 'Syncing…' : 'Sync all'}
                        </PageHeaderGlassButton>
                    )}
                    <PageHeaderPrimaryButton
                        icon={Plus}
                        onClick={() => setConnectOpen(true)}
                        disabled={
                            availableAccounts.length === 0 ||
                            !providerSetupEnabled
                        }
                    >
                        Connect feed
                    </PageHeaderPrimaryButton>
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Feeds"
                        href="/finance/bank-feeds"
                        ariaLabel="View every bank feed"
                    >
                        <PageHeaderMeterBig>{feeds.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Connected to this organisation
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Active"
                        tone="success"
                        onClick={() => {
                            setSyncState(ALL);
                            setProvider(ALL);
                            setSearch('');
                        }}
                        ariaLabel="Show every feed"
                    >
                        <PageHeaderMeterBig>{activeFeeds}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {feeds.length - activeFeeds} paused
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Failing"
                        tone={failedFeeds > 0 ? 'critical' : 'brand'}
                        onClick={() => setSyncState('failed')}
                        ariaLabel="Show feeds whose last sync failed"
                    >
                        <PageHeaderMeterBig>{failedFeeds}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Last sync ended in an error
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Bank accounts fed"
                        href="/finance/bank-accounts"
                        ariaLabel="View bank accounts"
                    >
                        <PageHeaderMeterBig>
                            {connectedAccounts}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            of {bankAccounts.length} active accounts
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Provider"
                        value={provider}
                        allValue={ALL}
                        options={[
                            { value: ALL, label: 'Any provider' },
                            ...BANK_FEED_PROVIDERS,
                        ]}
                        onChange={setProvider}
                    />
                    <PageHeaderFilterSelect
                        label="Sync state"
                        value={syncState}
                        allValue={ALL}
                        options={SYNC_OPTIONS}
                        onChange={setSyncState}
                    />
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Bank feeds" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    {!providerSetupEnabled && (
                        <Alert>
                            <AlertCircle className="h-4 w-4" />
                            <AlertTitle>
                                Bank provider setup unavailable
                            </AlertTitle>
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

                    {syncingAll ? (
                        <LoadingState message="Syncing every active bank feed…" />
                    ) : feeds.length === 0 ? (
                        <EmptyList
                            icon={Rss}
                            itemName="bank feed"
                            title="No bank feeds yet"
                            description={
                                providerSetupEnabled
                                    ? 'Connect a feed to import transactions from an NZ bank automatically.'
                                    : 'CSV import is the supported way to bring bank transactions in.'
                            }
                            action={
                                providerSetupEnabled ? (
                                    <Button
                                        size="sm"
                                        onClick={() => setConnectOpen(true)}
                                        disabled={
                                            availableAccounts.length === 0
                                        }
                                    >
                                        Connect feed
                                    </Button>
                                ) : csvImportSupported ? (
                                    <Button asChild size="sm">
                                        <Link href={csvImportUrl}>
                                            Open CSV import
                                        </Link>
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <>
                            <ListCaption
                                title="Bank feeds"
                                caption={`${visible.length} of ${feeds.length} shown`}
                            />

                            {visible.length === 0 ? (
                                <EmptySearch
                                    onClear={clearFilters}
                                    title="No bank feeds match your filters"
                                />
                            ) : (
                                <EntityCardGrid>
                                    {visible.map((feed) => (
                                        <EntityCard
                                            key={feed.id}
                                            meridian={
                                                feed.last_sync_status ===
                                                'failed'
                                                    ? 'critical'
                                                    : !feed.is_active ||
                                                        feed.last_sync_status !==
                                                            'success'
                                                      ? 'warning'
                                                      : 'success'
                                            }
                                            icon={Rss}
                                            name={feed.bank_account_name}
                                            subline={`${providerLabel(feed.provider)} · ${feed.bank_name}`}
                                            sublineIcon={Landmark}
                                            href={`/finance/bank-feeds/${feed.id}/logs`}
                                            onContextMenu={(event) =>
                                                ctxMenu.open(event, feed)
                                            }
                                            actions={actionsFor(feed)}
                                            muted={!feed.is_active}
                                            chips={
                                                <>
                                                    <EntityStatusChip
                                                        variant={
                                                            feed.last_sync_status ===
                                                            'success'
                                                                ? 'success'
                                                                : feed.last_sync_status ===
                                                                    'failed'
                                                                  ? 'critical'
                                                                  : 'warning'
                                                        }
                                                    >
                                                        {syncLabel(
                                                            feed.last_sync_status,
                                                        )}
                                                    </EntityStatusChip>
                                                    {!feed.is_active ? (
                                                        <EntityStatusChip variant="neutral">
                                                            Paused
                                                        </EntityStatusChip>
                                                    ) : null}
                                                    <EntityChip>
                                                        {feed.logs_count} sync
                                                        {feed.logs_count === 1
                                                            ? ''
                                                            : 's'}
                                                    </EntityChip>
                                                </>
                                            }
                                            alerts={
                                                feed.last_error &&
                                                feed.last_sync_status ===
                                                    'failed' ? (
                                                    <EntityStatusChip
                                                        variant="critical"
                                                        icon={AlertCircle}
                                                    >
                                                        {feed.last_error}
                                                    </EntityStatusChip>
                                                ) : undefined
                                            }
                                            footer={{
                                                personIcon: RefreshCw,
                                                primary: feed.last_sync_at
                                                    ? `Last sync ${feed.last_sync_at}`
                                                    : 'Never synced',
                                                secondary:
                                                    feed.consent_expires_at
                                                        ? `Consent expires ${feed.consent_expires_at}`
                                                        : 'No consent expiry recorded',
                                            }}
                                            openLabel="Logs"
                                        />
                                    ))}
                                </EntityCardGrid>
                            )}
                        </>
                    )}
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={Rss}
                    title={ctxMenu.ctx.record.bank_account_name}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}

            <ConnectFeedDialog
                open={connectOpen}
                onClose={() => setConnectOpen(false)}
                availableAccounts={availableAccounts}
            />

            <ConfirmDialog
                open={!!disconnectTarget}
                onClose={() => setDisconnectTarget(null)}
                title="Disconnect bank feed?"
                description={
                    <>
                        This disconnects the automated feed for{' '}
                        <span className="font-medium text-foreground">
                            {disconnectTarget?.bank_account_name}
                        </span>
                        . Transactions stop importing until you reconnect it.
                    </>
                }
                confirmText="Disconnect feed"
                variant="destructive"
                processing={disconnecting}
                onConfirm={confirmDisconnect}
            />
        </AppLayout>
    );
}
