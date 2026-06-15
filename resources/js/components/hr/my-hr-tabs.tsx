import { router } from '@inertiajs/react';
import {
    CalendarDays,
    Clock,
    FileText,
    GraduationCap,
    LayoutDashboard,
    MessageSquare,
    Receipt,
    ScrollText,
    Star,
    Target,
    User,
    Wallet,
} from 'lucide-react';

import { HrTabs, type HrTabItem } from './hr-tabs';

export type MyHrTab =
    | 'overview'
    | 'profile'
    | 'leave'
    | 'time'
    | 'expenses'
    | 'payslips'
    | 'reviews'
    | 'goals'
    | 'training'
    | 'documents'
    | 'policies'
    | 'surveys';

const TAB_URLS: Record<MyHrTab, string> = {
    overview: '/hr/my',
    profile: '/hr/my/profile',
    leave: '/hr/my/leave',
    time: '/hr/my/time',
    expenses: '/hr/my/expenses',
    payslips: '/hr/my/payslips',
    reviews: '/hr/my/reviews',
    goals: '/hr/my/goals',
    training: '/hr/my/training',
    documents: '/hr/my/documents',
    policies: '/hr/my/policies',
    surveys: '/hr/my/surveys',
};

const ITEMS: HrTabItem[] = [
    { id: 'overview', label: 'Overview', icon: LayoutDashboard, tone: 'primary' },
    { id: 'profile', label: 'Profile', icon: User, tone: 'info' },
    { id: 'leave', label: 'Leave', icon: CalendarDays, tone: 'success' },
    { id: 'time', label: 'Time', icon: Clock, tone: 'violet' },
    { id: 'expenses', label: 'Expenses', icon: Receipt, tone: 'warning' },
    { id: 'payslips', label: 'Payslips', icon: Wallet, tone: 'success' },
    { id: 'reviews', label: 'Reviews', icon: Star, tone: 'info' },
    { id: 'goals', label: 'Goals', icon: Target, tone: 'primary' },
    { id: 'training', label: 'Training', icon: GraduationCap, tone: 'violet' },
    { id: 'documents', label: 'Documents', icon: FileText, tone: 'info' },
    { id: 'policies', label: 'Policies', icon: ScrollText, tone: 'warning' },
    { id: 'surveys', label: 'Surveys', icon: MessageSquare, tone: 'success' },
];

/**
 * Section-level tab strip shared across the My HR (employee self-service) pages
 * so the cluster reads as one hub. The whole my.* route group is open to any
 * authenticated user, so the strip is a static list (no per-tab gating).
 */
export function MyHrTabs({ active }: { active: MyHrTab }) {
    return (
        <HrTabs
            value={active}
            onChange={(id) => {
                if (id !== active) router.visit(TAB_URLS[id as MyHrTab]);
            }}
            items={ITEMS}
            ariaLabel="My HR views"
        />
    );
}

export default MyHrTabs;
