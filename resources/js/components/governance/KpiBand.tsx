import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import {
    AlertOctagon,
    CalendarDays,
    CheckCircle2,
    ClipboardList,
    ShieldAlert,
    type LucideIcon,
} from 'lucide-react';

export type KpiTone = 'success' | 'info' | 'warning' | 'critical' | 'muted';

export interface KpiTile {
    key: string;
    label: string;
    value: string;
    sublabel?: string;
    tone?: KpiTone;
    href?: string;
}

interface KpiBandProps {
    kpis: KpiTile[];
    className?: string;
}

const TONE_VALUE: Record<KpiTone, string> = {
    success: 'text-status-success',
    info: 'text-status-info',
    warning: 'text-status-warning',
    critical: 'text-status-critical',
    muted: 'text-muted-foreground',
};

const TONE_ICON_BG: Record<KpiTone, string> = {
    success: 'bg-status-success-bg text-status-success',
    info: 'bg-status-info-bg text-status-info',
    warning: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
    muted: 'bg-muted text-muted-foreground',
};

const TONE_SUBLABEL: Record<KpiTone, string> = {
    success: 'text-status-success',
    info: 'text-muted-foreground',
    warning: 'text-status-warning',
    critical: 'text-status-critical',
    muted: 'text-muted-foreground',
};

const ICONS: Record<string, LucideIcon> = {
    upcoming_meetings: CalendarDays,
    open_actions: ClipboardList,
    risks_over_appetite: ShieldAlert,
    policy_attestations: CheckCircle2,
};

const FALLBACK_ICON: LucideIcon = AlertOctagon;

function KpiTileBody({ kpi }: { kpi: KpiTile }) {
    const tone: KpiTone = kpi.tone ?? 'info';
    const Icon = ICONS[kpi.key] ?? FALLBACK_ICON;

    return (
        <CardContent className="flex items-start gap-4 p-5">
            <div className={cn('rounded-xl p-3', TONE_ICON_BG[tone])}>
                <Icon className="h-5 w-5" aria-hidden="true" />
            </div>
            <div className="min-w-0 flex-1">
                <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {kpi.label}
                </p>
                <p
                    className={cn(
                        'mt-1 text-3xl leading-none font-semibold',
                        TONE_VALUE[tone],
                    )}
                >
                    {kpi.value}
                </p>
                {kpi.sublabel ? (
                    <p className={cn('mt-2 text-xs', TONE_SUBLABEL[tone])}>
                        {kpi.sublabel}
                    </p>
                ) : null}
            </div>
        </CardContent>
    );
}

/**
 * Four-tile KPI band rendered immediately under the hero. Each tile is
 * clickable when an href is supplied — gives board members a 1-glance
 * entry point to the relevant module.
 */
export function KpiBand({ kpis, className }: KpiBandProps) {
    if (!kpis?.length) return null;

    return (
        <div
            className={cn(
                'grid gap-4 md:grid-cols-2 xl:grid-cols-4',
                className,
            )}
            data-dusk="cockpit-kpi-band"
        >
            {kpis.map((kpi) => (
                <Card
                    key={kpi.key}
                    className="transition hover:border-primary/40 hover:shadow-sm"
                >
                    {kpi.href ? (
                        <Link
                            href={kpi.href}
                            className="block rounded-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            aria-label={`${kpi.label}: ${kpi.value}${kpi.sublabel ? ` (${kpi.sublabel})` : ''}`}
                        >
                            <KpiTileBody kpi={kpi} />
                        </Link>
                    ) : (
                        <KpiTileBody kpi={kpi} />
                    )}
                </Card>
            ))}
        </div>
    );
}

export default KpiBand;
