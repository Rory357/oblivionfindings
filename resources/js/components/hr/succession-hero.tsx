import { HrHero } from '@/components/hr/hr-hero';
import { Button } from '@/components/ui/button';
import { Plus, Users } from 'lucide-react';

export type SuccessionHeroStats = {
    total: number;
    high_risk: number;
    vacant: number;
    ready_now: number;
};

export function SuccessionHero({
    stats,
    canManage,
    onCreate,
}: {
    stats: SuccessionHeroStats;
    canManage: boolean;
    onCreate: () => void;
}) {
    return (
        <HrHero
            icon={Users}
            title="Succession planning"
            description="Prioritise continuity risks and develop ready successors for key roles."
            stats={[
                { label: 'Active plans', value: stats.total },
                {
                    label: 'High / critical risk',
                    value: stats.high_risk,
                    tone: stats.high_risk > 0 ? 'critical' : 'neutral',
                },
                {
                    label: 'Vacant roles',
                    value: stats.vacant,
                    tone: stats.vacant > 0 ? 'warning' : 'neutral',
                },
                {
                    label: 'Ready now',
                    value: stats.ready_now,
                    tone: 'success',
                },
            ]}
            actions={
                canManage ? (
                    <Button onClick={onCreate}>
                        <Plus className="h-4 w-4" />
                        New plan
                    </Button>
                ) : undefined
            }
        />
    );
}

export default SuccessionHero;
