import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import RespiteSubnav from '@/components/respite-subnav';
import { formatDateTime } from '@/lib/date-format';
import { Head } from '@inertiajs/react';

type Props = {
    events: any[];
    bookings: any[];
};

export default function RespiteCalendar({ events, bookings }: Props) {
    return (
        <AppLayout breadcrumbs={[
            { title: 'Respite', href: '/respite' },
            { title: 'Calendar', href: '/respite/calendar' },
        ]}>
            <Head title="Respite Calendar" />

            <div className="space-y-4">
                <div>
                    <h1 className="text-lg font-semibold">Respite Calendar</h1>
                    <div className="mt-1 text-sm text-muted-foreground">
                        Respite bookings and stays (module view).
                    </div>
                </div>
                <RespiteSubnav />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Upcoming Events</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2 text-sm text-muted-foreground">
                        {events.map((event) => (
                            <div key={event.id} className="rounded-md border px-3 py-2">
                                <div className="font-medium">{event.event_type}</div>
                                <div>{formatDateTime(event.start_at)} → {formatDateTime(event.end_at)}</div>
                            </div>
                        ))}
                        {!events.length && (
                            <div className="text-sm text-muted-foreground">No calendar events yet.</div>
                        )}
                    </CardContent>
                </Card>

                {!events.length && bookings.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Confirmed Bookings (fallback)</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2 text-sm text-muted-foreground">
                            {bookings.map((booking) => (
                                <div key={booking.id} className="rounded-md border px-3 py-2">
                                    <div className="font-medium">Booking #{booking.id}</div>
                                    <div>{formatDateTime(booking.start_at)} → {formatDateTime(booking.end_at)}</div>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
