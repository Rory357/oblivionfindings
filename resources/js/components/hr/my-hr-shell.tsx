import { Head } from '@inertiajs/react';
import { createContext, useContext, useState, type ReactNode } from 'react';

import { PageLayout } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

import { MyHrHero, type MyHrHeroHandlers } from './my-hr-hero';
import { MyHrKudosWizard } from './my-hr-kudos-wizard';
import { MyHrTabs, type MyHrTab } from './my-hr-tabs';
import type { MyHrShellData } from './my-hr-types';

const BREADCRUMBS: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr/my' },
    { title: 'My HR', href: '/hr/my' },
];

/** Lets any My HR page body open the shared "Send kudos" wizard hosted by the
 *  shell (the same instance the hero quick-action opens). */
const MyHrKudosContext = createContext<() => void>(() => {});

export function useSendKudos(): () => void {
    return useContext(MyHrKudosContext);
}

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
    const [kudosOpen, setKudosOpen] = useState(false);
    const openKudos = () => setKudosOpen(true);

    const badges: Partial<Record<MyHrTab, ReactNode>> = {
        leave: counts.pendingLeave || undefined,
        one: counts.onesToAck || undefined,
        documents: counts.docsToSign || undefined,
        policies: counts.policiesDue || undefined,
    };

    return (
        <AppLayout breadcrumbs={BREADCRUMBS}>
            <Head title={title ?? 'My HR'} />
            <MyHrKudosContext.Provider value={openKudos}>
                <PageLayout
                    hero={
                        <MyHrHero
                            myHr={myHr}
                            handlers={{ onSendKudos: openKudos, ...heroHandlers }}
                        />
                    }
                >
                    <MyHrTabs active={active} badges={badges} />
                    {children}
                </PageLayout>
            </MyHrKudosContext.Provider>
            <MyHrKudosWizard
                open={kudosOpen}
                onClose={() => setKudosOpen(false)}
                teammates={myHr.teammates}
            />
        </AppLayout>
    );
}

export default MyHrShell;
