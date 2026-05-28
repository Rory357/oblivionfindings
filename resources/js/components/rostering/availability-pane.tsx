import {
    Calendar,
    CalendarCheck,
    CalendarOff,
    CheckCircle,
    Clock,
    Plane,
    Search,
    User,
} from 'lucide-react';
import { useMemo, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
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

function hashHue(name: string): number {
    let h = 0;
    for (let i = 0; i < name.length; i++) {
        h = (h * 31 + name.charCodeAt(i)) % 360;
    }
    return h;
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
}: AvailabilityPaneProps) {
    const [search, setSearch] = useState('');
    const [editing, setEditing] = useState<AvailabilityStaffMember | null>(null);
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
    const todayIdx = new Date().getDay();
    const declaredToday = useMemo(() => {
        return filtered.filter((member) =>
            member.staff_availability?.some(
                (slot) => slot.day_of_week === todayIdx,
            ),
        ).length;
    }, [filtered, todayIdx]);

    const onLeave = useMemo(() => {
        const now = new Date().toISOString();
        return filtered.filter((member) =>
            (upcomingLeave[member.id] ?? []).some(
                (leave) => leave.starts_at <= now && leave.ends_at >= now,
            ),
        ).length;
    }, [filtered, upcomingLeave]);

    const noDataCount = useMemo(
        () =>
            filtered.filter(
                (member) =>
                    (member.staff_availability?.length ?? 0) === 0 &&
                    (member.staff_time_off?.length ?? 0) === 0 &&
                    (upcomingLeave[member.id]?.length ?? 0) === 0,
            ).length,
        [filtered, upcomingLeave],
    );

    const editingBlocks: EditAvailabilityBlock[] = useMemo(() => {
        if (!editing) return [];
        return (editing.staff_availability ?? []).map((slot) => ({
            id: slot.id,
            day_of_week: slot.day_of_week,
            start_time: slot.start_time,
            end_time: slot.end_time,
        }));
    }, [editing]);

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

            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
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
                    icon={Plane}
                    label="Currently on leave"
                    value={onLeave}
                    tone="warning"
                />
                <AvailabilityStat
                    icon={CalendarOff}
                    label="No data"
                    value={noDataCount}
                    tone="muted"
                />
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
                <div className="grid gap-3 [grid-template-columns:repeat(auto-fill,minmax(320px,1fr))]">
                    {filtered.map((member) => (
                        <AvailabilityCard
                            key={member.id}
                            member={member}
                            upcomingLeave={upcomingLeave[member.id] ?? []}
                            canManage={canManage}
                            todayIdx={todayIdx}
                            onEdit={() => setEditing(member)}
                        />
                    ))}
                </div>
            )}

            <EditAvailabilityDialog
                open={Boolean(editing)}
                onOpenChange={(open) => {
                    if (!open) setEditing(null);
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
                blocks={editingBlocks}
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
}: {
    member: AvailabilityStaffMember;
    upcomingLeave: AvailabilityLeaveRequest[];
    canManage: boolean;
    todayIdx: number;
    onEdit: () => void;
}) {
    const availability = member.staff_availability ?? [];
    const timeOff = member.staff_time_off ?? [];
    const hue = hashHue(member.name);
    const inits = initials(member.name);

    const daysCovered = new Set(availability.map((a) => a.day_of_week)).size;
    const totalBlocks = availability.length;
    const hasAnyData =
        availability.length > 0 || timeOff.length > 0 || upcomingLeave.length > 0;

    const declaredToday = availability.some((slot) => slot.day_of_week === todayIdx);
    const now = new Date().toISOString();
    const currentlyOnLeave = upcomingLeave.some(
        (leave) => leave.starts_at <= now && leave.ends_at >= now,
    );

    const statusBadge: { label: string; classes: string } = currentlyOnLeave
        ? {
              label: 'On leave',
              classes: 'bg-status-warning-bg text-status-warning',
          }
        : declaredToday
          ? {
                label: 'Available today',
                classes: 'bg-status-success-bg text-status-success',
            }
          : daysCovered > 0
            ? {
                  label: `${daysCovered}/7 days set`,
                  classes: 'bg-accent text-[var(--brand-deep,var(--primary))]',
              }
            : {
                  label: 'No data',
                  classes:
                      'bg-muted text-muted-foreground border border-dashed border-border',
              };

    return (
        <article className="flex flex-col gap-3 rounded-xl border border-border bg-card p-4 shadow-sm transition-all hover:-translate-y-px hover:shadow-md">
            <header className="flex items-start gap-3">
                <div
                    className="grid h-10 w-10 shrink-0 place-items-center rounded-full text-[12px] font-bold text-white"
                    style={{
                        background: `linear-gradient(135deg, hsl(${hue} 70% 55%), hsl(${(hue + 40) % 360} 70% 45%))`,
                    }}
                    aria-hidden="true"
                >
                    {inits}
                </div>
                <div className="min-w-0 flex-1">
                    <div className="flex items-start justify-between gap-2">
                        <h3 className="m-0 truncate text-sm font-bold leading-tight">
                            {member.name}
                        </h3>
                        <span
                            className={cn(
                                'inline-flex shrink-0 items-center rounded-full px-2 py-[2px] text-[10.5px] font-bold uppercase tracking-wide',
                                statusBadge.classes,
                            )}
                        >
                            {statusBadge.label}
                        </span>
                    </div>
                    <p className="truncate text-xs text-muted-foreground">
                        {member.email}
                    </p>
                    {member.role ? (
                        <Badge
                            variant="outline"
                            className="mt-1 text-[10px] capitalize"
                        >
                            {member.role.replace(/_/g, ' ')}
                        </Badge>
                    ) : null}
                </div>
            </header>

            <div>
                <p className="mb-1.5 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                    Weekly availability
                </p>
                <div className="grid grid-cols-7 gap-1">
                    {DAY_NAMES.map((day, idx) => {
                        const slot = availability.find(
                            (item) => item.day_of_week === idx,
                        );
                        const isAvailable = Boolean(slot);
                        const isToday = idx === todayIdx;

                        return (
                            <div
                                key={day}
                                title={
                                    slot
                                        ? `${day}: ${slot.start_time}–${slot.end_time}`
                                        : `${day}: not declared`
                                }
                                className={cn(
                                    'flex flex-col items-center justify-center rounded-md border py-1.5 text-[10px] font-semibold',
                                    isAvailable
                                        ? 'border-status-success/30 bg-status-success-bg text-status-success'
                                        : 'border-dashed border-border bg-muted/40 text-muted-foreground/60',
                                    isToday &&
                                        'ring-2 ring-primary/40 ring-offset-1 ring-offset-card',
                                )}
                            >
                                <span className="leading-none">{day}</span>
                                {isAvailable && slot ? (
                                    <span className="mt-0.5 text-[9px] font-bold tabular-nums">
                                        {slot.start_time}
                                    </span>
                                ) : (
                                    <span className="mt-0.5 text-[9px] leading-none">
                                        —
                                    </span>
                                )}
                            </div>
                        );
                    })}
                </div>
            </div>

            <dl className="m-0 grid grid-cols-3 gap-2 border-y border-dashed border-border py-2.5">
                <Stat
                    icon={<CalendarCheck className="h-3 w-3" />}
                    label="Blocks"
                    value={totalBlocks}
                />
                <Stat
                    icon={<Clock className="h-3 w-3" />}
                    label="Time off"
                    value={timeOff.length}
                    tone={timeOff.length > 0 ? 'warn' : 'default'}
                />
                <Stat
                    icon={<Plane className="h-3 w-3" />}
                    label="Leave"
                    value={upcomingLeave.length}
                    tone={upcomingLeave.length > 0 ? 'info' : 'default'}
                />
            </dl>

            {(timeOff.length > 0 || upcomingLeave.length > 0) && (
                <div className="flex flex-wrap gap-1.5">
                    {timeOff.slice(0, 2).map((off) => (
                        <span
                            key={`off-${off.id}`}
                            className="inline-flex items-center gap-1 rounded-md bg-status-warning-bg px-2 py-0.5 text-[10.5px] font-semibold text-status-warning"
                            title={off.reason}
                        >
                            <Clock className="h-2.5 w-2.5" />
                            {formatRange(off.starts_at, off.ends_at)}
                        </span>
                    ))}
                    {upcomingLeave.slice(0, 2).map((leave) => (
                        <span
                            key={`leave-${leave.id}`}
                            className="inline-flex items-center gap-1 rounded-md bg-status-info-bg px-2 py-0.5 text-[10.5px] font-semibold text-status-info"
                            title={formatLeaveType(leave.leave_type)}
                        >
                            <Plane className="h-2.5 w-2.5" />
                            {formatRange(leave.starts_at, leave.ends_at)}
                        </span>
                    ))}
                    {timeOff.length + upcomingLeave.length > 4 && (
                        <span className="inline-flex items-center rounded-md bg-muted px-2 py-0.5 text-[10.5px] font-semibold text-muted-foreground">
                            +{timeOff.length + upcomingLeave.length - 4} more
                        </span>
                    )}
                </div>
            )}

            {!hasAnyData ? (
                <p className="text-xs italic text-muted-foreground/70">
                    No availability data configured.
                </p>
            ) : null}

            {canManage ? (
                <footer className="mt-auto flex items-center justify-end">
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        className="h-8 text-xs"
                        onClick={onEdit}
                    >
                        <CalendarCheck className="h-3.5 w-3.5" />
                        Edit availability
                    </Button>
                </footer>
            ) : null}
        </article>
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
    tone: 'info' | 'success' | 'warning' | 'muted';
}) {
    const toneClass =
        tone === 'success'
            ? 'bg-status-success-bg text-status-success'
            : tone === 'warning'
              ? 'bg-status-warning-bg text-status-warning'
              : tone === 'muted'
                ? 'bg-muted text-muted-foreground'
                : 'bg-status-info-bg text-status-info';

    return (
        <Card>
            <CardContent className="flex items-center gap-3 pt-5">
                <div
                    className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg ${toneClass}`}
                >
                    <Icon className="h-5 w-5" />
                </div>
                <div className="min-w-0">
                    <p className="text-2xl font-bold leading-none tabular-nums">
                        {value}
                    </p>
                    <p className="mt-1 truncate text-xs text-muted-foreground">
                        {label}
                    </p>
                </div>
            </CardContent>
        </Card>
    );
}

function Stat({
    icon,
    label,
    value,
    tone = 'default',
}: {
    icon: React.ReactNode;
    label: string;
    value: number;
    tone?: 'default' | 'warn' | 'info';
}) {
    const valueTone =
        tone === 'warn'
            ? 'text-status-warning'
            : tone === 'info'
              ? 'text-status-info'
              : 'text-foreground';
    return (
        <div className="min-w-0">
            <dt className="inline-flex items-center gap-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
                {icon}
                {label}
            </dt>
            <dd className={cn('m-0 mt-0.5 text-sm font-bold tabular-nums', valueTone)}>
                {value}
            </dd>
        </div>
    );
}

function formatRange(startsAt: string, endsAt: string): string {
    return `${formatDate(startsAt)}–${formatDate(endsAt)}`;
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
