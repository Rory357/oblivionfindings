import { Badge } from '@/components/ui/badge';

type ShiftTimelineMeta = {
    status?: string | null;
    shift_type?: string | null;
    starts_at?: string | null;
    ends_at?: string | null;
    actual_starts_at?: string | null;
    actual_ends_at?: string | null;
    location?: string | null;
    service_context?: string | null;
    staff_name?: string | null;
    is_sleepover?: boolean;
    is_on_call?: boolean;
    expected_break_minutes?: number | null;
    reason?: string | null;
    current_staff?: string | null;
    replacement_staff?: string | null;
};

type Props = {
    eventType: string;
    meta?: ShiftTimelineMeta | null;
    className?: string;
};

const SHIFT_EVENT_TYPES = [
    'shift',
    'shift_started',
    'shift_completed',
    'shift_cancelled',
    'shift_replacement_requested',
    'shift_replacement_claimed',
    'shift_replacement_approved',
    'shift_replacement_cancelled',
] as const;

export function isShiftTimelineEvent(type: string): boolean {
    return SHIFT_EVENT_TYPES.includes(
        type as (typeof SHIFT_EVENT_TYPES)[number],
    );
}

function shiftTypeLabel(value?: string | null): string {
    switch (value) {
        case 'sleepover':
            return 'Sleepover';
        case 'on_call':
            return 'On-call';
        case 'split':
            return 'Split';
        case 'travel':
            return 'Transport';
        default:
            return 'Support';
    }
}

function eventTypeLabel(value: string, status?: string | null): string {
    switch (value) {
        case 'shift_started':
            return 'Started';
        case 'shift_completed':
            return 'Completed';
        case 'shift_cancelled':
            return 'Cancelled';
        case 'shift_replacement_requested':
            return 'Replacement requested';
        case 'shift_replacement_claimed':
            return 'Replacement claimed';
        case 'shift_replacement_approved':
            return 'Replacement approved';
        case 'shift_replacement_cancelled':
            return 'Replacement cancelled';
        default:
            return status ? status.replace(/_/g, ' ') : 'Scheduled';
    }
}

function formatDateTime(value?: string | null): string | null {
    if (!value) return null;

    return new Date(value).toLocaleString([], {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function formatWindow(meta?: ShiftTimelineMeta | null): string | null {
    if (!meta) return null;

    if (meta.actual_starts_at && meta.actual_ends_at) {
        return `Actual: ${formatDateTime(meta.actual_starts_at)} - ${new Date(
            meta.actual_ends_at,
        ).toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit',
        })}`;
    }

    if (meta.actual_starts_at) {
        return `Started: ${formatDateTime(meta.actual_starts_at)}`;
    }

    if (meta.starts_at && meta.ends_at) {
        return `Scheduled: ${formatDateTime(meta.starts_at)} - ${new Date(
            meta.ends_at,
        ).toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit',
        })}`;
    }

    return formatDateTime(meta.starts_at ?? null);
}

export default function ShiftTimelineSummary({
    eventType,
    meta,
    className = '',
}: Props) {
    if (!meta || !isShiftTimelineEvent(eventType)) {
        return null;
    }

    const flags = [
        meta.is_sleepover ? 'Sleepover' : null,
        meta.is_on_call ? 'On-call' : null,
        meta.expected_break_minutes
            ? `Break ${meta.expected_break_minutes} min`
            : null,
    ].filter(Boolean);

    const serviceContext = meta.service_context?.trim();
    const statusLabel = eventTypeLabel(eventType, meta.status);
    const windowLabel = formatWindow(meta);
    const people = [
        meta.current_staff ? `Current staff: ${meta.current_staff}` : null,
        meta.replacement_staff
            ? `Replacement: ${meta.replacement_staff}`
            : null,
    ].filter(Boolean);

    return (
        <div className={`mt-2 rounded-lg border bg-muted/25 p-3 ${className}`}>
            <div className="flex flex-wrap items-center gap-1.5">
                <Badge variant="secondary" className="text-[10px]">
                    {shiftTypeLabel(meta.shift_type)} shift
                </Badge>
                <Badge variant="outline" className="text-[10px] capitalize">
                    {statusLabel}
                </Badge>
                {serviceContext ? (
                    <Badge variant="outline" className="text-[10px]">
                        {serviceContext}
                    </Badge>
                ) : null}
            </div>
            <div className="mt-2 space-y-1 text-xs text-muted-foreground">
                {windowLabel ? <p>{windowLabel}</p> : null}
                {meta.staff_name ? <p>Staff: {meta.staff_name}</p> : null}
                {meta.location ? <p>Location: {meta.location}</p> : null}
                {meta.reason ? <p>Reason: {meta.reason}</p> : null}
                {people.length > 0 ? <p>{people.join(' · ')}</p> : null}
                {flags.length > 0 ? <p>{flags.join(' · ')}</p> : null}
            </div>
        </div>
    );
}
