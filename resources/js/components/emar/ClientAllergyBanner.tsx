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
        bg: 'bg-red-50 dark:bg-red-950/40',
        border: 'border-red-300 dark:border-red-800',
        text: 'text-red-800 dark:text-red-200',
        badge: 'bg-red-600 text-white',
        icon: Skull,
    },
    severe: {
        bg: 'bg-orange-50 dark:bg-orange-950/40',
        border: 'border-orange-300 dark:border-orange-800',
        text: 'text-orange-800 dark:text-orange-200',
        badge: 'bg-orange-600 text-white',
        icon: ShieldAlert,
    },
    moderate: {
        bg: 'bg-yellow-50 dark:bg-yellow-950/40',
        border: 'border-yellow-300 dark:border-yellow-800',
        text: 'text-yellow-800 dark:text-yellow-200',
        badge: 'bg-yellow-600 text-white',
        icon: AlertTriangle,
    },
    mild: {
        bg: 'bg-blue-50 dark:bg-blue-950/40',
        border: 'border-blue-300 dark:border-blue-800',
        text: 'text-blue-800 dark:text-blue-200',
        badge: 'bg-blue-600 text-white',
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
