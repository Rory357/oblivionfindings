import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { AlertOctagon, AlertTriangle, Clock, Circle } from 'lucide-react';

export type Priority = 'critical' | 'high' | 'medium' | 'low';
export type WorkflowStatus = 'overdue' | 'due_soon' | 'pending';

interface PriorityBadgeProps {
    priority: Priority;
    status?: WorkflowStatus;
    showLabel?: boolean;
    className?: string;
}

const PRIORITY_STYLES: Record<Priority, string> = {
    critical: 'bg-status-critical-bg text-status-critical border-status-critical/30',
    high: 'bg-status-warning-bg text-status-warning border-status-warning/30',
    medium: 'bg-status-info-bg text-status-info border-status-info/30',
    low: 'bg-muted text-muted-foreground border-border',
};

const PRIORITY_LABELS: Record<Priority, string> = {
    critical: 'Critical',
    high: 'High',
    medium: 'Medium',
    low: 'Low',
};

const PRIORITY_ICONS: Record<Priority, typeof AlertOctagon> = {
    critical: AlertOctagon,
    high: AlertTriangle,
    medium: Clock,
    low: Circle,
};

/**
 * Tone-coloured priority pill. When `status === 'overdue'` we always render
 * critical regardless of the source priority so overdue is impossible to miss.
 */
export function PriorityBadge({ priority, status, showLabel = true, className }: PriorityBadgeProps) {
    const effective: Priority = status === 'overdue' ? 'critical' : priority;
    const Icon = PRIORITY_ICONS[effective];

    return (
        <Badge
            className={cn('inline-flex items-center gap-1 border', PRIORITY_STYLES[effective], className)}
            aria-label={`Priority: ${PRIORITY_LABELS[effective]}`}
        >
            <Icon className="h-3 w-3" aria-hidden="true" />
            {showLabel ? <span className="text-xs font-medium">{PRIORITY_LABELS[effective]}</span> : null}
        </Badge>
    );
}

export default PriorityBadge;
