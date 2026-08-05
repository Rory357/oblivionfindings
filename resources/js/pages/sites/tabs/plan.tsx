import { SitePlanSurface, type SitePlanProps } from '../plan';

export type SitePlanData = SitePlanProps & { locked?: boolean };

export function SiteProfilePlan({ data }: { data: SitePlanData }) {
    return <SitePlanSurface {...data} embedded />;
}
