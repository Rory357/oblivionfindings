import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, usePage } from '@inertiajs/react';

export default function ReportsIndex() {
    const { auth } = usePage().props as any;
    const { kpis } = usePage().props as any;
    const can = auth?.can ?? {};
    const canIncidents = can?.incidents ?? {};

    return (
        <AppLayout breadcrumbs={[{ title: 'Reports', href: '/reports' }]}>
            <Head title="Reports" />
            <div className="space-y-4 p-4">
                {kpis ? (
                    <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Open incidents</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-3xl font-semibold">{kpis.openIncidents}</div>
                                <div className="mt-1 text-xs text-slate-500">Submitted / reviewed</div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Medication exceptions</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-3xl font-semibold">{kpis.missedMeds7d}</div>
                                <div className="mt-1 text-xs text-slate-500">Missed / withheld / refused (last 7 days)</div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Completed shifts</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-3xl font-semibold">{kpis.completedShifts7d}</div>
                                <div className="mt-1 text-xs text-slate-500">Last 7 days</div>
                            </CardContent>
                        </Card>
                    </div>
                ) : null}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Available reports</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 md:grid-cols-2">
                        <Link href="/reports/shifts" className="rounded-md border p-3 hover:bg-slate-50">
                            <div className="text-sm font-medium">Shift completeness report</div>
                            <div className="mt-1 text-xs text-slate-500">
                                Notes present, tasks completed, completion status. Filter by date.
                            </div>
                        </Link>
                        <Link href="/reports/medications" className="rounded-md border p-3 hover:bg-slate-50">
                            <div className="text-sm font-medium">Medication report (MAR + controlled discrepancies)</div>
                            <div className="mt-1 text-xs text-slate-500">
                                Filter by date, client, service context. Export CSV.
                            </div>
                        </Link>
                        {(canIncidents.export || canIncidents.viewAny) ? (
                            <Link href="/reports/incidents" className="rounded-md border p-3 hover:bg-slate-50">
                                <div className="text-sm font-medium">Incident reports</div>
                                <div className="mt-1 text-xs text-slate-500">
                                    Filter by date, client, severity, review status. Export CSV.
                                </div>
                            </Link>
                        ) : (
                            <div className="rounded-md border p-3 text-sm text-slate-500">
                                Incident reports (no access)
                            </div>
                        )}
                        <Link href="/reports/assets" className="rounded-md border p-3 hover:bg-slate-50">
                            <div className="text-sm font-medium">Asset compliance report</div>
                            <div className="mt-1 text-xs text-slate-500">
                                Overdue inspections, overdue maintenance, and expiring warranties.
                            </div>
                        </Link>
                        <div className="rounded-md border p-3 text-sm text-slate-500">
                            More reports coming next.
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
