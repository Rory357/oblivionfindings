import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import MealPlannerSubTabs from '@/pages/sites/meal-planner';
import { CateringTabs } from './_tabs';

type Props = { default_site_id: number | null };

export default function CateringMealPlanner({ default_site_id }: Props) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Catering', href: '/catering' }, { title: 'Meal Planner', href: '/catering' }]}>
            <Head title="Meal Planner" />
            <div className="space-y-4 p-6">
                <CateringTabs active="meal-planner" />
                {default_site_id ? (
                    <MealPlannerSubTabs mode="standalone" defaultSiteId={default_site_id} />
                ) : (
                    <div className="rounded-xl border border-dashed border-border bg-card p-10 text-center text-sm text-muted-foreground">
                        No active sites yet — create a site to start planning meals.
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
