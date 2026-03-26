import { Badge } from '@/components/ui/badge';
import { AlertTriangle, OctagonX, ShieldAlert } from 'lucide-react';

type Interaction = {
    drug_a: string;
    drug_b: string;
    severity: string;
    description: string;
};

interface Props {
    interactions: Interaction[];
}

const severityConfig: Record<string, { bg: string; border: string; text: string; badge: string; icon: typeof ShieldAlert }> = {
    contraindicated: {
        bg: 'bg-red-50 dark:bg-red-950/40',
        border: 'border-red-300 dark:border-red-800',
        text: 'text-red-800 dark:text-red-200',
        badge: 'bg-red-600 text-white',
        icon: OctagonX,
    },
    major: {
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
};

const severityOrder: Record<string, number> = {
    contraindicated: 0,
    major: 1,
    moderate: 2,
};

function getConfig(severity: string) {
    return severityConfig[severity] ?? severityConfig.moderate;
}

export default function DrugInteractionAlert({ interactions }: Props) {
    if (!interactions || interactions.length === 0) return null;

    const sorted = [...interactions].sort(
        (a, b) => (severityOrder[a.severity] ?? 3) - (severityOrder[b.severity] ?? 3),
    );

    return (
        <div className="space-y-2">
            {sorted.map((interaction, idx) => {
                const config = getConfig(interaction.severity);
                const Icon = config.icon;
                return (
                    <div
                        key={idx}
                        className={`rounded-lg border-2 ${config.border} ${config.bg} p-3`}
                    >
                        <div className="flex items-start gap-2">
                            <Icon className={`mt-0.5 h-5 w-5 shrink-0 ${config.text}`} />
                            <div className="min-w-0 flex-1">
                                <div className="flex items-center gap-2">
                                    <span className={`text-sm font-bold ${config.text}`}>
                                        Drug Interaction
                                    </span>
                                    <Badge className={`${config.badge} text-[10px]`}>
                                        {interaction.severity}
                                    </Badge>
                                </div>
                                <p className={`mt-0.5 text-sm font-medium ${config.text}`}>
                                    {interaction.drug_a} + {interaction.drug_b}
                                </p>
                                {interaction.description && (
                                    <p className={`mt-1 text-xs ${config.text} opacity-80`}>
                                        {interaction.description}
                                    </p>
                                )}
                            </div>
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
