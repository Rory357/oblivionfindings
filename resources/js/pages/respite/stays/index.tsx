import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import RespiteSubnav from '@/components/respite-subnav';
import { formatDateTime } from '@/lib/date-format';
import { Head, Link } from '@inertiajs/react';

type Props = {
    stays: any;
};

export default function RespiteStaysIndex({ stays }: Props) {
    return (
        <AppLayout breadcrumbs={[
            { title: 'Respite', href: '/respite' },
            { title: 'Stays', href: '/respite/stays' },
        ]}>
            <Head title="Respite Stays" />

            <div className="space-y-4">
                <div>
                    <h1 className="text-lg font-semibold">Stays</h1>
                    <div className="mt-1 text-sm text-muted-foreground">
                        Active and past respite stays.
                    </div>
                </div>
                <RespiteSubnav />

                <div className="space-y-2">
                    {stays.data.map((s: any) => (
                        <Card key={s.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="font-semibold">
                                                {s.client?.first_name} {s.client?.last_name}
                                            </div>
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                <Badge variant="outline">{s.status}</Badge>
                                            </div>
                                            <div className="mt-2 text-xs text-muted-foreground">
                                                {s.actual_start && <>Started: {formatDateTime(s.actual_start)}</>}
                                                {s.actual_end && <> — Ended: {formatDateTime(s.actual_end)}</>}
                                            </div>
                                        </div>
                                        <Link href={`/respite/stays/${s.id}`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                            View
                                        </Link>
                                    </div>
                                </CardTitle>
                            </CardHeader>
                        </Card>
                    ))}
                    {!stays.data.length && (
                        <div className="py-8 text-center text-sm text-muted-foreground">
                            No stays found.
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
