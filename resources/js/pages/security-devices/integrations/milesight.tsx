import { ConfirmDialog } from '@/components/confirm-dialog';
import { EntityCard, EntityCardGrid } from '@/components/lists/entity-card';
import { EntityChip, EntityStatusChip } from '@/components/lists/entity-cells';
import {
    EntityContextMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageHeaderViewToggle,
    PageLayout,
} from '@/components/page';
import { RecordPicker } from '@/components/people-locations/record-picker';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { Field, StepHead } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import AppLayout from '@/layouts/app-layout';
import { formatDateTimeLong } from '@/lib/datetime';
import { type BreadcrumbItem } from '@/types';
import type { RequestPayload } from '@inertiajs/core';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle,
    ClipboardList,
    Eye,
    KeyRound,
    LayoutGrid,
    List,
    Loader2,
    MapPin,
    Plug,
    RefreshCw,
    Trash2,
    Webhook,
} from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import {
    SiteCredentialsCard,
    type SiteCredentialRow,
} from './site-credentials';

type ConnectionStatus = 'connected' | 'disconnected' | 'error';
type ProviderConnection = {
    status: ConnectionStatus;
    secret_last4?: string | null;
    last_tested_at?: string | null;
    last_synced_at?: string | null;
    endpoint_configured: boolean;
    client_id_configured: boolean;
    applications_synced_at?: string | null;
    webhook_configured: boolean;
    webhook_secret_last4?: string | null;
    webhook_url: string;
    last_webhook_received_at?: string | null;
} | null;
type DiscoveredApplication = {
    mapping_token: string;
    name: string;
    device_count?: number | null;
};
type SiteConfig = {
    id: number;
    site_id: number;
    site_name: string;
    site_type?: string | null;
    mapped_external_site_name?: string | null;
    is_active: boolean;
};
type SiteOption = { id: number; name: string; type?: string | null };
type SyncLog = {
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
};
export type MilesightProps = {
    actor_id: number;
    as_of: string;
    providerConnection: ProviderConnection;
    discoveredApplications: DiscoveredApplication[];
    siteConfigs: SiteConfig[];
    sites: SiteOption[];
    syncLogs: SyncLog[];
    siteCredentials: SiteCredentialRow[];
    can: { manage: boolean };
};
type SharedAuth = {
    auth?: {
        user?: { id: number } | null;
        can?: {
            securityDevices?: {
                viewAny?: boolean;
                integrationsManage?: boolean;
            };
        };
    };
};
type View =
    | 'connection'
    | 'applications'
    | 'webhook'
    | 'activity'
    | 'credentials';
type RecordEntry =
    | {
          key: string;
          kind: 'application';
          name: string;
          data: DiscoveredApplication;
      }
    | { key: string; kind: 'mapping'; name: string; data: SiteConfig }
    | { key: string; kind: 'log'; name: string; data: SyncLog }
    | {
          key: string;
          kind: 'credential';
          name: string;
          data: SiteCredentialRow;
      };
const base = '/security-devices/integrations/milesight';
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Security & Devices', href: '/security-devices' },
    { title: 'APIs & Integrations', href: '/security-devices/integrations' },
    { title: 'Milesight', href: base },
];
const views = [
    { key: 'connection' as const, label: 'Connection', icon: Plug },
    {
        key: 'applications' as const,
        label: 'Applications & Sites',
        icon: MapPin,
    },
    { key: 'webhook' as const, label: 'Webhook', icon: Webhook },
    { key: 'activity' as const, label: 'Recent activity', icon: ClipboardList },
    { key: 'credentials' as const, label: 'Site credentials', icon: KeyRound },
];
const stamp = (value?: string | null) =>
    value ? formatDateTimeLong(value, value) : 'Not recorded';
const needsAttention = (log: SyncLog) =>
    log.status === 'failed' ||
    log.items_errored > 0 ||
    Boolean(log.failure_category);
const connectionTone = (status?: ConnectionStatus): StatusVariant =>
    status === 'connected'
        ? 'success'
        : status === 'error'
          ? 'critical'
          : 'neutral';
const connectionLabel = (connection: ProviderConnection) =>
    !connection
        ? 'Not configured'
        : connection.status === 'connected'
          ? 'Connected'
          : connection.status === 'error'
            ? 'Needs attention'
            : 'Disconnected';
function authMatches(
    props: Partial<MilesightProps> & SharedAuth,
    actor: number,
) {
    return (
        props.actor_id === actor &&
        props.auth?.user?.id === actor &&
        props.can?.manage === true &&
        props.auth?.can?.securityDevices?.viewAny === true &&
        props.auth?.can?.securityDevices?.integrationsManage === true
    );
}
function responseSuccess(
    page: { props: Record<string, unknown> },
    actor: number,
): string | null {
    const props = page.props as Partial<MilesightProps> &
        SharedAuth & { flash?: { success?: unknown; error?: unknown } };
    return authMatches(props, actor) &&
        !props.flash?.error &&
        typeof props.flash?.success === 'string'
        ? props.flash.success
        : null;
}
export default function MilesightIntegration(props: MilesightProps) {
    const { auth } = usePage<SharedAuth>().props;
    // Partial metadata cannot transfer ownership of DTOs from an earlier page read.
    // A full visit remounts this page and establishes the new source actor.
    const [sourceActor] = useState(props.actor_id);
    const visible =
        authMatches({ ...props, auth }, auth?.user?.id ?? 0) &&
        props.actor_id > 0 &&
        props.actor_id === sourceActor;
    const hasConnection = props.providerConnection !== null;
    const [recordScope, setRecordScope] = useState({
        hasConnection,
        revision: 0,
    });
    if (recordScope.hasConnection !== hasConnection) {
        // Loss clears private forms; a newly saved record keeps its confirmed success pane.
        setRecordScope({
            hasConnection,
            revision: recordScope.revision + (hasConnection ? 0 : 1),
        });
    }
    // Current authority owns every private record, draft and confirmation.
    return (
        <MilesightBody
            key={JSON.stringify([
                auth?.user?.id,
                visible,
                recordScope.revision,
                props.sites.map((site) => site.id),
            ])}
            data={props}
            visible={visible}
        />
    );
}
function MilesightBody({
    data,
    visible: receivedVisible,
}: {
    data: MilesightProps;
    visible: boolean;
}) {
    const [denied, setDenied] = useState(false);
    const [view, setView] = useState<View>('connection');
    const [search, setSearch] = useState('');
    const [scope, setScope] = useState('all');
    const [layout, setLayout] = useState<'cards' | 'table'>('cards');
    const [pending, setPending] = useState<string | null>(null);
    const [notice, setNotice] = useState('');
    const [setup, setSetup] = useState(false);
    const [secretDialog, setSecretDialog] = useState<
        'rotate' | 'webhook' | null
    >(null);
    const [mappingToken, setMappingToken] = useState<string | null>(null);
    const [readerKey, setReaderKey] = useState<string | null>(null);
    const [removing, setRemoving] = useState<
        'key' | 'webhook' | SiteConfig | null
    >(null);
    const ctx = useEntityContextMenu<RecordEntry>();
    const live = useRef(true);
    const busy = useRef(false);
    const visible = receivedVisible && !denied;
    const {
        providerConnection: connection,
        discoveredApplications: applications,
        siteConfigs: mappings,
        syncLogs: logs,
        siteCredentials: credentials,
        sites,
    } = data;
    useEffect(() => {
        live.current = true;
        return () => {
            live.current = false;
        };
    }, []);
    useEffect(
        () =>
            router.on('invalid', (event) => {
                if (!live.current || !busy.current) return;
                const response = event.detail.response;
                const url = response.config.url;
                if (typeof url !== 'string') return;
                let target: URL;
                try {
                    target = new URL(url, window.location.origin);
                } catch {
                    return;
                }
                if (
                    target.origin !== window.location.origin ||
                    (target.pathname !== base &&
                        !target.pathname.startsWith(base + '/'))
                )
                    return;
                event.preventDefault();
                busy.current = false;
                setPending(null);
                if ([401, 403, 404].includes(response.status)) setDenied(true);
                else
                    setNotice(
                        'The request could not be completed. Review the current details and try again. Your unsent entries remain available.',
                    );
            }),
        [],
    );
    const selectView = (next: View, nextScope = 'all') => {
        setView(next);
        setScope(nextScope);
        setSearch('');
        ctx.close();
    };
    const options =
        view === 'connection'
            ? [
                  { value: 'all', label: 'All connection details' },
                  { value: 'credentials', label: 'Credentials' },
                  { value: 'history', label: 'Connection history' },
              ]
            : view === 'webhook'
              ? [
                    { value: 'all', label: 'All webhook details' },
                    { value: 'callback', label: 'Callback setup' },
                    { value: 'history', label: 'Verification history' },
                ]
              : view === 'applications'
                ? [
                      { value: 'all', label: 'Applications and mappings' },
                      {
                          value: 'application',
                          label: 'Discovered applications',
                      },
                      { value: 'mapping', label: 'Site mappings' },
                      { value: 'active', label: 'Active mappings' },
                  ]
                : view === 'activity'
                  ? [
                        { value: 'all', label: 'All returned attempts' },
                        { value: 'attention', label: 'Needs attention' },
                        ...Array.from(
                            new Set(logs.map((log) => log.status)),
                        ).map((status) => ({ value: status, label: status })),
                    ]
                  : [
                        { value: 'all', label: 'All Site credentials' },
                        { value: 'connected', label: 'Connected' },
                        { value: 'untested', label: 'Not tested' },
                        { value: 'disabled', label: 'Disabled' },
                        { value: 'error', label: 'Needs attention' },
                    ];
    const allRecords: RecordEntry[] = [
        ...applications.map(
            (application): RecordEntry => ({
                key: 'application:' + application.mapping_token,
                kind: 'application',
                name: application.name,
                data: application,
            }),
        ),
        ...mappings.map(
            (mapping): RecordEntry => ({
                key: 'mapping:' + mapping.id,
                kind: 'mapping',
                name: mapping.site_name,
                data: mapping,
            }),
        ),
        ...logs.map(
            (log): RecordEntry => ({
                key: 'log:' + log.id,
                kind: 'log',
                name: log.action,
                data: log,
            }),
        ),
        ...credentials.map(
            (credential): RecordEntry => ({
                key: 'credential:' + credential.id,
                kind: 'credential',
                name: credential.site_name,
                data: credential,
            }),
        ),
    ];
    const records = allRecords.filter((record) =>
        view === 'applications'
            ? ['application', 'mapping'].includes(record.kind)
            : view === 'activity'
              ? record.kind === 'log'
              : view === 'credentials' && record.kind === 'credential',
    );
    const selected = allRecords.find((record) => record.key === readerKey);
    const mappingApplication = applications.find(
        (application) => application.mapping_token === mappingToken,
    );
    const filtered = records.filter((record) => {
        if (scope !== 'all') {
            if (
                record.kind === 'log' &&
                (scope === 'attention'
                    ? !needsAttention(record.data)
                    : record.data.status !== scope)
            )
                return false;
            if (record.kind === 'credential' && record.data.state !== scope)
                return false;
            if (
                view === 'applications' &&
                (scope === 'active'
                    ? record.kind !== 'mapping' || !record.data.is_active
                    : record.kind !== scope)
            )
                return false;
        }
        return JSON.stringify([record.name, record.data])
            .toLowerCase()
            .includes(search.trim().toLowerCase());
    });
    const acceptResponse = (page: { props: Record<string, unknown> }) => {
        if (!live.current) return false;
        if (
            !authMatches(
                page.props as Partial<MilesightProps> & SharedAuth,
                data.actor_id,
            )
        ) {
            setDenied(true);
            return false;
        }
        return true;
    };
    const request = (path: string, payload: RequestPayload, label: string) => {
        if (!visible || busy.current) return;
        busy.current = true;
        setPending(label);
        setNotice('');
        router.post(base + path, payload, {
            preserveScroll: true,
            onSuccess: (page) => {
                acceptResponse(page);
            },
            onFinish: () => {
                if (live.current) {
                    busy.current = false;
                    setPending(null);
                }
            },
        });
    };
    const refresh = () => {
        if (busy.current) return;
        busy.current = true;
        setPending('refresh');
        setNotice('');
        router.visit(base, {
            method: 'get',
            preserveState: false,
            preserveScroll: true,
            onFinish: () => {
                if (live.current) {
                    busy.current = false;
                    setPending(null);
                }
            },
        });
    };
    const actionsFor = (record: RecordEntry): MenuItem[] => [
        {
            label: 'View evidence',
            icon: Eye,
            onClick: () => setReaderKey(record.key),
        },
        ...(record.kind === 'application'
            ? [
                  {
                      label: 'Map to Site',
                      icon: MapPin,
                      disabled:
                          sites.length === 0
                              ? 'No approved Sites are available.'
                              : pending
                                ? 'Wait for the current request.'
                                : undefined,
                      onClick: () => setMappingToken(record.data.mapping_token),
                  },
              ]
            : []),
        ...(record.kind === 'mapping'
            ? [
                  {
                      label: 'Sync Devices',
                      icon: RefreshCw,
                      disabled: !record.data.is_active
                          ? 'This mapping is inactive.'
                          : connection?.status !== 'connected'
                            ? 'Test the provider connection first.'
                            : pending
                              ? 'Wait for the current request.'
                              : undefined,
                      onClick: () =>
                          request(
                              '/devices/sync',
                              { site_config_id: record.data.id },
                              'devices:' + record.data.id,
                          ),
                  },
                  {
                      label: 'Remove mapping',
                      icon: Trash2,
                      danger: true,
                      disabled: pending
                          ? 'Wait for the current request.'
                          : undefined,
                      onClick: () => setRemoving(record.data),
                  },
              ]
            : []),
    ];
    const activeMappings = mappings.filter(
        (mapping) => mapping.is_active,
    ).length;
    const attention = logs.filter(needsAttention).length;
    const match = (value: string) =>
        value.toLowerCase().includes(search.trim().toLowerCase());
    const hasKey = connection?.client_id_configured === true;
    const connectionSearch =
        'API credentials OAuth client ID secret provider endpoint ' +
        JSON.stringify([
            connectionLabel(connection),
            connection?.secret_last4,
            connection?.client_id_configured,
            connection?.endpoint_configured,
        ]);
    const historySearch =
        'Connection history test sync discovery ' +
        JSON.stringify([
            connection?.last_tested_at,
            connection?.last_synced_at,
            connection?.applications_synced_at,
        ]);
    const callbackSearch =
        'Real-time monitoring webhook callback ' +
        (connection?.webhook_url ?? '');
    const webhookHistorySearch =
        'Signature verification enabled not configured webhook secret verified event ' +
        JSON.stringify([
            connection?.webhook_configured,
            connection?.webhook_secret_last4,
            connection?.last_webhook_received_at,
        ]);
    const showCredentials =
        (scope === 'all' || scope === 'credentials') && match(connectionSearch);
    const showConnectionHistory =
        (scope === 'all' || scope === 'history') && match(historySearch);
    const showCallback =
        (scope === 'all' || scope === 'callback') && match(callbackSearch);
    const showWebhookHistory =
        (scope === 'all' || scope === 'history') && match(webhookHistorySearch);
    const currentContext = allRecords.find(
        (record) => record.key === ctx.ctx?.record.key,
    );
    const currentRemoval =
        removing === 'key'
            ? hasKey
                ? 'key'
                : null
            : removing === 'webhook'
              ? connection?.webhook_configured
                  ? 'webhook'
                  : null
              : removing
                ? (mappings.find((mapping) => mapping.id === removing.id) ??
                  null)
                : null;
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Milesight Integration" />
            <PageLayout
                hero={
                    <PageHeader
                        title="Milesight Integration"
                        icon={Plug}
                        wrapTitle
                        titleChip={
                            visible ? (
                                <PageHeaderStatusChip
                                    variant={connectionTone(connection?.status)}
                                >
                                    {connectionLabel(connection)}
                                </PageHeaderStatusChip>
                            ) : undefined
                        }
                        subline={
                            visible
                                ? `OAuth inventory and signed callbacks · Page read ${stamp(data.as_of)}`
                                : 'Integration access unavailable'
                        }
                        actions={
                            visible ? (
                                <>
                                    <PageHeaderSearch
                                        value={search}
                                        onChange={setSearch}
                                        placeholder={`Search ${views.find((item) => item.key === view)?.label.toLowerCase()}…`}
                                    />
                                    <PageHeaderGlassButton
                                        icon={RefreshCw}
                                        onClick={refresh}
                                        disabled={pending !== null}
                                    >
                                        Refresh page
                                    </PageHeaderGlassButton>
                                    {view === 'applications' ? (
                                        <PageHeaderPrimaryButton
                                            icon={RefreshCw}
                                            disabled={
                                                connection?.status !==
                                                    'connected' ||
                                                pending !== null
                                            }
                                            onClick={() =>
                                                request(
                                                    '/applications/sync',
                                                    {},
                                                    'applications',
                                                )
                                            }
                                        >
                                            Discover applications
                                        </PageHeaderPrimaryButton>
                                    ) : view === 'webhook' ? (
                                        <PageHeaderPrimaryButton
                                            icon={Webhook}
                                            disabled={
                                                !hasKey || pending !== null
                                            }
                                            onClick={() =>
                                                setSecretDialog('webhook')
                                            }
                                        >
                                            {connection?.webhook_configured
                                                ? 'Replace webhook secret'
                                                : 'Set webhook secret'}
                                        </PageHeaderPrimaryButton>
                                    ) : (
                                        <PageHeaderPrimaryButton
                                            icon={KeyRound}
                                            disabled={pending !== null}
                                            onClick={() =>
                                                hasKey
                                                    ? setSecretDialog('rotate')
                                                    : setSetup(true)
                                            }
                                        >
                                            {hasKey
                                                ? 'Rotate secret'
                                                : 'Configure credentials'}
                                        </PageHeaderPrimaryButton>
                                    )}
                                </>
                            ) : undefined
                        }
                        meters={
                            visible ? (
                                <>
                                    <PageHeaderMeterBlock
                                        label="Discovered applications"
                                        onClick={() =>
                                            selectView(
                                                'applications',
                                                'application',
                                            )
                                        }
                                    >
                                        <PageHeaderMeterBig>
                                            {applications.length}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            Returned provider inventory
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    <PageHeaderMeterBlock
                                        label="Active Site mappings"
                                        value={activeMappings}
                                        onClick={() =>
                                            selectView('applications', 'active')
                                        }
                                    >
                                        {mappings.length ? (
                                            <PageHeaderMeterDonut
                                                percent={
                                                    (activeMappings /
                                                        mappings.length) *
                                                    100
                                                }
                                                caption={`${activeMappings} of ${mappings.length} mappings`}
                                            />
                                        ) : (
                                            <PageHeaderMeterBig>
                                                0
                                            </PageHeaderMeterBig>
                                        )}
                                        <PageHeaderMeterCaption>
                                            Approved Sites in this response
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    <PageHeaderMeterBlock
                                        label="Recent attempts"
                                        onClick={() => selectView('activity')}
                                    >
                                        <PageHeaderMeterBig>
                                            {logs.length}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            Latest returned attempts · up to 10
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    <PageHeaderMeterBlock
                                        label="Attempts needing attention"
                                        tone={attention ? 'critical' : 'brand'}
                                        onClick={() =>
                                            selectView('activity', 'attention')
                                        }
                                    >
                                        <PageHeaderMeterBig>
                                            {attention}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            Failure or errors in returned
                                            attempts
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    <PageHeaderMeterBlock
                                        label="Site credentials"
                                        onClick={() =>
                                            selectView('credentials')
                                        }
                                    >
                                        <PageHeaderMeterBig>
                                            {credentials.length}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            Approved Site credential records
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                </>
                            ) : undefined
                        }
                        filters={
                            visible ? (
                                <>
                                    <PageHeaderFilterSelect
                                        label="Show"
                                        value={scope}
                                        options={options}
                                        onChange={setScope}
                                    />
                                    {[
                                        'applications',
                                        'activity',
                                        'credentials',
                                    ].includes(view) && (
                                        <PageHeaderViewToggle
                                            value={layout}
                                            onChange={setLayout}
                                            options={[
                                                {
                                                    value: 'cards',
                                                    label: 'Cards',
                                                    icon: LayoutGrid,
                                                },
                                                {
                                                    value: 'table',
                                                    label: 'Table',
                                                    icon: List,
                                                },
                                            ]}
                                        />
                                    )}
                                </>
                            ) : undefined
                        }
                        rail={
                            visible ? (
                                <PageHeaderRail
                                    items={views}
                                    value={view}
                                    onSelect={(next) => selectView(next)}
                                />
                            ) : undefined
                        }
                    />
                }
            >
                {!visible ? (
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                Integration access unavailable
                            </CardTitle>
                            <CardDescription>
                                Your account or access has changed. Private
                                integration details and unsent forms have been
                                cleared. Reload this integration to load current
                                access.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Button
                                variant="outline"
                                onClick={refresh}
                                disabled={pending !== null}
                            >
                                <RefreshCw className="size-4" />
                                Reload integration
                            </Button>
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        {notice && (
                            <p
                                role="status"
                                className="rounded-lg border border-status-warning/30 bg-status-warning-bg p-4 text-sm"
                            >
                                {notice}
                            </p>
                        )}
                        {pending && (
                            <p
                                role="status"
                                className="flex items-center gap-2 text-sm text-muted-foreground"
                            >
                                <Loader2 className="size-4 animate-spin" />
                                Request in progress. The provider result will
                                appear after the response.
                            </p>
                        )}
                        {view === 'connection' && (
                            <>
                                {showCredentials && (
                                    <Card>
                                        <CardHeader>
                                            <CardTitle>
                                                API credentials
                                            </CardTitle>
                                            <CardDescription>
                                                Stored encrypted for this
                                                application. Only the last four
                                                characters are shown.
                                            </CardDescription>
                                        </CardHeader>
                                        <CardContent className="space-y-4">
                                            <ReviewRow
                                                label="Connection status"
                                                value={
                                                    <StatusBadge
                                                        variant={connectionTone(
                                                            connection?.status,
                                                        )}
                                                    >
                                                        {connectionLabel(
                                                            connection,
                                                        )}
                                                    </StatusBadge>
                                                }
                                            />
                                            <ReviewRow
                                                label="OAuth client ID"
                                                value={
                                                    connection?.client_id_configured
                                                        ? 'Configured'
                                                        : 'Not configured'
                                                }
                                            />
                                            <ReviewRow
                                                label="Client secret ending in"
                                                value={
                                                    connection?.secret_last4
                                                        ? '•••' +
                                                          connection.secret_last4
                                                        : 'Not recorded'
                                                }
                                            />
                                            <ReviewRow
                                                label="Provider endpoint"
                                                value={
                                                    connection?.endpoint_configured
                                                        ? 'Custom endpoint configured'
                                                        : 'Default Milesight Development Platform endpoint'
                                                }
                                            />
                                            {!hasKey && (
                                                <p className="text-sm text-muted-foreground">
                                                    Configure the OAuth client
                                                    ID and secret, then test the
                                                    connection before discovery.
                                                </p>
                                            )}
                                            <div className="flex flex-wrap gap-2">
                                                <Button
                                                    variant="outline"
                                                    disabled={
                                                        !hasKey ||
                                                        pending !== null
                                                    }
                                                    onClick={() =>
                                                        request(
                                                            '/test',
                                                            {},
                                                            'test',
                                                        )
                                                    }
                                                >
                                                    Test connection
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    disabled={
                                                        !hasKey ||
                                                        pending !== null
                                                    }
                                                    onClick={() =>
                                                        setRemoving('key')
                                                    }
                                                >
                                                    <Trash2 className="size-4" />
                                                    Remove credentials
                                                </Button>
                                            </div>
                                            {connection?.status === 'error' && (
                                                <p
                                                    role="status"
                                                    className="flex gap-2 text-sm text-status-critical"
                                                >
                                                    <AlertTriangle className="size-4 shrink-0" />
                                                    The provider connection
                                                    needs attention. Test the
                                                    connection or retry.
                                                </p>
                                            )}
                                        </CardContent>
                                    </Card>
                                )}
                                {showConnectionHistory && (
                                    <ReviewCard
                                        title="Connection history"
                                        icon={ClipboardList}
                                    >
                                        <ReviewRow
                                            label="Last tested"
                                            value={stamp(
                                                connection?.last_tested_at,
                                            )}
                                        />
                                        <ReviewRow
                                            label="Last sync"
                                            value={stamp(
                                                connection?.last_synced_at,
                                            )}
                                        />
                                        <ReviewRow
                                            label="Last discovered"
                                            value={stamp(
                                                connection?.applications_synced_at,
                                            )}
                                        />
                                        <p className="mt-3 text-sm text-muted-foreground">
                                            Page read time confirms when these
                                            details were returned. It does not
                                            establish fresh device readings, a
                                            live callback, or equipment health.
                                        </p>
                                    </ReviewCard>
                                )}
                                {search &&
                                    !showCredentials &&
                                    !showConnectionHistory && (
                                        <EmptyState>
                                            No connection details match this
                                            search.
                                        </EmptyState>
                                    )}
                                <Card>
                                    <CardHeader>
                                        <CardTitle>
                                            OAuth inventory and Device Registry
                                            sync
                                        </CardTitle>
                                        <CardDescription>
                                            Connect the Milesight Development
                                            Platform, discover applications, map
                                            each one to an approved Site, then
                                            import its gateways and sensors into
                                            the canonical Device Registry.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="space-y-3 text-sm">
                                        <p>
                                            Imported gateways and sensors use
                                            the same Device Registry as Sites,
                                            Client profiles, monitoring,
                                            maintenance and IT tickets.
                                        </p>
                                        <p className="text-muted-foreground">
                                            Resident support: bed occupancy,
                                            fall detection, panic button and
                                            door contact. Environmental:
                                            temperature, humidity, air quality,
                                            CO₂ and occupancy counting.
                                            Facilities: water leak, valve
                                            control, power monitoring and
                                            cold-chain temperature loggers.
                                            These classifications do not
                                            establish model-specific clinical or
                                            safety acceptance.
                                        </p>
                                    </CardContent>
                                </Card>
                            </>
                        )}
                        {view === 'webhook' && (
                            <>
                                {showCallback && (
                                    <Card>
                                        <CardHeader>
                                            <CardTitle
                                                role="heading"
                                                aria-level={2}
                                            >
                                                Real-time monitoring webhook
                                            </CardTitle>
                                            <CardDescription>
                                                Receive signed Milesight status,
                                                sensor and safety events through
                                                the native Monitoring runtime.
                                                Events remain linked to the
                                                canonical Device and Site.
                                            </CardDescription>
                                        </CardHeader>
                                        <CardContent className="space-y-4">
                                            <Field label="Callback URL">
                                                <Input
                                                    value={
                                                        connection?.webhook_url ??
                                                        'Save API credentials to create the callback.'
                                                    }
                                                    readOnly
                                                />
                                            </Field>
                                            <p className="text-sm text-muted-foreground">
                                                Add this HTTPS callback to the
                                                same Milesight Development
                                                Platform application that owns
                                                the mapped Devices. Then use
                                                Milesight's Test action.
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                Signed batches are
                                                replay-protected, matched to one
                                                current Device and Site, and
                                                queued through the common
                                                Monitoring event path. Unknown
                                                Devices and mismatched Sites are
                                                rejected before anything is
                                                stored.
                                            </p>
                                        </CardContent>
                                    </Card>
                                )}
                                {showWebhookHistory && (
                                    <Card>
                                        <CardHeader>
                                            <CardTitle>
                                                Webhook verification
                                            </CardTitle>
                                            <CardDescription>
                                                The OAuth client secret and
                                                webhook verification secret are
                                                separate.
                                            </CardDescription>
                                        </CardHeader>
                                        <CardContent className="space-y-4">
                                            <StatusBadge
                                                variant={
                                                    connection?.webhook_configured
                                                        ? 'success'
                                                        : 'neutral'
                                                }
                                            >
                                                {connection?.webhook_configured
                                                    ? 'Signature verification enabled'
                                                    : 'Not configured'}
                                            </StatusBadge>
                                            {connection?.webhook_configured && (
                                                <p className="text-sm">
                                                    Webhook secret ending in{' '}
                                                    {connection.webhook_secret_last4
                                                        ? '•••' +
                                                          connection.webhook_secret_last4
                                                        : 'Not recorded'}
                                                </p>
                                            )}
                                            <ReviewRow
                                                label="Last verified event"
                                                value={stamp(
                                                    connection?.last_webhook_received_at,
                                                )}
                                            />
                                            <p className="text-sm text-muted-foreground">
                                                Verification configuration does
                                                not confirm live delivery or the
                                                health of mapped equipment.
                                            </p>
                                            <Button
                                                variant="outline"
                                                disabled={
                                                    !connection?.webhook_configured ||
                                                    pending !== null
                                                }
                                                onClick={() =>
                                                    setRemoving('webhook')
                                                }
                                            >
                                                Disable webhook
                                            </Button>
                                        </CardContent>
                                    </Card>
                                )}
                                {search &&
                                    !showCallback &&
                                    !showWebhookHistory && (
                                        <EmptyState>
                                            No webhook details match this
                                            search.
                                        </EmptyState>
                                    )}
                            </>
                        )}
                        {['applications', 'activity', 'credentials'].includes(
                            view,
                        ) && (
                            <div className="space-y-5">
                                <ListCaption
                                    title={
                                        view === 'applications'
                                            ? 'Applications and Site mapping'
                                            : view === 'activity'
                                              ? 'Recent activity'
                                              : 'Site credentials'
                                    }
                                    caption={`${filtered.length} of ${records.length} shown`}
                                />
                                {view === 'applications' && (
                                    <p className="text-sm text-muted-foreground">
                                        Last discovered:{' '}
                                        {stamp(
                                            connection?.applications_synced_at,
                                        )}
                                        . Applications come from the bounded
                                        device inventory returned by Milesight.
                                        Map to an approved Site before import;
                                        imports cannot move a Device across
                                        Sites.
                                    </p>
                                )}
                                {view === 'activity' && (
                                    <p className="text-sm text-muted-foreground">
                                        Latest connection tests and sync
                                        attempts returned for this provider,
                                        capped at 10. Counts describe these
                                        attempts and are not a total Device
                                        count.
                                    </p>
                                )}
                                {filtered.length === 0 ? (
                                    <EmptyState>
                                        {records.length
                                            ? 'No records match the current search and filter.'
                                            : view === 'applications'
                                              ? 'No Milesight applications or Site mappings were returned. Test the connection, then discover applications.'
                                              : view === 'activity'
                                                ? 'No connection tests or sync attempts were returned.'
                                                : 'No Site credential records were returned for your approved Sites.'}
                                    </EmptyState>
                                ) : layout === 'table' ? (
                                    <EntityTable
                                        rows={filtered}
                                        rowKey={(record) => record.key}
                                        identityLabel="Record"
                                        wrapIdentity
                                        rowHeight="content"
                                        identity={(record) => ({
                                            name: record.name,
                                            icon:
                                                record.kind === 'mapping'
                                                    ? MapPin
                                                    : record.kind ===
                                                        'credential'
                                                      ? KeyRound
                                                      : ClipboardList,
                                            subline: recordSubtitle(record),
                                        })}
                                        columns={[
                                            {
                                                key: 'state',
                                                label: 'State / reported count',
                                                width: '1.1fr',
                                                cell: (record) =>
                                                    recordFacts(record),
                                            },
                                            {
                                                key: 'details',
                                                label: 'Returned evidence',
                                                width: '2fr',
                                                cell: (record) =>
                                                    recordSummary(record),
                                            },
                                        ]}
                                        actionsFor={actionsFor}
                                        onOpen={(record) =>
                                            setReaderKey(record.key)
                                        }
                                        onRowContextMenu={ctx.open}
                                    />
                                ) : (
                                    <EntityCardGrid>
                                        {filtered.map((record) => (
                                            <EntityCard
                                                key={record.key}
                                                name={record.name}
                                                subline={recordSubtitle(record)}
                                                icon={
                                                    record.kind === 'mapping'
                                                        ? MapPin
                                                        : record.kind ===
                                                            'credential'
                                                          ? KeyRound
                                                          : ClipboardList
                                                }
                                                meridian={
                                                    record.kind === 'log' &&
                                                    needsAttention(record.data)
                                                        ? 'critical'
                                                        : 'neutral'
                                                }
                                                actions={actionsFor(record)}
                                                onContextMenu={(event) =>
                                                    ctx.open(event, record)
                                                }
                                                onOpen={() =>
                                                    setReaderKey(record.key)
                                                }
                                                chips={recordFacts(record)}
                                                footer={{
                                                    primary:
                                                        recordSummary(record),
                                                    secondary:
                                                        'View all returned evidence',
                                                }}
                                            />
                                        ))}
                                    </EntityCardGrid>
                                )}
                            </div>
                        )}
                        {ctx.ctx && currentContext && (
                            <EntityContextMenu
                                x={ctx.ctx.x}
                                y={ctx.ctx.y}
                                title={currentContext.name}
                                items={actionsFor(currentContext)}
                                onClose={ctx.close}
                            />
                        )}
                        {selected && (
                            <RecordReader
                                record={selected}
                                onClose={() => setReaderKey(null)}
                            />
                        )}
                        {setup && (
                            <CredentialWizard
                                actor={data.actor_id}
                                acceptResponse={acceptResponse}
                                onClose={() => setSetup(false)}
                                begin={() => {
                                    busy.current = true;
                                }}
                                finish={() => {
                                    busy.current = false;
                                }}
                            />
                        )}
                        {secretDialog && hasKey && (
                            <SecretDialog
                                actor={data.actor_id}
                                acceptResponse={acceptResponse}
                                kind={secretDialog}
                                webhookConfigured={
                                    connection?.webhook_configured === true
                                }
                                onClose={() => setSecretDialog(null)}
                                begin={() => {
                                    busy.current = true;
                                }}
                                finish={() => {
                                    busy.current = false;
                                }}
                            />
                        )}
                        {mappingApplication && (
                            <MappingWizard
                                actor={data.actor_id}
                                acceptResponse={acceptResponse}
                                application={mappingApplication}
                                sites={sites}
                                onClose={() => setMappingToken(null)}
                                begin={() => {
                                    busy.current = true;
                                }}
                                finish={() => {
                                    busy.current = false;
                                }}
                            />
                        )}
                        <ConfirmDialog
                            open={currentRemoval !== null}
                            onClose={() => setRemoving(null)}
                            title={
                                currentRemoval === 'key'
                                    ? 'Remove Milesight credentials?'
                                    : currentRemoval === 'webhook'
                                      ? 'Disable Milesight webhook verification?'
                                      : 'Remove Milesight Site mapping?'
                            }
                            description={
                                currentRemoval === 'key'
                                    ? 'Devices already synced remain in the canonical registry. Discovery, future syncs and signed callbacks stop until credentials are configured again.'
                                    : currentRemoval === 'webhook'
                                      ? 'New callbacks will be rejected until a verified webhook secret is configured again. Existing Device and monitoring history is preserved.'
                                      : currentRemoval
                                        ? `Remove the application mapping for “${currentRemoval.site_name}”? Imported Devices remain in the canonical registry. This Site will no longer receive Milesight sync or monitoring updates through this mapping.`
                                        : ''
                            }
                            confirmText={
                                currentRemoval === 'key'
                                    ? 'Remove credentials'
                                    : currentRemoval === 'webhook'
                                      ? 'Disable webhook'
                                      : 'Remove mapping'
                            }
                            onConfirm={() => {
                                if (
                                    !visible ||
                                    busy.current ||
                                    currentRemoval === null
                                )
                                    return;
                                const target =
                                    currentRemoval === 'key'
                                        ? '/key'
                                        : currentRemoval === 'webhook'
                                          ? '/webhook'
                                          : '/applications/' +
                                            currentRemoval.id;
                                busy.current = true;
                                setPending('remove');
                                router.delete(base + target, {
                                    preserveScroll: true,
                                    onSuccess: (page) => {
                                        acceptResponse(page);
                                    },
                                    onFinish: () => {
                                        if (live.current) {
                                            busy.current = false;
                                            setPending(null);
                                            setRemoving(null);
                                        }
                                    },
                                });
                            }}
                        />
                    </>
                )}
            </PageLayout>
        </AppLayout>
    );
}
function EmptyState({ children }: { children: ReactNode }) {
    return (
        <Card>
            <CardContent className="py-8 text-sm text-muted-foreground">
                {children}
            </CardContent>
        </Card>
    );
}
function recordSubtitle(record: RecordEntry): string {
    if (record.kind === 'application')
        return 'Discovered Milesight application';
    if (record.kind === 'mapping')
        return `${record.data.site_type ?? 'Site type not recorded'} · Site #${record.data.site_id} · Mapping #${record.data.id}`;
    if (record.kind === 'credential')
        return `${record.data.capability} · Site #${record.data.site_id}`;
    return `Attempt #${record.data.id} · ${stamp(record.data.started_at)}`;
}
function recordFacts(record: RecordEntry) {
    if (record.kind === 'application')
        return (
            <EntityChip>
                {record.data.device_count == null
                    ? 'Device count not reported'
                    : `${record.data.device_count} devices reported`}
            </EntityChip>
        );
    if (record.kind === 'mapping')
        return (
            <EntityStatusChip
                variant={record.data.is_active ? 'success' : 'neutral'}
            >
                {record.data.is_active ? 'Active mapping' : 'Inactive mapping'}
            </EntityStatusChip>
        );
    if (record.kind === 'credential')
        return (
            <EntityStatusChip
                variant={
                    record.data.state === 'error'
                        ? 'critical'
                        : record.data.state === 'connected'
                          ? 'success'
                          : 'neutral'
                }
            >
                {record.data.state === 'untested'
                    ? 'Not tested'
                    : record.data.state}
            </EntityStatusChip>
        );
    return (
        <>
            <EntityStatusChip
                variant={
                    needsAttention(record.data)
                        ? 'critical'
                        : record.data.status === 'success'
                          ? 'success'
                          : 'neutral'
                }
            >
                {record.data.status}
            </EntityStatusChip>
            <EntityChip>{record.data.items_errored} errored</EntityChip>
        </>
    );
}
function recordSummary(record: RecordEntry): ReactNode {
    if (record.kind === 'application')
        return 'Site mapping uses the selected discovery record';
    if (record.kind === 'mapping')
        return (
            record.data.mapped_external_site_name ??
            'Application name not recorded'
        );
    if (record.kind === 'credential')
        return (
            <span className="break-words">
                {record.data.enabled ? 'Enabled' : 'Disabled'} · Last tested:{' '}
                {stamp(record.data.last_tested_at)}
            </span>
        );
    return (
        <span className="break-words">
            {record.data.items_processed} processed ·{' '}
            {record.data.items_created} created · {record.data.items_updated}{' '}
            updated
        </span>
    );
}
function RecordReader({
    record,
    onClose,
}: {
    record: RecordEntry;
    onClose: () => void;
}) {
    return (
        <WizardShell
            open
            onClose={onClose}
            title={`${record.name} evidence`}
            description="Safe fields returned for the current account and approved Sites."
            railIcon={Eye}
            railTitle="Returned evidence"
            railSub={record.name}
            steps={[
                {
                    key: 'evidence',
                    label: 'Evidence',
                    blurb: 'Current returned fields',
                    icon: ClipboardList,
                },
            ]}
            stepIndex={0}
            onStepClick={() => {}}
            sequential={false}
            headerLabel={record.name}
            footerEnd={
                <Button variant="outline" onClick={onClose}>
                    Close
                </Button>
            }
        >
            <WizardStepPane>
                {record.kind === 'credential' && (
                    <SiteCredentialsCard rows={[record.data]} />
                )}
                <ReviewCard title="Record details" icon={ClipboardList}>
                    <ReviewRow label="Name / action" value={record.name} />
                    {record.kind === 'application' && (
                        <>
                            <ReviewRow
                                label="Device count reported"
                                value={
                                    record.data.device_count ?? 'Not reported'
                                }
                            />
                            <ReviewRow
                                label="Mapping token"
                                value={record.data.mapping_token}
                            />
                        </>
                    )}
                    {record.kind === 'mapping' && (
                        <>
                            <ReviewRow
                                label="Mapping ID"
                                value={record.data.id}
                            />
                            <ReviewRow
                                label="Site ID"
                                value={record.data.site_id}
                            />
                            <ReviewRow
                                label="Site type"
                                value={record.data.site_type}
                            />
                            <ReviewRow
                                label="Milesight application"
                                value={record.data.mapped_external_site_name}
                            />
                            <ReviewRow
                                label="Mapping active"
                                value={record.data.is_active ? 'Yes' : 'No'}
                            />
                        </>
                    )}
                    {record.kind === 'log' && (
                        <>
                            <ReviewRow
                                label="Attempt ID"
                                value={record.data.id}
                            />
                            <ReviewRow
                                label="Status"
                                value={record.data.status}
                            />
                            <ReviewRow
                                label="Processed"
                                value={record.data.items_processed}
                            />
                            <ReviewRow
                                label="Created"
                                value={record.data.items_created}
                            />
                            <ReviewRow
                                label="Updated"
                                value={record.data.items_updated}
                            />
                            <ReviewRow
                                label="Errored"
                                value={record.data.items_errored}
                            />
                            <ReviewRow
                                label="Failure category"
                                value={record.data.failure_category}
                            />
                            <ReviewRow
                                label="Started"
                                value={stamp(record.data.started_at)}
                            />
                            <ReviewRow
                                label="Completed"
                                value={stamp(record.data.completed_at)}
                            />
                        </>
                    )}
                    {record.kind === 'credential' && (
                        <>
                            <ReviewRow
                                label="Credential ID"
                                value={record.data.id}
                            />
                            <ReviewRow
                                label="Site ID"
                                value={record.data.site_id}
                            />
                            <ReviewRow
                                label="Capability"
                                value={record.data.capability}
                            />
                            <ReviewRow
                                label="Enabled"
                                value={record.data.enabled ? 'Yes' : 'No'}
                            />
                            <ReviewRow
                                label="State"
                                value={record.data.state}
                            />
                            <ReviewRow
                                label="Failure category"
                                value={record.data.failure_category}
                            />
                            <ReviewRow
                                label="Last tested"
                                value={stamp(record.data.last_tested_at)}
                            />
                        </>
                    )}
                </ReviewCard>
            </WizardStepPane>
        </WizardShell>
    );
}
function CredentialWizard({
    actor,
    acceptResponse,
    onClose,
    begin,
    finish,
}: {
    actor: number;
    acceptResponse: (page: { props: Record<string, unknown> }) => boolean;
    onClose: () => void;
    begin: () => void;
    finish: () => void;
}) {
    const form = useForm({ client_id: '', client_secret: '', base_url: '' });
    const [step, setStep] = useState(0);
    const [discard, setDiscard] = useState(false);
    const [success, setSuccess] = useState<string | null>(null);
    const [notice, setNotice] = useState('');
    const alive = useRef(true);
    useEffect(
        () => () => {
            alive.current = false;
        },
        [],
    );
    const valid = Boolean(
        form.data.client_id.trim() &&
        form.data.client_secret &&
        (!form.data.base_url || /^https:\/\/[^\s]+$/.test(form.data.base_url)),
    );
    const close = () => {
        if (form.processing) return;
        if (!success && Object.values(form.data).some(Boolean))
            setDiscard(true);
        else onClose();
    };
    const submit = () => {
        if (!valid || form.processing) return;
        setNotice('');
        begin();
        form.post(base + '/key', {
            preserveScroll: true,
            onSuccess: (page) => {
                if (!alive.current || !acceptResponse(page)) return;
                const saved = responseSuccess(page, actor);
                if (saved) {
                    form.reset();
                    setSuccess(saved);
                } else
                    setNotice(
                        'Review the page response. Credentials have not been confirmed saved; your entries remain available.',
                    );
            },
            onFinish: finish,
        });
    };
    return (
        <>
            <WizardShell
                open
                onClose={close}
                title="Configure Milesight credentials"
                description="Configure OAuth credentials and review the provider endpoint before saving."
                railIcon={KeyRound}
                railTitle="Configure Milesight"
                railSub="OAuth credentials"
                steps={[
                    {
                        key: 'credentials',
                        label: 'Credentials',
                        blurb: 'Client and regional endpoint',
                        icon: KeyRound,
                    },
                    {
                        key: 'review',
                        label: 'Review',
                        blurb: 'Check before saving',
                        icon: CheckCircle,
                    },
                ]}
                stepIndex={step}
                onStepClick={setStep}
                sequential={false}
                pct={
                    (Number(Boolean(form.data.client_id)) +
                        Number(Boolean(form.data.client_secret))) *
                    50
                }
                footerStart={
                    <>
                        <Button
                            variant="ghost"
                            onClick={close}
                            disabled={form.processing}
                        >
                            Cancel
                        </Button>
                        {step > 0 && (
                            <Button
                                variant="outline"
                                onClick={() => setStep(0)}
                                disabled={form.processing}
                            >
                                Back
                            </Button>
                        )}
                    </>
                }
                footerEnd={
                    step === 0 ? (
                        <Button onClick={() => setStep(1)} disabled={!valid}>
                            Continue
                        </Button>
                    ) : (
                        <Button
                            onClick={submit}
                            disabled={!valid || form.processing}
                        >
                            {form.processing && (
                                <Loader2 className="size-4 animate-spin" />
                            )}
                            Save credentials
                        </Button>
                    )
                }
                success={
                    success ? (
                        <WizardSuccessPane
                            title="Credentials saved"
                            blurb={success}
                            actions={
                                <Button onClick={onClose}>
                                    Review connection
                                </Button>
                            }
                        />
                    ) : undefined
                }
            >
                <WizardStepPane>
                    {step === 0 ? (
                        <>
                            <StepHead
                                icon={KeyRound}
                                title="OAuth credentials"
                                blurb="Use the Milesight Development Platform client. The secret is stored encrypted."
                            />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    label="OAuth client ID"
                                    required
                                    error={form.errors.client_id}
                                >
                                    <Input
                                        value={form.data.client_id}
                                        maxLength={255}
                                        onChange={(event) =>
                                            form.setData(
                                                'client_id',
                                                event.target.value,
                                            )
                                        }
                                        autoComplete="off"
                                    />
                                </Field>
                                <Field
                                    label="OAuth client secret"
                                    required
                                    error={form.errors.client_secret}
                                >
                                    <Input
                                        type="password"
                                        autoComplete="new-password"
                                        value={form.data.client_secret}
                                        maxLength={4096}
                                        onChange={(event) =>
                                            form.setData(
                                                'client_secret',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Field
                                    label="Server URL"
                                    hint="Optional"
                                    span
                                    error={form.errors.base_url}
                                >
                                    <Input
                                        type="url"
                                        value={form.data.base_url}
                                        maxLength={255}
                                        placeholder="https://mdp-api.milesight.com"
                                        onChange={(event) =>
                                            form.setData(
                                                'base_url',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Field>
                            </div>
                            <p className="mt-4 text-sm text-muted-foreground">
                                Leave the URL empty for the default Milesight
                                Development Platform endpoint. A regional API
                                endpoint must use HTTPS.
                            </p>
                        </>
                    ) : (
                        <ReviewCard
                            title="OAuth configuration"
                            icon={KeyRound}
                            onEdit={() => setStep(0)}
                        >
                            <ReviewRow
                                label="Client ID"
                                value={form.data.client_id}
                            />
                            <ReviewRow
                                label="Client secret"
                                value={
                                    form.data.client_secret
                                        ? 'Entered · hidden'
                                        : 'Not entered'
                                }
                            />
                            <ReviewRow
                                label="Server URL"
                                value={
                                    form.data.base_url ||
                                    'Default Milesight Development Platform endpoint'
                                }
                            />
                            <p className="mt-3 text-sm text-muted-foreground">
                                Saving does not connect the provider or import
                                Devices. Test the connection after saving.
                            </p>
                            {Object.values(form.errors).map((error) => (
                                <p
                                    key={error}
                                    role="alert"
                                    className="mt-2 text-sm text-status-critical"
                                >
                                    {error}
                                </p>
                            ))}
                        </ReviewCard>
                    )}
                    {notice && (
                        <p
                            role="alert"
                            className="mt-3 text-sm text-status-warning"
                        >
                            {notice}
                        </p>
                    )}
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
                title="Discard credential entries?"
                description="These unsent entries are held only in this open form. Closing will clear them."
                confirmText="Discard entries"
            />
        </>
    );
}
function SecretDialog({
    actor,
    acceptResponse,
    kind,
    webhookConfigured,
    onClose,
    begin,
    finish,
}: {
    actor: number;
    acceptResponse: (page: { props: Record<string, unknown> }) => boolean;
    kind: 'rotate' | 'webhook';
    webhookConfigured: boolean;
    onClose: () => void;
    begin: () => void;
    finish: () => void;
}) {
    const form = useForm({ client_secret: '', webhook_secret: '' });
    const [notice, setNotice] = useState('');
    const [discard, setDiscard] = useState(false);
    const alive = useRef(true);
    useEffect(
        () => () => {
            alive.current = false;
        },
        [],
    );
    const field = kind === 'rotate' ? 'client_secret' : 'webhook_secret';
    const label =
        kind === 'rotate'
            ? 'New client secret'
            : webhookConfigured
              ? 'Replace webhook secret key'
              : 'Webhook secret key';
    const close = () => {
        if (form.processing) return;
        if (form.data[field]) setDiscard(true);
        else onClose();
    };
    return (
        <>
            <Dialog
                open
                onOpenChange={(open) => {
                    if (!open) close();
                }}
            >
                <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            {kind === 'rotate'
                                ? 'Rotate Milesight client secret'
                                : 'Configure webhook verification'}
                        </DialogTitle>
                        <DialogDescription>
                            {kind === 'rotate'
                                ? 'Replace the stored OAuth secret. Test the connection after saving.'
                                : 'Use the Milesight application webhook secret. It is separate from the OAuth client secret, stored encrypted and never displayed again.'}
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            if (
                                form.processing ||
                                (kind === 'webhook'
                                    ? form.data[field].length < 16
                                    : !form.data[field])
                            )
                                return;
                            const payload =
                                kind === 'rotate'
                                    ? { client_secret: form.data.client_secret }
                                    : {
                                          webhook_secret:
                                              form.data.webhook_secret,
                                      };
                            begin();
                            form.transform(() => payload);
                            form.post(
                                base +
                                    (kind === 'rotate'
                                        ? '/rotate'
                                        : '/webhook'),
                                {
                                    preserveScroll: true,
                                    onSuccess: (page) => {
                                        if (
                                            !alive.current ||
                                            !acceptResponse(page)
                                        )
                                            return;
                                        if (responseSuccess(page, actor)) {
                                            form.reset();
                                            onClose();
                                        } else
                                            setNotice(
                                                'The returned response did not confirm the secret was saved. Review the page notice and retry.',
                                            );
                                    },
                                    onFinish: finish,
                                },
                            );
                        }}
                        className="space-y-4"
                    >
                        <Field
                            label={label}
                            required
                            error={form.errors[field]}
                        >
                            <Input
                                type="password"
                                autoComplete="new-password"
                                value={form.data[field]}
                                maxLength={4096}
                                onChange={(event) =>
                                    form.setData(field, event.target.value)
                                }
                            />
                        </Field>
                        {kind === 'webhook' && (
                            <p className="text-sm text-muted-foreground">
                                Use at least 16 characters. Verification
                                configuration does not confirm live delivery.
                            </p>
                        )}
                        {notice && (
                            <p
                                role="alert"
                                className="text-sm text-status-warning"
                            >
                                {notice}
                            </p>
                        )}
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={close}
                                disabled={form.processing}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                disabled={
                                    form.processing ||
                                    (kind === 'webhook'
                                        ? form.data[field].length < 16
                                        : !form.data[field])
                                }
                            >
                                {form.processing && (
                                    <Loader2 className="size-4 animate-spin" />
                                )}
                                {kind === 'rotate'
                                    ? 'Save new key'
                                    : webhookConfigured
                                      ? 'Replace verification secret'
                                      : 'Enable verified webhook'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
                title="Discard secret entry?"
                description="Closing clears the unsent secret from this form."
                confirmText="Discard entry"
            />
        </>
    );
}
function MappingWizard({
    actor,
    acceptResponse,
    application,
    sites,
    onClose,
    begin,
    finish,
}: {
    actor: number;
    acceptResponse: (page: { props: Record<string, unknown> }) => boolean;
    application: DiscoveredApplication;
    sites: SiteOption[];
    onClose: () => void;
    begin: () => void;
    finish: () => void;
}) {
    const form = useForm({
        site_id: '',
        mapping_token: application.mapping_token,
    });
    const [step, setStep] = useState(0);
    const [discard, setDiscard] = useState(false);
    const [success, setSuccess] = useState<string | null>(null);
    const [notice, setNotice] = useState('');
    const alive = useRef(true);
    useEffect(
        () => () => {
            alive.current = false;
        },
        [],
    );
    const site = sites.find(
        (option) => String(option.id) === form.data.site_id,
    );
    const close = () => {
        if (form.processing) return;
        if (!success && form.data.site_id) setDiscard(true);
        else onClose();
    };
    return (
        <>
            <WizardShell
                open
                onClose={close}
                title="Map Milesight application to Site"
                description="Review the discovered application and its approved Site before saving the mapping."
                railIcon={MapPin}
                railTitle="Map application"
                railSub={application.name}
                steps={[
                    {
                        key: 'site',
                        label: 'Approved Site',
                        blurb: 'Choose canonical ownership',
                        icon: MapPin,
                    },
                    {
                        key: 'review',
                        label: 'Review',
                        blurb: 'Confirm mapping',
                        icon: CheckCircle,
                    },
                ]}
                stepIndex={step}
                onStepClick={setStep}
                sequential={false}
                pct={site ? 100 : 0}
                footerStart={
                    <>
                        <Button
                            variant="ghost"
                            onClick={close}
                            disabled={form.processing}
                        >
                            Cancel
                        </Button>
                        {step > 0 && (
                            <Button
                                variant="outline"
                                onClick={() => setStep(0)}
                                disabled={form.processing}
                            >
                                Back
                            </Button>
                        )}
                    </>
                }
                footerEnd={
                    step === 0 ? (
                        <Button onClick={() => setStep(1)} disabled={!site}>
                            Continue
                        </Button>
                    ) : (
                        <Button
                            disabled={!site || form.processing}
                            onClick={() => {
                                if (!site || form.processing) return;
                                begin();
                                form.transform((fields) => ({
                                    ...fields,
                                    site_id: site.id,
                                }));
                                form.post(base + '/applications/map', {
                                    preserveScroll: true,
                                    onSuccess: (page) => {
                                        if (
                                            !alive.current ||
                                            !acceptResponse(page)
                                        )
                                            return;
                                        const saved = responseSuccess(
                                            page,
                                            actor,
                                        );
                                        if (saved) setSuccess(saved);
                                        else
                                            setNotice(
                                                'The returned response did not confirm this mapping was saved. Review the page notice and retry.',
                                            );
                                    },
                                    onFinish: finish,
                                });
                            }}
                        >
                            {form.processing && (
                                <Loader2 className="size-4 animate-spin" />
                            )}
                            Map to Site
                        </Button>
                    )
                }
                success={
                    success ? (
                        <WizardSuccessPane
                            title="Site mapping saved"
                            blurb={success}
                            actions={
                                <Button onClick={onClose}>
                                    Review mappings
                                </Button>
                            }
                        />
                    ) : undefined
                }
            >
                <WizardStepPane>
                    {step === 0 ? (
                        <>
                            <StepHead
                                icon={MapPin}
                                title={application.name}
                                blurb="Select the approved Site that owns this application. Imports cannot move a Device across Sites."
                            />
                            <ReviewRow
                                label="Devices reported"
                                value={
                                    application.device_count ?? 'Not reported'
                                }
                            />
                            <div className="mt-4">
                                <Field
                                    label="Approved Site"
                                    required
                                    error={form.errors.site_id}
                                >
                                    <RecordPicker
                                        label={`Site for ${application.name}`}
                                        value={form.data.site_id}
                                        options={sites.map((option) => ({
                                            value: String(option.id),
                                            label: option.name,
                                            description: `${option.type ?? 'Site'} · #${option.id}`,
                                        }))}
                                        onChange={(value) =>
                                            form.setData('site_id', value)
                                        }
                                    />
                                </Field>
                            </div>
                        </>
                    ) : (
                        <ReviewCard
                            title="Site mapping"
                            icon={MapPin}
                            onEdit={() => setStep(0)}
                        >
                            <ReviewRow
                                label="Milesight application"
                                value={application.name}
                            />
                            <ReviewRow
                                label="Approved Site"
                                value={site?.name}
                            />
                            <ReviewRow label="Site ID" value={site?.id} />
                            <ReviewRow label="Site type" value={site?.type} />
                            <p className="mt-3 text-sm text-muted-foreground">
                                Mapping does not import Devices. Use Sync
                                Devices after the returned mapping is active and
                                the provider is connected.
                            </p>
                            {Object.values(form.errors).map((error) => (
                                <p
                                    key={error}
                                    role="alert"
                                    className="mt-2 text-sm text-status-critical"
                                >
                                    {error}
                                </p>
                            ))}
                        </ReviewCard>
                    )}
                    {notice && (
                        <p
                            role="alert"
                            className="mt-3 text-sm text-status-warning"
                        >
                            {notice}
                        </p>
                    )}
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
                title="Discard Site selection?"
                description="Closing clears this unsent mapping selection."
                confirmText="Discard selection"
            />
        </>
    );
}
