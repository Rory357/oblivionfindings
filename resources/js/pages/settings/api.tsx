import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Activity, Check, Copy, Key, Plus, ShieldCheck, Trash2, Webhook } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

type ApiKey = {
    id: string;
    name: string;
    maskedKey: string;
    created: string;
    lastUsed: string | null;
    status: 'active' | 'revoked';
    scopes: string[];
};

type WebhookEntry = {
    id: string;
    url: string;
    events: string[];
    status: 'active' | 'inactive';
    lastDelivery: string | null;
};

type ApiSettingsPageProps = {
    api_keys: ApiKey[];
    webhooks: WebhookEntry[];
    available_scopes: string[];
    available_events: string[];
    stats: {
        active_keys: number;
        revoked_keys: number;
        active_webhooks: number;
        successful_tests: number;
    };
    can: {
        manage: boolean;
    };
};

type StatusMessage = {
    type: 'success' | 'error';
    text: string;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings' },
    { title: 'Outbound API & Webhooks' },
];

function csrfToken(): string {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

async function requestJson<TResponse>(url: string, method: 'POST' | 'DELETE', body?: Record<string, unknown>): Promise<TResponse> {
    const response = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: body ? JSON.stringify(body) : undefined,
        credentials: 'same-origin',
    });

    const payload = await response.json().catch(() => ({}));

    if (!response.ok) {
        const message = typeof payload?.message === 'string' ? payload.message : 'Request failed.';
        throw new Error(message);
    }

    return payload as TResponse;
}

function slugify(value: string): string {
    return value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
}

export default function Api() {
    const { api_keys, webhooks: webhookProps, available_scopes, available_events, can } = usePage<ApiSettingsPageProps>().props;
    const [apiKeys, setApiKeys] = useState<ApiKey[]>(api_keys);
    const [webhooks, setWebhooks] = useState<WebhookEntry[]>(webhookProps);
    const [statusMessage, setStatusMessage] = useState<StatusMessage | null>(null);

    const [showGenerateKey, setShowGenerateKey] = useState(false);
    const [newKeyName, setNewKeyName] = useState('');
    const [newKeyScopes, setNewKeyScopes] = useState<string[]>([]);
    const [generatedKey, setGeneratedKey] = useState<string | null>(null);
    const [copied, setCopied] = useState(false);
    const [creatingKey, setCreatingKey] = useState(false);

    const [showAddWebhook, setShowAddWebhook] = useState(false);
    const [webhookUrl, setWebhookUrl] = useState('');
    const [webhookEvents, setWebhookEvents] = useState<string[]>([]);
    const [webhookSecret, setWebhookSecret] = useState('');
    const [creatingWebhook, setCreatingWebhook] = useState(false);
    const [testingWebhookId, setTestingWebhookId] = useState<string | null>(null);
    const [deletingWebhookId, setDeletingWebhookId] = useState<string | null>(null);
    const [revokingKeyId, setRevokingKeyId] = useState<string | null>(null);

    useEffect(() => {
        setApiKeys(api_keys);
    }, [api_keys]);

    useEffect(() => {
        setWebhooks(webhookProps);
    }, [webhookProps]);

    const usage = useMemo(() => ({
        activeKeys: apiKeys.filter((key) => key.status === 'active').length,
        revokedKeys: apiKeys.filter((key) => key.status === 'revoked').length,
        activeWebhooks: webhooks.filter((webhook) => webhook.status === 'active').length,
        successfulTests: webhooks.filter((webhook) => webhook.lastDelivery !== null).length,
    }), [apiKeys, webhooks]);

    function toggleScope(scope: string) {
        setNewKeyScopes((current) => current.includes(scope) ? current.filter((item) => item !== scope) : [...current, scope]);
    }

    function toggleEvent(event: string) {
        setWebhookEvents((current) => current.includes(event) ? current.filter((item) => item !== event) : [...current, event]);
    }

    function closeGenerateDialog() {
        setShowGenerateKey(false);
        setNewKeyName('');
        setNewKeyScopes([]);
        setGeneratedKey(null);
        setCopied(false);
    }

    function closeWebhookDialog() {
        setShowAddWebhook(false);
        setWebhookUrl('');
        setWebhookEvents([]);
        setWebhookSecret('');
    }

    async function handleGenerateKey() {
        setCreatingKey(true);

        try {
            const payload = await requestJson<{ message: string; generatedKey: string; apiKey: ApiKey }>('/settings/api/keys', 'POST', {
                name: newKeyName,
                scopes: newKeyScopes,
            });

            setApiKeys((current) => [...current, payload.apiKey]);
            setGeneratedKey(payload.generatedKey);
            setStatusMessage({ type: 'success', text: payload.message });
        } catch (error) {
            setStatusMessage({ type: 'error', text: error instanceof Error ? error.message : 'Could not generate API key.' });
        } finally {
            setCreatingKey(false);
        }
    }

    async function handleCopyKey() {
        if (!generatedKey) {
            return;
        }

        await navigator.clipboard.writeText(generatedKey);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    }

    async function revokeKey(id: string) {
        setRevokingKeyId(id);

        try {
            const payload = await requestJson<{ message: string; apiKey: ApiKey }>(`/settings/api/keys/${id}/revoke`, 'POST');
            setApiKeys((current) => current.map((key) => key.id === id ? payload.apiKey : key));
            setStatusMessage({ type: 'success', text: payload.message });
        } catch (error) {
            setStatusMessage({ type: 'error', text: error instanceof Error ? error.message : 'Could not revoke API key.' });
        } finally {
            setRevokingKeyId(null);
        }
    }

    async function handleAddWebhook() {
        setCreatingWebhook(true);

        try {
            const payload = await requestJson<{ message: string; secret: string; webhook: WebhookEntry }>('/settings/api/webhooks', 'POST', {
                url: webhookUrl,
                events: webhookEvents,
            });

            setWebhooks((current) => [...current, payload.webhook]);
            setWebhookSecret(payload.secret);
            setStatusMessage({ type: 'success', text: payload.message });
        } catch (error) {
            setStatusMessage({ type: 'error', text: error instanceof Error ? error.message : 'Could not add webhook.' });
        } finally {
            setCreatingWebhook(false);
        }
    }

    async function deleteWebhook(id: string) {
        setDeletingWebhookId(id);

        try {
            const payload = await requestJson<{ message: string }>(`/settings/api/webhooks/${id}`, 'DELETE');
            setWebhooks((current) => current.filter((webhook) => webhook.id !== id));
            setStatusMessage({ type: 'success', text: payload.message });
        } catch (error) {
            setStatusMessage({ type: 'error', text: error instanceof Error ? error.message : 'Could not delete webhook.' });
        } finally {
            setDeletingWebhookId(null);
        }
    }

    async function testWebhook(id: string) {
        setTestingWebhookId(id);

        try {
            const payload = await requestJson<{ message: string; webhook: WebhookEntry }>(`/settings/api/webhooks/${id}/test`, 'POST');
            setWebhooks((current) => current.map((webhook) => webhook.id === id ? payload.webhook : webhook));
            setStatusMessage({ type: 'success', text: payload.message });
        } catch (error) {
            setStatusMessage({ type: 'error', text: error instanceof Error ? error.message : 'Webhook test failed.' });
        } finally {
            setTestingWebhookId(null);
        }
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Outbound API & Webhooks" />

            <SettingsLayout>
                <div className="space-y-6">
                    {statusMessage && (
                        <Card>
                            <CardContent className="py-4">
                                <div className={`text-sm font-medium ${statusMessage.type === 'success' ? 'text-status-success' : 'text-status-critical'}`}>
                                    {statusMessage.text}
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {!can.manage && (
                        <Card>
                            <CardContent className="py-4 text-sm text-muted-foreground">
                                You can view outbound API settings here, but key and webhook management requires integration-admin access.
                            </CardContent>
                        </Card>
                    )}

                    <Card>
                        <CardHeader>
                            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                <div className="flex items-center gap-2">
                                    <ShieldCheck className="h-5 w-5 text-primary" />
                                    <div>
                                        <CardTitle>Device Integrations</CardTitle>
                                        <CardDescription>
                                            Hardware providers and inbound device webhooks are managed in Security & Devices.
                                        </CardDescription>
                                    </div>
                                </div>
                                <Button asChild variant="outline">
                                    <Link href="/security-devices/integrations">Open Security & Devices</Link>
                                </Button>
                            </div>
                        </CardHeader>
                    </Card>

                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <Key className="h-5 w-5 text-primary" />
                                    <div>
                                        <CardTitle>API Keys</CardTitle>
                                        <CardDescription>Generate tenant API keys for non-hardware integrations.</CardDescription>
                                    </div>
                                </div>
                                {can.manage && (
                                    <Button dusk="api-generate-open" onClick={() => setShowGenerateKey(true)} className="bg-primary hover:bg-primary">
                                        <Plus className="mr-1.5 h-4 w-4" />
                                        Generate New Key
                                    </Button>
                                )}
                            </div>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Name</TableHead>
                                        <TableHead>Key</TableHead>
                                        <TableHead>Created</TableHead>
                                        <TableHead>Last Used</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="w-20"></TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {apiKeys.length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={6} className="text-center text-sm text-muted-foreground">
                                                No API keys have been created yet.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {apiKeys.map((key) => (
                                        <TableRow key={key.id} dusk={`api-key-row-${key.id}`}>
                                            <TableCell className="font-medium">{key.name}</TableCell>
                                            <TableCell>
                                                <code className="rounded bg-muted px-1.5 py-0.5 text-xs">{key.maskedKey}</code>
                                            </TableCell>
                                            <TableCell className="text-sm text-muted-foreground">{key.created}</TableCell>
                                            <TableCell className="text-sm text-muted-foreground">{key.lastUsed ?? 'Never'}</TableCell>
                                            <TableCell>
                                                <Badge variant={key.status === 'active' ? 'default' : 'destructive'}>
                                                    {key.status}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                {can.manage && key.status === 'active' && (
                                                    <Button
                                                        dusk={`api-key-revoke-${key.id}`}
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => revokeKey(key.id)}
                                                        disabled={revokingKeyId === key.id}
                                                        className="text-status-critical hover:text-status-critical"
                                                    >
                                                        Revoke
                                                    </Button>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <Webhook className="h-5 w-5 text-primary" />
                                    <div>
                                        <CardTitle>Outbound Webhooks</CardTitle>
                                        <CardDescription>Configure tenant endpoints that receive application event notifications.</CardDescription>
                                    </div>
                                </div>
                                {can.manage && (
                                    <Button dusk="api-webhook-open" variant="outline" onClick={() => setShowAddWebhook(true)}>
                                        <Plus className="mr-1.5 h-4 w-4" />
                                        Add Webhook
                                    </Button>
                                )}
                            </div>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>URL</TableHead>
                                        <TableHead>Events</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Last Delivery</TableHead>
                                        <TableHead className="w-28"></TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {webhooks.length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={5} className="text-center text-sm text-muted-foreground">
                                                No webhook endpoints have been configured yet.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {webhooks.map((webhook) => (
                                        <TableRow key={webhook.id} dusk={`api-webhook-row-${webhook.id}`}>
                                            <TableCell className="max-w-[200px] truncate font-mono text-xs">{webhook.url}</TableCell>
                                            <TableCell>
                                                <div className="flex flex-wrap gap-1">
                                                    {webhook.events.map((event) => (
                                                        <Badge key={event} variant="secondary" className="text-xs">
                                                            {event}
                                                        </Badge>
                                                    ))}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant={webhook.status === 'active' ? 'default' : 'outline'}>
                                                    {webhook.status}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-sm text-muted-foreground">
                                                {webhook.lastDelivery ?? 'Never'}
                                            </TableCell>
                                            <TableCell>
                                                {can.manage && (
                                                    <div className="flex gap-1">
                                                        <Button
                                                            dusk={`api-webhook-test-${webhook.id}`}
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() => testWebhook(webhook.id)}
                                                            disabled={testingWebhookId === webhook.id}
                                                        >
                                                            Test
                                                        </Button>
                                                        <Button
                                                            dusk={`api-webhook-delete-${webhook.id}`}
                                                            variant="ghost"
                                                            size="sm"
                                                            className="text-status-critical hover:text-status-critical"
                                                            onClick={() => deleteWebhook(webhook.id)}
                                                            disabled={deletingWebhookId === webhook.id}
                                                        >
                                                            <Trash2 className="h-3.5 w-3.5" />
                                                        </Button>
                                                    </div>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-2">
                                <Activity className="h-5 w-5 text-primary" />
                                <div>
                                    <CardTitle>Usage</CardTitle>
                                    <CardDescription>Current API key and webhook totals.</CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
                                <div className="rounded-lg border p-4 text-center">
                                    <p className="text-2xl font-bold text-primary">{usage.activeKeys}</p>
                                    <p className="text-xs text-muted-foreground">Active Keys</p>
                                </div>
                                <div className="rounded-lg border p-4 text-center">
                                    <p className="text-2xl font-bold text-primary">{usage.revokedKeys}</p>
                                    <p className="text-xs text-muted-foreground">Revoked Keys</p>
                                </div>
                                <div className="rounded-lg border p-4 text-center">
                                    <p className="text-2xl font-bold text-primary">{usage.activeWebhooks}</p>
                                    <p className="text-xs text-muted-foreground">Active Webhooks</p>
                                </div>
                                <div className="rounded-lg border p-4 text-center">
                                    <p className="text-2xl font-bold text-primary">{usage.successfulTests}</p>
                                    <p className="text-xs text-muted-foreground">Successful Tests</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <Dialog open={showGenerateKey} onOpenChange={(open) => !open && closeGenerateDialog()}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Generate New API Key</DialogTitle>
                            <DialogDescription>Create a new API key for external integrations.</DialogDescription>
                        </DialogHeader>
                        {!generatedKey ? (
                            <div className="space-y-4">
                                <div>
                                    <Label htmlFor="key-name">Key Name</Label>
                                    <Input
                                        id="key-name"
                                        dusk="api-key-name"
                                        value={newKeyName}
                                        onChange={(event) => setNewKeyName(event.target.value)}
                                        placeholder="e.g. Production API"
                                        className="mt-1"
                                    />
                                </div>
                                <div>
                                    <Label>Scopes</Label>
                                    <div className="mt-2 space-y-2">
                                        {available_scopes.map((scope) => (
                                            <div key={scope} className="flex items-center gap-2">
                                                <Checkbox
                                                    id={`scope-${scope}`}
                                                    dusk={`api-scope-${slugify(scope)}`}
                                                    checked={newKeyScopes.includes(scope)}
                                                    onCheckedChange={() => toggleScope(scope)}
                                                />
                                                <Label htmlFor={`scope-${scope}`} className="text-sm font-normal">
                                                    {scope}
                                                </Label>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                                <DialogFooter>
                                    <Button
                                        dusk="api-key-generate"
                                        onClick={handleGenerateKey}
                                        disabled={!newKeyName || newKeyScopes.length === 0 || creatingKey}
                                        className="bg-primary hover:bg-primary"
                                    >
                                        Generate Key
                                    </Button>
                                </DialogFooter>
                            </div>
                        ) : (
                            <div className="space-y-4">
                                <div className="rounded-lg border border-status-warning/30 bg-status-warning-bg p-3">
                                    <p className="text-sm font-medium text-status-warning">
                                        Copy this key now. You will not be able to see it again.
                                    </p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <code dusk="api-key-generated-value" className="flex-1 overflow-x-auto rounded-md bg-muted p-3 font-mono text-sm">
                                        {generatedKey}
                                    </code>
                                    <Button dusk="api-key-copy" variant="outline" size="icon" onClick={handleCopyKey}>
                                        {copied ? <Check className="h-4 w-4 text-status-success" /> : <Copy className="h-4 w-4" />}
                                    </Button>
                                </div>
                                <DialogFooter>
                                    <Button dusk="api-key-done" onClick={closeGenerateDialog}>
                                        Done
                                    </Button>
                                </DialogFooter>
                            </div>
                        )}
                    </DialogContent>
                </Dialog>

                <Dialog open={showAddWebhook} onOpenChange={(open) => !open && closeWebhookDialog()}>
                    <DialogContent className="max-w-lg">
                        <DialogHeader>
                            <DialogTitle>Add Outbound Webhook</DialogTitle>
                            <DialogDescription>Configure a tenant endpoint that receives application event notifications.</DialogDescription>
                        </DialogHeader>
                        {!webhookSecret ? (
                            <div className="space-y-4">
                                <div>
                                    <Label htmlFor="webhook-url">Endpoint URL</Label>
                                    <Input
                                        id="webhook-url"
                                        dusk="api-webhook-url"
                                        value={webhookUrl}
                                        onChange={(event) => setWebhookUrl(event.target.value)}
                                        placeholder="https://api.example.com/webhooks"
                                        className="mt-1"
                                    />
                                </div>
                                <div>
                                    <Label>Events</Label>
                                    <div className="mt-2 grid grid-cols-2 gap-2">
                                        {available_events.map((event) => (
                                            <div key={event} className="flex items-center gap-2">
                                                <Checkbox
                                                    id={`event-${event}`}
                                                    dusk={`api-event-${slugify(event)}`}
                                                    checked={webhookEvents.includes(event)}
                                                    onCheckedChange={() => toggleEvent(event)}
                                                />
                                                <Label htmlFor={`event-${event}`} className="font-mono text-xs font-normal">
                                                    {event}
                                                </Label>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                                <DialogFooter>
                                    <Button
                                        dusk="api-webhook-add"
                                        onClick={handleAddWebhook}
                                        disabled={!webhookUrl || webhookEvents.length === 0 || creatingWebhook}
                                        className="bg-primary hover:bg-primary"
                                    >
                                        Add Webhook
                                    </Button>
                                </DialogFooter>
                            </div>
                        ) : (
                            <div className="space-y-4">
                                <div className="rounded-lg border border-status-warning/30 bg-status-warning-bg p-3">
                                    <p className="text-sm font-medium text-status-warning">
                                        Copy this signing secret now. You will not be able to see it again.
                                    </p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <code dusk="api-webhook-secret" className="flex-1 overflow-x-auto rounded-md bg-muted p-3 font-mono text-sm">
                                        {webhookSecret}
                                    </code>
                                    <Button
                                        dusk="api-webhook-copy"
                                        variant="outline"
                                        size="icon"
                                        onClick={() => navigator.clipboard.writeText(webhookSecret)}
                                    >
                                        <Copy className="h-4 w-4" />
                                    </Button>
                                </div>
                                <DialogFooter>
                                    <Button dusk="api-webhook-done" onClick={closeWebhookDialog}>
                                        Done
                                    </Button>
                                </DialogFooter>
                            </div>
                        )}
                    </DialogContent>
                </Dialog>
            </SettingsLayout>
        </AppLayout>
    );
}
