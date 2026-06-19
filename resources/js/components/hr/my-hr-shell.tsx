import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { PageLayout } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

import { MyHrHero, type MyHrHeroHandlers } from './my-hr-hero';
import { MyHrTabs, type MyHrTab } from './my-hr-tabs';
import type { MyHrShellData } from './my-hr-types';

const BREADCRUMBS: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr/my' },
    { title: 'My HR', href: '/hr/my' },
];

/**
 * The standard chrome for every My HR (employee self-service) page: AppLayout +
 * the shared hero (greeting + live clock card) + the tab strip with live
 * "needs attention" count badges. Pages render their tab body as `children`.
 */
export function MyHrShell({
    active,
    myHr,
    title,
    heroHandlers,
    children,
}: {
    active: MyHrTab;
    myHr: MyHrShellData;
    title?: string;
    heroHandlers?: MyHrHeroHandlers;
    children: ReactNode;
}) {
    const { counts } = myHr;
    const badges: Partial<Record<MyHrTab, ReactNode>> = {
        leave: counts.pendingLeave || undefined,
        one: counts.onesToAck || undefined,
        documents: counts.docsToSign || undefined,
        policies: counts.policiesDue || undefined,
    };

    return (
        <AppLayout breadcrumbs={BREADCRUMBS}>
            <Head title={title ?? 'My HR'} />
            <PageLayout hero={<MyHrHero myHr={myHr} handlers={heroHandlers} />}>
                <MyHrTabs active={active} badges={badges} />
                {children}
            </PageLayout>
        </AppLayout>
    );
}

export default MyHrShell;
