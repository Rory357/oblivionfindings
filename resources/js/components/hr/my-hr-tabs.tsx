import { router } from '@inertiajs/react';
import {
    CalendarDays,
    Clock,
    FileText,
    GraduationCap,
    LayoutDashboard,
    Megaphone,
    MessagesSquare,
    MessageSquare,
    Receipt,
    ScrollText,
    Star,
    Target,
    User,
    Users,
    Wallet,
} from 'lucide-react';
import type { ReactNode } from 'react';

import { HrTabs, type HrTabItem } from './hr-tabs';

export type MyHrTab =
    | 'overview'
    | 'leave'
    | 'time'
    | 'one'
    | 'shoutouts'
    | 'documents'
    | 'profile'
    | 'directory'
    | 'expenses'
    | 'payslips'
    | 'training'
    | 'policies'
    | 'reviews'
    | 'goals'
    | 'surveys';

const TAB_URLS: Record<MyHrTab, string> = {
    overview: '/hr/my',
    leave: '/hr/my/leave',
    time: '/hr/my/time',
    one: '/hr/my/one',
    shoutouts: '/hr/my/shoutouts',
    documents: '/hr/my/documents',
    profile: '/hr/my/profile',
    directory: '/hr/my/directory',
    expenses: '/hr/my/expenses',
    payslips: '/hr/my/payslips',
    training: '/hr/my/training',
    policies: '/hr/my/policies',
    reviews: '/hr/my/reviews',
    goals: '/hr/my/goals',
    surveys: '/hr/my/surveys',
};

/**
 * Canonical My HR tab order + tones (from the redesign handoff). Time merges
 * shifts ("Time & Shifts"); a new "1:1s" tab sits after it. The first five are
 * the fully-designed surfaces; the rest follow in the same strip.
 */
const ITEMS: Omit<HrTabItem, 'badge'>[] = [
    { id: 'overview', label: 'Overview', icon: LayoutDashboard, tone: 'primary' },
    { id: 'leave', label: 'Leave', icon: CalendarDays, tone: 'success' },
    { id: 'time', label: 'Time & Shifts', icon: Clock, tone: 'violet' },
    { id: 'one', label: '1:1s', icon: MessagesSquare, tone: 'info' },
    { id: 'shoutouts', label: 'Shout-outs', icon: Megaphone, tone: 'primary' },
    { id: 'documents', label: 'Documents', icon: FileText, tone: 'info' },
    { id: 'profile', label: 'Profile', icon: User, tone: 'info' },
    { id: 'directory', label: 'Directory', icon: Users, tone: 'info' },
    { id: 'expenses', label: 'Expenses', icon: Receipt, tone: 'warning' },
    { id: 'payslips', label: 'Payslips', icon: Wallet, tone: 'success' },
    { id: 'training', label: 'Training', icon: GraduationCap, tone: 'violet' },
    { id: 'policies', label: 'Policies', icon: ScrollText, tone: 'warning' },
    { id: 'reviews', label: 'Reviews', icon: Star, tone: 'info' },
    { id: 'goals', label: 'Goals', icon: Target, tone: 'primary' },
    { id: 'surveys', label: 'Surveys', icon: MessageSquare, tone: 'success' },
];

/**
 * Section-level tab strip shared across the My HR (employee self-service) pages
 * so the cluster reads as one hub. The whole my.* route group is open to any
 * authenticated user, so the strip is a static list (no per-tab gating).
 *
 * `badges` surfaces live "needs attention" counts per tab (pending leave,
 * docs-to-sign, policies-due, 1:1s-to-acknowledge); a count of 0/undefined
 * hides the badge.
 */
export function MyHrTabs({
    active,
    badges,
}: {
    active: MyHrTab;
    badges?: Partial<Record<MyHrTab, ReactNode>>;
}) {
    const items: HrTabItem[] = ITEMS.map((item) => {
        const badge = badges?.[item.id as MyHrTab];
        return badge ? { ...item, badge } : item;
    });

    // No mb-6 here (unlike the other HR section tab strips): the My HR shell
    // renders this via PageLayout's `tabs` slot, which already supplies the gap
    // below the strip. Adding mb-6 would double the spacing.
    return (
        <HrTabs
            value={active}
            onChange={(id) => {
                if (id !== active) router.visit(TAB_URLS[id as MyHrTab]);
            }}
            items={items}
            ariaLabel="My HR views"
        />
    );
}

export default MyHrTabs;
