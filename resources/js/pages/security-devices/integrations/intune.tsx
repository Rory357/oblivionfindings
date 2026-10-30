import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    PageHeader,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderSearch,
} from '@/components/page';
import { SecurityDevicesSectionTabs } from '@/components/security-devices/security-devices-section-tabs';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTimeLong } from '@/lib/datetime';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    CheckCircle2,
    Download,
    ExternalLink,
    KeyRound,
    Laptop,
    Plug,
    RefreshCw,
    ShieldCheck,
    Trash2,
} from 'lucide-react';
import { useMemo, useRef, useState, type FormEvent } from 'react';

export type IntuneConnectionStatus =
    | 'connected'
    | 'disconnected'
    | 'error'
    | 'disabled';

export interface IntuneProviderConnection {
    status: IntuneConnectionStatus;
    secret_last4?: string | null;
    last_tested_at?: string | null;
    last_synced_at?: string | null;
    directory_id?: string | null;
    client_id?: string | null;
    directory_id_configured: boolean;
    client_id_configured: boolean;
    endpoint_configured: boolean;
    discovered_at?: string | null;
}

export interface DiscoveredIntuneComputer {
    managed_device_id: string;
    device_name: string;
    operating_system: string;
    os_version: string | null;
    compliance_state: string | null;
    is_encrypted: boolean | null;
    manufacturer: string | null;
    model: string | null;
    serial_number: string | null;
    owner_type: string | null;
    last_sync_at: string | null;
    link_state: 'unlinked' | 'linked' | 'linked_restricted';
    linked_device: {
        id: number;
        name: string;
        href: string;
        site_name: string | null;
    } | null;
}

export interface IntuneSiteOption {
    id: number;
    name: string;
    type?: string | null;
}

export interface IntuneSyncLog {
    id: number;
    action: string;
    status: string;
    items_processed: number;
    items_created: number;
    items_updated: number;
    items_errored: number;
    failure_category?: string | null;
    started_at: string | null;
    completed_at?: string | null;
}

export interface IntuneIntegrationProps {
    actor_id: number;
    as_of: string;
    providerConnection: IntuneProviderConnection | null;
    discoveredDevices: DiscoveredIntuneComputer[];
    linkedDevicesCount: number;
    sites: IntuneSiteOption[];
    syncLogs: IntuneSyncLog[];
    can: {
        manage: boolean;
        import_devices: boolean;
    };
}

type FlashProps = {
    flash?: {
        success?: string | null;
        warning?: string | null;
        error?: string | null;
    };
};

type LinkFilter = 'all' | 'unlinked' | 'linked';

function connectionBadge(connection: IntuneProviderConnection | null): {
    label: string;
    variant: StatusVariant;
} {
    if (!connection) {
        return { label: 'Not set up', variant: 'neutral' };
    }

    switch (connection.status) {
        case 'connected':
            return { label: 'Connected', variant: 'success' };
        case 'error':
            return { label: 'Needs attention', variant: 'critical' };
        case 'disabled':
            return { label: 'Disabled', variant: 'warning' };
        default:
            return { label: 'Needs test', variant: 'warning' };
    }
}

function complianceBadge(complianceState: string | null): {
    label: string;
    variant: StatusVariant;
} {
    const normalized = (complianceState ?? '').trim().toLocaleLowerCase();
    switch (normalized) {
        case 'compliant':
            return { label: 'Compliant', variant: 'success' };
        case 'noncompliant':
            return { label: 'Non-compliant', variant: 'critical' };
        case 'ingraceperiod':
            return { label: 'In grace period', variant: 'warning' };
        case '':
        case 'unknown':
            return { label: 'Compliance unknown', variant: 'neutral' };
        default:
            return { label: complianceState ?? 'Unknown', variant: 'info' };
    }
}

function syncActionLabel(action: string): string {
    switch (action) {
        case 'discover_devices':
            return 'Discover Windows computers';
        case 'import_devices':
            return 'Import selected computers';
        case 'sync_linked_devices':
        case 'sync_devices':
            return 'Refresh linked computers';
        default:
            return action.replaceAll('_', ' ');
    }
}

function syncStatusBadge(status: string): {
    label: string;
    variant: StatusVariant;
} {
    switch (status) {
        case 'success':
            return { label: 'Completed', variant: 'success' };
        case 'partial':
            return { label: 'Partial', variant: 'warning' };
        case 'failed':
            return { label: 'Failed', variant: 'critical' };
        default:
            return { label: 'Running', variant: 'info' };
    }
}

export default function IntuneIntegrationPage(props: IntuneIntegrationProps) {
    const page = usePage<FlashProps>();
    const flash = page.props.flash;
    const connection = props.providerConnection;
    const isConnected = connection?.status === 'connected';

    const [search, setSearch] = useState('');
    const [linkFilter, setLinkFilter] = useState<LinkFilter>('all');
    const [selectedIds, setSelectedIds] = useState<string[]>([]);
    const [selectedSiteId, setSelectedSiteId] = useState<string>(
        props.sites[0] ? String(props.sites[0].id) : '',
    );
    const [busyAction, setBusyAction] = useState<
        'test' | 'discover' | 'import' | 'sync' | 'remove' | null
    >(null);
    const [showRotateSecret, setShowRotateSecret] = useState(false);
    const [confirmRemoveOpen, setConfirmRemoveOpen] = useState(false);
    const connectionCardRef = useRef<HTMLDivElement | null>(null);
    const discoveredCardRef = useRef<HTMLDivElement | null>(null);
    const syncCardRef = useRef<HTMLDivElement | null>(null);

    const credentialsForm = useForm({
        directory_id: connection?.directory_id ?? '',
        client_id: connection?.client_id ?? '',
        client_secret: '',
    });

    const rotateForm = useForm({
        client_secret: '',
    });

    const unlinkedTotal = useMemo(
        () =>
            props.discoveredDevices.filter(
                (item) => item.link_state === 'unlinked',
            ).length,
        [props.discoveredDevices],
    );

    const filteredDevices = useMemo(() => {
        const needle = search.trim().toLocaleLowerCase();

        return props.discoveredDevices.filter((item) => {
            if (linkFilter === 'unlinked' && item.link_state !== 'unlinked') {
                return false;
            }
            if (linkFilter === 'linked' && item.link_state === 'unlinked') {
                return false;
            }
            if (!needle) {
                return true;
            }

            const haystack = [
                item.device_name,
                item.manufacturer ?? '',
                item.model ?? '',
                item.serial_number ?? '',
                item.operating_system,
                item.os_version ?? '',
                item.managed_device_id,
                item.linked_device?.site_name ?? '',
            ]
                .join(' ')
                .toLocaleLowerCase();

            return haystack.includes(needle);
        });
    }, [linkFilter, props.discoveredDevices, search]);

    const selectedSet = useMemo(() => new Set(selectedIds), [selectedIds]);

    const selectableFilteredIds = useMemo(
        () =>
            filteredDevices
                .filter((item) => item.link_state === 'unlinked')
                .map((item) => item.managed_device_id),
        [filteredDevices],
    );

    const allFilteredUnlinkedSelected =
        selectableFilteredIds.length > 0 &&
        selectableFilteredIds.every((id) => selectedSet.has(id));

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Home', href: '/dashboard' },
        { title: 'Security & Devices', href: '/security-devices' },
        { title: 'Integrations', href: '/security-devices/integrations' },
        {
            title: 'Microsoft Intune',
            href: '/security-devices/integrations/intune',
        },
    ];

    const statusBadge = connectionBadge(connection);

    function handleSaveCredentials(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        credentialsForm.post('/security-devices/integrations/intune/key', {
            preserveScroll: true,
            onSuccess: () => {
                credentialsForm.reset('client_secret');
            },
        });
    }

    function handleRotateSecret(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        rotateForm.post('/security-devices/integrations/intune/rotate', {
            preserveScroll: true,
            onSuccess: () => {
                rotateForm.reset();
                setShowRotateSecret(false);
            },
        });
    }

    function handleTestConnection() {
        setBusyAction('test');
        router.post(
            '/security-devices/integrations/intune/test',
            {},
            {
                preserveScroll: true,
                onFinish: () => setBusyAction(null),
            },
        );
    }

    function handleDiscover() {
        setBusyAction('discover');
        router.post(
            '/security-devices/integrations/intune/discover',
            {},
            {
                preserveScroll: true,
                onFinish: () => setBusyAction(null),
            },
        );
    }

    function handleSyncLinked() {
        setBusyAction('sync');
        router.post(
            '/security-devices/integrations/intune/sync',
            {},
            {
                preserveScroll: true,
                onFinish: () => setBusyAction(null),
            },
        );
    }

    function handleImportSelected() {
        if (!selectedSiteId || selectedIds.length === 0) {
            return;
        }
        setBusyAction('import');
        router.post(
            '/security-devices/integrations/intune/import',
            {
                site_id: Number(selectedSiteId),
                managed_device_ids: selectedIds,
            },
            {
                preserveScroll: true,
                onSuccess: () => setSelectedIds([]),
                onFinish: () => setBusyAction(null),
            },
        );
    }

    function handleConfirmRemove() {
        setBusyAction('remove');
        router.delete('/security-devices/integrations/intune/key', {
            preserveScroll: true,
            onFinish: () => {
                setBusyAction(null);
                setConfirmRemoveOpen(false);
            },
        });
    }

    function toggleOne(managedDeviceId: string, checked: boolean) {
        setSelectedIds((current) => {
            if (checked) {
                return current.includes(managedDeviceId)
                    ? current
                    : [...current, managedDeviceId];
            }

            return current.filter((item) => item !== managedDeviceId);
        });
    }

    function toggleSelectAllFilteredUnlinked() {
        if (allFilteredUnlinkedSelected) {
            const removeSet = new Set(selectableFilteredIds);
            setSelectedIds((current) =>
                current.filter((id) => !removeSet.has(id)),
            );
            return;
        }

        setSelectedIds((current) =>
            Array.from(new Set([...current, ...selectableFilteredIds])),
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Microsoft Intune - Security & Devices" />

            <div className="space-y-5" data-testid="intune-integration-page">
                <SecurityDevicesSectionTabs
                    section="setup"
                    activeTab="integrations"
                />

                <PageHeader
                    icon={Laptop}
                    title="Microsoft Intune"
                    subline="Read-only discovery, bulk Site import, and compliance posture for Windows 10, Windows 11, and Windows Server computers"
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            {isConnected && props.can.manage ? (
                                <>
                                    <Button
                                        type="button"
                                        size="sm"
                                        onClick={handleDiscover}
                                        disabled={busyAction !== null}
                                    >
                                        <RefreshCw className="mr-1.5 h-4 w-4" />
                                        {busyAction === 'discover'
                                            ? 'Scanning Intune…'
                                            : 'Discover Windows computers'}
                                    </Button>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={handleSyncLinked}
                                        disabled={busyAction !== null}
                                    >
                                        <ShieldCheck className="mr-1.5 h-4 w-4" />
                                        {busyAction === 'sync'
                                            ? 'Refreshing…'
                                            : 'Refresh linked computers'}
                                    </Button>
                                </>
                            ) : null}
                            <Button asChild size="sm" variant="outline">
                                <Link href="/security-devices/agent-downloads">
                                    <Download className="mr-1.5 h-4 w-4" />
                                    Windows installer & Obli
                                </Link>
                            </Button>
                        </div>
                    }
                    meters={[
                        <PageHeaderMeterBlock
                            key="connection"
                            label="Connection"
                            onClick={() =>
                                connectionCardRef.current?.scrollIntoView({
                                    behavior: 'smooth',
                                    block: 'start',
                                })
                            }
                            ariaLabel="Jump to Microsoft Entra credentials"
                        >
                            <div className="mt-1">
                                <StatusBadge variant={statusBadge.variant}>
                                    {statusBadge.label}
                                </StatusBadge>
                            </div>
                            <PageHeaderMeterCaption>
                                {connection?.last_tested_at
                                    ? `Tested ${formatDateTimeLong(connection.last_tested_at)}`
                                    : 'Read-only Microsoft Graph'}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>,
                        <PageHeaderMeterBlock
                            key="discovered"
                            label="Discovered computers"
                            onClick={() => {
                                setLinkFilter('all');
                                discoveredCardRef.current?.scrollIntoView({
                                    behavior: 'smooth',
                                    block: 'start',
                                });
                            }}
                            ariaLabel="Show all discovered Windows computers"
                        >
                            <PageHeaderMeterBig>
                                {props.discoveredDevices.length}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {unlinkedTotal} ready to import
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>,
                        <PageHeaderMeterBlock
                            key="linked"
                            label="Linked computers"
                            onClick={() => {
                                setLinkFilter('linked');
                                discoveredCardRef.current?.scrollIntoView({
                                    behavior: 'smooth',
                                    block: 'start',
                                });
                            }}
                            ariaLabel="Filter to computers already linked in Devices"
                        >
                            <PageHeaderMeterBig>
                                {props.linkedDevicesCount}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                Synced every 30 minutes
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>,
                        <PageHeaderMeterBlock
                            key="last-sync"
                            label="Last sync"
                            onClick={() =>
                                (
                                    syncCardRef.current ??
                                    discoveredCardRef.current
                                )?.scrollIntoView({
                                    behavior: 'smooth',
                                    block: 'start',
                                })
                            }
                            ariaLabel="Jump to recent Intune sync activity"
                        >
                            <div className="mt-1 text-sm font-semibold text-band-foreground">
                                {connection?.last_synced_at
                                    ? formatDateTimeLong(
                                          connection.last_synced_at,
                                      )
                                    : 'Never'}
                            </div>
                            <PageHeaderMeterCaption>
                                Compliance & encryption posture
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>,
                    ]}
                />

                {flash?.success ? (
                    <Alert>
                        <CheckCircle2 className="h-4 w-4" />
                        <AlertTitle>Done</AlertTitle>
                        <AlertDescription>{flash.success}</AlertDescription>
                    </Alert>
                ) : null}

                {flash?.warning ? (
                    <Alert>
                        <AlertTitle>Check details</AlertTitle>
                        <AlertDescription>{flash.warning}</AlertDescription>
                    </Alert>
                ) : null}

                {flash?.error ? (
                    <Alert variant="destructive">
                        <AlertTitle>Action could not complete</AlertTitle>
                        <AlertDescription>{flash.error}</AlertDescription>
                    </Alert>
                ) : null}

                <Card ref={connectionCardRef}>
                    <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div className="space-y-1">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <KeyRound className="h-4 w-4 text-muted-foreground" />
                                Microsoft Entra read-only app credentials
                            </CardTitle>
                            <CardDescription>
                                Register an app in Microsoft Entra, grant the
                                read-only Microsoft Graph application permission{' '}
                                <code className="rounded bg-muted px-1.5 py-0.5 text-xs">
                                    DeviceManagementManagedDevices.Read.All
                                </code>{' '}
                                with admin consent, then enter the directory ID,
                                client ID, and client secret below.
                            </CardDescription>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <StatusBadge variant={statusBadge.variant}>
                                {statusBadge.label}
                            </StatusBadge>
                            {connection?.secret_last4 ? (
                                <span className="text-caption">
                                    Secret ending ••••{connection.secret_last4}
                                </span>
                            ) : null}
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <form
                            onSubmit={handleSaveCredentials}
                            className="grid gap-4 md:grid-cols-3"
                        >
                            <div className="space-y-1.5">
                                <Label htmlFor="intune-directory-id">
                                    Microsoft directory ID
                                </Label>
                                <Input
                                    id="intune-directory-id"
                                    value={credentialsForm.data.directory_id}
                                    onChange={(event) =>
                                        credentialsForm.setData(
                                            'directory_id',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="00000000-0000-0000-0000-000000000000"
                                    autoComplete="off"
                                    disabled={!props.can.manage}
                                />
                                {credentialsForm.errors.directory_id ? (
                                    <p className="text-xs text-destructive">
                                        {credentialsForm.errors.directory_id}
                                    </p>
                                ) : (
                                    <p className="text-caption">
                                        UUID shown on your Microsoft Entra app
                                        overview page.
                                    </p>
                                )}
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="intune-client-id">
                                    Application (client) ID
                                </Label>
                                <Input
                                    id="intune-client-id"
                                    value={credentialsForm.data.client_id}
                                    onChange={(event) =>
                                        credentialsForm.setData(
                                            'client_id',
                                            event.target.value,
                                        )
                                    }
                                    placeholder="00000000-0000-0000-0000-000000000000"
                                    autoComplete="off"
                                    disabled={!props.can.manage}
                                />
                                {credentialsForm.errors.client_id ? (
                                    <p className="text-xs text-destructive">
                                        {credentialsForm.errors.client_id}
                                    </p>
                                ) : (
                                    <p className="text-caption">
                                        Application (client) UUID for the
                                        read-only connector.
                                    </p>
                                )}
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="intune-client-secret">
                                    Client secret
                                </Label>
                                <Input
                                    id="intune-client-secret"
                                    type="password"
                                    value={credentialsForm.data.client_secret}
                                    onChange={(event) =>
                                        credentialsForm.setData(
                                            'client_secret',
                                            event.target.value,
                                        )
                                    }
                                    placeholder={
                                        connection?.secret_last4
                                            ? `Replace secret ending ••••${connection.secret_last4}`
                                            : 'Paste client secret value'
                                    }
                                    autoComplete="new-password"
                                    disabled={!props.can.manage}
                                />
                                {credentialsForm.errors.client_secret ? (
                                    <p className="text-xs text-destructive">
                                        {credentialsForm.errors.client_secret}
                                    </p>
                                ) : (
                                    <p className="text-caption">
                                        Stored in encrypted secret storage and
                                        never shown again.
                                    </p>
                                )}
                            </div>

                            <div className="flex flex-wrap items-center gap-2 md:col-span-3">
                                <Button
                                    type="submit"
                                    disabled={
                                        !props.can.manage ||
                                        credentialsForm.processing
                                    }
                                >
                                    {credentialsForm.processing
                                        ? 'Saving…'
                                        : connection
                                          ? 'Save & replace credentials'
                                          : 'Save credentials'}
                                </Button>

                                {connection ? (
                                    <>
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            onClick={handleTestConnection}
                                            disabled={busyAction !== null}
                                        >
                                            <Plug className="mr-1.5 h-4 w-4" />
                                            {busyAction === 'test'
                                                ? 'Testing…'
                                                : 'Test connection'}
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() =>
                                                setShowRotateSecret(
                                                    (current) => !current,
                                                )
                                            }
                                            disabled={busyAction !== null}
                                        >
                                            Rotate secret only
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            className="text-destructive hover:text-destructive"
                                            onClick={() =>
                                                setConfirmRemoveOpen(true)
                                            }
                                            disabled={busyAction !== null}
                                        >
                                            <Trash2 className="mr-1.5 h-4 w-4" />
                                            Remove credentials
                                        </Button>
                                    </>
                                ) : null}
                            </div>
                        </form>

                        {connection && showRotateSecret ? (
                            <form
                                onSubmit={handleRotateSecret}
                                className="flex flex-col gap-3 rounded-lg border border-border bg-muted/30 p-3 sm:flex-row sm:items-end"
                            >
                                <div className="flex-1 space-y-1.5">
                                    <Label htmlFor="intune-rotate-secret">
                                        New client secret
                                    </Label>
                                    <Input
                                        id="intune-rotate-secret"
                                        type="password"
                                        value={rotateForm.data.client_secret}
                                        onChange={(event) =>
                                            rotateForm.setData(
                                                'client_secret',
                                                event.target.value,
                                            )
                                        }
                                        placeholder="Paste replacement client secret value"
                                        autoComplete="new-password"
                                    />
                                    {rotateForm.errors.client_secret ? (
                                        <p className="text-xs text-destructive">
                                            {rotateForm.errors.client_secret}
                                        </p>
                                    ) : null}
                                </div>
                                <Button
                                    type="submit"
                                    disabled={rotateForm.processing}
                                >
                                    {rotateForm.processing
                                        ? 'Rotating…'
                                        : 'Save new secret'}
                                </Button>
                            </form>
                        ) : null}
                    </CardContent>
                </Card>

                <Card ref={discoveredCardRef}>
                    <CardHeader className="space-y-3">
                        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <div className="space-y-1">
                                <CardTitle className="text-base">
                                    Windows computers in Microsoft Intune
                                </CardTitle>
                                <CardDescription>
                                    Select computers to add to Devices and
                                    assign to a Site. Once imported, open a
                                    computer in Devices to generate a one-time{' '}
                                    <code className="rounded bg-muted px-1 py-0.5 text-xs">
                                        ofc1_
                                    </code>{' '}
                                    setup code for the Windows service and Obli
                                    tray helper.
                                </CardDescription>
                            </div>
                            <PageHeaderSearch
                                value={search}
                                onChange={setSearch}
                                ariaLabel="Search Intune Windows computers"
                                placeholder="Search computer, model, or serial…"
                            />
                        </div>

                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div className="flex flex-wrap items-center gap-1.5">
                                {(
                                    [
                                        {
                                            key: 'all',
                                            label: `All (${props.discoveredDevices.length})`,
                                        },
                                        {
                                            key: 'unlinked',
                                            label: `Ready to import (${unlinkedTotal})`,
                                        },
                                        {
                                            key: 'linked',
                                            label: `Already in Devices (${props.discoveredDevices.length - unlinkedTotal})`,
                                        },
                                    ] as const
                                ).map((pill) => (
                                    <Button
                                        key={pill.key}
                                        type="button"
                                        size="sm"
                                        variant={
                                            linkFilter === pill.key
                                                ? 'secondary'
                                                : 'ghost'
                                        }
                                        onClick={() => setLinkFilter(pill.key)}
                                    >
                                        {pill.label}
                                    </Button>
                                ))}
                            </div>

                            {props.can.import_devices &&
                            props.discoveredDevices.length > 0 ? (
                                <div className="flex flex-wrap items-center gap-2">
                                    {selectableFilteredIds.length > 0 ? (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={
                                                toggleSelectAllFilteredUnlinked
                                            }
                                        >
                                            {allFilteredUnlinkedSelected
                                                ? 'Clear ready selection'
                                                : `Select all ready (${selectableFilteredIds.length})`}
                                        </Button>
                                    ) : null}

                                    <div className="w-56">
                                        <Select
                                            value={selectedSiteId}
                                            onValueChange={setSelectedSiteId}
                                        >
                                            <SelectTrigger aria-label="Target Site for imported computers">
                                                <SelectValue placeholder="Choose a Site…" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {props.sites.map((site) => (
                                                    <SelectItem
                                                        key={site.id}
                                                        value={String(site.id)}
                                                    >
                                                        {site.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <Button
                                        type="button"
                                        size="sm"
                                        onClick={handleImportSelected}
                                        disabled={
                                            busyAction !== null ||
                                            selectedIds.length === 0 ||
                                            !selectedSiteId
                                        }
                                    >
                                        {busyAction === 'import'
                                            ? 'Importing…'
                                            : `Import selected (${selectedIds.length})`}
                                    </Button>
                                </div>
                            ) : null}
                        </div>
                    </CardHeader>

                    <CardContent>
                        {filteredDevices.length === 0 ? (
                            <EmptyState
                                icon={Laptop}
                                title={
                                    props.discoveredDevices.length === 0
                                        ? 'No Windows computers scanned yet'
                                        : 'No matching Windows computers'
                                }
                                description={
                                    props.discoveredDevices.length === 0
                                        ? isConnected
                                            ? 'Click Discover Windows computers above to fetch Windows 10, Windows 11, and Windows Server devices from Microsoft Intune.'
                                            : 'Save and test your Microsoft Entra read-only credentials above, then scan Microsoft Intune for Windows computers.'
                                        : 'Try clearing the search box or switching the filter above.'
                                }
                            />
                        ) : (
                            <div className="overflow-x-auto rounded-lg border border-border">
                                <table className="w-full text-left text-sm">
                                    <thead className="border-b border-border bg-muted/40">
                                        <tr>
                                            {props.can.import_devices ? (
                                                <th className="w-10 px-3 py-2.5">
                                                    <span className="sr-only">
                                                        Select computer
                                                    </span>
                                                </th>
                                            ) : null}
                                            <th className="px-3 py-2.5 font-medium text-foreground">
                                                Computer
                                            </th>
                                            <th className="px-3 py-2.5 font-medium text-foreground">
                                                Windows & hardware
                                            </th>
                                            <th className="px-3 py-2.5 font-medium text-foreground">
                                                Compliance & encryption
                                            </th>
                                            <th className="px-3 py-2.5 font-medium text-foreground">
                                                Last Intune check-in
                                            </th>
                                            <th className="px-3 py-2.5 font-medium text-foreground">
                                                Status in Devices
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-border">
                                        {filteredDevices.map((computer) => {
                                            const compliance = complianceBadge(
                                                computer.compliance_state,
                                            );
                                            const checked = selectedSet.has(
                                                computer.managed_device_id,
                                            );
                                            const canSelect =
                                                computer.link_state !==
                                                'linked_restricted';

                                            return (
                                                <tr
                                                    key={
                                                        computer.managed_device_id
                                                    }
                                                    className="hover:bg-muted/30"
                                                >
                                                    {props.can
                                                        .import_devices ? (
                                                        <td className="px-3 py-3 align-top">
                                                            <Checkbox
                                                                checked={
                                                                    checked
                                                                }
                                                                disabled={
                                                                    !canSelect
                                                                }
                                                                aria-label={`Select ${computer.device_name}`}
                                                                onCheckedChange={(
                                                                    next,
                                                                ) =>
                                                                    toggleOne(
                                                                        computer.managed_device_id,
                                                                        next ===
                                                                            true,
                                                                    )
                                                                }
                                                            />
                                                        </td>
                                                    ) : null}
                                                    <td className="px-3 py-3 align-top">
                                                        <div className="font-medium text-foreground">
                                                            {
                                                                computer.device_name
                                                            }
                                                        </div>
                                                        <div className="text-caption">
                                                            {computer.serial_number
                                                                ? `Serial ${computer.serial_number}`
                                                                : `Intune ID ${computer.managed_device_id.slice(0, 8)}…`}
                                                        </div>
                                                    </td>
                                                    <td className="px-3 py-3 align-top">
                                                        <div className="text-foreground">
                                                            {
                                                                computer.operating_system
                                                            }
                                                            {computer.os_version
                                                                ? ` (${computer.os_version})`
                                                                : ''}
                                                        </div>
                                                        <div className="text-caption">
                                                            {[
                                                                computer.manufacturer,
                                                                computer.model,
                                                            ]
                                                                .filter(Boolean)
                                                                .join(' ') ||
                                                                'Model not reported'}
                                                        </div>
                                                    </td>
                                                    <td className="px-3 py-3 align-top">
                                                        <div className="flex flex-wrap items-center gap-1.5">
                                                            <StatusBadge
                                                                variant={
                                                                    compliance.variant
                                                                }
                                                            >
                                                                {
                                                                    compliance.label
                                                                }
                                                            </StatusBadge>
                                                            {computer.is_encrypted !==
                                                            null ? (
                                                                <StatusBadge
                                                                    variant={
                                                                        computer.is_encrypted
                                                                            ? 'success'
                                                                            : 'warning'
                                                                    }
                                                                >
                                                                    {computer.is_encrypted
                                                                        ? 'Encrypted'
                                                                        : 'Not encrypted'}
                                                                </StatusBadge>
                                                            ) : null}
                                                        </div>
                                                    </td>
                                                    <td className="px-3 py-3 align-top">
                                                        <span className="text-caption">
                                                            {computer.last_sync_at
                                                                ? formatDateTimeLong(
                                                                      computer.last_sync_at,
                                                                  )
                                                                : 'Not reported'}
                                                        </span>
                                                    </td>
                                                    <td className="px-3 py-3 align-top">
                                                        {computer.link_state ===
                                                        'unlinked' ? (
                                                            <StatusBadge variant="info">
                                                                Ready to import
                                                            </StatusBadge>
                                                        ) : computer.linked_device ? (
                                                            <div className="space-y-0.5">
                                                                <Link
                                                                    href={
                                                                        computer
                                                                            .linked_device
                                                                            .href
                                                                    }
                                                                    className="inline-flex items-center gap-1 font-medium text-foreground underline-offset-4 hover:underline"
                                                                >
                                                                    {
                                                                        computer
                                                                            .linked_device
                                                                            .name
                                                                    }
                                                                    <ExternalLink className="h-3.5 w-3.5 text-muted-foreground" />
                                                                </Link>
                                                                {computer
                                                                    .linked_device
                                                                    .site_name ? (
                                                                    <div className="text-caption">
                                                                        {
                                                                            computer
                                                                                .linked_device
                                                                                .site_name
                                                                        }
                                                                    </div>
                                                                ) : null}
                                                            </div>
                                                        ) : (
                                                            <StatusBadge variant="neutral">
                                                                Linked at
                                                                another Site
                                                            </StatusBadge>
                                                        )}
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {props.syncLogs.length > 0 ? (
                    <Card ref={syncCardRef}>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Recent Intune sync activity
                            </CardTitle>
                            <CardDescription>
                                Latest discovery, Site import, and 30-minute
                                posture sync runs.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="divide-y divide-border rounded-lg border border-border">
                                {props.syncLogs.map((log) => {
                                    const badge = syncStatusBadge(log.status);

                                    return (
                                        <div
                                            key={log.id}
                                            className="flex flex-col gap-2 px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between"
                                        >
                                            <div className="space-y-0.5">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="font-medium text-foreground">
                                                        {syncActionLabel(
                                                            log.action,
                                                        )}
                                                    </span>
                                                    <StatusBadge
                                                        variant={badge.variant}
                                                    >
                                                        {badge.label}
                                                    </StatusBadge>
                                                </div>
                                                <p className="text-caption">
                                                    Processed{' '}
                                                    {log.items_processed} ·
                                                    Added {log.items_created} ·
                                                    Updated {log.items_updated}{' '}
                                                    · Skipped{' '}
                                                    {log.items_errored}
                                                </p>
                                            </div>
                                            <span className="text-caption">
                                                {log.completed_at
                                                    ? formatDateTimeLong(
                                                          log.completed_at,
                                                      )
                                                    : log.started_at
                                                      ? formatDateTimeLong(
                                                            log.started_at,
                                                        )
                                                      : 'Just now'}
                                            </span>
                                        </div>
                                    );
                                })}
                            </div>
                        </CardContent>
                    </Card>
                ) : null}

                <ConfirmDialog
                    open={confirmRemoveOpen}
                    onClose={() => setConfirmRemoveOpen(false)}
                    onConfirm={handleConfirmRemove}
                    processing={busyAction === 'remove'}
                    title="Remove Microsoft Intune credentials?"
                    description="Existing imported computers stay in Devices, but automatic 30-minute Intune compliance updates will stop until credentials are added again."
                    confirmText="Remove credentials"
                    variant="destructive"
                />
            </div>
        </AppLayout>
    );
}
