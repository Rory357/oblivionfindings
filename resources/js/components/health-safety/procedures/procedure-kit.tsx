import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { formatDateOnly, toDateInput } from '@/lib/datetime';
import { Archive, CheckCircle2, Clock, FileEdit } from 'lucide-react';

export function procedureStatus(status: string, legacy = false) {
    if (legacy && status === 'approved')
        return {
            label: 'Legacy approved record',
            variant: 'neutral' as StatusVariant,
            icon: FileEdit,
        };
    const values = {
        draft: {
            label: 'Draft',
            variant: 'neutral' as StatusVariant,
            icon: FileEdit,
        },
        under_review: {
            label: 'Under review',
            variant: 'warning' as StatusVariant,
            icon: Clock,
        },
        approved: {
            label: 'Approved',
            variant: 'success' as StatusVariant,
            icon: CheckCircle2,
        },
        archived: {
            label: 'Archived',
            variant: 'neutral' as StatusVariant,
            icon: Archive,
        },
    };
    return (
        values[status as keyof typeof values] ?? {
            label: status.replaceAll('_', ' '),
            variant: 'neutral' as StatusVariant,
            icon: FileEdit,
        }
    );
}
export function ProcedureStatus({
    status,
    legacy = false,
}: {
    status: string;
    legacy?: boolean;
}) {
    const meta = procedureStatus(status, legacy);
    return (
        <StatusBadge variant={meta.variant}>
            <meta.icon className="size-3" />
            {meta.label}
        </StatusBadge>
    );
}
export function procedureReviewFlag(
    date: string | null,
    today = toDateInput(new Date()),
): { label: string; variant: StatusVariant } | null {
    if (!formatDateOnly(date, '') || !formatDateOnly(today, '')) return null;
    const days = Math.round(
        (Date.parse(`${date}T00:00:00Z`) - Date.parse(`${today}T00:00:00Z`)) /
            86400000,
    );
    if (days < 0)
        return {
            label: `Review overdue · ${Math.abs(days)}d`,
            variant: 'critical',
        };
    if (days === 0) return { label: 'Review due today', variant: 'warning' };
    if (days <= 30)
        return { label: `Review due in ${days}d`, variant: 'warning' };
    return null;
}
export function ProcedureReviewDate({ date }: { date: string | null }) {
    const flag = procedureReviewFlag(date);
    return flag ? (
        <StatusBadge variant={flag.variant}>
            <Clock className="size-3" />
            {flag.label}
        </StatusBadge>
    ) : (
        <span className="text-subtle">
            {date ? `Review ${formatDateOnly(date)}` : 'Review not scheduled'}
        </span>
    );
}
export function procedureCategory(value: string) {
    return (
        (
            {
                manual_handling: 'Manual handling',
                challenging_behaviour: 'Challenging behaviour',
                lone_working: 'Lone working',
                medication: 'Medication',
                infection_control: 'Infection control',
                fire_safety: 'Fire safety',
                emergency_procedures: 'Emergency procedures',
                equipment_use: 'Equipment use',
                personal_care: 'Personal care',
            } as Record<string, string>
        )[value] ?? value.replaceAll('_', ' ')
    );
}
