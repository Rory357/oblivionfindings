import { PageHeader, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { ShieldAlert, Users } from 'lucide-react';
import { Fragment, type ReactNode } from 'react';

/** Masks retained UI data; canonical read permission remains a server decision. */
export function ParticipationViewerBoundary({
    currentActorId,
    sourceActorId,
    manage,
    children,
}: {
    currentActorId: number | null | undefined;
    sourceActorId: number | null | undefined;
    manage: boolean;
    children: ReactNode;
}) {
    if (
        !Number.isSafeInteger(sourceActorId) ||
        Number(sourceActorId) < 1 ||
        sourceActorId !== currentActorId
    ) {
        return (
            <AppLayout
                breadcrumbs={[
                    { title: 'Home', href: '/dashboard' },
                    {
                        title: 'Worker participation',
                        href: '/health-safety/worker-participation',
                    },
                ]}
            >
                <Head title="Worker participation" />
                <PageLayout
                    hero={
                        <PageHeader
                            title="Worker participation"
                            icon={Users}
                            frontline
                            subline="Representatives, committee meetings and staff consultations"
                        />
                    }
                >
                    <EmptyState
                        icon={ShieldAlert}
                        title="You can’t see this page right now"
                        description="You’ve signed in as someone else, or your access has changed. Reload the page to see what you can view."
                        action={
                            <Button
                                className="frontline-tap frontline-focus"
                                onClick={() =>
                                    router.get(
                                        '/health-safety/worker-participation',
                                        {},
                                        {
                                            preserveState: false,
                                            preserveScroll: false,
                                        },
                                    )
                                }
                            >
                                Reload page
                            </Button>
                        }
                    />
                </PageLayout>
            </AppLayout>
        );
    }
    // Discard open forms and cached action callbacks on account/capability change.
    return (
        <Fragment key={`${sourceActorId}:${manage ? 'manage' : 'view'}`}>
            {children}
        </Fragment>
    );
}
