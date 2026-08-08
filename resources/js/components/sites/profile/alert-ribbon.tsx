import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { Bell, ChevronRight, type LucideIcon } from 'lucide-react';

export type SiteProfileAlert = {
    id: string;
    label: string;
    detail?: string;
    tone: 'critical' | 'warning';
    icon: LucideIcon;
    onSelect: () => void;
};

export function SiteProfileAlertRibbon({
    alerts,
}: {
    alerts: SiteProfileAlert[];
}) {
    if (!alerts.length) return null;

    return (
        <div
            className="mt-4 flex flex-wrap items-center gap-2"
            data-test="site-profile-alert-ribbon"
        >
            <span className="inline-flex items-center gap-1.5 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                <Bell className="h-3.5 w-3.5" /> Needs attention
            </span>
            {alerts.map((alert) => {
                const Icon = alert.icon;
                return (
                    <Button
                        key={alert.id}
                        type="button"
                        unstyled
                        onClick={alert.onSelect}
                        className={cn(
                            'group inline-flex min-h-11 items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-medium transition-all hover:shadow-sm',
                            alert.tone === 'critical'
                                ? 'border-status-critical/30 bg-status-critical-bg text-status-critical'
                                : 'border-status-warning/30 bg-status-warning-bg text-status-warning',
                        )}
                    >
                        <Icon className="h-3.5 w-3.5" />
                        {alert.label}
                        {alert.detail ? (
                            <span className="opacity-60">· {alert.detail}</span>
                        ) : null}
                        <ChevronRight className="h-3.5 w-3.5 opacity-0 transition-opacity group-hover:opacity-70" />
                    </Button>
                );
            })}
        </div>
    );
}
