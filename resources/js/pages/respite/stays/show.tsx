import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import RespiteSubnav from '@/components/respite-subnav';
import { formatDateTime } from '@/lib/date-format';
import { Head, Link, router } from '@inertiajs/react';

type Props = {
    stay: any;
};

export default function RespiteStayShow({ stay }: Props) {
    return (
        <AppLayout breadcrumbs={[
            { title: 'Respite', href: '/respite' },
            { title: 'Stay', href: `/respite/stays/${stay.id}` },
        ]}>
            <Head title="Respite Stay" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">
                            {stay.client?.first_name} {stay.client?.last_name}
                        </h1>
                        <div className="mt-2 flex flex-wrap gap-2">
                            <Badge variant="outline">{stay.status}</Badge>
                        </div>
                    </div>
                    <Link href="/respite" className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                        Back to list
                    </Link>
                </div>
                <RespiteSubnav />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Stay Details</CardTitle>
                    </CardHeader>
                    <CardContent className="text-sm text-slate-600 space-y-2">
                        <div>Start: {formatDateTime(stay.actual_start)}</div>
                        <div>End: {formatDateTime(stay.actual_end)}</div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Actions</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-wrap gap-2">
                        {stay.status === 'admitted' && (
                            <Button size="sm" onClick={() => router.post(`/respite/stays/${stay.id}/check-in`)}>
                                Check In
                            </Button>
                        )}
                        {stay.status !== 'discharged' && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => {
                                    const newEnd = prompt('Enter new end date (YYYY-MM-DD):');
                                    if (newEnd) {
                                        router.post(`/respite/stays/${stay.id}/extend`, { new_end: `${newEnd}T12:00:00` });
                                    }
                                }}
                            >
                                Extend
                            </Button>
                        )}
                        {stay.status !== 'discharged' && (
                            <Button
                                size="sm"
                                variant="destructive"
                                onClick={() => {
                                    const summary = prompt('Enter discharge summary:');
                                    if (summary) {
                                        router.post(`/respite/stays/${stay.id}/discharge`, { discharge_summary: summary });
                                    }
                                }}
                            >
                                Discharge
                            </Button>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
