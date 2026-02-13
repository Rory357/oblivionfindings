import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { type BreadcrumbItem } from '@/types';
import { BarChart3, Users, TrendingDown, ShieldCheck, CalendarDays, GraduationCap, Download } from 'lucide-react';

interface AvailableReport {
    key: string;
    title: string;
    description: string;
    category: string;
}

interface RecentReport {
    id: number;
    report_type: string;
    generated_at: string;
    parameters: Record<string, string>;
}

interface Props {
    availableReports: AvailableReport[];
    recentReports: RecentReport[];
    can: { export_data: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Reports', href: '/hr/reports' },
];

const categoryIcons: Record<string, React.ElementType> = {
    headcount: Users,
    turnover: TrendingDown,
    compliance: ShieldCheck,
    leave: CalendarDays,
    training: GraduationCap,
};

const categoryColors: Record<string, string> = {
    headcount: 'border-blue-500/30 text-blue-400 bg-blue-500/10',
    turnover: 'border-red-500/30 text-red-400 bg-red-500/10',
    compliance: 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10',
    leave: 'border-yellow-500/30 text-yellow-400 bg-yellow-500/10',
    training: 'border-purple-500/30 text-purple-400 bg-purple-500/10',
};

export default function ReportsIndex({ availableReports, recentReports, can }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="HR Reports" />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold">HR Reports</h1>
                </div>

                {/* Available Reports Grid */}
                <div>
                    <h2 className="mb-4 text-lg font-semibold">Available Reports</h2>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {availableReports.map((report) => {
                            const IconComponent = categoryIcons[report.category] || BarChart3;
                            const colorClass = categoryColors[report.category] || categoryColors.headcount;
                            return (
                                <Card key={report.key} className="transition-shadow hover:shadow-md">
                                    <CardHeader className="pb-2">
                                        <div className="flex items-center gap-3">
                                            <div className={`flex h-10 w-10 items-center justify-center rounded-lg border ${colorClass}`}>
                                                <IconComponent className="h-5 w-5" />
                                            </div>
                                            <div>
                                                <CardTitle className="text-base">{report.title}</CardTitle>
                                                <Badge variant="outline" className={`mt-1 text-xs ${colorClass}`}>
                                                    {report.category}
                                                </Badge>
                                            </div>
                                        </div>
                                    </CardHeader>
                                    <CardContent>
                                        <p className="mb-3 text-sm text-muted-foreground">{report.description}</p>
                                        <Button variant="outline" size="sm" asChild>
                                            <Link href={`/hr/reports/generate?type=${report.key}`}>
                                                Generate Report
                                            </Link>
                                        </Button>
                                    </CardContent>
                                </Card>
                            );
                        })}
                        {availableReports.length === 0 && (
                            <p className="col-span-full py-8 text-center text-muted-foreground">
                                No reports available.
                            </p>
                        )}
                    </div>
                </div>

                {/* Recent Reports */}
                <div>
                    <h2 className="mb-4 text-lg font-semibold">Recently Generated</h2>
                    <Card>
                        <CardContent className="p-0">
                            <table className="w-full text-sm">
                                <thead className="border-b bg-muted/50">
                                    <tr>
                                        <th className="px-4 py-3 text-left font-medium">Report Type</th>
                                        <th className="px-4 py-3 text-left font-medium">Parameters</th>
                                        <th className="px-4 py-3 text-left font-medium">Generated</th>
                                        <th className="px-4 py-3 text-right font-medium">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {recentReports.map((report) => (
                                        <tr key={report.id} className="hover:bg-muted/30">
                                            <td className="px-4 py-3 font-medium capitalize">
                                                {report.report_type.replace(/_/g, ' ')}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {Object.entries(report.parameters || {}).map(([key, val]) => (
                                                    <span key={key} className="mr-2">
                                                        <span className="capitalize">{key.replace(/_/g, ' ')}</span>: {val}
                                                    </span>
                                                ))}
                                                {(!report.parameters || Object.keys(report.parameters).length === 0) && '\u2014'}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">{report.generated_at}</td>
                                            <td className="px-4 py-3 text-right">
                                                <div className="flex items-center justify-end gap-2">
                                                    <Button variant="ghost" size="sm" asChild>
                                                        <Link href={`/hr/reports/${report.id}`}>View</Link>
                                                    </Button>
                                                    {can.export_data && (
                                                        <Button variant="outline" size="sm" asChild>
                                                            <a href={`/hr/reports/${report.id}/download`} target="_blank" rel="noreferrer">
                                                                <Download className="mr-1 h-3 w-3" />
                                                                Download
                                                            </a>
                                                        </Button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                    {recentReports.length === 0 && (
                                        <tr>
                                            <td colSpan={4} className="px-4 py-8 text-center text-muted-foreground">
                                                No reports generated yet.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
