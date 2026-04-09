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
            <Alert variant="destructive" className={cn('border-red-300 bg-red-50 dark:border-red-800 dark:bg-red-950/50', className)}>
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
                'border-yellow-300 bg-yellow-50 text-yellow-800 dark:border-yellow-800 dark:bg-yellow-950/50 dark:text-yellow-300',
                '[&>svg]:text-yellow-600 dark:[&>svg]:text-yellow-400',
                className,
            )}
        >
            <AlertTriangle className="size-4" />
            <AlertTitle>{title ?? 'Eligibility warnings'}</AlertTitle>
            <AlertDescription className="text-yellow-700 dark:text-yellow-400">
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
