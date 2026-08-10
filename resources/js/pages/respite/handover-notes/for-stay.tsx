import { PageHero, PageLayout } from '@/components/page';
import RespiteSubnav from '@/components/respite-subnav';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { formatDateTimeLong } from '@/lib/datetime';
import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';

type Props = {
    stay: any;
    notes: any[];
};

export default function HandoverNotesForStay({ stay, notes }: Props) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Respite', href: '/respite' },
                { title: 'Handover Notes', href: '/respite/handover-notes' },
                { title: 'For Stay', href: '#' },
            ]}
        >
            <Head title="Handover Notes for Stay" />

            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref={`/respite/stays/${stay.id}`}
                        title={`Handover Notes for ${stay.client?.first_name ?? ''} ${stay.client?.last_name ?? ''}`.trim()}
                        description={`Stay #${stay.id}`}
                        actions={
                            <Link
                                href={`/respite/handover-notes/create?stay_id=${stay.id}`}
                            >
                                <Button size="sm" variant="outline">
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    New Handover Note
                                </Button>
                            </Link>
                        }
                    />
                }
            >
                <RespiteSubnav />

                <div className="space-y-2">
                    {notes.map((n: any) => (
                        <Card key={n.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="mt-1 flex flex-wrap gap-2">
                                                <Badge variant="outline">
                                                    {n.handover_type?.replace(
                                                        /_/g,
                                                        ' ',
                                                    )}
                                                </Badge>
                                                {!n.acknowledged_at && (
                                                    <Badge className="bg-status-warning-bg text-status-warning">
                                                        Unacknowledged
                                                    </Badge>
                                                )}
                                                {n.acknowledged_at && (
                                                    <Badge className="bg-status-success-bg text-status-success">
                                                        Acknowledged
                                                    </Badge>
                                                )}
                                            </div>
                                            <div className="mt-2 line-clamp-2 text-xs text-muted-foreground">
                                                {n.notes}
                                            </div>
                                            <div className="mt-1 text-xs text-muted-foreground">
                                                {formatDateTimeLong(
                                                    n.created_at,
                                                )}
                                            </div>
                                        </div>
                                        <Link
                                            href={`/respite/handover-notes/${n.id}`}
                                            className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                        >
                                            View
                                        </Link>
                                    </div>
                                </CardTitle>
                            </CardHeader>
                        </Card>
                    ))}
                    {!notes.length && (
                        <div className="py-8 text-center text-sm text-muted-foreground">
                            No handover notes found for this stay.
                        </div>
                    )}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
