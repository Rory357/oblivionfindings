import { HrHero } from '@/components/hr/hr-hero';
import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/react';
import { Briefcase, Users } from 'lucide-react';

type HeadcountHeroProps = {
    headcount: number;
    totalFte: number;
    vacancies: number;
    attritionRisk: number;
    canRecruit: boolean;
};

export function HeadcountHero({
    headcount,
    totalFte,
    vacancies,
    attritionRisk,
    canRecruit,
}: HeadcountHeroProps) {
    return (
        <HrHero
            icon={Users}
            title="Headcount planning"
            description="Compare workforce capacity with approved positions and upcoming attrition risk."
            stats={[
                { label: 'Headcount', value: headcount },
                { label: 'Total FTE', value: totalFte },
                {
                    label: 'Vacancies',
                    value: vacancies,
                    tone: vacancies > 0 ? 'warning' : 'neutral',
                },
                {
                    label: 'Attrition risk',
                    value: attritionRisk,
                    tone: attritionRisk > 0 ? 'critical' : 'neutral',
                },
            ]}
            actions={
                canRecruit ? (
                    <Button asChild variant="secondary">
                        <Link href="/hr/recruitment?tab=requisitions">
                            <Briefcase className="h-4 w-4" />
                            Open recruitment
                        </Link>
                    </Button>
                ) : undefined
            }
        />
    );
}

export default HeadcountHero;
