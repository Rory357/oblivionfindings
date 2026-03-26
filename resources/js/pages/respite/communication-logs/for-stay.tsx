import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import RespiteSubnav from '@/components/respite-subnav';
import { formatDateTime } from '@/lib/date-format';
import { Head, Link } from '@inertiajs/react';

type Props = {
    stay: any;
    logs: any[];
    channels: Record<string, string>;
};

export default function CommunicationLogsForStay({ stay, logs, channels }: Props) {
    return (
        <AppLayout breadcrumbs={[
            { title: 'Respite', href: '/respite' },
            { title: 'Stays', href: '/respite/stays' },
            { title: `${stay.client?.first_name} ${stay.client?.last_name}`, href: `/respite/stays/${stay.id}` },
            { title: 'Communication Logs', href: `/respite/stays/${stay.id}/communication-logs` },
        ]}>
            <Head title="Communication Logs for Stay" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">
                            Communication Logs for {stay.client?.first_name} {stay.client?.last_name}
                        </h1>
                        <div className="mt-1 text-sm text-slate-500">
                            {formatDateTime(stay.start_date)} &mdash; {formatDateTime(stay.end_date)}
                        </div>
                    </div>
                    <Link
                        href={`/respite/communication-logs/create?stay_id=${stay.id}`}
                        className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                    >
                        New Log
                    </Link>
                </div>
                <RespiteSubnav />

                <div className="space-y-2">
                    {logs.map((log: any) => (
                        <Card key={log.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="mt-1 flex flex-wrap gap-2">
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
                    {!logs.length && (
                        <div className="py-8 text-center text-sm text-slate-500">
                            No items found.
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
