import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { Download, FileBarChart } from 'lucide-react';

type Props = {
    report_type: string;
    title: string;
    description: string;
    data: any;
    filters: { from?: string; to?: string };
};

const REPORT_TITLES: Record<string, string> = {
    'client-summary': 'Client Summary Report',
    'staff-utilisation': 'Staff Utilisation Report',
    'shift-analytics': 'Shift Analytics Report',
    'billing': 'Billing Report',
    'compliance': 'Compliance Report',
    'service-hours': 'Service Hours Report',
};

export default function ReportShow({ report_type, title, description, data, filters }: Props) {
    return (
        <AppLayout>
            <Head title={title || REPORT_TITLES[report_type] || 'Report'} />
            <PageHeader
                title={title || REPORT_TITLES[report_type] || 'Report'}
                description={description || `Operational report for ${report_type.replace(/-/g, ' ')}.`}
                backHref="/operations/reports"
            />
            <PageShell>
                {/* Filter controls */}
                <div className="mb-4 flex flex-wrap items-center gap-2">
                    <Input type="date" className="h-9 w-[160px] text-xs" defaultValue={filters?.from ?? ''} placeholder="From" />
                    <Input type="date" className="h-9 w-[160px] text-xs" defaultValue={filters?.to ?? ''} placeholder="To" />
                    <Button size="sm" variant="outline" className="h-9 text-xs">
                        <Download className="mr-1.5 h-3.5 w-3.5" /> Export CSV
                    </Button>
                </div>

                {/* Report content placeholder */}
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm font-medium">{REPORT_TITLES[report_type] ?? report_type}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {data && Object.keys(data).length > 0 ? (
                            <div className="space-y-4">
                                {/* Render data as a simple table for now */}
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b text-left text-xs font-medium uppercase tracking-wider text-muted-foreground">
                                                {Object.keys(typeof data === 'object' && !Array.isArray(data) ? data : {}).map((key) => (
                                                    <th key={key} className="pb-2 pr-4">{key.replace(/_/g, ' ')}</th>
                                                ))}
                                            </tr>
                                        </thead>
                                    </table>
                                    <pre className="mt-2 text-xs text-muted-foreground">{JSON.stringify(data, null, 2)}</pre>
                                </div>
                            </div>
                        ) : (
                            <div className="flex flex-col items-center justify-center py-12">
                                <FileBarChart className="mb-4 h-12 w-12 text-muted-foreground/30" />
                                <h2 className="text-lg font-semibold text-muted-foreground">No Data Available</h2>
                                <p className="mt-1 max-w-sm text-center text-sm text-muted-foreground/80">
                                    Select a date range and filters to generate this report. Data will populate as operational activity is recorded.
                                </p>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
