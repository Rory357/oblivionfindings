import { Badge } from '@/components/ui/badge';
import { Clock, CalendarCheck, Play, CheckCircle2, XCircle } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

const config: Record<string, { label: string; className: string; icon: LucideIcon }> = {
    draft: {
        label: 'Draft',
        className: 'border-slate-500/30 text-slate-400 bg-slate-500/10',
        icon: Clock,
    },
    scheduled: {
        label: 'Scheduled',
        className: 'border-blue-500/30 text-blue-400 bg-blue-500/10',
        icon: CalendarCheck,
    },
    in_progress: {
        label: 'In Progress',
        className: 'border-amber-500/30 text-amber-400 bg-amber-500/10',
        icon: Play,
    },
    completed: {
        label: 'Completed',
        className: 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10',
        icon: CheckCircle2,
    },
    cancelled: {
        label: 'Cancelled',
        className: 'border-red-500/30 text-red-400 bg-red-500/10',
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
