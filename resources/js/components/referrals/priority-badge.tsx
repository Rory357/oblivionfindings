import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { label } from './types';

/** Every referral starts at the protected default priority, which lists never badge. */
export const DEFAULT_PRIORITY = 'standard';

/**
 * Priority tones, kept in the referrals module: urgent reads as critical and
 * high as warning; any other configured priority is informational.
 */
export function priorityTone(priority: string): StatusVariant {
    if (priority === 'urgent') return 'critical';
    if (priority === 'high') return 'warning';
    return priority === DEFAULT_PRIORITY ? 'neutral' : 'info';
}

/** The configured label, worded so it reads on its own ("High priority"). */
export function priorityText(priority: string, configured?: string | null) {
    const name = configured || label(priority);
    return /priority/i.test(name) ? name : `${name} priority`;
}

/** Any priority, including the default, for places that state the current one. */
export function ReferralPriorityBadge({
    priority,
    priorityLabel,
}: {
    priority: string;
    priorityLabel?: string | null;
}) {
    return (
        <StatusBadge variant={priorityTone(priority)} size="sm">
            {priorityText(priority, priorityLabel)}
        </StatusBadge>
    );
}
