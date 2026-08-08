import { Badge } from '@/components/ui/badge';
import type { LucideIcon } from 'lucide-react';
import { CheckCircle2, FileEdit, RotateCcw, Send, XCircle } from 'lucide-react';

const config: Record<
    string,
    { label: string; className: string; icon: LucideIcon }
> = {
    draft: {
        label: 'Draft',
        className: 'border-border text-muted-foreground bg-muted',
        icon: FileEdit,
    },
    submitted: {
        label: 'Submitted',
        className:
            'border-status-warning/30 text-status-warning bg-status-warning-bg',
        icon: Send,
    },
    returned: {
        label: 'Returned',
        className:
            'border-status-warning/30 text-status-warning bg-status-warning-bg',
        icon: RotateCcw,
    },
    approved: {
        label: 'Approved',
        className:
            'border-status-success/30 text-status-success bg-status-success-bg',
        icon: CheckCircle2,
    },
    rejected: {
        label: 'Rejected',
        className:
            'border-status-critical/30 text-status-critical bg-status-critical-bg',
        icon: XCircle,
    },
};

interface TimesheetStatusBadgeProps {
    status: string;
    showIcon?: boolean;
    className?: string;
}

export function TimesheetStatusBadge({
    status,
    showIcon = false,
    className = '',
}: TimesheetStatusBadgeProps) {
    const c = config[status] ?? {
        label: status,
        className: '',
        icon: FileEdit,
    };
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
