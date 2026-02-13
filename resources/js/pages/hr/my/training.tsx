import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { type BreadcrumbItem } from '@/types';

interface ComplianceStatus {
    id: number;
    status: 'compliant' | 'expiring_soon' | 'expired' | 'not_started';
    expiry_date: string | null;
    completed_at: string | null;
    requirement: {
        id: number;
        name: string;
        category: string;
        description: string | null;
    };
}

interface Props {
    complianceStatuses: ComplianceStatus[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr/my' },
    { title: 'My HR', href: '/hr/my' },
    { title: 'My Training', href: '/hr/my/training' },
];

const statusConfig: Record<string, { className: string; label: string }> = {
    compliant: {
        className: 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10',
        label: 'Compliant',
    },
    expiring_soon: {
        className: 'border-yellow-500/30 text-yellow-400 bg-yellow-500/10',
        label: 'Expiring Soon',
    },
    expired: {
        className: 'border-red-500/30 text-red-400 bg-red-500/10',
        label: 'Expired',
    },
    not_started: {
        className: 'border-slate-500/30 text-slate-400 bg-slate-500/10',
        label: 'Not Started',
    },
};

export default function MyTraining({ complianceStatuses }: Props) {
    // Group by status for summary
    const summary = complianceStatuses.reduce(
        (acc, cs) => {
            acc[cs.status] = (acc[cs.status] || 0) + 1;
            return acc;
        },
        {} as Record<string, number>,
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My Training & Compliance" />
            <div className="flex flex-col gap-6 p-6">
                <h1 className="text-2xl font-bold">My Training & Compliance</h1>

                {/* Summary Cards */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Compliant</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold text-emerald-500">{summary.compliant || 0}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Expiring Soon</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold text-yellow-500">{summary.expiring_soon || 0}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Expired</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold text-red-500">{summary.expired || 0}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium text-muted-foreground">Not Started</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold text-slate-400">{summary.not_started || 0}</p>
                        </CardContent>
                    </Card>
                </div>

                {/* Compliance Requirements Table */}
                <Card>
                    <CardHeader>
                        <CardTitle>My Compliance Requirements</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">Requirement</th>
                                    <th className="px-4 py-3 text-left font-medium">Category</th>
                                    <th className="px-4 py-3 text-left font-medium">Status</th>
                                    <th className="px-4 py-3 text-left font-medium">Completed</th>
                                    <th className="px-4 py-3 text-left font-medium">Expiry</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {complianceStatuses.map((cs) => {
                                    const config = statusConfig[cs.status] || statusConfig.not_started;
                                    return (
                                        <tr key={cs.id} className="hover:bg-muted/30">
                                            <td className="px-4 py-3">
                                                <p className="font-medium">{cs.requirement.name}</p>
                                                {cs.requirement.description && (
                                                    <p className="text-xs text-muted-foreground">{cs.requirement.description}</p>
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                <Badge variant="outline">{cs.requirement.category}</Badge>
                                            </td>
                                            <td className="px-4 py-3">
                                                <Badge variant="outline" className={config.className}>
                                                    {config.label}
                                                </Badge>
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {cs.completed_at || '\u2014'}
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {cs.expiry_date || '\u2014'}
                                            </td>
                                        </tr>
                                    );
                                })}
                                {complianceStatuses.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="px-4 py-8 text-center text-muted-foreground">
                                            No compliance requirements assigned.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
