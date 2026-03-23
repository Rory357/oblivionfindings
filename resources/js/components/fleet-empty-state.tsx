import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

interface FleetEmptyStateProps {
    icon: LucideIcon;
    title: string;
    description?: string;
    actionLabel?: string;
    actionHref?: string;
    onAction?: () => void;
    compact?: boolean;
}

export function FleetEmptyState({
    icon: Icon,
    title,
    description,
    actionLabel,
    actionHref,
    onAction,
    compact = false,
}: FleetEmptyStateProps) {
    return (
        <div className={`flex flex-col items-center justify-center text-center ${compact ? 'py-6' : 'py-12'}`}>
            <div className="mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-muted">
                <Icon className="h-7 w-7 text-muted-foreground/60" />
            </div>
            <h3 className={`font-semibold ${compact ? 'text-sm' : 'text-base'}`}>{title}</h3>
            {description && (
                <p className={`mt-1 max-w-sm text-muted-foreground ${compact ? 'text-xs' : 'text-sm'}`}>
                    {description}
                </p>
            )}
            {actionLabel && (actionHref || onAction) && (
                <div className="mt-4">
                    {actionHref ? (
                        <Button size={compact ? 'sm' : 'default'} asChild>
                            <Link href={actionHref}>
                                <Plus className="mr-1.5 h-4 w-4" />
                                {actionLabel}
                            </Link>
                        </Button>
                    ) : (
                        <Button size={compact ? 'sm' : 'default'} onClick={onAction}>
                            <Plus className="mr-1.5 h-4 w-4" />
                            {actionLabel}
                        </Button>
                    )}
                </div>
            )}
        </div>
    );
}
