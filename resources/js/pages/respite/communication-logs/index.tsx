import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import RespiteSubnav from '@/components/respite-subnav';
import { formatDateTime } from '@/lib/date-format';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

type Props = {
    logs: { data: any[]; links: any[] };
    filters: { stay_id?: string; channel?: string; date_from?: string; date_to?: string };
    channels: Record<string, string>;
};

export default function CommunicationLogsIndex({ logs, filters, channels }: Props) {
    const [localFilters, setLocalFilters] = useState(filters);

    const applyFilter = (key: string, value: string) => {
        const updated = { ...localFilters, [key]: value };
        setLocalFilters(updated);
        router.get('/respite/communication-logs', updated, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={[
            { title: 'Respite', href: '/respite' },
            { title: 'Communication Logs', href: '/respite/communication-logs' },
        ]}>
            <Head title="Communication Logs" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Communication Logs</h1>
                        <div className="mt-1 text-sm text-slate-500">
                            Record of all communications related to respite stays.
                        </div>
                    </div>
                    <Link href="/respite/communication-logs/create" className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                        New Log
                    </Link>
                </div>
                <RespiteSubnav />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4 sm:grid-cols-3">
                        <div>
                            <Label>Channel</Label>
                            <Select value={localFilters.channel || ''} onValueChange={(v) => applyFilter('channel', v)}>
                                <SelectTrigger><SelectValue placeholder="All channels" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="">All channels</SelectItem>
                                    {Object.entries(channels).map(([key, label]) => (
                                        <SelectItem key={key} value={key}>{label}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label>Date From</Label>
                            <Input
                                type="date"
                                value={localFilters.date_from || ''}
                                onChange={(e) => applyFilter('date_from', e.target.value)}
                            />
                        </div>
                        <div>
                            <Label>Date To</Label>
                            <Input
                                type="date"
                                value={localFilters.date_to || ''}
                                onChange={(e) => applyFilter('date_to', e.target.value)}
                            />
                        </div>
                    </CardContent>
                </Card>

                <div className="space-y-2">
                    {logs.data.map((log: any) => (
                        <Card key={log.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="font-semibold">
                                                {log.stay?.client?.first_name} {log.stay?.client?.last_name}
                                            </div>
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                <Badge variant="outline">{channels[log.channel] || log.channel}</Badge>
                                                {log.participants?.length > 0 && (
                                                    <Badge variant="outline">{log.participants.length} participant{log.participants.length !== 1 ? 's' : ''}</Badge>
                                                )}
                                            </div>
                                            <div className="mt-2 text-xs text-slate-500">
                                                {formatDateTime(log.occurred_at)}
                                            </div>
                                            {log.summary && (
                                                <div className="mt-1 text-xs text-slate-500">
                                                    {log.summary.length > 100 ? `${log.summary.substring(0, 100)}...` : log.summary}
                                                </div>
                                            )}
                                        </div>
                                        <Link href={`/respite/communication-logs/${log.id}`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                            View
                                        </Link>
                                    </div>
                                </CardTitle>
                            </CardHeader>
                        </Card>
                    ))}
                    {!logs.data.length && (
                        <div className="py-8 text-center text-sm text-slate-500">
                            No items found.
                        </div>
                    )}
                </div>

                {logs?.links?.length ? (
                    <div className="flex flex-wrap gap-2">
                        {logs.links.map((l: any) => (
                            <Button
                                key={l.label}
                                variant="outline"
                                size="sm"
                                disabled={!l.url}
                                className={l.active ? 'bg-muted' : ''}
                                onClick={() => l.url && router.get(l.url, {}, { preserveState: true, preserveScroll: true })}
                                dangerouslySetInnerHTML={{ __html: l.label }}
                            />
                        ))}
                    </div>
                ) : null}
            </div>
        </AppLayout>
    );
}
