import { Link } from '@inertiajs/react';
import {
    ArrowUpRight,
    Briefcase,
    CalendarClock,
    Car,
    CheckCircle2,
    Clock,
    Coffee,
    Flag,
    MapPin,
    Pencil,
    Rotate3D,
    User,
    UserPlus,
    Users,
    X,
    type LucideIcon,
} from 'lucide-react';
import { useEffect } from 'react';

import { ShiftStatusBadge } from '@/components/shift-status-badge';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { shiftTypeMeta } from '@/lib/shift-types';
import { show as showShift } from '@/routes/operations/shifts';
import * as VisuallyHidden from '@radix-ui/react-visually-hidden';

import { Button as GuardrailButton } from '@/components/ui/button';
import {
    clientFullName,
    effectiveStatus,
    isOpenShift,
    shiftEndTime,
    shiftHours,
    shiftStartTime,
    type ShiftRow,
} from './shift-row-types';
import { StaffAvatar } from './staff-avatar';

type Props = {
    open: boolean;
    shift: ShiftRow | null;
    onClose: () => void;
    onAct: (action: 'assign' | 'start' | 'complete' | 'timesheet') => void;
    onEdit?: () => void;
};

export function ShiftDetailDialog({
    open,
    shift,
    onClose,
    onAct,
    onEdit,
}: Props) {
    useEffect(() => {
        if (!open) return;
        const handler = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        window.addEventListener('keydown', handler);
        return () => window.removeEventListener('keydown', handler);
    }, [open, onClose]);

    if (!open || !shift) return null;

    const open_ = isOpenShift(shift);
    const inProgress = shift.status === 'in_progress';
    const completed = shift.status === 'completed';
    const cancelled = shift.status === 'cancelled';
    const locked = completed || cancelled;
    const scheduled = shift.status === 'scheduled' && !!shift.staff;

    const dateObj = new Date(shift.starts_at);
    const dayLabel = Number.isNaN(dateObj.getTime())
        ? '—'
        : dateObj.toLocaleDateString('en-NZ', {
              weekday: 'long',
              day: 'numeric',
              month: 'long',
              year: 'numeric',
          });
    const hours = shiftHours(shift.starts_at, shift.ends_at);
    const type = shiftTypeMeta(shift.shift_type);
    const TypeIcon = type.icon;

    const primary = (() => {
        if (open_)
            return {
                label: 'Find cover',
                icon: UserPlus,
                act: 'assign' as const,
            };
        if (scheduled)
            return {
                label: 'Start shift',
                icon: CheckCircle2,
                act: 'start' as const,
            };
        if (inProgress)
            return {
                label: 'Mark complete',
                icon: CheckCircle2,
                act: 'complete' as const,
            };
        if (completed)
            return {
                label: 'Open timesheet',
                icon: Clock,
                act: 'timesheet' as const,
            };
        return null;
    })();

    return (
        <Dialog open={open} onOpenChange={(o) => (!o ? onClose() : null)}>
            <DialogContent
                className="max-h-[90vh] !w-full !max-w-[min(94vw,820px)] overflow-hidden !rounded-2xl !p-0 [&>button:last-child]:hidden"
                onInteractOutside={(e) => e.preventDefault()}
            >
                <VisuallyHidden.Root>
                    <DialogTitle>
                        Shift detail for {clientFullName(shift.client)}
                    </DialogTitle>
                    <DialogDescription>
                        Quick view of shift {shift.id}. Open the full view for
                        tasks, notes, and timeline.
                    </DialogDescription>
                </VisuallyHidden.Root>
                <div className="flex h-full max-h-[90vh] flex-col">
                    {/* Header */}
                    <div className="relative overflow-hidden rounded-t-2xl">
                        <div className="absolute -top-12 -right-12 h-40 w-40 rounded-full bg-primary/10 blur-2xl" />
                        <div className="relative flex items-start gap-4 border-b border-border px-6 pt-5 pb-4">
                            <span className="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary/10 ring-1 ring-primary/20">
                                <CalendarClock className="h-5 w-5 text-primary" />
                            </span>
                            <div className="min-w-0 flex-1">
                                <div className="text-[10.5px] font-semibold tracking-wider text-primary uppercase">
                                    Shift · #{shift.id}
                                </div>
                                <h2 className="mt-0.5 text-xl font-bold tracking-tight text-foreground">
                                    {clientFullName(shift.client)}
                                </h2>
                                <p className="mt-0.5 text-sm text-muted-foreground">
                                    {dayLabel} ·{' '}
                                    {shiftStartTime(shift.starts_at)}–
                                    {shiftEndTime(shift.ends_at)} ·{' '}
                                    {hours > 0 ? `${hours.toFixed(1)}h` : '—'}
                                </p>
                            </div>
                            <div className="flex shrink-0 items-center gap-2">
                                <ShiftStatusBadge
                                    status={effectiveStatus(shift)}
                                />
                                <GuardrailButton
                                    unstyled
                                    type="button"
                                    onClick={onClose}
                                    aria-label="Close"
                                    className="inline-flex h-9 w-9 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground"
                                >
                                    <X className="h-4 w-4" />
                                </GuardrailButton>
                            </div>
                        </div>
                    </div>

                    {/* Body */}
                    <div className="flex-1 overflow-y-auto px-6 py-4">
                        <SDSection first icon={Users} title="Who & where">
                            <SDRow label="Client" icon={User}>
                                <Link
                                    href={`/operations/clients/${shift.client.id}`}
                                    className="font-medium text-primary hover:underline"
                                >
                                    {clientFullName(shift.client)}
                                </Link>
                            </SDRow>
                            <SDRow label="Location" icon={MapPin}>
                                {shift.site?.name ?? shift.location ?? '—'}
                            </SDRow>
                            <SDRow label="Staff" icon={User}>
                                {shift.staff ? (
                                    <span className="inline-flex items-center gap-2">
                                        <StaffAvatar name={shift.staff.name} />
                                        <span className="font-medium">
                                            {shift.staff.name}
                                        </span>
                                    </span>
                                ) : (
                                    <span className="inline-flex items-center gap-2 text-status-critical">
                                        <UserPlus className="h-4 w-4" />
                                        <span className="font-medium">
                                            Unassigned
                                        </span>
                                        <GuardrailButton
                                            unstyled
                                            type="button"
                                            onClick={() => onAct('assign')}
                                            className="text-xs font-medium text-primary hover:underline"
                                        >
                                            Find cover
                                        </GuardrailButton>
                                    </span>
                                )}
                            </SDRow>
                            <SDRow label="Type" icon={Briefcase}>
                                <span className="inline-flex items-center gap-1 rounded-md border border-border bg-background px-1.5 py-0.5 text-[11px] font-medium text-muted-foreground">
                                    <TypeIcon className="h-3 w-3" />
                                    {type.label}
                                </span>
                            </SDRow>
                            {shift.required_licence_class ||
                            shift.required_licence_endorsements?.length ? (
                                <SDRow label="Driving" icon={Car}>
                                    <div className="flex flex-wrap gap-1.5">
                                        {shift.required_licence_class ? (
                                            <Tag
                                                label={`Class ${shift.required_licence_class}`}
                                            />
                                        ) : null}
                                        {shift.required_licence_endorsements?.map(
                                            (endorsement) => (
                                                <Tag
                                                    key={endorsement}
                                                    label={`${endorsement} endorsement`}
                                                />
                                            ),
                                        )}
                                    </div>
                                </SDRow>
                            ) : null}
                        </SDSection>

                        <SDSection icon={Clock} title="Schedule">
                            <SDRow label="Start" icon={Clock}>
                                <span className="font-medium tabular-nums">
                                    {shiftStartTime(shift.starts_at)}
                                </span>
                                <span className="text-muted-foreground">
                                    {' '}
                                    · {dayLabel}
                                </span>
                            </SDRow>
                            <SDRow label="End" icon={Clock}>
                                <span className="font-medium tabular-nums">
                                    {shiftEndTime(shift.ends_at)}
                                </span>
                                <span className="text-muted-foreground">
                                    {' '}
                                    · {hours > 0
                                        ? `${hours.toFixed(1)}h`
                                        : '—'}{' '}
                                    total
                                </span>
                            </SDRow>
                            <SDRow label="Break" icon={Coffee}>
                                Refer to full view for break configuration.
                            </SDRow>
                        </SDSection>

                        {shift.is_sleepover || shift.is_on_call ? (
                            <SDSection icon={Flag} title="Flags">
                                <div className="flex flex-wrap gap-2">
                                    {shift.is_sleepover ? (
                                        <Tag label="Sleepover" />
                                    ) : null}
                                    {shift.is_on_call ? (
                                        <Tag label="On-call" />
                                    ) : null}
                                </div>
                            </SDSection>
                        ) : null}

                        <SDSection icon={Pencil} title="More detail">
                            <p className="rounded-lg bg-muted/40 p-3 text-sm leading-relaxed text-foreground">
                                Tasks, handover notes, MAR records and timeline
                                live in the full view. Open it to drill in.
                            </p>
                            <Link
                                href={showShift.url(shift.id)}
                                className="mt-3 inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
                            >
                                Open full view{' '}
                                <ArrowUpRight className="h-3.5 w-3.5" />
                            </Link>
                        </SDSection>
                    </div>

                    {/* Footer */}
                    <div className="flex flex-col gap-3 border-t border-border bg-card px-6 py-3.5 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex items-center gap-2 text-xs text-muted-foreground">
                            <Rotate3D className="h-3 w-3" />
                            Quick view · open the shift page for full detail
                        </div>
                        <div className="flex shrink-0 items-center gap-2">
                            <GuardrailButton
                                unstyled
                                type="button"
                                onClick={onClose}
                                className="inline-flex items-center rounded-md border border-border bg-transparent px-3 py-1.5 text-sm font-medium text-foreground hover:bg-muted"
                            >
                                Close
                            </GuardrailButton>
                            {locked ? null : onEdit ? (
                                <GuardrailButton
                                    unstyled
                                    type="button"
                                    onClick={onEdit}
                                    className="inline-flex items-center gap-1.5 rounded-md border border-border bg-transparent px-3 py-1.5 text-sm font-medium text-foreground hover:bg-muted"
                                >
                                    <Pencil className="h-4 w-4" /> Edit
                                </GuardrailButton>
                            ) : (
                                <Link
                                    href={showShift.url(shift.id)}
                                    className="inline-flex items-center gap-1.5 rounded-md border border-border bg-transparent px-3 py-1.5 text-sm font-medium text-foreground hover:bg-muted"
                                >
                                    <Pencil className="h-4 w-4" /> Edit
                                </Link>
                            )}
                            {primary ? (
                                <GuardrailButton
                                    unstyled
                                    type="button"
                                    onClick={() => onAct(primary.act)}
                                    className="inline-flex items-center gap-1.5 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground hover:brightness-95"
                                >
                                    <primary.icon className="h-4 w-4" />{' '}
                                    {primary.label}
                                </GuardrailButton>
                            ) : null}
                        </div>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}

function SDSection({
    icon: Icon,
    title,
    children,
    first,
}: {
    icon: LucideIcon;
    title: string;
    children: React.ReactNode;
    first?: boolean;
}) {
    return (
        <section className={first ? '' : 'mt-4 border-t border-border pt-4'}>
            <div className="mb-2 flex items-center gap-2">
                <Icon className="h-4 w-4 text-primary" />
                <h3 className="text-sm font-semibold text-foreground">
                    {title}
                </h3>
            </div>
            {children}
        </section>
    );
}

function SDRow({
    label,
    icon: Icon,
    children,
}: {
    label: string;
    icon?: LucideIcon;
    children: React.ReactNode;
}) {
    return (
        <div className="grid grid-cols-[120px_1fr] items-start gap-3 border-b border-border py-2 last:border-b-0">
            <div className="inline-flex items-center gap-1.5 pt-1 text-xs font-medium text-muted-foreground">
                {Icon ? <Icon className="h-3.5 w-3.5" /> : null}
                {label}
            </div>
            <div className="min-w-0 text-sm text-foreground">{children}</div>
        </div>
    );
}

function Tag({ label }: { label: string }) {
    return (
        <span className="inline-flex items-center gap-1.5 rounded-full bg-muted px-2.5 py-1 text-xs text-foreground">
            <span className="h-1.5 w-1.5 rounded-full bg-primary" />
            {label}
        </span>
    );
}
