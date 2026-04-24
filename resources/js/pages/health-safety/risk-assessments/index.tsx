import AppLayout from '@/layouts/app-layout';
import FleetHero from '@/components/fleet-hero';
import { Head, router } from '@inertiajs/react';
import { Card, CardContent } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { riskScoreColor } from '@/lib/status-colors';
import { useCallback } from 'react';
import { Shield, ChevronRight, AlertTriangle } from 'lucide-react';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Health & Safety', href: '/health-safety' },
    { title: 'Risk Assessments', href: '/health-safety/risk-assessments' },
];

interface AssessmentRow {
    id: number;
    reference_number: string;
    title: string;
    status: string;
    risk_score: number;
    risk_level: string;
    residual_risk_level: string | null;
    risk_acceptable: boolean | null;
    assessed_by_name: string | null;
    review_due_at: string | null;
    is_due_for_review: boolean;
    assessable_type: string | null;
}

interface Props {
    assessments: {
        data: AssessmentRow[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    filters: {
        status?: string | null;
        risk_level?: string | null;
        due_for_review?: string | null;
    };
}

export default function RiskAssessmentsIndex({ assessments, filters }: Props) {
    const applyFilter = useCallback((key: string, value: string | null) => {
        router.get('/health-safety/risk-assessments', { ...filters, [key]: value || undefined }, { preserveState: true, replace: true });
    }, [filters]);

    const fmtDate = (iso: string | null) => {
        if (!iso) return '-';
        return new Date(iso).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Risk Assessments" />
            <div className="flex flex-col gap-6 p-6">

                {/* Hero Header */}
                <FleetHero
                    title="Risk Assessments"
                    description={`${assessments.total} assessment${assessments.total !== 1 ? 's' : ''} registered`}
                    icon={<Shield className="h-7 w-7 text-white" />}
                    stats={[
                        { label: 'Total', value: assessments.total },
                    ]}
                />

                {/* Filters */}
                <Card>
                    <CardContent className="flex flex-wrap items-center gap-3 py-4">
                        <Select value={filters.status ?? '__none__'} onValueChange={v => applyFilter('status', v === '__none__' ? null : v)}>
                            <SelectTrigger className="w-40"><SelectValue placeholder="All statuses" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__none__">All statuses</SelectItem>
                                <SelectItem value="draft">Draft</SelectItem>
                                <SelectItem value="active">Active</SelectItem>
                                <SelectItem value="under_review">Under Review</SelectItem>
                                <SelectItem value="superseded">Superseded</SelectItem>
                                <SelectItem value="archived">Archived</SelectItem>
                            </SelectContent>
                        </Select>
                        <Select value={filters.risk_level ?? '__none__'} onValueChange={v => applyFilter('risk_level', v === '__none__' ? null : v)}>
                            <SelectTrigger className="w-36"><SelectValue placeholder="All risk levels" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__none__">All levels</SelectItem>
                                <SelectItem value="extreme">Extreme</SelectItem>
                                <SelectItem value="high">High</SelectItem>
                                <SelectItem value="medium">Medium</SelectItem>
                                <SelectItem value="low">Low</SelectItem>
                            </SelectContent>
                        </Select>
                        <Select value={filters.due_for_review ?? '__none__'} onValueChange={v => applyFilter('due_for_review', v === '__none__' ? null : v)}>
                            <SelectTrigger className="w-44"><SelectValue placeholder="Review status" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__none__">All</SelectItem>
                                <SelectItem value="true">Due for review</SelectItem>
                            </SelectContent>
                        </Select>
                    </CardContent>
                </Card>

                {/* Table */}
                <Card>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">Reference</th>
                                    <th className="px-4 py-3 text-left font-medium">Title</th>
                                    <th className="px-4 py-3 text-left font-medium">Status</th>
                                    <th className="px-4 py-3 text-center font-medium">Risk Score</th>
                                    <th className="px-4 py-3 text-left font-medium">Level</th>
                                    <th className="px-4 py-3 text-left font-medium">Residual</th>
                                    <th className="px-4 py-3 text-left font-medium">Assessed by</th>
                                    <th className="px-4 py-3 text-left font-medium">Review due</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {assessments.data.map(ra => (
                                    <tr key={ra.id} className={`hover:bg-muted/30 ${ra.is_due_for_review ? 'bg-status-warning-bg' : ''}`}>
                                        <td className="px-4 py-3 font-medium">{ra.reference_number}</td>
                                        <td className="px-4 py-3 max-w-xs truncate text-muted-foreground">{ra.title}</td>
                                        <td className="px-4 py-3"><StatusBadge status={ra.status} /></td>
                                        <td className="px-4 py-3 text-center">
                                            <span className={`inline-flex h-8 w-8 items-center justify-center rounded-md text-xs font-bold ${riskScoreColor(ra.risk_score)}`}>
                                                {ra.risk_score}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3"><StatusBadge status={ra.risk_level} /></td>
                                        <td className="px-4 py-3">
                                            {ra.residual_risk_level ? (
                                                <StatusBadge status={ra.residual_risk_level} />
                                            ) : (
                                                <span className="text-muted-foreground">-</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">{ra.assessed_by_name ?? '-'}</td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-1.5 text-muted-foreground">
                                                {fmtDate(ra.review_due_at)}
                                                {ra.is_due_for_review && <AlertTriangle className="h-3.5 w-3.5 text-status-warning" />}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                                {assessments.data.length === 0 && (
                                    <tr>
                                        <td colSpan={8} className="px-4 py-12 text-center text-muted-foreground">
                                            <Shield className="mx-auto mb-3 h-12 w-12 opacity-30" />
                                            <p className="text-base font-medium">No risk assessments found</p>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                {assessments.last_page > 1 && (
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">Showing {assessments.from}–{assessments.to} of {assessments.total}</p>
                        <LaravelPagination links={assessments.links} />
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
