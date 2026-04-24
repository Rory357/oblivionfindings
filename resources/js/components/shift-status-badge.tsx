import { Badge } from '@/components/ui/badge';
import { Clock, CalendarCheck, Play, CheckCircle2, XCircle } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

const config: Record<string, { label: string; className: string; icon: LucideIcon }> = {
    draft: {
        label: 'Draft',
        className: 'border-border text-muted-foreground bg-muted',
        icon: Clock,
    },
    scheduled: {
        label: 'Scheduled',
        className: 'border-status-info/30 text-status-info bg-status-info-bg',
        icon: CalendarCheck,
    },
    in_progress: {
        label: 'In Progress',
        className: 'border-status-warning/30 text-status-warning bg-status-warning-bg',
        icon: Play,
    },
    completed: {
        label: 'Completed',
        className: 'border-status-success/30 text-status-success bg-status-success-bg',
        icon: CheckCircle2,
    },
    cancelled: {
        label: 'Cancelled',
        className: 'border-status-critical/30 text-status-critical bg-status-critical-bg',
        icon: XCircle,
    },
};

interface ShiftStatusBadgeProps {
    status: string;
    showIcon?: boolean;
    className?: string;
}

export function ShiftStatusBadge({ status, showIcon = false, className = '' }: ShiftStatusBadgeProps) {
    const c = config[status] ?? { label: status, className: '', icon: Clock };
    const Icon = c.icon;

    return (
        <Badge variant="outline" className={`${c.className} ${className}`}>
            {showIcon ? <Icon className="mr-1 h-3 w-3" /> : null}
            {c.label}
        </Badge>
    );
}

export const shiftStatusConfig = config;
export default ShiftStatusBadge;
