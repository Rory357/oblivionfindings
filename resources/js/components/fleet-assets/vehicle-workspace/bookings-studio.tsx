import { localDateTimeLabel } from '@/components/fleet-assets/maintenance/date-time-field';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { toDatetimeLocal } from '@/lib/datetime';
import { CalendarContextMenu } from '@/pages/sites/calendar/_parts';
import {
    ArrowRight,
    CalendarDays,
    FileText,
    KeyRound,
    MoreHorizontal,
} from 'lucide-react';
import { Fragment, useState } from 'react';
import type { CustodyRow, VehicleCalendarSummary } from './calendar-types';
import './studio.css';
import {
    custodyActions,
    type CalendarAction,
} from './vehicle-calendar-actions';
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
    onAction,
    onResolveUseProblem,
}: {
    summary: VehicleCalendarSummary | null;
    onRequest: () => void;
    onBlock: () => void;
    /** Runs one of the shared booking/period actions (the calendar menu's list). */
    onAction: (action: CalendarAction) => void;
    /** Opens where the vehicle-use problem is resolved (e.g. its evidence row). */
    onResolveUseProblem?: () => void;
}) {
    const rows = ordered(summary?.bookings ?? []);
    const [menu, setMenu] = useState<{
        x: number;
        y: number;
        row: CustodyRow;
        opener: HTMLElement | null;
    } | null>(null);
    /** Every action for a row: its record first, then the calendar's own list. */
    const actionsFor = (row: CustodyRow): CalendarAction[] =>
        summary
            ? [
                  {
                      key: 'record',
                      label: 'View record & history',
                      icon: FileText,
                      intent: { type: 'custody-record', row },
                  },
                  ...custodyActions(row, summary),
              ]
            : [];
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
            {summary && !summary.can.view_bookings && (
                <StudioNotice title="Bookings stay with the vehicle’s Site">
                    You can see this vehicle across Sites. Its bookings and
                    drivers are shown only to people at the vehicle’s Site; the
                    calendar shows when it’s busy.
                </StudioNotice>
            )}
            {summary?.use_problem && (
                <StudioNotice title="Vehicle use needs review">
                    {summary.use_problem} Requests can be recorded; confirmation
                    and checkout remain blocked.
                    {onResolveUseProblem && (
                        <Button
                            variant="link"
                            className="h-auto px-1 py-0 align-baseline"
                            onClick={onResolveUseProblem}
                        >
                            Resolve this
                        </Button>
                    )}
                </StudioNotice>
            )}
            {rows.map((row) => {
                const booking = row.kind === 'booking' ? row : null;
                const actions = actionsFor(row);
                // The card's one button is the row's next step.
                const next = LIVE.includes(row.status)
                    ? actions.find((action) =>
                          ['approve', 'checkout', 'return'].includes(
                              action.key,
                          ),
                      )
                    : undefined;
                const label = row.reference ?? `#${row.id}`;
                return (
                    <div
                        className="booking-card"
                        key={`${row.kind}-${row.id}`}
                        onContextMenu={(event) => {
                            if (!actions.length) return;
                            event.preventDefault();
                            const rect =
                                event.currentTarget.getBoundingClientRect();
                            const keyboard =
                                event.clientX === 0 && event.clientY === 0;
                            setMenu({
                                x: keyboard ? rect.left + 16 : event.clientX,
                                y: keyboard ? rect.top + 16 : event.clientY,
                                row,
                                opener:
                                    document.activeElement instanceof
                                    HTMLElement
                                        ? document.activeElement
                                        : null,
                            });
                        }}
                    >
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
                                    : row.kind === 'unavailable' &&
                                        row.work_order_id
                                      ? 'Held by a service appointment'
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
                            {next && (
                                <Button
                                    size="sm"
                                    disabled={next.disabled}
                                    title={next.reason}
                                    onClick={() => onAction(next)}
                                >
                                    {next.key === 'approve'
                                        ? 'Review & approve'
                                        : next.key === 'checkout'
                                          ? 'Check out'
                                          : 'Record return'}
                                </Button>
                            )}
                            {actions.length > 0 && (
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
                                        {actions.map((action, index) => (
                                            <Fragment key={action.key}>
                                                {action.destructive &&
                                                    !actions[index - 1]
                                                        ?.destructive && (
                                                        <DropdownMenuSeparator />
                                                    )}
                                                <DropdownMenuItem
                                                    disabled={action.disabled}
                                                    variant={
                                                        action.destructive
                                                            ? 'destructive'
                                                            : 'default'
                                                    }
                                                    onSelect={() =>
                                                        onAction(action)
                                                    }
                                                >
                                                    <action.icon className="size-4" />
                                                    <span>
                                                        {action.label}
                                                        {action.reason && (
                                                            <small className="block text-xs text-muted-foreground">
                                                                {action.reason}
                                                            </small>
                                                        )}
                                                    </span>
                                                </DropdownMenuItem>
                                            </Fragment>
                                        ))}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            )}
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
            {menu && summary && (
                <CalendarContextMenu
                    key={`${menu.row.kind}-${menu.row.id}-${menu.x}-${menu.y}`}
                    x={menu.x}
                    y={menu.y}
                    chip={
                        menu.row.kind === 'booking' ? 'Booking' : 'Unavailable'
                    }
                    chipIcon={KeyRound}
                    heading={menu.row.purpose}
                    subheading={`${wallTime(menu.row.starts_at)} → ${wallTime(menu.row.ends_at)} · ${menu.row.status_label}`}
                    ariaLabel="Booking actions"
                    returnFocus={menu.opener}
                    onClose={() => setMenu(null)}
                    sections={[false, true].map((destructive) => ({
                        key: destructive ? 'destructive' : 'actions',
                        items: actionsFor(menu.row)
                            .filter(
                                (action) =>
                                    !!action.destructive === destructive,
                            )
                            .map((action) => ({
                                key: action.key,
                                label: action.label,
                                icon: action.icon,
                                disabled: action.disabled,
                                destructive: action.destructive,
                                detail: action.reason,
                                onSelect: () => onAction(action),
                            })),
                    }))}
                />
            )}
        </section>
    );
}
