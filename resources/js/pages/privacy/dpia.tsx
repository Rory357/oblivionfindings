import AppLayout from '@/layouts/app-layout';
import { PageHero } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Activity, Plus, AlertTriangle, CheckCircle } from 'lucide-react';

type Props = {
    filters: {
        q: string;
        outcome: string | null;
        risk_level: string | null;
    };
    dpias: any;
    stats?: {
        total: number;
        pending_review: number;
        high_risk: number;
        approved: number;
    };
};

export default function PIAIndex({ filters, dpias, stats }: Props) {
    const ANY = '__any__';
    const OUTCOME_LABELS: Record<string, string> = {
        approved: 'approved',
        approved_with_conditions: 'approved with conditions',
        requires_dpo_review: 'requires Privacy Officer review',
        rejected: 'rejected',
    };
    const RISK_LABELS: Record<string, string> = {
        low: 'low',
        medium: 'medium',
        high: 'high',
        very_high: 'very high',
    };
    const { auth } = usePage().props as any;
    const can = auth?.can?.privacy ?? {};

    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/privacy/pia', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    const getOutcomeColor = (outcome: string | null) => {
        switch (outcome) {
            case 'approved':
                return 'bg-status-success-bg text-status-success border-status-success/30';
            case 'approved_with_conditions':
                return 'bg-status-warning-bg text-status-warning border-status-warning/30';
            case 'requires_dpo_review':
                return 'bg-status-warning-bg text-status-warning border-status-warning/30';
            case 'rejected':
                return 'bg-status-critical-bg text-status-critical border-status-critical/30';
            default:
                return 'bg-muted text-foreground border-border';
        }
    };

    const getRiskColor = (risk: string) => {
        switch (risk) {
            case 'low':
                return 'bg-status-success-bg text-status-success border-status-success/30';
            case 'medium':
                return 'bg-status-warning-bg text-status-warning border-status-warning/30';
            case 'high':
                return 'bg-status-warning-bg text-status-warning border-status-warning/30';
            case 'very_high':
                return 'bg-status-critical-bg text-status-critical border-status-critical/30';
            default:
                return 'bg-muted text-foreground border-border';
        }
    };

    const formatDate = (dateString: string) => {
        return new Date(dateString).toLocaleDateString('en-GB', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
        });
    };

    return (
        <AppLayout breadcrumbs={[
            { title: 'Privacy', href: '/privacy/dashboard' },
            { title: 'Impact Assessments', href: '/privacy/pia' }
        ]}>
            <Head title="Data Protection Impact Assessments" />

            <div className="flex flex-col gap-6 p-6">
                {/* Hero Header */}
                <PageHero
                    title="Data Protection Impact Assessments"
                    description="Privacy Impact Assessment — assess privacy risks before processing changes"
                    icon={<Activity className="h-7 w-7 text-white" />}
                    stats={stats ? [
                        { label: 'Total', value: stats.total },
                        { label: 'Pending Review', value: stats.pending_review },
                        { label: 'High Risk', value: stats.high_risk },
                        { label: 'Approved', value: stats.approved },
                    ] : undefined}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <Link href="/privacy/dashboard">
                                <Button variant="outline" size="sm">Privacy Dashboard</Button>
                            </Link>
                            {can.conductDPIA && (
                                <Link href="/privacy/pia/create">
                                    <Button size="sm">
                                        <Plus className="mr-1.5 h-4 w-4" />
                                        New Assessment
                                    </Button>
                                </Link>
                            )}
                        </div>
                    }
                />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
                            <Label className="text-xs text-muted-foreground">Search</Label>
                            <Input
                                placeholder="Search by name or project"
                                value={filters.q || ''}
                                onChange={(e) => onFilter({ q: e.target.value })}
                            />
                        </div>

                        <div>
                            <Label className="text-xs text-muted-foreground">Outcome</Label>
                            <Select
                                value={filters.outcome ?? ANY}
                                onValueChange={(v) => onFilter({ outcome: v === ANY ? null : v })}
                            >
                                <SelectTrigger><SelectValue placeholder="Outcome" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    <SelectItem value="approved">Approved</SelectItem>
                                    <SelectItem value="approved_with_conditions">Approved with Conditions</SelectItem>
                                    <SelectItem value="requires_dpo_review">Requires Privacy Officer Review</SelectItem>
                                    <SelectItem value="rejected">Rejected</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div>
                            <Label className="text-xs text-muted-foreground">Risk Level</Label>
                            <Select
                                value={filters.risk_level ?? ANY}
                                onValueChange={(v) => onFilter({ risk_level: v === ANY ? null : v })}
                            >
                                <SelectTrigger><SelectValue placeholder="Risk" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    <SelectItem value="low">Low</SelectItem>
                                    <SelectItem value="medium">Medium</SelectItem>
                                    <SelectItem value="high">High</SelectItem>
                                    <SelectItem value="very_high">Very High</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                <div className="space-y-2">
                    {dpias.data.map((dpia: any) => (
                        <Card key={dpia.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="flex items-center gap-2 font-semibold">
                                                <Activity className="h-4 w-4 text-status-success" />
                                                {dpia.assessment_name}
                                            </div>
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                <Badge className={getOutcomeColor(dpia.outcome)}>
                                                    {dpia.outcome ? OUTCOME_LABELS[dpia.outcome] ?? dpia.outcome.replace(/_/g, ' ') : 'Pending review'}
                                                </Badge>
                                                <Badge className={getRiskColor(dpia.overall_risk_level)}>
                                                    {RISK_LABELS[dpia.overall_risk_level] ?? dpia.overall_risk_level} risk
                                                </Badge>
                                                {dpia.residual_risk_level && (
                                                    <Badge variant="outline">
                                                        Residual: {RISK_LABELS[dpia.residual_risk_level] ?? dpia.residual_risk_level}
                                                    </Badge>
                                                )}
                                            </div>
                                            <div className="mt-2 text-sm text-muted-foreground">
                                                {dpia.project_or_process}
                                            </div>
                                            <div className="mt-2 text-xs text-muted-foreground">
                                                Assessed: {formatDate(dpia.assessment_date)}
                                                {dpia.assessor && ` by ${dpia.assessor.name}`}
                                                {dpia.review_date && ` • Review: ${formatDate(dpia.review_date)}`}
                                            </div>
                                        </div>
                                        <Link href={`/privacy/pia/${dpia.id}`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                            View
                                        </Link>
                                    </div>
                                </CardTitle>
                            </CardHeader>
                        </Card>
                    ))}
                    {!dpias.data.length && (
                        <div className="py-8 text-center text-sm text-muted-foreground">
                            No impact assessments found.
                        </div>
                    )}
                </div>

                {dpias?.links?.length ? (
                    <div className="flex flex-wrap gap-2">
                        {dpias.links.map((l: any) => (
                            <Button
                                key={l.label}
                                type="button"
                                variant={l.active ? 'secondary' : 'outline'}
                                size="sm"
                                disabled={!l.url}
                                onClick={() => l.url && router.get(l.url, {}, { preserveState: true, preserveScroll: true })}
                                dangerouslySetInnerHTML={{ __html: l.label }}
                            />
                        ))}
                    </div>
                ) : null}
            </div>
        </AppLayout>
    );
}
