import { PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import MealPlannerSubTabs from '@/pages/sites/meal-planner';
import { Head, Link } from '@inertiajs/react';
import { Home } from 'lucide-react';

type Props = { default_site_id: number | null; can_create_sites?: boolean };

export default function CateringMealPlanner({
    default_site_id,
    can_create_sites,
}: Props) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Sites & Locations', href: '/sites' },
                { title: 'Meal Planner', href: '/catering' },
            ]}
        >
            <Head title="Meal Planner" />
            <PageLayout width="full">
                {default_site_id ? (
                    <MealPlannerSubTabs
                        mode="standalone"
                        defaultSiteId={default_site_id}
                    />
                ) : (
                    <div className="flex flex-col items-center justify-center gap-3 rounded-2xl border border-dashed border-border bg-card px-6 py-16 text-center">
                        <span className="flex h-14 w-14 items-center justify-center rounded-2xl bg-sites-bg text-sites-deep">
                            <Home className="h-7 w-7" />
                        </span>
                        <div>
                            <div className="text-[16px] font-semibold text-foreground">
                                No sites yet
                            </div>
                            <p className="mx-auto mt-1 max-w-md text-[13.5px] text-muted-foreground">
                                Create a house or office to start planning
                                meals, tracking inventory and building shopping
                                lists.
                            </p>
                        </div>
                        {can_create_sites === false ? (
                            <p className="text-[12.5px] text-muted-foreground">
                                Contact an administrator to add a site.
                            </p>
                        ) : (
                            <Button asChild>
                                <Link href="/sites/create">Create a site</Link>
                            </Button>
                        )}
                    </div>
                )}
            </PageLayout>
        </AppLayout>
    );
}
