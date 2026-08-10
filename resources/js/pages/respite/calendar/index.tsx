import { PageHero, PageLayout } from '@/components/page';
import RespiteSubnav from '@/components/respite-subnav';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { formatDateTimeLong } from '@/lib/datetime';
import { Head } from '@inertiajs/react';
import { Calendar } from 'lucide-react';

type Props = {
    events: any[];
    bookings: any[];
};

export default function RespiteCalendar({ events, bookings }: Props) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Respite', href: '/respite' },
                { title: 'Calendar', href: '/respite/calendar' },
            ]}
        >
            <Head title="Respite Calendar" />

            <PageLayout
                hero={
                    <PageHero
                        icon={Calendar}
                        title="Respite Calendar"
                        description="Respite bookings and stays (module view)."
                        stats={[
                            { label: 'Events', value: events.length },
                            { label: 'Bookings', value: bookings.length },
                        ]}
                    />
                }
            >
                <RespiteSubnav />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Upcoming Events
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2 text-sm text-muted-foreground">
                        {events.map((event) => (
                            <div
                                key={event.id}
                                className="rounded-md border px-3 py-2"
                            >
                                <div className="font-medium">
                                    {event.event_type}
                                </div>
                                <div>
                                    {formatDateTimeLong(event.start_at)} →{' '}
                                    {formatDateTimeLong(event.end_at)}
                                </div>
                            </div>
                        ))}
                        {!events.length && (
                            <div className="text-sm text-muted-foreground">
                                No calendar events yet.
                            </div>
                        )}
                    </CardContent>
                </Card>

                {!events.length && bookings.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Confirmed Bookings (fallback)
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2 text-sm text-muted-foreground">
                            {bookings.map((booking) => (
                                <div
                                    key={booking.id}
                                    className="rounded-md border px-3 py-2"
                                >
                                    <div className="font-medium">
                                        Booking #{booking.id}
                                    </div>
                                    <div>
                                        {formatDateTimeLong(booking.start_at)} →{' '}
                                        {formatDateTimeLong(booking.end_at)}
                                    </div>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                )}
            </PageLayout>
        </AppLayout>
    );
}
