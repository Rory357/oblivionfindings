import { CheckCircle2, AlertTriangle, XCircle } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

export type EligibilityStatus = 'eligible' | 'warnings' | 'blocked';

interface EligibilityStatusBadgeProps {
    status: EligibilityStatus;
    warningCount?: number;
    className?: string;
}

const config: Record<EligibilityStatus, {
    label: string;
    icon: typeof CheckCircle2;
    badgeClass: string;
}> = {
    eligible: {
        label: 'Eligible',
        icon: CheckCircle2,
        badgeClass: 'border-status-success/30 bg-status-success-bg text-status-success dark:border-status-success/30 dark:bg-status-success-bg dark:text-status-success',
    },
    warnings: {
        label: 'Warnings',
        icon: AlertTriangle,
        badgeClass: 'border-status-warning/30 bg-status-warning-bg text-status-warning dark:border-status-warning/30 dark:bg-status-warning-bg dark:text-status-warning',
    },
    blocked: {
        label: 'Blocked',
        icon: XCircle,
        badgeClass: 'border-status-critical/30 bg-status-critical-bg text-status-critical dark:border-status-critical/30 dark:bg-status-critical-bg dark:text-status-critical',
    },
};

export function EligibilityStatusBadge({ status, warningCount, className }: EligibilityStatusBadgeProps) {
    const { label, icon: Icon, badgeClass } = config[status];
    const displayLabel = status === 'warnings' && warningCount
        ? `${warningCount} ${warningCount === 1 ? 'Warning' : 'Warnings'}`
        : label;

    return (
        <Badge variant="outline" className={cn(badgeClass, className)}>
            <Icon className="size-3" />
            {displayLabel}
        </Badge>
    );
}

/**
 * Derive the status from an eligibility result's toArray() shape.
 */
export function deriveEligibilityStatus(result: {
    is_eligible?: boolean;
    is_allowed?: boolean;
    blocked_reasons?: string[];
    warning_reasons?: string[];
}): { status: EligibilityStatus; warningCount: number } {
    const blocked = result.blocked_reasons?.length ?? 0;
    const warnings = result.warning_reasons?.length ?? 0;
    const allowed = result.is_allowed ?? result.is_eligible ?? true;

    if (!allowed || blocked > 0) {
        return { status: 'blocked', warningCount: 0 };
    }
    if (warnings > 0) {
        return { status: 'warnings', warningCount: warnings };
    }
    return { status: 'eligible', warningCount: 0 };
}
