import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import RespiteSubnav from '@/components/respite-subnav';
import { formatDateTime } from '@/lib/date-format';
import { Head, Link, router } from '@inertiajs/react';

type Props = {
    request: any;
    booking: any | null;
};

export default function RespiteRequestShow({ request, booking }: Props) {
    return (
        <AppLayout breadcrumbs={[
            { title: 'Respite', href: '/respite' },
            { title: 'Booking Request', href: `/respite/requests/${request.id}` },
        ]}>
            <Head title="Respite Booking Request" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">
                            {request.client?.first_name} {request.client?.last_name}
                        </h1>
                        <div className="mt-2 flex flex-wrap gap-2">
                            <Badge variant="outline">{request.status}</Badge>
                        </div>
                    </div>
                    <Link href="/respite" className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                        Back to list
                    </Link>
                </div>
                <RespiteSubnav />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Request Details</CardTitle>
                    </CardHeader>
                    <CardContent className="text-sm text-slate-600 space-y-2">
                        <div>Requested: {formatDateTime(request.requested_start)} → {formatDateTime(request.requested_end)}</div>
                        <div>Funding: {request.funding_reference || 'Not set'}</div>
                        <div>Notes: {request.preference_notes || 'None'}</div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Linked Booking</CardTitle>
                    </CardHeader>
                    <CardContent className="text-sm text-slate-600 space-y-2">
                        {booking ? (
                            <>
                                <div>Booking #{booking.id}</div>
                                <div>Status: {booking.status}</div>
                                <div>
                                    <Link href={`/respite/bookings/${booking.id}`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                        View Booking
                                    </Link>
                                </div>
                            </>
                        ) : (
                            <div>No booking created yet. Approve the request to auto-create a booking.</div>
                        )}
                    </CardContent>
                </Card>

                {request.status !== 'approved' && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Actions</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-wrap gap-2">
                            <Button
                                size="sm"
                                onClick={() => router.post(`/respite/requests/${request.id}/approve`)}
                            >
                                Approve
                            </Button>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
