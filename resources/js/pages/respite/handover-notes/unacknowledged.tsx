import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import RespiteSubnav from '@/components/respite-subnav';
import { formatDateTime } from '@/lib/date-format';
import { Head, Link, router } from '@inertiajs/react';
import { FileText } from 'lucide-react';

type Props = {
    notes: { data: any[]; links: any[] };
};

export default function UnacknowledgedHandoverNotes({ notes }: Props) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Respite', href: '/respite' }, { title: 'Handover Notes', href: '/respite/handover-notes' }, { title: 'Unacknowledged', href: '/respite/handover-notes/unacknowledged' }]}>
            <Head title="Unacknowledged Handover Notes" />

            <PageLayout
                hero={
                    <PageHero
                        icon={FileText}
                        title="Unacknowledged Handover Notes"
                        description="Handover notes that have not yet been acknowledged by incoming staff."
                        stats={[
                            { label: 'Total', value: notes.data.length },
                        ]}
                    />
                }
            >
                <RespiteSubnav />

                <div className="space-y-2">
                    {notes.data.map((n: any) => (
                        <Card key={n.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="font-semibold">
                                                {n.stay?.client?.first_name} {n.stay?.client?.last_name}
                                            </div>
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                <Badge variant="outline">{n.handover_type?.replace(/_/g, ' ')}</Badge>
                                                <Badge className="bg-status-warning-bg text-status-warning">Unacknowledged</Badge>
                                            </div>
                                            <div className="mt-2 text-xs text-muted-foreground line-clamp-2">{n.notes}</div>
                                            <div className="mt-1 text-xs text-muted-foreground">{formatDateTime(n.created_at)}</div>
                                        </div>
                                        <div className="flex flex-col gap-2">
                                            <Link href={`/respite/handover-notes/${n.id}`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted text-center">
                                                View
                                            </Link>
                                            <Button size="sm" variant="outline" onClick={() => router.post(`/respite/handover-notes/${n.id}/acknowledge`)}>
                                                Acknowledge
                                            </Button>
                                        </div>
                                    </div>
                                </CardTitle>
                            </CardHeader>
                        </Card>
                    ))}
                    {!notes.data.length && (
                        <div className="py-8 text-center text-sm text-muted-foreground">No unacknowledged handover notes.</div>
                    )}
                </div>

                {notes?.links?.length ? (
                    <div className="flex flex-wrap gap-2">
                        {notes.links.map((l: any) => (
                            <Button
                                key={l.label}
                                variant="outline"
                                size="sm"
                                disabled={!l.url}
                                onClick={() => l.url && router.get(l.url, {}, { preserveState: true, preserveScroll: true })}
                                dangerouslySetInnerHTML={{ __html: l.label }}
                            />
                        ))}
                    </div>
                ) : null}
            </PageLayout>
        </AppLayout>
    );
}
