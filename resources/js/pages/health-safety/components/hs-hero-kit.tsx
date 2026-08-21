/* H&S keeps its NZ compliance language here while the visual hero primitives
 * live in the neutral command-centre kit shared with Control Room. Existing
 * imports are re-exported so H&S screens retain their current contract. */
import { cn } from '@/lib/utils';
import { usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    Flame,
    HeartPulse,
    type LucideIcon,
    ShieldCheck,
} from 'lucide-react';

export {
    DOT_CLASS,
    fmt,
    HeroCluster,
    HeroClusterTile,
    HeroMedallion,
    HeroSegmented,
    HeroShell,
    HeroStatusPill,
    HeroSummaryMetric,
    HeroSummaryStrip,
} from '@/components/command-centre/hero-kit';
export type { HeroSegItem, Tone } from '@/components/command-centre/hero-kit';

export type BadgeTone = 'success' | 'warning' | 'critical' | 'neutral';

export type AssuranceStatus = 'certified' | 'action_required' | 'unknown';

type NzsAssurance = {
    certification_status: AssuranceStatus;
    first_aid_coverage_status: AssuranceStatus;
};

export type HeroComplianceBadge = {
    icon: LucideIcon;
    tone: BadgeTone;
    label: string;
};

const CHIP_CLASS: Record<BadgeTone, string> = {
    success:
        'border-primary-foreground/20 bg-primary-foreground/10 text-primary-foreground/90',
    warning:
        'border-status-warning/50 bg-status-warning/25 text-primary-foreground',
    critical:
        'border-status-critical/50 bg-status-critical/25 text-primary-foreground',
    neutral:
        'border-primary-foreground/20 bg-primary-foreground/5 text-primary-foreground/80',
};
const CHIP_ICON: Record<BadgeTone, string> = {
    success: 'text-primary-foreground/80',
    warning: 'text-status-warning',
    critical: 'text-status-critical',
    neutral: 'text-primary-foreground/70',
};

export function assuranceTone(status: AssuranceStatus): BadgeTone {
    return status === 'certified'
        ? 'success'
        : status === 'action_required'
          ? 'warning'
          : 'neutral';
}

export function ngaPaerewaBadge(
    status: AssuranceStatus,
): HeroComplianceBadge {
    return {
        icon: status === 'certified' ? ShieldCheck : AlertTriangle,
        tone: assuranceTone(status),
        label: `Ngā Paerewa NZS 8134:2021 · ${
            status === 'certified'
                ? 'Certified'
                : status === 'action_required'
                  ? 'Action required'
                  : 'Evidence unknown'
        }`,
    };
}

export function useNzsAssurance(): NzsAssurance {
    const page = usePage<{ nzsAssurance?: Partial<NzsAssurance> | null }>();

    return {
        certification_status:
            page.props.nzsAssurance?.certification_status ?? 'unknown',
        first_aid_coverage_status:
            page.props.nzsAssurance?.first_aid_coverage_status ?? 'unknown',
    };
}

export function HeroComplianceBadges({
    items,
    worksafeAwaiting = 0,
    sdsExpiring = 0,
    drillsDue = 0,
    drillsOverdue = 0,
}: {
    items?: HeroComplianceBadge[];
    worksafeAwaiting?: number;
    sdsExpiring?: number;
    drillsDue?: number;
    drillsOverdue?: number;
}) {
    const resolved = useNzsAssurance();
    const certificationStatus = resolved.certification_status;
    const coverageStatus = resolved.first_aid_coverage_status;
    const fireTone: BadgeTone =
        drillsOverdue > 0 ? 'critical' : drillsDue > 0 ? 'warning' : 'success';
    const fireLabel =
        drillsOverdue > 0
            ? `Fire · ${drillsOverdue} drill${drillsOverdue === 1 ? '' : 's'} overdue`
            : drillsDue > 0
              ? `Fire · ${drillsDue} drill${drillsDue === 1 ? '' : 's'} due`
              : 'Fire · Drills current';

    const badges: HeroComplianceBadge[] = items ?? [
        {
            icon: worksafeAwaiting > 0 ? AlertTriangle : CheckCircle2,
            tone: worksafeAwaiting > 0 ? 'warning' : 'success',
            label: `WorkSafe notifiable · ${worksafeAwaiting} awaiting`,
        },
        ngaPaerewaBadge(certificationStatus),
        {
            icon: sdsExpiring > 0 ? AlertTriangle : CheckCircle2,
            tone: sdsExpiring > 0 ? 'warning' : 'success',
            label:
                sdsExpiring > 0
                    ? `Hazardous substances · ${sdsExpiring} SDS expiring`
                    : 'Hazardous substances · SDS current',
        },
        { icon: Flame, tone: fireTone, label: fireLabel },
        {
            icon: HeartPulse,
            tone: assuranceTone(coverageStatus),
            label:
                coverageStatus === 'certified'
                    ? 'First aid · Cover OK'
                    : coverageStatus === 'action_required'
                      ? 'First aid · Cover gaps'
                      : 'First aid · Cover unknown',
        },
    ];

    return (
        <div className="mt-3 flex flex-wrap gap-2">
            {badges.map((badge, index) => (
                <span
                    key={`${badge.label}-${index}`}
                    className={cn(
                        'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium',
                        CHIP_CLASS[badge.tone],
                    )}
                >
                    <badge.icon
                        className={cn('h-3.5 w-3.5', CHIP_ICON[badge.tone])}
                        aria-hidden
                    />
                    {badge.label}
                </span>
            ))}
        </div>
    );
}
