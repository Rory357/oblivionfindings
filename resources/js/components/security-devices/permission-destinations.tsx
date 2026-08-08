import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/react';
import { AlertTriangle, ArrowUpRight, LockKeyhole } from 'lucide-react';

export type ControlRoomAlertAccess = {
    state: 'available' | 'restricted';
    label: string;
};

export type SiteProfileAccess = {
    state: 'available' | 'restricted';
    label: string;
};

export function ControlRoomAlertAccessRequired({
    label = 'Control Room alert access required',
    className = '',
}: {
    label?: string;
    className?: string;
}) {
    return (
        <span
            role="note"
            className={`flex min-h-11 items-center gap-2 text-sm text-muted-foreground ${className}`}
        >
            <LockKeyhole className="h-4 w-4 shrink-0" aria-hidden />
            {label}
        </span>
    );
}

export function ControlRoomDestination({ canView }: { canView: boolean }) {
    if (!canView) {
        return (
            <ControlRoomAlertAccessRequired
                label="Control Room access required"
                className="px-2"
            />
        );
    }

    return (
        <Button asChild variant="outline" className="min-h-11">
            <Link href="/control-room" className="frontline-focus">
                <AlertTriangle className="mr-2 h-4 w-4" aria-hidden />
                Open Control Room
            </Link>
        </Button>
    );
}

export function SiteProfileDestination({
    siteId,
    canView,
}: {
    siteId: number;
    canView: boolean;
}) {
    if (!canView) {
        return <SiteProfileAccessRequired className="px-2" />;
    }

    return (
        <Button asChild variant="outline" size="sm" className="min-h-11">
            <Link href={`/sites/${siteId}`} className="frontline-focus">
                Open Site profile
                <ArrowUpRight className="ml-2 h-4 w-4" aria-hidden />
            </Link>
        </Button>
    );
}

export function SiteProfileAccessRequired({
    label = 'Site profile access required',
    className = '',
}: {
    label?: string;
    className?: string;
}) {
    return (
        <span
            role="note"
            className={`flex min-h-11 items-center gap-2 text-sm text-muted-foreground ${className}`}
        >
            <LockKeyhole className="h-4 w-4 shrink-0" aria-hidden />
            {label}
        </span>
    );
}

export type ItChangeDestinationRecord = {
    id: number;
    reference: string;
    title: string;
};

export function ItChangeDestination({
    change,
}: {
    change: ItChangeDestinationRecord | null;
}) {
    if (!change) return null;

    return (
        <p className="mt-2 text-xs text-muted-foreground">
            IT Change:{' '}
            <Link
                href={`/it/changes/${change.id}`}
                className="frontline-focus rounded-sm font-medium underline-offset-4 hover:underline"
            >
                {change.reference} · {change.title}
            </Link>
        </p>
    );
}
