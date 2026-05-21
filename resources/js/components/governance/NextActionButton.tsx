import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { resolveActionVerb, type WorkflowArea, type WorkflowStatus } from '@/lib/governance-action-verbs';
import { cn } from '@/lib/utils';

interface NextActionButtonProps {
    area: WorkflowArea;
    status?: WorkflowStatus;
    /** Backend-supplied override; only used if not generic (e.g. not "Open"). */
    actionLabel?: string | null;
    href: string;
    /** Defaults to 'outline' for non-critical, 'default' (filled) for critical/overdue. */
    variant?: 'default' | 'outline' | 'ghost';
    size?: 'default' | 'sm';
    disabled?: boolean;
    className?: string;
    'data-dusk'?: string;
}

/**
 * Specific-verb call-to-action button. Looks up the right verb for a
 * (workflow area + status) pair from the verb library so cards never show
 * a generic "Open" label.
 */
export function NextActionButton({
    area,
    status = 'pending',
    actionLabel,
    href,
    variant,
    size = 'sm',
    disabled = false,
    className,
    ...rest
}: NextActionButtonProps) {
    const label = resolveActionVerb(area, status, actionLabel);
    const resolvedVariant = variant ?? (status === 'overdue' ? 'default' : 'outline');

    return (
        <Button
            asChild={!disabled}
            size={size}
            variant={resolvedVariant}
            disabled={disabled}
            className={cn('group inline-flex items-center gap-1.5', className)}
            data-dusk={rest['data-dusk']}
        >
            {disabled ? (
                <span>{label}</span>
            ) : (
                <Link href={href} className="inline-flex items-center gap-1.5">
                    <span>{label}</span>
                    <ArrowRight
                        className="h-3.5 w-3.5 transition-transform group-hover:translate-x-0.5"
                        aria-hidden="true"
                    />
                </Link>
            )}
        </Button>
    );
}

export default NextActionButton;
