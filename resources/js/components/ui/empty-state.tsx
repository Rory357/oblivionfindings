import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { InertiaLinkProps, Link } from '@inertiajs/react';
import { LucideIcon, Search, FileX, Inbox, AlertCircle } from 'lucide-react';
import { ReactNode, isValidElement } from 'react';

interface EmptyStateProps {
    icon?: LucideIcon | ReactNode;
    title?: string;
    heading?: string;
    description?: string;
    action?: ReactNode;
    secondaryAction?: ReactNode;
    className?: string;
    variant?: 'default' | 'compact' | 'inline';
}

const iconMap: Record<string, LucideIcon> = {
    search: Search,
    file: FileX,
    inbox: Inbox,
    alert: AlertCircle,
};

export function EmptyState({
    icon: Icon,
    title,
    heading,
    description,
    action,
    secondaryAction,
    className,
    variant = 'default',
}: EmptyStateProps) {
    const resolvedTitle = heading || title || 'Nothing here yet';
    const IconComponent = typeof Icon === 'function' ? Icon : null;
    const iconNode = isValidElement(Icon)
        ? Icon
        : IconComponent
          ? <IconComponent className={variant === 'compact' ? 'h-8 w-8 text-muted-foreground/50' : variant === 'inline' ? 'h-4 w-4' : 'h-8 w-8 text-muted-foreground'} />
          : null;

    if (variant === 'inline') {
        return (
            <div
                className={cn(
                    'flex items-center gap-3 rounded-lg border border-dashed p-4 text-sm text-muted-foreground',
                    className
                )}
            >
                {iconNode}
                <span>{resolvedTitle}</span>
                {action && <div className="ml-auto">{action}</div>}
            </div>
        );
    }

    if (variant === 'compact') {
        return (
            <div
                className={cn(
                    'flex flex-col items-center justify-center rounded-lg border border-dashed p-6 text-center',
                    className
                )}
            >
                {iconNode}
                <h3 className="mt-3 text-sm font-medium">{resolvedTitle}</h3>
                {description && (
                    <p className="mt-1 text-xs text-muted-foreground">{description}</p>
                )}
                {action && <div className="mt-3">{action}</div>}
            </div>
        );
    }

    return (
        <div
            className={cn(
                'flex flex-col items-center justify-center rounded-xl border border-dashed p-12 text-center',
                className
            )}
        >
            <div className="rounded-full bg-muted p-4">
                {iconNode}
            </div>
            <h3 className="mt-4 text-lg font-semibold">{resolvedTitle}</h3>
            {description && (
                <p className="mt-2 max-w-sm text-sm text-muted-foreground">
                    {description}
                </p>
            )}
            {(action || secondaryAction) && (
                <div className="mt-6 flex items-center gap-3">
                    {action}
                    {secondaryAction}
                </div>
            )}
        </div>
    );
}

// Pre-configured empty states for common scenarios

interface EmptyListProps extends Omit<EmptyStateProps, 'icon'> {
    icon?: LucideIcon | ReactNode;
    itemName?: string;
    itemNamePlural?: string;
    createHref?: InertiaLinkProps['href'];
    createLabel?: string;
    onCreate?: () => void;
}

export function EmptyList({
    icon,
    itemName = 'item',
    itemNamePlural,
    createHref,
    createLabel,
    onCreate,
    title,
    description,
    ...props
}: EmptyListProps) {
    const plural = itemNamePlural || `${itemName}s`;
    const defaultTitle = title || `No ${plural} yet`;
    const defaultDescription =
        description || `Get started by creating your first ${itemName}.`;

    const action =
        createHref || onCreate ? (
            <Button asChild={!!createHref} onClick={onCreate} size="sm">
                {createHref ? (
                    <Link href={createHref}>{createLabel || `Add ${itemName}`}</Link>
                ) : (
                    createLabel || `Add ${itemName}`
                )}
            </Button>
        ) : undefined;

    return (
        <EmptyState
            icon={icon ?? Inbox}
            title={defaultTitle}
            description={defaultDescription}
            action={action}
            {...props}
        />
    );
}

interface EmptySearchProps extends Omit<EmptyStateProps, 'icon'> {
    searchTerm?: string;
    onClear?: () => void;
}

export function EmptySearch({
    searchTerm,
    onClear,
    title,
    description,
    ...props
}: EmptySearchProps) {
    const defaultTitle = title || 'No results found';
    const defaultDescription =
        description ||
        (searchTerm
            ? `No matches found for "${searchTerm}". Try adjusting your search.`
            : 'Try adjusting your filters to see more results.');

    return (
        <EmptyState
            icon={Search}
            title={defaultTitle}
            description={defaultDescription}
            action={
                onClear ? (
                    <Button variant="outline" size="sm" onClick={onClear}>
                        Clear filters
                    </Button>
                ) : undefined
            }
            {...props}
        />
    );
}

interface EmptyErrorProps extends Omit<EmptyStateProps, 'icon'> {
    onRetry?: () => void;
}

export function EmptyError({
    onRetry,
    title,
    description,
    ...props
}: EmptyErrorProps) {
    return (
        <EmptyState
            icon={AlertCircle}
            title={title || 'Something went wrong'}
            description={description || 'Failed to load data. Please try again.'}
            action={
                onRetry ? (
                    <Button size="sm" onClick={onRetry}>
                        Try again
                    </Button>
                ) : undefined
            }
            {...props}
        />
    );
}

export default EmptyState;
