import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, usePage } from '@inertiajs/react';

export default function ReportsIndex() {
    const { kpis, modules = [], combined_reports = [] } = usePage().props as any;

    return (
        <AppLayout breadcrumbs={[{ title: 'Reports', href: '/reports' }]}>
            <Head title="Reports" />
            <div className="space-y-4 p-4">
                {kpis ? (
                    <div className="grid grid-cols-1 gap-3 md:grid-cols-4">
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
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Open safeguarding</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-3xl font-semibold">{kpis.openSafeguarding}</div>
                                <div className="mt-1 text-xs text-slate-500">Not closed</div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Open discrepancies</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-3xl font-semibold">{kpis.openDiscrepancies}</div>
                                <div className="mt-1 text-xs text-slate-500">Controlled drugs</div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Overdue asset checks</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-3xl font-semibold">{kpis.overdueAssetChecks}</div>
                                <div className="mt-1 text-xs text-slate-500">Inspection + maintenance</div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Audit events</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-3xl font-semibold">{kpis.auditEvents7d}</div>
                                <div className="mt-1 text-xs text-slate-500">Last 7 days</div>
                            </CardContent>
                        </Card>
                    </div>
                ) : null}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Combined Reports</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 md:grid-cols-3">
                        {combined_reports.map((report: any) => (
                            <div key={report.key} className="rounded-md border p-3">
                                <div className="text-sm font-medium">{report.label}</div>
                                <div className="mt-1 text-xs text-slate-500">
                                    {report.description}
                                </div>
                                <div className="mt-2 flex flex-wrap gap-1">
                                    {report.modules?.map((module: string) => (
                                        <span key={module} className="rounded-full border px-2 py-0.5 text-[11px] text-slate-600">
                                            {module}
                                        </span>
                                    ))}
                                </div>
                                <div className="mt-3 space-y-1">
                                    {(report.preview ?? []).map((item: any) => (
                                        <div key={item.label} className="flex items-center justify-between text-xs">
                                            <span className="text-slate-500">{item.label}</span>
                                            <span className="font-medium">{item.value}</span>
                                        </div>
                                    ))}
                                </div>
                                <div className="mt-3 flex gap-2">
                                    <Link href={report.route} className="rounded-md border px-2 py-1 text-xs hover:bg-slate-50">
                                        View
                                    </Link>
                                    <a href={report.export_route} className="rounded-md border px-2 py-1 text-xs hover:bg-slate-50">
                                        Export CSV
                                    </a>
                                </div>
                            </div>
                        ))}
                        {combined_reports.length === 0 ? (
                            <div className="rounded-md border p-3 text-sm text-slate-500">
                                No combined reports are configured.
                            </div>
                        ) : null}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Module Reports</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 md:grid-cols-2">
                        {modules.map((module: any) => (
                            <div key={module.key} className="rounded-md border p-3">
                                <div className="flex items-center justify-between gap-3">
                                    <div className="text-sm font-medium">{module.label}</div>
                                    <div className="text-xs text-slate-500">{module.summary?.total_records ?? 0} rows</div>
                                </div>
                                <div className="mt-1 text-xs text-slate-500">{module.description}</div>
                                <div className="mt-2 text-xs text-slate-500">
                                    Last activity: {module.summary?.last_activity ?? 'N/A'}
                                </div>
                                <div className="mt-2 flex gap-1 text-[11px] text-slate-600">
                                    {module.summary?.has_search_filter ? <span className="rounded border px-2 py-0.5">search</span> : null}
                                    {module.summary?.has_date_filter ? <span className="rounded border px-2 py-0.5">date</span> : null}
                                    {module.summary?.has_status_filter ? <span className="rounded border px-2 py-0.5">status</span> : null}
                                </div>
                                <div className="mt-3 flex gap-2">
                                    <Link href={module.route} className="rounded-md border px-2 py-1 text-xs hover:bg-slate-50">
                                        View
                                    </Link>
                                    <a href={`${module.route}/export`} className="rounded-md border px-2 py-1 text-xs hover:bg-slate-50">
                                        Export CSV
                                    </a>
                                </div>
                            </div>
                        ))}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
