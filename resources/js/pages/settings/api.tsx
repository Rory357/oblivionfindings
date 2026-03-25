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
import { Head } from '@inertiajs/react';
import { Activity, Check, Copy, Key, Plus, Trash2, Webhook } from 'lucide-react';
import { useState } from 'react';

interface ApiKey {
    id: string;
    name: string;
    key: string;
    maskedKey: string;
    created: string;
    lastUsed: string | null;
    status: 'active' | 'revoked';
    scopes: string[];
}

interface WebhookEntry {
    id: string;
    url: string;
    events: string[];
    status: 'active' | 'inactive';
    lastDelivery: string | null;
    secret: string;
}

const availableScopes = [
    'Read Clients',
    'Write Clients',
    'Read Shifts',
    'Write Shifts',
    'Read HR',
    'Reports',
];

const availableEvents = [
    'client.created',
    'client.updated',
    'shift.created',
    'shift.completed',
    'shift.cancelled',
    'incident.reported',
    'incident.resolved',
    'timesheet.submitted',
    'timesheet.approved',
    'document.uploaded',
    'document.expired',
    'leave.requested',
    'leave.approved',
];

function generateId() {
    return Math.random().toString(36).substring(2, 10);
}

function generateKey() {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    let result = 'sk_live_';
    for (let i = 0; i < 32; i++) result += chars.charAt(Math.floor(Math.random() * chars.length));
    return result;
}

function generateSecret() {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    let result = 'whsec_';
    for (let i = 0; i < 24; i++) result += chars.charAt(Math.floor(Math.random() * chars.length));
    return result;
}

function maskKey(key: string) {
    return '****' + key.slice(-8);
}

const initialKeys: ApiKey[] = [
    { id: '1', name: 'Production API', key: '', maskedKey: '****aBcDeFgH', created: '2025-11-15', lastUsed: '2026-03-25', status: 'active', scopes: ['Read Clients', 'Read Shifts', 'Reports'] },
    { id: '2', name: 'Staging Integration', key: '', maskedKey: '****xYzWvQrS', created: '2026-01-08', lastUsed: '2026-03-20', status: 'active', scopes: ['Read Clients', 'Write Clients', 'Read Shifts', 'Write Shifts'] },
];

const initialWebhooks: WebhookEntry[] = [
    { id: '1', url: 'https://api.example.com/webhooks/oblivion', events: ['shift.completed', 'timesheet.approved'], status: 'active', lastDelivery: '2026-03-25 14:30', secret: 'whsec_xxxxxxxxxxxx' },
];

export default function Api() {
    const [apiKeys, setApiKeys] = useState<ApiKey[]>(initialKeys);
    const [webhooks, setWebhooks] = useState<WebhookEntry[]>(initialWebhooks);

    // Generate key dialog
    const [showGenerateKey, setShowGenerateKey] = useState(false);
    const [newKeyName, setNewKeyName] = useState('');
    const [newKeyScopes, setNewKeyScopes] = useState<string[]>([]);
    const [generatedKey, setGeneratedKey] = useState<string | null>(null);
    const [copied, setCopied] = useState(false);

    // Webhook dialog
    const [showAddWebhook, setShowAddWebhook] = useState(false);
    const [webhookUrl, setWebhookUrl] = useState('');
    const [webhookEvents, setWebhookEvents] = useState<string[]>([]);
    const [webhookSecret, setWebhookSecret] = useState('');

    function handleGenerateKey() {
        const key = generateKey();
        const newKey: ApiKey = {
            id: generateId(),
            name: newKeyName,
            key,
            maskedKey: maskKey(key),
            created: new Date().toISOString().split('T')[0],
            lastUsed: null,
            status: 'active',
            scopes: newKeyScopes,
        };
        setApiKeys((prev) => [...prev, newKey]);
        setGeneratedKey(key);
    }

    function handleCopyKey() {
        if (generatedKey) {
            navigator.clipboard.writeText(generatedKey);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        }
    }

    function closeGenerateDialog() {
        setShowGenerateKey(false);
        setNewKeyName('');
        setNewKeyScopes([]);
        setGeneratedKey(null);
        setCopied(false);
    }

    function revokeKey(id: string) {
        setApiKeys((prev) => prev.map((k) => (k.id === id ? { ...k, status: 'revoked' as const } : k)));
    }

    function handleAddWebhook() {
        const secret = generateSecret();
        const newWebhook: WebhookEntry = {
            id: generateId(),
            url: webhookUrl,
            events: webhookEvents,
            status: 'active',
            lastDelivery: null,
            secret,
        };
        setWebhooks((prev) => [...prev, newWebhook]);
        setWebhookSecret(secret);
    }

    function closeWebhookDialog() {
        setShowAddWebhook(false);
        setWebhookUrl('');
        setWebhookEvents([]);
        setWebhookSecret('');
    }

    function deleteWebhook(id: string) {
        setWebhooks((prev) => prev.filter((w) => w.id !== id));
    }

    function toggleScope(scope: string) {
        setNewKeyScopes((prev) => (prev.includes(scope) ? prev.filter((s) => s !== scope) : [...prev, scope]));
    }

    function toggleEvent(event: string) {
        setWebhookEvents((prev) => (prev.includes(event) ? prev.filter((e) => e !== event) : [...prev, event]));
    }

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Settings', href: '/settings' },
        { title: 'API & Webhooks' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="API & Webhooks" />
            <SettingsLayout>

            <div className="space-y-6">
                {/* API Keys */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <Key className="h-5 w-5 text-violet-600" />
                                <div>
                                    <CardTitle>API Keys</CardTitle>
                                    <CardDescription>Generate API keys for external integrations</CardDescription>
                                </div>
                            </div>
                            <Button onClick={() => setShowGenerateKey(true)} className="bg-violet-600 hover:bg-violet-700">
                                <Plus className="mr-1.5 h-4 w-4" />
                                Generate New Key
                            </Button>
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
                                {apiKeys.map((key) => (
                                    <TableRow key={key.id}>
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
                                            {key.status === 'active' && (
                                                <Button variant="ghost" size="sm" onClick={() => revokeKey(key.id)} className="text-red-600 hover:text-red-700">
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

                {/* Webhooks */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <Webhook className="h-5 w-5 text-violet-600" />
                                <div>
                                    <CardTitle>Webhooks</CardTitle>
                                    <CardDescription>Configure webhook endpoints to receive real-time event notifications</CardDescription>
                                </div>
                            </div>
                            <Button variant="outline" onClick={() => setShowAddWebhook(true)}>
                                <Plus className="mr-1.5 h-4 w-4" />
                                Add Webhook
                            </Button>
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
                                {webhooks.map((wh) => (
                                    <TableRow key={wh.id}>
                                        <TableCell className="max-w-[200px] truncate font-mono text-xs">{wh.url}</TableCell>
                                        <TableCell>
                                            <div className="flex flex-wrap gap-1">
                                                {wh.events.map((e) => (
                                                    <Badge key={e} variant="secondary" className="text-xs">{e}</Badge>
                                                ))}
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant={wh.status === 'active' ? 'default' : 'outline'}>{wh.status}</Badge>
                                        </TableCell>
                                        <TableCell className="text-sm text-muted-foreground">{wh.lastDelivery ?? 'Never'}</TableCell>
                                        <TableCell>
                                            <div className="flex gap-1">
                                                <Button variant="ghost" size="sm">Test</Button>
                                                <Button variant="ghost" size="sm" className="text-red-600 hover:text-red-700" onClick={() => deleteWebhook(wh.id)}>
                                                    <Trash2 className="h-3.5 w-3.5" />
                                                </Button>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {/* Usage */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <Activity className="h-5 w-5 text-violet-600" />
                            <div>
                                <CardTitle>Usage</CardTitle>
                                <CardDescription>API request statistics</CardDescription>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
                            <div className="rounded-lg border p-4 text-center">
                                <p className="text-2xl font-bold text-violet-600">247</p>
                                <p className="text-xs text-muted-foreground">Requests Today</p>
                            </div>
                            <div className="rounded-lg border p-4 text-center">
                                <p className="text-2xl font-bold text-violet-600">1,842</p>
                                <p className="text-xs text-muted-foreground">This Week</p>
                            </div>
                            <div className="rounded-lg border p-4 text-center">
                                <p className="text-2xl font-bold text-violet-600">8,156</p>
                                <p className="text-xs text-muted-foreground">This Month</p>
                            </div>
                            <div className="rounded-lg border p-4 text-center">
                                <p className="text-2xl font-bold text-violet-600">100/min</p>
                                <p className="text-xs text-muted-foreground">Rate Limit</p>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            {/* Generate Key Dialog */}
            <Dialog open={showGenerateKey} onOpenChange={(open) => !open && closeGenerateDialog()}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Generate New API Key</DialogTitle>
                        <DialogDescription>Create a new API key for external integrations</DialogDescription>
                    </DialogHeader>
                    {!generatedKey ? (
                        <div className="space-y-4">
                            <div>
                                <Label htmlFor="key-name">Key Name</Label>
                                <Input
                                    id="key-name"
                                    value={newKeyName}
                                    onChange={(e) => setNewKeyName(e.target.value)}
                                    placeholder="e.g. Production API"
                                    className="mt-1"
                                />
                            </div>
                            <div>
                                <Label>Scopes</Label>
                                <div className="mt-2 space-y-2">
                                    {availableScopes.map((scope) => (
                                        <div key={scope} className="flex items-center gap-2">
                                            <Checkbox
                                                id={`scope-${scope}`}
                                                checked={newKeyScopes.includes(scope)}
                                                onCheckedChange={() => toggleScope(scope)}
                                            />
                                            <Label htmlFor={`scope-${scope}`} className="text-sm font-normal">{scope}</Label>
                                        </div>
                                    ))}
                                </div>
                            </div>
                            <DialogFooter>
                                <Button onClick={handleGenerateKey} disabled={!newKeyName} className="bg-violet-600 hover:bg-violet-700">
                                    Generate Key
                                </Button>
                            </DialogFooter>
                        </div>
                    ) : (
                        <div className="space-y-4">
                            <div className="rounded-lg border border-amber-200 bg-amber-50 p-3">
                                <p className="text-sm font-medium text-amber-800">
                                    Copy this key now. You will not be able to see it again.
                                </p>
                            </div>
                            <div className="flex items-center gap-2">
                                <code className="flex-1 overflow-x-auto rounded-md bg-muted p-3 font-mono text-sm">
                                    {generatedKey}
                                </code>
                                <Button variant="outline" size="icon" onClick={handleCopyKey}>
                                    {copied ? <Check className="h-4 w-4 text-green-600" /> : <Copy className="h-4 w-4" />}
                                </Button>
                            </div>
                            <DialogFooter>
                                <Button onClick={closeGenerateDialog}>Done</Button>
                            </DialogFooter>
                        </div>
                    )}
                </DialogContent>
            </Dialog>

            {/* Add Webhook Dialog */}
            <Dialog open={showAddWebhook} onOpenChange={(open) => !open && closeWebhookDialog()}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Add Webhook</DialogTitle>
                        <DialogDescription>Configure a webhook endpoint to receive event notifications</DialogDescription>
                    </DialogHeader>
                    {!webhookSecret ? (
                        <div className="space-y-4">
                            <div>
                                <Label htmlFor="webhook-url">Endpoint URL</Label>
                                <Input
                                    id="webhook-url"
                                    value={webhookUrl}
                                    onChange={(e) => setWebhookUrl(e.target.value)}
                                    placeholder="https://api.example.com/webhooks"
                                    className="mt-1"
                                />
                            </div>
                            <div>
                                <Label>Events</Label>
                                <div className="mt-2 grid grid-cols-2 gap-2">
                                    {availableEvents.map((event) => (
                                        <div key={event} className="flex items-center gap-2">
                                            <Checkbox
                                                id={`event-${event}`}
                                                checked={webhookEvents.includes(event)}
                                                onCheckedChange={() => toggleEvent(event)}
                                            />
                                            <Label htmlFor={`event-${event}`} className="font-mono text-xs font-normal">{event}</Label>
                                        </div>
                                    ))}
                                </div>
                            </div>
                            <DialogFooter>
                                <Button onClick={handleAddWebhook} disabled={!webhookUrl || webhookEvents.length === 0} className="bg-violet-600 hover:bg-violet-700">
                                    Add Webhook
                                </Button>
                            </DialogFooter>
                        </div>
                    ) : (
                        <div className="space-y-4">
                            <div className="rounded-lg border border-amber-200 bg-amber-50 p-3">
                                <p className="text-sm font-medium text-amber-800">
                                    Copy this signing secret now. You will not be able to see it again.
                                </p>
                            </div>
                            <div className="flex items-center gap-2">
                                <code className="flex-1 overflow-x-auto rounded-md bg-muted p-3 font-mono text-sm">
                                    {webhookSecret}
                                </code>
                                <Button
                                    variant="outline"
                                    size="icon"
                                    onClick={() => {
                                        navigator.clipboard.writeText(webhookSecret);
                                    }}
                                >
                                    <Copy className="h-4 w-4" />
                                </Button>
                            </div>
                            <DialogFooter>
                                <Button onClick={closeWebhookDialog}>Done</Button>
                            </DialogFooter>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
            </SettingsLayout>
        </AppLayout>
    );
}
