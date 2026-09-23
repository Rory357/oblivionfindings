import { localDateTimeLabel } from '@/components/fleet-assets/maintenance/date-time-field';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { toDatetimeLocal } from '@/lib/datetime';
import {
    ArrowRight,
    CalendarDays,
    KeyRound,
    MoreHorizontal,
} from 'lucide-react';
import type { BookingDecision } from './booking-decision-wizard';
import type { CustodyRow, VehicleCalendarSummary } from './calendar-types';
import './studio.css';
import { StudioNotice } from './wizard-kit';

const JOURNEY = [
    'Request & evidence',
    'Approval route',
    'Confirmed',
    'Keys & checkout',
    'Return & condition',
];

const LIVE = ['pending', 'approved', 'checked_out', 'active'];

const wallTime = (iso: string) => localDateTimeLabel(toDatetimeLocal(iso));

function tone(row: CustodyRow): StatusVariant {
    if (row.status_label === 'Confirmed') return 'success';
    if (row.status_label === 'Pending approval') return 'warning';
    return 'info';
}

/** Live bookings and periods first (soonest first), then recent history. */
function ordered(rows: CustodyRow[]): CustodyRow[] {
    const live = rows
        .filter((row) => LIVE.includes(row.status))
        .sort((a, b) => a.starts_at.localeCompare(b.starts_at));
    const past = rows
        .filter((row) => !LIVE.includes(row.status))
        .sort((a, b) => b.ends_at.localeCompare(a.ends_at));
    return [...live, ...past];
}

/**
 * Bookings & custody, under the vehicle calendar: every booking and
 * unavailable period with its next step, record and evidence.
 */
export function BookingsStudio({
    summary,
    onRequest,
    onBlock,
    onDecision,
    onChangeTimes,
    onRecord,
    onUpload,
}: {
    summary: VehicleCalendarSummary | null;
    onRequest: () => void;
    onBlock: () => void;
    onDecision: (row: CustodyRow, decision: BookingDecision) => void;
    onChangeTimes: (row: CustodyRow) => void;
    onRecord: (row: CustodyRow) => void;
    onUpload: (row: CustodyRow) => void;
}) {
    const rows = ordered(summary?.bookings ?? []);
    return (
        <section
            className="studio-card"
            aria-labelledby="bookings-custody-title"
        >
            <div className="studio-section-heading">
                <div>
                    <span className="studio-eyebrow">BOOKING WORKFLOW</span>
                    <h2
                        id="bookings-custody-title"
                        className="text-section-title"
                    >
                        Bookings & custody
                    </h2>
                </div>
                <div className="studio-inline">
                    <Button
                        variant="outline"
                        disabled={!summary?.can.mark_unavailable}
                        onClick={onBlock}
                    >
                        Add unavailable period
                    </Button>
                    <Button
                        disabled={!summary?.can.request}
                        onClick={onRequest}
                    >
                        Request booking
                    </Button>
                </div>
            </div>
            <div className="booking-journey">
                {JOURNEY.map((step, index) => (
                    <span key={step}>
                        <strong>{index + 1}</strong>
                        {step}
                        {index < JOURNEY.length - 1 && (
                            <ArrowRight className="size-[13px]" aria-hidden />
                        )}
                    </span>
                ))}
            </div>
            {summary?.use_problem && (
                <StudioNotice title="Vehicle use needs review">
                    {summary.use_problem} Requests can be recorded; confirmation
                    and checkout remain blocked.
                </StudioNotice>
            )}
            {rows.map((row) => {
                const active = LIVE.includes(row.status);
                const booking = row.kind === 'booking' ? row : null;
                const decision: BookingDecision | null = booking
                    ? booking.status === 'pending'
                        ? 'approve'
                        : booking.status === 'approved'
                          ? 'out'
                          : booking.status === 'checked_out'
                            ? 'return'
                            : null
                    : null;
                const allowed =
                    booking && decision
                        ? decision === 'approve'
                            ? booking.can.approve
                            : decision === 'out'
                              ? booking.can.checkout
                              : booking.can.return
                        : false;
                const label = row.reference ?? `#${row.id}`;
                const checkedOut = row.status === 'checked_out';
                return (
                    <div className="booking-card" key={`${row.kind}-${row.id}`}>
                        <span className="feature-icon">
                            <KeyRound className="size-[22px]" aria-hidden />
                        </span>
                        <div className="booking-main">
                            <strong>{row.purpose}</strong>
                            <small>
                                {row.kind === 'booking'
                                    ? label
                                    : (row.reference ??
                                      'Unavailable period')}{' '}
                                · {wallTime(row.starts_at)} →{' '}
                                {wallTime(row.ends_at)}
                            </small>
                            <p>
                                {booking
                                    ? `${booking.driver?.name ?? 'Driver not recorded'} · ${booking.approval_route === 'not_required' ? 'Approval not required' : 'Approval required'}`
                                    : 'Unavailable period'}
                                {booking?.approval_not_required_reason
                                    ? ` · ${booking.approval_not_required_reason}`
                                    : ''}
                            </p>
                            {booking && (booking.odometer_out ?? 0) > 0 && (
                                <p>
                                    Checkout{' '}
                                    {Number(
                                        booking.odometer_out,
                                    ).toLocaleString('en-NZ')}{' '}
                                    km
                                    {(booking.odometer_in ?? 0) > 0
                                        ? ` → return ${Number(booking.odometer_in).toLocaleString('en-NZ')} km`
                                        : ''}
                                </p>
                            )}
                        </div>
                        <StatusBadge variant={tone(row)}>
                            {row.status_label}
                        </StatusBadge>
                        <div className="booking-actions">
                            {active && booking && decision && (
                                <Button
                                    size="sm"
                                    disabled={!allowed}
                                    onClick={() => onDecision(row, decision)}
                                >
                                    {decision === 'approve'
                                        ? 'Review & approve'
                                        : decision === 'out'
                                          ? 'Check out'
                                          : 'Record return'}
                                </Button>
                            )}
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label={`Actions for ${row.kind === 'booking' ? label : row.purpose}`}
                                    >
                                        <MoreHorizontal className="size-[18px]" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        onSelect={() => onRecord(row)}
                                    >
                                        View record & history
                                    </DropdownMenuItem>
                                    {active && (
                                        <>
                                            <DropdownMenuItem
                                                disabled={
                                                    !row.can.edit || checkedOut
                                                }
                                                onSelect={() =>
                                                    onChangeTimes(row)
                                                }
                                            >
                                                Change times
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                disabled={
                                                    !row.can.cancel ||
                                                    checkedOut
                                                }
                                                onSelect={() =>
                                                    onDecision(row, 'cancel')
                                                }
                                            >
                                                Cancel booking / block
                                            </DropdownMenuItem>
                                        </>
                                    )}
                                    {booking?.can.decline && (
                                        <DropdownMenuItem
                                            onSelect={() =>
                                                onDecision(row, 'decline')
                                            }
                                        >
                                            Decline request
                                        </DropdownMenuItem>
                                    )}
                                    <DropdownMenuItem
                                        disabled={!row.can.upload}
                                        onSelect={() => onUpload(row)}
                                    >
                                        Upload evidence
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    </div>
                );
            })}
            {summary && !rows.length && (
                <div className="studio-empty">
                    <CalendarDays className="size-8" aria-hidden />
                    <h3>No booking requests yet</h3>
                    <p>Select a calendar slot or request a booking to begin.</p>
                </div>
            )}
            <p className="studio-footnote">
                Approval not required is an explicit, evidenced decision by an
                authorised coordinator. Restrictions and conflicts always block
                confirmation or checkout. Report-only staff still need
                coordinator verification.
            </p>
        </section>
    );
}
