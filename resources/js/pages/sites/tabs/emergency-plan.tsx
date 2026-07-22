import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Link } from '@inertiajs/react';
import { Map } from 'lucide-react';
import {
    SiteEmergencyPlanSurface,
    type SiteEmergencyPlanProps,
} from '../emergency-plan';
import SiteTypePlanBuilderDialog from '../plan/_builder-dialog';

export type EmergencyPlanModule = Partial<SiteEmergencyPlanProps> & {
    locked?: boolean;
    available: boolean;
    site: SiteEmergencyPlanProps['site'];
    plan_href?: string;
};

export function SiteProfileEmergencyPlan({
    data,
}: {
    data: EmergencyPlanModule;
}) {
    if (!data.available || !data.plan || !data.typePlan) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle>Emergency plan</CardTitle>
                </CardHeader>
                <CardContent className="flex flex-col items-start gap-4">
                    <div className="flex items-start gap-3">
                        <Map className="mt-0.5 h-5 w-5 text-muted-foreground" />
                        <p className="text-sm text-muted-foreground">
                            Publish the Site floor plan before adding emergency
                            exits, assembly points and evacuation routes.
                        </p>
                    </div>
                    {data.plan_href ? (
                        <Button asChild>
                            <Link href={data.plan_href}>Open floor plan</Link>
                        </Button>
                    ) : null}
                </CardContent>
            </Card>
        );
    }

    return (
        <SiteEmergencyPlanSurface
            {...(data as SiteEmergencyPlanProps)}
            embedded
            BuilderDialog={SiteTypePlanBuilderDialog}
        />
    );
}
