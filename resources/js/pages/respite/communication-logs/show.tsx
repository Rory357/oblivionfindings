import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { PageHero, PageLayout } from '@/components/page';
import RespiteSubnav from '@/components/respite-subnav';
import { formatDateTimeLong } from '@/lib/datetime';
import { Head, Link } from '@inertiajs/react';

type Props = {
    log: any;
};

export default function CommunicationLogShow({ log }: Props) {
    return (
        <AppLayout breadcrumbs={[
            { title: 'Respite', href: '/respite' },
            { title: 'Communication Logs', href: '/respite/communication-logs' },
            { title: `Log #${log.id}`, href: `/respite/communication-logs/${log.id}` },
        ]}>
            <Head title="Communication Log" />

            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref="/respite/communication-logs"
                        title="Communication Log"
                        actions={<Badge variant="outline">{log.channel}</Badge>}
                    />
                }
            >
                <RespiteSubnav />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Log Details</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2 text-sm text-muted-foreground">
                        <div>
                            Client:{' '}
                            {log.stay?.client ? (
                                <Link href={`/respite/stays/${log.stay.id}`} className="text-primary hover:text-primary">
                                    {log.stay.client.first_name} {log.stay.client.last_name}
                                </Link>
                            ) : (
                                'Unknown'
                            )}
                        </div>
                        <div>Channel: <Badge variant="outline">{log.channel}</Badge></div>
                        <div>Occurred: {formatDateTimeLong(log.occurred_at)}</div>
                        {log.created_by && <div>Created by: {log.created_by.name || log.created_by}</div>}
                        {log.summary && (
                            <div className="mt-3">
                                <div className="font-medium text-foreground">Summary</div>
                                <div className="mt-1 whitespace-pre-wrap">{log.summary}</div>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Participants</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {log.participants?.length > 0 ? (
                            <div className="space-y-2">
                                {log.participants.map((p: any, i: number) => (
                                    <div key={i} className="flex items-center gap-2 text-sm">
                                        <span className="font-medium">{p.name}</span>
                                        {p.role && <Badge variant="outline">{p.role}</Badge>}
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="py-4 text-center text-sm text-muted-foreground">No participants recorded.</div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Evidence</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {log.evidence?.length > 0 ? (
                            <div className="space-y-2">
                                {log.evidence.map((e: any, i: number) => (
                                    <div key={i} className="flex items-center justify-between rounded-md border p-3 text-sm">
                                        <div>
                                            <Badge variant="outline">{e.type}</Badge>
                                            <span className="ml-2">{e.description}</span>
                                        </div>
                                        <div className="text-xs text-muted-foreground">{formatDateTimeLong(e.added_at)}</div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="py-4 text-center text-sm text-muted-foreground">No evidence items.</div>
                        )}
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
