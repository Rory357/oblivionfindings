import { Badge } from '@/components/ui/badge';
import { FileEdit, Send, RotateCcw, CheckCircle2, XCircle } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

const config: Record<string, { label: string; className: string; icon: LucideIcon }> = {
    draft: {
        label: 'Draft',
        className: 'border-slate-500/30 text-muted-foreground bg-slate-500/10',
        icon: FileEdit,
    },
    submitted: {
        label: 'Submitted',
        className: 'border-yellow-500/30 text-yellow-400 bg-yellow-500/10',
        icon: Send,
    },
    returned: {
        label: 'Returned',
        className: 'border-amber-500/30 text-amber-400 bg-amber-500/10',
        icon: RotateCcw,
    },
    approved: {
        label: 'Approved',
        className: 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10',
        icon: CheckCircle2,
    },
    rejected: {
        label: 'Rejected',
        className: 'border-red-500/30 text-red-400 bg-red-500/10',
        icon: XCircle,
    },
};

interface TimesheetStatusBadgeProps {
    status: string;
    showIcon?: boolean;
    className?: string;
}

export function TimesheetStatusBadge({ status, showIcon = false, className = '' }: TimesheetStatusBadgeProps) {
    const c = config[status] ?? { label: status, className: '', icon: FileEdit };
    const Icon = c.icon;

    return (
        <Badge variant="outline" className={`${c.className} ${className}`}>
            {showIcon ? <Icon className="mr-1 h-3 w-3" /> : null}
            {c.label}
        </Badge>
    );
}

export const timesheetStatusConfig = config;
export default TimesheetStatusBadge;
