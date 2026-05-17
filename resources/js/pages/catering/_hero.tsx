import { Badge } from '@/components/ui/badge';
import { Utensils } from 'lucide-react';
import type { ComponentType, ReactNode } from 'react';

type HeroBadge = {
    icon?: ComponentType<{ className?: string }>;
    label: string;
    tone?: 'default' | 'warning';
};

type HeroStat = {
    value: ReactNode;
    label: string;
};

export function CateringHero({
    title = 'Meal Planner',
    subtitle = 'Cross-site overview of meal plans, kitchen inventory and the catering library.',
    badges = [],
    stats = [],
}: {
    title?: string;
    subtitle?: string;
    badges?: HeroBadge[];
    stats?: HeroStat[];
}) {
    return (
        <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-primary/90 via-primary to-primary/80 p-6 text-primary-foreground md:p-8">
            <div className="pointer-events-none absolute -top-16 -right-16 h-64 w-64 rounded-full bg-primary-foreground/5" />
            <div className="pointer-events-none absolute -bottom-20 -left-20 h-48 w-48 rounded-full bg-primary-foreground/5" />
            <div className="pointer-events-none absolute top-1/4 right-1/3 h-24 w-24 rounded-full bg-primary-foreground/5" />

            <div className="relative flex flex-col items-center gap-6 md:flex-row md:items-start">
                <div className="flex h-24 w-24 shrink-0 items-center justify-center rounded-full border-4 border-primary-foreground/20 bg-primary-foreground/10 shadow-xl md:h-28 md:w-28">
                    <Utensils className="h-12 w-12 text-primary-foreground md:h-14 md:w-14" />
                </div>

                <div className="flex-1 text-center md:text-left">
                    <h1 className="text-2xl font-bold md:text-3xl">{title}</h1>
                    <p className="mt-0.5 text-sm text-primary-foreground/70">{subtitle}</p>

                    {badges.length > 0 && (
                        <div className="mt-3 flex flex-wrap items-center justify-center gap-2 md:justify-start">
                            {badges.map((b, i) => {
                                const Icon = b.icon;
                                const cls = b.tone === 'warning'
                                    ? 'border-status-warning/30 bg-status-warning-bg text-status-warning'
                                    : 'border-primary-foreground/20 bg-primary-foreground/10 text-primary-foreground/90';
                                return (
                                    <Badge key={i} className={cls}>
                                        {Icon && <Icon className="mr-1 h-3 w-3" />}
                                        {b.label}
                                    </Badge>
                                );
                            })}
                        </div>
                    )}
                </div>

                {stats.length > 0 && (
                    <div className="hidden gap-6 text-center md:flex">
                        {stats.map((s, i) => (
                            <div key={i}>
                                <p className="text-2xl font-bold">{s.value}</p>
                                <p className="text-xs text-primary-foreground/60">{s.label}</p>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}
