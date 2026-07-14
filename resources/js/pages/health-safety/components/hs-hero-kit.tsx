/* H&S keeps its NZ compliance language here while the visual hero primitives
 * live in the neutral command-centre kit shared with Control Room. Existing
 * imports are re-exported so H&S screens retain their current contract. */
import { cn } from '@/lib/utils';
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

type BadgeTone = 'success' | 'warning' | 'critical';

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
};
const CHIP_ICON: Record<BadgeTone, string> = {
    success: 'text-primary-foreground/80',
    warning: 'text-status-warning',
    critical: 'text-status-critical',
};

export function HeroComplianceBadges({
    items,
    worksafeAwaiting = 0,
    sdsExpiring = 0,
    drillsDue = 0,
    drillsOverdue = 0,
    ngaPaerewaCertified = true,
    firstAidOk = true,
}: {
    items?: HeroComplianceBadge[];
    worksafeAwaiting?: number;
    sdsExpiring?: number;
    drillsDue?: number;
    drillsOverdue?: number;
    ngaPaerewaCertified?: boolean;
    firstAidOk?: boolean;
}) {
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
        {
            icon: ShieldCheck,
            tone: ngaPaerewaCertified ? 'success' : 'warning',
            label: `Ngā Paerewa NZS 8134:2021 · ${ngaPaerewaCertified ? 'Certified' : 'Review due'}`,
        },
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
            tone: firstAidOk ? 'success' : 'warning',
            label: firstAidOk
                ? 'First aid · Cover OK'
                : 'First aid · Cover gaps',
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
