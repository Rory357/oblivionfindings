/**
 * Approved Bookings pane — card grid with a pre-stay readiness ring, status
 * filter, inline confirm (pending → confirmed), and a read-only detail pop-up.
 */
import { Button } from '@/components/ui/button';
import { BedDouble, CalendarCheck, CalendarDays, CheckCircle2, Eye, MapPin, Users } from 'lucide-react';
import { useState } from 'react';
import { respiteActions } from '../actions';
import { Avatar, fmtRange, StatusBadge } from '../shared';
import { Empty, FilterChip, PaneHead, SearchBox } from '../pane-kit';
import type { RespiteBookingRow, RespiteCan } from '../types';

export function BookingsPane({
    bookings,
    can,
    onView,
}: {
    bookings: RespiteBookingRow[];
    can: RespiteCan;
    onView: (row: RespiteBookingRow) => void;
}) {
    const [q, setQ] = useState('');
    const [status, setStatus] = useState('all');

    const rows = bookings.filter(
        (b) =>
            (status === 'all' || b.status === status) &&
            (q === '' || `${b.client} ${b.site ?? ''} ${b.coordinator ?? ''}`.toLowerCase().includes(q.toLowerCase())),
    );

    return (
        <div>
            <PaneHead icon={CalendarCheck} title="Approved Bookings" count={`${rows.length} of ${bookings.length}`} />
            <div className="mb-4 flex flex-wrap items-center gap-2">
                <SearchBox value={q} onChange={setQ} placeholder="Search client, home or coordinator…" />
                {['all', 'pending', 'confirmed', 'in_progress', 'completed'].map((s) => (
                    <FilterChip key={s} active={status === s} onClick={() => setStatus(s)}>
                        {s === 'all' ? 'All' : s === 'in_progress' ? 'In house' : s[0].toUpperCase() + s.slice(1)}
                    </FilterChip>
                ))}
            </div>

            {rows.length === 0 ? (
                <Empty icon={CalendarCheck} title="No bookings match" />
            ) : (
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    {rows.map((b) => (
                        <BookingCard key={b.id} b={b} can={can} onView={onView} />
                    ))}
                </div>
            )}
        </div>
    );
}

function ReadinessRing({ pct }: { pct: number }) {
    const r = 18;
    const c = 2 * Math.PI * r;
    const tone = pct >= 90 ? 'var(--status-success)' : pct >= 70 ? 'var(--status-warning)' : 'var(--status-critical)';
    return (
        <div className="relative h-[46px] w-[46px] shrink-0">
            <svg width="46" height="46" className="-rotate-90">
                <circle cx="23" cy="23" r={r} fill="none" stroke="var(--muted)" strokeWidth="4" />
                <circle cx="23" cy="23" r={r} fill="none" stroke={tone} strokeWidth="4" strokeLinecap="round" strokeDasharray={c} strokeDashoffset={c * (1 - pct / 100)} />
            </svg>
            <span className="absolute inset-0 grid place-items-center text-xs font-bold tabular-nums">{pct}</span>
        </div>
    );
}

function BookingCard({ b, can, onView }: { b: RespiteBookingRow; can: RespiteCan; onView: (row: RespiteBookingRow) => void }) {
    return (
        <div className="flex flex-col gap-3 rounded-[14px] border border-border bg-card p-4 transition-shadow hover:shadow-sm">
            <div className="flex items-center gap-3">
                <Avatar name={b.client} className="h-10 w-10 text-sm" />
                <div className="min-w-0 flex-1">
                    <div className="truncate text-[15px] font-bold">{b.client}</div>
                    <div className="text-[11.5px] text-muted-foreground">{b.ref}</div>
                </div>
                <StatusBadge status={b.status} />
            </div>

            <div className="flex items-center gap-2 rounded-[10px] bg-muted px-3 py-2.5 text-[13px]">
                <CalendarDays className="h-4 w-4 text-primary" />
                <span className="font-semibold">{fmtRange(b.start, b.end)}</span>
                {b.nights != null ? <span className="ml-auto text-muted-foreground">{b.nights} nights</span> : null}
            </div>

            <div className="flex items-center gap-3.5">
                <ReadinessRing pct={b.readiness} />
                <div className="min-w-0">
                    <div className="text-[12.5px] font-semibold">{b.readiness >= 100 ? 'Ready for arrival' : 'Pre-stay readiness'}</div>
                    <div className="text-[11.5px] text-muted-foreground">
                        {b.readiness >= 100 ? 'All checks complete' : 'Care plan + eMAR pending'}
                    </div>
                </div>
            </div>

            <div className="flex flex-wrap gap-x-3.5 gap-y-1 text-xs text-muted-foreground">
                {b.site ? <span className="inline-flex items-center gap-1"><MapPin className="h-3.5 w-3.5" />{b.site}</span> : null}
                {b.coordinator ? <span className="inline-flex items-center gap-1"><Users className="h-3.5 w-3.5" />{b.coordinator}</span> : null}
                <span className="inline-flex items-center gap-1"><BedDouble className="h-3.5 w-3.5" />{b.hasStay ? 'Stay open' : 'No stay yet'}</span>
            </div>

            <div className="mt-0.5 flex gap-2">
                {can.bookingsManage && b.status === 'pending' ? (
                    <Button size="sm" className="flex-1" onClick={() => respiteActions.confirmBooking(b.id)}>
                        <CheckCircle2 className="h-3.5 w-3.5" /> Confirm
                    </Button>
                ) : null}
                <Button size="sm" variant="outline" className={b.status === 'pending' && can.bookingsManage ? '' : 'flex-1'} onClick={() => onView(b)}>
                    <Eye className="h-3.5 w-3.5" /> Details
                </Button>
            </div>
        </div>
    );
}
