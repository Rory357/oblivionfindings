import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    LoaderCircle,
    LockKeyhole,
    PackageOpen,
    type LucideIcon,
} from 'lucide-react';

export function SiteProfileLoadingState({ label }: { label: string }) {
    return (
        <Card aria-busy="true" aria-live="polite">
            <CardContent className="flex min-h-48 items-center justify-center gap-3 text-sm text-muted-foreground">
                <LoaderCircle className="h-5 w-5 animate-spin" />
                Loading {label.toLowerCase()}…
            </CardContent>
        </Card>
    );
}

export function SiteProfileErrorState({
    label,
    onRetry,
}: {
    label: string;
    onRetry: () => void;
}) {
    return (
        <Card role="alert" className="border-status-critical/30">
            <CardContent className="flex min-h-48 flex-col items-center justify-center gap-3 text-center">
                <AlertTriangle className="h-7 w-7 text-status-critical" />
                <div>
                    <p className="font-medium">Could not load {label}</p>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Your other Site Profile sections are still available.
                    </p>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    className="min-h-11"
                    onClick={onRetry}
                >
                    Try again
                </Button>
            </CardContent>
        </Card>
    );
}

export function SiteProfileLockedState({
    label,
    description = 'You do not have permission to view this Site information.',
}: {
    label: string;
    description?: string;
}) {
    return (
        <Card>
            <CardContent className="flex min-h-48 flex-col items-center justify-center gap-3 text-center">
                <LockKeyhole className="h-7 w-7 text-muted-foreground" />
                <div>
                    <p className="font-medium">{label} is restricted</p>
                    <p className="mt-1 max-w-md text-sm text-muted-foreground">
                        {description}
                    </p>
                </div>
            </CardContent>
        </Card>
    );
}

export function SiteProfileEmptyState({
    title,
    description,
    icon: Icon = PackageOpen,
    action,
}: {
    title: string;
    description: string;
    icon?: LucideIcon;
    action?: { label: string; href?: string; onClick?: () => void };
}) {
    const actionButton = action ? (
        <Button
            type="button"
            variant="outline"
            className="min-h-11"
            onClick={action.onClick}
        >
            {action.label}
        </Button>
    ) : null;

    return (
        <Card>
            <CardContent className="flex min-h-48 flex-col items-center justify-center gap-3 text-center">
                <Icon className="h-7 w-7 text-muted-foreground" />
                <div>
                    <p className="font-medium">{title}</p>
                    <p className="mt-1 max-w-md text-sm text-muted-foreground">
                        {description}
                    </p>
                </div>
                {action?.href ? (
                    <Button variant="outline" className="min-h-11" asChild>
                        <Link href={action.href}>{action.label}</Link>
                    </Button>
                ) : (
                    actionButton
                )}
            </CardContent>
        </Card>
    );
}
