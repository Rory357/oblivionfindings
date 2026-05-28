import { Link } from '@inertiajs/react';
import {
    Calendar,
    CalendarCheck,
    CheckCircle,
    Clock,
    Search,
    User,
    XCircle,
} from 'lucide-react';
import { useMemo, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

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
};

export type AvailabilityStaffMember = {
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
};

const DAY_NAMES = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

export function AvailabilityPane({
    staff,
    upcomingLeave,
    canManage,
}: AvailabilityPaneProps) {
    const [search, setSearch] = useState('');
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

    // "Declared today" — staff who have an availability block for today's
    // day-of-week. A row in staff_availabilities means the worker said
    // "I'm available this day during these hours", so existence == availability.
    const declaredToday = useMemo(() => {
        const today = new Date().getDay();
        return filtered.filter((member) =>
            member.staff_availability?.some(
                (slot) => slot.day_of_week === today,
            ),
        ).length;
    }, [filtered]);

    const onLeave = useMemo(() => {
        const now = new Date().toISOString();
        return filtered.filter((member) =>
            (upcomingLeave[member.id] ?? []).some(
                (leave) => leave.starts_at <= now && leave.ends_at >= now,
            ),
        ).length;
    }, [filtered, upcomingLeave]);

    return (
        <section className="space-y-4" aria-labelledby="availability-heading">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2
                        id="availability-heading"
                        className="text-base font-bold tracking-tight"
                    >
                        Staff availability
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Weekly availability, planned time off, and approved
                        leave for roster decisions.
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
                        className="h-9 pl-9 text-sm"
                    />
                </div>
            </div>

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <AvailabilityStat
                    icon={User}
                    label="Total staff"
                    value={filtered.length}
                    tone="info"
                />
                <AvailabilityStat
                    icon={CheckCircle}
                    label="Declared today"
                    value={declaredToday}
                    tone="success"
                />
                <AvailabilityStat
                    icon={Calendar}
                    label="Currently on leave"
                    value={onLeave}
                    tone="warning"
                />
            </div>

            <div className="space-y-3">
                {filtered.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center justify-center py-12">
                            <User className="mb-3 h-10 w-10 text-muted-foreground/30" />
                            <p className="text-sm text-muted-foreground">
                                No staff found.
                            </p>
                        </CardContent>
                    </Card>
                ) : null}

                {filtered.map((member) => {
                    const memberLeave = upcomingLeave[member.id] ?? [];
                    const availability = member.staff_availability ?? [];
                    const timeOff = member.staff_time_off ?? [];

                    return (
                        <Card key={member.id}>
                            <CardHeader className="pb-2">
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <CardTitle className="text-sm font-semibold">
                                            {member.name}
                                        </CardTitle>
                                        <p className="text-xs text-muted-foreground">
                                            {member.email}
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap items-center justify-end gap-2">
                                        {member.role ? (
                                            <Badge
                                                variant="outline"
                                                className="text-xs capitalize"
                                            >
                                                {member.role.replace(
                                                    /_/g,
                                                    ' ',
                                                )}
                                            </Badge>
                                        ) : null}
                                        {canManage ? (
                                            <Button
                                                asChild
                                                size="sm"
                                                variant="outline"
                                            >
                                                <Link
                                                    href={`/staff/${member.id}/availability`}
                                                >
                                                    <CalendarCheck className="h-3.5 w-3.5" />
                                                    Edit availability for{' '}
                                                    {member.name}
                                                </Link>
                                            </Button>
                                        ) : null}
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {availability.length > 0 ? (
                                    <div>
                                        <p className="mb-1.5 text-xs font-medium text-muted-foreground">
                                            Weekly availability
                                        </p>
                                        <div className="flex flex-wrap gap-1">
                                            {DAY_NAMES.map((day, index) => {
                                                const slot = availability.find(
                                                    (item) =>
                                                        item.day_of_week ===
                                                        index,
                                                );
                                                // A slot for this day == worker declared they're free.
                                                const isAvailable = Boolean(slot);

                                                return (
                                                    <div
                                                        key={day}
                                                        className={
                                                            isAvailable
                                                                ? 'flex h-9 w-11 flex-col items-center justify-center rounded-md bg-status-success-bg text-xs text-status-success'
                                                                : 'flex h-9 w-11 flex-col items-center justify-center rounded-md bg-muted text-xs text-muted-foreground'
                                                        }
                                                        title={
                                                            slot
                                                                ? `${slot.start_time} - ${slot.end_time}`
                                                                : undefined
                                                        }
                                                    >
                                                        <span className="text-[10px] font-medium">
                                                            {day}
                                                        </span>
                                                        {isAvailable ? (
                                                            <CheckCircle className="h-3 w-3" />
                                                        ) : (
                                                            <XCircle className="h-3 w-3 opacity-40" />
                                                        )}
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    </div>
                                ) : null}

                                {timeOff.length > 0 ? (
                                    <AvailabilityEventList
                                        icon={Clock}
                                        title="Scheduled time off"
                                        items={timeOff.map((off) => ({
                                            id: off.id,
                                            label: formatRange(
                                                off.starts_at,
                                                off.ends_at,
                                            ),
                                            meta: off.reason,
                                            tone: 'warning',
                                        }))}
                                    />
                                ) : null}

                                {memberLeave.length > 0 ? (
                                    <AvailabilityEventList
                                        icon={Calendar}
                                        title="Approved leave"
                                        items={memberLeave.map((leave) => ({
                                            id: leave.id,
                                            label: formatRange(
                                                leave.starts_at,
                                                leave.ends_at,
                                            ),
                                            meta: formatLeaveType(
                                                leave.leave_type,
                                            ),
                                            tone: 'info',
                                        }))}
                                    />
                                ) : null}

                                {availability.length === 0 &&
                                timeOff.length === 0 &&
                                memberLeave.length === 0 ? (
                                    <p className="text-xs text-muted-foreground/70">
                                        No availability data configured.
                                    </p>
                                ) : null}
                            </CardContent>
                        </Card>
                    );
                })}
            </div>
        </section>
    );
}

function AvailabilityStat({
    icon: Icon,
    label,
    value,
    tone,
}: {
    icon: typeof User;
    label: string;
    value: number;
    tone: 'info' | 'success' | 'warning';
}) {
    const toneClass =
        tone === 'success'
            ? 'bg-status-success-bg text-status-success'
            : tone === 'warning'
              ? 'bg-status-warning-bg text-status-warning'
              : 'bg-status-info-bg text-status-info';

    return (
        <Card>
            <CardContent className="flex items-center gap-3 pt-5">
                <div
                    className={`flex h-10 w-10 items-center justify-center rounded-lg ${toneClass}`}
                >
                    <Icon className="h-5 w-5" />
                </div>
                <div>
                    <p className="text-2xl font-bold tabular-nums">{value}</p>
                    <p className="text-xs text-muted-foreground">{label}</p>
                </div>
            </CardContent>
        </Card>
    );
}

function AvailabilityEventList({
    icon: Icon,
    title,
    items,
}: {
    icon: typeof Calendar;
    title: string;
    items: Array<{
        id: number;
        label: string;
        meta?: string | null;
        tone: 'info' | 'warning';
    }>;
}) {
    return (
        <div>
            <p className="mb-1 text-xs font-medium text-muted-foreground">
                {title}
            </p>
            <div className="space-y-1">
                {items.map((item) => (
                    <div key={item.id} className="flex items-center gap-2 text-xs">
                        <Icon
                            className={
                                item.tone === 'warning'
                                    ? 'h-3 w-3 text-status-warning'
                                    : 'h-3 w-3 text-status-info'
                            }
                        />
                        {item.meta ? (
                            <Badge variant="secondary" className="text-[10px]">
                                {item.meta}
                            </Badge>
                        ) : null}
                        <span>{item.label}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}

function formatRange(startsAt: string, endsAt: string): string {
    return `${formatDate(startsAt)} - ${formatDate(endsAt)}`;
}

function formatDate(value: string): string {
    return new Date(value).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
    });
}

function formatLeaveType(value: string): string {
    return value?.replace(/_/g, ' ') ?? 'Leave';
}

export default AvailabilityPane;
