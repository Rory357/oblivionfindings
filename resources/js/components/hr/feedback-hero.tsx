import { HrHero } from '@/components/hr/hr-hero';
import { MessageCircle } from 'lucide-react';
import type { ReactNode } from 'react';

export type FeedbackHeroStats = {
    total: number;
    pending: number;
    completed: number;
    overdue: number;
};

export function FeedbackHero({
    stats,
    actions,
}: {
    stats: FeedbackHeroStats;
    actions?: ReactNode;
}) {
    const responseRate =
        stats.total > 0 ? Math.round((stats.completed / stats.total) * 100) : 0;

    return (
        <HrHero
            icon={MessageCircle}
            title="360-degree feedback"
            description="Manage and respond to feedback requests across your team."
            stats={[
                {
                    label: 'Pending',
                    value: stats.pending,
                    href: '/hr/feedback?status=pending',
                    tone: 'warning',
                },
                {
                    label: 'Overdue',
                    value: stats.overdue,
                    href: '/hr/feedback?status=overdue',
                    tone: 'critical',
                },
                {
                    label: 'Completed',
                    value: stats.completed,
                    href: '/hr/feedback?status=completed',
                    tone: 'success',
                },
                {
                    label: 'Response rate',
                    value: `${responseRate}%`,
                    href: '/hr/feedback?status=completed',
                },
                { label: 'Total', value: stats.total, href: '/hr/feedback' },
            ]}
            actions={actions}
        />
    );
}

export default FeedbackHero;
