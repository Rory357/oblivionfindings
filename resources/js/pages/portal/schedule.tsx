import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useInitials } from '@/hooks/use-initials';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import {
    Calendar,
    CalendarPlus,
    Clock,
    MapPin,
    Users,
    Video,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';

type Staff = { id: number; name: string; avatar?: string | null };

type Shift = {
    id: number;
    starts_at: string;
    ends_at: string;
    status: string;
    shift_type: string;
    is_sleepover: boolean;
    is_on_call: boolean;
    expected_break_minutes?: number | null;
    location?: string | null;
    service_context?: { id: number; name: string; type?: string | null } | null;
    date: string;
    staff?: Staff | null;
};

type VisitRequest = {
    id: number;
    requested_date: string;
    preferred_time_start?: string | null;
    preferred_time_end?: string | null;
    visit_type: string;
    notes?: string | null;
    status: string;
    review_notes?: string | null;
};

type Props = {
    client: { id: number; first_name: string; last_name: string };
    shifts: Shift[];
    visitRequests: VisitRequest[];
    showShifts: boolean;
};

function formatTime(iso: string): string {
    return new Date(iso).toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
    });
}

function formatDate(dateStr: string): string {
    return new Date(dateStr + 'T00:00:00').toLocaleDateString([], {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
    });
}

const statusColors: Record<string, string> = {
    scheduled: 'bg-blue-100 text-blue-800',
    in_progress: 'bg-amber-100 text-amber-800',
    completed: 'bg-emerald-100 text-emerald-800',
    cancelled: 'bg-muted text-muted-foreground',
    pending: 'bg-yellow-100 text-yellow-800',
    approved: 'bg-emerald-100 text-emerald-800',
    declined: 'bg-red-100 text-red-800',
};

const shiftTypeLabels: Record<string, string> = {
    standard: 'Standard',
    sleepover: 'Sleepover',
    on_call: 'On-Call',
    split: 'Split',
    travel: 'Travel',
};

const defaultVisitType = { label: 'In Person', icon: Users };

const visitTypeLabels: Record<
    string,
    { label: string; icon: typeof Calendar }
> = {
    in_person: defaultVisitType,
    video_call: { label: 'Video Call', icon: Video },
    outing: { label: 'Outing', icon: MapPin },
};

function getVisitType(visitType: string): {
    label: string;
    icon: typeof Calendar;
} {
    return visitTypeLabels[visitType] ?? defaultVisitType;
}

function groupShiftsByDate(shifts: Shift[]): Map<string, Shift[]> {
    const grouped = new Map<string, Shift[]>();
    for (const shift of shifts) {
        const existing = grouped.get(shift.date);
        if (existing) {
            existing.push(shift);
        } else {
            grouped.set(shift.date, [shift]);
        }
    }
    return grouped;
}

export default function Schedule({
    client,
    shifts,
    visitRequests,
    showShifts,
}: Props) {
    const clientName = `${client.first_name} ${client.last_name}`.trim();
    const getInitials = useInitials();
    const [bookingOpen, setBookingOpen] = useState(false);

    const groupedShifts = useMemo(() => groupShiftsByDate(shifts), [shifts]);

    const form = useForm({
        requested_date: '',
        preferred_time_start: '',
        preferred_time_end: '',
        visit_type: 'in_person' as string,
        notes: '',
    });

    const submitVisit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/portal/clients/${client.id}/visit-requests`, {
            preserveScroll: true,
            onSuccess: () => {
                setBookingOpen(false);
                form.reset();
                toast.success('Visit request submitted!');
            },
            onError: () => toast.error('Please check the form and try again.'),
        });
    };

    const cancelVisit = (visitId: number) => {
        router.post(
            `/portal/clients/${client.id}/visit-requests/${visitId}/cancel`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => toast.success('Visit request cancelled.'),
            },
        );
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Portal', href: '/portal' },
                {
                    title: clientName,
                    href: `/portal/clients/${client.id}/dashboard`,
                },
                {
                    title: 'Schedule',
                    href: `/portal/clients/${client.id}/schedule`,
                },
            ]}
        >
            <Head title={`${clientName} - Schedule`} />

            <div className="mx-auto max-w-5xl space-y-6 p-4 md:p-6">
                {/* ── Shifts Section ──────────────────────────── */}
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between pb-3">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Clock className="h-4 w-4 text-primary" />
                            Shift Schedule
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {showShifts ? (
                            groupedShifts.size > 0 ? (
                                <div className="space-y-6">
                                    {Array.from(groupedShifts.entries()).map(
                                        ([date, dateShifts]) => (
                                            <div key={date}>
                                                <h3 className="mb-3 text-sm font-semibold text-foreground">
                                                    {formatDate(date)}
                                                </h3>
                                                <div className="space-y-3">
                                                    {dateShifts.map((shift) => (
                                                        <div
                                                            key={shift.id}
                                                            className="flex items-center justify-between gap-3 rounded-lg border p-3"
                                                        >
                                                            <div className="flex items-center gap-3">
                                                                {shift.staff ? (
                                                                    <Avatar className="h-9 w-9">
                                                                        <AvatarImage
                                                                            src={
                                                                                shift
                                                                                    .staff
                                                                                    .avatar ??
                                                                                undefined
                                                                            }
                                                                            alt={
                                                                                shift
                                                                                    .staff
                                                                                    .name
                                                                            }
                                                                        />
                                                                        <AvatarFallback className="bg-primary/10 text-xs font-medium text-primary">
                                                                            {getInitials(
                                                                                shift
                                                                                    .staff
                                                                                    .name,
                                                                            )}
                                                                        </AvatarFallback>
                                                                    </Avatar>
                                                                ) : (
                                                                    <div className="flex h-9 w-9 items-center justify-center rounded-full bg-muted">
                                                                        <Users className="h-4 w-4 text-muted-foreground" />
                                                                    </div>
                                                                )}
                                                                <div>
                                                                    <p className="text-sm font-medium">
                                                                        {shift
                                                                            .staff
                                                                            ?.name ??
                                                                            'Unassigned'}
                                                                    </p>
                                                                    <p className="text-xs text-muted-foreground">
                                                                        {formatTime(
                                                                            shift.starts_at,
                                                                        )}{' '}
                                                                        -{' '}
                                                                        {formatTime(
                                                                            shift.ends_at,
                                                                        )}
                                                                    </p>
                                                                    <p className="text-xs text-muted-foreground">
                                                                        {shiftTypeLabels[
                                                                            shift
                                                                                .shift_type
                                                                        ] ??
                                                                            shift.shift_type}
                                                                        {shift
                                                                            .service_context
                                                                            ?.name
                                                                            ? ` • ${shift.service_context.name}`
                                                                            : ''}
                                                                        {shift.location
                                                                            ? ` • ${shift.location}`
                                                                            : ''}
                                                                    </p>
                                                                    {(shift.is_sleepover ||
                                                                        shift.is_on_call ||
                                                                        shift.expected_break_minutes) && (
                                                                        <p className="text-[11px] text-muted-foreground">
                                                                            {shift.is_sleepover
                                                                                ? 'Sleepover'
                                                                                : null}
                                                                            {shift.is_sleepover &&
                                                                            shift.is_on_call
                                                                                ? ' • '
                                                                                : null}
                                                                            {shift.is_on_call
                                                                                ? 'On-call'
                                                                                : null}
                                                                            {(shift.is_sleepover ||
                                                                                shift.is_on_call) &&
                                                                            shift.expected_break_minutes
                                                                                ? ' • '
                                                                                : null}
                                                                            {shift.expected_break_minutes
                                                                                ? `Break ${shift.expected_break_minutes}m`
                                                                                : null}
                                                                        </p>
                                                                    )}
                                                                </div>
                                                            </div>
                                                            <Badge
                                                                className={`${statusColors[shift.status] ?? ''} border-0 capitalize`}
                                                            >
                                                                {shift.status.replace(
                                                                    '_',
                                                                    ' ',
                                                                )}
                                                            </Badge>
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        ),
                                    )}
                                </div>
                            ) : (
                                <div className="flex flex-col items-center justify-center py-8 text-center">
                                    <Calendar className="mb-2 h-8 w-8 text-muted-foreground/40" />
                                    <p className="text-sm text-muted-foreground">
                                        No shifts scheduled
                                    </p>
                                </div>
                            )
                        ) : (
                            <div className="flex flex-col items-center justify-center py-8 text-center">
                                <Clock className="mb-2 h-8 w-8 text-muted-foreground/40" />
                                <p className="text-sm font-medium text-muted-foreground">
                                    Shift schedule is not enabled for your
                                    portal access
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground/70">
                                    Contact the care team if you need access to
                                    shift information.
                                </p>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* ── Visit Requests Section ────────────────── */}
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between pb-3">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <CalendarPlus className="h-4 w-4 text-primary" />
                            Visit Requests
                        </CardTitle>
                        <Dialog
                            open={bookingOpen}
                            onOpenChange={setBookingOpen}
                        >
                            <DialogTrigger asChild>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="gap-1.5"
                                >
                                    <CalendarPlus className="h-3.5 w-3.5" />
                                    Book a Visit
                                </Button>
                            </DialogTrigger>
                            <DialogContent className="sm:max-w-md">
                                <DialogHeader>
                                    <DialogTitle>Request a Visit</DialogTitle>
                                    <DialogDescription>
                                        Submit a visit request to see{' '}
                                        {clientName}. The care team will review
                                        and confirm.
                                    </DialogDescription>
                                </DialogHeader>
                                <form
                                    onSubmit={submitVisit}
                                    className="space-y-4"
                                >
                                    <div>
                                        <Label htmlFor="visit-date">
                                            Date *
                                        </Label>
                                        <Input
                                            id="visit-date"
                                            type="date"
                                            value={form.data.requested_date}
                                            onChange={(e) =>
                                                form.setData(
                                                    'requested_date',
                                                    e.target.value,
                                                )
                                            }
                                            min={
                                                new Date()
                                                    .toISOString()
                                                    .split('T')[0]
                                            }
                                        />
                                        {form.errors.requested_date && (
                                            <p className="mt-1 text-xs text-red-500">
                                                {form.errors.requested_date}
                                            </p>
                                        )}
                                    </div>
                                    <div className="grid grid-cols-2 gap-3">
                                        <div>
                                            <Label htmlFor="time-start">
                                                From
                                            </Label>
                                            <Input
                                                id="time-start"
                                                type="time"
                                                value={
                                                    form.data
                                                        .preferred_time_start
                                                }
                                                onChange={(e) =>
                                                    form.setData(
                                                        'preferred_time_start',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div>
                                            <Label htmlFor="time-end">To</Label>
                                            <Input
                                                id="time-end"
                                                type="time"
                                                value={
                                                    form.data.preferred_time_end
                                                }
                                                onChange={(e) =>
                                                    form.setData(
                                                        'preferred_time_end',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                    </div>
                                    <div>
                                        <Label>Visit Type *</Label>
                                        <div className="mt-2 grid grid-cols-3 gap-2">
                                            {(
                                                [
                                                    'in_person',
                                                    'video_call',
                                                    'outing',
                                                ] as const
                                            ).map((type) => {
                                                const { label, icon: Icon } =
                                                    getVisitType(type);
                                                const selected =
                                                    form.data.visit_type ===
                                                    type;
                                                return (
                                                    <button
                                                        key={type}
                                                        type="button"
                                                        onClick={() =>
                                                            form.setData(
                                                                'visit_type',
                                                                type,
                                                            )
                                                        }
                                                        className={`flex flex-col items-center gap-1.5 rounded-lg border-2 p-3 text-xs font-medium transition-all ${
                                                            selected
                                                                ? 'border-primary bg-primary/5 text-primary'
                                                                : 'border-border text-muted-foreground hover:border-primary/30'
                                                        }`}
                                                    >
                                                        <Icon className="h-5 w-5" />
                                                        {label}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </div>
                                    <div>
                                        <Label htmlFor="visit-notes">
                                            Notes
                                        </Label>
                                        <textarea
                                            id="visit-notes"
                                            className="mt-1 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                            rows={3}
                                            placeholder="Any special requests or things to note..."
                                            value={form.data.notes}
                                            onChange={(e) =>
                                                form.setData(
                                                    'notes',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="flex justify-end gap-2 pt-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() =>
                                                setBookingOpen(false)
                                            }
                                        >
                                            Cancel
                                        </Button>
                                        <Button
                                            type="submit"
                                            disabled={
                                                form.processing ||
                                                !form.data.requested_date
                                            }
                                        >
                                            {form.processing
                                                ? 'Submitting...'
                                                : 'Submit Request'}
                                        </Button>
                                    </div>
                                </form>
                            </DialogContent>
                        </Dialog>
                    </CardHeader>
                    <CardContent>
                        {visitRequests.length > 0 ? (
                            <div className="space-y-3">
                                {visitRequests.map((visit) => {
                                    const vt = getVisitType(visit.visit_type);
                                    const VtIcon = vt.icon;
                                    return (
                                        <div
                                            key={visit.id}
                                            className="flex items-center justify-between gap-3 rounded-lg border p-3"
                                        >
                                            <div className="flex items-center gap-3">
                                                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                                                    <VtIcon className="h-5 w-5 text-primary" />
                                                </div>
                                                <div>
                                                    <div className="text-sm font-medium">
                                                        {formatDate(
                                                            visit.requested_date,
                                                        )}
                                                        {visit.preferred_time_start && (
                                                            <span className="ml-2 font-normal text-muted-foreground">
                                                                {
                                                                    visit.preferred_time_start
                                                                }
                                                                {visit.preferred_time_end &&
                                                                    ` - ${visit.preferred_time_end}`}
                                                            </span>
                                                        )}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {vt.label}
                                                        {visit.notes &&
                                                            ` \u2022 ${visit.notes}`}
                                                    </div>
                                                    {visit.review_notes && (
                                                        <div className="mt-1 text-xs text-muted-foreground italic">
                                                            Staff note:{' '}
                                                            {visit.review_notes}
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <Badge
                                                    className={`${statusColors[visit.status] ?? ''} border-0 capitalize`}
                                                >
                                                    {visit.status}
                                                </Badge>
                                                {visit.status === 'pending' && (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        className="h-7 w-7 p-0 text-muted-foreground hover:text-red-500"
                                                        onClick={() =>
                                                            cancelVisit(
                                                                visit.id,
                                                            )
                                                        }
                                                    >
                                                        <X className="h-4 w-4" />
                                                    </Button>
                                                )}
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        ) : (
                            <div className="flex flex-col items-center justify-center py-8 text-center">
                                <CalendarPlus className="mb-2 h-8 w-8 text-muted-foreground/40" />
                                <p className="text-sm text-muted-foreground">
                                    No upcoming visit requests
                                </p>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="mt-3 gap-1.5"
                                    onClick={() => setBookingOpen(true)}
                                >
                                    <CalendarPlus className="h-3.5 w-3.5" />
                                    Book a Visit
                                </Button>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
