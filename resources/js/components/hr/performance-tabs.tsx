import { router } from '@inertiajs/react';
import {
    Award,
    Gauge,
    GitBranch,
    MessageSquare,
    Sprout,
    Target,
    TrendingUp,
    UserCheck,
} from 'lucide-react';

import { HrTabs, type HrTabItem } from './hr-tabs';

export type PerformanceTab =
    | 'supervision'
    | 'reviews'
    | 'goals'
    | 'development'
    | 'competencies'
    | 'feedback'
    | 'pips'
    | 'succession';

/**
 * Satellite tab clicks deep-link back into the Performance hub's `?tab=` views
 * so hub ↔ satellite navigation feels continuous. Goals & Development keep
 * their canonical standalone hub (/hr/goals) as before.
 */
const TAB_URLS: Record<PerformanceTab, string> = {
    supervision: '/hr/performance?tab=supervision',
    reviews: '/hr/performance?tab=reviews',
    goals: '/hr/goals',
    development: '/hr/goals?tab=development',
    competencies: '/hr/performance?tab=competencies',
    feedback: '/hr/performance?tab=feedback',
    pips: '/hr/performance?tab=pips',
    succession: '/hr/performance?tab=succession',
};

// Mirrors the hub's TAB_ITEMS (ids, labels, icons, tones, order) so the strip
// reads identically on the hub and its satellite pages.
const ITEMS: HrTabItem[] = [
    { id: 'reviews', label: 'Reviews', icon: Award, tone: 'info' },
    { id: 'supervision', label: 'Supervision', icon: UserCheck, tone: 'primary' },
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
