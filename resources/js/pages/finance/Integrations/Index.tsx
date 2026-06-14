import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm, router } from '@inertiajs/react';
import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
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
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { RefreshCw, Plus, Settings, Trash2, Link2, AlertCircle, CheckCircle2, Clock, XCircle, Plug } from 'lucide-react';
import { FormEvent, useState } from 'react';

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
    settings: Record<string, any>;
    recent_logs: SyncLog[];
};

type PageProps = {
    integrations: Integration[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance/dashboard' },
    { title: 'Integrations', href: '/finance/integrations' },
];

const providerLabels: Record<string, string> = {
    xero: 'Xero',
    myob: 'MYOB',
};

const syncDirectionLabels: Record<string, string> = {
    push: 'Push Only',
    pull: 'Pull Only',
    bidirectional: 'Bidirectional',
};

const statusIcons: Record<string, React.ReactNode> = {
    success: <CheckCircle2 className="h-4 w-4 text-status-success" />,
    failed: <XCircle className="h-4 w-4 text-status-critical" />,
    pending: <Clock className="h-4 w-4 text-status-warning" />,
};

const statusColors: Record<string, string> = {
    success: 'bg-status-success-bg text-status-success border-status-success/30',
    failed: 'bg-status-critical-bg text-status-critical border-status-critical/30',
    pending: 'bg-status-warning-bg text-status-warning border-status-warning/30',
};

function CreateIntegrationDialog() {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        provider: '' as string,
        tenant_id: '',
        sync_direction: 'bidirectional',
    });

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        post('/finance/integrations', {
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
                    Connect Provider
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Connect Accounting Provider</DialogTitle>
                    <DialogDescription>
                        Set up a connection to Xero or MYOB for two-way GL synchronisation.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="provider">Provider *</Label>
                        <Select value={data.provider} onValueChange={(v) => setData('provider', v)}>
                            <SelectTrigger>
                                <SelectValue placeholder="Select a provider" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="xero">Xero</SelectItem>
                                <SelectItem value="myob">MYOB</SelectItem>
                            </SelectContent>
                        </Select>
                        {errors.provider && <p className="text-sm text-destructive">{errors.provider}</p>}
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="tenant_id">
                            {data.provider === 'myob' ? 'Company File URI' : 'Tenant ID'} (optional)
                        </Label>
                        <Input
                            id="tenant_id"
                            value={data.tenant_id}
                            onChange={(e) => setData('tenant_id', e.target.value)}
                            placeholder={data.provider === 'myob' ? 'MYOB company file URI' : 'Xero tenant ID'}
                        />
                        {errors.tenant_id && <p className="text-sm text-destructive">{errors.tenant_id}</p>}
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="sync_direction">Sync Direction *</Label>
                        <Select value={data.sync_direction} onValueChange={(v) => setData('sync_direction', v)}>
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="bidirectional">Bidirectional</SelectItem>
                                <SelectItem value="push">Push Only (local to external)</SelectItem>
                                <SelectItem value="pull">Pull Only (external to local)</SelectItem>
                            </SelectContent>
                        </Select>
                        {errors.sync_direction && <p className="text-sm text-destructive">{errors.sync_direction}</p>}
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Connecting...' : 'Connect'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function IntegrationCard({ integration }: { integration: Integration }) {
    const [syncing, setSyncing] = useState(false);
    const [testing, setTesting] = useState(false);

    function handleSync() {
        setSyncing(true);
        router.post(`/finance/integrations/${integration.id}/sync`, {}, {
            onFinish: () => setSyncing(false),
        });
    }

    function handleTest() {
        setTesting(true);
        router.post(`/finance/integrations/${integration.id}/test`, {}, {
            onFinish: () => setTesting(false),
        });
    }

    function handleDisconnect() {
        router.delete(`/finance/integrations/${integration.id}`);
    }

    return (
        <Card>
            <CardHeader>
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                            <Link2 className="h-5 w-5 text-primary" />
                        </div>
                        <div>
                            <CardTitle className="text-lg">{providerLabels[integration.provider]}</CardTitle>
                            <CardDescription>
                                {syncDirectionLabels[integration.sync_direction]}
                                {integration.tenant_id && ` \u00B7 ${integration.tenant_id}`}
                            </CardDescription>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <Badge variant={integration.is_active ? 'default' : 'secondary'}>
                            {integration.is_active ? 'Active' : 'Inactive'}
                        </Badge>
                        {integration.last_sync_status && (
                            <Badge variant="outline" className={statusColors[integration.last_sync_status]}>
                                {statusIcons[integration.last_sync_status]}
                                <span className="ml-1 capitalize">{integration.last_sync_status}</span>
                            </Badge>
                        )}
                    </div>
                </div>
            </CardHeader>
            <CardContent className="space-y-4">
                {/* Connection status */}
                <div className="grid grid-cols-3 gap-4 text-sm">
                    <div>
                        <span className="text-muted-foreground">Token Status</span>
                        <p className="font-medium">
                            {!integration.has_token ? (
                                <span className="text-status-warning">Not configured</span>
                            ) : integration.token_expired ? (
                                <span className="text-status-critical">Expired</span>
                            ) : (
                                <span className="text-status-success">Valid</span>
                            )}
                        </p>
                    </div>
                    <div>
                        <span className="text-muted-foreground">Last Sync</span>
                        <p className="font-medium">
                            {integration.last_sync_at || 'Never'}
                        </p>
                    </div>
                    <div>
                        <span className="text-muted-foreground">Total Syncs</span>
                        <p className="font-medium">{integration.sync_logs_count}</p>
                    </div>
                </div>

                {/* Error display */}
                {integration.last_error && (
                    <div className="flex items-start gap-2 rounded-md bg-destructive/10 p-3">
                        <AlertCircle className="mt-0.5 h-4 w-4 text-destructive shrink-0" />
                        <p className="text-sm text-destructive">{integration.last_error}</p>
                    </div>
                )}

                {/* Recent sync logs */}
                {integration.recent_logs.length > 0 && (
                    <div>
                        <h4 className="mb-2 text-sm font-medium text-muted-foreground">Recent Activity</h4>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Direction</TableHead>
                                    <TableHead>Entity</TableHead>
                                    <TableHead>Count</TableHead>
                                    <TableHead>Success</TableHead>
                                    <TableHead>Errors</TableHead>
                                    <TableHead>Duration</TableHead>
                                    <TableHead>When</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {integration.recent_logs.map((log) => (
                                    <TableRow key={log.id}>
                                        <TableCell>
                                            <Badge variant="outline" className="capitalize">
                                                {log.direction}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="capitalize">{log.entity_type}</TableCell>
                                        <TableCell>{log.entity_count}</TableCell>
                                        <TableCell className="text-status-success">{log.success_count}</TableCell>
                                        <TableCell className={log.error_count > 0 ? 'text-status-critical' : ''}>
                                            {log.error_count}
                                        </TableCell>
                                        <TableCell>
                                            {log.duration_ms ? `${(log.duration_ms / 1000).toFixed(1)}s` : '-'}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground text-xs">
                                            {log.started_at}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}

                {/* Actions */}
                <div className="flex items-center gap-2 border-t pt-4">
                    <Button
                        variant="default"
                        size="sm"
                        onClick={handleSync}
                        disabled={syncing || !integration.is_active}
                    >
                        <RefreshCw className={`mr-1 h-3 w-3 ${syncing ? 'animate-spin' : ''}`} />
                        {syncing ? 'Syncing...' : 'Sync Now'}
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={handleTest}
                        disabled={testing}
                    >
                        {testing ? 'Testing...' : 'Test Connection'}
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => router.visit(`/finance/integrations/${integration.id}/mapping`)}
                    >
                        <Settings className="mr-1 h-3 w-3" />
                        Account Mapping
                    </Button>

                    <div className="ml-auto">
                        <AlertDialog>
                            <AlertDialogTrigger asChild>
                                <Button variant="ghost" size="sm" className="text-destructive">
                                    <Trash2 className="mr-1 h-3 w-3" />
                                    Disconnect
                                </Button>
                            </AlertDialogTrigger>
                            <AlertDialogContent>
                                <AlertDialogHeader>
                                    <AlertDialogTitle>Disconnect {providerLabels[integration.provider]}?</AlertDialogTitle>
                                    <AlertDialogDescription>
                                        This will remove the integration connection. Your local data will not be affected,
                                        but synchronisation will stop. You can reconnect later.
                                    </AlertDialogDescription>
                                </AlertDialogHeader>
                                <AlertDialogFooter>
                                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                                    <AlertDialogAction onClick={handleDisconnect} className="bg-destructive text-destructive-foreground">
                                        Disconnect
                                    </AlertDialogAction>
                                </AlertDialogFooter>
                            </AlertDialogContent>
                        </AlertDialog>
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}

export default function IntegrationsIndex({ integrations }: PageProps) {
    const activeCount = integrations.filter((i) => i.is_active).length;
    const errorCount = integrations.filter((i) => i.last_sync_status === 'failed').length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Accounting Integrations" />

            <PageLayout
                hero={
                    <PageHero category="finance"
                        icon={Plug}
                        title="Accounting Integrations"
                        description="Connect to Xero or MYOB for two-way general ledger synchronisation"
                        stats={[
                            { label: 'Total', value: integrations.length },
                            { label: 'Active', value: activeCount },
                            { label: 'Failed', value: errorCount },
                        ]}
                        actions={<CreateIntegrationDialog />}
                    />
                }
            >
                {integrations.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center justify-center py-12">
                            <Link2 className="mb-4 h-12 w-12 text-muted-foreground/50" />
                            <h3 className="text-lg font-medium">No integrations connected</h3>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Connect Xero or MYOB to start synchronising your chart of accounts, journals and invoices.
                            </p>
                            <div className="mt-4">
                                <CreateIntegrationDialog />
                            </div>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="space-y-4">
                        {integrations.map((integration) => (
                            <IntegrationCard key={integration.id} integration={integration} />
                        ))}
                    </div>
                )}
            </PageLayout>
        </AppLayout>
    );
}
