import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PageHero, PageLayout } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Webhook } from 'lucide-react';
import { useMemo, useState } from 'react';

interface EventOption {
    value: string;
    label: string;
}

interface Endpoint {
    id: number;
    name: string;
    target_url: string;
    event_types: string[];
    headers: Record<string, string>;
    timeout_seconds: number;
    retry_limit: number;
    is_active: boolean;
    last_delivery_at: string | null;
    last_status: string | null;
    last_error: string | null;
    deliveries_count: number;
    failed_deliveries_count: number;
}

interface Delivery {
    id: number;
    endpoint_id: number;
    endpoint_name: string | null;
    event_type: string;
    status: 'pending' | 'retrying' | 'success' | 'failed';
    attempts: number;
    max_attempts: number;
    queued_at: string | null;
    delivered_at: string | null;
    failed_at: string | null;
    response_code: number | null;
    error_message: string | null;
}

interface Props {
    endpoints: Endpoint[];
    deliveries: Delivery[];
    eventOptions: EventOption[];
    can: { manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Reports', href: '/hr/reports' },
    { title: 'Webhooks', href: '/hr/reports/webhooks' },
];

const statusClass: Record<string, string> = {
    pending: 'border-border/30 text-muted-foreground bg-muted-foreground/80/10',
    retrying: 'border-status-warning/30 text-status-warning bg-status-warning',
    success: 'border-status-success/30 text-status-success bg-status-success',
    failed: 'border-status-critical/30 text-status-critical bg-status-critical',
};

export default function HrWebhookIndex({
    endpoints,
    deliveries,
    eventOptions,
    can,
}: Props) {
    const [editingEndpointId, setEditingEndpointId] = useState<number | null>(
        null,
    );
    const { data, setData, post, put, processing, errors, reset } = useForm({
        name: '',
        target_url: '',
        signing_secret: '',
        event_types: [] as string[],
        timeout_seconds: '10',
        retry_limit: '3',
        is_active: true,
    });

    const eventTypeLabelByValue = useMemo(
        () =>
            new Map(
                eventOptions.map((eventOption) => [
                    eventOption.value,
                    eventOption.label,
                ]),
            ),
        [eventOptions],
    );

    const toggleEvent = (eventType: string, checked: boolean) => {
        const next = new Set(data.event_types);
        if (checked) {
            next.add(eventType);
        } else {
            next.delete(eventType);
        }
        setData('event_types', Array.from(next));
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const onSuccess = () => {
            setEditingEndpointId(null);
            reset();
            setData('timeout_seconds', '10');
            setData('retry_limit', '3');
            setData('is_active', true);
        };

        if (editingEndpointId) {
            put(`/hr/reports/webhooks/${editingEndpointId}`, {
                preserveScroll: true,
                onSuccess,
            });
            return;
        }

        post('/hr/reports/webhooks', {
            preserveScroll: true,
            onSuccess,
        });
    };

    const toggleEndpoint = (id: number) => {
        router.post(
            `/hr/reports/webhooks/${id}/toggle-active`,
            {},
            { preserveScroll: true },
        );
    };

    const retryDelivery = (id: number) => {
        router.post(
            `/hr/reports/webhooks/deliveries/${id}/retry`,
            {},
            { preserveScroll: true },
        );
    };

    const startEdit = (endpoint: Endpoint) => {
        setEditingEndpointId(endpoint.id);
        setData({
            name: endpoint.name,
            target_url: endpoint.target_url,
            signing_secret: '',
            event_types: endpoint.event_types,
            timeout_seconds: String(endpoint.timeout_seconds),
            retry_limit: String(endpoint.retry_limit),
            is_active: endpoint.is_active,
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="HR Webhooks" />
            <PageLayout
                hero={
                    <PageHero
                        icon={Webhook}
                        title="HR Webhooks"
                        description="Manage webhook endpoints for HR event notifications."
                        actions={
                            <>
                                <Button
                                    variant="outline"
                                    asChild
                                    className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                >
                                    <Link href="/hr/reports/automations">
                                        Automations
                                    </Link>
                                </Button>
                                <Button
                                    variant="outline"
                                    asChild
                                    className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                >
                                    <Link href="/hr/reports">Back to Reports</Link>
                                </Button>
                            </>
                        }
                    />
                }
            >
                {can.manage && (
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <CardTitle className="text-base">
                                    {editingEndpointId
                                        ? 'Edit Webhook Endpoint'
                                        : 'Create Webhook Endpoint'}
                                </CardTitle>
                                {editingEndpointId && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setEditingEndpointId(null);
                                            reset();
                                            setData('timeout_seconds', '10');
                                            setData('retry_limit', '3');
                                            setData('is_active', true);
                                        }}
                                    >
                                        Cancel Edit
                                    </Button>
                                )}
                            </div>
                        </CardHeader>
                        <CardContent>
                            <form
                                className="grid gap-4 md:grid-cols-2"
                                onSubmit={submit}
                            >
                                <div className="space-y-2">
                                    <Label>Name</Label>
                                    <Input
                                        value={data.name}
                                        onChange={(e) =>
                                            setData('name', e.target.value)
                                        }
                                        placeholder="Operations Alerts"
                                    />
                                    {errors.name && (
                                        <p className="text-xs text-status-critical">
                                            {errors.name}
                                        </p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label>Target URL</Label>
                                    <Input
                                        value={data.target_url}
                                        onChange={(e) =>
                                            setData(
                                                'target_url',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="https://hooks.example.test/hr"
                                    />
                                    {errors.target_url && (
                                        <p className="text-xs text-status-critical">
                                            {errors.target_url}
                                        </p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label>Signing Secret (optional)</Label>
                                    <Input
                                        value={data.signing_secret}
                                        onChange={(e) =>
                                            setData(
                                                'signing_secret',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="hmac-secret"
                                    />
                                </div>

                                <div className="grid grid-cols-2 gap-3">
                                    <div className="space-y-2">
                                        <Label>Timeout Seconds</Label>
                                        <Input
                                            type="number"
                                            min={2}
                                            max={30}
                                            value={data.timeout_seconds}
                                            onChange={(e) =>
                                                setData(
                                                    'timeout_seconds',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Retry Limit</Label>
                                        <Input
                                            type="number"
                                            min={1}
                                            max={6}
                                            value={data.retry_limit}
                                            onChange={(e) =>
                                                setData(
                                                    'retry_limit',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                </div>

                                <div className="space-y-2 md:col-span-2">
                                    <Label>Event Types</Label>
                                    <div className="grid gap-2 md:grid-cols-3">
                                        {eventOptions.map((eventOption) => (
                                            <label
                                                key={eventOption.value}
                                                className="flex items-center gap-2 rounded border p-2 text-sm"
                                            >
                                                <Checkbox
                                                    checked={data.event_types.includes(
                                                        eventOption.value,
                                                    )}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        toggleEvent(
                                                            eventOption.value,
                                                            Boolean(checked),
                                                        )
                                                    }
                                                />
                                                <span>{eventOption.label}</span>
                                            </label>
                                        ))}
                                    </div>
                                    {errors.event_types && (
                                        <p className="text-xs text-status-critical">
                                            {errors.event_types}
                                        </p>
                                    )}
                                </div>

                                <div className="md:col-span-2">
                                    <label className="flex items-center gap-2 text-sm">
                                        <Checkbox
                                            checked={data.is_active}
                                            onCheckedChange={(checked) =>
                                                setData(
                                                    'is_active',
                                                    Boolean(checked),
                                                )
                                            }
                                        />
                                        <span>Endpoint is active</span>
                                    </label>
                                </div>

                                <div className="flex justify-end md:col-span-2">
                                    <Button
                                        type="submit"
                                        disabled={
                                            processing ||
                                            data.event_types.length === 0
                                        }
                                    >
                                        {editingEndpointId
                                            ? 'Update Endpoint'
                                            : 'Create Endpoint'}
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Webhook Endpoints
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Name
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        URL
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Events
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Health
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {endpoints.map((endpoint) => (
                                    <tr
                                        key={endpoint.id}
                                        className="align-top hover:bg-muted/30"
                                    >
                                        <td className="px-4 py-3">
                                            <div className="font-medium">
                                                {endpoint.name}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                retries {endpoint.retry_limit},
                                                timeout{' '}
                                                {endpoint.timeout_seconds}s
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {endpoint.target_url}
                                        </td>
                                        <td className="px-4 py-3 text-xs text-muted-foreground">
                                            {endpoint.event_types
                                                .map(
                                                    (eventType) =>
                                                        eventTypeLabelByValue.get(
                                                            eventType,
                                                        ) || eventType,
                                                )
                                                .join(', ')}
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-2">
                                                <Badge
                                                    variant="outline"
                                                    className={
                                                        endpoint.is_active
                                                            ? statusClass.success
                                                            : statusClass.pending
                                                    }
                                                >
                                                    {endpoint.is_active
                                                        ? 'active'
                                                        : 'paused'}
                                                </Badge>
                                                {endpoint.last_status && (
                                                    <Badge
                                                        variant="outline"
                                                        className={
                                                            statusClass[
                                                                endpoint
                                                                    .last_status
                                                            ] ||
                                                            statusClass.pending
                                                        }
                                                    >
                                                        {endpoint.last_status}
                                                    </Badge>
                                                )}
                                            </div>
                                            <div className="mt-1 text-xs text-muted-foreground">
                                                {endpoint.deliveries_count}{' '}
                                                deliveries,{' '}
                                                {
                                                    endpoint.failed_deliveries_count
                                                }{' '}
                                                failed
                                            </div>
                                            {endpoint.last_error && (
                                                <div className="mt-1 max-w-md text-xs text-status-critical">
                                                    {endpoint.last_error}
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            {can.manage && (
                                                <div className="flex items-center justify-end gap-2">
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            startEdit(endpoint)
                                                        }
                                                    >
                                                        Edit
                                                    </Button>
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            toggleEndpoint(
                                                                endpoint.id,
                                                            )
                                                        }
                                                    >
                                                        {endpoint.is_active
                                                            ? 'Pause'
                                                            : 'Resume'}
                                                    </Button>
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                                {endpoints.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={5}
                                            className="px-4 py-8 text-center text-muted-foreground"
                                        >
                                            No webhook endpoints configured.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Recent Deliveries
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Endpoint
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Event
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Status
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Attempts
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium">
                                        Response
                                    </th>
                                    <th className="px-4 py-3 text-right font-medium">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {deliveries.map((delivery) => (
                                    <tr
                                        key={delivery.id}
                                        className="hover:bg-muted/30"
                                    >
                                        <td className="px-4 py-3 font-medium">
                                            {delivery.endpoint_name || '-'}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {eventTypeLabelByValue.get(
                                                delivery.event_type,
                                            ) || delivery.event_type}
                                        </td>
                                        <td className="px-4 py-3">
                                            <Badge
                                                variant="outline"
                                                className={
                                                    statusClass[
                                                        delivery.status
                                                    ] || statusClass.pending
                                                }
                                            >
                                                {delivery.status}
                                            </Badge>
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {delivery.attempts}/
                                            {delivery.max_attempts}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {delivery.response_code || '-'}
                                            {delivery.error_message
                                                ? ` - ${delivery.error_message}`
                                                : ''}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            {can.manage &&
                                                delivery.status ===
                                                    'failed' && (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() =>
                                                            retryDelivery(
                                                                delivery.id,
                                                            )
                                                        }
                                                    >
                                                        Retry
                                                    </Button>
                                                )}
                                        </td>
                                    </tr>
                                ))}
                                {deliveries.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={6}
                                            className="px-4 py-8 text-center text-muted-foreground"
                                        >
                                            No webhook deliveries yet.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
