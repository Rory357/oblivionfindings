import { PageHero, PageLayout } from '@/components/page';
import RespiteSubnav from '@/components/respite-subnav';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { formatDateTimeLong } from '@/lib/datetime';
import { Head, Link, router } from '@inertiajs/react';

type Props = {
    request: any;
    booking: any | null;
};

export default function RespiteRequestShow({ request, booking }: Props) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Respite', href: '/respite' },
                {
                    title: 'Booking Request',
                    href: `/respite/requests/${request.id}`,
                },
            ]}
        >
            <Head title="Respite Booking Request" />

            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref="/respite/requests"
                        title={
                            `${request.client?.first_name ?? ''} ${request.client?.last_name ?? ''}`.trim() ||
                            'Booking Request'
                        }
                        actions={
                            <Badge variant="outline">{request.status}</Badge>
                        }
                    />
                }
            >
                <RespiteSubnav />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Request Details
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2 text-sm text-muted-foreground">
                        <div>
                            Requested:{' '}
                            {formatDateTimeLong(request.requested_start)} →{' '}
                            {formatDateTimeLong(request.requested_end)}
                        </div>
                        <div>
                            Funding: {request.funding_reference || 'Not set'}
                        </div>
                        <div>Notes: {request.preference_notes || 'None'}</div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Linked Booking
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2 text-sm text-muted-foreground">
                        {booking ? (
                            <>
                                <div>Booking #{booking.id}</div>
                                <div>Status: {booking.status}</div>
                                <div>
                                    <Link
                                        href={`/respite/bookings/${booking.id}`}
                                        className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                    >
                                        View Booking
                                    </Link>
                                </div>
                            </>
                        ) : (
                            <div>
                                No booking created yet. Approve the request to
                                auto-create a booking.
                            </div>
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
                                onClick={() =>
                                    router.post(
                                        `/respite/requests/${request.id}/approve`,
                                    )
                                }
                            >
                                Approve
                            </Button>
                        </CardContent>
                    </Card>
                )}
            </PageLayout>
        </AppLayout>
    );
}
