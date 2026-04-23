import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDate } from '@/lib/date-format';
import { Head, Link, router } from '@inertiajs/react';
import { FileText, CheckCircle, Clock, AlertTriangle, History, HardHat, Shield } from 'lucide-react';

type Props = {
    procedure: {
        id: number;
        title: string;
        reference_number: string | null;
        category: string;
        status: string;
        version: number;
        purpose: string | null;
        scope: string | null;
        steps: Array<{ step_number: number; description: string; safety_notes: string | null }>;
        ppe_required: string[];
        emergency_procedures: string | null;
        applicable_roles: string[];
        applicable_sites: string[];
        approved_by: { id: number; name: string } | null;
        approved_at: string | null;
        review_date: string | null;
    };
    versions: Array<{
        id: number;
        version: number;
        change_summary: string | null;
        changed_by: { id: number; name: string } | null;
        created_at: string;
    }>;
    canApprove: boolean;
    canEdit: boolean;
    canSubmitForReview: boolean;
};

function categoryBadge(cat: string) {
    switch (cat) {
        case 'fire_safety': return 'bg-red-100 text-red-800 border-red-200';
        case 'chemical_handling': return 'bg-purple-100 text-purple-800 border-purple-200';
        case 'manual_handling': return 'bg-blue-100 text-blue-800 border-blue-200';
        case 'infection_control': return 'bg-green-100 text-green-800 border-green-200';
        case 'emergency_procedures': return 'bg-orange-100 text-orange-800 border-orange-200';
        default: return 'bg-slate-100 text-slate-800 border-slate-200';
    }
}

function statusBadge(status: string) {
    switch (status) {
        case 'approved': return 'bg-green-100 text-green-800 border-green-200';
        case 'draft': return 'bg-slate-100 text-slate-800 border-slate-200';
        case 'under_review': return 'bg-amber-100 text-amber-800 border-amber-200';
        case 'archived': return 'bg-red-100 text-red-800 border-red-200';
        default: return 'bg-slate-100 text-slate-800 border-slate-200';
    }
}

export default function ProcedureShow({ procedure, versions, canApprove, canEdit, canSubmitForReview }: Props) {
    const approve = () => {
        router.post(`/health-safety/procedures/${procedure.id}/approve`);
    };

    const submitForReview = () => {
        router.post(`/health-safety/procedures/${procedure.id}/submit-for-review`);
    };

    return (
        <AppLayout breadcrumbs={[
            { title: 'Health & Safety', href: '/health-safety' },
            { title: 'Procedures', href: '/health-safety/procedures' },
            { title: procedure.title, href: `/health-safety/procedures/${procedure.id}` },
        ]}>
            <Head title={procedure.title} />

            <div className="space-y-6">
                {/* Header */}
                <Card>
                    <CardContent className="pt-6">
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <div className="flex items-center gap-2">
                                    <FileText className="h-5 w-5 text-blue-600" />
                                    <h1 className="text-xl font-semibold">{procedure.title}</h1>
                                </div>
                                <div className="mt-2 flex flex-wrap items-center gap-2">
                                    {procedure.reference_number && (
                                        <span className="rounded bg-slate-100 px-2 py-0.5 font-mono text-xs">{procedure.reference_number}</span>
                                    )}
                                    <Badge className={categoryBadge(procedure.category)}>
                                        {procedure.category?.replace(/_/g, ' ')}
                                    </Badge>
                                    <Badge className={statusBadge(procedure.status)}>
                                        {procedure.status?.replace(/_/g, ' ')}
                                    </Badge>
                                    <span className="text-xs text-slate-500">Version {procedure.version}</span>
                                </div>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                {canSubmitForReview && (
                                    <Button size="sm" variant="outline" onClick={submitForReview}>
                                        <Clock className="mr-1.5 h-4 w-4" />
                                        Submit for Review
                                    </Button>
                                )}
                                {canApprove && procedure.status === 'under_review' && (
                                    <Button size="sm" onClick={approve}>
                                        <CheckCircle className="mr-1.5 h-4 w-4" />
                                        Approve
                                    </Button>
                                )}
                                {canEdit && (
                                    <Link href={`/health-safety/procedures/${procedure.id}/edit`}>
                                        <Button size="sm" variant="outline">Edit</Button>
                                    </Link>
                                )}
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Purpose & Scope */}
                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Purpose</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm text-slate-700 whitespace-pre-wrap">{procedure.purpose || 'Not specified.'}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Scope</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm text-slate-700 whitespace-pre-wrap">{procedure.scope || 'Not specified.'}</p>
                        </CardContent>
                    </Card>
                </div>

                {/* Procedure Steps */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Procedure Steps</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="space-y-4">
                            {(procedure.steps ?? []).map((step) => (
                                <div key={step.step_number} className="rounded-lg border p-4">
                                    <div className="flex items-start gap-3">
                                        <div className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-blue-100 text-sm font-bold text-blue-700">
                                            {step.step_number}
                                        </div>
                                        <div className="flex-1">
                                            <p className="text-sm text-slate-700 whitespace-pre-wrap">{step.description}</p>
                                            {step.safety_notes && (
                                                <div className="mt-2 flex items-start gap-1.5 rounded border border-amber-200 bg-amber-50 p-2 text-xs text-amber-800">
                                                    <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                                    <span className="whitespace-pre-wrap">{step.safety_notes}</span>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            ))}
                            {!(procedure.steps ?? []).length && (
                                <p className="text-sm text-slate-500">No steps defined.</p>
                            )}
                        </div>
                    </CardContent>
                </Card>

                {/* Safety Requirements */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Safety Requirements</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div>
                            <div className="mb-2 flex items-center gap-1.5 text-sm font-medium">
                                <HardHat className="h-4 w-4 text-slate-500" />
                                PPE Required
                            </div>
                            {(procedure.ppe_required ?? []).length > 0 ? (
                                <div className="flex flex-wrap gap-1.5">
                                    {procedure.ppe_required.map((item) => (
                                        <Badge key={item} variant="outline" className="border-blue-200 bg-blue-50 text-blue-700">
                                            {item}
                                        </Badge>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-sm text-slate-500">None specified.</p>
                            )}
                        </div>

                        <div>
                            <div className="mb-2 flex items-center gap-1.5 text-sm font-medium">
                                <Shield className="h-4 w-4 text-slate-500" />
                                Emergency Procedures
                            </div>
                            <p className="text-sm text-slate-700 whitespace-pre-wrap">
                                {procedure.emergency_procedures || 'Not specified.'}
                            </p>
                        </div>
                    </CardContent>
                </Card>

                {/* Applicability */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Applicability</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <div className="mb-1 text-sm font-medium">Applicable Roles</div>
                                {(procedure.applicable_roles ?? []).length > 0 ? (
                                    <div className="flex flex-wrap gap-1.5">
                                        {procedure.applicable_roles.map((role) => (
                                            <Badge key={role} variant="outline">{role}</Badge>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-sm text-slate-500">All roles.</p>
                                )}
                            </div>
                            <div>
                                <div className="mb-1 text-sm font-medium">Applicable Sites</div>
                                {(procedure.applicable_sites ?? []).length > 0 ? (
                                    <div className="flex flex-wrap gap-1.5">
                                        {procedure.applicable_sites.map((site) => (
                                            <Badge key={site} variant="outline">{site}</Badge>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-sm text-slate-500">All sites.</p>
                                )}
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Approval Info */}
                {procedure.approved_by && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Approval</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center gap-2 text-sm">
                                <CheckCircle className="h-4 w-4 text-green-600" />
                                <span>
                                    Approved by <span className="font-medium">{procedure.approved_by.name}</span>
                                    {procedure.approved_at && (
                                        <> on {formatDate(procedure.approved_at)}</>
                                    )}
                                </span>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Version History */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <History className="h-4 w-4" />
                            Version History
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left text-xs text-slate-500">
                                        <th className="pb-2 font-medium">Version</th>
                                        <th className="pb-2 font-medium">Change Summary</th>
                                        <th className="pb-2 font-medium">Changed By</th>
                                        <th className="pb-2 font-medium">Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {versions.map((v) => (
                                        <tr key={v.id} className="border-b last:border-0">
                                            <td className="py-2 font-medium">v{v.version}</td>
                                            <td className="py-2">{v.change_summary ?? '-'}</td>
                                            <td className="py-2">{v.changed_by?.name ?? '-'}</td>
                                            <td className="py-2">{formatDate(v.created_at)}</td>
                                        </tr>
                                    ))}
                                    {!versions.length && (
                                        <tr>
                                            <td colSpan={4} className="py-4 text-center text-slate-500">No version history.</td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
