import { Head } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { CheckCircle2, AlertTriangle, Clock, FileText } from 'lucide-react';

interface Props extends PageProps {
    obligations: Record<string, any[]>;
    summary: {
        total: number;
        complete: number;
        overdue: number;
        due_soon: number;
    };
}

const statusColors: Record<string, string> = {
    complete: 'bg-green-100 text-green-800 border-green-200',
    compliant: 'bg-green-100 text-green-800 border-green-200',
    overdue: 'bg-red-100 text-red-800 border-red-200',
    due_soon: 'bg-amber-100 text-amber-800 border-amber-200',
    pending: 'bg-gray-100 text-gray-800 border-gray-200',
    in_progress: 'bg-blue-100 text-blue-800 border-blue-200',
};

export default function ComplianceStatus({ auth, obligations, summary }: Props) {
    const formatDate = (d: string | null) =>
        d ? new Date(d).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' }) : '-';

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Reports', href: '/governance/reports' },
                { title: 'Compliance', href: '#' },
            ]}
        >
            <Head title="Compliance Status Report" />

            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="mb-6">
                    <h1 className="text-3xl font-bold text-gray-900">Compliance Status Report</h1>
                    <p className="text-gray-500 mt-1">Obligation status across all frameworks</p>
                </div>

                {/* Summary Cards */}
                <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-gray-500">Total Obligations</p>
                                    <p className="text-3xl font-bold">{summary.total}</p>
                                </div>
                                <FileText className="w-8 h-8 text-gray-400" />
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="border-green-200">
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-green-600">Complete</p>
                                    <p className="text-3xl font-bold text-green-600">{summary.complete}</p>
                                </div>
                                <CheckCircle2 className="w-8 h-8 text-green-500" />
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="border-red-200">
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-red-600">Overdue</p>
                                    <p className="text-3xl font-bold text-red-600">{summary.overdue}</p>
                                </div>
                                <AlertTriangle className="w-8 h-8 text-red-500" />
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="border-amber-200">
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm text-amber-600">Due Soon</p>
                                    <p className="text-3xl font-bold text-amber-600">{summary.due_soon}</p>
                                </div>
                                <Clock className="w-8 h-8 text-amber-500" />
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Grouped by Framework */}
                {Object.keys(obligations).length === 0 ? (
                    <Card>
                        <CardContent className="pt-6">
                            <p className="text-center text-gray-500 py-8">No compliance obligations found.</p>
                        </CardContent>
                    </Card>
                ) : (
                    Object.entries(obligations).map(([framework, items]) => (
                        <Card key={framework} className="mb-6">
                            <CardHeader>
                                <CardTitle className="capitalize">{framework.replace(/_/g, ' ')}</CardTitle>
                                <CardDescription>{items.length} obligations</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-2">
                                    {items.map((ob: any) => (
                                        <div key={ob.id} className="flex items-center justify-between p-3 rounded-lg border hover:bg-gray-50">
                                            <div className="flex-1 min-w-0">
                                                <p className="font-medium text-gray-900">{ob.title}</p>
                                                <div className="flex items-center gap-3 mt-1 text-xs text-gray-500">
                                                    {ob.owner?.name && <span>Owner: {ob.owner.name}</span>}
                                                    {ob.due_date && <span>Due: {formatDate(ob.due_date)}</span>}
                                                </div>
                                            </div>
                                            <Badge className={statusColors[ob.status] ?? statusColors.pending}>
                                                {ob.status?.replace(/_/g, ' ')}
                                            </Badge>
                                        </div>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>
                    ))
                )}
            </div>
        </AppLayout>
    );
}
