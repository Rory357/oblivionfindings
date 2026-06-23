import { router } from '@inertiajs/react';
import {
    Award,
    ClipboardCheck,
    Gauge,
    GitBranch,
    MessageSquare,
    Sprout,
    Target,
    TrendingUp,
} from 'lucide-react';

import { HrTabs, type HrTabItem } from './hr-tabs';

export type PerformanceTab =
    | 'overview'
    | 'reviews'
    | 'goals'
    | 'development'
    | 'competencies'
    | 'feedback'
    | 'pips'
    | 'succession';

const TAB_URLS: Record<PerformanceTab, string> = {
    overview: '/hr/performance',
    reviews: '/hr/performance/reviews',
    goals: '/hr/goals',
    development: '/hr/goals/development',
    competencies: '/hr/performance/competencies',
    feedback: '/hr/feedback',
    pips: '/hr/performance/pips',
    succession: '/hr/succession',
};

const ITEMS: HrTabItem[] = [
    { id: 'overview', label: 'Supervision', icon: ClipboardCheck, tone: 'primary' },
    { id: 'reviews', label: 'Reviews', icon: Award, tone: 'info' },
    { id: 'goals', label: 'Goals & OKRs', icon: Target, tone: 'success' },
    { id: 'development', label: 'Development', icon: Sprout, tone: 'info' },
    { id: 'competencies', label: 'Competencies & Skills', icon: Gauge, tone: 'violet' },
    { id: 'feedback', label: '360 Feedback', icon: MessageSquare, tone: 'warning' },
    { id: 'pips', label: 'PIPs', icon: TrendingUp, tone: 'critical' },
    { id: 'succession', label: 'Succession', icon: GitBranch, tone: 'info' },
];

/**
 * Section-level tab strip shared across the Performance & Development pages so
 * the constellation (supervision, reviews, goals, competencies, feedback, PIPs,
 * succession) reads as one hub. All seven sit behind hr.performance.view, so no
 * per-tab gating is needed. Navigates between the existing pages.
 */
export function PerformanceTabs({ active }: { active: PerformanceTab }) {
    return (
        <HrTabs
            value={active}
            onChange={(id) => {
                if (id !== active) router.visit(TAB_URLS[id as PerformanceTab]);
            }}
            items={ITEMS}
            ariaLabel="Performance views"
            className="mb-6"
        />
    );
}

export default PerformanceTabs;
