import { Head } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { AlertTriangle, Shield, Users, DollarSign, CheckCircle2, Gavel, FileWarning, Radio } from 'lucide-react';

interface Props extends PageProps {
    topRisks: any;
    voidedRisks: any;
    riskChanges: any;
    clientSafety: any;
    workforce: any;
    financial: any;
    compliance: any;
    decisions: any;
    incidents: any;
    controlRoom: any;
    generatedAt: string;
}

function StatCard({ label, value, color }: { label: string; value: number | string; color?: string }) {
    return (
        <div className="p-4 rounded-lg border bg-white">
            <p className="text-sm text-gray-500">{label}</p>
            <p className={cn('text-3xl font-bold', color)}>{value}</p>
        </div>
    );
}

export default function BoardMonthly({ auth, topRisks, voidedRisks, riskChanges, clientSafety, workforce, financial, compliance, decisions, incidents, controlRoom, generatedAt }: Props) {
    const formatDate = (d: string) =>
        new Date(d).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Reports', href: '/governance/reports' },
                { title: 'Board Monthly', href: '#' },
            ]}
        >
            <Head title="Board Monthly Report" />

            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="mb-6">
                    <h1 className="text-3xl font-bold text-gray-900">Board Monthly Report</h1>
                    <p className="text-gray-500 mt-1">Comprehensive governance overview for the board</p>
                </div>

                {/* Top Risks */}
                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <AlertTriangle className="w-5 h-5 text-red-500" />
                            Top Risks
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {topRisks ? (
                            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                                <StatCard label="Critical" value={topRisks.critical ?? 0} color="text-red-600" />
                                <StatCard label="High" value={topRisks.high ?? 0} color="text-orange-600" />
                                <StatCard label="Above Appetite" value={topRisks.above_appetite ?? 0} color="text-purple-600" />
                                <StatCard label="Total Active" value={topRisks.total ?? 0} />
                            </div>
                        ) : (
                            <p className="text-gray-500 text-sm">No risk data available.</p>
                        )}
                    </CardContent>
                </Card>

                {/* Client Safety */}
                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Shield className="w-5 h-5 text-blue-500" />
                            Client Safety
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {clientSafety ? (
                            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                                <StatCard label="Total Incidents" value={clientSafety.total_incidents ?? 0} />
                                <StatCard label="Open" value={clientSafety.open ?? 0} color="text-orange-600" />
                                <StatCard label="Safeguarding" value={clientSafety.safeguarding ?? 0} color="text-red-600" />
                                <StatCard label="Resolved" value={clientSafety.resolved ?? 0} color="text-green-600" />
                            </div>
                        ) : (
                            <p className="text-gray-500 text-sm">No client safety data available.</p>
                        )}
                    </CardContent>
                </Card>

                {/* Workforce */}
                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Users className="w-5 h-5 text-indigo-500" />
                            Workforce
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {workforce ? (
                            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                                <StatCard label="Total Staff" value={workforce.total_staff ?? 0} />
                                <StatCard label="Compliance Rate" value={`${workforce.compliance_rate ?? 0}%`} />
                                <StatCard label="Vacancies" value={workforce.vacancies ?? 0} />
                                <StatCard label="Turnover" value={`${workforce.turnover ?? 0}%`} />
                            </div>
                        ) : (
                            <p className="text-gray-500 text-sm">No workforce data available.</p>
                        )}
                    </CardContent>
                </Card>

                {/* Financial */}
                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <DollarSign className="w-5 h-5 text-green-500" />
                            Financial
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {financial ? (
                            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                                <StatCard label="Budget Total" value={financial.budget_total ?? '-'} />
                                <StatCard label="Actual Spend" value={financial.actual_spend ?? '-'} />
                                <StatCard label="Variance" value={financial.variance ?? '-'} />
                                <StatCard label="YTD" value={financial.ytd ?? '-'} />
                            </div>
                        ) : (
                            <p className="text-gray-500 text-sm">No financial data available.</p>
                        )}
                    </CardContent>
                </Card>

                {/* Compliance Calendar */}
                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <CheckCircle2 className="w-5 h-5 text-teal-500" />
                            Compliance Calendar
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {compliance ? (
                            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                                <StatCard label="Total Obligations" value={compliance.total ?? 0} />
                                <StatCard label="Complete" value={compliance.complete ?? 0} color="text-green-600" />
                                <StatCard label="Overdue" value={compliance.overdue ?? 0} color="text-red-600" />
                                <StatCard label="Due Soon" value={compliance.due_soon ?? 0} color="text-amber-600" />
                            </div>
                        ) : (
                            <p className="text-gray-500 text-sm">No compliance data available.</p>
                        )}
                    </CardContent>
                </Card>

                {/* Decisions Required */}
                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Gavel className="w-5 h-5 text-violet-500" />
                            Decisions Required
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {decisions ? (
                            <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
                                <StatCard label="Pending" value={decisions.pending ?? 0} color="text-orange-600" />
                                <StatCard label="This Month" value={decisions.this_month ?? 0} />
                                <StatCard label="Overdue" value={decisions.overdue ?? 0} color="text-red-600" />
                            </div>
                        ) : (
                            <p className="text-gray-500 text-sm">No decision data available.</p>
                        )}
                    </CardContent>
                </Card>

                {/* Incidents */}
                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <FileWarning className="w-5 h-5 text-amber-500" />
                            Incidents
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {incidents ? (
                            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                                <StatCard label="Total" value={incidents.total ?? 0} />
                                <StatCard label="Open" value={incidents.open ?? 0} color="text-orange-600" />
                                <StatCard label="Serious" value={incidents.serious ?? 0} color="text-red-600" />
                                <StatCard label="Closed" value={incidents.closed ?? 0} color="text-green-600" />
                            </div>
                        ) : (
                            <p className="text-gray-500 text-sm">No incident data available.</p>
                        )}
                    </CardContent>
                </Card>

                {/* Control Room */}
                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Radio className="w-5 h-5 text-cyan-500" />
                            Control Room
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {controlRoom ? (
                            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                                <StatCard label="Total Alerts" value={controlRoom.total ?? 0} />
                                <StatCard label="Critical" value={controlRoom.critical ?? 0} color="text-red-600" />
                                <StatCard label="Open" value={controlRoom.open ?? 0} color="text-orange-600" />
                                <StatCard label="Avg Response" value={controlRoom.avg_response ?? '-'} />
                            </div>
                        ) : (
                            <p className="text-gray-500 text-sm">No control room data available.</p>
                        )}
                    </CardContent>
                </Card>

                {/* Generated timestamp */}
                <p className="text-sm text-gray-400 text-right">
                    Generated: {formatDate(generatedAt)}
                </p>
            </div>
        </AppLayout>
    );
}
