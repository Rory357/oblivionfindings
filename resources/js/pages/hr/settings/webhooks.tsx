import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
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
import { Head, router, useForm } from '@inertiajs/react';
import { Pencil, Plus, TestTube, Trash2, Webhook } from 'lucide-react';
import { useState } from 'react';

type BreadcrumbItem = { title: string; href: string };

interface WebhookRecord {
    id: number;
    url: string;
    secret: string;
    events: string[];
    is_active: boolean;
    last_triggered_at: string | null;
    failure_count: number;
    creator: { id: number; name: string } | null;
    created_at: string;
}

interface Props {
    webhooks: WebhookRecord[];
    availableEvents: string[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Settings', href: '/hr/settings/webhooks' },
    { title: 'Webhooks', href: '/hr/settings/webhooks' },
];

const formatDate = (value?: string | null) => {
    if (!value) return 'Never';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleString('en-GB', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          });
};

export default function WebhooksIndex({ webhooks, availableEvents }: Props) {
    const [open, setOpen] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);

    const form = useForm({
        url: '',
        events: [] as string[],
        is_active: true,
    });

    const openCreate = () => {
        form.reset();
        form.setData({ url: '', events: [], is_active: true });
        setEditingId(null);
        setOpen(true);
    };

    const openEdit = (webhook: WebhookRecord) => {
        form.setData({
            url: webhook.url,
            events: webhook.events,
            is_active: webhook.is_active,
        });
        setEditingId(webhook.id);
        setOpen(true);
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (editingId) {
            form.put(`/hr/settings/webhooks/${editingId}`, {
                onSuccess: () => setOpen(false),
            });
        } else {
            form.post('/hr/settings/webhooks', {
                onSuccess: () => setOpen(false),
            });
        }
    };

    const toggleEvent = (event: string) => {
        const current = form.data.events;
        if (current.includes(event)) {
            form.setData(
                'events',
                current.filter((e) => e !== event),
            );
        } else {
            form.setData('events', [...current, event]);
        }
    };

    const deleteWebhook = (id: number) => {
        if (confirm('Are you sure you want to delete this webhook?')) {
            router.delete(`/hr/settings/webhooks/${id}`);
        }
    };

    const testWebhook = (id: number) => {
        router.post(`/hr/settings/webhooks/${id}/test`);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Webhooks - HR Settings" />
            <PageShell>
                <PageHeader
                    title="Webhooks"
                    description="Manage webhook endpoints for HR event notifications."
                >
                    <Dialog open={open} onOpenChange={setOpen}>
                        <DialogTrigger asChild>
                            <Button onClick={openCreate}>
                                <Plus className="mr-2 h-4 w-4" />
                                Add Webhook
                            </Button>
                        </DialogTrigger>
                        <DialogContent className="sm:max-w-lg">
                            <DialogHeader>
                                <DialogTitle>
                                    {editingId
                                        ? 'Edit Webhook'
                                        : 'Create Webhook'}
                                </DialogTitle>
                            </DialogHeader>
                            <form onSubmit={submit} className="space-y-4">
                                <div>
                                    <Label htmlFor="url">Endpoint URL</Label>
                                    <Input
                                        id="url"
                                        type="url"
                                        value={form.data.url}
                                        onChange={(e) =>
                                            form.setData('url', e.target.value)
                                        }
                                        placeholder="https://example.com/webhook"
                                        required
                                    />
                                    {form.errors.url && (
                                        <p className="mt-1 text-sm text-status-critical">
                                            {form.errors.url}
                                        </p>
                                    )}
                                </div>

                                <div>
                                    <Label>Events</Label>
                                    <div className="mt-2 grid max-h-48 grid-cols-2 gap-2 overflow-y-auto">
                                        {availableEvents.map((event) => (
                                            <label
                                                key={event}
                                                className="flex items-center gap-2 text-sm"
                                            >
                                                <Checkbox
                                                    checked={form.data.events.includes(
                                                        event,
                                                    )}
                                                    onCheckedChange={() =>
                                                        toggleEvent(event)
                                                    }
                                                />
                                                {event}
                                            </label>
                                        ))}
                                    </div>
                                    {form.errors.events && (
                                        <p className="mt-1 text-sm text-status-critical">
                                            {form.errors.events}
                                        </p>
                                    )}
                                </div>

                                {editingId && (
                                    <div>
                                        <label className="flex items-center gap-2 text-sm">
                                            <Checkbox
                                                checked={form.data.is_active}
                                                onCheckedChange={(checked) =>
                                                    form.setData(
                                                        'is_active',
                                                        !!checked,
                                                    )
                                                }
                                            />
                                            Active
                                        </label>
                                    </div>
                                )}

                                <DialogFooter>
                                    <Button
                                        type="submit"
                                        disabled={form.processing}
                                    >
                                        {editingId ? 'Update' : 'Create'}
                                    </Button>
                                </DialogFooter>
                            </form>
                        </DialogContent>
                    </Dialog>
                </PageHeader>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Webhook className="h-5 w-5" />
                            Registered Webhooks
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {webhooks.length === 0 ? (
                            <p className="py-8 text-center text-muted-foreground">
                                No webhooks configured yet.
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>URL</TableHead>
                                        <TableHead>Events</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Failures</TableHead>
                                        <TableHead>Last Triggered</TableHead>
                                        <TableHead className="text-right">
                                            Actions
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {webhooks.map((webhook) => (
                                        <TableRow key={webhook.id}>
                                            <TableCell className="max-w-[200px] truncate font-mono text-xs">
                                                {webhook.url}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex flex-wrap gap-1">
                                                    {webhook.events
                                                        .slice(0, 3)
                                                        .map((event) => (
                                                            <Badge
                                                                key={event}
                                                                variant="secondary"
                                                                className="text-xs"
                                                            >
                                                                {event}
                                                            </Badge>
                                                        ))}
                                                    {webhook.events.length >
                                                        3 && (
                                                        <Badge
                                                            variant="outline"
                                                            className="text-xs"
                                                        >
                                                            +
                                                            {webhook.events
                                                                .length - 3}
                                                        </Badge>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant={
                                                        webhook.is_active
                                                            ? 'default'
                                                            : 'secondary'
                                                    }
                                                >
                                                    {webhook.is_active
                                                        ? 'Active'
                                                        : 'Inactive'}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                {webhook.failure_count > 0 ? (
                                                    <Badge variant="destructive">
                                                        {webhook.failure_count}
                                                    </Badge>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        0
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-sm text-muted-foreground">
                                                {formatDate(
                                                    webhook.last_triggered_at,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <div className="flex justify-end gap-1">
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            testWebhook(
                                                                webhook.id,
                                                            )
                                                        }
                                                        title="Test"
                                                    >
                                                        <TestTube className="h-4 w-4" />
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            openEdit(webhook)
                                                        }
                                                        title="Edit"
                                                    >
                                                        <Pencil className="h-4 w-4" />
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            deleteWebhook(
                                                                webhook.id,
                                                            )
                                                        }
                                                        title="Delete"
                                                    >
                                                        <Trash2 className="h-4 w-4 text-status-critical" />
                                                    </Button>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
