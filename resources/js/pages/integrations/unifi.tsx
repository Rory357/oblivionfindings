import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import { Head, useForm } from '@inertiajs/react';

type SiteDto = {
    id: number;
    name: string;
    connection: null | {
        id: number;
        base_url: string;
        controller_type: 'unifi_os' | 'network_application';
        verify_tls: string;
        status: string;
        last_synced_at?: string | null;
        last_error?: string | null;
    };
};

type Props = {
    sites: SiteDto[];
};

export default function UnifiIntegration(props: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'UniFi', href: '/integrations/unifi' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="UniFi" />

            <div className="space-y-4 p-4">
                <Card className="rounded-2xl">
                    <CardHeader>
                        <CardTitle className="text-base">
                            UniFi integration (MVP)
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="text-sm text-muted-foreground">
                        Save controller credentials per site. “Sync” currently
                        just stamps a sync event into the Timeline, so you can
                        build the UI and permissions now. When you’re ready,
                        we’ll replace it with real UniFi polling/webhooks.
                    </CardContent>
                </Card>

                <div className="grid gap-4 lg:grid-cols-2">
                    {props.sites.map((s) => (
                        <SiteCard key={s.id} site={s} />
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}

function SiteCard({ site }: { site: SiteDto }) {
    const form = useForm({
        base_url: site.connection?.base_url ?? '',
        controller_type: site.connection?.controller_type ?? 'unifi_os',
        verify_tls: site.connection?.verify_tls ?? '1',
        username: '',
        password: '',
        api_token: '',
    });

    const save = () => {
        form.post(`/integrations/unifi/${site.id}`, { preserveScroll: true });
    };

    const sync = () => {
        form.post(`/integrations/unifi/${site.id}/sync`, {
            preserveScroll: true,
        });
    };

    return (
        <Card className="rounded-2xl">
            <CardHeader>
                <CardTitle className="text-base">{site.name}</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
                <div className="grid gap-2">
                    <Label htmlFor={`base_url_${site.id}`}>
                        Controller base URL
                    </Label>
                    <Input
                        id={`base_url_${site.id}`}
                        value={form.data.base_url}
                        onChange={(e) =>
                            form.setData('base_url', e.target.value)
                        }
                        placeholder="https://unifi.example.com:8443"
                    />
                    {form.errors.base_url ? (
                        <div className="text-xs text-destructive">
                            {form.errors.base_url}
                        </div>
                    ) : null}
                </div>

                <div className="grid gap-2">
                    <Label>Controller type</Label>
                    <Select
                        value={form.data.controller_type}
                        onValueChange={(v) =>
                            form.setData('controller_type', v as any)
                        }
                    >
                        <SelectTrigger>
                            <SelectValue placeholder="Select" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="unifi_os">UniFi OS</SelectItem>
                            <SelectItem value="network_application">
                                Network Application
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div className="grid gap-2">
                    <Label>Verify TLS</Label>
                    <Select
                        value={form.data.verify_tls}
                        onValueChange={(v) => form.setData('verify_tls', v)}
                    >
                        <SelectTrigger>
                            <SelectValue placeholder="Select" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="1">Yes</SelectItem>
                            <SelectItem value="0">No (dev only)</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div className="grid gap-2 lg:grid-cols-2">
                    <div className="grid gap-2">
                        <Label>Username (optional)</Label>
                        <Input
                            value={form.data.username}
                            onChange={(e) =>
                                form.setData('username', e.target.value)
                            }
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label>Password (optional)</Label>
                        <Input
                            type="password"
                            value={form.data.password}
                            onChange={(e) =>
                                form.setData('password', e.target.value)
                            }
                        />
                    </div>
                </div>

                <div className="grid gap-2">
                    <Label>API token (optional)</Label>
                    <Input
                        value={form.data.api_token}
                        onChange={(e) =>
                            form.setData('api_token', e.target.value)
                        }
                    />
                </div>

                {site.connection ? (
                    <div className="rounded-xl border p-3 text-xs text-muted-foreground">
                        <div>Status: {site.connection.status}</div>
                        <div>
                            Last sync:{' '}
                            {site.connection.last_synced_at
                                ? new Date(
                                      site.connection.last_synced_at,
                                  ).toLocaleString()
                                : '—'}
                        </div>
                        {site.connection.last_error ? (
                            <div className="text-destructive">
                                {site.connection.last_error}
                            </div>
                        ) : null}
                    </div>
                ) : (
                    <div className="text-xs text-muted-foreground">
                        No connection saved for this site yet.
                    </div>
                )}

                <div className="flex flex-wrap gap-2">
                    <Button size="sm" onClick={save} disabled={form.processing}>
                        Save
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={sync}
                        disabled={form.processing || !site.connection}
                    >
                        Sync (MVP)
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}
