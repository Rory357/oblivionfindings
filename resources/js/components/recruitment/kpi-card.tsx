import { Card, CardContent } from '@/components/ui/card';
import { AnimatedCounter } from './animated-counter';
import { type LucideIcon, TrendingUp, TrendingDown } from 'lucide-react';

interface KpiCardProps {
    label: string;
    value: number;
    icon: LucideIcon;
    trend?: { value: number; direction: 'up' | 'down' };
    prefix?: string;
    suffix?: string;
    decimals?: number;
    description?: string;
    color?: string;
}

export function KpiCard({ label, value, icon: Icon, trend, prefix, suffix, decimals, description, color }: KpiCardProps) {
    return (
        <Card className="relative overflow-hidden">
            <CardContent className="p-5">
                <div className="flex items-start justify-between">
                    <div className="space-y-2">
                        <p className="text-sm font-medium text-muted-foreground">{label}</p>
                        <div className="flex items-baseline gap-2">
                            <p className="text-3xl font-bold tracking-tight">
                                <AnimatedCounter value={value} prefix={prefix} suffix={suffix} decimals={decimals} />
                            </p>
                            {trend && (
                                <span className={`inline-flex items-center gap-0.5 text-xs font-medium ${
                                    trend.direction === 'up' ? 'text-green-500' : 'text-red-500'
                                }`}>
                                    {trend.direction === 'up' ? (
                                        <TrendingUp className="h-3 w-3" />
                                    ) : (
                                        <TrendingDown className="h-3 w-3" />
                                    )}
                                    {trend.value}%
                                </span>
                            )}
                        </div>
                        {description && <p className="text-xs text-muted-foreground">{description}</p>}
                    </div>
                    <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg ${color ?? 'bg-primary/10 text-primary'}`}>
                        <Icon className="h-5 w-5" />
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}
