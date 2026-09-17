import { ConfirmDialog, FinanceSectionRail } from '@/components/finance';
import {
    compactMenu,
    EntityCard,
    EntityCardGrid,
    EntityChip,
    EntityContextMenu,
    EntityStatusChip,
    EntityTable,
    type EntityTableColumn,
    ListCaption,
    type MenuItem,
    useEntityContextMenu,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
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
import { Head, router, useForm } from '@inertiajs/react';
import {
    AlertCircle,
    ArrowDownToLine,
    ArrowUpFromLine,
    History,
    KeyRound,
    Link2,
    Plug,
    PlugZap,
    Plus,
    RefreshCw,
    Settings2,
    Trash2,
} from 'lucide-react';
import { FormEvent, useMemo, useRef, useState } from 'react';

type SyncLog = {
    id: number;
    direction: 'push' | 'pull';
    entity_type: string;
    entity_count: number;
    success_count: number;
    error_count: number;
    started_at: string;
    completed_at: string | null;
    duration_ms: number | null;
};

type Integration = {
    id: number;
    provider: 'xero' | 'myob';
    tenant_id: string | null;
    sync_direction: 'push' | 'pull' | 'bidirectional';
    is_active: boolean;
    last_sync_at: string | null;
    last_sync_status: 'success' | 'failed' | 'pending' | null;
    last_error: string | null;
    has_token: boolean;
    token_expired: boolean;
    sync_logs_count: number;
    created_by: string | null;
    created_at: string;
    settings: Record<string, unknown>;
    recent_logs: SyncLog[];
};

type Summary = {
    syncs_total: number;
    syncs_7d: number;
    records_synced_7d: number;
    errors_7d: number;
};

type PageProps = {
    integrations: Integration[];
    summary: Summary;
};

/** A recent sync log flattened onto the connection it belongs to. */
type ActivityRow = SyncLog & { integrationId: number; provider: string };

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'Settings', href: '/finance/settings' },
    { title: 'Integrations', href: '/finance/integrations' },
];

const providerLabels: Record<string, string> = {
    xero: 'Xero',
    myob: 'MYOB',
};

const syncDirectionLabels: Record<string, string> = {
    push: 'Push only',
    pull: 'Pull only',
    bidirectional: 'Two-way sync',
};

const directionIcons = {
    push: ArrowUpFromLine,
    pull: ArrowDownToLine,
    bidirectional: RefreshCw,
} as const;

const entityTypeLabels: Record<string, string> = {
    account: 'Accounts',
    accounts: 'Accounts',
    journal: 'Journals',
    journals: 'Journals',
    invoice: 'Invoices',
    invoices: 'Invoices',
    bill: 'Bills',
    bills: 'Bills',
    contact: 'Contacts',
    contacts: 'Contacts',
};

const entityTypeLabel = (value: string) =>
    entityTypeLabels[value] ??
    value.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());

const STATE_OPTIONS = [
    { value: 'all', label: 'All connections' },
    { value: 'active', label: 'Active only' },
    { value: 'inactive', label: 'Inactive only' },
    { value: 'failed', label: 'Last sync failed' },
];

const PROVIDER_OPTIONS = [
    { value: 'all', label: 'All providers' },
    { value: 'xero', label: 'Xero' },
    { value: 'myob', label: 'MYOB' },
];

const lastSyncVariant = (status: Integration['last_sync_status']) =>
    status === 'failed'
        ? 'critical'
        : status === 'pending'
          ? 'warning'
          : 'success';

function ConnectProviderDialog({
    open,
    onClose,
}: {
    open: boolean;
    onClose: () => void;
}) {
    const { data, setData, post, processing, errors, reset, clearErrors } =
        useForm({
            provider: '' as string,
            tenant_id: '',
            sync_direction: 'bidirectional',
        });

    const close = () => {
        reset();
        clearErrors();
        onClose();
    };

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        post('/finance/integrations', {
            onSuccess: close,
        });
    }

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) close();
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Connect an accounting provider</DialogTitle>
                    <DialogDescription>
                        Set up a connection to Xero or MYOB for two-way general
                        ledger synchronisation.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="provider">Provider *</Label>
                        <Select
                            value={data.provider}
                            onValueChange={(v) => setData('provider', v)}
                        >
                            <SelectTrigger id="provider">
                                <SelectValue placeholder="Select a provider" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="xero">Xero</SelectItem>
                                <SelectItem value="myob">MYOB</SelectItem>
                            </SelectContent>
                        </Select>
                        {errors.provider && (
                            <p className="text-sm text-destructive">
                                {errors.provider}
                            </p>
                        )}
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="tenant_id">
                            {data.provider === 'myob'
                                ? 'Company file URI'
                                : 'Tenant ID'}{' '}
                            (optional)
                        </Label>
                        <Input
                            id="tenant_id"
                            value={data.tenant_id}
                            onChange={(e) =>
                                setData('tenant_id', e.target.value)
                            }
                            placeholder={
                                data.provider === 'myob'
                                    ? 'MYOB company file URI'
                                    : 'Xero tenant ID'
                            }
                        />
                        {errors.tenant_id && (
                            <p className="text-sm text-destructive">
                                {errors.tenant_id}
                            </p>
                        )}
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="sync_direction">Sync direction *</Label>
                        <Select
                            value={data.sync_direction}
                            onValueChange={(v) => setData('sync_direction', v)}
                        >
                            <SelectTrigger id="sync_direction">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="bidirectional">
                                    Two-way sync
                                </SelectItem>
                                <SelectItem value="push">
                                    Push only (local to provider)
                                </SelectItem>
                                <SelectItem value="pull">
                                    Pull only (provider to local)
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        {errors.sync_direction && (
                            <p className="text-sm text-destructive">
                                {errors.sync_direction}
                            </p>
                        )}
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={close}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Connecting…' : 'Connect'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function IntegrationsIndex({
    integrations,
    summary,
}: PageProps) {
    const [connectOpen, setConnectOpen] = useState(false);
    const [stateFilter, setStateFilter] = useState('all');
    const [providerFilter, setProviderFilter] = useState('all');
    const [syncTarget, setSyncTarget] = useState<Integration | null>(null);
    const [syncing, setSyncing] = useState(false);
    const [disconnectTarget, setDisconnectTarget] =
        useState<Integration | null>(null);
    const [disconnecting, setDisconnecting] = useState(false);
    const [testingId, setTestingId] = useState<number | null>(null);

    const activityRef = useRef<HTMLDivElement>(null);
    const ctx = useEntityContextMenu<Integration>();
    const activityCtx = useEntityContextMenu<ActivityRow>();

    const activeCount = integrations.filter((i) => i.is_active).length;
    const failedCount = integrations.filter(
        (i) => i.last_sync_status === 'failed',
    ).length;

    const shown = integrations.filter((integration) => {
        const matchesProvider =
            providerFilter === 'all' || integration.provider === providerFilter;
        const matchesState =
            stateFilter === 'all' ||
            (stateFilter === 'active' && integration.is_active) ||
            (stateFilter === 'inactive' && !integration.is_active) ||
            (stateFilter === 'failed' &&
                integration.last_sync_status === 'failed');
        return matchesProvider && matchesState;
    });

    const activity = useMemo<ActivityRow[]>(
        () =>
            shown
                .flatMap((integration) =>
                    integration.recent_logs.map((log) => ({
                        ...log,
                        integrationId: integration.id,
                        provider: integration.provider,
                    })),
                )
                .sort((a, b) => b.started_at.localeCompare(a.started_at)),
        [shown],
    );

    const handleTest = (integration: Integration) => {
        setTestingId(integration.id);
        router.post(
            `/finance/integrations/${integration.id}/test`,
            {},
            { preserveScroll: true, onFinish: () => setTestingId(null) },
        );
    };

    const confirmSync = () => {
        if (!syncTarget) return;
        router.post(
            `/finance/integrations/${syncTarget.id}/sync`,
            {},
            {
                onStart: () => setSyncing(true),
                onFinish: () => {
                    setSyncing(false);
                    setSyncTarget(null);
                },
            },
        );
    };

    const confirmDisconnect = () => {
        if (!disconnectTarget) return;
        router.delete(`/finance/integrations/${disconnectTarget.id}`, {
            onStart: () => setDisconnecting(true),
            onFinish: () => {
                setDisconnecting(false);
                setDisconnectTarget(null);
            },
        });
    };

    const actionsFor = (integration: Integration): MenuItem[] =>
        compactMenu([
            {
                label: 'Account mapping',
                icon: Settings2,
                onClick: () =>
                    router.visit(
                        `/finance/integrations/${integration.id}/mapping`,
                    ),
            },
            integration.is_active && {
                label: 'Sync now',
                icon: RefreshCw,
                onClick: () => setSyncTarget(integration),
            },
            {
                label: 'Test connection',
                icon: PlugZap,
                onClick: () => handleTest(integration),
            },
            { separator: true },
            {
                label: 'Disconnect',
                icon: Trash2,
                danger: true,
                onClick: () => setDisconnectTarget(integration),
            },
        ]);

    const activityActionsFor = (log: ActivityRow): MenuItem[] => [
        {
            label: 'Account mapping',
            icon: Settings2,
            onClick: () =>
                router.visit(
                    `/finance/integrations/${log.integrationId}/mapping`,
                ),
        },
        {
            label: `Show ${providerLabels[log.provider]} only`,
            icon: Plug,
            onClick: () => setProviderFilter(log.provider),
        },
    ];

    const activityColumns: EntityTableColumn<ActivityRow>[] = [
        {
            key: 'direction',
            label: 'Direction',
            width: '130px',
            cell: (log) => (
                <EntityChip
                    outline
                    icon={
                        log.direction === 'push'
                            ? ArrowUpFromLine
                            : ArrowDownToLine
                    }
                >
                    {log.direction === 'push' ? 'Push' : 'Pull'}
                </EntityChip>
            ),
        },
        {
            key: 'entity_count',
            label: 'Records',
            width: '100px',
            align: 'right',
            cell: (log) => (
                <span className="tabular-nums">{log.entity_count}</span>
            ),
        },
        {
            key: 'success_count',
            label: 'Synced',
            width: '100px',
            align: 'right',
            cell: (log) => (
                <span className="text-status-success tabular-nums">
                    {log.success_count}
                </span>
            ),
        },
        {
            key: 'error_count',
            label: 'Errors',
            width: '100px',
            align: 'right',
            cell: (log) => (
                <span
                    className={
                        log.error_count > 0
                            ? 'text-status-critical tabular-nums'
                            : 'text-muted-foreground tabular-nums'
                    }
                >
                    {log.error_count}
                </span>
            ),
        },
        {
            key: 'duration',
            label: 'Duration',
            width: '110px',
            align: 'right',
            cell: (log) => (
                <span className="text-muted-foreground tabular-nums">
                    {log.duration_ms
                        ? `${(log.duration_ms / 1000).toFixed(1)}s`
                        : '—'}
                </span>
            ),
        },
        {
            key: 'started_at',
            label: 'Started',
            width: '190px',
            cell: (log) => (
                <span className="truncate text-muted-foreground">
                    {log.started_at}
                </span>
            ),
        },
    ];

    const header = (
        <PageHeader
            icon={Plug}
            title="Accounting integrations"
            titleChip={
                <PageHeaderStatusChip
                    variant={failedCount > 0 ? 'critical' : 'success'}
                >
                    {failedCount > 0
                        ? `${failedCount} failing`
                        : `${activeCount} active`}
                </PageHeaderStatusChip>
            }
            subline={`Settings · ${integrations.length} connections · two-way general ledger sync with Xero and MYOB`}
            actions={
                <PageHeaderPrimaryButton
                    icon={Plus}
                    onClick={() => setConnectOpen(true)}
                >
                    Connect provider
                </PageHeaderPrimaryButton>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Connections"
                        ariaLabel="Show every connection"
                        onClick={() => {
                            setStateFilter('all');
                            setProviderFilter('all');
                        }}
                    >
                        <PageHeaderMeterBig>
                            {integrations.length}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Xero and MYOB connections
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Active"
                        tone="success"
                        ariaLabel="Show only active connections"
                        onClick={() => setStateFilter('active')}
                    >
                        <PageHeaderMeterDonut
                            percent={
                                integrations.length === 0
                                    ? 0
                                    : (activeCount / integrations.length) * 100
                            }
                            caption={`${activeCount} of ${integrations.length} syncing`}
                        />
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Failing"
                        tone="critical"
                        ariaLabel="Show only connections whose last sync failed"
                        onClick={() => setStateFilter('failed')}
                    >
                        <PageHeaderMeterBig>{failedCount}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            last sync returned an error
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Sync runs"
                        ariaLabel="Jump to recent sync activity"
                        onClick={() =>
                            activityRef.current?.scrollIntoView({
                                behavior: 'smooth',
                                block: 'start',
                            })
                        }
                    >
                        <PageHeaderMeterBig>
                            {summary.syncs_total}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {summary.syncs_7d} in the last 7 days
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Records synced"
                        tone={summary.errors_7d > 0 ? 'warning' : 'brand'}
                        ariaLabel="Jump to recent sync activity"
                        onClick={() =>
                            activityRef.current?.scrollIntoView({
                                behavior: 'smooth',
                                block: 'start',
                            })
                        }
                    >
                        <PageHeaderMeterBig>
                            {summary.records_synced_7d}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            last 7 days · {summary.errors_7d} errors
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Provider"
                        value={providerFilter}
                        allValue="all"
                        options={PROVIDER_OPTIONS}
                        onChange={setProviderFilter}
                    />
                    <PageHeaderFilterSelect
                        label="Connection state"
                        value={stateFilter}
                        allValue="all"
                        options={STATE_OPTIONS}
                        onChange={setStateFilter}
                    />
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Accounting integrations" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Connections"
                        caption={`${shown.length} of ${integrations.length} shown`}
                    />

                    {shown.length === 0 ? (
                        <EmptyState
                            icon={Link2}
                            heading={
                                integrations.length === 0
                                    ? 'No integrations connected'
                                    : 'No connections match these filters'
                            }
                            description={
                                integrations.length === 0
                                    ? 'Connect Xero or MYOB to start synchronising your chart of accounts, journals and invoices.'
                                    : 'Clear the provider or connection-state filter to see every connection.'
                            }
                            action={
                                integrations.length === 0 ? (
                                    <Button
                                        size="sm"
                                        onClick={() => setConnectOpen(true)}
                                    >
                                        Connect provider
                                    </Button>
                                ) : (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setStateFilter('all');
                                            setProviderFilter('all');
                                        }}
                                    >
                                        Clear filters
                                    </Button>
                                )
                            }
                        />
                    ) : (
                        <EntityCardGrid>
                            {shown.map((integration) => {
                                const DirectionIcon =
                                    directionIcons[integration.sync_direction];
                                const inFlight =
                                    testingId === integration.id ||
                                    (syncing &&
                                        syncTarget?.id === integration.id);
                                return (
                                    <EntityCard
                                        key={integration.id}
                                        meridian={
                                            integration.last_sync_status ===
                                                'failed' ||
                                            integration.token_expired
                                                ? 'critical'
                                                : !integration.is_active ||
                                                    !integration.has_token
                                                  ? 'warning'
                                                  : 'success'
                                        }
                                        icon={Link2}
                                        name={
                                            providerLabels[integration.provider]
                                        }
                                        subline={
                                            integration.tenant_id ??
                                            'No tenant configured'
                                        }
                                        muted={!integration.is_active}
                                        actions={actionsFor(integration)}
                                        href={`/finance/integrations/${integration.id}/mapping`}
                                        openLabel="Account mapping"
                                        linkLabel={`Account mapping for ${providerLabels[integration.provider]}`}
                                        onContextMenu={(e) =>
                                            ctx.open(e, integration)
                                        }
                                        chips={
                                            <>
                                                <EntityStatusChip
                                                    variant={
                                                        integration.is_active
                                                            ? 'success'
                                                            : 'neutral'
                                                    }
                                                >
                                                    {integration.is_active
                                                        ? 'Active'
                                                        : 'Inactive'}
                                                </EntityStatusChip>
                                                <EntityChip
                                                    icon={DirectionIcon}
                                                    outline
                                                >
                                                    {
                                                        syncDirectionLabels[
                                                            integration
                                                                .sync_direction
                                                        ]
                                                    }
                                                </EntityChip>
                                                <EntityChip icon={History}>
                                                    {integration.last_sync_at
                                                        ? `Last sync ${integration.last_sync_at}`
                                                        : 'Never synced'}
                                                </EntityChip>
                                                <EntityChip icon={RefreshCw}>
                                                    {
                                                        integration.sync_logs_count
                                                    }{' '}
                                                    sync runs
                                                </EntityChip>
                                            </>
                                        }
                                        alerts={
                                            <>
                                                {integration.last_sync_status && (
                                                    <EntityStatusChip
                                                        variant={lastSyncVariant(
                                                            integration.last_sync_status,
                                                        )}
                                                    >
                                                        {integration.last_sync_status ===
                                                        'failed'
                                                            ? 'Last sync failed'
                                                            : integration.last_sync_status ===
                                                                'pending'
                                                              ? 'Sync in progress'
                                                              : 'Last sync succeeded'}
                                                    </EntityStatusChip>
                                                )}
                                                <EntityStatusChip
                                                    icon={KeyRound}
                                                    variant={
                                                        !integration.has_token
                                                            ? 'warning'
                                                            : integration.token_expired
                                                              ? 'critical'
                                                              : 'success'
                                                    }
                                                >
                                                    {!integration.has_token
                                                        ? 'Token not configured'
                                                        : integration.token_expired
                                                          ? 'Token expired'
                                                          : 'Token valid'}
                                                </EntityStatusChip>
                                                {inFlight && (
                                                    <EntityStatusChip
                                                        variant="info"
                                                        icon={RefreshCw}
                                                    >
                                                        {testingId ===
                                                        integration.id
                                                            ? 'Testing connection…'
                                                            : 'Queueing sync…'}
                                                    </EntityStatusChip>
                                                )}
                                                {integration.last_error && (
                                                    <EntityStatusChip
                                                        variant="critical"
                                                        icon={AlertCircle}
                                                        className="max-w-full"
                                                    >
                                                        <span className="min-w-0 truncate">
                                                            {
                                                                integration.last_error
                                                            }
                                                        </span>
                                                    </EntityStatusChip>
                                                )}
                                            </>
                                        }
                                        footer={{
                                            personName: integration.created_by,
                                            personIcon: Plug,
                                            primary:
                                                integration.created_by ??
                                                'Connected automatically',
                                            secondary: `Connected ${integration.created_at}`,
                                        }}
                                    />
                                );
                            })}
                        </EntityCardGrid>
                    )}

                    <div ref={activityRef} className="flex flex-col gap-5">
                        <ListCaption
                            title="Recent sync activity"
                            caption={
                                activity.length === 0
                                    ? 'No sync runs yet'
                                    : `${activity.length} most recent runs · ${summary.syncs_total} in total`
                            }
                        />

                        {activity.length === 0 ? (
                            <EmptyState
                                icon={History}
                                heading="No sync runs recorded"
                                description="Sync runs appear here once a connection has pushed or pulled its first batch."
                            />
                        ) : (
                            <EntityTable
                                rows={activity}
                                rowKey={(log) => log.id}
                                identityLabel="Sync run"
                                minWidth={980}
                                identity={(log) => ({
                                    icon: RefreshCw,
                                    name: `${providerLabels[log.provider]} · ${entityTypeLabel(log.entity_type)}`,
                                    subline: log.completed_at
                                        ? `Completed ${log.completed_at}`
                                        : 'Still running',
                                })}
                                columns={activityColumns}
                                actionsFor={activityActionsFor}
                                hrefFor={(log) =>
                                    `/finance/integrations/${log.integrationId}/mapping`
                                }
                                onRowContextMenu={(e, log) =>
                                    activityCtx.open(e, log)
                                }
                            />
                        )}
                    </div>
                </div>
            </PageLayout>

            {ctx.ctx ? (
                <EntityContextMenu
                    x={ctx.ctx.x}
                    y={ctx.ctx.y}
                    icon={Link2}
                    title={providerLabels[ctx.ctx.record.provider]}
                    items={actionsFor(ctx.ctx.record)}
                    onClose={ctx.close}
                />
            ) : null}

            {activityCtx.ctx ? (
                <EntityContextMenu
                    x={activityCtx.ctx.x}
                    y={activityCtx.ctx.y}
                    icon={RefreshCw}
                    title={`${providerLabels[activityCtx.ctx.record.provider]} · ${entityTypeLabel(activityCtx.ctx.record.entity_type)}`}
                    items={activityActionsFor(activityCtx.ctx.record)}
                    onClose={activityCtx.close}
                />
            ) : null}

            <ConnectProviderDialog
                open={connectOpen}
                onClose={() => setConnectOpen(false)}
            />

            <ConfirmDialog
                open={!!syncTarget}
                onClose={() => setSyncTarget(null)}
                title={`Sync ${syncTarget ? providerLabels[syncTarget.provider] : ''} now?`}
                description={
                    <>
                        This queues a{' '}
                        {syncTarget
                            ? syncDirectionLabels[
                                  syncTarget.sync_direction
                              ].toLowerCase()
                            : 'sync'}{' '}
                        run between this ledger and{' '}
                        {syncTarget ? providerLabels[syncTarget.provider] : ''}.
                        Accounts, journals and invoices are written to whichever
                        side the sync direction allows.
                    </>
                }
                confirmText="Queue sync"
                processing={syncing}
                onConfirm={confirmSync}
            />

            <ConfirmDialog
                variant="destructive"
                open={!!disconnectTarget}
                onClose={() => setDisconnectTarget(null)}
                title={`Disconnect ${disconnectTarget ? providerLabels[disconnectTarget.provider] : ''}?`}
                description="This removes the integration connection. Your local data is not affected, but synchronisation stops. You can reconnect later."
                confirmText="Disconnect"
                processing={disconnecting}
                onConfirm={confirmDisconnect}
            />
        </AppLayout>
    );
}
