import type { LucideIcon } from 'lucide-react';
import { CheckCircle, Clock, ShieldCheck, Users2 } from 'lucide-react';

export type SystemUserStats = {
    total: number;
    active: number;
    pending: number;
    staff: number;
};

type SystemUserSummaryDefinition = {
    key: keyof SystemUserStats;
    heroLabel: string;
    cardLabel: string;
    icon: LucideIcon;
    color: 'violet' | 'emerald' | 'amber' | 'blue';
};

const SYSTEM_USER_SUMMARY_DEFINITIONS: SystemUserSummaryDefinition[] = [
    {
        key: 'total',
        heroLabel: 'Total',
        cardLabel: 'Total Users',
        icon: Users2,
        color: 'violet',
    },
    {
        key: 'active',
        heroLabel: 'Active',
        cardLabel: 'Active',
        icon: CheckCircle,
        color: 'emerald',
    },
    {
        key: 'pending',
        heroLabel: 'Pending',
        cardLabel: 'Pending Approval',
        icon: Clock,
        color: 'amber',
    },
    {
        key: 'staff',
        heroLabel: 'Staff',
        cardLabel: 'Staff Members',
        icon: ShieldCheck,
        color: 'blue',
    },
];

export function buildSystemUserSummary(stats: SystemUserStats) {
    return SYSTEM_USER_SUMMARY_DEFINITIONS.map((definition) => ({
        ...definition,
        value: stats[definition.key],
        staticValue: true as const,
    }));
}
