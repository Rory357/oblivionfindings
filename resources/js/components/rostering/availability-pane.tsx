import { WORKER_TIMEZONE } from '@/lib/datetime';
import { rosterWallTime } from '@/lib/roster-time';
import { CalendarCheck, Search, User } from 'lucide-react';
import { useMemo, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { cn } from '@/lib/utils';

import {
    EditAvailabilityDialog,
    type EditAvailabilityBlock,
} from './edit-availability-dialog';

type TimeOff = {
    id: number;
    reason: string;
    starts_at: string;
    ends_at: string;
};

export type AvailabilityLeaveRequest = {
    id: number;
    leave_type: string;
    starts_at: string;
    ends_at: string;
    status: string;
};

type Availability = {
    id: number;
    day_of_week: number;
    start_time: string;
    end_time: string;
    ends_next_day?: boolean;
};

export type AvailabilityStaffMember = {
    can_manage?: boolean;
    id: number;
    name: string;
    email: string;
    role?: string | null;
    staff_availability?: Availability[];
    staff_time_off?: TimeOff[];
};

export type AvailabilityPaneProps = {
    staff: AvailabilityStaffMember[];
    upcomingLeave: Record<number, AvailabilityLeaveRequest[]>;
    canManage: boolean;
    workerTimezone?: string;
};

const DAY_NAMES = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
function daySlots(availability: Availability[], day: number): string[] {
    return availability.flatMap((slot) => {
        if (slot.day_of_week === day)
            return [
                `${slot.start_time}–${slot.ends_next_day ? '24:00' : slot.end_time}`,
            ];
        if (
            slot.ends_next_day &&
            slot.end_time.slice(0, 5) > '00:00' &&
            (slot.day_of_week + 1) % 7 === day
        )
            return [`00:00–${slot.end_time} (continues)`];
        return [];
    });
}

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .map((w) => w[0]!)
        .slice(0, 2)
        .join('')
        .toUpperCase();
}

export function AvailabilityPane({
    staff,
    upcomingLeave,
    canManage,
    workerTimezone = WORKER_TIMEZONE,
}: AvailabilityPaneProps) {
    const [search, setSearch] = useState('');
    const [editingId, setEditingId] = useState<number | null>(null);
    const editing = staff.find((member) => member.id === editingId) ?? null;
    const searchTerm = search.trim().toLowerCase();

    const filtered = useMemo(
        () =>
            staff.filter(
                (member) =>
                    searchTerm.length === 0 ||
                    member.name.toLowerCase().includes(searchTerm) ||
                    member.email.toLowerCase().includes(searchTerm),
            ),
        [staff, searchTerm],
    );

    // Highlight the current worker-zone day in each staff member’s schedule.
    const todayIdx = new Date(
        `${rosterWallTime(new Date(), workerTimezone).slice(0, 10)}T12:00:00Z`,
    ).getUTCDay();
    const editingBlocks: EditAvailabilityBlock[] = useMemo(() => {
        if (!editing) return [];
        return (editing.staff_availability ?? []).map((slot) => ({
            id: slot.id,
            day_of_week: slot.day_of_week,
            start_time: slot.start_time,
            end_time: slot.end_time,
            ends_next_day: slot.ends_next_day,
        }));
    }, [editing]);

    return (
        <section className="space-y-4" aria-labelledby="availability-heading">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2
                        id="availability-heading"
                        className="text-section-title"
                    >
                        Staff availability
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Weekly availability, planned time off, and approved
                        leave for roster decisions.
                    </p>
                    <p
                        className="mt-1 text-xs text-muted-foreground"
                        role="status"
                    >
                        {filtered.length} of {staff.length} staff shown
                    </p>
                </div>
                <div className="relative w-full sm:w-72">
                    <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        type="search"
                        aria-label="Search staff availability"
                        placeholder="Search staff..."
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        className="frontline-tap pl-9 text-sm"
                    />
                </div>
            </div>

            {filtered.length === 0 ? (
                <Card>
                    <CardContent className="flex flex-col items-center justify-center py-12">
                        <User className="mb-3 h-10 w-10 text-muted-foreground/30" />
                        <p className="text-sm text-muted-foreground">
                            No staff found.
                        </p>
                    </CardContent>
                </Card>
            ) : (
                <div className="grid [grid-template-columns:repeat(auto-fill,minmax(min(100%,320px),1fr))] gap-3">
                    {filtered.map((member) => (
                        <AvailabilityCard
                            key={member.id}
                            member={member}
                            upcomingLeave={upcomingLeave[member.id] ?? []}
                            canManage={member.can_manage ?? canManage}
                            todayIdx={todayIdx}
                            workerTimezone={workerTimezone}
                            onEdit={() => setEditingId(member.id)}
                        />
                    ))}
                </div>
            )}

            <EditAvailabilityDialog
                open={Boolean(editing)}
                onOpenChange={(open) => {
                    if (!open) setEditingId(null);
                }}
                staff={
                    editing
                        ? {
                              id: editing.id,
                              name: editing.name,
                              email: editing.email,
                              role: editing.role,
                          }
                        : null
                }
                canManage={editing?.can_manage ?? canManage}
                blocks={editingBlocks}
                workerTimezone={workerTimezone}
            />
        </section>
    );
}

function AvailabilityCard({
    member,
    upcomingLeave,
    canManage,
    todayIdx,
    onEdit,
    workerTimezone,
}: {
    member: AvailabilityStaffMember;
    upcomingLeave: AvailabilityLeaveRequest[];
    canManage: boolean;
    todayIdx: number;
    onEdit: () => void;
    workerTimezone: string;
}) {
    const availability = member.staff_availability ?? [];
    const timeOff = member.staff_time_off ?? [];
    const daysCovered = DAY_NAMES.filter(
        (_, index) => daySlots(availability, index).length > 0,
    ).length;
    const declaredToday = daySlots(availability, todayIdx).length > 0;
    const now = Date.now();
    const currentlyOnLeave = upcomingLeave.some(
        (leave) =>
            Date.parse(leave.starts_at) <= now &&
            Date.parse(leave.ends_at) > now,
    );
    const status: { label: string; variant: StatusVariant } = currentlyOnLeave
        ? { label: 'On leave', variant: 'warning' }
        : declaredToday
          ? { label: 'Times supplied today', variant: 'info' }
          : daysCovered > 0
            ? { label: `${daysCovered}/7 days set`, variant: 'neutral' }
            : { label: 'No times supplied', variant: 'neutral' };
    return (
        <article className="flex min-w-0 flex-col gap-3 rounded-xl border bg-card p-4 shadow-sm">
            <header className="flex items-start gap-3">
                <div
                    className="grid size-10 shrink-0 place-items-center rounded-full bg-category-hr-bg text-sm font-semibold text-category-hr"
                    aria-hidden="true"
                >
                    {initials(member.name)}
                </div>
                <div className="min-w-0 flex-1">
                    <h3 className="text-sm font-semibold break-words">
                        {member.name}
                    </h3>
                    <p className="text-caption break-words">{member.email}</p>
                    {member.role && (
                        <Badge variant="outline" className="mt-1 capitalize">
                            {member.role.replace(/_/g, ' ')}
                        </Badge>
                    )}
                </div>
            </header>
            <div>
                <StatusBadge variant={status.variant}>
                    {status.label}
                </StatusBadge>
            </div>
            <div>
                <p className="text-caption mb-2">
                    Weekly availability · {availability.length}{' '}
                    {availability.length === 1 ? 'block' : 'blocks'}
                </p>
                <dl className="divide-y rounded-lg border">
                    {[1, 2, 3, 4, 5, 6, 0].map((index) => {
                        const slots = daySlots(availability, index);
                        return (
                            <div
                                key={index}
                                className={cn(
                                    'grid grid-cols-[3rem_1fr] gap-2 px-3 py-2 text-sm',
                                    index === todayIdx && 'bg-accent',
                                )}
                            >
                                <dt className="font-medium">
                                    {DAY_NAMES[index]}
                                    {index === todayIdx && (
                                        <span className="sr-only">
                                            {' '}
                                            (today)
                                        </span>
                                    )}
                                </dt>
                                <dd className="min-w-0 space-y-1">
                                    {slots.length ? (
                                        slots.map((slot, slotIndex) => (
                                            <p
                                                key={slotIndex}
                                                className="tabular-nums"
                                            >
                                                {slot}
                                            </p>
                                        ))
                                    ) : (
                                        <span className="text-muted-foreground">
                                            No times supplied
                                        </span>
                                    )}
                                </dd>
                            </div>
                        );
                    })}
                </dl>
            </div>
            {timeOff.length > 0 && (
                <section
                    className="space-y-2"
                    aria-label={`Planned time off for ${member.name}`}
                >
                    <h4 className="text-sm font-medium">Planned time off</h4>
                    {timeOff.map((off) => (
                        <p key={off.id} className="text-caption">
                            {formatRange(
                                off.starts_at,
                                off.ends_at,
                                workerTimezone,
                            )}
                            {off.reason ? ` · ${off.reason}` : ''}
                        </p>
                    ))}
                </section>
            )}
            {upcomingLeave.length > 0 && (
                <section
                    className="space-y-2"
                    aria-label={`Approved leave for ${member.name}`}
                >
                    <h4 className="text-sm font-medium">Approved leave</h4>
                    {upcomingLeave.map((leave) => (
                        <p key={leave.id} className="text-caption">
                            {formatRange(
                                leave.starts_at,
                                leave.ends_at,
                                workerTimezone,
                            )}{' '}
                            · {leave.leave_type?.replace(/_/g, ' ') || 'Leave'}
                        </p>
                    ))}
                </section>
            )}
            {canManage && (
                <footer className="mt-auto flex justify-end">
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        className="frontline-hit"
                        onClick={onEdit}
                    >
                        <CalendarCheck className="size-4" />
                        Edit availability
                    </Button>
                </footer>
            )}
        </article>
    );
}

function formatRange(
    startsAt: string,
    endsAt: string,
    timeZone: string,
): string {
    const format = (value: string) => {
        const instant = new Date(value);
        return Number.isFinite(instant.getTime())
            ? new Intl.DateTimeFormat('en-NZ', {
                  timeZone,
                  day: 'numeric',
                  month: 'short',
                  year: 'numeric',
              }).format(instant)
            : 'Date unavailable';
    };
    return `${format(startsAt)}–${format(endsAt)}`;
}

export default AvailabilityPane;
