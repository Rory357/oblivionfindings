import { PageHero, PageLayout } from '@/components/page';
import RespiteSubnav from '@/components/respite-subnav';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { formatDateTimeLong } from '@/lib/datetime';
import { Head, Link } from '@inertiajs/react';
import { CalendarCheck } from 'lucide-react';

type Props = {
    bookings: any;
};

export default function RespiteBookingsIndex({ bookings }: Props) {
    const totalBookings = bookings.data.length;
    const confirmedCount = bookings.data.filter(
        (b: any) => b.status === 'confirmed',
    ).length;
    const inProgressCount = bookings.data.filter(
        (b: any) => b.status === 'in_progress',
    ).length;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Respite', href: '/respite' },
                { title: 'Bookings', href: '/respite/bookings' },
            ]}
        >
            <Head title="Respite Bookings" />

            <PageLayout
                hero={
                    <PageHero
                        icon={CalendarCheck}
                        title="Approved Bookings"
                        description="Central list of bookings created from approved requests."
                        stats={[
                            { label: 'Total', value: totalBookings },
                            { label: 'Confirmed', value: confirmedCount },
                            { label: 'In progress', value: inProgressCount },
                        ]}
                    />
                }
            >
                <RespiteSubnav />

                <div className="space-y-2">
                    {bookings.data.map((b: any) => (
                        <Card key={b.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="font-semibold">
                                                {b.client?.first_name}{' '}
                                                {b.client?.last_name}
                                            </div>
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                <Badge variant="outline">
                                                    {b.status}
                                                </Badge>
                                            </div>
                                            <div className="mt-2 text-xs text-muted-foreground">
                                                {formatDateTimeLong(b.start_at)}{' '}
                                                → {formatDateTimeLong(b.end_at)}
                                            </div>
                                        </div>
                                        <Link
                                            href={`/respite/bookings/${b.id}`}
                                            className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                        >
                                            View
                                        </Link>
                                    </div>
                                </CardTitle>
                            </CardHeader>
                            {b.coordinator && (
                                <CardContent className="text-xs text-muted-foreground">
                                    Coordinator: {b.coordinator.name}
                                </CardContent>
                            )}
                        </Card>
                    ))}
                    {!bookings.data.length && (
                        <div className="py-8 text-center text-sm text-muted-foreground">
                            No approved bookings found.
                        </div>
                    )}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
