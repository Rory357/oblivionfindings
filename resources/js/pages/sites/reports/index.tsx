import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Home, Building2, Warehouse, FileText } from 'lucide-react';

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
    head_office: 'bg-blue-500/10 text-blue-400 border-blue-500/30',
    house: 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30',
    facility: 'bg-amber-500/10 text-amber-400 border-amber-500/30',
};

export default function SiteReportsIndex({ sites }: Props) {
    const houseCount = sites.filter(s => s.type === 'house').length;
    const facilityCount = sites.filter(s => s.type === 'facility').length;
    const officeCount = sites.filter(s => s.type === 'head_office').length;

    return (
        <AppLayout breadcrumbs={[{ title: 'Reports', href: '/sites/reports' }]}>
            <Head title="Site Reports" />

            <div className="m-4 space-y-4">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-lg font-semibold flex items-center gap-2">
                            <FileText className="w-5 h-5" />
                            Site Reports
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Reporting packs for Houses, Facilities, and Head Office
                        </p>
                    </div>
                </div>

                {/* Report Packs */}
                <div className="grid gap-4 sm:grid-cols-3">
                    {/* Houses Report Pack */}
                    <Card className="hover:bg-muted/50 transition-colors">
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <Home className="w-5 h-5 text-emerald-400" />
                                    <CardTitle className="text-base">Houses</CardTitle>
                                </div>
                                <Badge variant="outline" className={typeColors.house}>
                                    {houseCount} sites
                                </Badge>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <p className="text-sm text-muted-foreground">
                                Quality home checks, bedroom occupancy, hazard trends, and maintenance reports.
                            </p>
                            <ul className="text-sm space-y-1 text-slate-300">
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
                    <Card className="hover:bg-muted/50 transition-colors">
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <Warehouse className="w-5 h-5 text-amber-400" />
                                    <CardTitle className="text-base">Facilities</CardTitle>
                                </div>
                                <Badge variant="outline" className={typeColors.facility}>
                                    {facilityCount} sites
                                </Badge>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <p className="text-sm text-muted-foreground">
                                Equipment-focused safety reports, zone utilization, and facility compliance.
                            </p>
                            <ul className="text-sm space-y-1 text-slate-300">
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
                    <Card className="hover:bg-muted/50 transition-colors">
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <Building2 className="w-5 h-5 text-blue-400" />
                                    <CardTitle className="text-base">Head Office</CardTitle>
                                </div>
                                <Badge variant="outline" className={typeColors.head_office}>
                                    {officeCount} sites
                                </Badge>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <p className="text-sm text-muted-foreground">
                                Room booking utilization, safety compliance, and IT asset reports.
                            </p>
                            <ul className="text-sm space-y-1 text-slate-300">
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

                {/* Quick Stats */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm">All Sites Overview</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-4 sm:grid-cols-4">
                            <div className="text-center">
                                <div className="text-2xl font-bold">{sites.length}</div>
                                <div className="text-sm text-muted-foreground">Total Sites</div>
                            </div>
                            <div className="text-center">
                                <div className="text-2xl font-bold text-emerald-400">{houseCount}</div>
                                <div className="text-sm text-muted-foreground">Houses</div>
                            </div>
                            <div className="text-center">
                                <div className="text-2xl font-bold text-amber-400">{facilityCount}</div>
                                <div className="text-sm text-muted-foreground">Facilities</div>
                            </div>
                            <div className="text-center">
                                <div className="text-2xl font-bold text-blue-400">{officeCount}</div>
                                <div className="text-sm text-muted-foreground">Head Offices</div>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
