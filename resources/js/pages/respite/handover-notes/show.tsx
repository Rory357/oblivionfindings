import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import RespiteSubnav from '@/components/respite-subnav';
import { formatDateTime } from '@/lib/date-format';
import { Head, Link, router } from '@inertiajs/react';

type Props = {
    note: any;
};

export default function HandoverNoteShow({ note }: Props) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Respite', href: '/respite' }, { title: 'Handover Notes', href: '/respite/handover-notes' }, { title: `Note #${note.id}`, href: `/respite/handover-notes/${note.id}` }]}>
            <Head title="Handover Note" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Handover Note #{note.id}</h1>
                        <div className="mt-1 text-sm text-muted-foreground">
                            {note.stay?.client?.first_name} {note.stay?.client?.last_name}
                        </div>
                    </div>
                    <Link href="/respite/handover-notes" className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                        Back to list
                    </Link>
                </div>
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
                        <div className="text-xs text-muted-foreground">Created: {formatDateTime(note.created_at)}</div>
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
                                <div>Acknowledged at: {formatDateTime(note.acknowledged_at)}</div>
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
            </div>
        </AppLayout>
    );
}
