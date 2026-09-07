import {
    PageHeader,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageLayout,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { BarChart3, Building2, Home, Warehouse } from 'lucide-react';

type Site = {
    id: number;
    name: string;
    type: 'head_office' | 'house' | 'facility';
    region?: string;
};

type Props = {
    sites: Site[];
};

const typeColors = {
    head_office: 'bg-status-info-bg text-status-info border-status-info/30',
    house: 'bg-status-success-bg text-status-success border-status-success/30',
    facility:
        'bg-status-warning-bg text-status-warning border-status-warning/30',
};

export default function SiteReportsIndex({ sites }: Props) {
    const houseCount = sites.filter((s) => s.type === 'house').length;
    const facilityCount = sites.filter((s) => s.type === 'facility').length;
    const officeCount = sites.filter((s) => s.type === 'head_office').length;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Sites', href: '/sites' },
                { title: 'Reports', href: '/sites/reports' },
            ]}
        >
            <Head title="Site Reports" />

            <PageLayout
                hero={
                    <PageHeader
                        icon={BarChart3}
                        title="Site Reports"
                        subline={`Reporting packs for Houses, Facilities and Head Office · ${sites.length} ${sites.length === 1 ? 'site' : 'sites'}`}
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Total sites"
                                    ariaLabel="View all sites"
                                    href="/sites"
                                >
                                    <PageHeaderMeterBig>
                                        {sites.length}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        across all types
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Houses"
                                    tone="success"
                                    ariaLabel="View house reports"
                                    href="/sites/reports/houses"
                                >
                                    <PageHeaderMeterBig>
                                        {houseCount}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        quality & occupancy pack
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Facilities"
                                    tone="warning"
                                    ariaLabel="View facility reports"
                                    href="/sites/reports/facilities"
                                >
                                    <PageHeaderMeterBig>
                                        {facilityCount}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        equipment & safety pack
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Head office"
                                    ariaLabel="View head office reports"
                                    href="/sites/reports/head-office"
                                >
                                    <PageHeaderMeterBig>
                                        {officeCount}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        rooms & IT pack
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            </>
                        }
                    />
                }
            >
                {/* Report Packs */}
                <div className="grid gap-4 sm:grid-cols-3">
                    {/* Houses Report Pack */}
                    <Card className="transition-colors hover:bg-muted/50">
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <Home className="h-5 w-5 text-status-success" />
                                    <CardTitle className="text-base">
                                        Houses
                                    </CardTitle>
                                </div>
                                <Badge
                                    variant="outline"
                                    className={typeColors.house}
                                >
                                    {houseCount} sites
                                </Badge>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <p className="text-sm text-muted-foreground">
                                Quality home checks, bedroom occupancy, hazard
                                trends, and maintenance reports.
                            </p>
                            <ul className="space-y-1 text-sm text-muted-foreground">
                                <li>- Hazards by severity & time-to-close</li>
                                <li>- Checklist compliance rates</li>
                                <li>- Bedroom occupancy reports</li>
                                <li>- Vendor contact lists</li>
                            </ul>
                            <Button asChild className="w-full">
                                <Link href="/sites/reports/houses">
                                    View House Reports
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>

                    {/* Facilities Report Pack */}
                    <Card className="transition-colors hover:bg-muted/50">
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <Warehouse className="h-5 w-5 text-status-warning" />
                                    <CardTitle className="text-base">
                                        Facilities
                                    </CardTitle>
                                </div>
                                <Badge
                                    variant="outline"
                                    className={typeColors.facility}
                                >
                                    {facilityCount} sites
                                </Badge>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <p className="text-sm text-muted-foreground">
                                Equipment-focused safety reports, zone
                                utilization, and facility compliance.
                            </p>
                            <ul className="space-y-1 text-sm text-muted-foreground">
                                <li>- Equipment hazard register</li>
                                <li>- Safety walkthrough compliance</li>
                                <li>- Equipment condition summary</li>
                                <li>- Zone utilization reports</li>
                            </ul>
                            <Button asChild className="w-full">
                                <Link href="/sites/reports/facilities">
                                    View Facility Reports
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>

                    {/* Head Office Report Pack */}
                    <Card className="transition-colors hover:bg-muted/50">
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <Building2 className="h-5 w-5 text-status-info" />
                                    <CardTitle className="text-base">
                                        Head Office
                                    </CardTitle>
                                </div>
                                <Badge
                                    variant="outline"
                                    className={typeColors.head_office}
                                >
                                    {officeCount} sites
                                </Badge>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <p className="text-sm text-muted-foreground">
                                Room booking utilization, safety compliance, and
                                IT asset reports.
                            </p>
                            <ul className="space-y-1 text-sm text-muted-foreground">
                                <li>- Room booking utilization</li>
                                <li>- Safety & facilities compliance</li>
                                <li>- IT/Network asset summary</li>
                                <li>- Meeting room usage trends</li>
                            </ul>
                            <Button asChild className="w-full">
                                <Link href="/sites/reports/head-office">
                                    View Head Office Reports
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
