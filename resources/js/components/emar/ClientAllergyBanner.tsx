import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { AlertTriangle, ChevronDown, ChevronUp, Info, ShieldAlert, Skull } from 'lucide-react';
import { useState } from 'react';

type Allergy = {
    allergen: string;
    reaction: string;
    severity: string;
};

interface Props {
    allergies: Allergy[];
}

const severityConfig: Record<string, { bg: string; border: string; text: string; badge: string; icon: typeof ShieldAlert }> = {
    life_threatening: {
        bg: 'bg-status-critical-bg',
        border: 'border-status-critical/30 dark:border-status-critical/30',
        text: 'text-status-critical dark:text-status-critical',
        badge: 'bg-status-critical text-white',
        icon: Skull,
    },
    severe: {
        bg: 'bg-status-warning-bg',
        border: 'border-status-warning/30 dark:border-status-warning/30',
        text: 'text-status-warning dark:text-status-warning',
        badge: 'bg-status-warning text-white',
        icon: ShieldAlert,
    },
    moderate: {
        bg: 'bg-status-warning-bg',
        border: 'border-status-warning/30 dark:border-status-warning/30',
        text: 'text-status-warning dark:text-status-warning',
        badge: 'bg-status-warning text-white',
        icon: AlertTriangle,
    },
    mild: {
        bg: 'bg-status-info-bg',
        border: 'border-status-info/30 dark:border-status-info/30',
        text: 'text-status-info dark:text-status-info',
        badge: 'bg-status-info text-white',
        icon: Info,
    },
};

const severityOrder: Record<string, number> = {
    life_threatening: 0,
    severe: 1,
    moderate: 2,
    mild: 3,
};

function getConfig(severity: string) {
    return severityConfig[severity] ?? severityConfig.mild;
}

export default function ClientAllergyBanner({ allergies }: Props) {
    const [expanded, setExpanded] = useState(false);

    if (!allergies || allergies.length === 0) return null;

    const sorted = [...allergies].sort(
        (a, b) => (severityOrder[a.severity] ?? 4) - (severityOrder[b.severity] ?? 4),
    );

    const visibleAllergies = expanded ? sorted : sorted.slice(0, 3);
    const hasMore = sorted.length > 3;

    // Use the highest severity for the outer container
    const highestConfig = getConfig(sorted[0].severity);

    return (
        <div className={`rounded-lg border-2 ${highestConfig.border} ${highestConfig.bg} p-3`}>
            <div className="mb-2 flex items-center gap-2">
                <ShieldAlert className={`h-5 w-5 ${highestConfig.text}`} />
                <span className={`text-sm font-bold ${highestConfig.text}`}>
                    Known Allergies ({allergies.length})
                </span>
            </div>
            <div className="space-y-1.5">
                {visibleAllergies.map((allergy, idx) => {
                    const config = getConfig(allergy.severity);
                    const Icon = config.icon;
                    return (
                        <div
                            key={idx}
                            className="flex items-center gap-2 text-sm"
                        >
                            <Icon className={`h-4 w-4 shrink-0 ${config.text}`} />
                            <span className={`font-semibold ${config.text}`}>
                                {allergy.allergen}
                            </span>
                            {allergy.reaction && (
                                <span className={`${config.text} opacity-80`}>
                                    &mdash; {allergy.reaction}
                                </span>
                            )}
                            <Badge className={`${config.badge} ml-1 text-[10px]`}>
                                {allergy.severity.replace('_', ' ')}
                            </Badge>
                        </div>
                    );
                })}
            </div>
            {hasMore && (
                <Button
                    variant="ghost"
                    size="sm"
                    className={`mt-1.5 h-6 px-2 text-xs ${highestConfig.text}`}
                    onClick={() => setExpanded(!expanded)}
                >
                    {expanded ? (
                        <>
                            <ChevronUp className="mr-1 h-3 w-3" />
                            Show less
                        </>
                    ) : (
                        <>
                            <ChevronDown className="mr-1 h-3 w-3" />
                            Show {sorted.length - 3} more
                        </>
                    )}
                </Button>
            )}
        </div>
    );
}
