import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { formatDateTimeLong } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';

type RespiteBooking = {
    id: number;
    start_at?: string | null;
    end_at?: string | null;
    status: string;
    shift_id?: number | null;
    coordinator?: { id: number; name: string } | null;
};

type RespiteRequest = {
    id: number;
    requested_start?: string | null;
    requested_end?: string | null;
    status: string;
};

type RespiteAllocation = {
    allocated?: number;
    used?: number;
    booked?: number;
    remaining?: number;
    period_label?: string | null;
    funding_source?: string | null;
} | null;

type RespiteTabProps = {
    clientId: number;
    canCreate?: boolean;
    bookings: RespiteBooking[];
    requests: RespiteRequest[];
    allocation?: RespiteAllocation;
    onNewBooking: () => void;
};

function statValue(value: number | undefined): string {
    return value == null ? '-' : String(value);
}

export function RespiteTab({
    clientId,
    canCreate = false,
    bookings,
    requests,
    allocation,
    onNewBooking,
}: RespiteTabProps) {
    const allocated = allocation?.allocated ?? 0;
    const used = allocation?.used ?? 0;
    const booked = allocation?.booked ?? 0;
    const remaining = allocation?.remaining ?? 0;
    const committed =
        allocated > 0
            ? Math.min(100, Math.round(((used + booked) / allocated) * 100))
            : 0;

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Respite</CardTitle>
                <p className="text-sm text-muted-foreground">
                    Bookings, requests, and annual allocation for this client.
                </p>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="grid gap-3 sm:grid-cols-4">
                    {[
                        ['Allocation', statValue(allocation?.allocated)],
                        ['Used', statValue(allocation?.used)],
                        ['Booked', statValue(allocation?.booked)],
                        ['Remaining', statValue(allocation?.remaining)],
                    ].map(([label, value]) => (
                        // eslint-disable-next-line no-restricted-syntax -- MiniStat tile per the profile pattern language.
                        <div
                            key={label}
                            className="rounded-xl border bg-card px-4 py-3"
                        >
                            <div className="text-xl font-bold text-primary">
                                {value}
                            </div>
                            <div className="text-xs text-muted-foreground">
                                {label}
                            </div>
                        </div>
                    ))}
                </div>

                <div className="rounded-xl border bg-muted/30 p-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p className="text-sm font-semibold">
                                {allocation?.period_label ??
                                    'No allocation period set'}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {allocation?.funding_source
                                    ? `Funding source: ${allocation.funding_source}`
                                    : 'Set an allocation in the Respite workspace to track remaining nights.'}
                            </p>
                        </div>
                        <p className="text-sm font-semibold text-primary">
                            {remaining} night{remaining === 1 ? '' : 's'}{' '}
                            remaining
                        </p>
                    </div>
                    <div className="mt-3 h-3 overflow-hidden rounded-full bg-background">
                        <div
                            className="h-full rounded-full bg-primary"
                            style={{ width: `${committed}%` }}
                        />
                    </div>
                    <div className="mt-2 flex justify-between text-[11px] text-muted-foreground">
                        <span>{used} used</span>
                        <span>{booked} booked</span>
                        <span>{allocated} allocated</span>
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    {canCreate ? (
                        <Button
                            size="sm"
                            onClick={onNewBooking}
                            data-test="respite-new-booking"
                        >
                            <Plus className="mr-1.5 h-3.5 w-3.5" />
                            New booking
                        </Button>
                    ) : null}
                    {canCreate ? (
                        <Button size="sm" variant="outline" asChild>
                            <Link
                                href={`/respite/requests/create?client_id=${clientId}`}
                            >
                                Full intake wizard
                            </Link>
                        </Button>
                    ) : null}
                    <Link
                        href="/respite/requests"
                        className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                    >
                        View booking requests
                    </Link>
                    <Link
                        href="/respite/bookings"
                        className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                    >
                        View approved bookings
                    </Link>
                </div>

                <Separator />

                <div>
                    <div className="text-sm font-medium">Bookings</div>
                    <div className="mt-2 space-y-2">
                        {bookings.map((booking) => (
                            <div
                                key={booking.id}
                                className="rounded-md border p-3"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <div className="text-sm font-medium">
                                            {formatDateTimeLong(
                                                booking.start_at,
                                            )}{' '}
                                            -{' '}
                                            {formatDateTimeLong(booking.end_at)}
                                        </div>
                                        <div className="mt-1 text-xs text-muted-foreground">
                                            Status: {booking.status}
                                            {booking.coordinator?.name
                                                ? ` | Coordinator: ${booking.coordinator.name}`
                                                : ''}
                                        </div>
                                        {booking.shift_id ? (
                                            <div className="mt-1 text-xs text-muted-foreground">
                                                Shift:{' '}
                                                <Link
                                                    href={`/operations/shifts/${booking.shift_id}`}
                                                    className="text-primary hover:text-primary"
                                                >
                                                    View shift
                                                </Link>
                                            </div>
                                        ) : null}
                                    </div>
                                    <Link
                                        href={`/respite/bookings/${booking.id}`}
                                        className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                    >
                                        View
                                    </Link>
                                </div>
                            </div>
                        ))}
                        {!bookings.length && (
                            <div className="text-sm text-muted-foreground">
                                No respite bookings yet.
                            </div>
                        )}
                    </div>
                </div>

                <Separator />

                <div>
                    <div className="text-sm font-medium">Booking Requests</div>
                    <div className="mt-2 space-y-2">
                        {requests.map((request) => (
                            <div
                                key={request.id}
                                className="rounded-md border p-3"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <div className="text-sm font-medium">
                                            {formatDateTimeLong(
                                                request.requested_start,
                                            )}{' '}
                                            -{' '}
                                            {formatDateTimeLong(
                                                request.requested_end,
                                            )}
                                        </div>
                                        <div className="mt-1 text-xs text-muted-foreground">
                                            Status: {request.status}
                                        </div>
                                    </div>
                                    <Link
                                        href={`/respite/requests/${request.id}`}
                                        className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                    >
                                        View
                                    </Link>
                                </div>
                            </div>
                        ))}
                        {!requests.length && (
                            <div className="text-sm text-muted-foreground">
                                No respite booking requests yet.
                            </div>
                        )}
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}
