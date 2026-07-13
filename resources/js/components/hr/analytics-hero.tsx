import { HrHero } from '@/components/hr/hr-hero';
import { Badge } from '@/components/ui/badge';
import { BarChart3 } from 'lucide-react';

type AnalyticsHeroProps = {
    currentHeadcount: number;
    turnoverRate: number;
    averageTenure: string;
    complianceScore: number;
};

export function AnalyticsHero({
    currentHeadcount,
    turnoverRate,
    averageTenure,
    complianceScore,
}: AnalyticsHeroProps) {
    return (
        <HrHero
            icon={BarChart3}
            title="Workforce analytics"
            description="Understand workforce movement, tenure, leave, and compliance over the last 12 months."
            stats={[
                {
                    label: 'Headcount',
                    value: currentHeadcount,
                    href: '/hr/headcount',
                },
                { label: 'Turnover', value: `${turnoverRate}%` },
                { label: 'Average tenure', value: averageTenure },
                {
                    label: 'Compliance',
                    value: `${complianceScore}%`,
                    href: '/hr/compliance',
                    tone: complianceScore < 90 ? 'warning' : 'success',
                },
            ]}
            actions={
                <Badge
                    variant="outline"
                    className="border-primary-foreground/30 bg-primary-foreground/10 text-xs font-normal text-primary-foreground backdrop-blur-sm"
                >
                    Last 12 months
                </Badge>
            }
        />
    );
}

export default AnalyticsHero;
