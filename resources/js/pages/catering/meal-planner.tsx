import { PageLayout } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import MealPlannerSubTabs from '@/pages/sites/meal-planner';
import { Head, router } from '@inertiajs/react';

/**
 * One site's Meal Planner (/catering?site=…). The side menu opens the
 * all-sites overview (meal-planner-overview.tsx); the breadcrumb and the
 * site switcher's "All sites" lead back to it.
 */
type Props = {
    default_site_id: number;
    default_site_name: string;
    /** Monday (Y-m-d) of the week the link asked for; this week when null. */
    default_week?: string | null;
};

export default function CateringMealPlanner({
    default_site_id,
    default_site_name,
    default_week,
}: Props) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Meal planner', href: '/catering' },
                {
                    title: default_site_name,
                    href: `/catering?site=${default_site_id}`,
                },
            ]}
        >
            <Head title={`${default_site_name} · Meal planner`} />
            <PageLayout width="full">
                <MealPlannerSubTabs
                    mode="standalone"
                    defaultSiteId={default_site_id}
                    defaultWeek={default_week ?? undefined}
                    onSiteChange={(site) =>
                        // Switching sites keeps the planner's state; the URL,
                        // history entry and breadcrumb follow the new site.
                        router.replace({
                            url: `/catering?site=${site.id}`,
                            props: (current) => ({
                                ...current,
                                default_site_id: site.id,
                                default_site_name:
                                    site.name || current.default_site_name,
                                default_week: null,
                            }),
                            preserveState: true,
                            preserveScroll: true,
                        })
                    }
                />
            </PageLayout>
        </AppLayout>
    );
}
