import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import RespiteSubnav from '@/components/respite-subnav';
import { formatDateTime } from '@/lib/date-format';
import { Head, Link } from '@inertiajs/react';

type Props = {
    stay: any;
    notes: any[];
};

export default function HandoverNotesForStay({ stay, notes }: Props) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Respite', href: '/respite' }, { title: 'Handover Notes', href: '/respite/handover-notes' }, { title: 'For Stay', href: '#' }]}>
            <Head title="Handover Notes for Stay" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">
                            Handover Notes for {stay.client?.first_name} {stay.client?.last_name}
                        </h1>
                        <div className="mt-1 text-sm text-slate-500">Stay #{stay.id}</div>
                    </div>
                    <Link href={`/respite/handover-notes/create?stay_id=${stay.id}`}>
                        <Button size="sm">New Handover Note</Button>
                    </Link>
                </div>
                <RespiteSubnav />

                <div className="space-y-2">
                    {notes.map((n: any) => (
                        <Card key={n.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="mt-1 flex flex-wrap gap-2">
                                                <Badge variant="outline">{n.handover_type?.replace(/_/g, ' ')}</Badge>
                                                {!n.acknowledged_at && <Badge className="bg-amber-100 text-amber-800">Unacknowledged</Badge>}
                                                {n.acknowledged_at && <Badge className="bg-green-100 text-green-800">Acknowledged</Badge>}
                                            </div>
                                            <div className="mt-2 text-xs text-slate-500 line-clamp-2">{n.notes}</div>
                                            <div className="mt-1 text-xs text-slate-400">{formatDateTime(n.created_at)}</div>
                                        </div>
                                        <Link href={`/respite/handover-notes/${n.id}`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                            View
                                        </Link>
                                    </div>
                                </CardTitle>
                            </CardHeader>
                        </Card>
                    ))}
                    {!notes.length && (
                        <div className="py-8 text-center text-sm text-slate-500">No handover notes found for this stay.</div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
