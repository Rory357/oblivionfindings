import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { PageHero, PageLayout } from '@/components/page';
import RespiteSubnav from '@/components/respite-subnav';
import { formatDateTimeLong } from '@/lib/datetime';
import { Head, router } from '@inertiajs/react';

type Props = {
    note: any;
};

export default function HandoverNoteShow({ note }: Props) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Respite', href: '/respite' }, { title: 'Handover Notes', href: '/respite/handover-notes' }, { title: `Note #${note.id}`, href: `/respite/handover-notes/${note.id}` }]}>
            <Head title="Handover Note" />

            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref="/respite/handover-notes"
                        title={`Handover Note #${note.id}`}
                        description={`${note.stay?.client?.first_name ?? ''} ${note.stay?.client?.last_name ?? ''}`.trim() || undefined}
                    />
                }
            >
                <RespiteSubnav />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Note Details</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm text-muted-foreground">
                        <div className="flex flex-wrap gap-2">
                            <Badge variant="outline">{note.handover_type?.replace(/_/g, ' ')}</Badge>
                            {note.sensitive_flag && <Badge className="bg-status-critical-bg text-status-critical">Sensitive</Badge>}
                        </div>
                        <div className="whitespace-pre-wrap">{note.notes}</div>
                        <div className="text-xs text-muted-foreground">Created: {formatDateTimeLong(note.created_at)}</div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Acknowledgment</CardTitle>
                    </CardHeader>
                    <CardContent className="text-sm text-muted-foreground">
                        {note.acknowledged_at ? (
                            <div className="space-y-1">
                                <div>Acknowledged by: {note.acknowledged_by?.name || 'Unknown'}</div>
                                <div>Acknowledged at: {formatDateTimeLong(note.acknowledged_at)}</div>
                            </div>
                        ) : (
                            <div className="space-y-3">
                                <div className="text-muted-foreground">This note has not been acknowledged yet.</div>
                                <Button size="sm" onClick={() => router.post(`/respite/handover-notes/${note.id}/acknowledge`)}>
                                    Acknowledge
                                </Button>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
