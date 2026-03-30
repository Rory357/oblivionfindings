import AppLayout from '@/layouts/app-layout';
import PageShell from '@/components/page-shell';
import PageHeader from '@/components/page-header';
import { Head, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { TabsRoot as Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { type BreadcrumbItem } from '@/types';
import { BarChart3, AlertTriangle, TrendingUp } from 'lucide-react';
import { useState } from 'react';

interface MonthlyData {
    month: number;
    label: string;
    count: number;
    total_hours: number;
}

interface Absentee {
    user_id: number;
    name: string;
    occurrences: number;
    total_hours: number;
}

interface BradfordEmployee {
    user_id: number;
    name: string;
    spells: number;
    days: number;
    factor: number;
    risk_level: string;
}

interface UtilizationType {
    leave_type: string;
    entitlement: number;
    taken: number;
    pending: number;
    remaining: number;
    pct_used: number;
}

interface UtilizationEmployee {
    user_id: number;
    name: string;
    types: UtilizationType[];
    total_entitlement: number;
    total_used: number;
    total_remaining: number;
    overall_pct: number;
}

interface Props {
    absenteeism: {
        monthly: MonthlyData[];
        top_absentees: Absentee[];
        year: number;
    };
    bradfordFactor: {
        employees: BradfordEmployee[];
        year: number;
    };
    utilization: {
        employees: UtilizationEmployee[];
        year: number;
    };
    year: number;
    can: { manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Leave & Rosters', href: '/hr/leave' },
    { title: 'Reports', href: '/hr/leave/reports' },
];

const riskColors: Record<string, string> = {
    low: 'bg-green-100 text-green-800',
    medium: 'bg-yellow-100 text-yellow-800',
    high: 'bg-orange-100 text-orange-800',
    critical: 'bg-red-100 text-red-800',
};

export default function LeaveReports({ absenteeism, bradfordFactor, utilization, year, can }: Props) {
    const [activeTab, setActiveTab] = useState('absenteeism');

    const maxCount = Math.max(...absenteeism.monthly.map((m) => m.count), 1);

    const yearOptions: number[] = [];
    const currentYear = new Date().getFullYear();
    for (let y = currentYear - 3; y <= currentYear; y++) {
        yearOptions.push(y);
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Leave Reports" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Leave Reports</h1>
                        <div className="mt-1 text-sm text-slate-500">
                            Absenteeism trends, Bradford Factor analysis, and leave utilisation
                        </div>
                    </div>

                    <Select
                        value={String(year)}
                        onValueChange={(val) => router.get('/hr/leave/reports', { year: val }, { preserveState: true })}
                    >
                        <SelectTrigger className="w-32">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {yearOptions.map((y) => (
                                <SelectItem key={y} value={String(y)}>{y}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <Tabs value={activeTab} onValueChange={setActiveTab}>
                    <TabsList>
                        <TabsTrigger value="absenteeism">
                            <BarChart3 className="mr-1.5 h-4 w-4" />
                            Absenteeism
                        </TabsTrigger>
                        <TabsTrigger value="bradford">
                            <AlertTriangle className="mr-1.5 h-4 w-4" />
                            Bradford Factor
                        </TabsTrigger>
                        <TabsTrigger value="utilization">
                            <TrendingUp className="mr-1.5 h-4 w-4" />
                            Utilisation
                        </TabsTrigger>
                    </TabsList>

                    {/* Absenteeism Tab */}
                    <TabsContent value="absenteeism" className="space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Monthly Sick Leave ({year})</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="flex items-end gap-2" style={{ height: 200 }}>
                                    {absenteeism.monthly.map((m) => (
                                        <div key={m.month} className="flex flex-1 flex-col items-center gap-1">
                                            <div
                                                className="w-full rounded-t bg-blue-500 transition-all"
                                                style={{ height: `${(m.count / maxCount) * 160}px`, minHeight: m.count > 0 ? 4 : 0 }}
                                                title={`${m.count} occurrences, ${m.total_hours}h`}
                                            />
                                            <span className="text-xs text-slate-500">{m.label}</span>
                                            <span className="text-xs font-medium">{m.count}</span>
                                        </div>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Top Absentees</CardTitle>
                            </CardHeader>
                            <CardContent className="p-0">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Employee</TableHead>
                                            <TableHead className="text-right">Occurrences</TableHead>
                                            <TableHead className="text-right">Total Hours</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {absenteeism.top_absentees.length === 0 && (
                                            <TableRow>
                                                <TableCell colSpan={3} className="text-center text-slate-400">No data for this period</TableCell>
                                            </TableRow>
                                        )}
                                        {absenteeism.top_absentees.map((a) => (
                                            <TableRow key={a.user_id}>
                                                <TableCell className="font-medium">{a.name}</TableCell>
                                                <TableCell className="text-right">{a.occurrences}</TableCell>
                                                <TableCell className="text-right">{a.total_hours}h</TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* Bradford Factor Tab */}
                    <TabsContent value="bradford" className="space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Bradford Factor ({year})</CardTitle>
                            </CardHeader>
                            <CardContent className="p-0">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Employee</TableHead>
                                            <TableHead className="text-right">Spells (S)</TableHead>
                                            <TableHead className="text-right">Days (D)</TableHead>
                                            <TableHead className="text-right">Factor (S²xD)</TableHead>
                                            <TableHead>Risk</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {bradfordFactor.employees.length === 0 && (
                                            <TableRow>
                                                <TableCell colSpan={5} className="text-center text-slate-400">No data for this period</TableCell>
                                            </TableRow>
                                        )}
                                        {bradfordFactor.employees.map((e) => (
                                            <TableRow key={e.user_id}>
                                                <TableCell className="font-medium">{e.name}</TableCell>
                                                <TableCell className="text-right">{e.spells}</TableCell>
                                                <TableCell className="text-right">{e.days}</TableCell>
                                                <TableCell className="text-right font-mono">{e.factor}</TableCell>
                                                <TableCell>
                                                    <Badge className={riskColors[e.risk_level] || 'bg-slate-100'} variant="outline">
                                                        {e.risk_level}
                                                    </Badge>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* Utilisation Tab */}
                    <TabsContent value="utilization" className="space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">Leave Utilisation ({year})</CardTitle>
                            </CardHeader>
                            <CardContent className="p-0">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Employee</TableHead>
                                            <TableHead className="text-right">Entitlement</TableHead>
                                            <TableHead className="text-right">Used</TableHead>
                                            <TableHead className="text-right">Remaining</TableHead>
                                            <TableHead>Utilisation</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {utilization.employees.length === 0 && (
                                            <TableRow>
                                                <TableCell colSpan={5} className="text-center text-slate-400">No data for this period</TableCell>
                                            </TableRow>
                                        )}
                                        {utilization.employees.map((e) => (
                                            <TableRow key={e.user_id}>
                                                <TableCell className="font-medium">{e.name}</TableCell>
                                                <TableCell className="text-right">{e.total_entitlement}h</TableCell>
                                                <TableCell className="text-right">{e.total_used}h</TableCell>
                                                <TableCell className="text-right">{e.total_remaining}h</TableCell>
                                                <TableCell>
                                                    <div className="flex items-center gap-2">
                                                        <div className="h-2 w-24 overflow-hidden rounded-full bg-slate-100">
                                                            <div
                                                                className={`h-full rounded-full ${e.overall_pct >= 90 ? 'bg-red-500' : e.overall_pct >= 70 ? 'bg-yellow-500' : 'bg-green-500'}`}
                                                                style={{ width: `${Math.min(100, e.overall_pct)}%` }}
                                                            />
                                                        </div>
                                                        <span className="text-xs text-slate-500">{e.overall_pct}%</span>
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                    </TabsContent>
                </Tabs>
            </div>
        </AppLayout>
    );
}
