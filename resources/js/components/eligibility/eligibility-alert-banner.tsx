import { AlertTriangle, XCircle, ShieldAlert } from 'lucide-react';
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/alert';
import { cn } from '@/lib/utils';

interface EligibilityAlertBannerProps {
    /** 'blocked' shows red destructive alert, 'warnings' shows amber alert. */
    type: 'blocked' | 'warnings';
    /** List of reason strings to display as bullet points. */
    reasons: string[];
    /** Optional title override. */
    title?: string;
    className?: string;
}

export function EligibilityAlertBanner({
    type,
    reasons,
    title,
    className,
}: EligibilityAlertBannerProps) {
    if (reasons.length === 0) {
        return null;
    }

    if (type === 'blocked') {
        return (
            <Alert variant="destructive" className={cn('border-status-critical/30 bg-status-critical-bg dark:border-status-critical/30', className)}>
                <ShieldAlert className="size-4" />
                <AlertTitle>{title ?? 'This staff member cannot be assigned'}</AlertTitle>
                <AlertDescription>
                    <ul className="mt-1 space-y-0.5">
                        {reasons.map((reason, i) => (
                            <li key={i} className="flex items-start gap-2">
                                <XCircle className="mt-0.5 size-3 shrink-0" />
                                <span>{reason}</span>
                            </li>
                        ))}
                    </ul>
                </AlertDescription>
            </Alert>
        );
    }

    return (
        <Alert
            className={cn(
                'border-status-warning/30 bg-status-warning-bg text-status-warning dark:border-status-warning/30 dark:bg-status-warning-bg dark:text-status-warning',
                '[&>svg]:text-status-warning dark:[&>svg]:text-status-warning',
                className,
            )}
        >
            <AlertTriangle className="size-4" />
            <AlertTitle>{title ?? 'Eligibility warnings'}</AlertTitle>
            <AlertDescription className="text-status-warning dark:text-status-warning">
                <ul className="mt-1 space-y-0.5">
                    {reasons.map((reason, i) => (
                        <li key={i} className="flex items-start gap-2">
                            <AlertTriangle className="mt-0.5 size-3 shrink-0" />
                            <span>{reason}</span>
                        </li>
                    ))}
                </ul>
            </AlertDescription>
        </Alert>
    );
}
