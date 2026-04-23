import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import RespiteSubnav from '@/components/respite-subnav';
import { formatDateTime } from '@/lib/date-format';
import { Head, Link, router } from '@inertiajs/react';

type Props = {
    booking: any;
};

export default function RespiteBookingShow({ booking }: Props) {
    const hoursBetween = (start?: string | null, end?: string | null) => {
        if (!start || !end) return '—';
        const s = new Date(start).getTime();
        const e = new Date(end).getTime();
        if (Number.isNaN(s) || Number.isNaN(e) || e <= s) return '—';
        const hours = (e - s) / (1000 * 60 * 60);
        return `${hours.toFixed(2)}h`;
    };

    return (
        <AppLayout breadcrumbs={[
            { title: 'Respite', href: '/respite' },
            { title: 'Booking', href: `/respite/bookings/${booking.id}` },
        ]}>
            <Head title="Respite Booking" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">
                            {booking.client?.first_name} {booking.client?.last_name}
                        </h1>
                        <div className="mt-2 flex flex-wrap gap-2">
                            <Badge variant="outline">{booking.status}</Badge>
                        </div>
                    </div>
                    <Link href="/respite" className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                        Back to list
                    </Link>
                </div>
                <RespiteSubnav />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Booking Details</CardTitle>
                    </CardHeader>
                    <CardContent className="text-sm text-muted-foreground space-y-2">
                        <div>Start: {formatDateTime(booking.start_at)}</div>
                        <div>End: {formatDateTime(booking.end_at)}</div>
                        <div>Hours: {hoursBetween(booking.start_at, booking.end_at)}</div>
                        <div>Coordinator: {booking.coordinator?.name || 'Unassigned'}</div>
                        <div>
                            Shift:{' '}
                            {booking.shift ? (
                                <Link href={`/shifts/${booking.shift.id}`} className="text-primary hover:text-primary">
                                    View shift
                                </Link>
                            ) : (
                                'Not created'
                            )}
                        </div>
                    </CardContent>
                </Card>

                {booking.status === 'pending' && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Actions</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-wrap gap-2">
                            <Button size="sm" onClick={() => router.post(`/respite/bookings/${booking.id}/confirm`)}>
                                Confirm Booking
                            </Button>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
