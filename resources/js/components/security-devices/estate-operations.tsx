import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import {
    AlertTriangle,
    Archive,
    CheckCircle2,
    CircleDashed,
    CircleHelp,
    Clock3,
    MonitorOff,
    Package,
    Power,
    RadioTower,
    Wrench,
    XCircle,
    type LucideIcon,
} from 'lucide-react';

type OperationalStateSpec = {
    label: string;
    icon: LucideIcon;
    className: string;
};

function stateSpec(state: string): OperationalStateSpec {
    switch (state) {
        case 'critical':
        case 'failed':
        case 'offline':
        case 'lost':
            return {
                label:
                    state === 'offline'
                        ? 'Offline'
                        : state === 'lost'
                          ? 'Lost'
                          : 'Critical',
                icon: XCircle,
                className:
                    'border-status-critical/30 bg-status-critical-bg text-status-critical',
            };
        case 'warning':
        case 'attention':
        case 'stale':
        case 'degraded':
            return {
                label:
                    state === 'stale'
                        ? 'Stale'
                        : state === 'degraded'
                          ? 'Degraded'
                          : 'Needs attention',
                icon: AlertTriangle,
                className:
                    'border-status-warning/30 bg-status-warning-bg text-status-warning',
            };
        case 'healthy':
        case 'online':
        case 'active':
            return {
                label:
                    state === 'online'
                        ? 'Online'
                        : state === 'active'
                          ? 'Active'
                          : 'Healthy',
                icon: state === 'active' ? Power : CheckCircle2,
                className:
                    'border-status-success/30 bg-status-success-bg text-status-success',
            };
        case 'unmonitored':
            return {
                label: 'Not monitored',
                icon: MonitorOff,
                className:
                    'border-status-warning/30 bg-status-warning-bg text-status-warning',
            };
        case 'not_configured':
            return {
                label: 'Not configured',
                icon: CircleDashed,
                className: 'border-border bg-muted text-muted-foreground',
            };
        case 'maintenance':
            return {
                label: 'Maintenance',
                icon: Wrench,
                className:
                    'border-status-warning/30 bg-status-warning-bg text-status-warning',
            };
        case 'in_stock':
            return {
                label: 'In stock',
                icon: Package,
                className: 'border-border bg-muted text-muted-foreground',
            };
        case 'decommissioned':
            return {
                label: 'Decommissioned',
                icon: Archive,
                className: 'border-border bg-muted text-muted-foreground',
            };
        case 'pending':
            return {
                label: 'Scheduled',
                icon: Clock3,
                className: 'border-border bg-muted text-muted-foreground',
            };
        case 'unknown':
            return {
                label: 'Unknown',
                icon: CircleHelp,
                className: 'border-border bg-muted text-muted-foreground',
            };
        default:
            return {
                label: operationalHealthLabel(state),
                icon: RadioTower,
                className: 'border-border bg-muted text-muted-foreground',
            };
    }
}

export function operationalHealthLabel(state: string): string {
    if (!state) return 'Unknown';

    return state
        .replace(/_/g, ' ')
        .replace(/^./, (character) => character.toUpperCase());
}

export function OperationalStateBadge({
    state,
    className,
}: {
    state: string;
    className?: string;
}) {
    const spec = stateSpec(state);
    const Icon = spec.icon;

    return (
        <Badge
            variant="outline"
            className={cn('gap-1.5 font-medium', spec.className, className)}
        >
            <Icon className="h-3.5 w-3.5" aria-hidden="true" />
            {spec.label}
        </Badge>
    );
}

export function formatCoverage(percent: number | null): string {
    return percent === null ? 'Not measured' : `${percent}% monitored`;
}

export function CoverageIndicator({
    percent,
    monitored,
    total,
    className,
}: {
    percent: number | null;
    monitored: number;
    total: number;
    className?: string;
}) {
    return (
        <div className={cn('space-y-1.5', className)}>
            <div className="flex items-center justify-between gap-3 text-xs">
                <span className="font-medium">{formatCoverage(percent)}</span>
                <span className="text-muted-foreground">
                    {monitored} of {total}
                </span>
            </div>
            {percent !== null ? (
                <div
                    role="progressbar"
                    aria-label="Monitoring coverage"
                    aria-valuemin={0}
                    aria-valuemax={100}
                    aria-valuenow={percent}
                    className="h-2 overflow-hidden rounded-full bg-muted"
                >
                    <div
                        className={cn(
                            'h-full rounded-full transition-[width]',
                            percent === 100
                                ? 'bg-status-success'
                                : 'bg-status-warning',
                        )}
                        style={{
                            width: `${Math.min(100, Math.max(0, percent))}%`,
                        }}
                    />
                </div>
            ) : null}
        </div>
    );
}
