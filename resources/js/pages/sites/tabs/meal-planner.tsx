import { lazy, Suspense } from 'react';
import {
    SiteProfileLoadingState,
    SiteProfileLockedState,
} from './site-profile-states';

const MealPlanner = lazy(() => import('../meal-planner'));

type MealPlannerSite = {
    id: number;
    name: string;
    type: string;
};

export function SiteProfileMealPlanner({
    site,
    data,
}: {
    site: MealPlannerSite;
    data: { locked?: boolean };
}) {
    if (data.locked) return <SiteProfileLockedState label="Meal Planner" />;

    return (
        <Suspense fallback={<SiteProfileLoadingState label="Meal Planner" />}>
            <MealPlanner site={site} mode="embedded" />
        </Suspense>
    );
}
