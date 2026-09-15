import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { governanceStatus } from '@/lib/governance-labels';

export type Priority = 'critical' | 'high' | 'medium' | 'low';
export type WorkflowStatus = 'overdue' | 'due_soon' | 'pending' | 'blocked';

interface PriorityBadgeProps {
    priority: Priority | string;
    status?: WorkflowStatus | string;
    className?: string;
}

/**
 * The one chip a board priority needs: its due state when it is overdue,
 * blocked or due soon; otherwise its priority when that is high or critical.
 * Routine items get no chip. Always a labelled StatusBadge — colour is never
 * the only signal.
 */
export function priorityChip(
    priority: Priority | string,
    status?: WorkflowStatus | string,
): { label: string; variant: StatusVariant } | null {
    if (status === 'overdue') return { label: 'Overdue', variant: 'critical' };
    if (status === 'blocked') return { label: 'Blocked', variant: 'critical' };
    if (status === 'due_soon') return { label: 'Due soon', variant: 'warning' };
    if (priority === 'critical' || priority === 'high') {
        const chip = governanceStatus('priority', priority);
        return { label: `${chip.label} priority`, variant: chip.variant };
    }
    return null;
}

export function PriorityBadge({ priority, status, className }: PriorityBadgeProps) {
    const chip = priorityChip(priority, status);
    if (!chip) return null;

    return (
        <StatusBadge size="sm" variant={chip.variant} className={className}>
            {chip.label}
        </StatusBadge>
    );
}

export default PriorityBadge;
